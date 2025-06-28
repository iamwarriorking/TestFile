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
    <title>About Us - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <div class="card about-page">
            <h1>About Us</h1>
            <p class="last-updated">Last Updated: May 20, 2025</p>
            
            <div class="toc">
                <h2>Table of Contents</h2>
                <ul>
                    <li><a href="#who-we-are">1. Who We Are</a></li>
                    <li><a href="#mission">2. Our Mission</a></li>
                    <li><a href="#services">3. Our Services</a></li>
                    <li><a href="#third-party">4. Third-Party Partnerships</a></li>
                    <li><a href="#why-choose">5. Why Choose AmezPrice</a></li>
                    <li><a href="#contact">6. Contact Us</a></li>
                </ul>
            </div>

            <section id="who-we-are">
                <h2>1. Who We Are</h2>
                <p>AmezPrice is a leading price tracking and comparison platform designed exclusively for Indian consumers. We help shoppers make informed purchase decisions by tracking product prices on Amazon India and Flipkart. Our user-friendly website and Telegram bot empower users to monitor price trends, receive notifications, and discover the best deals.</p>
            </section>

            <section id="mission">
                <h2>2. Our Mission</h2>
                <p>Our mission is to simplify online shopping for Indian consumers by providing accurate, transparent, and timely price information. We aim to save you time and money by helping you buy products at the right price, backed by advanced technology and a commitment to user satisfaction.</p>
            </section>

            <section id="services">
                <h2>3. Our Services</h2>
                <p>AmezPrice offers a range of services tailored to Indian shoppers:</p>
                <ul>
                    <li><strong>Price Tracking:</strong> Monitor product prices on Amazon India and Flipkart, with historical data to identify trends.</li>
                    <li><strong>Price Predictions:</strong> Receive AI-powered price predictions to plan your purchases (for informational purposes only).</li>
                    <li><strong>Notifications:</strong> Get alerts via email, web push, or Telegram for price drops, stock updates, or low stock warnings.</li>
                    <li><strong>Curated Deals:</strong> Access exclusive deals through our Goldbox (Amazon) and Flipbox (Flipkart) sections.</li>
                    <li><strong>User Accounts:</strong> Create an account to save favorite products, track prices, and manage notification preferences.</li>
                </ul>
            </section>

            <section id="third-party">
                <h2>4. Third-Party Partnerships</h2>
                <p>
                    <strong>4.1 Amazon and Flipkart:</strong> We proudly partner with Amazon India and Flipkart through their affiliate programs, using their logos and product images to enhance your shopping experience. These materials are the property of Amazon Services LLC and Flipkart Internet Private Limited, and we credit them for their contributions to our platform.
                </p>
                <p>
                    <strong>4.2 Telegram:</strong> Our Telegram bot delivers real-time price alerts and promotional messages, powered by Telegram’s secure API.
                </p>
            </section>

            <section id="why-choose">
                <h2>5. Why Choose AmezPrice</h2>
                <p>
                    <strong>5.1 India-Focused:</strong> Designed specifically for Indian consumers, AmezPrice complies with Indian laws, including the Information Technology Act, 2000, and the Digital Personal Data Protection Act, 2023.
                </p>
                <p>
                    <strong>5.2 User Control:</strong> Easily manage your account, delete it anytime via settings or by contacting support@amezprice.com, and opt out of promotional communications.
                </p>
                <p>
                    <strong>5.3 Transparency:</strong> We provide clear historical price data and predictions without influencing third-party pricing, ensuring you have the information you need to shop smart.
                </p>
                <p>
                    <strong>5.4 Reliable Support:</strong> Our team is available to assist you via email at support@amezprice.com, ensuring a seamless experience.
                </p>
            </section>

            <section id="contact">
                <h2>6. Contact Us</h2>
                <p>We’re here to help! Reach out with any questions or feedback:</p>
                <p>Email: <a href="mailto:support@amezprice.com">support@amezprice.com</a></p>
            </section>
        </div>
    </main>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/main.js"></script>
</body>
</html>