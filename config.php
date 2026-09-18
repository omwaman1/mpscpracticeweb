<?php
// config.php - Direct Database & Practice Web Configuration
error_reporting(0);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Kolkata');

// Load local secrets if present (git-ignored)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'BANK');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
if (!defined('USE_API_BRIDGE')) define('USE_API_BRIDGE', false);

// Monetization & Subscription Settings
if (!defined('FREE_TRIAL_SECONDS')) define('FREE_TRIAL_SECONDS', 3600); // 1 hour (60 minutes) free practice trial
if (!defined('DAILY_PASS_PRICE')) define('DAILY_PASS_PRICE', 10.00); // ₹10 per day study pass
if (!defined('GOOGLE_CLIENT_ID')) define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
if (!defined('GOOGLE_CLIENT_SECRET')) define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: 'YOUR_GOOGLE_CLIENT_SECRET');
if (!defined('RAZORPAY_PAYMENT_URL')) define('RAZORPAY_PAYMENT_URL', 'https://rzp.io/rzp/FTFoJWx');
if (!defined('RAZORPAY_WEBHOOK_SECRET')) define('RAZORPAY_WEBHOOK_SECRET', getenv('RAZORPAY_WEBHOOK_SECRET') ?: 'mpsc_webhook_secret_2026');
if (!defined('ADMIN_SECRET_KEY')) define('ADMIN_SECRET_KEY', 'mpsc2026');

function getDBConnection() {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ];

    return new PDO($dsn, DB_USER, DB_PASS, $options);
}
