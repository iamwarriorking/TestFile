<?php
require_once '../config/database.php';
require_once '../middleware/csrf.php';

startApplicationSession();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disclaimer - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <div class="card legal-page">
            <h1>Disclaimer</h1>
            <p class="last-updated">Last Updated: May 20, 2025</p>
            
            <div class="toc">
                <h2>Table of Contents</h2>
                <ul>
                    <li><a href="#introduction">1. Introduction</a></li>
                    <li><a href="#third-party">2. Third-Party Content</a></li>
                    <li><a href="#price-tracking">3. Price Tracking and Storage</a></li>
                    <li><a href="#price-predictions">4. Price Predictions</a></li>
                    <li><a href="#affiliate-links">5. Affiliate Links</a></li>
                    <li><a href="#notifications">6. Notifications</a></li>
                    <li><a href="#no-warranty">7. No Warranty</a></li>
                    <li><a href="#contact">8. Contact Us</a></li>
                </ul>
            </div>

            <section id="introduction">
                <h2>1. Introduction</h2>
                <p>AmezPrice provides price tracking and comparison services for products listed on Amazon India and Flipkart, exclusively for users in India. This Disclaimer outlines the limitations of our Services, third-party content usage, and our responsibilities. By using our website (https://amezprice.com), mobile applications, or related services (collectively, the "Services"), you acknowledge and accept the terms of this Disclaimer.</p>
            </section>

            <section id="third-party">
                <h2>2. Third-Party Content</h2>
                <p>
                    <strong>2.1 Amazon and Flipkart:</strong> AmezPrice uses logos, product images, and data from Amazon India and Flipkart as part of their respective affiliate programs. These materials are the intellectual property of Amazon Services LLC and Flipkart Internet Private Limited. AmezPrice does not claim any ownership, copyright, or proprietary rights over these logos, images, or product data. They are used solely to enhance user experience and facilitate price tracking.
                </p>
                <p>
                    <strong>2.2 Credits:</strong> We acknowledge and credit Amazon India and Flipkart for their logos and product images, which are used with permission under their affiliate program guidelines.
                </p>
            </section>

            <section id="price-tracking">
                <h2>3. Price Tracking and Storage</h2>
                <p>
                    <strong>3.1 Purpose:</strong> AmezPrice collects and stores product prices from Amazon India and Flipkart to provide users with historical price data and assist in making informed purchase decisions. These prices are for user reference only and do not represent official pricing by Amazon or Flipkart.
                </p>
                <p>
                    <strong>3.2 Disclaimer for Amazon and Flipkart:</strong> The stored prices are not intended to influence, predict, or control future pricing by Amazon India or Flipkart. AmezPrice complies with the terms of service of both platforms, and our price tracking is conducted solely to benefit users. If Amazon or Flipkart prohibits price storage, we will cease such activities for the respective platform and update this Disclaimer accordingly.
                </p>
                <p>
                    <strong>3.3 User Disclaimer:</strong> Historical prices displayed on AmezPrice are for informational purposes only and do not guarantee future prices. Prices on Amazon India and Flipkart are subject to change at their discretion, and AmezPrice is not responsible for discrepancies between our data and current prices on these platforms.
                </p>
            </section>

            <section id="price-predictions">
                <h2>4. Price Predictions</h2>
                <p>
                    <strong>4.1 Informational Purpose:</strong> AmezPrice provides price predictions based on historical price trends and machine learning algorithms. These predictions are for informational purposes only and do not guarantee future prices on Amazon India or Flipkart.
                </p>
                <p>
                    <strong>4.2 No Influence:</strong> Our price predictions do not influence or predict the actual future pricing decisions of Amazon India or Flipkart. They are independent estimates based on past data and market trends, and AmezPrice is not liable for any reliance on these predictions.
                </p>
                <p>
                    <strong>4.3 User Disclaimer:</strong> Users should not rely solely on our price predictions when making purchase decisions. Always verify current prices directly on Amazon India or Flipkart before purchasing.
                </p>
            </section>

            <section id="affiliate-links">
                <h2>5. Affiliate Links</h2>
                <p>AmezPrice includes affiliate links to Amazon India and Flipkart, through which we may earn a commission on qualifying purchases. These links redirect you to the respective platforms, where their terms, conditions, and pricing apply. AmezPrice is not responsible for the availability, pricing, or quality of products on these platforms.</p>
            </section>

            <section id="notifications">
                <h2>6. Notifications</h2>
                <p>AmezPrice sends notifications via email, web push, and Telegram to inform users about price changes, stock updates, or low stock alerts for tracked products. We may also send promotional messages about deals via email or Telegram. Users can opt out of promotional communications through account settings or the AmezPrice Telegram bot, but price tracking and OTP notifications are mandatory while those features are active.</p>
            </section>

            <section id="no-warranty">
                <h2>7. No Warranty</h2>
                <p>The Services are provided "as is" without any warranties, express or implied. AmezPrice does not warrant the accuracy, completeness, or reliability of price data, predictions, or third-party content. To the fullest extent permitted by Indian law, including the Consumer Protection Act, 2019, we disclaim liability for any damages arising from your use of the Services.</p>
            </section>

            <section id="contact">
                <h2>8. Contact Us</h2>
                <p>If you have questions about this Disclaimer, contact us at:</p>
                <p>Email: <a href="mailto:support@amezprice.com">support@amezprice.com</a></p>
            </section>
        </div>
    </main>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/main.js"></script>
</body>
</html>