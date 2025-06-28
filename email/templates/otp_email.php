<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Code</title>
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
                                        <span style="color: #673de6; font-weight: bold; font-size: 14px;">Three. Two. Online</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 0 40px;">
                            <h1 style="font-size: 24px; color: #2F1C6A; margin: 30px 0 15px;">2FA Code</h1>
                            <p style="font-size: 16px; color: #333; margin: 0;">Here is your login verification code:</p>
                            <table bgcolor="#dfd9f9" width="100%" style="margin: 20px 0; padding: 15px; text-align: center; border-radius: 4px;" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="color: #673de6; font-weight: bold; font-size: 20px;">{{otp}}</td>
                                </tr>
                            </table>
                            <p style="font-size: 14px; color: #333; margin: 10px 0;">Please make sure you never share this code with anyone.</p>
                            <p style="font-size: 14px; color: #333; margin: 10px 0;"><strong>Note:</strong> The code will expire in 5 minutes.</p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 40px 40px 0;">
                            <img src="https://amezprice.com/assets/images/logos/mail-logo.png" alt="AmezPrice Logo" style="height: 32px; display: block; margin-bottom: 10px;" />
                            <p style="font-size: 13px; color: #555; margin: 10px 0;">
                                You have received this email because you are registered at AmezPrice, to ensure the implementation of our Terms of Service and (or) for other legitimate matters.
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