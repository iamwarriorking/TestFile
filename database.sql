-- Stores admin credentials
CREATE TABLE admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) COMMENT 'Stores admin credentials';

-- Initial admin insert with bcrypt hash
INSERT INTO admins (first_name, last_name, username, email, password)
VALUES ('Hitesh', 'Rajpurohit', 'HiteshRajpurohit', 'iamrajpurohithitesh@gmail.com', '$2y$10$4fXz4vY9Qz3Xz6Y7z8X9Y.u7vY9Qz3Xz6Y7z8X9Y.u7vY9Qz3Xz6Y');

-- Stores website and Telegram user data
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255),
    username VARCHAR(255) UNIQUE,
    email VARCHAR(255) UNIQUE,
    telegram_id BIGINT UNIQUE,
    telegram_username VARCHAR(32) NULL,
    password VARCHAR(255),
    cluster INT DEFAULT 0 COMMENT 'For AI clustering',
    cluster_updated_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_interaction TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    update_count INT UNSIGNED DEFAULT 0,
    language_code VARCHAR(10),
    INDEX idx_email (email),
    INDEX idx_telegram_id (telegram_id),
    INDEX idx_last_interaction (last_interaction),
    INDEX idx_created_at (created_at),
    INDEX idx_users_cluster (cluster)
) COMMENT 'Stores user data for website and Telegram';

-- Stores product details
CREATE TABLE products (
    asin VARCHAR(20) PRIMARY KEY,
    merchant ENUM('amazon', 'flipkart') NOT NULL,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100) DEFAULT 'General',
    subcategory VARCHAR(100) DEFAULT 'General',
    brand VARCHAR(100) DEFAULT "Generic",
    highest_price DECIMAL(10,2),
    current_price DECIMAL(10,2),
    original_price DECIMAL(10,2),
    lowest_price DECIMAL(10,2),
    website_url VARCHAR(255),
    affiliate_link VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    stock_status ENUM('in_stock', 'out_of_stock') DEFAULT 'in_stock',
    stock_quantity INT,
    out_of_stock_since TIMESTAMP NULL,
    image_path VARCHAR(255),
    local_image_path VARCHAR(500),
    rating DECIMAL(3,1),
    rating_count INT,
    update_count INT UNSIGNED DEFAULT 0,
    tracking_count INT UNSIGNED DEFAULT 0,
    INDEX idx_asin (asin),
    INDEX idx_merchant (merchant),
    INDEX idx_category (category),
    INDEX idx_subcategory (subcategory),
    INDEX idx_categories_combined (category, subcategory),
    INDEX idx_category_merchant (category, merchant),
    INDEX idx_subcategory_merchant (subcategory, merchant),
    INDEX idx_stock (stock_status, stock_quantity),
    INDEX idx_price (current_price, highest_price),
    INDEX idx_original_price (original_price),
    INDEX idx_price_comparison (current_price, original_price),
    INDEX idx_rating (rating),
    INDEX idx_tracking (tracking_count),
    INDEX idx_last_updated (last_updated),
    INDEX idx_products_price_rating (current_price, rating),
    INDEX idx_products_asin_price (asin, current_price)
) COMMENT 'Stores product details from Amazon and Flipkart';

-- Stores price history for products
CREATE TABLE price_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_asin VARCHAR(20) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    date_recorded DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_product_date (product_asin, date_recorded),
    INDEX idx_date (date_recorded),
    INDEX idx_price (price),
    INDEX idx_created_at (created_at),
    UNIQUE KEY unique_product_date (product_asin, date_recorded)
) COMMENT 'Stores daily price history for products - Optimized separate table';

-- Stores user-tracked products
CREATE TABLE user_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_asin VARCHAR(20) NOT NULL,
    product_url VARCHAR(255),
    price_history_url VARCHAR(255),
    price_threshold DECIMAL(10,2),
    is_favorite BOOLEAN DEFAULT FALSE,
    email_alert BOOLEAN DEFAULT FALSE,
    push_alert BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_product_asin (product_asin),
    INDEX idx_user_product_alerts (user_id, email_alert, push_alert)
) COMMENT 'Stores user-tracked products with alert preferences';

-- Stores tracking request limits
CREATE TABLE user_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    asin VARCHAR(20) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_user_created (user_id, created_at),  -- Composite index for faster queries
    INDEX idx_cleanup (created_at)  -- For cleanup operations
) COMMENT 'Tracks user tracking requests for rate limiting - Auto cleanup after 24h';

-- Stores Goldbox products (Amazon deals)
CREATE TABLE IF NOT EXISTS goldbox_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asin VARCHAR(20) NOT NULL,
    merchant ENUM('amazon') NOT NULL DEFAULT 'amazon',
    name VARCHAR(500) NOT NULL,
    current_price DECIMAL(10,2) NOT NULL,
    original_price DECIMAL(10,2),
    discount_percentage INT DEFAULT 0,
    affiliate_link VARCHAR(1000),
    image_url VARCHAR(1000),
    rating DECIMAL(3,2) DEFAULT 0,
    review_count INT DEFAULT 0,
    stock_status ENUM('in_stock', 'out_of_stock', 'limited_stock') DEFAULT 'in_stock',
    category VARCHAR(100) NULL,
    subcategory VARCHAR(100) NULL,
    deal_end_time DATETIME,
    is_lightning_deal BOOLEAN DEFAULT FALSE,
    deal_claimed_percentage INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_asin (asin),
    INDEX idx_discount_percentage (discount_percentage),
    INDEX idx_last_updated (last_updated),
    INDEX idx_stock_status (stock_status),
    INDEX idx_category (category),
    INDEX idx_subcategory (subcategory),
    INDEX idx_category_subcategory (category, subcategory),
    INDEX idx_category_discount (category, discount_percentage),
    INDEX idx_deal_end_time (deal_end_time),
    INDEX idx_lightning_deal (is_lightning_deal),
    UNIQUE KEY unique_asin (asin)
) COMMENT 'Stores Amazon Goldbox deals with enhanced features and category filtering';

-- Stores Flipbox products (Flipkart deals)
CREATE TABLE flipbox_products (
    asin VARCHAR(20) PRIMARY KEY,
    merchant ENUM('flipkart') NOT NULL DEFAULT 'flipkart',
    name VARCHAR(255) NOT NULL,
    current_price DECIMAL(10,2),
    discount_percentage INT,
    affiliate_link VARCHAR(255),
    image_url VARCHAR(255),
    last_updated DATETIME,
    INDEX idx_asin (asin),
    INDEX idx_discount_percentage (discount_percentage)
) COMMENT 'Stores Flipkart deals';

-- Stores HotDeals user and category data
CREATE TABLE hotdealsbot (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT UNIQUE NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255),
    username VARCHAR(255),
    language_code VARCHAR(10),
    category VARCHAR(100) COMMENT 'Main categories (comma-separated)',
    subcategory VARCHAR(100) COMMENT 'Subcategories (comma-separated)',
    merchant ENUM('amazon', 'flipkart', 'both'),
    price_range DECIMAL(10,2),
    is_active BOOLEAN DEFAULT TRUE,
    update_count INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_interaction TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_telegram_id (telegram_id),
    INDEX idx_category (category),
    INDEX idx_subcategory (subcategory),
    INDEX idx_merchant (merchant),
    INDEX idx_is_active (is_active),
    INDEX idx_last_interaction (last_interaction),
    INDEX idx_created_at (created_at),
    INDEX idx_hotdealsbot_active (is_active),
    INDEX idx_hotdealsbot_telegram_active (telegram_id, is_active)
) COMMENT 'Stores HotDeals bot users and their category preferences';

-- Stores AI-generated price predictions
CREATE TABLE predictions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asin VARCHAR(20) NOT NULL,
    predicted_price DECIMAL(10,2),
    prediction_date DATE,
    period VARCHAR(20),
    confidence DECIMAL(3,2) DEFAULT 0.50,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_asin (asin),
    INDEX idx_prediction_date (prediction_date),
    INDEX idx_predictions_asin_date (asin, prediction_date)
) COMMENT 'Stores AI-generated price predictions';

-- Stores detected price drop patterns
CREATE TABLE patterns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asin VARCHAR(20) NOT NULL,
    pattern_description VARCHAR(255),
    confidence DECIMAL(5,2),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_asin (asin)
) COMMENT 'Stores detected price drop patterns';

-- Stores festival and sale event data
CREATE TABLE festivals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(100) NOT NULL,
    event_date DATE NOT NULL,
    event_type ENUM('festival', 'sale') NOT NULL,
    offers_likely BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_date (event_date)
) COMMENT 'Stores festival and sale events for AI predictions';

-- Stores user behavior for AI analysis
CREATE TABLE user_behavior (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    asin VARCHAR(20),
    is_favorite BOOLEAN,
    is_ai_suggested BOOLEAN,
    interaction_type ENUM('buy_now', 'price_history', 'tracking', 'deal_suggested', 'favorite'),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (asin) REFERENCES products(asin) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_asin (asin),
    INDEX idx_user_behavior_user_type (user_id, interaction_type),
    INDEX idx_user_behavior_created (created_at)
) COMMENT 'Stores user behavior for AI analysis';

-- Stores email subscription preferences
CREATE TABLE email_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    subscribed ENUM('yes', 'no') DEFAULT 'yes',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) COMMENT 'Tracks email subscription preferences for offers';

-- Stores one-time passwords for authentication
CREATE TABLE otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    otp VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    telegram_username VARCHAR(32) NULL,
    user_id BIGINT UNSIGNED NULL,
    INDEX idx_email (email),
    INDEX idx_user_id (user_id),
    INDEX idx_telegram_username (telegram_username)
) COMMENT 'Stores OTPs for authentication';

-- Stores push notification subscriptions
CREATE TABLE push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_asin VARCHAR(20),
    subscription JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_product_asin (product_asin)
) COMMENT 'Stores push notification subscriptions';

-- Stores VAPID keys for push notifications
CREATE TABLE vapid_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_key TEXT NOT NULL,
    private_key TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at)
) COMMENT 'Stores VAPID keys for push notifications';