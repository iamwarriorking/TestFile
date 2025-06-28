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
    <title>Terms & Conditions - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <div class="card legal-page">
            <h1>Terms & Conditions</h1>
            <p class="last-updated">Last Updated: May 20, 2025</p>
            
            <div class="toc">
                <h2>Table of Contents</h2>
                <ul>
                    <li><a href="#introduction">1. Introduction</a></li>
                    <li><a href="#acceptance">2. Acceptance of Terms</a></li>
                    <li><a href="#eligibility">3. Eligibility</a></li>
                    <li><a href="#account">4. User Account</a></li>
                    <li><a href="#services">5. Services Provided</a></li>
                    <li><a href="#price-tracking">6. Price Tracking and Notifications</a></li>
                    <li><a href="#promotions">7. Promotional Communications</a></li>
                    <li><a href="#third-party">8. Third-Party Content</a></li>
                    <li><a href="#user-conduct">9. User Conduct</a></li>
                    <li><a href="#intellectual-property">10. Intellectual Property</a></li>
                    <li><a href="#termination">11. Termination</a></li>
                    <li><a href="#liability">12. Limitation of Liability</a></li>
                    <li><a href="#indemnity">13. Indemnity</a></li>
                    <li><a href="#governing-law">14. Governing Law</a></li>
                    <li><a href="#changes">15. Changes to Terms</a></li>
                    <li><a href="#contact">16. Contact Us</a></li>
                </ul>
            </div>

            <section id="introduction">
                <h2>1. Introduction</h2>
                <p>Welcome to AmezPrice, a price tracking and comparison platform operating exclusively in India. These Terms & Conditions ("Terms") govern your use of the AmezPrice website (https://amezprice.com), mobile applications, and related services (collectively, the "Services"). By accessing or using our Services, you agree to be bound by these Terms and all applicable laws in India. If you do not agree with these Terms, please do not use our Services.</p>
            </section>

            <section id="acceptance">
                <h2>2. Acceptance of Terms</h2>
                <p>By creating an account, browsing our website, or using any of our Services, you confirm that you have read, understood, and agree to comply with these Terms. These Terms apply to all users, including registered users, guests, and visitors. We reserve the right to update these Terms at any time, and continued use of the Services constitutes acceptance of the updated Terms.</p>
            </section>

            <section id="eligibility">
                <h2>3. Eligibility</h2>
                <p>You must be at least 18 years old and a resident of India to use our Services. By using AmezPrice, you represent and warrant that you meet these eligibility requirements and have the legal capacity to enter into a binding agreement under Indian law, including the Indian Contract Act, 1872.</p>
            </section>

            <section id="account">
                <h2>4. User Account</h2>
                <p>
                    <strong>4.1 Account Creation:</strong> To access certain features, such as price tracking and favorites, you may need to create an account. You agree to provide accurate, complete, and current information during registration and to update such information as needed.
                </p>
                <p>
                    <strong>4.2 Account Security:</strong> You are responsible for maintaining the confidentiality of your account credentials (username and password) and for all activities under your account. Notify us immediately at support@amezprice.com if you suspect unauthorized access.
                </p>
                <p>
                    <strong>4.3 Account Deletion:</strong> You may delete your account at any time through the account settings menu on our website. Alternatively, you can request account deletion by emailing support@amezprice.com from the email address associated with your account. Upon verification, we will delete your account and associated data within 7 business days, in compliance with the Information Technology Act, 2000, and related rules.
                </p>
            </section>

            <section id="services">
                <h2>5. Services Provided</h2>
                <p>AmezPrice provides a platform to track and compare prices of products listed on Amazon India and Flipkart. Our Services include:</p>
                <ul>
                    <li>Price tracking and historical price data for user-selected products.</li>
                    <li>Price predictions based on historical trends (for informational purposes only).</li>
                    <li>Notifications via email, web push, and Telegram for price changes, stock updates, and promotions.</li>
                    <li>Access to curated deals (Goldbox for Amazon, Flipbox for Flipkart).</li>
                </ul>
                <p>We do not sell products directly but provide affiliate links to Amazon India and Flipkart, earning a commission on qualifying purchases.</p>
            </section>

            <section id="price-tracking">
                <h2>6. Price Tracking and Notifications</h2>
                <p>
                    <strong>6.1 Price Storage:</strong> AmezPrice stores product prices from Amazon India and Flipkart to assist users in making informed purchase decisions. These prices are for user reference only and do not influence future pricing by Amazon or Flipkart.
                </p>
                <p>
                    <strong>6.2 Notifications:</strong> By enabling price tracking, you consent to receive notifications via email, web push, or Telegram about price changes, stock availability, or low stock alerts for tracked products. You cannot unsubscribe from these notifications while tracking is active, but you can stop tracking a product to cease receiving related alerts.
                </p>
            </section>

            <section id="promotions">
                <h2>7. Promotional Communications</h2>
                <p>
                    <strong>7.1 Opt-In/Opt-Out:</strong> AmezPrice may send promotional emails or Telegram messages about deals and offers. You can opt out of promotional emails through your account settings or by clicking the unsubscribe link in any promotional email. Promotional Telegram messages can be managed via the AmezPrice Telegram bot settings.
                </p>
                <p>
                    <strong>7.2 Mandatory Notifications:</strong> OTP emails (for account actions like login or deletion) and price tracking notifications cannot be unsubscribed from while the respective features are active, as they are essential to the Services.
                </p>
            </section>

            <section id="third-party">
                <h2>8. Third-Party Content</h2>
                <p>
                    <strong>8.1 Amazon and Flipkart:</strong> AmezPrice uses logos, product images, and data from Amazon India and Flipkart with permission as part of their affiliate programs. These logos and images are the property of Amazon Services LLC and Flipkart Internet Private Limited, respectively, and are used to enhance user experience. We do not claim any ownership or copyright over them.
                </p>
                <p>
                    <strong>8.2 Affiliate Links:</strong> Our Services include affiliate links to Amazon India and Flipkart. Clicking these links may redirect you to their websites, where their terms, privacy policies, and pricing apply. AmezPrice is not responsible for the content, products, or services provided by these third parties.
                </p>
            </section>

            <section id="user-conduct">
                <h2>9. User Conduct</h2>
                <p>You agree not to:</p>
                <ul>
                    <li>Use the Services for any unlawful purpose or in violation of Indian laws, including the Information Technology Act, 2000.</li>
                    <li>Attempt to access, modify, or disrupt our systems, databases, or networks.</li>
                    <li>Use automated tools (e.g., bots, scrapers) to extract data from our Services without permission.</li>
                    <li>Share your account credentials or allow others to use your account.</li>
                </ul>
                <p>Violation of these terms may result in account suspension or termination.</p>
            </section>

            <section id="intellectual-property">
                <h2>10. Intellectual Property</h2>
                <p>All content on AmezPrice, excluding third-party content (e.g., Amazon and Flipkart logos), including text, graphics, and software, is the property of AmezPrice or its licensors and is protected under the Copyright Act, 1957. You may not reproduce, distribute, or modify our content without prior written consent.</p>
            </section>

            <section id="termination">
                <h2>11. Termination</h2>
                <p>We reserve the right to suspend or terminate your access to the Services at our discretion, with or without notice, if you violate these Terms or engage in activities that harm AmezPrice or its users. You may terminate your account at any time as described in Section 4.3.</p>
            </section>

            <section id="liability">
                <h2>12. Limitation of Liability</h2>
                <p>To the fullest extent permitted by Indian law, AmezPrice shall not be liable for any indirect, incidental, or consequential damages arising from your use of the Services. Our liability is limited to the amount paid by you for the Services, if any. We do not guarantee the accuracy of price data or predictions, as they are for informational purposes only.</p>
            </section>

            <section id="indemnity">
                <h2>13. Indemnity</h2>
                <p>You agree to indemnify and hold AmezPrice, its affiliates, and employees harmless from any claims, losses, or damages arising from your violation of these Terms or misuse of the Services, in accordance with the Indian Contract Act, 1872.</p>
            </section>

            <section id="governing-law">
                <h2>14. Governing Law</h2>
                <p>These Terms are governed by the laws of India. Any disputes arising from these Terms or your use of the Services shall be subject to the exclusive jurisdiction of the courts in Chennai, Tamil Nadu, India.</p>
            </section>

            <section id="changes">
                <h2>15. Changes to Terms</h2>
                <p>We may update these Terms from time to time to reflect changes in our Services or legal requirements. We will notify users of significant changes via email or a website notice. Your continued use of the Services after such changes constitutes acceptance of the updated Terms.</p>
            </section>

            <section id="contact">
                <h2>16. Contact Us</h2>
                <p>If you have any questions about these Terms, please contact us at:</p>
                <p>Email: <a href="mailto:support@amezprice.com">support@amezprice.com</a></p>
            </section>
        </div>
    </main>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/main.js"></script>
</body>
</html>