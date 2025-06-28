<?php
require_once __DIR__ . '/../config/telegram.php';
require_once __DIR__ . '/../middleware/csrf.php';
$social = require_once __DIR__ . '/../config/social.php';
?>
<footer class="footer">
    <div class="footer-content">
        <div class="footer-section footer-left">
            <h3>Explore</h3>
            <div class="footer-links">
                <a href="/pages/privacy-policy.php" aria-label="Privacy Policy">Privacy Policy</a>
                <a href="/pages/terms-conditions.php" aria-label="Terms & Conditions">Terms & Conditions</a>
                <a href="/pages/disclaimer.php" aria-label="Disclaimer">Disclaimer</a>
                <a href="/pages/about-us.php" aria-label="About Us">About Us</a>
                <a href="/pages/contact-us.php" aria-label="Contact Us">Contact Us</a>
                <a href="/pages/faq.php" aria-label="Contact Us">FAQ</a>
            </div>
        </div>
        <div class="footer-section footer-center">
            <h3>Follow Us</h3>
            <div class="footer-social">
                <?php if (!empty($social['instagram'])): ?>
                    <a href="<?php echo htmlspecialchars($social['instagram']); ?>" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i> Instagram</a>
                <?php endif; ?>
                <?php if (!empty($social['twitter'])): ?>
                    <a href="<?php echo htmlspecialchars($social['twitter']); ?>" target="_blank" aria-label="Twitter"><i class="fab fa-twitter"></i> Twitter</a>
                <?php endif; ?>
                <?php if (!empty($social['facebook'])): ?>
                    <a href="<?php echo htmlspecialchars($social['facebook']); ?>" target="_blank" aria-label="Facebook"><i class="fab fa-facebook"></i> Facebook</a>
                <?php endif; ?>
                <?php if (!empty($social['telegram'])): ?>
                    <a href="<?php echo htmlspecialchars($social['telegram']); ?>" target="_blank" aria-label="Telegram"><i class="fab fa-telegram"></i> Telegram</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="footer-section footer-right">
            <h3>Telegram Bots</h3>
            <div class="footer-bots">
                <a href="https://t.me/AmezPriceBot" class="btn bot-btn" target="_blank" aria-label="AmezPrice Bot"><i class="fas fa-robot"></i> AmezPrice</a>
                <a href="https://t.me/AmezPriceHotDealsBot" class="btn bot-btn" target="_blank" aria-label="HotDeals Bot"><i class="fas fa-fire"></i> Hot Deals</a>
            </div>
            <h3>Telegram Channels</h3>
            <div class="footer-channels">
                <a href="<?php echo htmlspecialchars($telegramConfig['channels']['amezprice']); ?>" class="btn channel-btn" target="_blank" aria-label="AmezPrice Channel"><i class="fab fa-telegram"></i> AmezPrice</a>
                <a href="<?php echo htmlspecialchars($telegramConfig['channels']['hotdeals']); ?>" class="btn channel-btn" target="_blank" aria-label="HotDeals Channel"><i class="fab fa-telegram"></i> Hot Deals</a>
            </div>
        </div>
    </div>
    <div class="footer-copyright">
        © <?php echo date('Y'); ?> AmezPrice. All rights reserved.
    </div>
</footer>