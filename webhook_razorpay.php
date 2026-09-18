<?php
// webhook_razorpay.php - Automated Razorpay Webhook Handler for 1-Day Study Pass
error_reporting(0);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

// Helper function to log webhook audit events
function logWebhookAudit($message, $data = null) {
    $logFile = __DIR__ . '/webhook.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message";
    if ($data !== null) {
        $logEntry .= ' | ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    $logEntry .= PHP_EOL;
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// 1. Check HTTP Request Method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'online',
        'service' => 'MPSC Practice Razorpay Webhook',
        'server_time' => date('Y-m-d H:i:s'),
        'instructions' => 'Send POST requests with X-Razorpay-Signature header'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// 2. Read Raw Payload & Signature Header
$rawBody = file_get_contents('php://input');
$receivedSignature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty request body']);
    exit;
}

// 3. Verify Razorpay HMAC SHA256 Signature
$webhookSecret = defined('RAZORPAY_WEBHOOK_SECRET') ? RAZORPAY_WEBHOOK_SECRET : 'mpsc_webhook_secret_2026';
$expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);

if (empty($receivedSignature) || !hash_equals($expectedSignature, $receivedSignature)) {
    logWebhookAudit('SIGNATURE_VERIFICATION_FAILED', [
        'received' => substr($receivedSignature, 0, 16) . '...',
        'expected' => substr($expectedSignature, 0, 16) . '...',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook signature']);
    exit;
}

// 4. Parse JSON Payload
$payload = json_decode($rawBody, true);
if (!is_array($payload) || !isset($payload['event'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON structure']);
    exit;
}

$event = $payload['event'];
logWebhookAudit("EVENT_RECEIVED: $event");

// 5. Filter for Successful Payment Events
$supportedEvents = ['payment_link.paid', 'payment.captured', 'order.paid'];
if (!in_array($event, $supportedEvents, true)) {
    // Acknowledge other Razorpay events cleanly without error
    echo json_encode(['status' => 'ignored', 'event' => $event, 'message' => 'Event not handled']);
    exit;
}

// 6. Extract Payment Details
$email = '';
$name = '';
$phone = '';
$paymentId = '';
$amountPaid = defined('DAILY_PASS_PRICE') ? DAILY_PASS_PRICE : 10.00;

if ($event === 'payment_link.paid') {
    $plEntity = $payload['payload']['payment_link']['entity'] ?? [];
    $payEntity = $payload['payload']['payment']['entity'] ?? [];

    $email = $plEntity['customer']['email'] ?? $payEntity['email'] ?? $plEntity['notes']['email'] ?? $payEntity['notes']['email'] ?? '';
    $name = $plEntity['customer']['name'] ?? $payEntity['notes']['name'] ?? $plEntity['notes']['name'] ?? '';
    $phone = $plEntity['customer']['contact'] ?? $payEntity['contact'] ?? '';
    $paymentId = $payEntity['id'] ?? $plEntity['id'] ?? ('PL_' . time());

    if (isset($payEntity['amount'])) {
        $amountPaid = floatval($payEntity['amount']) / 100;
    } elseif (isset($plEntity['amount'])) {
        $amountPaid = floatval($plEntity['amount']) / 100;
    }
} elseif ($event === 'payment.captured') {
    $payEntity = $payload['payload']['payment']['entity'] ?? [];

    $email = $payEntity['email'] ?? $payEntity['notes']['email'] ?? '';
    $name = $payEntity['notes']['name'] ?? '';
    $phone = $payEntity['contact'] ?? $payEntity['notes']['phone'] ?? '';
    $paymentId = $payEntity['id'] ?? ('PAY_' . time());

    if (isset($payEntity['amount'])) {
        $amountPaid = floatval($payEntity['amount']) / 100;
    }
} elseif ($event === 'order.paid') {
    $orderEntity = $payload['payload']['order']['entity'] ?? [];
    $payEntity = $payload['payload']['payment']['entity'] ?? [];

    $email = $payEntity['email'] ?? $orderEntity['notes']['email'] ?? $payEntity['notes']['email'] ?? '';
    $name = $orderEntity['notes']['name'] ?? $payEntity['notes']['name'] ?? '';
    $phone = $payEntity['contact'] ?? '';
    $paymentId = $payEntity['id'] ?? $orderEntity['id'] ?? ('ORD_' . time());

    if (isset($payEntity['amount'])) {
        $amountPaid = floatval($payEntity['amount']) / 100;
    } elseif (isset($orderEntity['amount_paid'])) {
        $amountPaid = floatval($orderEntity['amount_paid']) / 100;
    }
}

// Clean & Validate Email
$email = filter_var(trim(strtolower($email)), FILTER_VALIDATE_EMAIL);

if (empty($email)) {
    logWebhookAudit('PAYMENT_IGNORED_NO_EMAIL', [
        'event' => $event,
        'payment_id' => $paymentId,
        'phone' => $phone
    ]);
    // Respond 200 so Razorpay does not endlessly retry an unmapped payment
    echo json_encode([
        'status' => 'warning',
        'message' => 'No valid customer email found in webhook payload',
        'payment_id' => $paymentId
    ]);
    exit;
}

// 7. Calculate Expiration Timestamp (Daily Pass Expires Today at 12:00 AM Midnight)
// If payment happens right before midnight (after 23:50), grant until tomorrow midnight to be fair
$now = time();
$midnightToday = strtotime(date('Y-m-d 23:59:59'));
if (($midnightToday - $now) < 600) {
    // Less than 10 minutes left in today; grant full next day until 12:00 AM midnight
    $expiresAt = date('Y-m-d 23:59:59', strtotime('+1 day'));
} else {
    $expiresAt = date('Y-m-d 23:59:59');
}

// 8. Connect Database & Activate 1-Day Study Pass
try {
    $pdo = getDBConnection();

    // Ensure User exists in tbl_app_users
    $userStmt = $pdo->prepare("
        INSERT INTO tbl_app_users (email, name, created_at, last_active)
        VALUES (?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            name = COALESCE(NULLIF(VALUES(name), ''), name),
            last_active = NOW()
    ");
    $userStmt->execute([$email, !empty($name) ? $name : 'विद्यार्थी (Student)']);

    // Check if user has an existing longer subscription (e.g. 1 Month or 1 Year)
    $checkStmt = $pdo->prepare("SELECT * FROM tbl_app_subscriptions WHERE user_email = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $checkStmt->execute([$email]);
    $existingSub = $checkStmt->fetch();

    $effectiveExpiresAt = $expiresAt;
    if ($existingSub && !empty($existingSub['expires_at'])) {
        $existingExpiryTime = strtotime($existingSub['expires_at']);
        if ($existingExpiryTime > strtotime($expiresAt)) {
            // Keep the longer expiration
            $effectiveExpiresAt = $existingSub['expires_at'];
        }
    }

    $planName = 'Daily Pass (1 Day)';
    $notes = "Razorpay Webhook: Verified payment {$paymentId} via {$event}. Valid until 12:00 AM midnight ({$effectiveExpiresAt})";

    $insSub = $pdo->prepare("
        INSERT INTO tbl_app_subscriptions (user_email, plan_name, amount, status, activated_at, expires_at, notes)
        VALUES (?, ?, ?, 'active', NOW(), ?, ?)
    ");
    $insSub->execute([$email, $planName, $amountPaid, $effectiveExpiresAt, $notes]);

    logWebhookAudit('PAYMENT_ACTIVATED_SUCCESS', [
        'email' => $email,
        'payment_id' => $paymentId,
        'amount' => $amountPaid,
        'expires_at' => $effectiveExpiresAt
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Daily Pass activated successfully until 12:00 AM midnight',
        'email' => $email,
        'plan' => $planName,
        'amount' => $amountPaid,
        'expires_at' => $effectiveExpiresAt,
        'payment_id' => $paymentId
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    logWebhookAudit('DATABASE_ERROR', [
        'error' => $e->getMessage(),
        'email' => $email,
        'payment_id' => $paymentId
    ]);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error during subscription activation: ' . $e->getMessage()
    ]);
    exit;
}
