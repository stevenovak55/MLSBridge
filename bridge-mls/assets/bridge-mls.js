/**
 * Bridge MLS Frontend JavaScript
 * Version 3.0.0
 */

(function($) {
    'use strict';

    /**
     * Main Bridge MLS Application Class
     */
    class BridgeMLSApp {
        constructor() {
            this.currentSearchParams = {};
            this.currentImages = [];
            this.currentImageIndex = 0;
            this.searchTimeout = null;
            this.init();
        }
        
        /**
         * Initialize the application
         */
        init() {
            console.log('Bridge MLS: Initializing application...');
            
            // Add flag to prevent reload loops
            this._isTransitioning = false;
            this._lastSearch = null;
            
            this.bindEvents();
            this.initSelect2();
            this.loadInitialProperties();
            
            // Initialize gallery on property details pages
            if ($('.bridge-property-details-modern').length) {
                console.log('Bridge MLS: Property details page detected');
                this.initPropertyDetailsPage();
            }
            
            // Don't handle URL params - let PHP handle the routing
        }
        
        /**
         * Bind event handlers
         */
        bindEvents() {
            // Search form events
            $(document).on('click', '#bridge-search-button', () => this.performSearch());
            $(document).on('click', '#bridge-clear-button', () => this.clearFilters());
            
            // Real-time search as user types/changes filters
            $(document).on('change', '#bridge-property-search-form select', () => this.performSearch());
            $(document).on('input', '#bridge-property-search-form input[type="number"]', () => this.debouncedSearch());
            $(document).on('input', '#bridge-keywords', () => this.debouncedSearch());
            
            // REMOVED ALL PROPERTY CARD CLICK HANDLERS
            // Only the View Details button will be clickable
            
            // Debug mode API test
            if (bridgeMLS.debug) {
                $(document).on('click', '#bridge-test-api', () => this.testAPIConnection());
            }
            
            // Contact form submission
            $(document).on('submit', '.agent-contact-form', (e) => this.handleContactForm(e));
        }
        
        /**
         * Initialize Select2 for city multiselect
         */
        initSelect2() {
            if ($.fn.select2) {
                $('.bridge-multiselect').select2({
                    placeholder: 'Select cities...',
                    allowClear: true,
                    width: '100%'
                });
                
                // Handle Select2 change events
                $('.bridge-multiselect').on('select2:select select2:unselect', () => {
                    this.performSearch();
                });
            }
        }
        
        /**
         * Initialize property details page functionality
         */
        initPropertyDetailsPage() {
            console.log('Bridge MLS: Initializing property details page...');
            this.initGalleryEvents();
            this.initLightbox();
        }
        
        /**
         * Initialize gallery event handlers
         */
        initGalleryEvents() {
            // Thumbnail clicks
            $(document).on('click', '.thumbnail', (e) => {
                const index = $(e.currentTarget).data('index');
                const fullUrl = $(e.currentTarget).data('full');
                
                // Update main image
                $('#main-property-image').attr('src', fullUrl).data('index', index);
                
                // Update active thumbnail
                $('.thumbnail').removeClass('active');
                $(e.currentTarget).addClass('active');
            });
            
            // Gallery navigation
            $(document).on('click', '.gallery-prev', () => this.navigateGallery(-1));
            $(document).on('click', '.gallery-next', () => this.navigateGallery(1));
        }
        
        /**
         * Navigate gallery images
         */
        navigateGallery(direction) {
            const currentIndex = parseInt($('#main-property-image').data('index') || 0);
            const thumbnails = $('.thumbnail');
            const totalImages = thumbnails.length + 1; // +1 for main image
            
            let newIndex = currentIndex + direction;
            if (newIndex < 0) newIndex = totalImages - 1;
            if (newIndex >= totalImages) newIndex = 0;
            
            if (newIndex === 0) {
                // Show original main image
                const originalSrc = $('#main-property-image').data('original-src');
                if (originalSrc) {
                    $('#main-property-image').attr('src', originalSrc).data('index', 0);
                }
            } else {
                // Show thumbnail image
                const thumbnail = thumbnails.eq(newIndex - 1);
                thumbnail.trigger('click');
            }
        }
        
        /**
         * Initialize modern lightbox
         */
        initLightbox() {
            console.log('Bridge MLS: Initializing lightbox...');
            
            // Create lightbox HTML if it doesn't exist
            if (!$('#gallery-lightbox').length) {
                const lightboxHTML = `
                    <div id="gallery-lightbox" class="gallery-lightbox" style="display: none;">
                        <div class="lightbox-content">
                            <span class="lightbox-close">&times;</span>
                            <img class="lightbox-image" src="" alt="">
                            <div class="lightbox-nav">
                                <button class="lightbox-prev">&#10094;</button>
                                <button class="lightbox-next">&#10095;</button>
                            </div>
                            <div class="lightbox-counter"></div>
                        </div>
                    </div>
                `;
                $('body').append(lightboxHTML);
            }
            
            // Collect images
            this.collectGalleryImages();
            
            // Bind lightbox events
            this.bindLightboxEvents();
        }
        
        /**
         * Collect all gallery images
         */
        collectGalleryImages() {
            this.currentImages = [];
            
            // Method 1: From main image and thumbnails
            const mainImage = $('#main-property-image');
            if (mainImage.length && mainImage.attr('src')) {
                this.currentImages.push(mainImage.attr('src'));
            }
            
            $('.thumbnail').each((index, thumb) => {
                const fullUrl = $(thumb).data('full');
                if (fullUrl && this.currentImages.indexOf(fullUrl) === -1) {
                    this.currentImages.push(fullUrl);
                }
            });
            
            // Method 2: Fallback from all property images
            if (this.currentImages.length === 0) {
                $('.property-image img').each((index, img) => {
                    const src = $(img).attr('src');
                    if (src && this.currentImages.indexOf(src) === -1) {
                        this.currentImages.push(src);
                    }
                });
            }
            
            console.log('Bridge MLS: Collected', this.currentImages.length, 'images');
        }
        
        /**
         * Bind lightbox event handlers
         */
        bindLightboxEvents() {
            // Image clicks
            $(document).on('click', '.property-image img, .thumbnail img, #main-property-image', (e) => {
                e.preventDefault();
                const clickedSrc = $(e.target).attr('src');
                const fullSrc = $(e.target).closest('.thumbnail').data('full') || clickedSrc;
                this.openLightbox(fullSrc);
            });
            
            // Navigation
            $(document).on('click', '.lightbox-prev', () => this.previousImage());
            $(document).on('click', '.lightbox-next', () => this.nextImage());
            
            // Close events
            $(document).on('click', '.lightbox-close', () => this.closeLightbox());
            $(document).on('click', '#gallery-lightbox', (e) => {
                if (e.target.id === 'gallery-lightbox') {
                    this.closeLightbox();
                }
            });
            
            // Keyboard navigation
            $(document).on('keydown', (e) => {
                if ($('#gallery-lightbox').is(':visible')) {
                    if (e.key === 'Escape') this.closeLightbox();
                    if (e.key === 'ArrowLeft') this.previousImage();
                    if (e.key === 'ArrowRight') this.nextImage();
                }
            });
            
            // Touch/swipe support for mobile
            this.initTouchSupport();
        }
        
        /**
         * Initialize touch/swipe support for mobile
         */
        initTouchSupport() {
            let touchStartX = 0;
            let touchEndX = 0;
            
            $('#gallery-lightbox').on('touchstart', (e) => {
                touchStartX = e.changedTouches[0].screenX;
            });
            
            $('#gallery-lightbox').on('touchend', (e) => {
                touchEndX = e.changedTouches[0].screenX;
                this.handleSwipe(touchStartX, touchEndX);
            });
        }
        
        /**
         * Handle swipe gestures
         */
        handleSwipe(startX, endX) {
            const threshold = 50;
            const diff = startX - endX;
            
            if (Math.abs(diff) > threshold) {
                if (diff > 0) {
                    // Swipe left - next image
                    this.nextImage();
                } else {
                    // Swipe right - previous image
                    this.previousImage();
                }
            }
        }
        
        /**
         * Open lightbox with specific image
         */
        openLightbox(imageSrc) {
            console.log('Bridge MLS: Opening lightbox for:', imageSrc);
            
            this.currentImageIndex = this.currentImages.indexOf(imageSrc);
            if (this.currentImageIndex === -1) {
                this.currentImageIndex = 0;
            }
            
            this.updateLightboxImage();
            $('#gallery-lightbox').fadeIn(300).addClass('show');
            $('body').addClass('lightbox-open');
        }
        
        /**
         * Update lightbox image display
         */
        updateLightboxImage() {
            if (this.currentImages.length === 0) return;
            
            const currentImage = this.currentImages[this.currentImageIndex];
            $('.lightbox-image').attr('src', currentImage);
            $('.lightbox-counter').text(`${this.currentImageIndex + 1} of ${this.currentImages.length}`);
            
            // Show/hide navigation buttons
            $('.lightbox-prev, .lightbox-next').toggle(this.currentImages.length > 1);
        }
        
        /**
         * Show previous image in lightbox
         */
        previousImage() {
            if (this.currentImages.length <= 1) return;
            this.currentImageIndex = (this.currentImageIndex - 1 + this.currentImages.length) % this.currentImages.length;
            this.updateLightboxImage();
        }
        
        /**
         * Show next image in lightbox
         */
        nextImage() {
            if (this.currentImages.length <= 1) return;
            this.currentImageIndex = (this.currentImageIndex + 1) % this.currentImages.length;
            this.updateLightboxImage();
        }
        
        /**
         * Close lightbox
         */
        closeLightbox() {
            $('#gallery-lightbox').fadeOut(300).removeClass('show');
            $('body').removeClass('lightbox-open');
        }
        
        /**
         * Load initial properties based on URL parameters or defaults
         */
        loadInitialProperties() {
            if (window.bridgeInitialParams) {
                this.currentSearchParams = window.bridgeInitialParams;
                
                // Set form values from initial params
                if (this.currentSearchParams.city) {
                    const cities = Array.isArray(this.currentSearchParams.city) ? 
                        this.currentSearchParams.city : 
                        this.currentSearchParams.city.split(',');
                    $('#bridge-city').val(cities).trigger('change');
                }
                
                if (this.currentSearchParams.min_price) {
                    $('#bridge-min-price').val(this.currentSearchParams.min_price);
                }
                
                if (this.currentSearchParams.max_price) {
                    $('#bridge-max-price').val(this.currentSearchParams.max_price);
                }
                
                if (this.currentSearchParams.bedrooms) {
                    $('#bridge-bedrooms').val(this.currentSearchParams.bedrooms);
                }
                
                if (this.currentSearchParams.bathrooms) {
                    $('#bridge-bathrooms').val(this.currentSearchParams.bathrooms);
                }
                
                if (this.currentSearchParams.property_type) {
                    $('#bridge-property-type').val(this.currentSearchParams.property_type);
                }
                
                if (this.currentSearchParams.keywords) {
                    $('#bridge-keywords').val(this.currentSearchParams.keywords);
                }
            }
        }
        
        /**
         * Handle URL parameters
         */
        handleURLParams() {
            const urlParams = new URLSearchParams(window.location.search);
            
            // Check for property details
            const mlsId = urlParams.get('mls');
            if (mlsId && $('.bridge-property-details-modern').length === 0) {
                // Only redirect if we're not already on the property details page
                const currentPath = window.location.pathname;
                if (!currentPath.includes('/property-details')) {
                    const detailsUrl = window.location.origin + '/property-details/?mls=' + mlsId;
                    window.location.replace(detailsUrl); // Use replace instead of href to avoid history issues
                }
            }
        }
        
        /**
         * Perform property search
         */
        performSearch(updateURL = true) {
            console.log('Bridge MLS: Performing search...');
            
            // Prevent duplicate searches
            const searchParams = {
                city: $('#bridge-city').val(),
                min_price: $('#bridge-min-price').val(),
                max_price: $('#bridge-max-price').val(),
                bedrooms: $('#bridge-bedrooms').val(),
                bathrooms: $('#bridge-bathrooms').val(),
                property_type: $('#bridge-property-type').val(),
                keywords: $('#bridge-keywords').val()
            };
            
            // Remove empty values
            Object.keys(searchParams).forEach(key => {
                if (!searchParams[key] || searchParams[key] === 'any') {
                    delete searchParams[key];
                }
            });
            
            // Check if this is the same as the last search to prevent loops
            const searchKey = JSON.stringify(searchParams);
            if (this._lastSearch === searchKey) {
                console.log('Bridge MLS: Duplicate search prevented');
                return;
            }
            this._lastSearch = searchKey;
            
            this.currentSearchParams = searchParams;
            
            // Update URL if requested
            if (updateURL && !this._isTransitioning) {
                this.updateURL(searchParams);
            }
            
            // Show loading state
            $('#bridge-loading').show();
            $('#bridge-search-results').css('opacity', '0.5');
            
            // Make AJAX request
            $.ajax({
                url: bridgeMLS.ajax_url,
                type: 'POST',
                data: {
                    action: 'bridge_search_properties',
                    nonce: bridgeMLS.nonce,
                    ...searchParams
                },
                success: (response) => {
                    if (response.success) {
                        this.displaySearchResults(response.data);
                    } else {
                        this.displayError(response.data || 'Search failed. Please try again.');
                    }
                },
                error: () => {
                    this.displayError('Connection error. Please check your internet connection.');
                },
                complete: () => {
                    $('#bridge-loading').hide();
                    $('#bridge-search-results').css('opacity', '1');
                    
                    // Reset last search after a delay to allow new searches
                    setTimeout(() => {
                        this._lastSearch = null;
                    }, 500);
                }
            });
        }
        
        /**
         * Debounced search for input fields
         */
        debouncedSearch() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                this.performSearch();
            }, 500);
        }
        
        /**
         * Display search results
         */
        displaySearchResults(data) {
            const resultsContainer = $('#bridge-search-results .property-grid');
            
            if (data.html) {
                resultsContainer.html(data.html);
                
                // Animate new results
                resultsContainer.find('.property-card').each(function(index) {
                    $(this).css('animation-delay', (index * 50) + 'ms');
                });
            } else if (data.count === 0) {
                resultsContainer.html('<p class="no-properties">No properties found matching your criteria. Try adjusting your filters.</p>');
            }
            
            // Update result count if available
            if (data.count !== undefined) {
                console.log(`Bridge MLS: Found ${data.count} properties`);
            }
        }
        
        /**
         * Display error message
         */
        displayError(message) {
            const resultsContainer = $('#bridge-search-results .property-grid');
            resultsContainer.html(`<div class="error">${message}</div>`);
        }
        
        /**
         * Clear all filters
         */
        clearFilters() {
            $('#bridge-property-search-form')[0].reset();
            $('#bridge-city').val(null).trigger('change');
            this.currentSearchParams = {};
            this.performSearch();
        }
        
        /**
         * Update URL with search parameters
         */
        updateURL(searchParams) {
            // Prevent updating URL if we're in the middle of a page transition
            if (window.bridgeMLSApp._isTransitioning) {
                return;
            }
            
            const url = new URL(window.location);
            
            // Clear existing params
            url.searchParams.delete('city');
            url.searchParams.delete('min_price');
            url.searchParams.delete('max_price');
            url.searchParams.delete('bedrooms');
            url.searchParams.delete('bathrooms');
            url.searchParams.delete('property_type');
            url.searchParams.delete('keywords');
            
            // Add new params
            Object.keys(searchParams).forEach(key => {
                if (Array.isArray(searchParams[key])) {
                    url.searchParams.set(key, searchParams[key].join(','));
                } else {
                    url.searchParams.set(key, searchParams[key]);
                }
            });
            
            // Update browser history without triggering popstate
            window.history.replaceState({searchParams: searchParams}, '', url);
        }
        
        /**
         * Share property functionality
         */
        shareProperty() {
            const url = window.location.href;
            const title = document.title;
            
            if (navigator.share) {
                // Use native share API if available
                navigator.share({
                    title: title,
                    url: url
                }).catch(err => console.log('Error sharing:', err));
            } else {
                // Fallback to copying URL
                this.copyToClipboard(url);
                alert('Property link copied to clipboard!');
            }
        }
        
        /**
         * Copy text to clipboard
         */
        copyToClipboard(text) {
            const temp = $('<input>');
            $('body').append(temp);
            temp.val(text).select();
            document.execCommand('copy');
            temp.remove();
        }
        
        /**
         * Handle contact form submission
         */
        handleContactForm(e) {
            e.preventDefault();
            
            const form = $(e.target);
            const data = form.serialize();
            const propertyAddress = form.data('property');
            
            // Simple form validation
            const name = form.find('[name="name"]').val();
            const email = form.find('[name="email"]').val();
            
            if (!name || !email) {
                alert('Please fill in all required fields.');
                return;
            }
            
            // Here you would normally submit to a backend endpoint
            // For now, we'll just show a success message
            alert(`Thank you for your interest in ${propertyAddress}. An agent will contact you soon!`);
            form[0].reset();
        }
        
        /**
         * Test API connection (debug mode)
         */
        testAPIConnection() {
            const statusDiv = $('#bridge-api-status');
            statusDiv.html('<em>Testing API connection...</em>');
            
            $.ajax({
                url: bridgeMLS.ajax_url,
                type: 'POST',
                data: {
                    action: 'bridge_test_api',
                    nonce: bridgeMLS.nonce
                },
                success: (response) => {
                    if (response.success) {
                        let html = `<div style="color: green; margin-top: 10px;">
                            <strong>✓ ${response.data.message}</strong>`;
                        
                        if (response.data.tests) {
                            html += '<ul style="margin-top: 10px;">';
                            for (let key in response.data.tests) {
                                const test = response.data.tests[key];
                                const icon = test.success ? '✅' : '❌';
                                html += `<li>${icon} ${test.name}: ${test.message}</li>`;
                            }
                            html += '</ul>';
                        }
                        
                        html += '</div>';
                        statusDiv.html(html);
                    } else {
                        statusDiv.html(`<div style="color: red; margin-top: 10px;">
                            <strong>✗ API Test Failed:</strong> ${response.data}
                        </div>`);
                    }
                },
                error: () => {
                    statusDiv.html(`<div style="color: red; margin-top: 10px;">
                        <strong>✗ Connection Error:</strong> Could not reach the server.
                    </div>`);
                }
            });
        }
    }
    
    /**
     * Initialize app when DOM is ready
     */
    $(document).ready(function() {
        window.bridgeMLSApp = new BridgeMLSApp();
    });
    
    /**
     * Global utility functions
     */
    window.BridgeMLSUtils = {
        search: function(params) {
            if (window.bridgeMLSApp) {
                return window.bridgeMLSApp.performSearch(params);
            }
        },
        showProperty: function(listingKey) {
            // This would typically load property details via AJAX
            console.log('Loading property:', listingKey);
        },
        shareProperty: function() {
            if (window.bridgeMLSApp) {
                return window.bridgeMLSApp.shareProperty();
            }
        }
    };
    
    /**
     * Inline lightbox fallback for critical functionality
     */
    window.bridgeLightboxFallback = function() {
        console.log('Bridge MLS: Using inline lightbox fallback');
        
        // Basic lightbox implementation as fallback
        $('.property-image img, .thumbnail img, #main-property-image').on('click', function(e) {
            e.preventDefault();
            const src = $(this).attr('src');
            const lightbox = $('<div class="bridge-lightbox-fallback">').css({
                position: 'fixed',
                top: 0,
                left: 0,
                width: '100%',
                height: '100%',
                backgroundColor: 'rgba(0,0,0,0.9)',
                zIndex: 10000,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                cursor: 'pointer'
            });
            
            const img = $('<img>').attr('src', src).css({
                maxWidth: '90%',
                maxHeight: '90%',
                borderRadius: '8px'
            });
            
            lightbox.append(img).appendTo('body');
            
            lightbox.on('click', function() {
                $(this).fadeOut(300, function() {
                    $(this).remove();
                });
            });
            
            // ESC key to close
            $(document).on('keydown.lightbox', function(e) {
                if (e.key === 'Escape') {
                    lightbox.fadeOut(300, function() {
                        $(this).remove();
                    });
                    $(document).off('keydown.lightbox');
                }
            });
        });
    };

})(jQuery);