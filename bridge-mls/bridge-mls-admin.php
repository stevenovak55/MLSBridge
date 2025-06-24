<?php
/**
 * Bridge MLS Admin Interface
 * 
 * Handles all admin functionality including settings pages,
 * options management, and documentation.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BridgeMLSAdmin {
    
    private $options;
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_options_page(
            'Bridge MLS Settings',
            'Bridge MLS',
            'manage_options',
            'bridge-mls-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'options-general.php',
            'Bridge MLS Documentation',
            'MLS Documentation',
            'manage_options',
            'bridge-mls-docs',
            array($this, 'documentation_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_bridge-mls-settings' && $hook !== 'settings_page_bridge-mls-docs') {
            return;
        }
        
        wp_enqueue_style('bridge-mls-admin', BRIDGE_MLS_PLUGIN_URL . 'assets/bridge-mls-admin.css', array(), BRIDGE_MLS_VERSION);
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting(
            'bridge_mls_settings',
            'bridge_mls_options',
            array($this, 'sanitize_settings')
        );
        
        // API Configuration Section
        add_settings_section(
            'bridge_mls_api_section',
            'API Configuration',
            array($this, 'api_section_callback'),
            'bridge-mls-settings'
        );
        
        add_settings_field(
            'api_url',
            'API Base URL',
            array($this, 'api_url_callback'),
            'bridge-mls-settings',
            'bridge_mls_api_section'
        );
        
        add_settings_field(
            'server_token',
            'Server Token',
            array($this, 'server_token_callback'),
            'bridge-mls-settings',
            'bridge_mls_api_section'
        );
        
        add_settings_field(
            'browser_token',
            'Browser Token',
            array($this, 'browser_token_callback'),
            'bridge-mls-settings',
            'bridge_mls_api_section'
        );
        
        // Display Settings Section
        add_settings_section(
            'bridge_mls_display_section',
            'Display Settings',
            array($this, 'display_section_callback'),
            'bridge-mls-settings'
        );
        
        add_settings_field(
            'default_columns',
            'Default Grid Columns',
            array($this, 'default_columns_callback'),
            'bridge-mls-settings',
            'bridge_mls_display_section'
        );
        
        add_settings_field(
            'default_limit',
            'Properties Per Page',
            array($this, 'default_limit_callback'),
            'bridge-mls-settings',
            'bridge_mls_display_section'
        );
        
        // Agent Information Section
        add_settings_section(
            'bridge_mls_agent_section',
            'Agent Information',
            array($this, 'agent_section_callback'),
            'bridge-mls-settings'
        );
        
        add_settings_field(
            'agent_name',
            'Agent Name',
            array($this, 'agent_name_callback'),
            'bridge-mls-settings',
            'bridge_mls_agent_section'
        );
        
        add_settings_field(
            'agent_phone',
            'Agent Phone',
            array($this, 'agent_phone_callback'),
            'bridge-mls-settings',
            'bridge_mls_agent_section'
        );
        
        add_settings_field(
            'agent_email',
            'Agent Email',
            array($this, 'agent_email_callback'),
            'bridge-mls-settings',
            'bridge_mls_agent_section'
        );
        
        add_settings_field(
            'agent_company',
            'Agent Company',
            array($this, 'agent_company_callback'),
            'bridge-mls-settings',
            'bridge_mls_agent_section'
        );
    }
    
    /**
     * Section callbacks
     */
    public function api_section_callback() {
        echo '<p>Configure your Bridge MLS API credentials. These are required for the plugin to function.</p>';
    }
    
    public function display_section_callback() {
        echo '<p>Configure how properties are displayed on your website.</p>';
    }
    
    public function agent_section_callback() {
        echo '<p>Enter your contact information to display on property detail pages.</p>';
    }
    
    /**
     * Field callbacks
     */
    public function api_url_callback() {
        $this->options = get_option('bridge_mls_options');
        $value = isset($this->options['api_url']) ? esc_attr($this->options['api_url']) : 'https://api.bridgedataoutput.com/api/v2/OData/shared_mlspin_41854c5';
        echo '<input type="url" id="api_url" name="bridge_mls_options[api_url]" value="' . $value . '" size="60" />';
        echo '<p class="description">The base URL for the Bridge MLS API</p>';
    }
    
    public function server_token_callback() {
        $value = isset($this->options['server_token']) ? esc_attr($this->options['server_token']) : '';
        echo '<input type="text" id="server_token" name="bridge_mls_options[server_token]" value="' . $value . '" size="40" />';
        echo '<p class="description">Server-side authentication token</p>';
    }
    
    public function browser_token_callback() {
        $value = isset($this->options['browser_token']) ? esc_attr($this->options['browser_token']) : '';
        echo '<input type="text" id="browser_token" name="bridge_mls_options[browser_token]" value="' . $value . '" size="40" />';
        echo '<p class="description">Browser-side authentication token (for future use)</p>';
    }
    
    public function default_columns_callback() {
        $value = isset($this->options['default_columns']) ? esc_attr($this->options['default_columns']) : '3';
        echo '<select id="default_columns" name="bridge_mls_options[default_columns]">';
        for ($i = 1; $i <= 4; $i++) {
            $selected = selected($value, $i, false);
            echo '<option value="' . $i . '"' . $selected . '>' . $i . ' Column' . ($i > 1 ? 's' : '') . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Default number of columns for property grids</p>';
    }
    
    public function default_limit_callback() {
        $value = isset($this->options['default_limit']) ? esc_attr($this->options['default_limit']) : '12';
        echo '<input type="number" id="default_limit" name="bridge_mls_options[default_limit]" value="' . $value . '" min="1" max="50" />';
        echo '<p class="description">Default number of properties to display per page</p>';
    }
    
    public function agent_name_callback() {
        $value = isset($this->options['agent_name']) ? esc_attr($this->options['agent_name']) : '';
        echo '<input type="text" id="agent_name" name="bridge_mls_options[agent_name]" value="' . $value . '" size="30" />';
        echo '<p class="description">Agent name to display on property details</p>';
    }
    
    public function agent_phone_callback() {
        $value = isset($this->options['agent_phone']) ? esc_attr($this->options['agent_phone']) : '';
        echo '<input type="tel" id="agent_phone" name="bridge_mls_options[agent_phone]" value="' . $value . '" size="20" />';
        echo '<p class="description">Agent phone number for contact</p>';
    }
    
    public function agent_email_callback() {
        $value = isset($this->options['agent_email']) ? esc_attr($this->options['agent_email']) : '';
        echo '<input type="email" id="agent_email" name="bridge_mls_options[agent_email]" value="' . $value . '" size="30" />';
        echo '<p class="description">Agent email address for contact</p>';
    }
    
    public function agent_company_callback() {
        $value = isset($this->options['agent_company']) ? esc_attr($this->options['agent_company']) : '';
        echo '<input type="text" id="agent_company" name="bridge_mls_options[agent_company]" value="' . $value . '" size="30" />';
        echo '<p class="description">Real estate company or brokerage name</p>';
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['api_url'])) {
            $sanitized['api_url'] = esc_url_raw($input['api_url']);
        }
        
        if (isset($input['server_token'])) {
            $sanitized['server_token'] = sanitize_text_field($input['server_token']);
        }
        
        if (isset($input['browser_token'])) {
            $sanitized['browser_token'] = sanitize_text_field($input['browser_token']);
        }
        
        if (isset($input['default_columns'])) {
            $sanitized['default_columns'] = intval($input['default_columns']);
            if ($sanitized['default_columns'] < 1 || $sanitized['default_columns'] > 4) {
                $sanitized['default_columns'] = 3;
            }
        }
        
        if (isset($input['default_limit'])) {
            $sanitized['default_limit'] = intval($input['default_limit']);
            if ($sanitized['default_limit'] < 1 || $sanitized['default_limit'] > 50) {
                $sanitized['default_limit'] = 12;
            }
        }
        
        if (isset($input['agent_name'])) {
            $sanitized['agent_name'] = sanitize_text_field($input['agent_name']);
        }
        
        if (isset($input['agent_phone'])) {
            $sanitized['agent_phone'] = sanitize_text_field($input['agent_phone']);
        }
        
        if (isset($input['agent_email'])) {
            $sanitized['agent_email'] = sanitize_email($input['agent_email']);
        }
        
        if (isset($input['agent_company'])) {
            $sanitized['agent_company'] = sanitize_text_field($input['agent_company']);
        }
        
        return $sanitized;
    }
    
    /**
     * Settings page HTML
     */
    public function settings_page() {
        $this->options = get_option('bridge_mls_options');
        ?>
        <div class="wrap bridge-mls-admin">
            <h1>Bridge MLS Settings</h1>
            
            <div class="bridge-admin-container">
                <div class="bridge-admin-main">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('bridge_mls_settings');
                        do_settings_sections('bridge-mls-settings');
                        submit_button();
                        ?>
                    </form>
                    
                    <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                        <div class="bridge-debug-section">
                            <h2>Debug Tools</h2>
                            <button type="button" id="bridge-admin-test-api" class="button button-secondary">Test API Connection</button>
                            <div id="bridge-admin-api-status" style="margin-top: 10px;"></div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="bridge-admin-sidebar">
                    <div class="bridge-sidebar-box">
                        <h3>Quick Setup</h3>
                        <ol>
                            <li>Configure API credentials above</li>
                            <li>Test the API connection</li>
                            <li>Add agent information</li>
                            <li>Create a property search page</li>
                            <li>Use shortcode: <code>[bridge_property_search]</code></li>
                        </ol>
                        <a href="<?php echo admin_url('options-general.php?page=bridge-mls-docs'); ?>" class="button button-primary">View Documentation</a>
                    </div>
                    
                    <div class="bridge-sidebar-box">
                        <h3>Shortcodes</h3>
                        <p><strong>Property Search:</strong><br>
                        <code>[bridge_property_search]</code></p>
                        
                        <p><strong>Featured Properties:</strong><br>
                        <code>[bridge_featured_properties limit="6"]</code></p>
                        
                        <p><strong>Property Details:</strong><br>
                        <code>[bridge_property_details]</code></p>
                    </div>
                    
                    <div class="bridge-sidebar-box">
                        <h3>Support</h3>
                        <p>Need help? Check the documentation page for detailed setup instructions and troubleshooting tips.</p>
                    </div>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#bridge-admin-test-api').on('click', function() {
                const button = $(this);
                const status = $('#bridge-admin-api-status');
                
                button.prop('disabled', true).text('Testing...');
                status.html('<em>Testing API connection...</em>');
                
                $.post(ajaxurl, {
                    action: 'bridge_test_api',
                    nonce: '<?php echo wp_create_nonce('bridge_mls_nonce'); ?>'
                })
                .done(function(response) {
                    if (response.success) {
                        status.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        if (response.data.tests) {
                            let testHtml = '<ul>';
                            $.each(response.data.tests, function(key, test) {
                                const icon = test.success ? '✅' : '❌';
                                testHtml += '<li>' + icon + ' ' + test.name + ': ' + test.message + '</li>';
                            });
                            testHtml += '</ul>';
                            status.append(testHtml);
                        }
                    } else {
                        status.html('<div class="notice notice-error inline"><p>API Test Failed: ' + response.data + '</p></div>');
                    }
                })
                .fail(function() {
                    status.html('<div class="notice notice-error inline"><p>Connection failed. Please check your settings.</p></div>');
                })
                .always(function() {
                    button.prop('disabled', false).text('Test API Connection');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Documentation page HTML
     */
    public function documentation_page() {
        ?>
        <div class="wrap bridge-mls-docs">
            <h1>Bridge MLS Documentation</h1>
            
            <div class="bridge-docs-container">
                <div class="bridge-docs-toc">
                    <h3>Table of Contents</h3>
                    <ol>
                        <li><a href="#getting-started">Getting Started</a></li>
                        <li><a href="#shortcodes">Shortcodes</a></li>
                        <li><a href="#property-search">Property Search</a></li>
                        <li><a href="#property-details">Property Details</a></li>
                        <li><a href="#customization">Customization</a></li>
                        <li><a href="#troubleshooting">Troubleshooting</a></li>
                        <li><a href="#api-reference">API Reference</a></li>
                    </ol>
                </div>
                
                <div class="bridge-docs-content">
                    <section id="getting-started">
                        <h2>Getting Started</h2>
                        <p>Welcome to the Bridge MLS WordPress plugin! This plugin provides a complete MLS integration solution for your WordPress website.</p>
                        
                        <h3>Initial Setup</h3>
                        <ol>
                            <li><strong>Configure API Credentials:</strong> Go to Settings → Bridge MLS and enter your API credentials.</li>
                            <li><strong>Test Connection:</strong> Click the "Test API Connection" button to verify your credentials are working.</li>
                            <li><strong>Add Agent Information:</strong> Fill in your contact details to display on property pages.</li>
                            <li><strong>Create Pages:</strong> Create WordPress pages for your property search and details.</li>
                            <li><strong>Add Shortcodes:</strong> Add the appropriate shortcodes to your pages.</li>
                        </ol>
                    </section>
                    
                    <section id="shortcodes">
                        <h2>Shortcodes</h2>
                        
                        <h3>[bridge_property_search]</h3>
                        <p>Displays a full property search interface with filters and results.</p>
                        <h4>Parameters:</h4>
                        <ul>
                            <li><code>title</code> - The title to display above the search (default: "Property Search")</li>
                            <li><code>show_search</code> - Show/hide the search form (default: "true")</li>
                            <li><code>columns</code> - Number of columns for property grid (default: "3")</li>
                            <li><code>limit</code> - Number of properties to display (default: "12")</li>
                            <li><code>city</code> - Pre-filter by city</li>
                            <li><code>min_price</code> - Minimum price filter</li>
                            <li><code>max_price</code> - Maximum price filter</li>
                            <li><code>bedrooms</code> - Minimum bedrooms</li>
                            <li><code>bathrooms</code> - Minimum bathrooms</li>
                            <li><code>property_type</code> - Filter by property type</li>
                            <li><code>show_title</code> - Show/hide the title (default: "true")</li>
                        </ul>
                        
                        <h4>Example:</h4>
                        <pre><code>[bridge_property_search title="Find Your Dream Home" columns="4" limit="16" city="Boston"]</code></pre>
                        
                        <h3>[bridge_featured_properties]</h3>
                        <p>Displays a grid of featured properties without the search form.</p>
                        <h4>Parameters:</h4>
                        <ul>
                            <li><code>limit</code> - Number of properties to display (default: "6")</li>
                            <li><code>columns</code> - Number of columns (default: "3")</li>
                            <li><code>title</code> - Section title (default: "Featured Properties")</li>
                            <li><code>city</code> - Filter by city</li>
                            <li><code>min_price</code> - Minimum price</li>
                            <li><code>max_price</code> - Maximum price</li>
                            <li><code>show_title</code> - Show/hide title (default: "true")</li>
                        </ul>
                        
                        <h4>Example:</h4>
                        <pre><code>[bridge_featured_properties limit="8" columns="4" title="Latest Listings" city="Cambridge"]</code></pre>
                        
                        <h3>[bridge_property_details]</h3>
                        <p>Displays detailed information about a single property.</p>
                        <h4>Parameters:</h4>
                        <ul>
                            <li><code>mls_id</code> - The MLS ID of the property</li>
                            <li><code>listing_key</code> - The listing key of the property</li>
                        </ul>
                        <p><strong>Note:</strong> Usually used on a dedicated property details page with URL parameters.</p>
                    </section>
                    
                    <section id="property-search">
                        <h2>Property Search</h2>
                        
                        <h3>Search Features</h3>
                        <ul>
                            <li><strong>Multi-City Selection:</strong> Users can select multiple cities using the Select2 dropdown</li>
                            <li><strong>Price Range:</strong> Min and max price filters</li>
                            <li><strong>Bedrooms/Bathrooms:</strong> Minimum room count filters</li>
                            <li><strong>Property Type:</strong> Filter by Residential, Condominium, Townhouse, or Land</li>
                            <li><strong>Keyword Search:</strong> Search within property descriptions</li>
                            <li><strong>Real-time Updates:</strong> Results update automatically as filters change</li>
                        </ul>
                        
                        <h3>URL Parameters</h3>
                        <p>Search pages support URL parameters for direct linking:</p>
                        <ul>
                            <li><code>?city=Boston,Cambridge</code> - Multiple cities (comma-separated)</li>
                            <li><code>?min_price=300000&max_price=500000</code> - Price range</li>
                            <li><code>?bedrooms=3&bathrooms=2</code> - Room requirements</li>
                            <li><code>?property_type=Condominium</code> - Property type</li>
                            <li><code>?keywords=waterfront</code> - Keyword search</li>
                        </ul>
                    </section>
                    
                    <section id="property-details">
                        <h2>Property Details</h2>
                        
                        <h3>Page Setup</h3>
                        <ol>
                            <li>Create a new page called "Property Details"</li>
                            <li>Add the shortcode: <code>[bridge_property_details]</code></li>
                            <li>Properties will be accessible via: <code>/property-details/?mls=12345</code></li>
                        </ol>
                        
                        <h3>Features</h3>
                        <ul>
                            <li><strong>Image Gallery:</strong> Main image with thumbnail grid</li>
                            <li><strong>Lightbox:</strong> Click images to view in fullscreen</li>
                            <li><strong>Property Information:</strong> All available details displayed</li>
                            <li><strong>Agent Contact:</strong> Your contact information with contact form</li>
                            <li><strong>Share Functionality:</strong> Easy sharing options</li>
                            <li><strong>Mobile Responsive:</strong> Optimized for all devices</li>
                        </ul>
                    </section>
                    
                    <section id="customization">
                        <h2>Customization</h2>
                        
                        <h3>CSS Customization</h3>
                        <p>The plugin uses the following main CSS classes that you can override:</p>
                        <ul>
                            <li><code>.bridge-mls-container</code> - Main container</li>
                            <li><code>.bridge-property-search</code> - Search form container</li>
                            <li><code>.property-grid</code> - Property grid container</li>
                            <li><code>.property-card</code> - Individual property cards</li>
                            <li><code>.bridge-property-details-modern</code> - Property details page</li>
                        </ul>
                        
                        <h3>Color Scheme</h3>
                        <p>To change the color scheme, add custom CSS to your theme:</p>
                        <pre><code>/* Example: Change primary button color */
.button-primary {
    background-color: #your-color;
    border-color: #your-color;
}

/* Change accent color */
.property-status.status-active {
    background-color: #your-color;
}</code></pre>
                        
                        <h3>Template Override</h3>
                        <p>You can create custom templates by using WordPress hooks and filters. Contact support for advanced customization options.</p>
                    </section>
                    
                    <section id="troubleshooting">
                        <h2>Troubleshooting</h2>
                        
                        <h3>Common Issues</h3>
                        
                        <h4>API Connection Failed</h4>
                        <ul>
                            <li>Verify your API credentials are correct</li>
                            <li>Check if your server can make external HTTPS requests</li>
                            <li>Look for firewall or security plugin conflicts</li>
                            <li>Enable WP_DEBUG to see detailed error messages</li>
                        </ul>
                        
                        <h4>No Properties Displaying</h4>
                        <ul>
                            <li>Ensure API connection is working</li>
                            <li>Check if filters are too restrictive</li>
                            <li>Verify properties exist in the selected area</li>
                            <li>Clear browser cache and WordPress transients</li>
                        </ul>
                        
                        <h4>Images Not Loading</h4>
                        <ul>
                            <li>Check browser console for errors</li>
                            <li>Verify image URLs are accessible</li>
                            <li>Look for HTTPS/HTTP mixed content issues</li>
                            <li>Check if a security plugin is blocking external images</li>
                        </ul>
                        
                        <h4>JavaScript Errors</h4>
                        <ul>
                            <li>Check for jQuery conflicts with other plugins</li>
                            <li>Ensure Select2 library is loading correctly</li>
                            <li>Test with default WordPress theme</li>
                            <li>Disable other plugins to isolate conflicts</li>
                        </ul>
                        
                        <h3>Debug Mode</h3>
                        <p>Enable WordPress debug mode to see detailed error messages:</p>
                        <pre><code>// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);</code></pre>
                    </section>
                    
                    <section id="api-reference">
                        <h2>API Reference</h2>
                        
                        <h3>Available Fields</h3>
                        <p>The following fields are available from the Bridge MLS API:</p>
                        
                        <h4>Basic Fields:</h4>
                        <ul>
                            <li><code>ListingKey</code> - Unique identifier</li>
                            <li><code>ListingId</code> - MLS number</li>
                            <li><code>ListPrice</code> - Listing price</li>
                            <li><code>BedroomsTotal</code> - Number of bedrooms</li>
                            <li><code>BathroomsTotalInteger</code> - Number of bathrooms</li>
                            <li><code>LivingArea</code> - Square footage</li>
                            <li><code>City</code> - City name</li>
                            <li><code>StateOrProvince</code> - State</li>
                            <li><code>PostalCode</code> - ZIP code</li>
                            <li><code>UnparsedAddress</code> - Full address</li>
                        </ul>
                        
                        <h4>Extended Fields:</h4>
                        <ul>
                            <li><code>PublicRemarks</code> - Property description</li>
                            <li><code>PropertyType</code> - Type of property</li>
                            <li><code>PropertySubType</code> - Property style</li>
                            <li><code>Media</code> - Array of media objects</li>
                            <li><code>PhotosCount</code> - Number of photos</li>
                            <li><code>YearBuilt</code> - Year constructed</li>
                            <li><code>LotSizeArea</code> - Lot size</li>
                            <li><code>StandardStatus</code> - Listing status</li>
                        </ul>
                        
                        <h3>Filter Operators</h3>
                        <p>OData filter operators used in API queries:</p>
                        <ul>
                            <li><code>eq</code> - Equals</li>
                            <li><code>ne</code> - Not equals</li>
                            <li><code>gt</code> - Greater than</li>
                            <li><code>ge</code> - Greater than or equal</li>
                            <li><code>lt</code> - Less than</li>
                            <li><code>le</code> - Less than or equal</li>
                            <li><code>and</code> - Logical AND</li>
                            <li><code>or</code> - Logical OR</li>
                            <li><code>contains()</code> - String contains</li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>
        <?php
    }
}