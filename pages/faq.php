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
    <title>FAQs - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <div class="card faq-page">
            <h1>Frequently Asked Questions</h1>
            <p class="last-updated">Last Updated: May 20, 2025</p>

            <div class="faq-list">
                <div class="faq-item">
                    <h3 class="faq-question">What is AmezPrice?</h3>
                    <div class="faq-answer">
                        <p>AmezPrice is a price tracking and comparison platform designed for Indian consumers. We help you monitor product prices on Amazon India and Flipkart, receive deal alerts, and make informed purchase decisions through our website and Telegram bot.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">Is AmezPrice available outside India?</h3>
                    <div class="faq-answer">
                        <p>No, AmezPrice is exclusively for users in India. Our services are tailored to comply with Indian laws, such as the Information Technology Act, 2000, and the Digital Personal Data Protection Act, 2023.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">How do I create an account?</h3>
                    <div class="faq-answer">
                        <p>Go to the "Sign Up" page on our website, provide your name, username, email, and password, and verify your email with an OTP. If you don’t enter the OTP within the 5-minute time limit, your account won’t be created, and your details won’t be saved in our database. You’ll need to start the signup process again.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">Can I delete my account?</h3>
                    <div class="faq-answer">
                        <p>Yes, you can delete your account through the account settings menu on our website. Alternatively, email support@amezprice.com from your registered email, and we’ll process the deletion within 7 business days after verification. Upon deletion, all your personal data, including tracked products and notification preferences, will be permanently removed from our database.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">How does price tracking work on Telegram?</h3>
                    <div class="faq-answer">
                        <p>With our Telegram bot (@AmezPriceBot), simply send the product link from Amazon India or Flipkart. Tracking starts immediately, and you’ll receive price change, stock, or low stock alerts. You can track up to 50 products, with a limit of 5 new products per hour to prevent misuse.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">How does price tracking work on the website?</h3>
                    <div class="faq-answer">
                        <p>To track a product on our website, search for the product using its Amazon India or Flipkart link. This takes you to the product’s history page, where a heart button lets you add it to your favorites. Go to your favorites list to view all favorite products, and for each product, toggle email or push notifications on or off to start tracking. You can add up to 200 products to your favorites for tracking, with no hourly limit.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">How many products can I track?</h3>
                    <div class="faq-answer">
                        <p>On Telegram, you can track up to 50 products, with a limit of 5 new products per hour. On the website, you can add and track up to 200 products in your favorites list, with no hourly limit. These limits are in place to prevent misuse of our services.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">Are your price predictions accurate?</h3>
                    <div class="faq-answer">
                        <p>Our price predictions use historical data and AI algorithms but are for informational purposes only. They don’t guarantee future prices, as Amazon India and Flipkart control their pricing. Always check current prices on their websites before purchasing.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">Why do you store prices?</h3>
                    <div class="faq-answer">
                        <p>We store prices from Amazon India and Flipkart to show historical trends, helping you decide the best time to buy. These prices are for your reference only and don’t influence future pricing by Amazon or Flipkart.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">Can I stop receiving notifications?</h3>
                    <div class="faq-answer">
                        <p>
                            <strong>Product Tracking Notifications:</strong>
                            <ul>
                                <li><strong>Telegram:</strong> Stop tracking a product by removing it from your tracking list using the /stop command in the AmezPrice bot.</li>
                                <li><strong>Website:</strong> Go to your favorites list and toggle off email and/or push notifications for specific products.</li>
                            </ul>
                            <strong>Promotional Notifications:</strong> Opt out of promotional emails via account settings or the unsubscribe link in emails. For Telegram, manage promotional messages through the AmezPrice bot settings. OTP emails for account actions are mandatory and cannot be unsubscribed while using those features.
                        </p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">Why do you use Amazon and Flipkart logos?</h3>
                    <div class="faq-answer">
                        <p>We use Amazon India and Flipkart logos and product images as part of their affiliate programs to improve your experience. These are the property of Amazon Services LLC and Flipkart Internet Private Limited, and we credit them accordingly.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">What happens when I click an affiliate link?</h3>
                    <div class="faq-answer">
                        <p>You’ll be redirected to Amazon India or Flipkart, where their terms, pricing, and policies apply. We may earn a commission on qualifying purchases at no extra cost to you.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">How can I contact support?</h3>
                    <div class="faq-answer">
                        <p>Email us at support@amezprice.com or use the contact form on our Contact Us page. We respond within 48 hours. For account deletion, email from your registered email address.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <h3 class="faq-question">I’m not receiving notifications. What should I do?</h3>
                    <div class="faq-answer">
                        <p>Check your account settings to ensure notifications are enabled. For emails, look in your spam/junk folder. On Telegram, ensure you’ve started the @AmezPriceBot. If issues persist, contact support@amezprice.com.</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <?php include '../include/footer.php'; ?>
    <script src="/assets/js/main.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const questions = document.querySelectorAll('.faq-question');

            questions.forEach(question => {
                question.addEventListener('click', () => {
                    const answer = question.nextElementSibling;
                    const isOpen = answer.classList.contains('open');

                    // Close all other open answers
                    document.querySelectorAll('.faq-answer.open').forEach(openAnswer => {
                        openAnswer.classList.remove('open');
                        openAnswer.style.maxHeight = null;
                    });

                    // Toggle the clicked answer
                    if (!isOpen) {
                        answer.classList.add('open');
                        answer.style.maxHeight = answer.scrollHeight + 'px';
                    }
                });
            });
        });
    </script>
</body>
</html>