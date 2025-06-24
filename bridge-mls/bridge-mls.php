<?php
/**
 * Plugin Name: Bridge MLS
 * Plugin URI: https://bridgemls.com
 * Description: Professional Bridge MLS integration with advanced property search, modern gallery lightbox, and comprehensive admin interface.
 * Version: 3.0.0
 * Author: Bridge MLS Development Team
 * License: GPL v2 or later
 * Text Domain: bridge-mls
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BRIDGE_MLS_VERSION', '3.0.0');
define('BRIDGE_MLS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BRIDGE_MLS_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Load configuration from options or use defaults
$bridge_options = get_option('bridge_mls_options', array());
define('BRIDGE_API_URL', isset($bridge_options['api_url']) ? $bridge_options['api_url'] : 'https://api.bridgedataoutput.com/api/v2/OData/shared_mlspin_41854c5');
define('BRIDGE_SERVER_TOKEN', isset($bridge_options['server_token']) ? $bridge_options['server_token'] : '1c69fed3083478d187d4ce8deb8788ed');
define('BRIDGE_BROWSER_TOKEN', isset($bridge_options['browser_token']) ? $bridge_options['browser_token'] : '6c3ff882c868eb6ace6cd2ad9005ea7c');

// Include admin interface
if (is_admin()) {
    require_once BRIDGE_MLS_PLUGIN_DIR . 'bridge-mls-admin.php';
    new BridgeMLSAdmin();
}

/**
 * Main plugin class
 */
class BridgeMLSPlugin {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Core hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_bridge_search_properties', array($this, 'ajax_search_properties'));
        add_action('wp_ajax_nopriv_bridge_search_properties', array($this, 'ajax_search_properties'));
        add_action('wp_ajax_bridge_get_property_details', array($this, 'ajax_get_property_details'));
        add_action('wp_ajax_nopriv_bridge_get_property_details', array($this, 'ajax_get_property_details'));
        add_action('wp_ajax_bridge_test_api', array($this, 'ajax_test_api'));
        
        // Shortcodes
        add_shortcode('bridge_property_search', array($this, 'property_search_shortcode'));
        add_shortcode('bridge_featured_properties', array($this, 'featured_properties_shortcode'));
        add_shortcode('bridge_property_details', array($this, 'property_details_shortcode'));
    }
    
    /**
     * Enqueue scripts and styles with conditional loading
     */
    public function enqueue_scripts() {
        // Check if we need to load scripts
        $load_scripts = false;
        
        // Load on pages with shortcodes
        if (is_singular()) {
            $post = get_post();
            if ($post && (
                has_shortcode($post->post_content, 'bridge_property_search') ||
                has_shortcode($post->post_content, 'bridge_featured_properties') ||
                has_shortcode($post->post_content, 'bridge_property_details')
            )) {
                $load_scripts = true;
            }
        }
        
        // Load on property details pages (URL-based detection)
        if (isset($_GET['mls']) || strpos($_SERVER['REQUEST_URI'], 'property-details') !== false) {
            $load_scripts = true;
        }
        
        // Always load in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $load_scripts = true;
        }
        
        if ($load_scripts) {
            // Select2 for multiselect
            wp_enqueue_style('select2-css', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
            wp_enqueue_script('select2-js', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'));
            
            // Plugin assets
            wp_enqueue_style('bridge-mls-style', BRIDGE_MLS_PLUGIN_URL . 'assets/bridge-mls.css', array(), BRIDGE_MLS_VERSION);
            wp_enqueue_script('bridge-mls-script', BRIDGE_MLS_PLUGIN_URL . 'assets/bridge-mls.js', array('jquery', 'select2-js'), BRIDGE_MLS_VERSION, true);
            
            // Localize script
            wp_localize_script('bridge-mls-script', 'bridgeMLS', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bridge_mls_nonce'),
                'plugin_url' => BRIDGE_MLS_PLUGIN_URL,
                'debug' => defined('WP_DEBUG') && WP_DEBUG
            ));
        }
    }
    
    /**
     * Make API request with proper error handling
     */
    private function make_api_request($endpoint, $params = array()) {
        // Add authentication token
        $params['access_token'] = BRIDGE_SERVER_TOKEN;
        
        // Build URL
        $url = BRIDGE_API_URL . '/' . $endpoint . '?' . http_build_query($params);
        
        // Debug logging
        $this->debug_log('API Request: ' . $url);
        
        // Make request with timeout
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'Bridge MLS WordPress Plugin v' . BRIDGE_MLS_VERSION
            )
        ));
        
        // Check for WordPress errors
        if (is_wp_error($response)) {
            $this->debug_log('API Error: ' . $response->get_error_message());
            return false;
        }
        
        // Check HTTP status
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $this->debug_log('API HTTP Error: ' . $status_code . ' - ' . substr($body, 0, 500));
            return false;
        }
        
        // Parse JSON response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->debug_log('JSON Error: ' . json_last_error_msg());
            return false;
        }
        
        // Check for API errors
        if (isset($data['error'])) {
            $this->debug_log('API Error: ' . print_r($data['error'], true));
            return false;
        }
        
        return $data;
    }
    
    /**
     * Search properties with advanced filtering and caching
     */
    public function search_properties($search_params = array()) {
        // Cache implementation
        $use_cache = !defined('WP_DEBUG') || !WP_DEBUG;
        $cache_key = 'bridge_properties_' . md5(serialize($search_params));
        
        if ($use_cache) {
            $cached_result = get_transient($cache_key);
            if ($cached_result !== false) {
                return $cached_result;
            }
        }
        
        // Build API parameters
        $params = array(
            '$select' => 'ListingKey,ListingId,ListPrice,BedroomsTotal,BathroomsTotalInteger,LivingArea,City,StateOrProvince,UnparsedAddress,PublicRemarks,PropertyType,Media,PhotosCount,ModificationTimestamp,ListingContractDate,StandardStatus',
            '$top' => isset($search_params['limit']) ? intval($search_params['limit']) : 50,
            '$orderby' => 'ModificationTimestamp desc'
        );
        
        // Build filters
        $filters = array();
        
        // Always filter for active listings with photos
        $filters[] = "StandardStatus eq 'Active'";
        $filters[] = "PhotosCount gt 0";
        
        // City filtering with multiple city support
        if (!empty($search_params['city'])) {
            $cities = $search_params['city'];
            if (is_string($cities)) {
                $cities = array_map('trim', explode(',', $cities));
            }
            if (is_array($cities) && !empty($cities)) {
                $city_filters = array();
                foreach ($cities as $city) {
                    if (!empty($city)) {
                        $city = str_replace("'", "''", trim($city));
                        $city_filters[] = "City eq '" . $city . "'";
                    }
                }
                if (!empty($city_filters)) {
                    $filters[] = '(' . implode(' or ', $city_filters) . ')';
                }
            }
        }
        
        // Price range filtering
        if (!empty($search_params['min_price'])) {
            $min_price = intval($search_params['min_price']);
            if ($min_price > 0) {
                $filters[] = "ListPrice ge " . $min_price;
            }
        }
        
        if (!empty($search_params['max_price'])) {
            $max_price = intval($search_params['max_price']);
            if ($max_price > 0) {
                $filters[] = "ListPrice le " . $max_price;
            }
        }
        
        // Bedroom filtering
        if (!empty($search_params['bedrooms']) && $search_params['bedrooms'] !== 'any') {
            $bedrooms = intval(str_replace('+', '', $search_params['bedrooms']));
            if ($bedrooms > 0) {
                $filters[] = "BedroomsTotal ge " . $bedrooms;
            }
        }
        
        // Bathroom filtering
        if (!empty($search_params['bathrooms']) && $search_params['bathrooms'] !== 'any') {
            $bathrooms = intval(str_replace('+', '', $search_params['bathrooms']));
            if ($bathrooms > 0) {
                $filters[] = "BathroomsTotalInteger ge " . $bathrooms;
            }
        }
        
        // Property type filtering
        if (!empty($search_params['property_type']) && $search_params['property_type'] !== 'any') {
            $property_type = str_replace("'", "''", $search_params['property_type']);
            $filters[] = "PropertyType eq '" . $property_type . "'";
        }
        
        // Keyword search in property description
        if (!empty($search_params['keywords'])) {
            $keywords = str_replace("'", "''", $search_params['keywords']);
            $filters[] = "contains(PublicRemarks, '" . $keywords . "')";
        }
        
        // Combine all filters
        if (!empty($filters)) {
            $params['$filter'] = implode(' and ', $filters);
        }
        
        // Make API request
        $result = $this->make_api_request('Property', $params);
        
        if ($result && isset($result['value'])) {
            // Process and enhance property data
            foreach ($result['value'] as &$property) {
                // Extract and sort photos
                $property['Photos'] = $this->extract_property_photos($property);
                
                // Calculate additional fields
                $property['PricePerSqFt'] = $this->calculate_price_per_sqft($property);
                $property['MonthlyPayment'] = $this->calculate_monthly_payment($property['ListPrice']);
                $property['DaysOnMarket'] = $this->calculate_days_on_market($property['ListingContractDate']);
            }
            
            // Cache result
            if ($use_cache) {
                set_transient($cache_key, $result, 1800); // 30 minutes
            }
            
            return $result;
        }
        
        return false;
    }
    
    /**
     * Get single property by listing key
     */
    public function get_single_property($listing_key) {
        // Cache implementation
        $use_cache = !defined('WP_DEBUG') || !WP_DEBUG;
        $cache_key = 'bridge_property_' . $listing_key;
        
        if ($use_cache) {
            $cached_result = get_transient($cache_key);
            if ($cached_result !== false) {
                return $cached_result;
            }
        }
        
        // Build API parameters
        $params = array(
            '$filter' => "ListingKey eq '" . str_replace("'", "''", $listing_key) . "'",
            '$select' => 'ListingKey,ListingId,ListPrice,BedroomsTotal,BathroomsTotalInteger,LivingArea,LotSizeArea,YearBuilt,City,StateOrProvince,PostalCode,UnparsedAddress,PublicRemarks,PropertyType,PropertySubType,Media,PhotosCount,ModificationTimestamp,ListingContractDate,CloseDate,CoolingYN,HeatingYN,FireplacesTotal,StandardStatus'
        );
        
        $result = $this->make_api_request('Property', $params);
        
        if ($result && isset($result['value']) && !empty($result['value'])) {
            $property = $result['value'][0];
            
            // Extract photos
            $property['Photos'] = $this->extract_property_photos($property);
            
            // Calculate additional fields
            $property['PricePerSqFt'] = $this->calculate_price_per_sqft($property);
            $property['MonthlyPayment'] = $this->calculate_monthly_payment($property['ListPrice']);
            $property['DaysOnMarket'] = $this->calculate_days_on_market($property['ListingContractDate']);
            
            // Cache result
            if ($use_cache) {
                set_transient($cache_key, $property, 3600); // 1 hour
            }
            
            return $property;
        }
        
        return false;
    }
    
    /**
     * Get property by MLS ID
     */
    public function get_property_by_mls_id($mls_id) {
        // Cache implementation
        $use_cache = !defined('WP_DEBUG') || !WP_DEBUG;
        $cache_key = 'bridge_property_mls_' . $mls_id;
        
        if ($use_cache) {
            $cached_result = get_transient($cache_key);
            if ($cached_result !== false) {
                return $cached_result;
            }
        }
        
        // Build API parameters
        $params = array(
            '$filter' => "ListingId eq '" . str_replace("'", "''", $mls_id) . "'",
            '$select' => 'ListingKey,ListingId,ListPrice,BedroomsTotal,BathroomsTotalInteger,LivingArea,LotSizeArea,YearBuilt,City,StateOrProvince,PostalCode,UnparsedAddress,PublicRemarks,PropertyType,PropertySubType,Media,PhotosCount,ModificationTimestamp,ListingContractDate,CloseDate,CoolingYN,HeatingYN,FireplacesTotal,StandardStatus'
        );
        
        $result = $this->make_api_request('Property', $params);
        
        if ($result && isset($result['value']) && !empty($result['value'])) {
            $property = $result['value'][0];
            
            // Extract photos
            $property['Photos'] = $this->extract_property_photos($property);
            
            // Calculate additional fields
            $property['PricePerSqFt'] = $this->calculate_price_per_sqft($property);
            $property['MonthlyPayment'] = $this->calculate_monthly_payment($property['ListPrice']);
            $property['DaysOnMarket'] = $this->calculate_days_on_market($property['ListingContractDate']);
            
            // Cache result
            if ($use_cache) {
                set_transient($cache_key, $property, 3600); // 1 hour
            }
            
            return $property;
        }
        
        return false;
    }
    
    /**
     * Extract and sort property photos
     */
    private function extract_property_photos($property) {
        $photos = array();
        
        if (isset($property['Media']) && is_array($property['Media'])) {
            // Filter for photos only
            $photo_media = array_filter($property['Media'], function($media) {
                return isset($media['MediaCategory']) && 
                       $media['MediaCategory'] === 'Photo' && 
                       !empty($media['MediaURL']);
            });
            
            // Sort by Order field for proper sequence
            usort($photo_media, function($a, $b) {
                $orderA = isset($a['Order']) ? intval($a['Order']) : 999;
                $orderB = isset($b['Order']) ? intval($b['Order']) : 999;
                return $orderA - $orderB;
            });
            
            // Extract URLs
            foreach ($photo_media as $media) {
                if (!empty($media['MediaURL'])) {
                    $photos[] = $media['MediaURL'];
                }
            }
        }
        
        return $photos;
    }
    
    /**
     * AJAX handler for property search
     */
    public function ajax_search_properties() {
        // Verify nonce for security
        if (!check_ajax_referer('bridge_mls_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        // Sanitize all inputs
        $search_params = array();
        
        // Handle city array
        if (isset($_POST['city']) && !empty($_POST['city'])) {
            if (is_array($_POST['city'])) {
                $search_params['city'] = array_map('sanitize_text_field', $_POST['city']);
            } else {
                $search_params['city'] = sanitize_text_field($_POST['city']);
            }
        }
        
        // Handle other parameters
        $params = ['min_price', 'max_price', 'bedrooms', 'bathrooms', 'keywords', 'property_type', 'limit'];
        foreach ($params as $param) {
            if (isset($_POST[$param]) && !empty($_POST[$param])) {
                $search_params[$param] = sanitize_text_field($_POST[$param]);
            }
        }
        
        // Perform search
        $result = $this->search_properties($search_params);
        
        if ($result && isset($result['value'])) {
            $html = '';
            foreach ($result['value'] as $property) {
                $html .= $this->render_property_card($property);
            }
            
            wp_send_json_success(array(
                'html' => $html,
                'count' => count($result['value']),
                'total' => isset($result['@odata.count']) ? $result['@odata.count'] : count($result['value'])
            ));
        } else {
            wp_send_json_error('No properties found matching your criteria.');
        }
    }
    
    /**
     * AJAX handler for property details
     */
    public function ajax_get_property_details() {
        // Verify nonce
        if (!check_ajax_referer('bridge_mls_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $listing_key = isset($_POST['listing_key']) ? sanitize_text_field($_POST['listing_key']) : '';
        
        if (empty($listing_key)) {
            wp_send_json_error('No property specified');
            return;
        }
        
        $property = $this->get_single_property($listing_key);
        
        if ($property) {
            wp_send_json_success(array(
                'html' => $this->render_property_details($property),
                'property' => $property
            ));
        } else {
            wp_send_json_error('Property not found');
        }
    }
    
    /**
     * AJAX handler for API testing
     */
    public function ajax_test_api() {
        check_ajax_referer('bridge_mls_nonce', 'nonce');
        
        $test_results = $this->test_api_connection();
        
        if ($test_results['success']) {
            wp_send_json_success($test_results);
        } else {
            wp_send_json_error($test_results);
        }
    }
    
    /**
     * Test API connection with comprehensive checks
     */
    private function test_api_connection() {
        $results = array(
            'tests' => array(),
            'success' => true,
            'message' => ''
        );
        
        // Test 1: Basic connection
        $basic_params = array(
            '$top' => 1,
            '$select' => 'ListingKey,ListPrice'
        );
        
        $basic_result = $this->make_api_request('Property', $basic_params);
        $results['tests']['basic_connection'] = array(
            'name' => 'Basic API Connection',
            'success' => $basic_result !== false,
            'message' => $basic_result ? 'Connection successful' : 'Connection failed'
        );
        
        // Test 2: Media field handling
        $media_params = array(
            '$top' => 1,
            '$select' => 'ListingKey,Media,PhotosCount',
            '$filter' => "PhotosCount gt 0"
        );
        
        $media_result = $this->make_api_request('Property', $media_params);
        $results['tests']['media_handling'] = array(
            'name' => 'Media Field Handling',
            'success' => $media_result !== false,
            'message' => $media_result ? 'Media fields working' : 'Media field error'
        );
        
        // Test 3: Filter syntax
        $filter_params = array(
            '$top' => 1,
            '$select' => 'ListingKey,City,StandardStatus',
            '$filter' => "StandardStatus eq 'Active' and City eq 'Boston'"
        );
        
        $filter_result = $this->make_api_request('Property', $filter_params);
        $results['tests']['filter_syntax'] = array(
            'name' => 'OData Filter Syntax',
            'success' => $filter_result !== false,
            'message' => $filter_result ? 'Filters working' : 'Filter syntax error'
        );
        
        // Overall success
        $all_passed = true;
        foreach ($results['tests'] as $test) {
            if (!$test['success']) {
                $all_passed = false;
                break;
            }
        }
        
        $results['success'] = $all_passed;
        $results['message'] = $all_passed ? 
            'All API tests passed successfully!' : 
            'Some API tests failed. Check individual test results.';
        
        return $results;
    }
    
    /**
     * Property search shortcode
     */
    public function property_search_shortcode($atts) {
        $atts = shortcode_atts(array(
            'title' => 'Property Search',
            'show_search' => 'true',
            'columns' => '3',
            'limit' => '12',
            'city' => '',
            'min_price' => '',
            'max_price' => '',
            'bedrooms' => '',
            'bathrooms' => '',
            'keywords' => '',
            'property_type' => '',
            'show_title' => 'true'
        ), $atts);
        
        // Start output buffering
        ob_start();
        
        // Get initial search parameters from shortcode and URL
        $initial_params = array();
        
        // URL parameters take precedence
        foreach (['city', 'min_price', 'max_price', 'bedrooms', 'bathrooms', 'keywords', 'property_type'] as $param) {
            if (isset($_GET[$param]) && !empty($_GET[$param])) {
                $initial_params[$param] = sanitize_text_field($_GET[$param]);
            } elseif (!empty($atts[$param])) {
                $initial_params[$param] = $atts[$param];
            }
        }
        
        // Add limit to search params
        $initial_params['limit'] = intval($atts['limit']);
        
        ?>
        <div class="bridge-mls-container">
            <?php if ($atts['show_title'] === 'true'): ?>
                <h2 class="search-title"><?php echo esc_html($atts['title']); ?></h2>
            <?php endif; ?>
            
            <?php if ($atts['show_search'] === 'true'): ?>
                <div class="bridge-property-search">
                    <?php $this->render_search_form($initial_params); ?>
                </div>
            <?php endif; ?>
            
            <div id="bridge-search-results">
                <div class="property-grid columns-<?php echo esc_attr($atts['columns']); ?>">
                    <?php 
                    // Load initial properties
                    $properties = $this->search_properties($initial_params);
                    if ($properties && isset($properties['value'])) {
                        foreach ($properties['value'] as $property) {
                            echo $this->render_property_card($property);
                        }
                    }
                    ?>
                </div>
            </div>
            
            <div id="bridge-loading" style="display: none;">
                <div class="loading-spinner">Searching properties...</div>
            </div>
            
            <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                <div class="debug-panel">
                    <strong>Debug Mode:</strong>
                    <button type="button" id="bridge-test-api">Test API Connection</button>
                    <div id="bridge-api-status"></div>
                </div>
            <?php endif; ?>
        </div>
        
        <script type="text/javascript">
            // Initialize with search parameters
            window.bridgeInitialParams = <?php echo json_encode($initial_params); ?>;
            
            // Diagnostic: Log all navigation attempts
            (function() {
                // Track navigation
                let navigationCount = 0;
                let lastNavigation = '';
                
                // Override location methods to track issues
                const originalAssign = window.location.assign;
                const originalReplace = window.location.replace;
                const originalHref = Object.getOwnPropertyDescriptor(window.location, 'href');
                
                window.location.assign = function(url) {
                    console.log('Bridge MLS Debug: location.assign called with:', url);
                    navigationCount++;
                    if (navigationCount > 3 && url === lastNavigation) {
                        console.error('Bridge MLS: Navigation loop detected! Blocking navigation.');
                        return;
                    }
                    lastNavigation = url;
                    originalAssign.call(window.location, url);
                };
                
                window.location.replace = function(url) {
                    console.log('Bridge MLS Debug: location.replace called with:', url);
                    navigationCount++;
                    if (navigationCount > 3 && url === lastNavigation) {
                        console.error('Bridge MLS: Navigation loop detected! Blocking navigation.');
                        return;
                    }
                    lastNavigation = url;
                    originalReplace.call(window.location, url);
                };
                
                if (originalHref) {
                    Object.defineProperty(window.location, 'href', {
                        set: function(url) {
                            console.log('Bridge MLS Debug: location.href set to:', url);
                            navigationCount++;
                            if (navigationCount > 3 && url === lastNavigation) {
                                console.error('Bridge MLS: Navigation loop detected! Blocking navigation.');
                                return;
                            }
                            lastNavigation = url;
                            originalHref.set.call(window.location, url);
                        },
                        get: originalHref.get
                    });
                }
                
                // Reset counter periodically
                setInterval(() => {
                    navigationCount = 0;
                }, 5000);
            })();
            
            // Fallback lightbox initialization
            if (typeof window.bridgeMLSApp === 'undefined') {
                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof window.bridgeLightboxFallback === 'function') {
                        window.bridgeLightboxFallback();
                    }
                });
            }
        </script>
        
        <?php
        return ob_get_clean();
    }
    
    /**
     * Featured properties shortcode
     */
    public function featured_properties_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => '6',
            'columns' => '3',
            'title' => 'Featured Properties',
            'city' => '',
            'min_price' => '',
            'max_price' => '',
            'bedrooms' => '',
            'bathrooms' => '',
            'property_type' => '',
            'show_title' => 'true'
        ), $atts);
        
        // Build search parameters
        $search_params = array(
            'limit' => intval($atts['limit'])
        );
        
        foreach (['city', 'min_price', 'max_price', 'bedrooms', 'bathrooms', 'property_type'] as $param) {
            if (!empty($atts[$param])) {
                $search_params[$param] = $atts[$param];
            }
        }
        
        // Get properties
        $properties = $this->search_properties($search_params);
        
        ob_start();
        ?>
        <div class="bridge-featured-properties">
            <?php if ($atts['show_title'] === 'true'): ?>
                <h2 class="featured-title"><?php echo esc_html($atts['title']); ?></h2>
            <?php endif; ?>
            
            <div class="property-grid columns-<?php echo esc_attr($atts['columns']); ?>">
                <?php 
                if ($properties && isset($properties['value'])) {
                    foreach ($properties['value'] as $property) {
                        echo $this->render_property_card($property);
                    }
                } else {
                    echo '<p class="no-properties">No featured properties found.</p>';
                }
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Property details shortcode
     */
    public function property_details_shortcode($atts) {
        // Loop detection
        if (!session_id()) {
            session_start();
        }
        
        $current_url = $_SERVER['REQUEST_URI'];
        $session_key = 'bridge_mls_' . md5($current_url);
        $last_access = isset($_SESSION[$session_key]) ? $_SESSION[$session_key] : 0;
        $now = time();
        
        // If this page was accessed less than 1 second ago, it's likely a loop
        if ($now - $last_access < 1) {
            // Clear the session and show error
            unset($_SESSION[$session_key]);
            return '<div class="error">
                <p>A redirect loop was detected and blocked.</p>
                <p><a href="' . home_url('/mlslookup/') . '">Return to Property Search</a></p>
            </div>';
        }
        
        $_SESSION[$session_key] = $now;
        
        // Clean old session entries (older than 5 minutes)
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, 'bridge_mls_') === 0 && $now - $value > 300) {
                unset($_SESSION[$key]);
            }
        }
        
        // Original shortcode logic
        $atts = shortcode_atts(array(
            'mls_id' => '',
            'listing_key' => ''
        ), $atts);
        
        // Get property identifier from shortcode attributes or URL
        $mls_id = !empty($atts['mls_id']) ? $atts['mls_id'] : 
                  (!empty($_GET['mls']) ? sanitize_text_field($_GET['mls']) : '');
        
        $listing_key = !empty($atts['listing_key']) ? $atts['listing_key'] : 
                       (!empty($_GET['listing_key']) ? sanitize_text_field($_GET['listing_key']) : '');
        
        if (empty($mls_id) && empty($listing_key)) {
            return '<p class="error">Property not specified. Please provide an MLS ID or Listing Key.</p>';
        }
        
        // Get property data
        $property = null;
        if (!empty($listing_key)) {
            $property = $this->get_single_property($listing_key);
        } elseif (!empty($mls_id)) {
            $property = $this->get_property_by_mls_id($mls_id);
        }
        
        if (!$property) {
            return '<p class="error">Property not found or no longer available.</p>';
        }
        
        return $this->render_property_details($property);
    }
    
    /**
     * Render search form
     */
    private function render_search_form($initial_params = array()) {
        $cities = $this->get_ma_cities();
        $selected_cities = isset($initial_params['city']) ? 
            (is_array($initial_params['city']) ? $initial_params['city'] : array($initial_params['city'])) : 
            array();
        ?>
        <form id="bridge-property-search-form" method="get">
            <div class="search-row">
                <div class="search-field search-field-wide">
                    <label for="bridge-city">City</label>
                    <select id="bridge-city" name="city[]" multiple="multiple" class="bridge-multiselect">
                        <?php foreach ($cities as $city): ?>
                            <option value="<?php echo esc_attr($city); ?>" <?php echo in_array($city, $selected_cities) ? 'selected' : ''; ?>>
                                <?php echo esc_html($city); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-field">
                    <label for="bridge-min-price">Min Price</label>
                    <input type="number" id="bridge-min-price" name="min_price" 
                           value="<?php echo esc_attr(isset($initial_params['min_price']) ? $initial_params['min_price'] : ''); ?>" 
                           placeholder="No Min" min="0" step="10000">
                </div>
                
                <div class="search-field">
                    <label for="bridge-max-price">Max Price</label>
                    <input type="number" id="bridge-max-price" name="max_price" 
                           value="<?php echo esc_attr(isset($initial_params['max_price']) ? $initial_params['max_price'] : ''); ?>" 
                           placeholder="No Max" min="0" step="10000">
                </div>
            </div>
            
            <div class="search-row">
                <div class="search-field">
                    <label for="bridge-bedrooms">Bedrooms</label>
                    <select id="bridge-bedrooms" name="bedrooms">
                        <option value="any">Any</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo (isset($initial_params['bedrooms']) && $initial_params['bedrooms'] == $i) ? 'selected' : ''; ?>>
                                <?php echo $i; ?>+
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="search-field">
                    <label for="bridge-bathrooms">Bathrooms</label>
                    <select id="bridge-bathrooms" name="bathrooms">
                        <option value="any">Any</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo (isset($initial_params['bathrooms']) && $initial_params['bathrooms'] == $i) ? 'selected' : ''; ?>>
                                <?php echo $i; ?>+
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="search-field">
                    <label for="bridge-property-type">Property Type</label>
                    <select id="bridge-property-type" name="property_type">
                        <option value="any">Any</option>
                        <option value="Residential" <?php echo (isset($initial_params['property_type']) && $initial_params['property_type'] == 'Residential') ? 'selected' : ''; ?>>Residential</option>
                        <option value="Condominium" <?php echo (isset($initial_params['property_type']) && $initial_params['property_type'] == 'Condominium') ? 'selected' : ''; ?>>Condominium</option>
                        <option value="Townhouse" <?php echo (isset($initial_params['property_type']) && $initial_params['property_type'] == 'Townhouse') ? 'selected' : ''; ?>>Townhouse</option>
                        <option value="Land" <?php echo (isset($initial_params['property_type']) && $initial_params['property_type'] == 'Land') ? 'selected' : ''; ?>>Land</option>
                    </select>
                </div>
                
                <div class="search-field search-field-wide">
                    <label for="bridge-keywords">Keywords</label>
                    <input type="text" id="bridge-keywords" name="keywords" 
                           value="<?php echo esc_attr(isset($initial_params['keywords']) ? $initial_params['keywords'] : ''); ?>" 
                           placeholder="Search property descriptions...">
                </div>
            </div>
            
            <div class="search-actions">
                <button type="button" id="bridge-search-button" class="button button-primary">Search Properties</button>
                <button type="button" id="bridge-clear-button" class="button button-secondary">Clear Filters</button>
            </div>
        </form>
        <?php
    }
    
    /**
     * Render property card
     */
    private function render_property_card($property) {
        $photos = isset($property['Photos']) ? $property['Photos'] : array();
        $primary_photo = !empty($photos) ? $photos[0] : BRIDGE_MLS_PLUGIN_URL . 'assets/no-image.jpg';
        $photo_count = isset($property['PhotosCount']) ? intval($property['PhotosCount']) : count($photos);
        
        // Build the property URL - directly use /property-details/
        $property_url = home_url('/property-details/?mls=' . $property['ListingId']);
        
        ob_start();
        ?>
        <div class="property-card" data-listing-key="<?php echo esc_attr($property['ListingKey']); ?>">
            <div class="property-image">
                <img src="<?php echo esc_url($primary_photo); ?>" 
                     alt="<?php echo esc_attr($property['UnparsedAddress']); ?>" 
                     loading="lazy">
                <?php if ($photo_count > 0): ?>
                    <span class="photo-count">📷 <?php echo $photo_count; ?></span>
                <?php endif; ?>
                <?php if (isset($property['StandardStatus']) && $property['StandardStatus'] === 'Active'): ?>
                    <span class="property-status status-active">Active</span>
                <?php endif; ?>
            </div>
            
            <div class="property-info">
                <div class="property-price">
                    $<?php echo number_format($property['ListPrice']); ?>
                </div>
                
                <div class="property-address">
                    <?php echo esc_html($property['UnparsedAddress']); ?><br>
                    <?php echo esc_html($property['City'] . ', ' . $property['StateOrProvince']); ?>
                </div>
                
                <div class="property-stats">
                    <?php if (!empty($property['BedroomsTotal'])): ?>
                        <span class="property-stat">
                            🛏️ <?php echo intval($property['BedroomsTotal']); ?> beds
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($property['BathroomsTotalInteger'])): ?>
                        <span class="property-stat">
                            🚿 <?php echo intval($property['BathroomsTotalInteger']); ?> baths
                        </span>
                    <?php endif; ?>
                    
                    <?php if (!empty($property['LivingArea'])): ?>
                        <span class="property-stat">
                            📐 <?php echo number_format($property['LivingArea']); ?> sqft
                        </span>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($property['PropertyType'])): ?>
                    <div class="property-type-tag">
                        <span class="property-type"><?php echo esc_html($property['PropertyType']); ?></span>
                    </div>
                <?php endif; ?>
                
                <div class="property-actions">
                    <a href="<?php echo esc_url($property_url); ?>" 
                       class="button button-primary view-details">View Details</a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render property details page
     */
    private function render_property_details($property) {
        $photos = isset($property['Photos']) ? $property['Photos'] : array();
        $options = get_option('bridge_mls_options', array());
        
        ob_start();
        ?>
        <div class="bridge-property-details-modern">
            <div class="property-header">
                <h1 class="property-title"><?php echo esc_html($property['UnparsedAddress']); ?></h1>
                <div class="property-address">
                    <?php echo esc_html($property['City'] . ', ' . $property['StateOrProvince'] . ' ' . $property['PostalCode']); ?>
                </div>
            </div>
            
            <div class="property-content">
                <div class="property-main">
                    <?php $this->render_image_gallery($photos); ?>
                    
                    <div class="property-price-display">
                        <span class="main-price">$<?php echo number_format($property['ListPrice']); ?></span>
                        <?php if (!empty($property['MonthlyPayment'])): ?>
                            <span class="monthly-payment">Est. $<?php echo number_format($property['MonthlyPayment']); ?>/mo</span>
                        <?php endif; ?>
                        <?php if (isset($property['StandardStatus'])): ?>
                            <span class="property-status status-<?php echo strtolower($property['StandardStatus']); ?>">
                                <?php echo esc_html($property['StandardStatus']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="property-stats-horizontal">
                        <?php if (!empty($property['BedroomsTotal'])): ?>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo intval($property['BedroomsTotal']); ?></div>
                                <div class="stat-label">Bedrooms</div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($property['BathroomsTotalInteger'])): ?>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo intval($property['BathroomsTotalInteger']); ?></div>
                                <div class="stat-label">Bathrooms</div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($property['LivingArea'])): ?>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo number_format($property['LivingArea']); ?></div>
                                <div class="stat-label">Sq Ft</div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($property['PricePerSqFt'])): ?>
                            <div class="stat-item">
                                <div class="stat-value">$<?php echo number_format($property['PricePerSqFt']); ?></div>
                                <div class="stat-label">Per Sq Ft</div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($property['DaysOnMarket'])): ?>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo intval($property['DaysOnMarket']); ?></div>
                                <div class="stat-label">Days on Market</div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="property-description">
                        <h2>Property Description</h2>
                        <p><?php echo nl2br(esc_html($property['PublicRemarks'])); ?></p>
                    </div>
                    
                    <div class="property-highlights">
                        <h2>Property Highlights</h2>
                        <div class="highlights-grid">
                            <?php if (!empty($property['PropertyType'])): ?>
                                <div class="highlight-item">
                                    <svg class="highlight-icon" viewBox="0 0 24 24" width="24" height="24">
                                        <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z" fill="currentColor"/>
                                    </svg>
                                    <span>Type: <?php echo esc_html($property['PropertyType']); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($property['YearBuilt'])): ?>
                                <div class="highlight-item">
                                    <svg class="highlight-icon" viewBox="0 0 24 24" width="24" height="24">
                                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" fill="currentColor"/>
                                    </svg>
                                    <span>Year Built: <?php echo esc_html($property['YearBuilt']); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($property['LotSizeArea'])): ?>
                                <div class="highlight-item">
                                    <svg class="highlight-icon" viewBox="0 0 24 24" width="24" height="24">
                                        <path d="M20 2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM8 20H4v-4h4v4zm0-6H4v-4h4v4zm0-6H4V4h4v4zm6 12h-4v-4h4v4zm0-6h-4v-4h4v4zm0-6h-4V4h4v4zm6 12h-4v-4h4v4zm0-6h-4v-4h4v4zm0-6h-4V4h4v4z" fill="currentColor"/>
                                    </svg>
                                    <span>Lot Size: <?php echo number_format($property['LotSizeArea']); ?> sq ft</span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (isset($property['CoolingYN']) && $property['CoolingYN'] === true): ?>
                                <div class="highlight-item">
                                    <svg class="highlight-icon" viewBox="0 0 24 24" width="24" height="24">
                                        <path d="M22 11h-4.17l3.24-3.24-1.41-1.42L15 11h-2V9l4.66-4.66-1.42-1.41L13 6.17V2h-2v4.17L7.76 2.93 6.34 4.34 11 9v2H9L4.34 6.34 2.93 7.76 6.17 11H2v2h4.17l-3.24 3.24 1.41 1.42L9 13h2v2l-4.66 4.66 1.42 1.41L11 17.83V22h2v-4.17l3.24 3.24 1.42-1.41L13 15v-2h2l4.66 4.66 1.41-1.42L17.83 13H22z" fill="currentColor"/>
                                    </svg>
                                    <span>Air Conditioning</span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (isset($property['HeatingYN']) && $property['HeatingYN'] === true): ?>
                                <div class="highlight-item">
                                    <svg class="highlight-icon" viewBox="0 0 24 24" width="24" height="24">
                                        <path d="M12 2l-5.5 9h11z M12 5.84L13.93 9h-3.87z M17.5 13c-2.49 0-4.5 2.01-4.5 4.5s2.01 4.5 4.5 4.5 4.5-2.01 4.5-4.5-2.01-4.5-4.5-4.5zm0 7c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" fill="currentColor"/>
                                    </svg>
                                    <span>Heating</span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($property['FireplacesTotal']) && $property['FireplacesTotal'] > 0): ?>
                                <div class="highlight-item">
                                    <svg class="highlight-icon" viewBox="0 0 24 24" width="24" height="24">
                                        <path d="M12 2C9.24 2 7 4.24 7 7l.09 1L5 11v7c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2v-7l-2.09-3L17 7c0-2.76-2.24-5-5-5zm3 16H9v-5h6v5z" fill="currentColor"/>
                                    </svg>
                                    <span>Fireplaces: <?php echo intval($property['FireplacesTotal']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="property-details-grid">
                        <h2>Listing Information</h2>
                        <div class="details-grid">
                            <div class="detail-item">
                                <span class="detail-label">MLS #:</span>
                                <span class="detail-value"><?php echo esc_html($property['ListingId']); ?></span>
                            </div>
                            
                            <?php if (!empty($property['ListingContractDate'])): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Listed:</span>
                                    <span class="detail-value"><?php echo date('M j, Y', strtotime($property['ListingContractDate'])); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($property['PropertySubType'])): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Style:</span>
                                    <span class="detail-value"><?php echo esc_html($property['PropertySubType']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="property-sidebar">
                    <div class="agent-contact-card">
                        <h3>Contact Agent</h3>
                        
                        <?php if (!empty($options['agent_name'])): ?>
                            <div class="agent-name"><?php echo esc_html($options['agent_name']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($options['agent_company'])): ?>
                            <div class="agent-company"><?php echo esc_html($options['agent_company']); ?></div>
                        <?php endif; ?>
                        
                        <div class="agent-actions">
                            <?php if (!empty($options['agent_phone'])): ?>
                                <a href="tel:<?php echo esc_attr($options['agent_phone']); ?>" class="button button-primary">
                                    📞 Call <?php echo esc_html($options['agent_phone']); ?>
                                </a>
                            <?php endif; ?>
                            
                            <?php if (!empty($options['agent_email'])): ?>
                                <a href="mailto:<?php echo esc_attr($options['agent_email']); ?>?subject=Inquiry about <?php echo urlencode($property['UnparsedAddress']); ?>" 
                                   class="button button-secondary">
                                    ✉️ Email Agent
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="contact-form-section">
                            <h4>Request Information</h4>
                            <form class="agent-contact-form" data-property="<?php echo esc_attr($property['UnparsedAddress']); ?>">
                                <input type="text" name="name" placeholder="Your Name" required>
                                <input type="email" name="email" placeholder="Your Email" required>
                                <input type="tel" name="phone" placeholder="Your Phone">
                                <textarea name="message" rows="4" placeholder="I'm interested in this property..."></textarea>
                                <button type="submit" class="button button-primary">Send Message</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="share-property">
                        <h3>Share This Property</h3>
                        <div class="share-buttons">
                            <button class="share-button" onclick="window.bridgeMLSApp.shareProperty()">
                                📤 Share
                            </button>
                            <button class="share-button" onclick="window.print()">
                                🖨️ Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render image gallery
     */
    private function render_image_gallery($photos) {
        if (empty($photos)) {
            $photos = array(BRIDGE_MLS_PLUGIN_URL . 'assets/no-image.jpg');
        }
        ?>
        <div class="property-gallery">
            <div class="gallery-container">
                <div class="main-image-wrapper">
                    <div class="main-image-container">
                        <img id="main-property-image" 
                             src="<?php echo esc_url($photos[0]); ?>" 
                             alt="Property photo" 
                             data-index="0">
                        
                        <?php if (count($photos) > 1): ?>
                            <button class="gallery-nav-btn gallery-prev" aria-label="Previous image">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="15 18 9 12 15 6"></polyline>
                                </svg>
                            </button>
                            <button class="gallery-nav-btn gallery-next" aria-label="Next image">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="9 18 15 12 9 6"></polyline>
                                </svg>
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (count($photos) > 1): ?>
                        <div class="gallery-dots">
                            <?php for ($i = 0; $i < count($photos); $i++): ?>
                                <button class="gallery-dot <?php echo $i === 0 ? 'active' : ''; ?>" 
                                        data-index="<?php echo $i; ?>" 
                                        aria-label="Go to image <?php echo $i + 1; ?>"></button>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (count($photos) > 1): ?>
                    <div class="side-images">
                        <div class="side-image-container" data-index="1">
                            <img src="<?php echo esc_url(isset($photos[1]) ? $photos[1] : $photos[0]); ?>" 
                                 alt="Property photo 2">
                        </div>
                        <div class="side-image-container" data-index="2">
                            <img src="<?php echo esc_url(isset($photos[2]) ? $photos[2] : (isset($photos[1]) ? $photos[1] : $photos[0])); ?>" 
                                 alt="Property photo 3">
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Mobile swipe container -->
            <div class="mobile-gallery-container">
                <div class="mobile-gallery-track" data-current="0">
                    <?php foreach ($photos as $index => $photo): ?>
                        <div class="mobile-gallery-slide" data-index="<?php echo $index; ?>">
                            <img src="<?php echo esc_url($photo); ?>" alt="Property photo <?php echo $index + 1; ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($photos) > 1): ?>
                    <div class="mobile-gallery-dots">
                        <?php for ($i = 0; $i < count($photos); $i++): ?>
                            <button class="gallery-dot <?php echo $i === 0 ? 'active' : ''; ?>" 
                                    data-index="<?php echo $i; ?>" 
                                    aria-label="Go to image <?php echo $i + 1; ?>"></button>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <script type="text/javascript">
            // Store all photos for gallery navigation
            window.propertyPhotos = <?php echo json_encode($photos); ?>;
        </script>
        <?php
    }
    
    /**
     * Utility: Format price
     */
    private function format_price($price) {
        return '$' . number_format($price);
    }
    
    /**
     * Utility: Calculate monthly payment
     */
    private function calculate_monthly_payment($price) {
        // Assuming 20% down, 30-year mortgage at 7% interest
        $down_payment = $price * 0.2;
        $loan_amount = $price - $down_payment;
        $monthly_rate = 0.07 / 12;
        $num_payments = 30 * 12;
        
        if ($monthly_rate > 0) {
            $monthly_payment = $loan_amount * ($monthly_rate * pow(1 + $monthly_rate, $num_payments)) / (pow(1 + $monthly_rate, $num_payments) - 1);
            return round($monthly_payment);
        }
        
        return 0;
    }
    
    /**
     * Utility: Calculate price per square foot
     */
    private function calculate_price_per_sqft($property) {
        if (!empty($property['ListPrice']) && !empty($property['LivingArea']) && $property['LivingArea'] > 0) {
            return round($property['ListPrice'] / $property['LivingArea']);
        }
        return null;
    }
    
    /**
     * Utility: Calculate days on market
     */
    private function calculate_days_on_market($listing_date) {
        if (!empty($listing_date)) {
            $listed = strtotime($listing_date);
            $today = time();
            $days = floor(($today - $listed) / (60 * 60 * 24));
            return max(0, $days);
        }
        return null;
    }
    
    /**
     * Utility: Debug logging
     */
    private function debug_log($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = 'Bridge MLS: ' . $message;
            if ($data !== null) {
                $log_message .= ' - ' . print_r($data, true);
            }
            error_log($log_message);
        }
    }
    
    /**
     * Get Massachusetts cities
     */
    private function get_ma_cities() {
        return array(
            'Abington', 'Acton', 'Acushnet', 'Adams', 'Agawam', 'Alford', 'Amesbury', 'Amherst', 'Andover', 'Aquinnah', 
            'Arlington', 'Ashburnham', 'Ashby', 'Ashfield', 'Ashland', 'Athol', 'Attleboro', 'Auburn', 'Avon', 'Ayer', 
            'Barnstable', 'Barre', 'Becket', 'Bedford', 'Belchertown', 'Bellingham', 'Belmont', 'Berkley', 'Berlin', 
            'Bernardston', 'Beverly', 'Billerica', 'Blackstone', 'Blandford', 'Bolton', 'Boston', 'Bourne', 'Boxborough', 
            'Boxford', 'Boylston', 'Braintree', 'Brewster', 'Bridgewater', 'Brimfield', 'Brockton', 'Brookfield', 
            'Brookline', 'Buckland', 'Burlington', 'Cambridge', 'Canton', 'Carlisle', 'Carver', 'Charlemont', 'Charlton', 
            'Chatham', 'Chelmsford', 'Chelsea', 'Cheshire', 'Chester', 'Chesterfield', 'Chicopee', 'Chilmark', 'Clarksburg', 
            'Clinton', 'Cohasset', 'Colrain', 'Concord', 'Conway', 'Cummington', 'Dalton', 'Danvers', 'Dartmouth', 
            'Dedham', 'Deerfield', 'Dennis', 'Dighton', 'Douglas', 'Dover', 'Dracut', 'Dudley', 'Dunstable', 'Duxbury', 
            'East Bridgewater', 'East Brookfield', 'East Longmeadow', 'Eastham', 'Easthampton', 'Easton', 'Edgartown', 
            'Egremont', 'Erving', 'Essex', 'Everett', 'Fairhaven', 'Fall River', 'Falmouth', 'Fitchburg', 'Florida', 
            'Foxborough', 'Framingham', 'Franklin', 'Freetown', 'Gardner', 'Georgetown', 'Gill', 'Gloucester', 'Goshen', 
            'Gosnold', 'Grafton', 'Granby', 'Granville', 'Great Barrington', 'Greenfield', 'Groton', 'Groveland', 
            'Hadley', 'Halifax', 'Hamilton', 'Hampden', 'Hancock', 'Hanover', 'Hanson', 'Hardwick', 'Harvard', 'Harwich', 
            'Hatfield', 'Haverhill', 'Hawley', 'Heath', 'Hingham', 'Hinsdale', 'Holbrook', 'Holden', 'Holland', 
            'Holliston', 'Holyoke', 'Hopedale', 'Hopkinton', 'Hubbardston', 'Hudson', 'Hull', 'Huntington', 'Ipswich', 
            'Kingston', 'Lakeville', 'Lancaster', 'Lanesborough', 'Lawrence', 'Lee', 'Leicester', 'Lenox', 'Leominster', 
            'Leverett', 'Lexington', 'Leyden', 'Lincoln', 'Littleton', 'Longmeadow', 'Lowell', 'Ludlow', 'Lunenburg', 
            'Lynn', 'Lynnfield', 'Malden', 'Manchester-by-the-Sea', 'Mansfield', 'Marblehead', 'Marion', 'Marlborough', 
            'Marshfield', 'Mashpee', 'Mattapoisett', 'Maynard', 'Medfield', 'Medford', 'Medway', 'Melrose', 'Mendon', 
            'Merrimac', 'Methuen', 'Middleborough', 'Middlefield', 'Middleton', 'Milford', 'Millbury', 'Millis', 
            'Millville', 'Milton', 'Monroe', 'Monson', 'Montague', 'Monterey', 'Montgomery', 'Mount Washington', 'Nahant', 
            'Nantucket', 'Natick', 'Needham', 'New Ashford', 'New Bedford', 'New Braintree', 'New Marlborough', 
            'New Salem', 'Newbury', 'Newburyport', 'Newton', 'Norfolk', 'North Adams', 'North Andover', 
            'North Attleborough', 'North Brookfield', 'North Reading', 'Northampton', 'Northborough', 'Northbridge', 
            'Northfield', 'Norton', 'Norwell', 'Norwood', 'Oak Bluffs', 'Oakham', 'Orange', 'Orleans', 'Otis', 'Oxford', 
            'Palmer', 'Paxton', 'Peabody', 'Pelham', 'Pembroke', 'Pepperell', 'Peru', 'Petersham', 'Phillipston', 
            'Pittsfield', 'Plainfield', 'Plainville', 'Plymouth', 'Plympton', 'Princeton', 'Provincetown', 'Quincy', 
            'Randolph', 'Raynham', 'Reading', 'Rehoboth', 'Revere', 'Richmond', 'Rochester', 'Rockland', 'Rockport', 
            'Rowe', 'Rowley', 'Royalston', 'Russell', 'Rutland', 'Salem', 'Salisbury', 'Sandisfield', 'Sandwich', 
            'Saugus', 'Savoy', 'Scituate', 'Seekonk', 'Sharon', 'Sheffield', 'Shelburne', 'Sherborn', 'Shirley', 
            'Shrewsbury', 'Shutesbury', 'Somerset', 'Somerville', 'South Hadley', 'Southampton', 'Southborough', 
            'Southbridge', 'Southwick', 'Spencer', 'Springfield', 'Sterling', 'Stockbridge', 'Stoneham', 'Stoughton', 
            'Stow', 'Sturbridge', 'Sudbury', 'Sunderland', 'Sutton', 'Swampscott', 'Swansea', 'Taunton', 'Templeton', 
            'Tewksbury', 'Tisbury', 'Tolland', 'Topsfield', 'Townsend', 'Truro', 'Tyngsborough', 'Tyringham', 'Upton', 
            'Uxbridge', 'Wakefield', 'Wales', 'Walpole', 'Waltham', 'Ware', 'Wareham', 'Warren', 'Warwick', 'Washington', 
            'Watertown', 'Wayland', 'Webster', 'Wellesley', 'Wellfleet', 'Wendell', 'Wenham', 'West Boylston', 
            'West Bridgewater', 'West Brookfield', 'West Newbury', 'West Springfield', 'West Stockbridge', 'West Tisbury', 
            'Westborough', 'Westfield', 'Westford', 'Weston', 'Westport', 'Westwood', 'Weymouth', 'Whately', 'Whitman', 
            'Wilbraham', 'Williamsburg', 'Williamstown', 'Wilmington', 'Winchendon', 'Winchester', 'Windsor', 'Winthrop', 
            'Woburn', 'Worcester', 'Worthington', 'Wrentham', 'Yarmouth'
        );
    }
}

// Initialize plugin
new BridgeMLSPlugin();

// Activation hook
register_activation_hook(__FILE__, 'bridge_mls_activate');
function bridge_mls_activate() {
    // Set default options
    $default_options = array(
        'api_url' => 'https://api.bridgedataoutput.com/api/v2/OData/shared_mlspin_41854c5',
        'server_token' => '1c69fed3083478d187d4ce8deb8788ed',
        'browser_token' => '6c3ff882c868eb6ace6cd2ad9005ea7c',
        'default_columns' => '3',
        'default_limit' => '12',
        'agent_name' => '',
        'agent_phone' => '',
        'agent_email' => '',
        'agent_company' => ''
    );
    
    // Only add options if they don't exist
    if (get_option('bridge_mls_options') === false) {
        add_option('bridge_mls_options', $default_options);
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'bridge_mls_deactivate');
function bridge_mls_deactivate() {
    // Clear any transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bridge_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_bridge_%'");
    
    // Flush rewrite rules
    flush_rewrite_rules();
}