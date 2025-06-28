<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hot Deal Alert</title>
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
                                        <span style="color: #673de6; font-weight: bold; font-size: 14px;">Three. Two. Buy</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 0 40px;">
                            <h1 style="font-size: 24px; color: #2F1C6A; margin: 30px 0 15px;">🎉 Hot Deal Alert!</h1>
                            <img src="{{image_path}}" alt="{{name}}" style="max-width: 100%; height: auto; display: block; margin: 0 auto 15px;" />
                            <p style="font-size: 16px; color: #333; margin: 0; font-weight: bold;">{{name}}</p>
                            <p style="font-size: 16px; color: #333; margin: 10px 0;">Highest Price: ₹{{highest_price}}</p>
                            <p style="font-size: 16px; color: #333; margin: 10px 0;">Current Price: ₹{{current_price}} ({{discount}}% off)</p>
                            <p style="font-size: 16px; color: #333; margin: 10px 0;">🔥 {{tracker_count}} users are tracking this!</p>
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin: 20px 0;">
                                <tr>
                                    <td width="50%" style="padding-right: 10px;">
                                        <a href="{{affiliate_link}}" style="display: block; background-color: #673de6; color: #ffffff; font-size: 16px; padding: 10px 20px; text-decoration: none; border-radius: 4px; text-align: center; width: 100%; box-sizing: border-box;">Buy Now</a>
                                    </td>
                                    <td width="50%" style="padding-left: 10px;">
                                        <a href="{{history_url}}" style="display: block; background-color: #673de6; color: #ffffff; font-size: 16px; padding: 10px 20px; text-decoration: none; border-radius: 4px; text-align: center; width: 100%; box-sizing: border-box;">Price History</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 40px 40px 0;">
                            <img src="https://amezprice.com/assets/images/logos/mail-logo.png" alt="AmezPrice Logo" style="height: 32px; display: block; margin-bottom: 10px;" />
                            <p style="font-size: 13px; color: #555; margin: 10px 0;">
                                You're getting this email because we found something awesome for you on AmezPrice! Grab it now!
                            </p>
                            <p style="margin: 10px 0;">
                                <a href="https://amezprice.com/pages/privacy-policy.php" style="font-size: 13px; color: #673de6; text-decoration: underline;">Privacy Policy</a> | 
                                <a href="{{unsubscribe_url}}" style="font-size: 13px; color: #673de6; text-decoration: underline;">Unsubscribe</a>
                            </p>
                            <p style="font-size: 13px; color: #555; margin: 5px 0;">© 2025 AmezPrice. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>