/*
 * Search functionality for AmezPrice - Fixed Version with Short URL Support
 */
document.addEventListener('DOMContentLoaded', () => {
    const searchBtn = document.getElementById('search-btn');
    const productUrlInput = document.getElementById('product-url');
    const errorMessage = document.getElementById('error-message');
    const searchResult = document.getElementById('search-result');
    const searchForm = document.getElementById('search-form');

    // Fallback to old selectors if new IDs don't exist
    const searchButton = searchBtn || document.querySelector('.search-button');
    const searchInput = productUrlInput || document.querySelector('.search-input');
    const form = searchForm || document.querySelector('.search-form');

    // Constants
    const DEBOUNCE_DELAY = 300;
    let searchTimeout;

    // ✅ FIXED: Enhanced URL validation with proper short URL support
    function validateUrl(url) {
        const supportedDomains = [
            'amazon.in', 'www.amazon.in', 'amzn.in', 'amzn.to', 'amzn.com',
            'flipkart.com', 'www.flipkart.com'
        ];
        
        try {
            const parsedUrl = new URL(url);
            const hostname = parsedUrl.hostname.toLowerCase();
            
            if (!supportedDomains.includes(hostname)) {
                return false;
            }
            
            // Enhanced validation for Amazon URLs (including short URLs)
            if (hostname.includes('amazon') || hostname.includes('amzn')) {
                // For short URLs, just check if domain is supported - let backend handle resolution
                if (hostname.includes('amzn')) {
                    return true; // Let PHP backend handle short URL validation
                }
                
                // For regular Amazon URLs
                return /\/dp\/[A-Z0-9]{10}/i.test(url) || 
                    /\/gp\/product\/[A-Z0-9]{10}/i.test(url) ||
                    /\/product\/[A-Z0-9]{10}/i.test(url) ||
                    /\/dp\/[^\/]+\/[A-Z0-9]{10}/i.test(url);
            }
            
            // Flipkart validation
            if (hostname.includes('flipkart')) {
                return /\/p\/[a-zA-Z0-9-]+/.test(url);
            }
            
            return true;
        } catch (e) {
            return false;
        }
    }

    // Sanitize input to prevent XSS
    function sanitizeInput(input) {
        if (!input) return '';
        const div = document.createElement('div');
        div.textContent = input;
        return div.innerHTML;
    }

    // Show popup (fallback method)
    function showPopup(id, content) {
        const popup = document.getElementById(id);
        const overlay = document.querySelector('.popup-overlay');
        
        if (!popup) {
            console.error(`Popup not found: ${id}`);
            // Fallback: show in search result area
            if (searchResult) {
                searchResult.innerHTML = content;
                searchResult.style.display = 'block';
            }
            return;
        }
        
        const popupContent = popup.querySelector('.popup-content');
        if (popupContent) {
            popupContent.innerHTML = content;
        }
        
        popup.style.display = 'block';
        if (overlay) {
            overlay.style.display = 'block';
        }
        document.body.style.overflow = 'hidden';
    }

    // Hide popup
    function hidePopup(id) {
        const popup = document.getElementById(id);
        const overlay = document.querySelector('.popup-overlay');
        
        if (popup) {
            popup.style.display = 'none';
        }
        if (overlay) {
            overlay.style.display = 'none';
        }
        document.body.style.overflow = 'auto';
    }

    // Make hidePopup globally available
    window.hidePopup = hidePopup;

    // Handle search request
    async function handleSearch() {
        const url = (searchInput || productUrlInput).value.trim();
        
        // Clear previous results
        if (errorMessage) {
            errorMessage.textContent = '';
            errorMessage.style.display = 'none';
        }
        if (searchResult) {
            searchResult.innerHTML = '';
            searchResult.style.display = 'none';
        }

        // Validation
        if (!url) {
            showError('Please enter a product URL');
            return;
        }

        if (!validateUrl(url)) {
            showError('Please enter a valid Amazon India or Flipkart URL.');
            return;
        }

        // Show loading state
        const button = searchButton || searchBtn;
        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
        }

        try {
            // Get CSRF token if available
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            
            const headers = {
                'Content-Type': 'application/json'
            };
            
            if (csrfToken) {
                headers['X-CSRF-Token'] = csrfToken;
            }

            const response = await fetch('/search/search.php', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({ url: url })
            });

            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }

            const data = await response.json();

            if (data.status === 'success' && data.product) {
                // MODIFIED: Redirect to product page instead of showing popup
                redirectToProductPage(data.product);
            } else {
                showError(data.message || 'Failed to fetch product data');
            }
        } catch (error) {
            console.error('Search error:', error);
            showError('An error occurred. Please try again later.');
        } finally {
            // Reset loading state
            if (button) {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-magnifying-glass"></i>';
            }
        }
    }

    // Show error message
    function showError(message) {
        if (errorMessage) {
            errorMessage.innerHTML = message.replace(/\n/g, '<br>'); // Support line breaks
            errorMessage.style.display = 'block';
        } else {
            // Fallback to popup
            showPopup('search-error-popup', `
                <h3>Error</h3>
                <p>${sanitizeInput(message).replace(/\n/g, '<br>')}</p>
            `);
        }
    }

    // NEW: Redirect to product page function
    function redirectToProductPage(product) {
        // Construct product page URL
        const productPageUrl = `/product/${product.merchant}/pid=${product.asin}`;
        
        // Show a brief loading message
        if (searchResult) {
            searchResult.innerHTML = `
                <div class="product-card">
                    <div style="text-align: center; padding: 20px;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #2a3aff;"></i>
                        <p style="margin-top: 10px;">Redirecting to product page...</p>
                    </div>
                </div>
            `;
            searchResult.style.display = 'block';
        }
        
        // Redirect after a short delay for better UX
        setTimeout(() => {
            window.location.href = productPageUrl;
        }, 1000);
    }

    // OLD: Show search result function (now unused but kept for compatibility)
    function showSearchResult(product) {
        // This function is no longer used but kept for backward compatibility
        redirectToProductPage(product);
    }

    // Debounced search function
    function debouncedSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(handleSearch, DEBOUNCE_DELAY);
    }

    // Event listeners
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            handleSearch();
        });
    }

    if (searchButton || searchBtn) {
        (searchButton || searchBtn).addEventListener('click', (e) => {
            e.preventDefault();
            handleSearch();
        });
    }

    if (searchInput || productUrlInput) {
        (searchInput || productUrlInput).addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleSearch();
            }
        });
    }

    // Close popups on overlay click
    const overlay = document.querySelector('.popup-overlay');
    if (overlay) {
        overlay.addEventListener('click', () => {
            hidePopup('search-preview-popup');
            hidePopup('search-error-popup');
        });
    }

    // Close popups on escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            hidePopup('search-preview-popup');
            hidePopup('search-error-popup');
        }
    });
});