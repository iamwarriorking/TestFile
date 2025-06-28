<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Alert</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif;">
    <table bgcolor="#f4f5ff" width="100%" border="0" cellspacing="0" cellpadding="0">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table bgcolor="#ffffff" width="100%" style="max-width: 640px; padding: 40px; border: 1px solid #e0e0e0;" border="0" cellspacing="0" cellpadding="0">
                    <!-- Header -->
                    <tr>
                        <td align="left" style="padding-bottom: 20px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left">
                                        <img src="https://amezprice.com/assets/images/logos/mail-logo.png" alt="AmezPrice Logo" style="height: 32px; display: block;" />
                                    </td>
                                    <td align="right">
                                        <?php if ($stock_type === 'low_stock'): ?>
                                            <span style="color: #673de6; font-weight: bold; font-size: 14px;">Hurry! Limited Stock</span>
                                        <?php elseif ($stock_type === 'out_of_stock'): ?>
                                            <span style="color: #673de6; font-weight: bold; font-size: 14px;">Track. Wait. Win.</span>
                                        <?php elseif ($stock_type === 'in_stock'): ?>
                                            <span style="color: #673de6; font-weight: bold; font-size: 14px;">Back in Action!</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 0 40px;">
                            <!-- Dynamic Alert Header based on stock_type -->
                            <?php if ($stock_type === 'low_stock'): ?>
                                <h1 style="font-size: 24px; color: #FF6B35; margin: 30px 0 15px;">⚠️ Low Stock Alert!</h1>
                                <p style="font-size: 18px; color: #FF6B35; margin: 10px 0 20px; font-weight: bold;">Only {{quantity}} left in stock!</p>
                            <?php elseif ($stock_type === 'out_of_stock'): ?>
                                <h1 style="font-size: 24px; color: #DC2626; margin: 30px 0 15px;">😔 Out of Stock Alert!</h1>
                                <p style="font-size: 18px; color: #DC2626; margin: 10px 0 20px; font-weight: bold;">This product is currently out of stock</p>
                            <?php elseif ($stock_type === 'in_stock'): ?>
                                <h1 style="font-size: 24px; color: #16A34A; margin: 30px 0 15px;">🎉 Back in Stock!</h1>
                                <p style="font-size: 18px; color: #16A34A; margin: 10px 0 20px; font-weight: bold;">Great news! This product is back in stock</p>
                            <?php endif; ?>
                            <img src="{{image_path}}" alt="{{name}}" style="max-width: 100%; height: auto; display: block; margin: 0 auto 15px;" />
                            <p style="font-size: 16px; color: #333; margin: 0; font-weight: bold;">{{name}}</p>
                            <!-- Price Display -->
                            <?php if ($stock_type !== 'out_of_stock'): ?>
                                <p style="font-size: 20px; color: #673de6; margin: 15px 0; font-weight: bold;">Current Price: ₹{{current_price}}</p>
                            <?php else: ?>
                                <p style="font-size: 20px; color: #673de6; margin: 15px 0; font-weight: bold;">Last Price: ₹{{last_price}}</p>
                            <?php endif; ?>
                            <!-- Price History -->
                            <table style="background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;" width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td>
                                        <p style="font-size: 14px; color: #666; margin: 5px 0;">💰 Lowest Ever: ₹{{lowest_price}}</p>
                                        <p style="font-size: 14px; color: #666; margin: 5px 0;">📈 Highest Price: ₹{{highest_price}}</p>
                                        <p style="font-size: 14px; color: #666; margin: 5px 0;">🔥 {{tracker_count}} users are tracking this!</p>
                                        <p style="font-size: 14px; color: #666; margin: 5px 0;">⌚ Updated: {{last_updated}}</p>
                                    </td>
                                </tr>
                            </table>
                            <!-- Action Buttons -->
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin: 20px 0;">
                                <tr>
                                    <?php if ($stock_type !== 'out_of_stock'): ?>
                                        <td width="50%" style="padding-right: 10px;">
                                            <a href="{{affiliate_link}}" style="display: block; background-color: #673de6; color: #ffffff; font-size: 16px; padding: 12px 20px; text-decoration: none; border-radius: 6px; text-align: center; font-weight: bold;">Buy Now</a>
                                        </td>
                                        <td width="50%" style="padding-left: 10px;">
                                            <a href="{{history_url}}" style="display: block; background-color: #673de6; color: #ffffff; font-size: 16px; padding: 12px 20px; text-decoration: none; border-radius: 6px; text-align: center; font-weight: bold;">Price History</a>
                                        </td>
                                    <?php else: ?>
                                        <td width="50%" style="padding-right: 10px;">
                                            <a href="{{unsubscribe_url}}" style="display: block; background-color: #EF4444; color: #ffffff; font-size: 16px; padding: 12px 20px; text-decoration: none; border-radius: 6px; text-align: center; font-weight: bold;">Stop Tracking</a>
                                        </td>
                                        <td width="50%" style="padding-left: 10px;">
                                            <a href="{{history_url}}" style="display: block; background-color: #673de6; color: #ffffff; font-size: 16px; padding: 12px 20px; text-decoration: none; border-radius: 6px; text-align: center; font-weight: bold;">Price History</a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 40px 40px 0;">
                            <img src="https://amezprice.com/assets/images/logos/mail-logo.png" alt="AmezPrice Logo" style="height: 32px; display: block; margin-bottom: 10px;" />
                            <p style="font-size: 13px; color: #555; margin: 10px 0;">
                                You're getting this email because you're tracking {{name}} on AmezPrice! Here's the latest stock update.
                            </p>
                            <p style="margin: 10px 0;">
                                <a href="https://amezprice.com/pages/privacy-policy.php" style="font-size: 13px; color: #673de6; text-decoration: underline;">Privacy Policy</a>
                            </p>
                            <p style="font-size: 13px; color: #555; margin: 5px 0;">© <?php echo date('Y'); ?> AmezPrice. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>