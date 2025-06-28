Cron Jobs

Price Update: Runs daily to update product prices via APIs
Command: 0 0 * * * php /home/u123456789/domains/amezprice.com/public_html/cron/update_prices.php

Cleanup: Runs weekly to remove products out of stock for 6 months or untracked for 12 months
Command: 0 0 * * 0 php /home/u123456789/domains/amezprice.com/public_html/cron/cleanup.php

History Cleanup: Runs monthly to archive price history and delete data older than 24 months
Command: 0 0 1 * * php /home/u123456789/domains/amezprice.com/public_html/cron/history_cleanup.php

Goldbox Update: Runs daily to fetch Amazon Goldbox deals
Command: 0 0 * * * php /home/u123456789/domains/amezprice.com/public_html/cron/update_goldbox.php

Flipbox Update: Runs daily to fetch Flipkart deals
Command: 0 0 * * * php /home/u123456789/domains/amezprice.com/public_html/cron/update_flipbox.php

Hotdeals Cleanup: Runs daily to remove untracked products from hotdeals
Command: 0 3 * * * php /home/u123456789/domains/amezprice.com/public_html/cron/cleanup_hotdeals.php

AI Training: Runs daily to retrain AI models
Command: 0 2 * * * php /home/u123456789/domains/amezprice.com/public_html/cron/ai_train.php

AI Behavior Analysis: Runs daily to analyze user behavior
Command: 0 4 * * * php /home/u123456789/domains/amezprice.com/public_html/cron/ai_behavior.php

Fetch Festivals: Runs annually to fetch festival data
Command: 0 0 1 1 * php /home/u123456789/domains/amezprice.com/public_html/cron/fetch_festivals.php

Image Cleanup: Runs daily to delete temp images after 48 hours
Command: 0 0 * * * php /home/u123456789/domains/amezprice.com/public_html/cron/cleanup_images.php

Logs Cleanup: Runs monthly to delete logs older than 3 months
Command: 0 0 1 * * php /home/u123456789/domains/amezprice.com/public_html/cron/cleanup_logs.php

VAPID Update: Runs monthly to regenerate VAPID keys
Command: 0 0 1 * * php /home/u123456789/domains/amezprice.com/public_html/cron/update_vapid.php

OTP Cleanup: Run daily to cleanup expired OTP from database.
0 0 * * * php /path/to/your/project/cron/cleanup_otps.php