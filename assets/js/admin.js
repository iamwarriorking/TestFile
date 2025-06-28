/** Admin JavaScript for AmezPrice **/

const POPUPS = {
    SUCCESS: 'success-popup',
    ERROR: 'error-popup',
    OTP: 'otp-popup',
    DELETE_USER: 'delete-user-popup',
    DELETE_LOG: 'delete-log-popup',
    LOG_VIEW: 'log-view-popup'
};

const ENDPOINTS = {
    DELETE_USER: '/admin/delete_user.php',
    LOG_VIEW: '/admin/logs/view.php',
    DELETE_LOG: '/admin/logs/delete.php',
    SETTINGS_API_UI: '/admin/settings/api_ui.php',
    SETTINGS_CATEGORY: '/admin/settings/category.php',
    SETTINGS_TELEGRAM: '/admin/settings/telegram.php',
    SETTINGS_SOCIAL_SECURITY: '/admin/settings/social_security.php',
    SETTINGS_MAIL: '/admin/settings/mail.php',
    PROMOTION_CHANNEL: '/admin/promotion/channel.php',
    PROMOTION_DMS: '/admin/promotion/dms.php',
    PROMOTION_EMAIL: '/admin/promotion/email.php',
};

// Improved CSRF token retrieval function
const getCsrfToken = () => {
    // Try multiple ways to get the token
    const meta = document.querySelector('meta[name="csrf-token"]');
    const hiddenInput = document.querySelector('input[name="csrf_token"]');
    
    if (meta && meta.content) {
        console.log('CSRF token found in meta tag:', meta.content);
        return meta.content;
    }
    
    if (hiddenInput && hiddenInput.value) {
        console.log('CSRF token found in hidden input:', hiddenInput.value);
        return hiddenInput.value;
    }
    
    console.error('CSRF token not found in meta tag or hidden input');
    return '';
};

function toggleSubmenu(element) {
    document.querySelectorAll('.menu-item .submenu.show').forEach(submenu => {
        const menuHeading = submenu.parentElement.querySelector('.menu-heading');
        if (menuHeading !== element) {
            menuHeading.classList.remove('active');
            submenu.classList.remove('show');
        }
    });

    const submenu = element.parentElement.querySelector('.submenu');
    
    element.classList.toggle('active');
    submenu.classList.toggle('show');
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.position = 'fixed';
    toast.style.bottom = '20px';
    toast.style.right = '20px';
    toast.style.padding = '12px 24px';
    toast.style.zIndex = '1000';
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s';
        setTimeout(() => toast.remove(), 500);
    }, 3000);
}

function debounce(func, wait) {
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

async function sendDMPromotion(data, csrfToken) {
    try {
        const response = await fetch(ENDPOINTS.PROMOTION_DMS, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json', 
                'X-CSRF-Token': csrfToken 
            },
            body: JSON.stringify(data),
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();

        if (result.status === 'success') {
            showPopup('success-popup', `<h3>Success</h3><p>${result.message}</p>`);
            document.getElementById('dm-promotion-form').reset();
        } else {
            throw new Error(result.message || 'Failed to send DM promotion');
        }
    } catch (error) {
        console.error('Error:', error);
        showPopup('error-popup', `<h3>Error</h3><p>${error.message || 'Failed to send DM promotion. Please try again.'}</p>`);
    }
}

// Popup Management
document.querySelectorAll('.popup-close').forEach(closeBtn => {
    closeBtn.addEventListener('click', () => {
        const popup = closeBtn.closest('.popup');
        hidePopup(popup.id);
    });
});

// Handle keyboard navigation for popups
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.popup').forEach(popup => {
            if (popup.style.display === 'block') {
                hidePopup(popup.id);
            }
        });
    }
});

// Form Validation
function validateForm(formId) {
    const form = document.getElementById(formId);
    const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
    let valid = true;

    inputs.forEach(input => {
        if (!input.value.trim()) {
            valid = false;
            input.classList.add('error');
            input.setAttribute('aria-invalid', 'true');
        } else {
            input.classList.remove('error');
            input.setAttribute('aria-invalid', 'false');
        }
    });

    return valid;
}

// Sidebar Toggle
document.querySelector('.admin-hamburger')?.addEventListener('click', () => {
    document.querySelector('.admin-sidebar').classList.toggle('active');
});

// User Deletion functions remain the same...
async function confirmDeleteUser(userId, email) {
    showPopup(POPUPS.DELETE_USER, `
        <h3>Confirm Deletion</h3>
        <p>Account with email <strong>${email}</strong> will be permanently deleted and cannot be recovered.</p>
        <button class="btn btn-primary" onclick="requestUserDeletionOtp(${userId})">Yes, Send OTP</button>
        <button class="btn btn-secondary" onclick="hidePopup('${POPUPS.DELETE_USER}')">Cancel</button>
    `);
}

async function requestUserDeletionOtp(userId) {
    showLoading();
    const response = await fetch(ENDPOINTS.DELETE_USER, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ user_id: userId, action: 'request_otp' })
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showPopup(POPUPS.OTP, `
            <h3>Enter OTP</h3>
            <p>OTP sent to your admin email.</p>
            <input type="text" id="otp-input" placeholder="Enter OTP" aria-label="OTP">
            <button class="btn btn-primary" onclick="verifyUserDeletionOtp(${userId})">Submit</button>
            <button class="btn btn-secondary" onclick="hidePopup('${POPUPS.OTP}')">Cancel</button>
            <p id="resend-timer" style="display: none;">Resend in <span id="timer">30</span> seconds</p>
            <a href="#" id="resend-otp" style="display: none;" onclick="requestUserDeletionOtp(${userId})">Resend OTP</a>
        `);
        startResendTimer();
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

async function verifyUserDeletionOtp(userId) {
    const otp = document.getElementById('otp-input').value;
    showLoading();
    const response = await fetch(ENDPOINTS.DELETE_USER, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ user_id: userId, action: 'verify_otp', otp })
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showToast('User deleted successfully');
        hidePopup(POPUPS.OTP);
        setTimeout(() => window.location.reload(), 1000);
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

function startResendTimer() {
    let timeLeft = 30;
    const timerEl = document.getElementById('timer');
    const resendEl = document.getElementById('resend-otp');
    const timerContainer = document.getElementById('resend-timer');
    timerContainer.style.display = 'block';
    resendEl.style.display = 'none';

    const interval = setInterval(() => {
        timeLeft--;
        timerEl.textContent = timeLeft;
        if (timeLeft <= 0) {
            clearInterval(interval);
            timerContainer.style.display = 'none';
            resendEl.style.display = 'block';
        }
    }, 1000);
}

// Log Management functions remain the same...
async function viewLog(filename) {
    showLoading();
    const response = await fetch(ENDPOINTS.LOG_VIEW, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ filename })
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showPopup(POPUPS.LOG_VIEW, `
            <h3>${filename}</h3>
            <pre style="white-space: pre-wrap; max-height: 500px; overflow-y: auto;">${result.content}</pre>
            <button class="btn btn-primary" onclick="downloadLog('${filename}', \`${result.content}\`)">Download</button>
        `);
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

async function confirmDeleteLog(filename) {
    showPopup(POPUPS.DELETE_LOG, `
        <h3>Confirm Deletion</h3>
        <p>Log file <strong>${filename}</strong> will be permanently deleted and cannot be recovered.</p>
        <button class="btn btn-primary" onclick="deleteLog(['${filename}'])">Yes</button>
        <button class="btn btn-secondary" onclick="hidePopup('${POPUPS.DELETE_LOG}')">Cancel</button>
    `);
}

async function deleteLog(filenames) {
    showLoading();
    const response = await fetch(ENDPOINTS.DELETE_LOG, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ filenames })
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showToast('Log files deleted successfully');
        hidePopup(POPUPS.DELETE_LOG);
        setTimeout(() => window.location.reload(), 1000);
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

function downloadLog(filename, content) {
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

// Initialize on Page Load
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM Content Loaded - Initializing admin features');
    
    // Auto-open promotion submenu if on promotion pages
    if (window.location.pathname.includes('/admin/promotion/')) {
        const promotionMenu = document.querySelector('.menu-item .menu-heading');
        if (promotionMenu) {
            toggleSubmenu(promotionMenu);
        }
    }

    // **IMPROVED PROMOTION FORM HANDLING**
    const promotionForm = document.getElementById('promotion-form');
    if (promotionForm) {
        console.log('Promotion form found, setting up event listener');
        
        promotionForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            console.log('Promotion form submitted');
            
            try {
                if (!validateForm('promotion-form')) {
                    showPopup(POPUPS.ERROR, '<h3>Error</h3><p>Please fill all required fields</p>');
                    return;
                }

                const csrfToken = getCsrfToken();
                console.log('Retrieved CSRF token:', csrfToken);
                
                if (!csrfToken) {
                    showPopup('error-popup', '<h3>Error</h3><p>CSRF token not found. Please refresh the page and try again.</p>');
                    return;
                }

                showLoading();
                const formData = new FormData(e.target);
                const data = {
                    channel: formData.get('channel'),
                    message: formData.get('message')
                };

                console.log('Form data prepared:', data);

                const imageFile = formData.get('image');
                if (imageFile && imageFile.size > 0) {
                    console.log('Processing image file...');
                    try {
                        const base64Image = await new Promise((resolve, reject) => {
                            const reader = new FileReader();
                            reader.onload = () => resolve(reader.result);
                            reader.onerror = () => reject(new Error('Failed to read image file'));
                            reader.readAsDataURL(imageFile);
                        });
                        data.image = base64Image;
                        console.log('Image processed successfully');
                    } catch (error) {
                        throw new Error('Failed to process image file');
                    }
                }

                console.log('Sending request to server...');
                const response = await fetch('/admin/promotion/channel.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(data),
                    credentials: 'same-origin'
                });

                console.log('Response status:', response.status);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                console.log('Server response:', result);
                
                if (result.status === 'success') {
                    showPopup('success-popup', `<h3>Success</h3><p>${result.message}</p>`);
                    e.target.reset(); // Reset form on success
                } else {
                    throw new Error(result.message || 'Failed to send promotion');
                }
            } catch (error) {
                console.error('Error:', error);
                showPopup('error-popup', `<h3>Error</h3><p>${error.message || 'Failed to send promotion. Please try again.'}</p>`);
            } finally {
                hideLoading();
            }
        });
    } else {
        console.log('Promotion form not found on this page');
    }

    // 🆕 DM promotion form specific handling
    const dmPromotionForm = document.getElementById('dm-promotion-form');
    if (dmPromotionForm) {
        console.log('DM promotion form found, setting up event listener');
        
        dmPromotionForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            console.log('DM promotion form submitted');
            
            try {
                if (!validateForm('dm-promotion-form')) {
                    showPopup(POPUPS.ERROR, '<h3>Error</h3><p>Please fill all required fields</p>');
                    return;
                }

                const csrfToken = getCsrfToken();
                console.log('Retrieved CSRF token:', csrfToken);
                
                if (!csrfToken) {
                    showPopup('error-popup', '<h3>Error</h3><p>CSRF token not found. Please refresh the page and try again.</p>');
                    return;
                }

                showLoading();
                const formData = new FormData(e.target);
                const data = {
                    bot_type: formData.get('bot_type'), // 🆕 Bot type
                    message: formData.get('message')
                };

                console.log('Form data prepared:', data);

                const imageFile = formData.get('image');
                if (imageFile && imageFile.size > 0) {
                    console.log('Processing image file...');
                    try {
                        const base64Image = await new Promise((resolve, reject) => {
                            const reader = new FileReader();
                            reader.onload = () => resolve(reader.result);
                            reader.onerror = () => reject(new Error('Failed to read image file'));
                            reader.readAsDataURL(imageFile);
                        });
                        data.image = base64Image;
                        console.log('Image processed successfully');
                    } catch (error) {
                        throw new Error('Failed to process image file');
                    }
                }

                await sendDMPromotion(data, csrfToken);
            } catch (error) {
                console.error('Error:', error);
                showPopup('error-popup', `<h3>Error</h3><p>${error.message || 'Failed to send DM promotion. Please try again.'}</p>`);
            } finally {
                hideLoading();
            }
        });
        
        console.log('Selected bot type:', data.bot_type);
        console.log('Form data:', formData.get('bot_type'));
        console.log('Select element value:', document.getElementById('bot_type').value);

    } else {
        console.log('DM promotion form not found on this page');
    }

    // Initialize other features
    initTableSorting();
    initSearchFilter();
    initThemeToggle();

    // Initialize social security settings if on that page
    if (window.location.pathname.includes('social_security.php')) {
        initSocialSecuritySettings();
    }

    // Form submissions for other settings
    ['amazon-form', 'flipkart-form', 'marketplaces-form', 'social-form', 'security-form', 'mail-form', 'email-promotion-form'].forEach(formId => {
        const form = document.getElementById(formId);
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!validateForm(formId)) {
                    showPopup(POPUPS.ERROR, '<h3>Error</h3><p>Please fill all required fields</p>');
                    return;
                }

                showLoading();
                const formData = new FormData(form);
                const section = formId.split('-')[0];
                const endpoint = ENDPOINTS[`SETTINGS_${section.toUpperCase()}`] || ENDPOINTS[`PROMOTION_${section.toUpperCase()}`];
                const data = { [section]: Object.fromEntries(formData) };
                await submitForm(endpoint, data);
            });
        }
    });
    
    // Category form specific handling
    const categoryForm = document.getElementById('category-form');
    if (categoryForm) {
        categoryForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!validateForm('category-form')) {
                showPopup(POPUPS.ERROR, '<h3>Error</h3><p>Please fill all required fields</p>');
                return;
            }

            showLoading();
            const formData = new FormData(categoryForm);
            const categories = [];
            const headings = formData.getAll('heading[]');
            const cats = formData.getAll('category[]');
            const subcats = formData.getAll('subcategory[]');
            const platforms = formData.getAll('platform[]');

            for (let i = 0; i < headings.length; i++) {
                categories.push({
                    heading: headings[i],
                    category: cats[i],
                    platform: platforms[i]
                });
            }

            const response = await fetch(ENDPOINTS.SETTINGS_CATEGORY, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({ categories })
            });
            const result = await response.json();
            hideLoading();

            if (result.status === 'success') {
                showToast('Categories updated successfully');
                hidePopup(POPUPS.SUCCESS);
            } else {
                showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
            }
        });
    }
});

// Rest of the functions remain the same...
async function submitForm(endpoint, data) {
    showLoading();
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify(data)
    });
    const result = await response.json();
    hideLoading();

    if (result.status === 'success') {
        showToast('Settings updated successfully');
        hidePopup(POPUPS.SUCCESS);
    } else {
        showPopup(POPUPS.ERROR, `<h3>Error</h3><p>${result.message}</p>`);
    }
}

// Social & Security Settings Functions
function initSocialSecuritySettings() {
    // Handle Social Media form submission
    const socialForm = document.getElementById('social-form');
    if (socialForm) {
        socialForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('/admin/settings/social_security.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showAlert('success', data.message);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred while saving social media settings');
                console.error('Error:', error);
            });
        });
    }

    // Handle Security form submission
    const securityForm = document.getElementById('security-form');
    if (securityForm) {
        securityForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('/admin/settings/social_security.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showAlert('success', data.message);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred while saving security settings');
                console.error('Error:', error);
            });
        });
    }
}

// Show alert function for settings
function showAlert(type, message) {
    const alertElement = document.getElementById(type + '-alert');
    if (alertElement) {
        alertElement.textContent = message;
        alertElement.style.display = 'block';
        
        // Hide other alert
        const otherType = type === 'success' ? 'error' : 'success';
        const otherAlert = document.getElementById(otherType + '-alert');
        if (otherAlert) {
            otherAlert.style.display = 'none';
        }
        
        // Auto hide after 5 seconds
        setTimeout(() => {
            alertElement.style.display = 'none';
        }, 5000);
    }
}

function showLoading() {
    const overlay = document.querySelector('.popup-overlay');
    if (overlay) {
        overlay.style.display = 'block';
    }
    // Disable form submission while loading
    const forms = ['promotion-form', 'dm-promotion-form'];
    forms.forEach(formId => {
        const form = document.getElementById(formId);
        if (form) {
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) submitButton.disabled = true;
        }
    });
}

function hideLoading() {
    const overlay = document.querySelector('.popup-overlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
    // Re-enable form submission
    const forms = ['promotion-form', 'dm-promotion-form'];
    forms.forEach(formId => {
        const form = document.getElementById(formId);
        if (form) {
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) submitButton.disabled = false;
        }
    });
}

// Table Sorting
function initTableSorting() {
    const table = document.querySelector('.admin-table table');
    if (!table) return;

    const headers = table.querySelectorAll('th.sortable');
    headers.forEach(header => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', () => {
            const column = header.textContent.toLowerCase().replace(/\s+/g, '_');
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            const isNumeric = ['highest_price', 'lowest_price', 'current_price', 'tracking_users'].includes(column);
            const ascending = !header.classList.contains('asc');

            rows.sort((a, b) => {
                let aValue = a.querySelector(`td:nth-child(${Array.from(header.parentNode.children).indexOf(header) + 1})`).textContent;
                let bValue = b.querySelector(`td:nth-child(${Array.from(header.parentNode.children).indexOf(header) + 1})`).textContent;

                if (isNumeric) {
                    aValue = parseFloat(aValue.replace(/[^\d.]/g, '')) || 0;
                    bValue = parseFloat(bValue.replace(/[^\d.]/g, '')) || 0;
                    return ascending ? aValue - bValue : bValue - aValue;
                } else {
                    return ascending ? aValue.localeCompare(bValue) : bValue.localeCompare(aValue);
                }
            });

            headers.forEach(h => h.classList.remove('asc', 'desc'));
            header.classList.add(ascending ? 'asc' : 'desc');
            header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');

            const tbody = table.querySelector('tbody');
            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
        });
    });
}

// NEW: Improved Search and Filter - Now uses predefined container
function initSearchFilter() {
    const table = document.querySelector('.admin-table table');
    const searchInput = document.getElementById('admin-table-search');
    
    // Only proceed if both table and search input exist
    if (!table || !searchInput) {
        console.log('Table or search input not found, skipping search initialization');
        return;
    }

    console.log('Initializing search filter for admin table');
    
    // Add search functionality to the existing input
    searchInput.addEventListener('input', debounce(() => {
        const query = searchInput.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = Array.from(row.querySelectorAll('td')).map(td => td.textContent.toLowerCase()).join(' ');
            row.style.display = text.includes(query) ? '' : 'none';
        });
        
        // Show count of visible rows
        const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none').length;
        console.log(`Search: "${query}" - ${visibleRows} results found`);
    }, 300));

    console.log('Search filter initialized successfully');
}

// Enhanced popup management
function showPopup(popupId, content = '') {
    const popup = document.getElementById(popupId);
    const overlay = document.querySelector('.popup-overlay');
    
    if (!popup) return;
    
    if (content) {
        const contentElement = popup.querySelector('.popup-content');
        if (contentElement) {
            contentElement.innerHTML = content;
        }
    }
    
    // Show overlay and popup
    if (overlay) {
        overlay.style.display = 'block';
        setTimeout(() => overlay.style.opacity = '1', 10);
    }
    
    popup.style.display = 'block';
    setTimeout(() => {
        popup.style.opacity = '1';
        popup.style.transform = 'translate(-50%, -50%) scale(1)';
    }, 10);
    
    // Add escape key listener
    document.addEventListener('keydown', escapeKeyHandler);
    
    // Prevent body scroll
    document.body.style.overflow = 'hidden';
}

function hidePopup(popupId) {
    const popup = document.getElementById(popupId);
    const overlay = document.querySelector('.popup-overlay');
    
    if (!popup) return;
    
    // Hide popup with animation
    popup.style.opacity = '0';
    popup.style.transform = 'translate(-50%, -50%) scale(0.95)';
    
    setTimeout(() => {
        popup.style.display = 'none';
    }, 200);
    
    // Hide overlay
    if (overlay) {
        overlay.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
        }, 200);
    }
    
    // Remove escape key listener
    document.removeEventListener('keydown', escapeKeyHandler);
    
    // Restore body scroll
    document.body.style.overflow = '';
}

// Escape key handler for closing popups
function escapeKeyHandler(event) {
    if (event.key === 'Escape') {
        const visiblePopups = document.querySelectorAll('.popup[style*="block"]');
        visiblePopups.forEach(popup => {
            hidePopup(popup.id);
        });
    }
}

// Enhanced loading states
function showLoading(message = 'Loading...') {
    const loadingElement = document.querySelector('.loading-spinner');
    if (loadingElement) {
        loadingElement.style.display = 'block';
        const textElement = loadingElement.querySelector('.loading-text');
        if (textElement) {
            textElement.textContent = message;
        }
    }
}

function hideLoading() {
    const loadingElement = document.querySelector('.loading-spinner');
    if (loadingElement) {
        loadingElement.style.display = 'none';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    
    // Add popup overlay if not exists
    if (!document.querySelector('.popup-overlay')) {
        const overlay = document.createElement('div');
        overlay.className = 'popup-overlay';
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            display: none;
            opacity: 0;
            transition: opacity 0.2s ease;
        `;
        
        overlay.addEventListener('click', function() {
            const visiblePopups = document.querySelectorAll('.popup[style*="block"]');
            visiblePopups.forEach(popup => {
                hidePopup(popup.id);
            });
        });
        
        document.body.appendChild(overlay);
    }
    
    // Style popups
    const popups = document.querySelectorAll('.popup');
    popups.forEach(popup => {
        popup.style.cssText += `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.95);
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            z-index: 10000;
            max-width: 90vw;
            max-height: 90vh;
            opacity: 0;
            transition: all 0.2s ease;
        `;
    });
});

// Theme Toggle
function initThemeToggle() {
    const themeToggle = document.createElement('button');
    themeToggle.className = 'btn btn-secondary';
    themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
    themeToggle.style.position = 'fixed';
    themeToggle.style.top = '20px';
    themeToggle.style.right = '20px';
    themeToggle.setAttribute('aria-label', 'Toggle theme');
    document.body.appendChild(themeToggle);

    const currentTheme = localStorage.getItem('theme') || 'light';
    document.body.classList.add(currentTheme);

    themeToggle.addEventListener('click', () => {
        const newTheme = document.body.classList.contains('light') ? 'dark' : 'light';
        document.body.classList.remove('light', 'dark');
        document.body.classList.add(newTheme);
        localStorage.setItem('theme', newTheme);
        themeToggle.innerHTML = newTheme === 'light' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
    });
}

function hideAllPopups() {
            document.querySelectorAll('.popup').forEach(popup => {
                popup.style.display = 'none';
            });
            document.querySelector('.popup-overlay').style.display = 'none';
        }

        function showPopup(popupId, content) {
            hideAllPopups(); // Hide all popups first
            const popup = document.getElementById(popupId);
            const popupContent = popup.querySelector('.popup-content');
            popupContent.innerHTML = content;
            popup.style.display = 'block';
            document.querySelector('.popup-overlay').style.display = 'block';
        }

        function hidePopup(popupId) {
            document.getElementById(popupId).style.display = 'none';
            document.querySelector('.popup-overlay').style.display = 'none';
        }

        // Custom form handler for email promotion only
        document.getElementById('custom-email-promotion-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Disable submit button to prevent multiple submissions
            const submitBtn = document.getElementById('submit-btn');
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending...';
            
            try {
                const formData = new FormData(e.target);
                const data = {
                    subject: formData.get('subject').trim(),
                    message: formData.get('message').trim()
                };
                
                // Client-side validation
                if (!data.subject || !data.message) {
                    showPopup('error-popup', `<h3>Error</h3><p>Subject and message are required</p>`);
                    return;
                }

                console.log('Sending data:', data); // Debug log

                const response = await fetch('/admin/promotion/email.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json', 
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content 
                    },
                    body: JSON.stringify(data)
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                console.log('Server response:', result); // Debug log

                if (result.status === 'success') {
                    showPopup('success-popup', `<h3>Success</h3><p>${result.message}</p>`);
                    // Clear form on success
                    document.getElementById('custom-email-promotion-form').reset();
                } else {
                    showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
                }
            } catch (error) {
                console.error('Error:', error);
                showPopup('error-popup', `<h3>Error</h3><p>An error occurred while sending the promotion. Please try again.</p>`);
            } finally {
                // Re-enable submit button
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        });

        // Handle close buttons
        document.querySelectorAll('.popup-close').forEach(closeBtn => {
            closeBtn.addEventListener('click', () => {
                const popup = closeBtn.closest('.popup');
                hidePopup(popup.id);
            });
        });

        // Handle ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                hideAllPopups();
            }
        });

        // Handle overlay click
        document.querySelector('.popup-overlay').addEventListener('click', () => {
            hideAllPopups();
        });