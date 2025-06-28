// Product Page JavaScript

class ProductPage {
    constructor() {
        this.chart = null;
        this.autoScrollIntervals = [];
        this.tooltip = null;
        this.init();
    }

    init() {
        // Wait for DOM to be fully loaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                this.initializeComponents();
            });
        } else {
            this.initializeComponents();
        }
    }

    initializeComponents() {
        this.initChart();
        this.initFavorites();
        this.initAutoScroll();  // Enable auto scroll
        this.initInteractionTracking();
        this.initResponsiveChart();
        this.initSuggestionColors();
        this.initPriceTooltip();
    }

    // Initialize Price Info Tooltip - FIXED
    initPriceTooltip() {
        const priceInfoIcon = document.querySelector('.price-info-icon');
        const tooltip = document.getElementById('price-tooltip');
        
        if (!priceInfoIcon || !tooltip) {
            console.log('Price info icon or tooltip not found');
            return;
        }

        this.tooltip = tooltip;

        // Mouse enter event
        priceInfoIcon.addEventListener('mouseenter', (e) => {
            this.showTooltip(e.target);
        });

        // Mouse leave event
        priceInfoIcon.addEventListener('mouseleave', () => {
            this.hideTooltip();
        });

        // Mobile touch support
        priceInfoIcon.addEventListener('click', (e) => {
            e.preventDefault();
            if (this.tooltip.classList.contains('show')) {
                this.hideTooltip();
            } else {
                this.showTooltip(e.target);
                
                // Auto-hide on mobile after 5 seconds
                setTimeout(() => {
                    this.hideTooltip();
                }, 5000);
            }
        });

        // Hide tooltip when clicking outside
        document.addEventListener('click', (e) => {
            if (!priceInfoIcon.contains(e.target) && !this.tooltip.contains(e.target)) {
                this.hideTooltip();
            }
        });
    }

    showTooltip(iconElement) {
        if (!iconElement || !this.tooltip) return;

        const tooltipContent = this.tooltip.querySelector('.price-tooltip-content');
        if (!tooltipContent) return;

        // Set tooltip content
        tooltipContent.textContent = iconElement.dataset.tooltip;

        // Calculate position
        const iconRect = iconElement.getBoundingClientRect();
        const tooltipRect = this.tooltip.getBoundingClientRect();
        
        // Position tooltip above the icon, centered
        const left = iconRect.left + (iconRect.width / 2) - (300 / 2); // 300 is max-width of tooltip
        const top = iconRect.top - tooltipRect.height - 10;

        // Ensure tooltip stays within viewport
        const finalLeft = Math.max(20, Math.min(left, window.innerWidth - 320));
        
        this.tooltip.style.left = finalLeft + 'px';
        this.tooltip.style.top = Math.max(10, top) + 'px';

        // Show tooltip
        this.tooltip.classList.add('show');
    }

    hideTooltip() {
        if (this.tooltip) {
            this.tooltip.classList.remove('show');
        }
    }

    // Initialize Price Chart
    initChart() {
        if (!window.chartData) {
            console.warn('Chart data not available');
            return;
        }

        const ctx = document.getElementById('priceChart');
        if (!ctx) {
            console.warn('Chart canvas not found');
            return;
        }

        // Show loading state
        const chartWrapper = ctx.closest('.chart-wrapper');
        if (chartWrapper) {
            chartWrapper.classList.add('chart-loading');
        }

        try {
            this.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: window.chartData.labels,
                    datasets: [
                        {
                            label: 'Highest Price',
                            data: window.chartData.highest,
                            borderColor: '#ff0000',
                            backgroundColor: 'transparent',
                            fill: false,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                            borderWidth: 3,
                            tension: 0.1
                        },
                        {
                            label: 'Lowest Price',
                            data: window.chartData.lowest,
                            borderColor: '#00cc00',
                            backgroundColor: 'transparent',
                            fill: false,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                            borderWidth: 3,
                            tension: 0.1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            title: { 
                                display: true, 
                                text: 'Price (₹)',
                                font: { size: 14, weight: 'bold' }
                            },
                            ticks: { 
                                callback: function(value) {
                                    if (value === 0) return 'Out of Stock';
                                    return '₹' + value.toLocaleString('en-IN');
                                },
                                font: { size: 12 }
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            }
                        },
                        x: { 
                            title: { 
                                display: true, 
                                text: 'Time Period (Last 23 Months + Current Month Daily)',
                                font: { size: 14, weight: 'bold' }
                            },
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45,
                                font: { size: 10 }
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            borderColor: 'rgba(255,255,255,0.3)',
                            borderWidth: 1,
                            callbacks: {
                                title: function(context) {
                                    return 'Date: ' + context[0].label;
                                },
                                label: function(context) {
                                    if (context.parsed.y === 0) {
                                        return context.dataset.label + ': Out of Stock';
                                    }
                                    return context.dataset.label + ': ₹' + context.parsed.y.toLocaleString('en-IN');
                                }
                            }
                        },
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: { size: 12, weight: 'bold' },
                                usePointStyle: false,
                                padding: 20
                            }
                        }
                    },
                    elements: {
                        line: {
                            tension: 0.1
                        },
                        point: {
                            radius: 0
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    }
                }
            });

            // Remove loading state
            if (chartWrapper) {
                chartWrapper.classList.remove('chart-loading');
            }

            console.log('Chart initialized successfully');
        } catch (error) {
            console.error('Error initializing chart:', error);
            if (chartWrapper) {
                chartWrapper.classList.remove('chart-loading');
                chartWrapper.innerHTML = '<div class="chart-error">Error loading chart. Please refresh the page.</div>';
            }
        }
    }

    // Initialize Favorites functionality
    initFavorites() {
        const favoriteBtn = document.querySelector('.favorite-btn');
        if (!favoriteBtn) return;

        favoriteBtn.addEventListener('click', (e) => {
            this.handleFavoriteClick(e);
        });

        favoriteBtn.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.handleFavoriteClick(e);
            }
        });
    }

    async handleFavoriteClick(e) {
        const btn = e.target;
        
        // Check if user is logged in
        if (btn.classList.contains('guest')) {
            this.showLoginPrompt();
            return;
        }

        const productId = btn.dataset.productId;
        const isFavorite = btn.dataset.isFavorite === 'true';
        
        if (!productId) {
            console.error('Product ID not found');
            return;
        }

        try {
            // Show loading state
            btn.style.opacity = '0.5';
            btn.style.pointerEvents = 'none';

            const response = await this.toggleFavorite(productId, isFavorite);
            
            if (response.status === 'success') {
                // Update button state
                const newIsFavorite = !isFavorite;
                btn.dataset.isFavorite = newIsFavorite.toString();
                btn.style.color = newIsFavorite ? '#ff0000' : '#ccc';
                btn.setAttribute('aria-label', newIsFavorite ? 'Remove from favorites' : 'Add to favorites');
                
                // Show success message
                this.showPopup('favorite-popup', `
                    <h3>Success</h3>
                    <p>Product ${newIsFavorite ? 'added to' : 'removed from'} favorites!</p>
                `);
            } else {
                this.showPopup('favorite-popup', `
                    <h3>Error</h3>
                    <p>${response.message || 'An error occurred. Please try again.'}</p>
                `);
            }
        } catch (error) {
            console.error('Error toggling favorite:', error);
            this.showPopup('favorite-popup', `
                <h3>Error</h3>
                <p>An error occurred. Please try again.</p>
            `);
        } finally {
            // Remove loading state
            btn.style.opacity = '1';
            btn.style.pointerEvents = 'auto';
        }
    }

    async toggleFavorite(productId, isFavorite) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        
        const response = await fetch('/user/toggle_favorite.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            body: JSON.stringify({ 
                product_id: productId, 
                is_favorite: !isFavorite 
            })
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        return await response.json();
    }

    // Initialize Auto Scroll for recommendations
    initAutoScroll() {
        const carousels = document.querySelectorAll('.product-carousel');
        
        carousels.forEach((carousel, index) => {
            this.setupAutoScroll(carousel, index);
        });
    }

    setupAutoScroll(carousel, index) {
        if (!carousel) return;

        const cards = carousel.querySelectorAll('.carousel-product-card');
        if (cards.length <= 3) return; // Don't auto-scroll if few items

        let isScrolling = false;
        let currentPosition = 0;
        const cardWidth = 300; // Card width + gap
        const maxScroll = (cards.length - 3) * cardWidth;

        // FIXED: Better search template visibility check
        const autoScroll = () => {
            if (isScrolling) return;

            // Check if search template is visible
            const searchTemplate = document.querySelector('.search-card, .search-template, .search-section, .hero-section');
            if (!searchTemplate) {
                // If no search template found, allow auto scroll
                this.performHorizontalScroll(carousel, currentPosition, cardWidth, maxScroll);
                currentPosition += cardWidth;
                if (currentPosition >= maxScroll) {
                    currentPosition = 0;
                }
                return;
            }

            const searchRect = searchTemplate.getBoundingClientRect();
            // FIXED: Only start auto-scroll when search is completely out of view
            // This means search bottom should be <= 0 (completely scrolled past)
            const isSearchCompletelyHidden = searchRect.bottom <= 0;

            // Only auto-scroll horizontally when search template is completely hidden
            if (isSearchCompletelyHidden) {
                this.performHorizontalScroll(carousel, currentPosition, cardWidth, maxScroll);
                currentPosition += cardWidth;
                if (currentPosition >= maxScroll) {
                    currentPosition = 0;
                }
            }
        };

        // Start auto-scroll
        const intervalId = setInterval(autoScroll, 4000);
        this.autoScrollIntervals.push(intervalId);

        // Pause on hover
        carousel.addEventListener('mouseenter', () => {
            clearInterval(intervalId);
        });

        carousel.addEventListener('mouseleave', () => {
            const newIntervalId = setInterval(autoScroll, 4000);
            this.autoScrollIntervals.push(newIntervalId);
        });

        // Handle manual scroll
        carousel.addEventListener('scroll', () => {
            isScrolling = true;
            setTimeout(() => {
                isScrolling = false;
                currentPosition = carousel.scrollLeft;
            }, 150);
        });

        // Touch support for mobile
        let startX = 0;
        let isDown = false;

        carousel.addEventListener('touchstart', (e) => {
            isDown = true;
            startX = e.touches[0].pageX - carousel.offsetLeft;
        });

        carousel.addEventListener('touchmove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.touches[0].pageX - carousel.offsetLeft;
            const walk = (x - startX) * 2;
            carousel.scrollLeft = carousel.scrollLeft - walk;
            startX = x;
        });

        carousel.addEventListener('touchend', () => {
            isDown = false;
        });
    }

    // Helper function for horizontal scrolling
    performHorizontalScroll(carousel, currentPosition, cardWidth, maxScroll) {
        carousel.scrollTo({
            left: currentPosition,
            behavior: 'smooth'
        });
    }

    // Initialize Interaction Tracking
    initInteractionTracking() {
        // Track buy button clicks
        document.querySelectorAll('a[href*="affiliate_link"], .btn-primary').forEach(btn => {
            btn.addEventListener('click', () => {
                this.trackInteraction('purchase_intent', window.chartData?.productId);
            });
        });

        // Track price history views
        document.querySelectorAll('.btn-secondary, a[href*="price-history"]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.trackInteraction('price_history_view', window.chartData?.productId);
            });
        });

        // Track chart interactions
        const chartCanvas = document.getElementById('priceChart');
        if (chartCanvas) {
            chartCanvas.addEventListener('click', () => {
                this.trackInteraction('chart_interaction', window.chartData?.productId);
            });
        }
    }

    async trackInteraction(type, productId) {
        if (!productId) return;

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            
            await fetch('/user/track_interaction.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken || ''
                },
                body: JSON.stringify({ 
                    type: type, 
                    product_id: productId 
                })
            });
        } catch (error) {
            console.error('Error tracking interaction:', error);
        }
    }

    // Initialize Responsive Chart
    initResponsiveChart() {
        let resizeTimeout;
        
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (this.chart) {
                    this.chart.resize();
                }
            }, 300);
        });
    }

    // Initialize Buy Suggestion Colors
    initSuggestionColors() {
        const suggestionText = document.querySelector('.suggestion-text');
        if (!suggestionText) return;

        const colorData = suggestionText.dataset.color;
        if (!colorData) return;

        if (colorData.includes('linear-gradient')) {
            suggestionText.style.background = colorData;
            suggestionText.style.webkitBackgroundClip = 'text';
            suggestionText.style.webkitTextFillColor = 'transparent';
            suggestionText.style.backgroundClip = 'text';
        } else {
            suggestionText.style.color = colorData;
        }
    }

    // Show Login Prompt
    showLoginPrompt() {
        this.showPopup('login-popup', `
            <h3>Login Required</h3>
            <p>Please login to add products to your favorites.</p>
            <div style="margin-top: 20px;">
                <a href="/user/login.php" class="btn btn-primary">Login</a>
                <a href="/user/register.php" class="btn btn-secondary" style="margin-left: 10px;">Register</a>
            </div>
        `);
    }

    // Show Popup
    showPopup(popupId, content) {
        const popup = document.getElementById(popupId);
        const overlay = document.querySelector('.popup-overlay');
        const popupContent = popup?.querySelector('.popup-content');

        if (!popup || !overlay || !popupContent) return;

        popupContent.innerHTML = content;
        popup.style.display = 'block';
        overlay.style.display = 'block';

        // Auto-hide after 3 seconds for success messages
        if (content.includes('Success')) {
            setTimeout(() => {
                this.hidePopup(popupId);
            }, 3000);
        }
    }

    // Hide Popup
    hidePopup(popupId) {
        const popup = document.getElementById(popupId);
        const overlay = document.querySelector('.popup-overlay');

        if (popup) popup.style.display = 'none';
        if (overlay) overlay.style.display = 'none';
    }

    // Cleanup method
    destroy() {
        // Clear auto-scroll intervals
        this.autoScrollIntervals.forEach(intervalId => {
            clearInterval(intervalId);
        });
        this.autoScrollIntervals = [];

        // Destroy chart
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }

        // Clear tooltip reference
        this.tooltip = null;
    }
}

// Global functions for backward compatibility
window.toggleFavorite = async function(productId, isFavorite) {
    if (window.productPageInstance) {
        const btn = document.querySelector('.favorite-btn');
        if (btn) {
            btn.dataset.productId = productId;
            btn.dataset.isFavorite = isFavorite.toString();
            await window.productPageInstance.handleFavoriteClick({ target: btn });
        }
    }
};

window.trackInteraction = function(type, productId) {
    if (window.productPageInstance) {
        window.productPageInstance.trackInteraction(type, productId);
    }
};

window.hidePopup = function(popupId) {
    if (window.productPageInstance) {
        window.productPageInstance.hidePopup(popupId);
    }
};

window.showPopup = function(popupId, content) {
    if (window.productPageInstance) {
        window.productPageInstance.showPopup(popupId, content);
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    window.productPageInstance = new ProductPage();
});

// Handle page unload
window.addEventListener('beforeunload', function() {
    if (window.productPageInstance) {
        window.productPageInstance.destroy();
    }
});

// Performance monitoring
if ('performance' in window) {
    window.addEventListener('load', function() {
        setTimeout(function() {
            const perfData = window.performance.timing;
            const loadTime = perfData.loadEventEnd - perfData.navigationStart;
            console.log(`Page load time: ${loadTime}ms`);
            
            // Track slow loads
            if (loadTime > 3000) {
                console.warn('Slow page load detected');
                // Could send analytics here
            }
        }, 0);
    });
}

// Error handling for uncaught errors
window.addEventListener('error', function(e) {
    console.error('Uncaught error:', e.error);
    
    // Show user-friendly error message for critical errors
    if (e.error && e.error.message.includes('Chart')) {
        const chartWrapper = document.querySelector('.chart-wrapper');
        if (chartWrapper) {
            chartWrapper.innerHTML = `
                <div class="chart-error" style="text-align: center; padding: 40px; color: #666;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>
                    <p>Unable to load price chart. Please refresh the page.</p>
                    <button onclick="location.reload()" class="btn btn-primary" style="margin-top: 10px;">Refresh Page</button>
                </div>
            `;
        }
    }
});

// Handle network connectivity
window.addEventListener('online', function() {
    console.log('Connection restored');
    // Could reload failed requests here
});

window.addEventListener('offline', function() {
    console.log('Connection lost');
    // Could show offline message here
});