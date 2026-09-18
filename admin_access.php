<?php
// sync/admin_access.php - MPSC Abhyas Admin Subscription & Access Manager
session_start();
require_once __DIR__ . '/config.php';

$pdo = getDBConnection();

// Check Admin Authentication
$isAdmin = false;
if (isset($_SESSION['mpsc_admin_logged']) && $_SESSION['mpsc_admin_logged'] === true) {
    $isAdmin = true;
}
if ((isset($_GET['key']) && $_GET['key'] === ADMIN_SECRET_KEY) || (isset($_POST['key']) && $_POST['key'] === ADMIN_SECRET_KEY)) {
    $_SESSION['mpsc_admin_logged'] = true;
    $isAdmin = true;
}

// Handle Login
$loginError = '';
if (isset($_POST['admin_action']) && $_POST['admin_action'] === 'login') {
    $pass = $_POST['admin_password'] ?? '';
    if ($pass === ADMIN_SECRET_KEY) {
        $_SESSION['mpsc_admin_logged'] = true;
        $isAdmin = true;
    } else {
        $loginError = 'चुकीचा पासवर्ड! (Invalid Admin Password)';
    }
}

// Handle Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['mpsc_admin_logged']);
    header('Location: admin_access.php');
    exit;
}

// Handle Actions if logged in
$successMsg = '';
if ($isAdmin && isset($_POST['action'])) {
    $action = $_POST['action'];
    $email = trim($_POST['email'] ?? '');

    if (!empty($email)) {
        // Ensure user exists in tbl_app_users
        $stmt = $pdo->prepare("SELECT id FROM tbl_app_users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user) {
            $stmt = $pdo->prepare("INSERT INTO tbl_app_users (email, name) VALUES (?, ?)");
            $stmt->execute([$email, 'विद्यार्थी (Student)']);
        }

        if ($action === 'grant_day') {
            // Grant access until today 12:00 AM midnight
            $expiry = date('Y-m-d 23:59:59');
            $checkSub = $pdo->prepare("SELECT id FROM tbl_app_subscriptions WHERE user_email = ? ORDER BY id DESC LIMIT 1");
            $checkSub->execute([$email]);
            $sub = $checkSub->fetch();
            if ($sub) {
                $pdo->prepare("UPDATE tbl_app_subscriptions SET status = 'active', plan_name = 'Daily Pass (1 Day)', amount = 10.00, activated_at = NOW(), expires_at = ?, notes = '१ दिवस ॲक्सेस (आज रात्री १२ वाजेपर्यंत) मंजूर केला' WHERE id = ?")
                    ->execute([$expiry, $sub['id']]);
            } else {
                $pdo->prepare("INSERT INTO tbl_app_subscriptions (user_email, plan_name, amount, status, activated_at, expires_at, notes) VALUES (?, 'Daily Pass (1 Day)', 10.00, 'active', NOW(), ?, '१ दिवस ॲक्सेस (आज रात्री १२ वाजेपर्यंत) मंजूर केला')")
                    ->execute([$email, $expiry]);
            }
            $successMsg = "ईमेल [{$email}] साठी आज रात्री १२ वाजेपर्यंतचा (१ दिवस) दैनिक ॲक्सेस सक्रिय केला!";
        } elseif ($action === 'grant_month') {
            // Grant or extend 30 days
            $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
            $stmt = $pdo->prepare("
                INSERT INTO tbl_app_subscriptions (user_email, plan_name, amount, status, activated_at, expires_at)
                VALUES (?, '1 Month Unlimited', 199.00, 'active', NOW(), ?)
                ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), expires_at = ?
            ");
            // If already exists active, update last record
            $checkSub = $pdo->prepare("SELECT id FROM tbl_app_subscriptions WHERE user_email = ? ORDER BY id DESC LIMIT 1");
            $checkSub->execute([$email]);
            $sub = $checkSub->fetch();
            if ($sub) {
                $pdo->prepare("UPDATE tbl_app_subscriptions SET status = 'active', activated_at = NOW(), expires_at = ?, notes = '1 महिना ॲक्सेस मंजूर केला' WHERE id = ?")
                    ->execute([$expiry, $sub['id']]);
            } else {
                $pdo->prepare("INSERT INTO tbl_app_subscriptions (user_email, plan_name, amount, status, activated_at, expires_at, notes) VALUES (?, '1 Month Unlimited', 199.00, 'active', NOW(), ?, '1 महिना ॲक्सेस मंजूर केला')")
                    ->execute([$email, $expiry]);
            }
            $successMsg = "ईमेल [{$email}] साठी १ महिन्याचा अमर्यादित ॲक्सेस यशस्वीरित्या सक्रिय केला!";
        } elseif ($action === 'grant_year') {
            $expiry = date('Y-m-d H:i:s', strtotime('+365 days'));
            $checkSub = $pdo->prepare("SELECT id FROM tbl_app_subscriptions WHERE user_email = ? ORDER BY id DESC LIMIT 1");
            $checkSub->execute([$email]);
            $sub = $checkSub->fetch();
            if ($sub) {
                $pdo->prepare("UPDATE tbl_app_subscriptions SET status = 'active', activated_at = NOW(), expires_at = ?, notes = '1 वर्ष ॲक्सेस मंजूर केला' WHERE id = ?")
                    ->execute([$expiry, $sub['id']]);
            } else {
                $pdo->prepare("INSERT INTO tbl_app_subscriptions (user_email, plan_name, amount, status, activated_at, expires_at, notes) VALUES (?, '1 Year Unlimited', 499.00, 'active', NOW(), ?, '1 वर्ष ॲक्सेस मंजूर केला')")
                    ->execute([$email, $expiry]);
            }
            $successMsg = "ईमेल [{$email}] साठी १ वर्षाचा ॲक्सेस यशस्वीरित्या सक्रिय केला!";
        } elseif ($action === 'deactivate') {
            $pdo->prepare("UPDATE tbl_app_subscriptions SET status = 'inactive', notes = 'ॲडमिनद्वारे ॲक्सेस बंद केला' WHERE user_email = ?")
                ->execute([$email]);
            $successMsg = "ईमेल [{$email}] चा ॲक्सेस बंद केला.";
        }
    }
}

// Reset Guest IP Trials actions
if ($isAdmin && isset($_POST['reset_guest_ips'])) {
    $pdo->exec("TRUNCATE TABLE tbl_trial_ips");
    $successMsg = "सर्व गेस्ट IP ट्रायल्स यशस्वीरित्या रीसेट केले (All guest IP trials reset)!";
}

if ($isAdmin && isset($_GET['reset_my_ip'])) {
    $myIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $pdo->prepare("DELETE FROM tbl_trial_ips WHERE ip_address = ?")->execute([$myIp]);
    $successMsg = "स्थानिक IP [{$myIp}] चा ट्रायल यशस्वीरित्या रीसेट केला!";
}

if (!function_exists('formatDuration')) {
    function formatDuration($seconds) {
        $seconds = max(0, intval($seconds));
        if ($seconds < 60) {
            return "{$seconds} से.";
        }
        $mins = floor($seconds / 60);
        $remSecs = $seconds % 60;
        if ($mins < 60) {
            return "{$mins} मि. {$remSecs} से.";
        }
        $hours = floor($mins / 60);
        $remMins = $mins % 60;
        return "{$hours} तास {$remMins} मि.";
    }
}

// Fetch all users with their latest subscription status
$users = [];
$totalUsers = 0;
$activeSubs = 0;
$pendingSubs = 0;
$totalTrialIps = 0;
$totalVisitors = 0;
$activeNowCount = 0;
$totalSecondsSpentAll = 0;
$avgSecondsSpent = 0;
$visitorsList = [];

if ($isAdmin) {
    try {
        $ipCountStmt = $pdo->query("SELECT COUNT(*) FROM tbl_trial_ips");
        $totalTrialIps = (int)$ipCountStmt->fetchColumn();

        $vCountStmt = $pdo->query("SELECT COUNT(*) FROM tbl_visitor_analytics");
        $totalVisitors = (int)$vCountStmt->fetchColumn();

        $activeStmt = $pdo->query("SELECT COUNT(*) FROM tbl_visitor_analytics WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $activeNowCount = (int)$activeStmt->fetchColumn();

        $totSecStmt = $pdo->query("SELECT COALESCE(SUM(total_seconds), 0) FROM tbl_visitor_analytics");
        $totalSecondsSpentAll = (int)$totSecStmt->fetchColumn();
        $avgSecondsSpent = $totalVisitors > 0 ? round($totalSecondsSpentAll / $totalVisitors) : 0;

        $vListStmt = $pdo->query("
            SELECT 
                v.*,
                TIMESTAMPDIFF(SECOND, v.last_seen, NOW()) as seconds_ago,
                s.status as sub_status,
                s.expires_at,
                s.plan_name
            FROM tbl_visitor_analytics v
            LEFT JOIN (
                SELECT s1.user_email, s1.status, s1.expires_at, s1.plan_name
                FROM tbl_app_subscriptions s1
                INNER JOIN (
                    SELECT user_email, MAX(id) as max_id
                    FROM tbl_app_subscriptions
                    GROUP BY user_email
                ) s2 ON s1.id = s2.max_id
            ) s ON v.user_email = s.user_email
            ORDER BY v.last_seen DESC
            LIMIT 150
        ");
        $visitorsList = $vListStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $stmt = $pdo->query("
        SELECT 
            u.id as user_id,
            u.email,
            u.name,
            u.picture,
            u.created_at as registered_at,
            u.last_active,
            s.id as sub_id,
            s.plan_name,
            s.amount,
            s.status as sub_status,
            s.activated_at,
            s.expires_at,
            s.notes
        FROM tbl_app_users u
        LEFT JOIN (
            SELECT s1.*
            FROM tbl_app_subscriptions s1
            INNER JOIN (
                SELECT user_email, MAX(id) as max_id
                FROM tbl_app_subscriptions
                GROUP BY user_email
            ) s2 ON s1.id = s2.max_id
        ) s ON u.email = s.user_email
        ORDER BY u.id DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalUsers = count($users);

    foreach ($users as $u) {
        $isSubActive = false;
        if (!empty($u['sub_status']) && $u['sub_status'] === 'active') {
            if (empty($u['expires_at']) || strtotime($u['expires_at']) > time()) {
                $isSubActive = true;
            }
        }
        if ($isSubActive) {
            $activeSubs++;
        } elseif (!empty($u['sub_status']) && $u['sub_status'] === 'pending') {
            $pendingSubs++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MPSC Abhyas Admin | युझर ॲक्सेस व्यवस्थापन</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-body: #0b0f19;
            --bg-card: #162032;
            --bg-card-hover: #1e293b;
            --primary: #3b82f6;
            --accent-emerald: #10b981;
            --accent-rose: #ef4444;
            --accent-amber: #f59e0b;
            --border-color: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', 'Noto Sans Devanagari', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            padding: 24px 16px;
        }
        .admin-container {
            max-width: 1140px;
            margin: 0 auto;
        }
        .admin-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }
        .admin-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .admin-logo {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #fff;
        }
        .admin-header h1 {
            font-size: 20px;
            font-weight: 700;
        }
        .admin-header p {
            font-size: 13px;
            color: var(--text-muted);
        }
        .admin-header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-link-action {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            padding: 8px 14px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-link-action:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .stat-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .stat-info .num {
            font-size: 28px;
            font-weight: 800;
            line-height: 1;
        }
        .stat-info .lbl {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
        }
        .stat-icon {
            font-size: 28px;
            opacity: 0.7;
        }
        .section-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .section-title {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .add-user-form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            background: rgba(15, 23, 42, 0.6);
            padding: 14px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        .input-email {
            flex: 1;
            min-width: 240px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            padding: 10px 14px;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            outline: none;
        }
        .input-email:focus {
            border-color: var(--primary);
        }
        .btn-submit-action {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .table-wrap {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        th {
            text-align: left;
            padding: 12px 14px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        td {
            padding: 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
        }
        tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        .user-email-col {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(59, 130, 246, 0.2);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
        }
        .badge-status.active {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .badge-status.pending {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .badge-status.inactive {
            background: rgba(100, 116, 139, 0.15);
            color: #94a3b8;
            border: 1px solid rgba(100, 116, 139, 0.25);
        }
        .pulse-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: #34d399;
            padding: 3px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
        }
        .pulse-dot {
            width: 7px;
            height: 7px;
            background: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: livePulseAnim 1.6s infinite;
        }
        @keyframes livePulseAnim {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 7px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .time-spent-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12.5px;
            font-weight: 700;
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.1);
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid rgba(56, 189, 248, 0.25);
        }
        .device-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            color: #94a3b8;
            background: rgba(255, 255, 255, 0.05);
            padding: 2px 7px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
        }
        .action-btns-cell {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .btn-act-month {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-act-month:hover {
            background: #10b981;
            color: #fff;
        }
        .btn-act-year {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-act-year:hover {
            background: #3b82f6;
            color: #fff;
        }
        .btn-act-revoke {
            background: rgba(239, 68, 68, 0.1);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.25);
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 11.5px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-act-revoke:hover {
            background: #ef4444;
            color: #fff;
        }
        .login-card {
            max-width: 400px;
            margin: 60px auto;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.6);
            text-align: center;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid #10b981;
            color: #34d399;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13.5px;
        }
    </style>
</head>
<body>

<?php if (!$isAdmin): ?>
    <div class="login-card">
        <div style="font-size: 40px; color: #3b82f6; margin-bottom: 14px;"><i class="fa-solid fa-shield-halved"></i></div>
        <h2 style="font-size: 20px; margin-bottom: 6px;">MPSC Abhyas Admin</h2>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">युझर ॲक्सेस व्यवस्थापनासाठी पासवर्ड टाका</p>
        
        <?php if ($loginError): ?>
            <div style="color: #ef4444; font-size: 13px; margin-bottom: 14px;"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="admin_action" value="login">
            <input type="password" name="admin_password" placeholder="Admin Secret Password" required style="width: 100%; padding: 11px 14px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); border-radius: 8px; color: #fff; font-size: 14px; margin-bottom: 16px; outline: none;">
            <button type="submit" style="width: 100%; padding: 11px; background: #3b82f6; border: none; border-radius: 8px; color: #fff; font-weight: 700; font-size: 14px; cursor: pointer;">
                लॉगिन करा (Login)
            </button>
        </form>
        <p style="font-size: 11px; color: #64748b; margin-top: 14px;">Default Key: mpsc2026</p>
    </div>
<?php else: ?>
    <div class="admin-container">
        <!-- Top Bar -->
        <header class="admin-header">
            <div class="admin-title-wrap">
                <div class="admin-logo"><i class="fa-solid fa-crown"></i></div>
                <div>
                    <h1>MPSC Abhyas • युझर सबस्क्रिप्शन व्यवस्थापक</h1>
                    <p>विद्यार्थ्यांचे ईमेल तपासा आणि थेट १-क्लिकमध्ये ॲक्सेस सक्रिय करा</p>
                </div>
            </div>
            <div class="admin-header-actions">
                <a href="practice.php" class="btn-link-action" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> सराव केंद्र उघडा</a>
                <a href="admin_access.php?logout=1" class="btn-link-action" style="color: #ef4444;"><i class="fa-solid fa-right-from-bracket"></i> लॉगआउट</a>
            </div>
        </header>

        <?php if ($successMsg): ?>
            <div class="alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 24px;">
            <div class="stat-box">
                <div class="stat-info">
                    <div class="num" style="color: #34d399; display: flex; align-items: center; gap: 8px;">
                        <span class="pulse-dot"></span> <?= $activeNowCount ?>
                    </div>
                    <div class="lbl">सध्या ॲक्टिव्ह विद्यार्थी (Live Now)</div>
                </div>
                <div class="stat-icon" style="color: #10b981;"><i class="fa-solid fa-signal"></i></div>
            </div>
            <div class="stat-box">
                <div class="stat-info">
                    <div class="num" style="color: #38bdf8;"><?= $totalVisitors ?></div>
                    <div class="lbl">एकूण युनिक अभ्यागत (Visitors)</div>
                </div>
                <div class="stat-icon" style="color: #0284c7;"><i class="fa-solid fa-users-viewfinder"></i></div>
            </div>
            <div class="stat-box">
                <div class="stat-info">
                    <div class="num" style="color: #fbbf24; font-size: 20px;"><?= formatDuration($totalSecondsSpentAll) ?></div>
                    <div class="lbl">एकूण अभ्यास वेळ (Total Time)</div>
                </div>
                <div class="stat-icon" style="color: #f59e0b;"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
            <div class="stat-box">
                <div class="stat-info">
                    <div class="num" style="color: #a78bfa; font-size: 20px;"><?= formatDuration($avgSecondsSpent) ?></div>
                    <div class="lbl">सरासरी वेळ / विद्यार्थी (Avg Time)</div>
                </div>
                <div class="stat-icon" style="color: #8b5cf6;"><i class="fa-solid fa-stopwatch"></i></div>
            </div>
            <div class="stat-box">
                <div class="stat-info">
                    <div class="num" style="color: #34d399;"><?= $activeSubs ?></div>
                    <div class="lbl">सक्रिय सबस्क्रिप्शन (Active PRO)</div>
                </div>
                <div class="stat-icon" style="color: #10b981;"><i class="fa-solid fa-crown"></i></div>
            </div>
            <div class="stat-box">
                <div class="stat-info">
                    <div class="num" style="color: #c084fc;"><?= $totalTrialIps ?></div>
                    <div class="lbl">नोंदणी झालेले गेस्ट IPs (Trials)</div>
                    <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">
                        <form method="POST" style="display:inline;" onsubmit="return confirm('सर्व गेस्ट IP ट्रायल्स रीसेट करायचे का?');">
                            <button type="submit" name="reset_guest_ips" class="btn-act-year" style="padding: 3px 8px; font-size: 11px;">
                                <i class="fa-solid fa-arrows-rotate"></i> सर्व IPs रीसेट
                            </button>
                        </form>
                        <a href="practice.php?reset_trial=1&key=<?= ADMIN_SECRET_KEY ?>" target="_blank" class="btn-act-month" style="padding: 3px 8px; font-size: 11px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                            <i class="fa-solid fa-play"></i> टेस्ट रीसेट
                        </a>
                    </div>
                </div>
                <div class="stat-icon" style="color: #a855f7;"><i class="fa-solid fa-network-wired"></i></div>
            </div>
        </div>

        <!-- Real-Time Visitors & Time Spent Analytics Table -->
        <div class="section-box" style="border-left: 4px solid #38bdf8;">
            <div class="section-header">
                <div class="section-title">
                    <i class="fa-solid fa-chart-line" style="color: #38bdf8;"></i> युनिक विद्यार्थी व वेळ विश्लेषण (Visitors & Time Spent Analytics)
                    <span class="pulse-badge"><span class="pulse-dot"></span> <?= $activeNowCount ?> सध्या ॲक्टिव्ह</span>
                </div>
                <div style="font-size: 12.5px; color: var(--text-muted);">
                    एकूण अभ्यास वेळ: <strong style="color: #38bdf8;"><?= formatDuration($totalSecondsSpentAll) ?></strong> • सरासरी: <strong style="color: #34d399;"><?= formatDuration($avgSecondsSpent) ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>विद्यार्थी / अभ्यागत (Visitor)</th>
                            <th>डिव्हाइस (Device)</th>
                            <th>वेबसाईटवर घालवलेला वेळ (Time Spent)</th>
                            <th>प्रश्नांची संख्या</th>
                            <th>पहिली भेट (First Seen)</th>
                            <th>शेवटची ॲक्टिव्हिटी (Last Active)</th>
                            <th>स्थिती (Status)</th>
                            <th>ॲक्शन</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($visitorsList)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 24px;">अद्याप कोणतीही अभ्यागत नोंद झालेली नाही. सराव केंद्र उघडताच येथे लाईव्ह नोंदी दिसतील.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($visitorsList as $v): ?>
                                <?php 
                                    $isLiveNow = ($v['seconds_ago'] <= 300);
                                    $hasEmail = !empty($v['user_email']);
                                    $isPro = (!empty($v['sub_status']) && $v['sub_status'] === 'active');
                                ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <?php if ($hasEmail): ?>
                                                <div class="user-avatar" style="background: rgba(59, 130, 246, 0.2); color: #60a5fa;">
                                                    <i class="fa-brands fa-google"></i>
                                                </div>
                                                <div>
                                                    <div class="user-name"><?= htmlspecialchars($v['user_name'] ?: 'गुगल विद्यार्थी') ?></div>
                                                    <div class="user-email" style="color: #60a5fa;"><?= htmlspecialchars($v['user_email']) ?></div>
                                                </div>
                                            <?php else: ?>
                                                <div class="user-avatar" style="background: rgba(148, 163, 184, 0.15); color: #94a3b8;">
                                                    <i class="fa-solid fa-user-secret"></i>
                                                </div>
                                                <div>
                                                    <div class="user-name">गेस्ट अभ्यागत (Guest)</div>
                                                    <div class="user-email"><?= htmlspecialchars($v['ip_address']) ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="device-pill">
                                            <i class="fa-solid <?= $v['device_type'] === 'Mobile' ? 'fa-mobile-screen' : 'fa-desktop' ?>"></i>
                                            <?= htmlspecialchars($v['device_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="time-spent-badge">
                                            <i class="fa-solid fa-clock"></i> <?= formatDuration($v['total_seconds']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-size: 13px; font-weight: 600; color: #f1f5f9;">
                                            <i class="fa-solid fa-circle-question" style="color: #a855f7; margin-right: 4px;"></i> <?= intval($v['questions_viewed']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-size: 12px; color: #cbd5e1;"><?= date('d M Y, h:i A', strtotime($v['first_seen'])) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($isLiveNow): ?>
                                            <span class="pulse-badge"><span class="pulse-dot"></span> सध्या ॲक्टिव्ह</span>
                                        <?php else: ?>
                                            <div style="font-size: 12px; color: #94a3b8;">
                                                <?= date('d M, h:i A', strtotime($v['last_seen'])) ?>
                                                <span style="font-size: 11px; opacity: 0.7;">(<?= round($v['seconds_ago'] / 60) ?> मि. आधी)</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isPro): ?>
                                            <span class="badge-status active"><i class="fa-solid fa-crown"></i> PRO सक्रिय</span>
                                        <?php elseif ($hasEmail): ?>
                                            <span class="badge-status pending"><i class="fa-solid fa-user-check"></i> लॉगिन झाले</span>
                                        <?php else: ?>
                                            <span class="badge-status inactive"><i class="fa-solid fa-eye"></i> गेस्ट</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($hasEmail && !$isPro): ?>
                                            <form method="POST" style="display:inline-flex; gap: 4px;">
                                                <input type="hidden" name="email" value="<?= htmlspecialchars($v['user_email']) ?>">
                                                <button type="submit" name="action" value="grant_day" class="btn-act-day" style="background: rgba(245, 158, 11, 0.18); border: 1px solid rgba(245, 158, 11, 0.45); color: #fde047; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;" title="आज रात्री १२ वाजेपर्यंत १ दिवस ॲक्सेस द्या">
                                                    <i class="fa-solid fa-bolt"></i> ₹१० (१ दिवस)
                                                </button>
                                                <button type="submit" name="action" value="grant_month" class="btn-act-month" title="१ महिना ॲक्सेस द्या">
                                                    <i class="fa-solid fa-plus"></i> १ महिना
                                                </button>
                                            </form>
                                        <?php elseif ($hasEmail && $isPro): ?>
                                            <span style="font-size: 11px; color: #34d399;"><i class="fa-solid fa-check-double"></i> ॲक्सेस दिला आहे</span>
                                        <?php else: ?>
                                            <span style="font-size: 11px; color: #64748b;">लॉगिनची प्रतीक्षा</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add Email Manually Form -->
        <div class="section-box">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-user-plus" style="color: #10b981;"></i> नवीन ईमेल थेट सक्रिय करा (Add & Activate Access Manually)</div>
            </div>
            <form method="POST" class="add-user-form">
                <input type="email" name="email" class="input-email" placeholder="विद्यार्थ्याचा ईमेल टाका (उदा. student@gmail.com)" required>
                <button type="submit" name="action" value="grant_day" class="btn-submit-action" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                    <i class="fa-solid fa-bolt"></i> ₹१० १ दिवस (आज रात्री १२ पर्यंत)
                </button>
                <button type="submit" name="action" value="grant_month" class="btn-submit-action">
                    <i class="fa-solid fa-plus"></i> १ महिना ॲक्सेस द्या
                </button>
                <button type="submit" name="action" value="grant_year" class="btn-submit-action" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <i class="fa-solid fa-star"></i> १ वर्ष ॲक्सेस द्या
                </button>
            </form>
        </div>

        <!-- Users Table -->
        <div class="section-box">
            <div class="section-header">
                <div class="section-title"><i class="fa-solid fa-list-check" style="color: #60a5fa;"></i> सर्व विद्यार्थी व ॲक्सेस सूची (Users & Access Status)</div>
                <input type="text" id="searchInput" placeholder="ईमेल किंवा नाव शोधा..." style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); padding: 7px 12px; border-radius: 6px; color: #fff; font-size: 13px; outline: none; width: 220px;">
            </div>

            <div class="table-wrap">
                <table id="usersTable">
                    <thead>
                        <tr>
                            <th>विद्यार्थी (Email & Name)</th>
                            <th>नोंदणी तारीख</th>
                            <th>सद्यस्थिती (Status)</th>
                            <th>मुदत (Valid Till)</th>
                            <th>ॲक्शन (1-Click Action)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                    अद्याप कोणत्याही विद्यार्थ्याने नोंदणी केलेली नाही. वरील फॉर्म वापरून तुम्ही थेट ईमेल जोडू शकता.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($users as $u): 
                            $isActive = (!empty($u['sub_status']) && $u['sub_status'] === 'active' && (empty($u['expires_at']) || strtotime($u['expires_at']) > time()));
                            $isPending = (!empty($u['sub_status']) && $u['sub_status'] === 'pending');
                        ?>
                            <tr>
                                <td>
                                    <div class="user-email-col">
                                        <div class="user-avatar">
                                            <?php if (!empty($u['picture'])): ?>
                                                <img src="<?= htmlspecialchars($u['picture']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                <?= strtoupper(substr($u['email'], 0, 1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; color: #fff;"><?= htmlspecialchars($u['email']) ?></div>
                                            <div style="font-size: 11.5px; color: var(--text-muted);"><?= htmlspecialchars($u['name'] ?? '') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="color: var(--text-muted); font-size: 12.5px;">
                                    <?= date('d M Y, h:i A', strtotime($u['registered_at'])) ?>
                                </td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge-status active"><i class="fa-solid fa-circle-check"></i> ॲक्टिव्ह (Active)</span>
                                    <?php elseif ($isPending): ?>
                                        <span class="badge-status pending"><i class="fa-solid fa-clock"></i> प्रलंबित (Pending)</span>
                                    <?php else: ?>
                                        <span class="badge-status inactive"><i class="fa-solid fa-circle-xmark"></i> निष्क्रिय (Unpaid)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12.5px;">
                                    <?php if ($isActive && !empty($u['expires_at'])): 
                                        $expSec = strtotime($u['expires_at']) - time();
                                        $diffHours = ceil($expSec / 3600);
                                        $diffDays = ceil($expSec / 86400);
                                    ?>
                                        <span style="color: #34d399; font-weight: 600;"><?= date('d M Y, h:i A', strtotime($u['expires_at'])) ?></span>
                                        <?php if ($diffHours <= 24 && $diffHours > 0): ?>
                                            <div style="font-size: 11px; color: #fbbf24; font-weight: 600;"><i class="fa-solid fa-clock"></i> <?= $diffHours ?> तास शिल्लक (आज रात्री १२ पर्यंत)</div>
                                        <?php elseif ($diffDays > 0): ?>
                                            <div style="font-size: 11px; color: var(--text-muted);">(<?= $diffDays ?> दिवस शिल्लक)</div>
                                        <?php else: ?>
                                            <div style="font-size: 11px; color: #ef4444;">मुदत संपली</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #64748b;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="action-btns-cell">
                                        <input type="hidden" name="email" value="<?= htmlspecialchars($u['email']) ?>">
                                        
                                        <button type="submit" name="action" value="grant_day" class="btn-act-day" style="background: rgba(245, 158, 11, 0.18); border: 1px solid rgba(245, 158, 11, 0.45); color: #fde047; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" title="आज रात्री १२ वाजेपर्यंत १ दिवस ॲक्सेस द्या">
                                            <i class="fa-solid fa-bolt"></i> ₹१० १ दिवस
                                        </button>
                                        
                                        <button type="submit" name="action" value="grant_month" class="btn-act-month" title="१ महिन्यासाठी सक्रिय करा">
                                            <i class="fa-solid fa-plus"></i> १ महिना
                                        </button>
                                        
                                        <button type="submit" name="action" value="grant_year" class="btn-act-year" title="१ वर्षासाठी सक्रिय करा">
                                            <i class="fa-solid fa-star"></i> १ वर्ष
                                        </button>
                                        
                                        <?php if ($isActive): ?>
                                            <button type="submit" name="action" value="deactivate" class="btn-act-revoke" title="ॲक्सेस बंद करा" onclick="return confirm('नक्की ॲक्सेस बंद करायचा आहे का?');">
                                                <i class="fa-solid fa-ban"></i> बंद करा
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const q = e.target.value.toLowerCase();
                const rows = document.querySelectorAll('#usersTable tbody tr');
                rows.forEach(r => {
                    const text = r.textContent.toLowerCase();
                    r.style.display = text.includes(q) ? '' : 'none';
                });
            });
        }
    </script>
<?php endif; ?>

</body>
</html>
