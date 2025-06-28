<?php
require_once '../config/database.php';
require_once '../config/mail.php';
require_once '../email/send.php';
require_once '../middleware/csrf.php';

startApplicationSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json'); // Set JSON content type

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input');
        }

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $subject = trim($input['subject'] ?? '');
        $message = trim($input['message'] ?? '');

        if (!$name || !$email || !$subject || !$message) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
            exit;
        }

        // Prepare email content
        $emailBody = "Name: $name\nEmail: $email\nMessage: $message";

        // Send email from no-reply to support
        $success = sendEmail(
            $mailConfig['support']['email'], // Recipient (support email)
            $subject,                        // User-provided subject
            nl2br($emailBody),               // Formatted email body
            'otp'                            // Use 'otp' config for no-reply
        );

        if ($success) {
            // Log contact request (without storing sensitive data)
            file_put_contents('../logs/contact.log', "[" . date('Y-m-d H:i:s') . "] Contact form submitted by $email\n", FILE_APPEND);
            echo json_encode(['status' => 'success', 'message' => 'Your message has been sent. We will respond within 48 hours.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to send your message. Please try again later.']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../include/header.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - AmezPrice</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
    <?php include '../include/navbar.php'; ?>
    <main class="container">
        <div class="card contact-page">
            <h1>Contact Us</h1>
            <p class="last-updated">Last Updated: May 20, 2025</p>
            
            <div class="toc">
                <h2>Table of Contents</h2>
                <ul>
                    <li><a href="#get-in-touch">1. Get in Touch</a></li>
                    <li><a href="#contact-form">2. Contact Form</a></li>
                    <li><a href="#support">3. Support Options</a></li>
                    <li><a href="#social">4. Connect on Social Media</a></li>
                </ul>
            </div>

            <section id="get-in-touch">
                <h2>1. Get in Touch</h2>
                <p>At AmezPrice, we value your feedback and are here to assist with any questions or concerns about our price tracking and comparison services. Whether you need help with your account, have inquiries about our Services, or want to request account deletion, our support team is ready to help.</p>
            </section>

            <section id="contact-form">
                <h2>2. Contact Form</h2>
                <p>Use the form below to send us a message. We typically respond within 48 hours.</p>
                <form id="contact-form" aria-label="Contact Form">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" placeholder="Your Name" required aria-required="true">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="Your Email" required aria-required="true">
                    <label for="subject">Subject</label>
                    <input type="text" id="subject" name="subject" placeholder="Subject" required aria-required="true">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" placeholder="Your Message" required aria-required="true"></textarea>
                    <button type="submit" class="btn btn-primary">Send Message</button>
                </form>
            </section>

            <section id="support">
                <h2>3. Support Options</h2>
                <p>
                    <strong>Email Support:</strong> Reach us at <a href="mailto:support@amezprice.com">support@amezprice.com</a> for general inquiries, technical support, or account deletion requests. For account deletion, please email from the registered email address associated with your AmezPrice account.
                </p>
                <p>
                    <strong>Account Deletion:</strong> You can delete your account directly through the account settings menu on our website. Alternatively, send a deletion request to support@amezprice.com, and we will process it within 7 business days after verification.
                </p>
            </section>

            <section id="social">
                <h2>4. Connect on Social Media</h2>
                <p>Stay updated with the latest deals and news by following us on social media:</p>
                <ul>
                    <li><a href="<?php echo htmlspecialchars($social['instagram']); ?>" target="_blank">Instagram</a></li>
                    <li><a href="<?php echo htmlspecialchars($social['twitter']); ?>" target="_blank">Twitter</a></li>
                    <li><a href="<?php echo htmlspecialchars($social['telegram']); ?>" target="_blank">Telegram</a></li>
                    <li><a href="<?php echo htmlspecialchars($social['facebook']); ?>" target="_blank">Facebook</a></li>
                </ul>
                <p>Join our Telegram channels for real-time deal alerts and updates:</p>
                <ul>
                    <li><a href="https://t.me/AmezPrice">AmezPrice</a></li>
                    <li><a href="https://t.me/AmezPriceHotDeals">HotDeals</a></li>
                </ul>
            </section>
        </div>
    </main>
    <?php include '../include/footer.php'; ?>
    <div id="success-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('success-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div id="error-popup" class="popup" style="display: none;">
        <i class="fas fa-times popup-close" onclick="hidePopup('error-popup')"></i>
        <div class="popup-content"></div>
    </div>
    <div class="popup-overlay" style="display: none;"></div>
    <script src="/assets/js/main.js"></script>
</body>
</html>