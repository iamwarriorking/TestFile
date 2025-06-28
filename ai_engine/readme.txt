AI Engine for AmezPrice

Purpose: Handles price prediction, user behavior analysis, personalized deal recommendations, and interaction tracking using Rubix ML.

Components:
- models/: Stores serialized ML models (e.g., price_model_v1.ser) and parameter files (e.g., params_v1.json).
- data/: Contains static data like festivals.json for festival and sale events.
- scripts/: Core logic for prediction (predict.php), behavior analysis (behavior.php), deal suggestions (deals.php, telegram_deals.php, mail_deals.php), pattern detection (patterns.php), festival fetching (fetch_festivals.php, update_festivals.php), and model retraining (retrain.php).
- logs/: Stores logs for accuracy (accuracy.log), patterns (patterns.log), festivals (festivals.log), training (training.log), and restrictions (restrictions.log).
- config/: Configuration files for safety (safety.php) and AI settings (ai_config.php).

Setup:
1. Install Rubix ML via Composer: `composer require rubix/ml`.
2. Ensure cron jobs are set for daily training (ai_train.php), behavior analysis (ai_behavior.php), and annual festival fetching (fetch_festivals.php).
3. Configure Google Calendar API key in config/google.php.
4. Secure ai_engine/ directory with .htaccess (Deny from all).

Security:
- Access restricted to ai_engine/, database/, telegram_bot/, and config/google.php via safety.php.
- Unauthorized access attempts logged in restrictions.log.