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
    <title>Privacy Policy - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <div class="card legal-page">
            <h1>Privacy Policy</h1>
            <p class="last-updated">Last Updated: May 20, 2025</p>
            
            <div class="toc">
                <h2>Table of Contents</h2>
                <ul>
                    <li><a href="#introduction">1. Introduction</a></li>
                    <li><a href="#info-collected">2. Information We Collect</a></li>
                    <li><a href="#info-use">3. How We Use Your Information</a></li>
                    <li><a href="#info-sharing">4. Sharing Your Information</a></li>
                    <li><a href="#notifications">5. Notifications and Communications</a></li>
                    <li><a href="#data-storage">6. Data Storage and Security</a></li>
                    <li><a href="#third-party">7. Third-Party Services</a></li>
                    <li><a href="#user-rights">8. Your Rights</a></li>
                    <li><a href="#data-retention">9. Data Retention</a></li>
                    <li><a href="#children">10. Children’s Privacy</a></li>
                    <li><a href="#changes">11. Changes to Privacy Policy</a></li>
                    <li><a href="#contact">12. Contact Us</a></li>
                </ul>
            </div>

            <section id="introduction">
                <h2>1. Introduction</h2>
                <p>AmezPrice ("we," "us," or "our") is committed to protecting your privacy. This Privacy Policy explains how we collect, use, share, and protect your personal information when you use our website (https://amezprice.com), mobile applications, and related services (collectively, the "Services") in India. We comply with the Information Technology Act, 2000, the Digital Personal Data Protection Act, 2023 (DPDP Act), and other applicable Indian laws.</p>
            </section>

            <section id="info-collected">
                <h2>2. Information We Collect</h2>
                <p>We collect the following types of information:</p>
                <p>
                    <strong>2.1 Personal Information:</strong>
                    <ul>
                        <li><strong>Account Information:</strong> When you create an account, we collect your first name, last name, username, email address, and password.</li>
                        <li><strong>Contact Information:</strong> If you contact us, we may collect your email address or other contact details provided.</li>
                        <li><strong>Telegram ID:</strong> If you use our Telegram bot, we collect your Telegram ID and username to send notifications.</li>
                    </ul>
                </p>
                <p>
                    <strong>2.2 Non-Personal Information:</strong>
                    <ul>
                        <li><strong>Usage Data:</strong> We collect information about your interactions with our Services, such as pages visited, products tracked, and clicks on affiliate links.</li>
                        <li><strong>Device Information:</strong> We may collect device details like IP address, browser type, operating system, and device identifiers.</li>
                        <li><strong>Price Tracking Data:</strong> We store product IDs (e.g., ASINs or PIDs) and price histories for products you track from Amazon India and Flipkart.</li>
                    </ul>
                </p>
            </section>

            <section id="info-use">
                <h2>3. How We Use Your Information</h2>
                <p>We use your information to:</p>
                <ul>
                    <li>Provide and personalize our Services, such as tracking product prices and sending notifications.</li>
                    <li>Send OTPs for account actions (e.g., login, deletion) and price tracking alerts via email, web push, or Telegram.</li>
                    <li>Send promotional emails or Telegram messages about deals, which you can opt out of (see Section 5).</li>
                    <li>Analyze usage patterns to improve our Services and user experience.</li>
                    <li>Comply with legal obligations under Indian laws, such as the DPDP Act, 2023.</li>
                </ul>
            </section>

            <section id="info-sharing">
                <h2>4. Sharing Your Information</h2>
                <p>We do not sell or rent your personal information. We may share your information in the following cases:</p>
                <ul>
                    <li><strong>Service Providers:</strong> With trusted third-party providers (e.g., email or push notification services) who assist in delivering our Services, bound by confidentiality agreements.</li>
                    <li><strong>Third-Party Platforms:</strong> When you click affiliate links, Amazon India and Flipkart may collect information as per their privacy policies.</li>
                    <li><strong>Legal Compliance:</strong> To comply with Indian laws, court orders, or government requests, such as under the Information Technology Act, 2000.</li>
                </ul>
            </section>

            <section id="notifications">
                <h2>5. Notifications and Communications</h2>
                <p>
                    <strong>5.1 Price Tracking Notifications:</strong> If you track a product, you will receive price change, stock, or low stock alerts via email, web push, or Telegram. These notifications cannot be unsubscribed from while tracking is active. To stop them, remove the product from your tracking list.
                </p>
                <p>
                    <strong>5.2 OTP Emails:</strong> OTP emails for account actions (e.g., login, deletion) are mandatory and cannot be unsubscribed from, as they are essential for security.
                </p>
                <p>
                    <strong>5.3 Promotional Communications:</strong> You may receive promotional emails or Telegram messages about deals. You can opt out of promotional emails via account settings or the unsubscribe link in emails. For Telegram, manage settings through the AmezPrice bot.
                </p>
            </section>

            <section id="data-storage">
                <h2>6. Data Storage and Security</h2>
                <p>
                    <strong>6.1 Storage:</strong> Your data is stored on secure servers in India, in compliance with the DPDP Act, 2023. Price data is retained for up to 24 months for analysis and prediction purposes.
                </p>
                <p>
                    <strong>6.2 Security:</strong> We use industry-standard security measures, such as encryption and secure sockets layer (SSL), to protect your data. However, no system is completely secure, and we cannot guarantee absolute security.
                </p>
            </section>

            <section id="third-party">
                <h2>7. Third-Party Services</h2>
                <p>
                    <strong>7.1 Amazon and Flipkart:</strong> We use Amazon India and Flipkart logos and product images as part of their affiliate programs. These are their property, and their privacy policies apply when you visit their sites via our affiliate links.
                </p>
                <p>
                    <strong>7.2 Telegram:</strong> Our Telegram bot uses Telegram’s API to send notifications. Telegram’s privacy policy governs the data you share with them.
                </p>
            </section>

            <section id="user-rights">
                <h2>8. Your Rights</h2>
                <p>Under the DPDP Act, 2023, you have the following rights:</p>
                <ul>
                    <li><strong>Access:</strong> Request a copy of your personal data.</li>
                    <li><strong>Correction:</strong> Request correction of inaccurate data.</li>
                    <li><strong>Deletion:</strong> Delete your account via account settings or by emailing support@amezprice.com from your registered email.</li>
                    <li><strong>Objection:</strong> Opt out of promotional communications (see Section 5).</li>
                </ul>
                <p>To exercise these rights, contact us at support@amezprice.com. We will respond within 30 days, as required by law.</p>
            </section>

            <section id="data-retention">
                <h2>9. Data Retention</h2>
                <p>We retain personal data only as long as necessary to provide our Services or comply with legal obligations. Account data is deleted within 7 business days of a deletion request. Price tracking data is retained for up to 24 months. Non-personal usage data may be retained indefinitely for analytics.</p>
            </section>

            <section id="children">
                <h2>10. Children’s Privacy</h2>
                <p>Our Services are not intended for users under 18 years old. We do not knowingly collect personal information from children. If we learn that a child’s data has been collected, we will delete it immediately.</p>
            </section>

            <section id="changes">
                <h2>11. Changes to Privacy Policy</h2>
                <p>We may update this Privacy Policy to reflect changes in our practices or legal requirements. We will notify you of significant changes via email or a website notice. Your continued use of the Services constitutes acceptance of the updated policy.</p>
            </section>

            <section id="contact">
                <h2>12. Contact Us</h2>
                <p>For questions or concerns about this Privacy Policy, contact us at:</p>
                <p>Email: <a href="mailto:support@amezprice.com">support@amezprice.com</a></p>
            </section>
        </div>
    </main>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/main.js"></script>
</body>
</html>