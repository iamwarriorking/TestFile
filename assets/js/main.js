// Configuration
const config = {
    carousel: {
        scrollInterval: 3000,
        scrollAmount: window.innerWidth > 768 ? 300 : 200,
        debounceTime: 100,
    },
    popup: {
        animationDuration: 300,
        permissionDelay: 3000,
        errorTimeout: 5000,
    },
    deals: {
        skeletonLoadDelay: 500,
    }
};

// Utility: Debounce function
function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// CSRF Token Utilities
function getCsrfToken() {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        console.error('CSRF token not found');
        throw new Error('Security token missing');
    }
    return token;
}

async function fetchWithCsrf(url, options = {}) {
    const token = getCsrfToken();
    console.log('Sending CSRF Token:', token);
    
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': token,
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers
    };
    
    try {
        const response = await fetch(url, { 
            ...options, 
            headers,
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            const text = await response.text();
            
            // Check specifically for CSRF token errors (status 403)
            if (response.status === 403 && text.includes('CSRF token not found')) {
                console.log('CSRF token expired, handling gracefully');
                
                // Create or use an existing popup for a better user experience
                const overlay = document.querySelector('.popup-overlay');
                const errorDiv = document.createElement('div');
                errorDiv.className = 'popup';
                errorDiv.id = 'csrf-error-popup';
                errorDiv.style.display = 'block';
                
                errorDiv.innerHTML = `
                    <div class="popup-content">
                        <h3>Session Expired</h3>
                        <p>Your session has expired due to inactivity.</p>
                        <p>The page will refresh automatically in <span id="countdown">5</span> seconds...</p>
                        <button class="btn btn-primary" onclick="window.location.reload()">Refresh Now</button>
                    </div>
                `;
                
                document.body.appendChild(errorDiv);
                if (overlay) {
                    overlay.style.display = 'block';
                }
                
                // Start countdown for auto-refresh
                let countdown = 5;
                const timer = setInterval(() => {
                    countdown--;
                    const countdownEl = document.getElementById('countdown');
                    if (countdownEl) {
                        countdownEl.textContent = countdown;
                    }
                    
                    if (countdown <= 0) {
                        clearInterval(timer);
                        window.location.reload();
                    }
                }, 1000);
                
                return { status: 'session_expired', message: 'Session expired, page will refresh' };
            }
            
            throw new Error(`HTTP error! status: ${response.status}, response: ${text}`);
        }
        
        const text = await response.text();
        if (!text.trim()) {
            throw new Error('Empty response from server');
        }
        
        try {
            const jsonResponse = JSON.parse(text);
            
            // Only auto-redirect for non-auth endpoints
            if (jsonResponse.status === 'success' && 
                jsonResponse.is_authenticated && 
                !url.includes('/auth/')) {
                // Force immediate redirect for authenticated responses (non-auth pages)
                if (jsonResponse.redirect) {
                    window.location.replace(jsonResponse.redirect);
                    return jsonResponse; // Return to prevent further processing
                }
            }
            
            return jsonResponse;
        } catch (parseError) {
            console.error('JSON parse error:', parseError);
            throw new Error('Server returned invalid JSON');
        }
    } catch (error) {
        console.error('Fetch error:', error);
        throw error;
    }
}

// Carousel Module
const Carousel = {
    carouselInstances: new Map(), // Track carousel instances and their intervals

    init() {
        document.querySelectorAll('.product-box').forEach(box => {
            const carousel = box.querySelector('.product-carousel');
            if (!carousel) return;

            // Optimize image loading
            carousel.querySelectorAll('img').forEach(img => {
                img.setAttribute('loading', 'lazy');
                img.setAttribute('decoding', 'async');
            });

            // Event delegation for arrows
            box.addEventListener('click', (e) => {
                const arrow = e.target.closest('.carousel-arrow');
                if (!arrow) return;
                
                const direction = arrow.classList.contains('left') ? -1 : 1;
                this.scroll(carousel, direction);
                // this.pauseAutoScroll(carousel);
            });

            carousel.setAttribute('tabindex', '0');
            carousel.setAttribute('aria-label', 'Product carousel');
            
            carousel.addEventListener('keydown', e => {
                if (e.key === 'ArrowLeft') {
                    this.scroll(carousel, -1);
                    // this.pauseAutoScroll(carousel);
                } else if (e.key === 'ArrowRight') {
                    this.scroll(carousel, 1);
                    // this.pauseAutoScroll(carousel);
                }
            });

            let touchStartX = 0;
            carousel.addEventListener('touchstart', e => {
                touchStartX = e.touches[0].clientX;
                // this.stopAutoScroll(carousel);
            }, { passive: true });

            const debouncedTouchMove = debounce(e => {
                const touchEndX = e.touches[0].clientX;
                const diff = touchStartX - touchEndX;
                if (Math.abs(diff) > 50) {
                    this.scroll(carousel, diff > 0 ? 1 : -1);
                    touchStartX = touchEndX;
                }
            }, config.carousel.debounceTime);

            carousel.addEventListener('touchmove', debouncedTouchMove, { passive: true });
        });
    },

};

// Popup Module
const Popup = {
    init() {
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.popup').forEach(popup => {
                    if (popup.style.display === 'block') {
                        this.hide(popup.id);
                    }
                });
            }
        });

        // Add popup close button event listeners
        document.querySelectorAll('.popup-close').forEach(closeBtn => {
            closeBtn.addEventListener('click', () => {
                const popup = closeBtn.closest('.popup');
                if (popup) {
                    this.hide(popup.id);
                }
            });
        });

        // Close popup when clicking on overlay
        document.querySelector('.popup-overlay')?.addEventListener('click', () => {
            document.querySelectorAll('.popup').forEach(popup => {
                if (popup.style.display === 'block') {
                    this.hide(popup.id);
                }
            });
        });
    },

    show(id, content, autoDismiss = false) {
        const popup = document.getElementById(id);
        if (!popup) {
            console.error(`Popup with ID ${id} not found at:`, new Date().toISOString());
            return;
        }
        const overlay = document.querySelector('.popup-overlay');
        const previousActiveElement = document.activeElement;
        popup.querySelector('.popup-content').innerHTML = content;
        popup.style.display = 'block';
        overlay.style.display = 'block';
        popup.style.opacity = '0';
        overlay.style.opacity = '0';
        document.body.style.overflow = 'hidden';

        setTimeout(() => {
            popup.style.transition = `opacity ${config.popup.animationDuration}ms`;
            overlay.style.transition = `opacity ${config.popup.animationDuration}ms`;
            popup.style.opacity = '1';
            overlay.style.opacity = '1';
        }, 10);

        const focusableElements = popup.querySelectorAll('button, [href], input, select, textarea');
        if (focusableElements.length) {
            focusableElements[0].focus();
            popup.addEventListener('keydown', e => {
                if (e.key === 'Tab') {
                    if (e.shiftKey && document.activeElement === focusableElements[0]) {
                        e.preventDefault();
                        focusableElements[focusableElements.length - 1].focus();
                    } else if (!e.shiftKey && document.activeElement === focusableElements[focusableElements.length - 1]) {
                        e.preventDefault();
                        focusableElements[0].focus();
                    }
                }
            });
        }

        if (autoDismiss && id === 'error-popup') {
            setTimeout(() => this.hide(id), config.popup.errorTimeout);
        }

        popup.dataset.previousActive = previousActiveElement ? previousActiveElement.id : '';
    },

       hide(id) {
        const popup = document.getElementById(id);
        if (!popup) {
            console.error(`Popup with ID ${id} not found at:`, new Date().toISOString());
            return;
                    }
                    const overlay = document.querySelector('.popup-overlay');
                
            // Clear any existing transition
            popup.style.transition = '';
            overlay.style.transition = '';
            
            // Hide immediately
            popup.style.display = 'none';
            overlay.style.display = 'none';
            document.body.style.overflow = 'auto';

            // Clear opacity
            popup.style.opacity = '0';
            overlay.style.opacity = '0';

            // Reset any form inside popup
            const form = popup.querySelector('form');
            if (form) {
                form.reset();
            }

            // Restore focus to previous element
            const previousActiveId = popup.dataset.previousActive;
            if (previousActiveId) {
                const previousElement = document.getElementById(previousActiveId);
                if (previousElement) previousElement.focus();
            }
        }
};

// Today's Deals page

const Deals = {
    init() {
        const grid = document.getElementById('product-grid');
        if (!grid) return;

        // Only show skeleton if no products are present
        if (grid.children.length === 0 || !grid.querySelector('.product-card:not(.skeleton)')) {
            this.showSkeletonLoading();
        }

        // Initialize filters and pagination
        this.initFilters();
        this.initPagination();
    },

    showSkeletonLoading() {
        const grid = document.getElementById('product-grid');
        if (!grid) return;

        grid.style.opacity = 0;
        grid.innerHTML = `
            ${Array(8).fill().map(() => `
                <div class="product-card skeleton">
                    <div class="skeleton-image"></div>
                    <div class="skeleton-title"></div>
                    <div class="skeleton-price"></div>
                    <div class="skeleton-tracking"></div>
                </div>
            `).join('')}
        `;
        
        setTimeout(() => {
            grid.style.transition = 'opacity 0.3s';
            grid.style.opacity = 1;
        }, 500);
    },

    initFilters() {
        // Filter form handling
        const dealFiltersForm = document.getElementById('deal-filters');
        if (dealFiltersForm) {
            dealFiltersForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.applyFilters();
            });
        }

        // Category select handling
        const categorySelect = document.getElementById('category');
        if (categorySelect) {
            categorySelect.addEventListener('change', () => {
                this.loadSubcategories();
            });
        }

        // Subcategory select handling
        const subcategorySelect = document.getElementById('subcategory');
        if (subcategorySelect) {
            subcategorySelect.addEventListener('change', () => {
                this.applyFilters();
            });
        }
    },

    loadSubcategories() {
        const category = document.getElementById('category').value;
        const subcategorySelect = document.getElementById('subcategory');
        
        if (!category) {
            subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';
            this.applyFilters();
            return;
        }
        
        // Fetch subcategories via AJAX
        fetch(`/api/get-subcategories.php?category=${encodeURIComponent(category)}`)
            .then(response => response.json())
            .then(data => {
                subcategorySelect.innerHTML = '<option value="">All Subcategories</option>';
                if (data.success && data.subcategories) {
                    data.subcategories.forEach(subcat => {
                        const option = document.createElement('option');
                        option.value = subcat;
                        option.textContent = subcat;
                        subcategorySelect.appendChild(option);
                    });
                }
                this.applyFilters();
            })
            .catch(error => {
                console.error('Error fetching subcategories:', error);
                this.applyFilters();
            });
    },

    applyFilters() {
        const category = document.getElementById('category').value;
        const subcategory = document.getElementById('subcategory').value;
        
        const params = new URLSearchParams();
        if (category) params.set('category', category);
        if (subcategory) params.set('subcategory', subcategory);
        params.set('page', '1');
        
        window.location.href = '?' + params.toString();
    },

    initPagination() {
        const pagination = document.querySelector('.pagination');
        if (!pagination) return;

        pagination.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.applyPagination(link.href);
            });
        });
    },

    async applyPagination(url) {
        const grid = document.getElementById('product-grid');
        const pagination = document.querySelector('.pagination');

        // Show loading state
        grid.style.opacity = '0.5';

        try {
            const response = await fetch(url);
            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newGrid = doc.querySelector('.product-grid');
            const newPagination = doc.querySelector('.pagination');

            if (newGrid && newPagination) {
                grid.innerHTML = newGrid.innerHTML;
                pagination.innerHTML = newPagination.innerHTML;
                grid.style.opacity = '1';
                history.pushState({}, '', url);
                this.initPagination();
            }
        } catch (error) {
            console.error('Error fetching paginated deals:', error);
            grid.style.opacity = '1';
        }
    }
};

// Navbar Module
const Navbar = {
    init() {
        const menuButton = document.querySelector('.navbar-menu');
        if (!menuButton) {
            console.log('Navbar menu button not found');
            return;
        }

        const links = document.querySelector('.navbar-links');
        const social = document.querySelector('.navbar-social');

        if (!links || !social) {
            console.log('Navbar links or social elements not found');
            return;
        }

        menuButton.addEventListener('click', () => {
            links.classList.toggle('active');
            social.classList.toggle('active');
            menuButton.setAttribute('aria-expanded', links.classList.contains('active'));
        });

        if (links) {
            links.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    links.classList.remove('active');
                    social.classList.remove('active');
                    menuButton.setAttribute('aria-expanded', 'false');
                });
            });
        }

        menuButton.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                menuButton.click();
            }
        });
    }
};

// Contact Form Module
const ContactForm = {
    init() {
        const contactForm = document.getElementById('contact-form');
        if (!contactForm) return;

        contactForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(contactForm);
            const data = {
                name: formData.get('name').trim(),
                email: formData.get('email').trim(),
                subject: formData.get('subject').trim(),
                message: formData.get('message').trim()
            };

            // Client-side validation
            if (!data.name || !data.email || !data.subject || !data.message) {
                Popup.show('error-popup', `
                    <h3>Error</h3>
                    <p>All fields are required.</p>
                `, true);
                return;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(data.email)) {
                Popup.show('error-popup', `
                    <h3>Error</h3>
                    <p>Please enter a valid email address.</p>
                `, true);
                return;
            }

            try {
                const result = await fetchWithCsrf('/pages/contact-us.php', {
                    method: 'POST',
                    body: JSON.stringify(data)
                });

                if (result.status === 'success') {
                    Popup.show('success-popup', `
                        <h3>Success</h3>
                        <p>${result.message}</p>
                    `);
                    contactForm.reset();
                    contactForm.querySelector('button[type="submit"]').focus();
                } else {
                    throw new Error(result.message || 'Submission failed');
                }
            } catch (err) {
                console.error('Contact form submission error:', err);
                Popup.show('error-popup', `
                    <h3>Error</h3>
                    <p>Failed to send your message: ${err.message}. Please try again later.</p>
                `, true);
            }
        });

        // Accessibility: Ensure form fields are navigable
        const inputs = contactForm.querySelectorAll('input, textarea, button');
        inputs.forEach((input, index) => {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && input.tagName !== 'BUTTON') {
                    e.preventDefault();
                    const nextIndex = index + 1 < inputs.length ? index + 1 : 0;
                    inputs[nextIndex].focus();
                }
            });
        });
    }
};

// Enhanced Auth Module
const Auth = {
    pendingAuth: null, // Track pending authentication
    resendTimer: null, // Track resend timer

    init() {
        console.log('Auth module initializing at:', new Date().toISOString());
        this.initLoginForm();
        this.initSignupForm();
        this.initForgotPasswordForm();
        this.initOtpForm();
    },

    initLoginForm() {
        const loginForm = document.getElementById('login-form');
        if (!loginForm) {
            console.error('Login form not found');
            return;
        }

        const newForm = loginForm.cloneNode(true);
        loginForm.parentNode.replaceChild(newForm, loginForm);

        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

                const formData = new FormData(e.target);
                const data = {
                    identifier: formData.get('identifier').trim(),
                    password: formData.get('password').trim()
                };

                await this.submitForm('/auth/login.php', data, e.target, 'identifier');
            } catch (err) {
                console.error('Login error:', err);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    },

    initSignupForm() {
        const signupForm = document.getElementById('signup-form');
        if (!signupForm) {
            console.error('Signup form not found');
            return;
        }

        const newForm = signupForm.cloneNode(true);
        signupForm.parentNode.replaceChild(newForm, signupForm);

        document.getElementById('signup-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

                const formData = new FormData(e.target);
                const data = {
                    first_name: formData.get('first_name').trim(),
                    last_name: formData.get('last_name').trim(),
                    username: formData.get('username').trim(),
                    email: formData.get('email').trim(),
                    password: formData.get('password').trim()
                };

                await this.submitForm('/auth/signup.php', data, e.target, 'email');
            } catch (err) {
                console.error('Signup error:', err);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    },

    initForgotPasswordForm() {
        const forgotForm = document.getElementById('forgot-password-form');
        if (!forgotForm) {
            console.error('Forgot password form not found');
            return;
        }

        const newForm = forgotForm.cloneNode(true);
        forgotForm.parentNode.replaceChild(newForm, forgotForm);

        document.getElementById('forgot-password-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

                const formData = new FormData(e.target);
                const data = {
                    email: formData.get('email').trim()
                };

                await this.submitForgotPassword('/auth/forgot-password.php', data, e.target);
            } catch (err) {
                console.error('Forgot password error:', err);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    },

    initOtpForm() {
        const otpForm = document.getElementById('otp-verification-form');
        if (!otpForm) {
            console.error('OTP verification form not found');
            return;
        }

        otpForm.addEventListener('submit', (e) => {
            e.preventDefault();
            this.verifyOtp();
        });
    },

    async submitForm(url, data, form, emailKey) {
        try {
            const result = await fetchWithCsrf(url, {
                method: 'POST',
                body: JSON.stringify(data),
                credentials: 'same-origin'
            });

            if (result.status === 'success' && result.message === 'OTP sent to your email') {
                this.showOtpPopup(form, data, emailKey);
            } else if (result.status === 'success') {
                window.location.href = result.redirect;
            } else {
                throw new Error(result.message || 'Unknown error occurred');
            }
        } catch (err) {
            console.error('Form submission error:', err);
            Popup.show('error-popup', `
                <h3>Error</h3>
                <p>${err.message || 'Failed to process request. Please try again.'}</p>
            `, true);
            throw err;
        }
    },

    async submitForgotPassword(url, data, form) {
    try {
        const result = await fetchWithCsrf(url, {
            method: 'POST',
            body: JSON.stringify(data),
            credentials: 'same-origin'
        });

        if (result.status === 'success' && result.message === 'OTP sent to your email') {
            this.showForgotPasswordOtpPopup(form, data);
        } else if (result.status === 'success') {
            Popup.show('success-popup', `
                <h3>Success!</h3>
                <p>OTP has been sent to your email. Please check your inbox.</p>
                <button class="btn btn-primary" onclick="window.location.href='/auth/login.php'">Login Now</button>
            `);
            setTimeout(() => {
                window.location.href = '/auth/login.php';
            }, 3000);
        } else {
            throw new Error(result.message || 'Unknown error occurred');
        }
    } catch (err) {
        console.error('Forgot password error:', err);
        Popup.show('error-popup', `
            <h3>Error</h3>
            <p>${err.message || 'Failed to process request. Please try again.'}</p>
        `, true);
        throw err;
    }
},

showForgotPasswordOtpPopup(form, data) {
    // Store original form data
    this.pendingAuth = {
        formData: data,
        formType: form.id,
        emailKey: 'email'
    };

    Popup.show('otp-popup', `
        <h3>Reset Password</h3>
        <p>Please enter the OTP sent to your email and set your new password.</p>
        <form id="reset-password-form" onsubmit="return false;">
            <div class="form-group">
                <input type="text" id="otp-input" class="form-control mb-3" placeholder="Enter OTP" required>
            </div>
            <div class="form-group">
                <small class="form-text text-muted">Password must be at least 8 characters long and include uppercase, number, and special character.</small>
                <input type="password" id="new-password" class="form-control mb-3" placeholder="Enter New Password" required>
            </div>
            <div class="form-group">
                <input type="password" id="confirm-password" class="form-control mb-3" placeholder="Confirm New Password" required>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" id="submit-otp-btn">Reset Password</button>
                <button class="btn btn-secondary" id="cancel-otp-btn">Cancel</button>
            </div>
            <div class="mt-3 text-center">
                <p id="resend-timer" style="display: none;">Resend OTP in <span id="timer">30</span> seconds</p>
                <a href="#" id="resend-otp" style="display: none;">Resend OTP</a>
            </div>
        </form>
    `);

    // Bind events after popup is shown
    setTimeout(() => {
        const submitBtn = document.getElementById('submit-otp-btn');
        const cancelBtn = document.getElementById('cancel-otp-btn');
        const resendBtn = document.getElementById('resend-otp');
        const otpInput = document.getElementById('otp-input');
        const newPasswordInput = document.getElementById('new-password');
        const confirmPasswordInput = document.getElementById('confirm-password');
        
        if (submitBtn) {
            submitBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.verifyForgotPasswordOtp();
            });
        }
        
        if (cancelBtn) {
            cancelBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                Popup.hide('otp-popup');
            });
        }
        
        if (resendBtn) {
            resendBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.resendOtp();
            });
        }
        
        // Enter key support for inputs
        [otpInput, newPasswordInput, confirmPasswordInput].forEach((input, index, inputs) => {
            if (input) {
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        e.stopPropagation();
                        if (index === inputs.length - 1) {
                            this.verifyForgotPasswordOtp();
                        } else {
                            inputs[index + 1].focus();
                        }
                    }
                });
            }
        });
        
        if (otpInput) {
            otpInput.focus();
        }
    }, 100);
    
    this.startResendTimer();
},

async verifyForgotPasswordOtp() {
    const otpInput = document.getElementById('otp-input');
    const newPasswordInput = document.getElementById('new-password');
    const confirmPasswordInput = document.getElementById('confirm-password');

    if (!otpInput || !otpInput.value.trim()) {
        Popup.show('error-popup', 'Please enter the OTP code');
        return;
    }

    if (!newPasswordInput || !newPasswordInput.value.trim()) {
        Popup.show('error-popup', 'Please enter a new password');
        return;
    }

    if (!confirmPasswordInput || !confirmPasswordInput.value.trim()) {
        Popup.show('error-popup', 'Please confirm your new password');
        return;
    }

    if (newPasswordInput.value !== confirmPasswordInput.value) {
        Popup.show('error-popup', 'Passwords do not match');
        return;
    }

    const submitBtn = document.querySelector('#otp-popup .btn-primary');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Resetting Password...';

    try {
        if (!this.pendingAuth) {
            throw new Error('Session expired. Please restart the process.');
        }

        const data = {
            email: this.pendingAuth.formData.email,
            otp: otpInput.value.trim(),
            new_password: newPasswordInput.value.trim(),
            confirm_password: confirmPasswordInput.value.trim()
        };

        const response = await fetchWithCsrf('/auth/forgot-password.php', {
            method: 'POST',
            body: JSON.stringify(data),
            credentials: 'same-origin'
        });

        if (response.status === 'success') {
            // Clear any pending state
            this.pendingAuth = null;
            
            // Clear any existing timers
            if (this.resendTimer) {
                clearInterval(this.resendTimer);
                this.resendTimer = null;
            }

            // Hide OTP popup
            Popup.hide('otp-popup');

            // Show success message
            Popup.show('success-popup', `
                <h3>Success!</h3>
                <p>Your password has been reset successfully!</p>
                <button class="btn btn-primary mt-3" onclick="window.location.href='/auth/login.php'">Login Now</button>
            `);

            // Redirect to login page after 3 seconds
            setTimeout(() => {
                window.location.href = '/auth/login.php';
            }, 3000);
        } else {
            throw new Error(response.message || 'Failed to reset password');
        }
    } catch (error) {
        console.error('Password reset error:', error);
        Popup.show('error-popup', `
            <h3>Error</h3>
            <p>${error.message || 'Failed to reset password'}</p>
        `);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
},

    showOtpPopup(form, data, emailKey) {
        // Store original form data
        this.pendingAuth = {
            formData: data,
            formType: form.id,
            emailKey: emailKey
        };

        Popup.show('otp-popup', `
            <h3>Enter OTP</h3>
            <p>OTP sent to your email.</p>
            <input type="text" id="otp-input" placeholder="Enter OTP" aria-label="OTP" class="form-control mb-3">
            <input type="hidden" name="identifier" id="otp-identifier" value="${data.identifier || data.email || ''}">
            <input type="hidden" name="password" id="otp-password" value="${data.password || ''}">
            <div class="d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" id="submit-otp-btn">Submit</button>
                <button class="btn btn-secondary" id="cancel-otp-btn">Cancel</button>
            </div><br><br>
            <div class="mt-3 text-center">
                <p id="resend-timer" style="display: none;">Resend in <span id="timer">30</span> seconds</p>
                <a href="#" id="resend-otp" style="display: none;">Resend OTP</a>
            </div>
        `);

        // Bind events after popup is shown
        setTimeout(() => {
            const submitBtn = document.getElementById('submit-otp-btn');
            const cancelBtn = document.getElementById('cancel-otp-btn');
            const resendBtn = document.getElementById('resend-otp');
            const otpInput = document.getElementById('otp-input');
            
            if (submitBtn) {
                submitBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.verifyOtp();
                });
            }
            
            if (cancelBtn) {
                cancelBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    Popup.hide('otp-popup');
                });
            }
            
            if (resendBtn) {
                resendBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.resendOtp();
                });
            }
            
            // Enter key support for OTP input
            if (otpInput) {
                otpInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        e.stopPropagation();
                        this.verifyOtp();
                    }
                });
                otpInput.focus();
            }
        }, 100);
        
        this.startResendTimer();
},

    async verifyOtp() {
        const otpInput = document.getElementById('otp-input');
        if (!otpInput || !otpInput.value.trim()) {
            Popup.show('error-popup', 'Please enter the OTP code');
            return;
        }

        const submitBtn = document.querySelector('#otp-popup .btn-primary');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Verifying...';

        try {
            if (!this.pendingAuth) {
                throw new Error('Session expired. Please restart the process.');
            }

            // Prepare data based on form type
            let data = {
                otp: otpInput.value.trim()
            };

            let endpoint = '/auth/login.php';

            // Handle different form types
            if (this.pendingAuth.formType === 'login-form') {
                data.identifier = this.pendingAuth.formData.identifier;
                data.password = this.pendingAuth.formData.password;
                endpoint = '/auth/login.php';
            } else if (this.pendingAuth.formType === 'signup-form') {
                data.first_name = this.pendingAuth.formData.first_name;
                data.last_name = this.pendingAuth.formData.last_name;
                data.username = this.pendingAuth.formData.username;
                data.email = this.pendingAuth.formData.email;
                data.password = this.pendingAuth.formData.password;
                endpoint = '/auth/signup.php';
            } else if (this.pendingAuth.formType === 'forgot-password-form') {
                data.email = this.pendingAuth.formData.email;
                if (this.pendingAuth.formData.new_password) {
                    data.new_password = this.pendingAuth.formData.new_password;
                    data.confirm_password = this.pendingAuth.formData.confirm_password;
                }
                endpoint = '/auth/forgot-password.php';
            }

            console.log('Sending OTP verification with data:', data);
            console.log('To endpoint:', endpoint);

            const response = await fetchWithCsrf(endpoint, {
                method: 'POST',
                body: JSON.stringify(data),
                credentials: 'same-origin'
            });

            console.log('OTP verification response:', response);

                        if (response.status === 'success') {
            // Store formType before clearing pendingAuth
            const currentFormType = this.pendingAuth?.formType;
            
            // Clear any pending state
            this.pendingAuth = null;
            
            // Clear any existing timers
            if (this.resendTimer) {
                clearInterval(this.resendTimer);
                this.resendTimer = null;
            }
            
            // Hide all popups immediately
            document.querySelectorAll('.popup').forEach(popup => {
                popup.style.display = 'none';
                popup.style.opacity = '0';
            });
            
            // Remove overlay immediately
            const overlay = document.querySelector('.popup-overlay');
            if (overlay) {
                overlay.style.display = 'none';
                overlay.style.opacity = '0';
            }
            
            // Clear body overflow
            document.body.style.overflow = 'auto';
            
            // Clear any form data
            document.querySelectorAll('form').forEach(form => form.reset());
            
            // **यहां justLoggedIn flag set करें - LOGIN SUCCESS के बाद**
            if (currentFormType === 'login-form') {
                sessionStorage.setItem('justLoggedIn', 'true');
                // Force clearing notification permission flag to ensure prompt after login
                localStorage.removeItem('notificationPermissionAsked');
                console.log('Set justLoggedIn flag for push notification');
            }
            
            // Show success message briefly before redirect
            if (currentFormType === 'signup-form') {
                Popup.show('success-popup', `
                    <h3>Success!</h3>
                    <p>Your account has been created successfully!</p>
                    <button class="btn btn-primary mt-3" onclick="window.location.href='/auth/login.php'">Login Now</button>
                `);
                setTimeout(() => {
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    } else {
                        window.location.href = '/auth/login.php';
                    }
                }, 3000);
            } else {
                // For login, redirect immediately
                if (response.redirect) {
                    window.location.href = response.redirect;
                } else if (response.is_admin) {
                    window.location.href = '/admin/dashboard';
                } else {
                    window.location.href = '/user/dashboard';
                }
            }
                
                return; // Prevent further processing
            } else {
                throw new Error(response.message || 'OTP verification failed');
            }
        } catch (error) {
            console.error('OTP verification error:', error);
            Popup.show('error-popup', `
                <h3>Error</h3>
                <p>${error.message || 'Failed to verify OTP'}</p>
            `);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
},
    async resendOtp() {
        if (!this.pendingAuth) {
            Popup.show('error-popup', 'Session expired. Please restart the process.');
            return;
        }

        const resendBtn = document.getElementById('resend-otp');
        const originalText = resendBtn.innerHTML;
        resendBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';

        try {
            // Determine the correct endpoint based on the form type
            let endpoint = '/auth/login.php';
            if (this.pendingAuth.formType === 'forgot-password-form') {
                endpoint = '/auth/forgot-password.php';
            }

            const response = await fetchWithCsrf(endpoint, {
                method: 'POST',
                body: JSON.stringify(this.pendingAuth.formData),
                credentials: 'same-origin'
            });

            if (response.status === 'success') {
                Popup.show('success-popup', 'New OTP sent to your email');
                this.startResendTimer();
            } else {
                throw new Error(response.message || 'Failed to resend OTP');
            }
        } catch (error) {
            console.error('Resend OTP error:', error);
            Popup.show('error-popup', error.message || 'Failed to resend OTP');
        } finally {
            resendBtn.innerHTML = originalText;
        }
    },

    startResendTimer() {
        let timeLeft = 30;
        const timerEl = document.getElementById('timer');
        const resendEl = document.getElementById('resend-otp');
        const timerContainer = document.getElementById('resend-timer');

        resendEl.style.display = 'none';
        timerContainer.style.display = 'block';

        // Clear existing timer if any
        if (this.resendTimer) {
            clearInterval(this.resendTimer);
        }

        this.resendTimer = setInterval(() => {
            timeLeft--;
            timerEl.textContent = timeLeft;

            if (timeLeft <= 0) {
                clearInterval(this.resendTimer);
                timerContainer.style.display = 'none';
                resendEl.style.display = 'inline';
            }
        }, 1000);
    }
};

// Utility Functions
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map(char => char.charCodeAt(0)));
}

function initPriceHistoryScroll() {
    if (document.getElementById('price-history-result') && !history.state?.noScroll) {
        const headerHeight = document.querySelector('header')?.offsetHeight || 0;
        window.scrollTo({
            top: document.getElementById('price-history-result').offsetTop - headerHeight,
            behavior: 'smooth'
        });
    }
}

async function toggleFavorite(productId, isFavorite) {
    try {
        await fetchWithCsrf('/user/toggle_favorite.php', {
            method: 'POST',
            body: JSON.stringify({ product_id: productId, is_favorite: !isFavorite })
        });

        const heart = document.querySelector(`i[data-product-id="${productId}"]`) || event.target;
        heart.classList.toggle('favorite');
        heart.style.color = isFavorite ? '#ccc' : '#ff0000';
        
        Popup.show('favorite-popup', `
            <h3>${isFavorite ? 'Removed' : 'Added'} Favorite</h3>
            <p>Product ${isFavorite ? 'removed from' : 'added to'} your favorites.</p>
        `);
        
        if (!isFavorite) {
            const alertData = await fetchWithCsrf('/user/check_alerts.php', {
                method: 'POST',
                body: JSON.stringify({ product_id: productId })
            });
            
            if (alertData.status === 'success' && !alertData.alerts_active) {
                Popup.show('permission-popup', `
                    <h3>Enable Notifications</h3>
                    <p>Would you like to receive price alerts for this product?</p>
                    <button class="btn btn-primary" onclick="Push.requestPermission(true, '${productId}')">Yes</button>
                    <button class="btn btn-secondary" onclick="Push.dismissPermission()">No</button>
                `);
            }
        }
    } catch (err) {
        console.error('Favorite toggle error:', err);
        Popup.show('error-popup', `
            <h3>Error</h3>
            <p>${err.message || 'Failed to update favorite. Please try again.'}</p>
        `, true);
    }
}

function initNavbarAccessibility() {
    const navLinks = document.querySelectorAll('nav a');
    navLinks.forEach(link => {
        link.setAttribute('role', 'menuitem');
        link.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                link.click();
            }
        });
    });
}

// Initialize Modules
document.addEventListener('DOMContentLoaded', () => {
    console.log('Initializing modules at:', new Date().toISOString());
    try {
        Carousel.init();
        Popup.init();
        Deals.init();
        Navbar.init();
        ContactForm.init();
        Auth.init();
        initPriceHistoryScroll();
        initNavbarAccessibility();
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        console.log('Initial CSRF Token:', csrfToken || 'none');
        
        if (!csrfToken) {
            console.error('CSRF token meta tag missing or empty');
            Popup.show('error-popup', `
                <h3>Error</h3>
                <p>CSRF token missing. Please reload the page.</p>
            `, true);
        }
        
        console.log('main.js fully loaded and initialized');
    } catch (err) {
        console.error('Initialization error:', err);
        Popup.show('error-popup', `
            <h3>Error</h3>
            <p>Failed to initialize page: ${err.message}. Please reload the page.</p>
        `, true);
    }
});

// Enhanced Carousel Scroll Function
function scrollCarousel(carouselId, direction) {
    const carousel = document.getElementById(carouselId);
    if (!carousel) return;
    
    const cardWidth = 300; // Approximate width of one card + gap
    const scrollAmount = direction * cardWidth;
    
    carousel.scrollBy({
        left: scrollAmount,
        behavior: 'smooth'
    });
    
    // Add visual feedback
    const arrows = carousel.parentElement.querySelectorAll('.carousel-arrow');
    arrows.forEach(arrow => {
        arrow.style.transform = direction > 0 ? 'translateY(-50%) scale(0.9)' : 'translateY(-50%) scale(0.9)';
        setTimeout(() => {
            arrow.style.transform = 'translateY(-50%) scale(1)';
        }, 150);
    });
}

// Enhanced Skeleton Loading with Staggered Animation
document.addEventListener('DOMContentLoaded', () => {
    // Add staggered animation to skeleton cards
    const skeletonCards = document.querySelectorAll('.skeleton');
    skeletonCards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
    });
    
    // Simulate loading completion after 2 seconds
    setTimeout(() => {
        const carousels = document.querySelectorAll('.product-carousel');
        carousels.forEach(carousel => {
            const skeletons = carousel.querySelectorAll('.skeleton');
            if (skeletons.length > 0) {
                skeletons.forEach((skeleton, index) => {
                    setTimeout(() => {
                        skeleton.style.opacity = '0';
                        skeleton.style.transform = 'scale(0.9)';
                        setTimeout(() => {
                            skeleton.remove();
                        }, 300);
                    }, index * 100);
                });
            }
        });
    }, 2000);
});

// Touch and Swipe Support for Mobile
document.querySelectorAll('.product-carousel').forEach(carousel => {
    let isDown = false;
    let startX;
    let scrollLeft;
    
    carousel.addEventListener('mousedown', (e) => {
        isDown = true;
        carousel.classList.add('active');
        startX = e.pageX - carousel.offsetLeft;
        scrollLeft = carousel.scrollLeft;
    });
    
    carousel.addEventListener('mouseleave', () => {
        isDown = false;
        carousel.classList.remove('active');
    });
    
    carousel.addEventListener('mouseup', () => {
        isDown = false;
        carousel.classList.remove('active');
    });
    
    carousel.addEventListener('mousemove', (e) => {
        if (!isDown) return;
        e.preventDefault();
        const x = e.pageX - carousel.offsetLeft;
        const walk = (x - startX) * 2;
        carousel.scrollLeft = scrollLeft - walk;
    });
});

// Price Update Notifications
function showPriceUpdateNotification(productName, oldPrice, newPrice) {
    const notification = document.createElement('div');
    notification.className = 'price-notification';
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas fa-tag"></i>
            <div>
                <strong>${productName}</strong>
                <p>Price updated: ₹${oldPrice} → ₹${newPrice}</p>
            </div>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('show');
    }, 100);
    
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 3000);
}

// Enhanced Deals Functionality
const EnhancedDeals = {
    init() {
        this.initSkeletonLoading();
        this.initFilterOptimization();
        this.initLazyLoading();
    },

    initSkeletonLoading() {
        const grid = document.getElementById('product-grid');
        if (!grid) return;

        // Enhanced skeleton with staggered animation
        const createSkeleton = (index) => `
            <div class="product-card skeleton" style="animation-delay: ${index * 0.1}s">
                <div class="skeleton-image"></div>
                <div class="skeleton-title"></div>
                <div class="skeleton-price"></div>
                <div class="skeleton-tracking"></div>
                <div class="skeleton-button"></div>
                <div class="skeleton-button"></div>
            </div>
        `;

        if (grid.children.length === 0) {
            grid.innerHTML = Array(8).fill().map((_, i) => createSkeleton(i)).join('');
            
            setTimeout(() => {
                grid.style.transition = 'opacity 0.5s ease';
                grid.style.opacity = '1';
            }, 500);
        }
    },

    initFilterOptimization() {
        const selects = document.querySelectorAll('.filter-group select');
        selects.forEach(select => {
            select.addEventListener('change', this.debounce(() => {
                this.showLoadingState();
                setTimeout(() => this.applyFilters(), 300);
            }, 500));
        });
    },

    initLazyLoading() {
        const images = document.querySelectorAll('img[loading="lazy"]');
        images.forEach(img => {
            img.addEventListener('load', () => {
                img.style.opacity = '1';
                img.style.transform = 'scale(1)';
            });
        });
    },

    showLoadingState() {
        const grid = document.getElementById('product-grid');
        if (grid) {
            grid.style.opacity = '0.6';
            grid.style.transform = 'scale(0.98)';
        }
    },

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
};

// Initialize Enhanced Deals
document.addEventListener('DOMContentLoaded', () => {
    EnhancedDeals.init();
});

// Homepage specific JavaScript for AI Recommendations and Trending sections

document.addEventListener('DOMContentLoaded', function() {
    initAIInteractionTracking();
    initInfiniteScroll();
});

// AI interaction tracking
function initAIInteractionTracking() {
    // Track AI recommendation clicks
    document.querySelectorAll('.ai-recommendations .btn-small').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const productCard = this.closest('.carousel-product-card');
            if (productCard) {
                const productImage = productCard.querySelector('img');
                const asin = extractASINFromImage(productImage);
                const action = this.textContent.includes('Buy') ? 'ai_buy_click' : 'ai_track_click';
                trackAIInteraction(action, asin);
            }
        });
    });

    // Track trending product clicks
    document.querySelectorAll('.trending-section .btn-small').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const productCard = this.closest('.carousel-product-card');
            if (productCard) {
                const productImage = productCard.querySelector('img');
                const asin = extractASINFromImage(productImage);
                const action = this.textContent.includes('Buy') ? 'trending_buy_click' : 'trending_track_click';
                trackTrendingInteraction(action, asin);
            }
        });
    });
}

// Extract ASIN from product image alt text or URL
function extractASINFromImage(imageElement) {
    if (!imageElement) return '';
    
    // Try to extract from alt text or src
    const alt = imageElement.alt || '';
    const src = imageElement.src || '';
    
    // Simple extraction - you might need to adjust based on your data structure
    const asinMatch = (alt + src).match(/[A-Z0-9]{10}/);
    return asinMatch ? asinMatch[0] : '';
}

// Track AI interactions
function trackAIInteraction(action, asin) {
    if (!asin) return;
    
    fetch('/api/track_interaction.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: action,
            asin: asin,
            source: 'ai_recommendations',
            timestamp: new Date().toISOString()
        })
    }).catch(error => {
        console.error('AI interaction tracking failed:', error);
    });
}

// Track trending interactions
function trackTrendingInteraction(action, asin) {
    if (!asin) return;
    
    fetch('/api/track_interaction.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: action,
            asin: asin,
            source: 'trending_products',
            timestamp: new Date().toISOString()
        })
    }).catch(error => {
        console.error('Trending interaction tracking failed:', error);
    });
}

// Infinite scroll effect for better UX
function initInfiniteScroll() {
    const carousels = document.querySelectorAll('.product-carousel.auto-scroll');
    
    carousels.forEach(carousel => {
        let isScrolling = false;
        
        carousel.addEventListener('scroll', () => {
            if (!isScrolling) {
                window.requestAnimationFrame(() => {
                    const maxScroll = carousel.scrollWidth - carousel.clientWidth;
                    const currentScroll = carousel.scrollLeft;
                    
                    // Reset scroll position for infinite effect
                    if (currentScroll >= maxScroll * 0.75) {
                        carousel.scrollLeft = currentScroll - (maxScroll / 2);
                    }
                    
                    isScrolling = false;
                });
                isScrolling = true;
            }
        });
    });
}

// Enhanced carousel scroll function (overrides main.js function)
function scrollCarousel(carouselId, direction) {
    const carousel = document.getElementById(carouselId);
    if (!carousel) return;
    
    const cardWidth = 300; // Approximate width of one card + gap
    const scrollAmount = direction * cardWidth;
    
    carousel.scrollBy({
        left: scrollAmount,
        behavior: 'smooth'
    });
    
    // Add visual feedback
    const arrows = carousel.parentElement?.querySelectorAll('.carousel-arrow');
    if (arrows) {
        arrows.forEach(arrow => {
            arrow.style.transform = direction > 0 
                ? 'translateY(-50%) scale(0.9)' 
                : 'translateY(-50%) scale(0.9)';
            
            setTimeout(() => {
                arrow.style.transform = 'translateY(-50%) scale(1)';
            }, 150);
        });
    }
}

// Performance optimization: Lazy load images
function initLazyLoading() {
    const images = document.querySelectorAll('.carousel-product-card img[data-src]');
    
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
                imageObserver.unobserve(img);
            }
        });
    });
    
    images.forEach(img => imageObserver.observe(img));
}

// Initialize lazy loading if supported
if ('IntersectionObserver' in window) {
    initLazyLoading();
}

// Error handling for failed requests
window.addEventListener('unhandledrejection', event => {
    console.error('Unhandled promise rejection in homepage:', event.reason);
});

// Accessibility improvements
function initAccessibility() {
    // Add keyboard navigation for carousels
    const carouselCards = document.querySelectorAll('.carousel-product-card');
    
    carouselCards.forEach((card, index) => {
        card.setAttribute('tabindex', '0');
        card.setAttribute('role', 'article');
        card.setAttribute('aria-label', `Product ${index + 1}`);
        
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const buyButton = card.querySelector('.btn-primary-small');
                if (buyButton) buyButton.click();
            }
        });
    });
}

// Initialize accessibility features
initAccessibility();