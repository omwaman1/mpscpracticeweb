<?php
// sync/practice.php - Dedicated MPSC Practice Center
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
require_once __DIR__ . '/config.php';

// Client IP Helper Function
if (!function_exists('getClientIP')) {
    function getClientIP() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = trim($_SERVER['HTTP_CLIENT_IP']);
        }
        return substr($ip, 0, 64);
    }
}

// Check User Login & Subscription Status from Database
$userSession = null;
$isSubscribedUser = false;
$userExpiresAt = null;
$userHoursLeft = 0;
$userDaysLeft = 0;
$userTrialExpired = false;
$trialLimitSeconds = defined('FREE_TRIAL_SECONDS') ? FREE_TRIAL_SECONDS : 3600;
$clientIp = getClientIP();
$ipTrialExpired = false;
$serverSecondsRemaining = $trialLimitSeconds;

$pdoInit = null;
try {
    $pdoInit = getDBConnection();
} catch (Exception $e) {}

// Admin / Dev instant trial reset feature: ?reset_trial=1&key=mpsc2026
if (isset($_GET['reset_trial']) && $_GET['reset_trial'] === '1') {
    if ((isset($_GET['key']) && $_GET['key'] === ADMIN_SECRET_KEY) || (isset($_SESSION['mpsc_admin_logged']) && $_SESSION['mpsc_admin_logged'] === true)) {
        if ($pdoInit) {
            try {
                $pdoReset = getDBConnection();
                $pdoReset->prepare("DELETE FROM tbl_trial_ips WHERE ip_address = ?")->execute([$clientIp]);
                if (!empty($_SESSION['mpsc_user_email'])) {
                    $pdoReset->prepare("UPDATE tbl_app_users SET created_at = NOW() WHERE email = ?")->execute([$_SESSION['mpsc_user_email']]);
                }
            } catch (Exception $e) {}
        }
        $targetGoal = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['goal'] ?? 'mpsc-combined-group-c-2026');
        header("Location: practice.php?goal=" . $targetGoal . "&reset_done=1");
        exit;
    }
}

if ($pdoInit && !empty($_SESSION['mpsc_user_email'])) {
    $sessEmail = $_SESSION['mpsc_user_email'];
    try {
        $uStmt = $pdoInit->prepare("SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds FROM tbl_app_users WHERE email = ?");
        $uStmt->execute([$sessEmail]);
        $userSession = $uStmt->fetch(PDO::FETCH_ASSOC);

        $sStmt = $pdoInit->prepare("SELECT * FROM tbl_app_subscriptions WHERE user_email = ? ORDER BY id DESC LIMIT 1");
        $sStmt->execute([$sessEmail]);
        $subRow = $sStmt->fetch(PDO::FETCH_ASSOC);

        if ($subRow && $subRow['status'] === 'active') {
            if (empty($subRow['expires_at']) || strtotime($subRow['expires_at']) > time()) {
                $isSubscribedUser = true;
                $userExpiresAt = $subRow['expires_at'];
                if ($userExpiresAt) {
                    $secondsLeft = max(0, strtotime($userExpiresAt) - time());
                    $userHoursLeft = max(1, ceil($secondsLeft / 3600));
                    $userDaysLeft = max(1, ceil($secondsLeft / 86400));
                }
            }
        }

        if ($userSession && !$isSubscribedUser) {
            $userElapsed = isset($userSession['elapsed_seconds']) ? max(0, intval($userSession['elapsed_seconds'])) : 0;
            if ($userElapsed >= $trialLimitSeconds) {
                $userTrialExpired = true;
            } else {
                $serverSecondsRemaining = min($serverSecondsRemaining, max(0, $trialLimitSeconds - $userElapsed));
            }
        }
    } catch (Exception $e) {
        // Fallback gracefully
    }
}

// IP-Based Trial Tracking (Enforces trial limit across all browsers, incognito sessions, and guests on this IP)
if (!$isSubscribedUser && $pdoInit) {
    try {
        $ipStmt = $pdoInit->prepare("SELECT *, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed_seconds FROM tbl_trial_ips WHERE ip_address = ?");
        $ipStmt->execute([$clientIp]);
        $ipRow = $ipStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ipRow) {
            // First time this IP is seen: record start time
            $insStmt = $pdoInit->prepare("INSERT INTO tbl_trial_ips (ip_address, started_at, last_active) VALUES (?, NOW(), NOW())");
            $insStmt->execute([$clientIp]);
            if ($userTrialExpired) {
                $serverSecondsRemaining = 0;
            }
        } else {
            // Existing IP record: calculate elapsed seconds from started_at
            $ipElapsed = isset($ipRow['elapsed_seconds']) ? max(0, intval($ipRow['elapsed_seconds'])) : 0;
            if ($ipElapsed >= $trialLimitSeconds) {
                $ipTrialExpired = true;
                $serverSecondsRemaining = 0;
            } else {
                $serverSecondsRemaining = min($serverSecondsRemaining, max(0, $trialLimitSeconds - $ipElapsed));
            }
            $updStmt = $pdoInit->prepare("UPDATE tbl_trial_ips SET last_active = NOW() WHERE ip_address = ?");
            $updStmt->execute([$clientIp]);
        }
    } catch (Exception $e) {
        // Fallback gracefully
    }
}

$isTrialTimeOver = ($userTrialExpired || $ipTrialExpired);
if ($isTrialTimeOver) {
    $serverSecondsRemaining = 0;
}

// Record/Update Visitor in tbl_visitor_analytics
if ($pdoInit) {
    try {
        $devType = (isset($_SERVER['HTTP_USER_AGENT']) && preg_match('/Mobile|Android|iPhone|iPad/i', $_SERVER['HTTP_USER_AGENT'])) ? 'Mobile' : 'Desktop';
        $vEmail = !empty($sessEmail) ? $sessEmail : null;
        $vName = $userSession['name'] ?? null;
        $pdoInit->prepare("
            INSERT INTO tbl_visitor_analytics (ip_address, user_email, user_name, device_type, total_seconds, questions_viewed, first_seen, last_seen)
            VALUES (?, ?, ?, ?, 0, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                user_email = COALESCE(NULLIF(VALUES(user_email), ''), user_email),
                user_name = COALESCE(NULLIF(VALUES(user_name), ''), user_name),
                device_type = VALUES(device_type),
                questions_viewed = questions_viewed + 1,
                last_seen = NOW()
        ")->execute([$clientIp, $vEmail, $vName, $devType]);
    } catch (Exception $e) {}
}

// Multi-Exam Goals Configuration
$goals = [
    'mpsc-combined-group-c-2026' => [
        'name' => 'MPSC Group C',
        'badge' => 'Group C 2026',
        'sub' => 'महाराष्ट्र अराजपत्रित गट-क सेवा संयुक्त पूर्व परीक्षा',
        'icon' => 'fa-solid fa-layer-group',
        'default_subject' => 'History'
    ],
    'mpsc-rajyaseva-2027' => [
        'name' => 'MPSC Rajyaseva',
        'badge' => 'Rajyaseva 2027',
        'sub' => 'महाराष्ट्र राजपत्रित नागरी सेवा (राज्यसेवा) परीक्षा',
        'icon' => 'fa-solid fa-crown',
        'default_subject' => 'GS Paper - I'
    ],
    'group-c-1' => [
        'name' => 'Group C 1',
        'badge' => 'Group C 1',
        'sub' => 'महाराष्ट्र अराजपत्रित गट-क सेवा संयुक्त पूर्व परीक्षा सराव मालिका - १',
        'icon' => 'fa-solid fa-layer-group',
        'default_subject' => 'History'
    ],
    'maharashtra-gk' => [
        'name' => 'Maharashtra GK',
        'badge' => 'महा. GK',
        'sub' => 'महाराष्ट्र सामान्य ज्ञान, पोलीस भरती, तलाठी व सराव संच',
        'icon' => 'fa-solid fa-landmark',
        'default_subject' => 'History'
    ]
];

$currentGoal = $_GET['goal'] ?? 'mpsc-combined-group-c-2026';
if ($currentGoal === 'ignite-mpsc-group-c') {
    $currentGoal = 'group-c-1';
}
if (!isset($goals[$currentGoal])) {
    $currentGoal = 'mpsc-combined-group-c-2026';
}
$activeGoalInfo = $goals[$currentGoal];

// Subject configuration & visual styling (Combined Group C & Rajyaseva)
$subjectMeta = [
    'Current Affairs' => [
        'title_mr' => 'चालू घडामोडी',
        'icon' => 'fa-solid fa-newspaper',
        'badge_color' => '#f43f5e',
        'gradient' => 'linear-gradient(135deg, #e11d48 0%, #f43f5e 100%)',
        'desc' => 'महाराष्ट्र, राष्ट्रीय व आंतरराष्ट्रीय चालू घडामोडी आणि पुरस्कार'
    ],
    'Full Length Test' => [
        'title_mr' => 'संपूर्ण सराव चाचणी (Full Mock)',
        'icon' => 'fa-solid fa-file-signature',
        'badge_color' => '#8b5cf6',
        'gradient' => 'linear-gradient(135deg, #7c3aed 0%, #6366f1 100%)',
        'desc' => 'संयुक्त गट-क संपूर्ण १०० प्रश्न पॅटर्न परीक्षा सराव संच'
    ],
    'GS Paper - I' => [
        'title_mr' => 'सामान्य अध्ययन पेपर - १',
        'icon' => 'fa-solid fa-earth-americas',
        'badge_color' => '#3b82f6',
        'gradient' => 'linear-gradient(135deg, #1e40af 0%, #3b82f6 100%)',
        'desc' => 'प्राकृतिक, भारत, जग व मानवी भूगोल, प्राचीन, मध्ययुगीन, आधुनिक भारत व जगाचा इतिहास, कला व संस्कृती'
    ],
    'GS Paper - II' => [
        'title_mr' => 'सामान्य अध्ययन पेपर - २',
        'icon' => 'fa-solid fa-scale-balanced',
        'badge_color' => '#10b981',
        'gradient' => 'linear-gradient(135deg, #047857 0%, #10b981 100%)',
        'desc' => 'भारतीय राज्यघटना, राज्यव्यवस्था, आंतरराष्ट्रीय संबंध व आंतरराष्ट्रीय घडामोडी'
    ],
    'GS Paper III' => [
        'title_mr' => 'सामान्य अध्ययन पेपर - ३',
        'icon' => 'fa-solid fa-chart-pie',
        'badge_color' => '#f59e0b',
        'gradient' => 'linear-gradient(135deg, #b45309 0%, #f59e0b 100%)',
        'desc' => 'भारतीय अर्थव्यवस्था, पर्यावरण व जैवविविधता, सामान्य विज्ञान व तंत्रज्ञान विकास'
    ],
    'English' => [
        'title_mr' => 'इंग्रजी व्याकरण व आकलन',
        'icon' => 'fa-solid fa-spell-check',
        'badge_color' => '#06b6d4',
        'gradient' => 'linear-gradient(135deg, #0e7490 0%, #06b6d4 100%)',
        'desc' => 'Parts of Speech, English Grammar, Vocabulary, Reading Comprehension'
    ],
    'CSAT' => [
        'title_mr' => 'नागरी सेवा अभियोग्यता चाचणी (CSAT)',
        'icon' => 'fa-solid fa-brain',
        'badge_color' => '#8b5cf6',
        'gradient' => 'linear-gradient(135deg, #6d28d9 0%, #8b5cf6 100%)',
        'desc' => 'अंकगणित, बुद्धिमत्ता चाचणी, तार्किक विश्लेषण व निर्णय क्षमता'
    ],
    'History' => [
        'title_mr' => 'इतिहास',
        'icon' => 'fa-solid fa-scroll',
        'badge_color' => '#a855f7',
        'gradient' => 'linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%)',
        'desc' => 'आधुनिक भारताचा इतिहास, महाराष्ट्रातील समाजसुधारक व ऐतिहासिक घडामोडी'
    ],
    'Geography' => [
        'title_mr' => 'भूगोल',
        'icon' => 'fa-solid fa-earth-asia',
        'badge_color' => '#10b981',
        'gradient' => 'linear-gradient(135deg, #059669 0%, #0d9488 100%)',
        'desc' => 'भारताचा व महाराष्ट्राचा प्राकृतिक, सामाजिक व आर्थिक भूगोल'
    ],
    'Polity' => [
        'title_mr' => 'राज्यशास्त्र व राज्यघटना',
        'icon' => 'fa-solid fa-scale-balanced',
        'badge_color' => '#3b82f6',
        'gradient' => 'linear-gradient(135deg, #2563eb 0%, #0284c7 100%)',
        'desc' => 'भारतीय संविधान, संघराज्य, विधिमंडळ, न्यायव्यवस्था व पंचायत राज'
    ],
    'Economics' => [
        'title_mr' => 'अर्थव्यवस्था',
        'icon' => 'fa-solid fa-chart-line',
        'badge_color' => '#f59e0b',
        'gradient' => 'linear-gradient(135deg, #d97706 0%, #ea580c 100%)',
        'desc' => 'भारतीय अर्थव्यवस्था, पंचवार्षिक योजना, पायाभूत सुविधा व बँकिंग'
    ],
    'Science & Technology' => [
        'title_mr' => 'सामान्य विज्ञान व तंत्रज्ञान',
        'icon' => 'fa-solid fa-flask-vial',
        'badge_color' => '#06b6d4',
        'gradient' => 'linear-gradient(135deg, #0891b2 0%, #2563eb 100%)',
        'desc' => 'जीवशास्त्र, प्राणीशास्त्र, रसायनशास्त्र, भौतिकशास्त्र आणि संगणक व माहिती तंत्रज्ञान'
    ],
    'Marathi' => [
        'title_mr' => 'मराठी व्याकरण',
        'icon' => 'fa-solid fa-book-open-reader',
        'badge_color' => '#ec4899',
        'gradient' => 'linear-gradient(135deg, #db2777 0%, #e11d48 100%)',
        'desc' => 'वर्णमाला, शब्दांच्या जाती, लिंग, वचन, विभक्ती, समास, संधी, समानार्थी व विरुद्धार्थी'
    ],
    'General Mental Ability' => [
        'title_mr' => 'सामान्य मानसिक क्षमता व बुद्धिमत्ता',
        'icon' => 'fa-solid fa-brain',
        'badge_color' => '#8b5cf6',
        'gradient' => 'linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)',
        'desc' => 'संख्यात्मक अभियोग्यता (अंकगणित), तार्किक विचार व गैर-शाब्दिक बुद्धिमत्ता चाचणी'
    ],
    'General Knowledge' => [
        'title_mr' => 'महाराष्ट्र व सामान्य ज्ञान',
        'icon' => 'fa-solid fa-landmark',
        'badge_color' => '#0284c7',
        'gradient' => 'linear-gradient(135deg, #0284c7 0%, #0369a1 100%)',
        'desc' => 'महाराष्ट्र विशेष, प्रसिद्ध व्यक्ती, ठिकाणे, पुरस्कार व विविध संकीर्ण माहिती'
    ],
    'Maharashtra Special' => [
        'title_mr' => 'महाराष्ट्र विशेष (स्थापना दिवस)',
        'icon' => 'fa-solid fa-fort-awesome',
        'badge_color' => '#d97706',
        'gradient' => 'linear-gradient(135deg, #d97706 0%, #b45309 100%)',
        'desc' => 'महाराष्ट्रातील गड-किल्ले, संत परंपरा, पेशवे, स्वराज्य निर्मिती व सांस्कृतिक वारसा'
    ],
    'Previous Year Papers' => [
        'title_mr' => 'मागील वर्षांच्या प्रश्नपत्रिका (PYQ)',
        'icon' => 'fa-solid fa-box-archive',
        'badge_color' => '#6366f1',
        'gradient' => 'linear-gradient(135deg, #4f46e5 0%, #6366f1 100%)',
        'desc' => 'तलाठी भरती २०१९, पोलीस भरती व MPSC दुय्यम सेवा मुख्य अधिकृत पेपर्स'
    ],
    'Full Length Tests' => [
        'title_mr' => 'संपूर्ण सराव चाचण्या (Full Mock)',
        'icon' => 'fa-solid fa-file-signature',
        'badge_color' => '#8b5cf6',
        'gradient' => 'linear-gradient(135deg, #7c3aed 0%, #6366f1 100%)',
        'desc' => 'General Studies for Maharashtra Exams संपूर्ण १०० प्रश्न सराव चाचण्या'
    ]
];

// Comprehensive Bilingual Topic Dictionary (All 61 Topics)
$topicTranslations = [
    // Economics
    'Banking' => ['mr' => 'बँकिंग व वित्तीय संस्था', 'en' => 'Banking & Financial Institutions'],
    'Concepts of Economy' => ['mr' => 'अर्थव्यवस्थेच्या मूलभूत संकल्पना', 'en' => 'Concepts of Economy'],
    'Economic Reforms & Policies' => ['mr' => 'आर्थिक सुधारणा व नवीन धोरणे', 'en' => 'Economic Reforms & Policies'],
    'Infrastructure Development' => ['mr' => 'पायाभूत सुविधांचा विकास', 'en' => 'Infrastructure Development'],
    'Introduction of Indian Economy' => ['mr' => 'भारतीय अर्थव्यवस्थेचा परिचय', 'en' => 'Introduction of Indian Economy'],
    'Planning in Indian Economy' => ['mr' => 'भारतातील आर्थिक नियोजन व पंचवार्षिक योजना', 'en' => 'Planning in Indian Economy'],
    'Public Finance' => ['mr' => 'सार्वजनिक वित्त, कर व महसूल', 'en' => 'Public Finance & Taxation'],

    // Geography
    'Indian Geography' => ['mr' => 'भारताचा भूगोल', 'en' => 'Indian Geography'],
    'Maharashtra Geography' => ['mr' => 'महाराष्ट्राचा भूगोल', 'en' => 'Maharashtra Geography'],

    // History
    'Maharashtra History' => ['mr' => 'महाराष्ट्राचा इतिहास', 'en' => 'Maharashtra History'],
    'Modern History' => ['mr' => 'आधुनिक भारताचा इतिहास', 'en' => 'Modern History of India'],
    'Social Reformers' => ['mr' => 'महाराष्ट्रातील समाजसुधारक', 'en' => 'Social Reformers of Maharashtra'],

    // Marathi Grammar
    'अलंकार' => ['mr' => 'अलंकार व प्रकार', 'en' => 'Figures of Speech (Alankar)'],
    'काळ व काळाचे प्रकार' => ['mr' => 'काळ व काळाचे प्रकार', 'en' => 'Tenses & Types'],
    'प्रयोग' => ['mr' => 'प्रयोग विचार (कर्तरी, कर्मणी, भावे)', 'en' => 'Voice (Prayog)'],
    'मराठी वर्णमाला' => ['mr' => 'मराठी वर्णमाला व उच्चारस्थाने', 'en' => 'Marathi Alphabets'],
    'लिंग' => ['mr' => 'लिंग विचार', 'en' => 'Gender (Ling)'],
    'वचन' => ['mr' => 'वचन विचार', 'en' => 'Number (Vachan)'],
    'विरुद्धार्थी शब्द' => ['mr' => 'विरुद्धार्थी शब्द', 'en' => 'Antonyms'],
    'शब्दांचे प्रकार' => ['mr' => 'शब्दांच्या जाती व प्रकार', 'en' => 'Parts of Speech'],
    'संधी व संधीचे प्रकार' => ['mr' => 'संधी व संधीचे प्रकार', 'en' => 'Sandhi & Types'],
    'समानार्थी शब्द' => ['mr' => 'समानार्थी शब्द', 'en' => 'Synonyms'],
    'समास' => ['mr' => 'समास व प्रकार', 'en' => 'Compounds (Samas)'],

    // Polity
    'Citizenship' => ['mr' => 'नागरिकत्व व मूलभूत हक्क', 'en' => 'Citizenship & Fundamental Rights'],
    'Constitutional Amendments' => ['mr' => 'महत्त्वाच्या घटनादुरुस्त्या', 'en' => 'Constitutional Amendments'],
    'Executive' => ['mr' => 'कार्यकारी मंडळ (राष्ट्रपती, राज्यपाल, पंतप्रधान)', 'en' => 'Executive (President, Governor, PM)'],
    'Federalism' => ['mr' => 'संघराज्य व केंद्र-राज्य संबंध', 'en' => 'Federalism & Centre-State Relations'],
    'Governmental Organisations' => ['mr' => 'संवैधानिक व वैधानिक संस्था', 'en' => 'Governmental Organisations'],
    'Indian Constitution' => ['mr' => 'भारतीय संविधान व सरनामा', 'en' => 'Indian Constitution & Preamble'],
    'Judiciary' => ['mr' => 'न्यायव्यवस्था (सर्वोच्च व उच्च न्यायालय)', 'en' => 'Judiciary (Supreme Court & High Courts)'],
    'Legislative' => ['mr' => 'कायदेमंडळ (संसद व राज्य विधिमंडळ)', 'en' => 'Legislative (Parliament & State Legislature)'],
    'Maharashtra Lokseva Hakka Adhiniyam' => ['mr' => 'महाराष्ट्र लोकसेवा हक्क अधिनियम २०१५', 'en' => 'Maharashtra Lokseva Hakka Adhiniyam'],
    'Panchayat Raj' => ['mr' => 'पंचायत राज व स्थानिक स्वराज्य संस्था', 'en' => 'Panchayat Raj System'],
    'Right to Information Act' => ['mr' => 'माहितीचा अधिकार कायदा २००५', 'en' => 'Right to Information Act (RTI)'],
    'Socio-political issues' => ['mr' => 'सामाजिक-राजकीय चालू घडामोडी', 'en' => 'Socio-Political Issues'],

    // Science & Technology
    'Biology' => ['mr' => 'जीवशास्त्र व वनस्पती/प्राणीशास्त्र', 'en' => 'Biology & Life Sciences'],
    'Chemistry' => ['mr' => 'रसायनशास्त्र', 'en' => 'Chemistry'],
    'Computer & IT' => ['mr' => 'संगणक व माहिती तंत्रज्ञान', 'en' => 'Computer & Information Technology'],
    'Physics' => ['mr' => 'भौतिकशास्त्र', 'en' => 'Physics'],

    // General Mental Ability
    'Basics of Data Interpretation' => ['mr' => 'माहितीचे विश्लेषण (तक्ते व आलेख)', 'en' => 'Data Interpretation'],
    'Boat, Streams & Problems on Trains' => ['mr' => 'बोट, प्रवाह आणि रेल्वेवरील उदाहरणे', 'en' => 'Boat, Streams & Trains'],
    'Clocks & Calendars' => ['mr' => 'घड्याळ आणि दिनदर्शिका (कॅलेंडर)', 'en' => 'Clocks & Calendars'],
    'Figure Counting' => ['mr' => 'आकृत्यांची संख्या मोजणे', 'en' => 'Figure Counting'],
    'General Mental Ability - Numeracy' => ['mr' => 'संख्यात्मक अभियोग्यता व अंकगणित', 'en' => 'Numeracy & Mental Ability'],
    'Images' => ['mr' => 'प्रतिमा - आरसा व जलप्रतिमा', 'en' => 'Mirror & Water Images'],
    'Mixture & Alligation' => ['mr' => 'मिश्रण आणि प्रमाण', 'en' => 'Mixture & Alligation'],
    'Number Alphabet & Mixed Series' => ['mr' => 'संख्या व अक्षर मालिका', 'en' => 'Number & Alphabet Series'],
    'Ordering & Ranking' => ['mr' => 'क्रमवारी व स्थान निश्चिती', 'en' => 'Ordering & Ranking'],
    'Paper Cutting & Folding' => ['mr' => 'कागदाची घडी व कापणे', 'en' => 'Paper Cutting & Folding'],
    'Permutation, Combination & Probability' => ['mr' => 'क्रमपरिवर्तन, संचय व संभाव्यता', 'en' => 'Permutation, Combination & Probability'],
    'Pipes & Cisterns' => ['mr' => 'नळ आणि पाण्याची टाकी', 'en' => 'Pipes & Cisterns'],
    'Problems Based on Ages' => ['mr' => 'वयवारी आधारित उदाहरणे', 'en' => 'Problems Based on Ages'],
    'Problems of Blood Relations' => ['mr' => 'नातेसंबंधांवरील प्रश्न', 'en' => 'Problems of Blood Relations'],
    'Profit & Loss' => ['mr' => 'नफा, तोटा व सूट', 'en' => 'Profit & Loss'],
    'Puzzles' => ['mr' => 'तार्किक कोडी व बैठक व्यवस्था', 'en' => 'Puzzles & Seating Arrangement'],
    'Quadratic Equations & Basics of Algebra' => ['mr' => 'वर्गसमीकरणे व बीजगणित', 'en' => 'Quadratic Equations & Algebra'],
    'Rotations Turns & Shadows' => ['mr' => 'दिशा, वळणे व सावल्या', 'en' => 'Directions, Turns & Shadows'],
    'Speed Time & Distance' => ['mr' => 'वेग, वेळ आणि अंतर', 'en' => 'Speed, Time & Distance'],
    'Syllogisms' => ['mr' => 'तर्क व विधाने (सिलॉजिझम)', 'en' => 'Syllogisms'],
    'Time & Work' => ['mr' => 'काळ, काम आणि वेग', 'en' => 'Time & Work'],
    'Venn Diagram' => ['mr' => 'व्हेन आकृत्या', 'en' => 'Venn Diagram'],

    // Rajyaseva Topics
    'Physical Geography' => ['mr' => 'प्राकृतिक भूगोल', 'en' => 'Physical Geography'],
    'Biogeography' => ['mr' => 'जैव भूगोल', 'en' => 'Biogeography'],
    'Climatology' => ['mr' => 'हवामानशास्त्र', 'en' => 'Climatology'],
    'Geomorphology' => ['mr' => 'भूरूपशास्त्र', 'en' => 'Geomorphology'],
    'Oceanography' => ['mr' => 'समुद्रशास्त्र व जलमंडल', 'en' => 'Oceanography'],
    'World Geography' => ['mr' => 'जगाचा भूगोल', 'en' => 'World Geography'],
    'Human Geography' => ['mr' => 'मानवी भूगोल', 'en' => 'Human Geography'],
    'Ancient History' => ['mr' => 'प्राचीन भारताचा इतिहास', 'en' => 'Ancient History'],
    'Medieval India' => ['mr' => 'मध्ययुगीन भारताचा इतिहास', 'en' => 'Medieval India'],
    'Modern India' => ['mr' => 'आधुनिक भारताचा इतिहास', 'en' => 'Modern India'],
    'Post Independence' => ['mr' => 'स्वातंत्र्योत्तर भारत', 'en' => 'Post Independence India'],
    'World History' => ['mr' => 'जगाचा इतिहास', 'en' => 'World History'],
    'Art & Culture' => ['mr' => 'कला व संस्कृती', 'en' => 'Art & Culture'],
    'Polity' => ['mr' => 'भारतीय राज्यघटना व राज्यव्यवस्था', 'en' => 'Indian Polity & Governance'],
    'International Relations' => ['mr' => 'आंतरराष्ट्रीय संबंध व संस्था', 'en' => 'International Relations'],
    'Economy' => ['mr' => 'भारतीय अर्थव्यवस्था व विकास', 'en' => 'Indian Economy & Development'],
    'Environment & Ecology' => ['mr' => 'पर्यावरण आणि परिसंस्था', 'en' => 'Environment & Ecology'],
    'Reading Comprehension' => ['mr' => 'उतारा वाचन व आकलन', 'en' => 'Reading Comprehension'],
    'Active Passive Voice' => ['mr' => 'प्रयोग (Active & Passive Voice)', 'en' => 'Active & Passive Voice'],
    'Direct Indirect Speech' => ['mr' => 'प्रत्यक्ष व अप्रत्यक्ष कथन', 'en' => 'Direct & Indirect Speech'],
    'Error Spotting' => ['mr' => 'वाक्य दुरुस्ती व त्रुटी शोधणे', 'en' => 'Error Spotting'],
    'Fillers' => ['mr' => 'योग्य शब्द भरा (Fillers)', 'en' => 'Sentence Fillers'],
    'Idioms & Phrases' => ['mr' => 'वाक्प्रचार व म्हणी', 'en' => 'Idioms & Phrases'],
    'One Word Substitution' => ['mr' => 'शब्दसमूहाबद्दल एक शब्द', 'en' => 'One Word Substitution'],
    'Synonyms & Antonyms' => ['mr' => 'समानार्थी व विरुद्धार्थी शब्द', 'en' => 'Synonyms & Antonyms'],
    'Adjectives & Adverbs' => ['mr' => 'विशेषण व क्रियाविशेषण', 'en' => 'Adjectives & Adverbs'],
    'Articles' => ['mr' => 'उपपदे (Articles - A, An, The)', 'en' => 'Articles'],
    'Conjunction' => ['mr' => 'उभयान्वयी अव्यये', 'en' => 'Conjunctions'],
    'Noun' => ['mr' => 'नाम (Noun)', 'en' => 'Nouns'],
    'Parts of Speech' => ['mr' => 'शब्दांच्या जाती', 'en' => 'Parts of Speech'],
    'Preposition' => ['mr' => 'शब्दयोगी अव्यये', 'en' => 'Prepositions'],
    'Pronoun' => ['mr' => 'सर्वनाम (Pronoun)', 'en' => 'Pronouns'],
    'Subject Verb Agreement' => ['mr' => 'कर्ता व क्रियापद समन्वय', 'en' => 'Subject Verb Agreement'],
    'Tenses' => ['mr' => 'काळ व प्रकार', 'en' => 'Tenses'],
    'Verbs' => ['mr' => 'क्रियापदे व रूपे', 'en' => 'Verbs & Forms'],
    'Ratio & Proportion' => ['mr' => 'गुणोत्तर व प्रमाण', 'en' => 'Ratio & Proportion'],
    'Statement & Assumption' => ['mr' => 'विधाने आणि गृहीतके', 'en' => 'Statements & Assumptions'],
    'Preview Class - CSAT' => ['mr' => 'CSAT पूर्वतयारी', 'en' => 'CSAT Preparatory'],
    'अलंकारिक शब्द' => ['mr' => 'अलंकारिक शब्द', 'en' => 'Metaphorical Words'],
    'काळ' => ['mr' => 'काळ व प्रकार', 'en' => 'Tenses'],
    'क्रियापद' => ['mr' => 'क्रियापद विचार', 'en' => 'Verbs'],
    'नाम' => ['mr' => 'नाम विचार', 'en' => 'Nouns'],
    'विशेषण' => ['mr' => 'विशेषण विचार', 'en' => 'Adjectives'],
    'सर्वनाम' => ['mr' => 'सर्वनाम विचार', 'en' => 'Pronouns'],
    'संधी' => ['mr' => 'संधी विचार', 'en' => 'Sandhi'],

];

function getBilingualTopic($topicName, $map) {
    if (isset($map[$topicName])) {
        return $map[$topicName];
    }
    return ['mr' => $topicName, 'en' => $topicName];
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX API HANDLERS
// ─────────────────────────────────────────────────────────────────────────────
$isAjaxRequest = (isset($_GET['ajax']) && $_GET['ajax'] === '1') || isset($_POST['action']) || (isset($_GET['action']) && in_array($_GET['action'], ['check_user_status', 'logout_user', 'get_questions', 'record_payment_intent', 'auth_google_user', 'track_engagement']));

if ($isAjaxRequest) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $pdo = getDBConnection();

    // 1. Get Questions for a Practice Set OR Entire Main Topic (All Subtopics included!)
    if ($action === 'get_questions') {
        $practiceId = trim($_GET['practice_id'] ?? '');
        $subjectName = trim($_GET['subject_name'] ?? '');
        $topicName = trim($_GET['topic_name'] ?? '');

        if (!empty($practiceId)) {
            // Specific practice set requested
            $stmt = $pdo->prepare("
                SELECT 
                    m.id as map_id,
                    m.practice_id,
                    m.question_order,
                    m.testbook_id,
                    m.subject_name,
                    m.chapter_name,
                    m.topic_name,
                    m.subtopic_name,
                    COALESCE(pq.question_mr, q.question_mr, '') as question_mr,
                    COALESCE(pq.question_en, q.question_en, '') as question_en,
                    COALESCE(pq.opt1_mr, q.opt1_mr, '') as opt1_mr,
                    COALESCE(pq.opt1_en, q.opt1_en, '') as opt1_en,
                    COALESCE(pq.opt2_mr, q.opt2_mr, '') as opt2_mr,
                    COALESCE(pq.opt2_en, q.opt2_en, '') as opt2_en,
                    COALESCE(pq.opt3_mr, q.opt3_mr, '') as opt3_mr,
                    COALESCE(pq.opt3_en, q.opt3_en, '') as opt3_en,
                    COALESCE(pq.opt4_mr, q.opt4_mr, '') as opt4_mr,
                    COALESCE(pq.opt4_en, q.opt4_en, '') as opt4_en,
                    COALESCE(pq.correct_option, q.correct_option, 0) as correct_option,
                    COALESCE(pq.solution_mr, q.solution_mr, '') as solution_mr,
                    COALESCE(pq.solution_en, q.solution_en, '') as solution_en,
                    COALESCE(pq.positive_marks, q.positive_marks, 1.0) as positive_marks,
                    COALESCE(pq.negative_marks, q.negative_marks, 0.0) as negative_marks
                FROM tbl_coaching_practice_map m
                LEFT JOIN tbl_coaching_practice_questions pq ON m.testbook_id = pq.testbook_id
                LEFT JOIN tbl_questions q ON m.testbook_id = q.testbook_id
                WHERE m.practice_id = ?
                ORDER BY m.question_order ASC
            ");
            $stmt->execute([$practiceId]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($subjectName) && !empty($topicName)) {
            // Main Topic Clicked: Fetch ALL questions across all subtopics belonging to this topic!
            $goalFilter = trim($_GET['goal'] ?? '');
            $sqlWhere = "WHERE m.subject_name = ? AND m.topic_name = ?";
            $params = [$subjectName, $topicName];
            if (!empty($goalFilter)) {
                $sqlWhere .= " AND m.goal_slug = ?";
                $params[] = $goalFilter;
            }

            $stmt = $pdo->prepare("
                SELECT 
                    m.id as map_id,
                    m.practice_id,
                    m.question_order,
                    m.testbook_id,
                    m.subject_name,
                    m.chapter_name,
                    m.topic_name,
                    m.subtopic_name,
                    COALESCE(pq.question_mr, q.question_mr, '') as question_mr,
                    COALESCE(pq.question_en, q.question_en, '') as question_en,
                    COALESCE(pq.opt1_mr, q.opt1_mr, '') as opt1_mr,
                    COALESCE(pq.opt1_en, q.opt1_en, '') as opt1_en,
                    COALESCE(pq.opt2_mr, q.opt2_mr, '') as opt2_mr,
                    COALESCE(pq.opt2_en, q.opt2_en, '') as opt2_en,
                    COALESCE(pq.opt3_mr, q.opt3_mr, '') as opt3_mr,
                    COALESCE(pq.opt3_en, q.opt3_en, '') as opt3_en,
                    COALESCE(pq.opt4_mr, q.opt4_mr, '') as opt4_mr,
                    COALESCE(pq.opt4_en, q.opt4_en, '') as opt4_en,
                    COALESCE(pq.correct_option, q.correct_option, 0) as correct_option,
                    COALESCE(pq.solution_mr, q.solution_mr, '') as solution_mr,
                    COALESCE(pq.solution_en, q.solution_en, '') as solution_en,
                    COALESCE(pq.positive_marks, q.positive_marks, 1.0) as positive_marks,
                    COALESCE(pq.negative_marks, q.negative_marks, 0.0) as negative_marks
                FROM tbl_coaching_practice_map m
                LEFT JOIN tbl_coaching_practice_questions pq ON m.testbook_id = pq.testbook_id
                LEFT JOIN tbl_questions q ON m.testbook_id = q.testbook_id
                $sqlWhere
                ORDER BY m.id ASC
            ");
            $stmt->execute($params);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'practice_id or (subject_name and topic_name) is required']);
            exit;
        }

        if (empty($questions)) {
            echo json_encode(['status' => 'error', 'message' => 'No questions found']);
            exit;
        }

        // Meta info from first row
        $rawTopic = $questions[0]['topic_name'];
        $bilingualTopic = getBilingualTopic($rawTopic, $topicTranslations);
        $isAllTopic = empty($practiceId);

        $meta = [
            'practice_id' => $practiceId,
            'is_all_topic' => $isAllTopic,
            'subject_name' => $questions[0]['subject_name'],
            'chapter_name' => $questions[0]['chapter_name'],
            'topic_name' => $rawTopic,
            'topic_name_mr' => $bilingualTopic['mr'],
            'topic_name_en' => $bilingualTopic['en'],
            'subtopic_name' => $isAllTopic ? 'सर्व सराव संच (' . count($questions) . ' प्रश्न)' : $questions[0]['subtopic_name'],
            'total_questions' => count($questions)
        ];

        echo json_encode([
            'status' => 'success',
            'meta' => $meta,
            'questions' => $questions
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Google / Email User Authentication
    if ($action === 'auth_google_user') {
        $email = trim($_POST['email'] ?? $_GET['email'] ?? '');
        $name = trim($_POST['name'] ?? $_GET['name'] ?? '');
        $credential = trim($_POST['credential'] ?? $_GET['credential'] ?? '');
        $picture = '';
        $googleId = '';

        if (!empty($credential)) {
            $parts = explode('.', $credential);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (!empty($payload['email'])) {
                    $email = $payload['email'];
                    $name = $payload['name'] ?? $name;
                    $picture = $payload['picture'] ?? '';
                    $googleId = $payload['sub'] ?? '';
                }
            }
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'कृपया योग्य ईमेल टाका (Valid email is required)']);
            exit;
        }

        // Upsert user into tbl_app_users
        $stmt = $pdo->prepare("
            INSERT INTO tbl_app_users (email, name, google_id, picture, last_active)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                name = COALESCE(VALUES(name), name),
                google_id = COALESCE(VALUES(google_id), google_id),
                picture = COALESCE(VALUES(picture), picture),
                last_active = NOW()
        ");
        $stmt->execute([$email, $name, $googleId, $picture]);

        $_SESSION['mpsc_user_email'] = $email;

        // Check if user has active subscription
        $subStmt = $pdo->prepare("SELECT * FROM tbl_app_subscriptions WHERE user_email = ? ORDER BY id DESC LIMIT 1");
        $subStmt->execute([$email]);
        $sub = $subStmt->fetch();
        $isSubscribed = false;
        $expiresAt = null;
        $hoursLeft = 0;
        $daysLeft = 0;
        if ($sub && $sub['status'] === 'active') {
            if (empty($sub['expires_at']) || strtotime($sub['expires_at']) > time()) {
                $isSubscribed = true;
                $expiresAt = $sub['expires_at'];
                if ($expiresAt) {
                    $secLeft = max(0, strtotime($expiresAt) - time());
                    $hoursLeft = max(1, ceil($secLeft / 3600));
                    $daysLeft = max(1, ceil($secLeft / 86400));
                }
            }
        }

        echo json_encode([
            'status' => 'success',
            'user' => [
                'email' => $email,
                'name' => $name,
                'picture' => $picture
            ],
            'is_subscribed' => $isSubscribed,
            'expires_at' => $expiresAt,
            'hours_left' => $hoursLeft,
            'days_left' => $daysLeft
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Record Razorpay Payment Intent
    if ($action === 'record_payment_intent') {
        $email = trim($_POST['email'] ?? $_GET['email'] ?? '');
        $name = trim($_POST['name'] ?? $_GET['name'] ?? '');
        $phone = trim($_POST['phone'] ?? $_GET['phone'] ?? '');
        $planPrice = defined('DAILY_PASS_PRICE') ? DAILY_PASS_PRICE : 10.00;

        if (!empty($email)) {
            $pdo->prepare("
                INSERT INTO tbl_app_subscriptions (user_email, plan_name, amount, status, notes)
                VALUES (?, 'Daily Pass (1 Day)', ?, 'pending', ?)
            ")->execute([$email, $planPrice, "Razorpay UPI Payment Initiated for ₹10 Daily Pass. Phone: " . $phone . ", Name: " . $name]);
        }

        $paymentUrl = defined('RAZORPAY_PAYMENT_URL') && !empty(RAZORPAY_PAYMENT_URL) ? RAZORPAY_PAYMENT_URL : 'https://rzp.io/rzp/FTFoJWx';
        echo json_encode([
            'status' => 'success',
            'payment_url' => $paymentUrl
        ]);
        exit;
    }

    // 4. Check Current Subscription Status
    if ($action === 'check_user_status') {
        $email = trim($_GET['email'] ?? $_SESSION['mpsc_user_email'] ?? '');
        $isSubscribed = false;
        $expiresAt = null;
        $hoursLeft = 0;
        $daysLeft = 0;

        if (!empty($email)) {
            $subStmt = $pdo->prepare("SELECT * FROM tbl_app_subscriptions WHERE user_email = ? ORDER BY id DESC LIMIT 1");
            $subStmt->execute([$email]);
            $sub = $subStmt->fetch();
            if ($sub && $sub['status'] === 'active') {
                if (empty($sub['expires_at']) || strtotime($sub['expires_at']) > time()) {
                    $isSubscribed = true;
                    $expiresAt = $sub['expires_at'];
                    if ($expiresAt) {
                        $secLeft = max(0, strtotime($expiresAt) - time());
                        $hoursLeft = max(1, ceil($secLeft / 3600));
                        $daysLeft = max(1, ceil($secLeft / 86400));
                    }
                }
            }
        }

        echo json_encode([
            'status' => 'success',
            'email' => $email,
            'is_subscribed' => $isSubscribed,
            'expires_at' => $expiresAt,
            'hours_left' => $hoursLeft,
            'days_left' => $daysLeft
        ]);
        exit;
    }

    // 5. User Logout
    if ($action === 'logout_user') {
        unset($_SESSION['mpsc_user_email']);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // 6. Real-time Visitor & Time Spent Engagement Tracker
    if ($action === 'track_engagement') {
        $delta = min(60, max(1, intval($_POST['delta_seconds'] ?? $_GET['delta_seconds'] ?? 15)));
        $clientIp = getClientIP();
        $email = trim($_POST['email'] ?? $_GET['email'] ?? $_SESSION['mpsc_user_email'] ?? '');
        $device = trim($_POST['device'] ?? 'Desktop');

        try {
            $stmt = $pdo->prepare("
                INSERT INTO tbl_visitor_analytics (ip_address, user_email, device_type, total_seconds, questions_viewed, first_seen, last_seen)
                VALUES (?, ?, ?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    user_email = COALESCE(NULLIF(VALUES(user_email), ''), user_email),
                    device_type = VALUES(device_type),
                    total_seconds = total_seconds + VALUES(total_seconds),
                    last_seen = NOW()
            ");
            $stmt->execute([$clientIp, !empty($email) ? $email : null, $device, $delta]);

            if (!empty($email)) {
                $pdo->prepare("
                    UPDATE tbl_visitor_analytics v
                    JOIN tbl_app_users u ON v.user_email = u.email
                    SET v.user_name = u.name
                    WHERE v.user_email = ?
                ")->execute([$email]);
            }
        } catch (Exception $e) {}

        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// INITIAL PAGE LOAD DATA & SUBJECT ACCORDION TREE PRE-FETCH
// ─────────────────────────────────────────────────────────────────────────────
$pdo = getDBConnection();

// Fetch summary per subject for current goal
$stmtStats = $pdo->prepare("
    SELECT 
        subject_name,
        COUNT(DISTINCT topic_name) as total_topics,
        COUNT(DISTINCT practice_id) as total_sets,
        COUNT(id) as total_questions
    FROM tbl_coaching_practice_map
    WHERE goal_slug = ?
    GROUP BY subject_name
    ORDER BY total_questions DESC
");
$stmtStats->execute([$currentGoal]);
$subjectStats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

$totalAllQuestions = 0;
$totalAllSets = 0;
$totalAllTopics = 0;
foreach ($subjectStats as $st) {
    $totalAllQuestions += intval($st['total_questions']);
    $totalAllSets += intval($st['total_sets']);
    $totalAllTopics += intval($st['total_topics']);
}

// Fetch complete tree for current goal (subjects -> topics -> practice sets)
$stmtTree = $pdo->prepare("
    SELECT 
        subject_name,
        chapter_name,
        topic_name,
        subtopic_name,
        practice_id,
        COUNT(id) as question_count
    FROM tbl_coaching_practice_map
    WHERE goal_slug = ?
    GROUP BY subject_name, chapter_name, topic_name, subtopic_name, practice_id
    ORDER BY subject_name ASC, chapter_name ASC, topic_name ASC, MIN(question_order) ASC, practice_id ASC
");
$stmtTree->execute([$currentGoal]);
$treeRows = $stmtTree->fetchAll(PDO::FETCH_ASSOC);

$fullSubjectTree = [];
foreach ($treeRows as $r) {
    $s = $r['subject_name'];
    $t = $r['topic_name'];
    if (!isset($fullSubjectTree[$s])) {
        $fullSubjectTree[$s] = [
            'subject_name' => $s,
            'topics' => [],
            'total_questions' => 0,
            'total_sets' => 0
        ];
    }
    if (!isset($fullSubjectTree[$s]['topics'][$t])) {
        $bilingual = getBilingualTopic($t, $topicTranslations);
        $fullSubjectTree[$s]['topics'][$t] = [
            'topic_name' => $t,
            'topic_name_mr' => $bilingual['mr'],
            'topic_name_en' => $bilingual['en'],
            'chapter_name' => $r['chapter_name'],
            'total_questions' => 0,
            'sets' => []
        ];
    }
    $qc = intval($r['question_count']);
    $fullSubjectTree[$s]['total_questions'] += $qc;
    $fullSubjectTree[$s]['total_sets'] += 1;
    $fullSubjectTree[$s]['topics'][$t]['total_questions'] += $qc;
    $fullSubjectTree[$s]['topics'][$t]['sets'][] = [
        'practice_id' => $r['practice_id'],
        'subtopic_name' => $r['subtopic_name'],
        'question_count' => $qc
    ];
}

// Determine default initial selection (Subject, Topic)
$defaultSubject = $_GET['subject'] ?? ($activeGoalInfo['default_subject'] ?? 'History');
if (!isset($fullSubjectTree[$defaultSubject])) {
    $defaultSubject = array_key_first($fullSubjectTree) ?? 'History';
}
$firstTopicName = array_key_first($fullSubjectTree[$defaultSubject]['topics'] ?? []);
$firstTopicData = $fullSubjectTree[$defaultSubject]['topics'][$firstTopicName] ?? null;

$initialSubject = $defaultSubject;
$initialTopic = $firstTopicName ?? '';
$initialTopicMr = $firstTopicData['topic_name_mr'] ?? $initialTopic;
$initialTopicEn = $firstTopicData['topic_name_en'] ?? $initialTopic;
$initialPracticeId = $_GET['practice_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="mr" class="notranslate" translate="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="google" content="notranslate">
    <meta name="googlebot" content="notranslate">
    <title>MPSC Abhyas | एमपीएससी अभ्यास</title>
    
    <!-- PWA & Mobile Web App Manifest -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#111827">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MPSC अभ्यास">
    <link rel="apple-touch-icon" href="icon-192.png">
    <link rel="icon" type="image/png" sizes="192x192" href="icon-192.png">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Noto+Sans+Devanagari:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&family=Fira+Code:wght@400;600&display=swap" rel="stylesheet">
    
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Identity Services for Google One-Tap Login -->
    <script src="https://accounts.google.com/gsi/client" async defer></script>

    <!-- Canvas Confetti for celebration effect -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

    <!-- MathJax for formula rendering -->
    <script>
        window.MathJax = {
            tex: { inlineMath: [['$', '$'], ['\\(', '\\)']] },
            svg: { fontCache: 'global' }
        };
    </script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

    <style>
        :root {
            --bg-body: #0b0f19;
            --bg-surface: #111827;
            --bg-card: #162032;
            --bg-card-hover: #1e293b;
            --primary: #3b82f6;
            --primary-glow: rgba(59, 130, 246, 0.35);
            --accent-emerald: #10b981;
            --accent-rose: #ef4444;
            --accent-amber: #f59e0b;
            --border-color: rgba(255, 255, 255, 0.08);
            --border-highlight: rgba(59, 130, 246, 0.4);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
        }

        /* ─── Remove Vertical Scrollbar Strip Completely ─── */
        ::-webkit-scrollbar {
            width: 0px !important;
            height: 0px !important;
            background: transparent !important;
            display: none !important;
        }

        html, body, .app-container, .main-panel, .sidebar, .sidebar-accordion, .deck-stream-body {
            -ms-overflow-style: none !important;  /* IE and Edge */
            scrollbar-width: none !important;     /* Firefox */
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', 'Noto Sans Devanagari', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
        }

        /* ─── Top Navbar (Position relative: shifts upward out of view on scroll) ─── */
        .navbar {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-color);
            position: relative;
            z-index: 100;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: nowrap;
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            flex: 1 1 auto;
        }

        .btn-sidebar-toggle {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .btn-sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.2);
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #fff;
            min-width: 0;
            overflow: hidden;
        }

        .nav-brand-logo {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #fff;
            box-shadow: 0 4px 14px rgba(59, 130, 246, 0.4);
            flex-shrink: 0;
        }

        .nav-brand-text {
            min-width: 0;
            overflow: hidden;
        }

        .nav-brand-text h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 17px;
            font-weight: 700;
            line-height: 1.2;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .nav-brand-text p {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .nav-badge-goal {
            background: rgba(59, 130, 246, 0.12);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }

        .nav-right {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
        }

        /* ─── App Container (Full Width For Maximum Reading Space) ─── */
        .app-container {
            display: block;
            width: 100%;
            min-height: calc(100vh - 65px);
            position: relative;
        }

        /* ─── Extreme Left Hover Trigger Zone (Invisible Edge Sensor) ─── */
        .sidebar-edge-trigger {
            position: fixed;
            top: 0;
            left: 0;
            width: 18px;
            height: 100vh;
            z-index: 998;
            background: transparent;
            cursor: pointer;
        }

        .sidebar-edge-trigger::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 3px;
            height: 100%;
            background: linear-gradient(to bottom, transparent 10%, rgba(59, 130, 246, 0.5) 50%, transparent 90%);
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .sidebar-edge-trigger:hover::after {
            opacity: 1;
        }

        /* ─── Mobile Sidebar Overlay Backdrop ─── */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.25s ease;
            pointer-events: none;
        }

        .sidebar-backdrop.active {
            display: block;
            opacity: 1;
            pointer-events: auto;
        }

        /* ─── Left Sidebar Overlay Drawer (Hidden by default, slides in on extreme left hover) ─── */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 350px;
            height: 100vh;
            background: rgba(15, 23, 42, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: none;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            transform: translateX(-100%);
            transition: transform 0.26s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.26s ease, box-shadow 0.26s ease;
            overflow-y: auto;
            visibility: hidden;
            pointer-events: none;
        }

        .sidebar.open {
            transform: translateX(0);
            box-shadow: 14px 0 45px rgba(0, 0, 0, 0.7);
            visibility: visible;
            pointer-events: auto;
        }

        .sidebar-header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
            background: rgba(17, 24, 39, 0.95);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .sidebar-title-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .sidebar-title-label {
            font-size: 12px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-sidebar-close {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }

        .btn-sidebar-close:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.4);
        }

        .sidebar-stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            background: rgba(255, 255, 255, 0.02);
            padding: 8px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .stat-item .num {
            font-size: 14px;
            font-weight: 800;
            color: #fff;
            font-family: 'Outfit', sans-serif;
        }

        .stat-item .label {
            font-size: 9.5px;
            color: var(--text-dim);
            text-transform: uppercase;
            font-weight: 600;
            margin-top: 2px;
        }

        .sidebar-search-box {
            margin-top: 10px;
            position: relative;
        }

        .sidebar-search-input {
            width: 100%;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: #fff;
            padding: 8px 12px 8px 32px;
            border-radius: 8px;
            font-size: 12.5px;
            outline: none;
            transition: border-color 0.2s;
        }

        .sidebar-search-input:focus {
            border-color: var(--primary);
        }

        .sidebar-search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-dim);
            font-size: 12px;
        }

        /* ─── Left Sidebar Accordion Tree ─── */
        .sidebar-accordion {
            flex: 1;
            padding: 10px 8px 24px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .accordion-subject-group {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.02);
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .accordion-subject-group.expanded {
            border-color: rgba(59, 130, 246, 0.35);
            background: rgba(17, 24, 39, 0.7);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
        }

        .accordion-subject-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            cursor: pointer;
            user-select: none;
            transition: background 0.2s ease;
        }

        .accordion-subject-header:hover {
            background: rgba(255, 255, 255, 0.04);
        }

        .accordion-subject-group.expanded .accordion-subject-header {
            background: rgba(59, 130, 246, 0.08);
        }

        .subj-icon-box {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #fff;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .subj-info {
            flex: 1;
            min-width: 0;
        }

        .subj-title-en {
            font-size: 12.5px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #f1f5f9;
        }

        .subj-title-mr {
            font-size: 11px;
            color: var(--text-dim);
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .accordion-subject-group.expanded .subj-title-en {
            color: #93c5fd;
        }

        .subj-meta-end {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .subj-badge {
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-muted);
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
        }

        .accordion-subject-group.expanded .subj-badge {
            background: rgba(59, 130, 246, 0.25);
            color: #93c5fd;
        }

        .accordion-arrow {
            font-size: 11px;
            color: var(--text-dim);
            transition: transform 0.25s ease;
        }

        .accordion-subject-group.expanded .accordion-arrow {
            transform: rotate(180deg);
            color: #60a5fa;
        }

        .accordion-subject-body {
            padding: 8px 10px 10px 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .accordion-topic-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        /* Bilingual Topic Title Bar in Left Drawer (Clickable to show all subtopic questions) */
        .topic-title-bar {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 12px;
            padding: 6px 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
            border-left: 3px solid transparent;
        }

        .topic-title-bar:hover {
            background: rgba(255, 255, 255, 0.05);
            border-left-color: rgba(59, 130, 246, 0.4);
        }

        .topic-title-bar.active-topic {
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.22) 0%, rgba(59, 130, 246, 0.06) 100%);
            border-left-color: #3b82f6;
        }

        .topic-icon {
            font-size: 11.5px;
            color: #38bdf8;
            margin-top: 3px;
            flex-shrink: 0;
        }

        .topic-name-wrap {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 1px;
        }

        .topic-name-mr {
            font-size: 12.5px;
            font-weight: 700;
            color: #f1f5f9;
            line-height: 1.35;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .topic-name-en {
            font-size: 10.5px;
            font-weight: 500;
            color: #94a3b8;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .topic-title-bar:hover .topic-name-mr {
            color: #fff;
        }

        .topic-title-bar:hover .topic-name-en {
            color: #60a5fa;
        }

        .topic-title-bar.active-topic .topic-name-mr {
            color: #60a5fa;
        }

        .topic-total-badge {
            font-size: 10px;
            font-weight: 700;
            color: #93c5fd;
            background: rgba(59, 130, 246, 0.18);
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: auto;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .topic-title-bar.active-topic .topic-total-badge {
            background: #3b82f6;
            color: #fff;
        }

        .sets-sublist {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding-left: 10px;
            margin-left: 5px;
            margin-top: 2px;
        }

        .accordion-set-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 5px 8px;
            border-radius: 6px;
            font-size: 11.5px;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            user-select: none;
        }

        .accordion-set-link:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #fff;
            transform: translateX(2px);
        }

        .accordion-set-link.active {
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.22) 0%, rgba(59, 130, 246, 0.08) 100%);
            color: #60a5fa;
            font-weight: 700;
            border-left: 3px solid #3b82f6;
            padding-left: 6px;
        }

        .set-bullet {
            font-size: 8px;
            color: var(--text-dim);
            flex-shrink: 0;
        }

        .accordion-set-link.active .set-bullet {
            color: #3b82f6;
        }

        .set-link-name {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .set-link-count {
            font-size: 10px;
            color: var(--text-dim);
            background: rgba(255, 255, 255, 0.05);
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .accordion-set-link.active .set-link-count {
            background: rgba(59, 130, 246, 0.3);
            color: #bfdbfe;
        }

        /* ─── Main Content Area (100% Full Screen Width) ─── */
        .main-panel {
            width: 100%;
            background: var(--bg-body);
            position: relative;
            display: flex;
            flex-direction: column;
        }

        /* ─── Practice Deck View (Direct Questions Stream) ─── */
        .practice-deck-view {
            display: flex;
            flex: 1;
            flex-direction: column;
            width: 100%;
            background: var(--bg-body);
        }

        /* ─── Deck Header (Position relative: naturally shifts upward with page on scroll) ─── */
        .deck-header {
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            gap: 12px;
            flex-wrap: wrap;
        }

        .deck-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .deck-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            color: var(--text-muted);
            flex-wrap: wrap;
        }

        .deck-breadcrumb .sep {
            color: var(--text-dim);
        }

        .crumb-clickable {
            cursor: pointer;
            transition: color 0.2s;
            font-weight: 600;
        }

        .crumb-clickable:hover {
            color: #60a5fa;
            text-decoration: underline;
        }

        .crumb-mr {
            color: #f8fafc;
            font-weight: 700;
        }

        .crumb-en {
            color: #93c5fd;
            font-size: 11.5px;
            font-weight: 500;
            margin-left: 3px;
        }

        .deck-breadcrumb .active-title {
            color: #fff;
            font-weight: 700;
        }

        .deck-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .deck-score-badge {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-finish-deck {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: #fff;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            transition: all 0.2s ease;
        }

        .btn-finish-deck:hover {
            filter: brightness(1.15);
            transform: translateY(-1px);
        }

        /* ─── Scrollable Questions Feed (Spacious Full Width Layout) ─── */
        .deck-stream-body {
            flex: 1;
            width: 100%;
        }

        .deck-stream-layout {
            max-width: 1180px;
            width: 95%;
            margin: 0 auto;
            padding: 24px 0 80px;
        }

        .deck-questions-col {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* ─── Question Card (Exact UI Matching Screenshot) ─── */
        .question-card {
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            padding: 22px 26px;
            transition: transform 0.15s ease, border-color 0.15s ease;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .question-card:hover {
            border-color: rgba(59, 130, 246, 0.4);
        }

        .q-subtopic-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 600;
            color: #93c5fd;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.25);
            padding: 3px 9px;
            border-radius: 6px;
        }

        .q-title {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.6;
            color: #f8fafc;
        }

        .q-title-en {
            font-size: 14px;
            color: #94a3b8;
            margin-top: 6px;
            margin-bottom: 14px;
            line-height: 1.5;
            border-left: 3px solid #2563eb;
            padding-left: 10px;
        }

        .btn-google-ai-search {
            background: rgba(66, 133, 244, 0.12);
            border: 1px solid rgba(66, 133, 244, 0.35);
            color: #60a5fa;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            flex-shrink: 0;
            text-decoration: none;
            vertical-align: middle;
        }

        .btn-google-ai-search:hover {
            background: rgba(66, 133, 244, 0.28);
            border-color: #3b82f6;
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(66, 133, 244, 0.35);
        }

        /* ─── Options List with Dual Language Support ─── */
        .options-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 12px;
        }

        .option-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 18px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            font-size: 14px;
            color: #e2e8f0;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
        }

        .option-item.interactive {
            cursor: pointer;
        }

        .option-item.interactive:hover {
            background: rgba(59, 130, 246, 0.1);
            border-color: rgba(59, 130, 246, 0.4);
            transform: translateX(4px);
        }

        .opt-num {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            color: #cbd5e1;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }

        .option-item.interactive:hover .opt-num {
            background: var(--primary);
            color: #fff;
        }

        .opt-text-container {
            flex: 1;
            min-width: 0;
            line-height: 1.45;
        }

        .opt-text-mr {
            font-size: 14.5px;
            color: #f1f5f9;
            font-weight: 500;
        }

        .opt-text-en {
            font-size: 12.5px;
            color: #94a3b8;
            margin-top: 2px;
        }

        /* Option Verification States */
        .option-item.user-correct {
            background: rgba(16, 185, 129, 0.2) !important;
            border-color: #10b981 !important;
            box-shadow: 0 0 16px rgba(16, 185, 129, 0.35);
            animation: pulseSuccess 0.5s ease;
        }

        .option-item.user-correct .opt-num {
            background: #10b981;
            color: #fff;
        }

        .option-item.user-wrong {
            background: rgba(239, 68, 68, 0.2) !important;
            border-color: #ef4444 !important;
            box-shadow: 0 0 16px rgba(239, 68, 68, 0.35);
            animation: shakeError 0.4s ease;
        }

        .option-item.user-wrong .opt-num {
            background: #ef4444;
            color: #fff;
        }

        .option-item.correct {
            background: rgba(16, 185, 129, 0.15) !important;
            border-color: #10b981 !important;
            font-weight: 600;
        }

        .option-item.correct .opt-num {
            background: #10b981;
            color: #fff;
        }

        .option-item.correct .opt-text-mr { color: #34d399 !important; }
        .option-item.correct .opt-text-en { color: #6ee7b7 !important; }

        .option-item.dimmed {
            opacity: 0.5;
            cursor: default;
        }

        .status-icon {
            margin-left: auto;
            font-size: 15px;
        }

        .status-icon.correct-icon { color: #10b981; }
        .status-icon.wrong-icon { color: #ef4444; }

        /* Attempt Feedback Badge */
        .attempt-feedback {
            margin-top: 10px;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 12px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: fadeIn 0.3s ease;
        }

        .attempt-feedback.success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }

        .attempt-feedback.error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        /* ─── Solution Action Bar & Language Switcher Pills ─── */
        .sol-action-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
            padding-top: 8px;
        }

        .sol-btn {
            background: rgba(59, 130, 246, 0.1);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .sol-btn:hover {
            background: rgba(59, 130, 246, 0.2);
            color: #fff;
        }

        .lang-switch-pills {
            display: inline-flex;
            align-items: center;
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 2px;
            gap: 2px;
        }

        .lang-pill {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .lang-pill:hover {
            color: #fff;
        }

        .lang-pill.active {
            background: #3b82f6;
            color: #fff;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
        }

        /* ─── Solution Explanation Box ─── */
        .sol-box {
            display: none;
            margin-top: 10px;
            padding: 16px;
            background: #1e293b;
            border-left: 3px solid #f59e0b;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.6;
            color: #cbd5e1;
        }

        .sol-box.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .sol-content.sol-hidden {
            display: none !important;
        }

        .sol-box img {
            max-width: 100%;
            height: auto;
            border-radius: 6px;
            margin: 8px 0;
        }

        .sol-box table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }

        .sol-box th, .sol-box td {
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 6px 10px;
            font-size: 13px;
        }

        .sol-box th {
            background: rgba(255, 255, 255, 0.05);
        }

        .sol-box ul, .sol-box ol {
            padding-left: 20px;
            margin: 8px 0;
        }

        .sol-box li {
            margin-bottom: 4px;
        }

        /* ─── Score Result Modal ─── */
        .modal-backdrop {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(10px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            width: 90%;
            max-width: 480px;
            padding: 32px;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
            animation: zoomIn 0.3s ease;
        }

        .modal-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #fff;
            margin: 0 auto 16px;
            box-shadow: 0 8px 24px rgba(16, 185, 129, 0.4);
        }

        .modal-card h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            color: #fff;
            margin-bottom: 6px;
        }

        .modal-card p {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        .score-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 28px;
        }

        .score-box {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px;
        }

        .score-box .num {
            font-size: 20px;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
        }

        .score-box .lbl {
            font-size: 11px;
            color: var(--text-dim);
            text-transform: uppercase;
            margin-top: 4px;
        }

        .score-box.green .num { color: #34d399; }
        .score-box.red .num { color: #f87171; }
        .score-box.blue .num { color: #60a5fa; }

        .modal-actions {
            display: flex;
            gap: 12px;
        }

        .modal-actions button {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-retake {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .btn-retake:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .btn-next-set {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: #fff;
        }

        .btn-next-set:hover {
            filter: brightness(1.15);
        }

        /* ─── Loading Spinner & Animations ─── */
        .loading-spinner-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px;
            gap: 16px;
            color: var(--text-muted);
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(59, 130, 246, 0.2);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes pulseSuccess { 0% { transform: scale(1); } 50% { transform: scale(1.02); } 100% { transform: scale(1); } }
        @keyframes shakeError { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-4px); } 40%, 80% { transform: translateX(4px); } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes zoomIn { from { opacity: 0; transform: scale(0.92); } to { opacity: 1; transform: scale(1); } }

        
        /* ─── Dual Exam Switcher Styles (Group C & Rajyaseva) ─── */
        .sidebar-exam-switcher {
            margin-bottom: 12px;
        }
        .exam-switcher-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .exam-switcher-pills {
            display: flex;
            gap: 6px;
            background: rgba(255, 255, 255, 0.04);
            padding: 3px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        .exam-pill {
            flex: 1;
            text-align: center;
            padding: 6px 8px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .exam-pill:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.06);
        }
        .exam-pill.active {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #fff;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
        }
        .nav-exam-tabs {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.04);
            padding: 4px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }
        .nav-exam-tab {
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .nav-exam-tab:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.06);
        }
        .nav-exam-tab.active {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #fff;
            box-shadow: 0 2px 10px rgba(59, 130, 246, 0.35);
        }
        /* ─── Comprehensive Mobile & Tablet Responsiveness (Preserving Desktop) ─── */
        @media (max-width: 900px) {
            .sidebar-edge-trigger {
                display: none !important;
                pointer-events: none !important;
                visibility: hidden !important;
            }
            .deck-stream-layout {
                width: 94%;
                padding: 16px 0 40px;
            }
            .sidebar {
                width: min(320px, 86vw);
            }
        }

        @media (max-width: 768px) {
            .nav-exam-tabs {
                display: none;
            }
            .navbar {
                padding: 10px 14px;
                gap: 8px;
            }
            .nav-left {
                gap: 8px;
                min-width: 0;
                flex: 1 1 auto;
            }
            .nav-brand {
                gap: 8px;
                min-width: 0;
            }
            .nav-brand-logo {
                width: 34px;
                height: 34px;
                font-size: 15px;
                border-radius: 8px;
                flex-shrink: 0;
            }
            .nav-brand-text {
                min-width: 0;
            }
            .nav-brand-text h1 {
                font-size: 15px;
                gap: 6px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .nav-badge-goal {
                font-size: 10px;
                padding: 2px 7px;
                flex-shrink: 0;
            }
            .nav-brand-text p {
                display: none;
            }
            .nav-right {
                flex-shrink: 0;
            }
            .nav-user-area {
                margin-left: 4px;
                gap: 6px;
                flex-shrink: 0;
                flex-wrap: nowrap;
            }
            .nav-sub-pill, .nav-trial-pill {
                padding: 4px 10px;
                font-size: 11px;
                flex-shrink: 0;
            }
            .nav-login-btn {
                padding: 4px 10px;
                font-size: 11px;
                flex-shrink: 0;
            }
            .nav-user-email-text {
                max-width: 85px;
            }
            .sidebar {
                width: min(320px, 88vw);
            }
        }

        @media (max-width: 520px) {
            .navbar {
                padding: 8px 10px;
                gap: 6px;
            }
            .btn-sidebar-toggle {
                width: 34px;
                height: 34px;
                font-size: 14px;
            }
            .nav-brand-logo {
                width: 30px;
                height: 30px;
                font-size: 13px;
            }
            .nav-brand-text h1 {
                font-size: 13px;
            }
            .nav-badge-goal {
                display: none;
            }
            .nav-user-area {
                gap: 5px;
                margin-left: 2px;
            }
            .nav-trial-pill {
                padding: 4px 7px;
                font-size: 11px;
                gap: 4px;
            }
            .nav-login-btn {
                padding: 4px 8px;
                font-size: 11px;
                gap: 4px;
            }
            .nav-sub-pill {
                padding: 4px 8px;
                font-size: 11px;
                gap: 4px;
            }
            .nav-user-email-text {
                max-width: 70px;
            }
        }

        @media (max-width: 360px) {
            .nav-brand-text {
                display: none;
            }
            .trial-label {
                display: none;
            }
            .nav-trial-pill {
                padding: 3px 6px;
                font-size: 10px;
            }
            .nav-login-btn {
                padding: 3px 6px;
                font-size: 10px;
            }
        }
            .sidebar-edge-trigger {
                display: none !important;
                pointer-events: none !important;
                visibility: hidden !important;
            }
            .exam-switcher-pills {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 4px;
            }
            .exam-pill {
                padding: 6px 4px;
                font-size: 11px;
            }
            .deck-header {
                padding: 10px 14px;
                gap: 8px;
            }
            .deck-breadcrumb {
                font-size: 11.5px;
                gap: 5px;
            }
            .crumb-en {
                display: none;
            }
            .deck-controls {
                width: 100%;
                justify-content: space-between;
                margin-top: 4px;
            }
            .deck-score-badge {
                padding: 4px 10px;
                font-size: 11.5px;
            }
            .btn-finish-deck {
                padding: 5px 12px;
                font-size: 11.5px;
            }
            .deck-stream-layout {
                width: 96%;
                padding: 12px 0 60px;
            }
            .deck-questions-col {
                gap: 14px;
            }
            .question-card {
                padding: 16px 14px;
                border-radius: 12px;
            }
            .q-header-meta {
                flex-wrap: wrap;
                gap: 6px;
                margin-bottom: 10px;
            }
            .q-num-badge {
                font-size: 11px;
                padding: 2px 7px;
            }
            .q-subtopic-tag {
                font-size: 10.5px;
                padding: 2px 7px;
                max-width: 180px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .btn-google-ai-search {
                padding: 3px 8px;
                font-size: 10px;
            }
            .q-title {
                font-size: 15px;
                line-height: 1.55;
            }
            .q-title-en {
                font-size: 13px;
                line-height: 1.45;
                margin-top: 5px;
                margin-bottom: 12px;
                padding-left: 8px;
            }
            .option-item {
                padding: 11px 13px;
                gap: 10px;
                font-size: 13.5px;
                border-radius: 10px;
            }
            .option-item.interactive:hover {
                transform: none;
            }
            .opt-num {
                width: 24px;
                height: 24px;
                font-size: 11px;
            }
            .opt-text-mr {
                font-size: 14px;
                line-height: 1.4;
            }
            .opt-text-en {
                font-size: 12px;
            }
            .sol-action-bar {
                margin-top: 8px;
                gap: 8px;
            }
            .sol-btn {
                padding: 5px 11px;
                font-size: 11px;
            }
            .lang-pill {
                padding: 3px 10px;
                font-size: 10px;
            }
            .sol-box {
                padding: 12px;
                font-size: 12.5px;
                border-radius: 8px;
            }
            .sol-box table {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }

        @media (max-width: 480px) {
            .deck-stream-layout {
                width: 98%;
                padding: 8px 0 50px;
            }
            .question-card {
                padding: 13px 10px;
                border-radius: 10px;
            }
            .q-title {
                font-size: 14.5px;
            }
            .q-title-en {
                font-size: 12.5px;
            }
            .option-item {
                padding: 10px 10px;
                gap: 8px;
                font-size: 13px;
            }
            .modal-card {
                padding: 22px 16px;
                width: 94%;
            }
            .modal-icon {
                width: 56px;
                height: 56px;
                font-size: 24px;
                margin-bottom: 12px;
            }
            .modal-card h3 {
                font-size: 18px;
            }
            .modal-stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 6px;
            }
            .modal-stat-box .num {
                font-size: 18px;
            }
            .modal-stat-box .lbl {
                font-size: 10px;
            }
        }

        /* ─── Mobile PWA / Chrome App Install Popup ─── */
        .mobile-install-banner {
            position: fixed;
            bottom: 16px;
            left: 12px;
            right: 12px;
            margin: 0 auto;
            max-width: 420px;
            background: rgba(17, 24, 39, 0.98);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(59, 130, 246, 0.45);
            border-radius: 16px;
            box-shadow: 0 14px 40px rgba(0, 0, 0, 0.7), 0 0 25px rgba(59, 130, 246, 0.2);
            padding: 14px 15px;
            z-index: 9999;
            transform: translateY(140%);
            opacity: 0;
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.35s ease;
        }

        .mobile-install-banner.show {
            transform: translateY(0);
            opacity: 1;
        }

        .install-banner-header {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }

        .install-app-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4);
            background: #1e293b;
        }

        .install-app-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .install-app-info {
            flex: 1;
            min-width: 0;
        }

        .install-app-title-row {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .install-app-title-row h4 {
            font-size: 14px;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .install-badge {
            font-size: 9.5px;
            font-weight: 700;
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.35);
            border-radius: 4px;
            padding: 1px 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .install-app-info p {
            font-size: 11.5px;
            color: #94a3b8;
            margin-top: 3px;
            line-height: 1.35;
        }

        .btn-install-dismiss {
            background: transparent;
            border: none;
            color: #64748b;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            font-size: 14px;
            transition: color 0.15s, background 0.15s;
        }

        .btn-install-dismiss:hover {
            color: #f8fafc;
            background: rgba(255, 255, 255, 0.08);
        }

        .install-banner-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }

        .btn-install-app {
            flex: 1;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 9px 12px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
            transition: transform 0.15s;
        }

        .btn-install-app:active {
            transform: scale(0.98);
        }

        .btn-install-later {
            background: rgba(255, 255, 255, 0.06);
            color: #94a3b8;
            border: 1px solid var(--border-color);
            padding: 9px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-install-later:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
        }

        .install-guide-box {
            margin-top: 10px;
            padding: 10px 12px;
            background: rgba(15, 23, 42, 0.95);
            border: 1px dashed rgba(56, 189, 248, 0.45);
            border-radius: 8px;
            font-size: 11.5px;
            color: #e2e8f0;
            line-height: 1.45;
            animation: installFadeIn 0.25s ease;
        }

        @keyframes installFadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        
        /* ─── Monetization & Auth / Paywall UI ─── */
        .nav-user-area {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: 8px;
            flex-shrink: 0;
            flex-wrap: nowrap;
        }

        .nav-sub-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: #6ee7b7;
            padding: 5px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .nav-sub-pill.active {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.18), rgba(16, 185, 129, 0.18));
            border: 1px solid rgba(245, 158, 11, 0.45);
            color: #fef08a;
        }

        .nav-expiry-countdown {
            font-size: 11.5px;
            font-weight: 700;
            color: #fde047;
            letter-spacing: 0.2px;
            font-variant-numeric: tabular-nums;
        }

        .nav-sub-pill.unsubs {
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.35);
            color: #fde047;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .nav-sub-pill.unsubs:hover {
            background: rgba(245, 158, 11, 0.22);
            border-color: rgba(245, 158, 11, 0.6);
            transform: translateY(-1px);
        }

        .nav-user-email-text {
            max-width: 130px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .nav-sub-badge {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 2px 6px;
            border-radius: 4px;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }

        .nav-sub-pill.active .nav-sub-badge {
            background: #eab308;
            color: #0f172a;
        }

        .nav-sub-pill.unsubs .nav-sub-badge.buy {
            background: #f97316;
            color: #ffffff;
        }

        .nav-logout-btn {
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 11px;
            padding: 2px 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            transition: color 0.15s;
            margin-left: 2px;
            flex-shrink: 0;
        }

        .nav-logout-btn:hover {
            color: #f87171;
        }

        .nav-trial-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(59, 130, 246, 0.12);
            border: 1px solid rgba(59, 130, 246, 0.35);
            color: #93c5fd;
            padding: 5px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .nav-trial-pill.warning {
            background: rgba(239, 68, 68, 0.18);
            border-color: rgba(239, 68, 68, 0.5);
            color: #fca5a5;
            animation: trialWarningPulse 1.8s infinite;
        }

        @keyframes trialWarningPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.65; }
        }

        .trial-label {
            font-size: 10px;
            background: rgba(255, 255, 255, 0.1);
            padding: 1px 5px;
            border-radius: 4px;
            color: #cbd5e1;
            flex-shrink: 0;
        }

        .nav-login-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--border-color);
            color: #e2e8f0;
            padding: 5px 11px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .nav-login-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: #64748b;
        }

        /* Modals Backdrop & Card for Auth & Paywall */
        .auth-modal-backdrop, .paywall-modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(8, 14, 26, 0.88);
            backdrop-filter: blur(10px);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }

        .auth-modal-backdrop.show, .paywall-modal-backdrop.show {
            opacity: 1;
            pointer-events: auto;
        }

        .auth-modal-card, .paywall-modal-card {
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 18px;
            width: 100%;
            max-width: 450px;
            padding: 26px 22px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.65), 0 0 40px rgba(59, 130, 246, 0.15);
            position: relative;
            transform: translateY(12px) scale(0.98);
            transition: transform 0.25s ease;
            box-sizing: border-box;
        }

        .auth-modal-backdrop.show .auth-modal-card,
        .paywall-modal-backdrop.show .paywall-modal-card {
            transform: translateY(0) scale(1);
        }

        .modal-close-icon {
            position: absolute;
            top: 14px;
            right: 16px;
            background: none;
            border: none;
            color: #64748b;
            font-size: 18px;
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
            transition: color 0.15s;
        }

        .modal-close-icon:hover {
            color: #cbd5e1;
        }

        .auth-badge-header {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
            border-radius: 9999px;
            font-size: 11.5px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .auth-title, .paywall-title {
            font-size: 19px;
            font-weight: 800;
            color: #f8fafc;
            margin-bottom: 6px;
            font-family: 'Outfit', sans-serif;
            line-height: 1.3;
        }

        .auth-sub, .paywall-sub {
            font-size: 12.5px;
            color: #94a3b8;
            line-height: 1.5;
            margin-bottom: 18px;
        }

        /* Paywall Price Highlight Banner */
        
        .paywall-user-preview {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 10px 14px;
            margin-top: 6px;
            margin-bottom: 8px;
        }

        .user-preview-info {
            display: flex;
            flex-direction: column;
            text-align: left;
            overflow: hidden;
        }

        .user-preview-label {
            font-size: 11px;
            color: #94a3b8;
        }

        #paywallUserEmailDisplay {
            font-size: 13.5px;
            color: #f8fafc;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .paywall-price-banner {
            background: linear-gradient(135deg, rgba(30, 58, 138, 0.45) 0%, rgba(245, 158, 11, 0.15) 100%);
            border: 1px solid rgba(245, 158, 11, 0.35);
            border-radius: 14px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .price-left {
            display: flex;
            flex-direction: column;
        }

        .price-plan-name {
            font-size: 11px;
            color: #cbd5e1;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .price-amount-wrap {
            display: flex;
            align-items: baseline;
            gap: 3px;
        }

        .price-currency {
            font-size: 18px;
            font-weight: 700;
            color: #fbbf24;
        }

        .price-number {
            font-size: 32px;
            font-weight: 800;
            color: #fef08a;
            font-family: 'Outfit', sans-serif;
            line-height: 1;
        }

        .price-period {
            font-size: 12px;
            color: #94a3b8;
            font-weight: 500;
        }

        .price-tag-pill {
            background: rgba(245, 158, 11, 0.2);
            border: 1px solid rgba(245, 158, 11, 0.4);
            color: #fef08a;
            padding: 4px 9px;
            border-radius: 8px;
            font-size: 10.5px;
            font-weight: 700;
            text-align: center;
        }

        .paywall-features-list {
            list-style: none;
            padding: 0;
            margin: 0 0 18px 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .paywall-features-list li {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 12.5px;
            color: #cbd5e1;
        }

        .paywall-features-list li i {
            color: #10b981;
            font-size: 12px;
            width: 14px;
            text-align: center;
        }

        .auth-form, .paywall-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            text-align: left;
        }

        .form-group label {
            font-size: 11.5px;
            font-weight: 600;
            color: #cbd5e1;
        }

        .form-control-input {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            color: #f8fafc;
            outline: none;
            transition: border-color 0.15s;
            width: 100%;
            box-sizing: border-box;
        }

        .form-control-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
        }

        .btn-proceed-pay {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.4);
            transition: all 0.15s;
        }

        .btn-proceed-pay:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.5);
        }

        .btn-proceed-pay:active {
            transform: translateY(0);
        }

        .btn-auth-submit {
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 11px 16px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.15s;
        }

        .btn-auth-submit:hover {
            background: #1d4ed8;
        }

        .auth-note-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.25);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            color: #fde047;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .auth-separator {
            display: flex;
            align-items: center;
            text-align: center;
            color: #64748b;
            font-size: 11.5px;
            margin: 10px 0;
        }

        .auth-separator::before,
        .auth-separator::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .auth-separator span {
            padding: 0 10px;
        }

        .google-auth-box {
            display: flex;
            justify-content: center;
            margin-bottom: 4px;
            min-height: 40px;
        }

        .paywall-security-note {
            font-size: 11px;
            color: #94a3b8;
            text-align: center;
            margin-top: 12px;
            line-height: 1.4;
        }

        .paywall-security-note i {
            color: #10b981;
            margin-right: 4px;
        }

        .paywall-status-msg {
            margin-top: 10px;
            padding: 9px 12px;
            border-radius: 8px;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            font-size: 12px;
            color: #93c5fd;
            text-align: center;
            display: none;
        }

        @media (max-width: 600px) {
            .nav-user-email-text {
                max-width: 75px;
            }
            .trial-label {
                display: none;
            }
            .auth-modal-card, .paywall-modal-card {
                padding: 22px 18px;
            }
            .price-number {
                font-size: 28px;
            }
        }
        /* Desktop screens (> 900px) - never show mobile install popup */
        @media (min-width: 901px) {
            .mobile-install-banner {
                display: none !important;
            }
        }
    </style>
</head>
<body class="notranslate">

    <!-- ─── Extreme Left Edge Hover Sensor (Trigger to open sidebar on mouse hover) ─── -->
    <div class="sidebar-edge-trigger" id="sidebarEdgeTrigger" title="विषय सूची पाहण्यासाठी कर्सर येथे आणा (Hover for topics)"></div>

    <!-- ─── Top Navbar (Position relative: naturally shifts upward with page on scroll) ─── -->
    <header class="navbar">
        <div class="nav-left">
            <button class="btn-sidebar-toggle" id="btnToggleSidebar" title="विषय सूची उघडा / बंद करा (Toggle Sidebar)">
                <i class="fa-solid fa-bars"></i>
            </button>

            <a href="practice.php?goal=<?= urlencode($currentGoal) ?>" class="nav-brand">
                <div class="nav-brand-logo">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div class="nav-brand-text">
                    <h1>MPSC Practice <span class="nav-badge-goal"><?= htmlspecialchars($activeGoalInfo['badge']) ?></span></h1>
                    <p><?= htmlspecialchars($activeGoalInfo['sub']) ?> • विषयवार व घटकवार परिपूर्ण सराव</p>
                </div>
            </a>
        </div>

        <div class="nav-right">
            <div class="nav-exam-tabs">
                <a href="practice.php?goal=mpsc-combined-group-c-2026" class="nav-exam-tab <?= $currentGoal === 'mpsc-combined-group-c-2026' ? 'active' : '' ?>">
                    <i class="fa-solid fa-layer-group"></i> Group C
                </a>
                <a href="practice.php?goal=mpsc-rajyaseva-2027" class="nav-exam-tab <?= $currentGoal === 'mpsc-rajyaseva-2027' ? 'active' : '' ?>">
                    <i class="fa-solid fa-crown"></i> Rajyaseva
                </a>
                <a href="practice.php?goal=group-c-1" class="nav-exam-tab <?= $currentGoal === 'group-c-1' ? 'active' : '' ?>">
                    <i class="fa-solid fa-layer-group" style="color: #f97316;"></i> Group C 1
                </a>
                <a href="practice.php?goal=maharashtra-gk" class="nav-exam-tab <?= $currentGoal === 'maharashtra-gk' ? 'active' : '' ?>">
                    <i class="fa-solid fa-landmark" style="color: #38bdf8;"></i> महा. GK
                </a>
            </div>
            <div class="nav-user-area" id="navUserArea">
                <?php if ($isSubscribedUser): ?>
                    <div class="nav-sub-pill active" title="<?= htmlspecialchars($userExpiresAt ? "दैनिक अभ्यास पास - आज रात्री १२ वाजेपर्यंत ($userHoursLeft तास शिल्लक)" : "अमर्यादित ॲक्सेस") ?>">
                        <i class="fa-solid fa-clock" style="color: #fbbf24;"></i>
                        <span id="navDailyExpiryTimer" class="nav-expiry-countdown"><?= $userHoursLeft ?> तास शिल्लक</span>
                        <span class="nav-sub-badge">PRO</span>
                        <span class="nav-user-email-text" title="<?= htmlspecialchars($sessEmail) ?>"><?= htmlspecialchars($sessEmail) ?></span>
                        <button class="nav-logout-btn" onclick="handleUserLogout(event)" title="लॉगआउट (Logout)"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
                    </div>
                <?php elseif (!empty($sessEmail)): ?>
                    <div class="nav-trial-pill <?= $isTrialTimeOver ? 'warning' : '' ?>" id="navTrialTimerPill" title="मोफत सराव वेळ (Free Practice Time)">
                        <i class="fa-solid fa-clock"></i>
                        <span id="navTimerDisplay"><?= $isTrialTimeOver ? '00:00' : sprintf('%02d:%02d', floor($serverSecondsRemaining / 60), $serverSecondsRemaining % 60) ?></span>
                        <span class="trial-label" style="<?= $isTrialTimeOver ? 'background: rgba(239, 68, 68, 0.2); color: #fca5a5;' : '' ?>"><?= $isTrialTimeOver ? 'वेळ संपली' : 'मोफत' ?></span>
                    </div>
                    <div class="nav-sub-pill unsubs" onclick="openPaywallModal()" title="दैनिक पास सक्रिय करा (₹10 / दिवस)">
                        <i class="fa-solid fa-bolt" style="color: #f59e0b;"></i>
                        <span class="nav-user-email-text" title="<?= htmlspecialchars($sessEmail) ?>"><?= htmlspecialchars($sessEmail) ?></span>
                        <span class="nav-sub-badge buy">₹10</span>
                        <button class="nav-logout-btn" onclick="handleUserLogout(event)" title="लॉगआउट (Logout)"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
                    </div>
                <?php else: ?>
                    <div class="nav-trial-pill <?= $isTrialTimeOver ? 'warning' : '' ?>" id="navTrialTimerPill" title="मोफत सराव वेळ (Free Practice Time)">
                        <i class="fa-solid fa-clock"></i>
                        <span id="navTimerDisplay"><?= $isTrialTimeOver ? '00:00' : sprintf('%02d:%02d', floor($serverSecondsRemaining / 60), $serverSecondsRemaining % 60) ?></span>
                        <span class="trial-label" style="<?= $isTrialTimeOver ? 'background: rgba(239, 68, 68, 0.2); color: #fca5a5;' : '' ?>"><?= $isTrialTimeOver ? 'वेळ संपली' : 'मोफत' ?></span>
                    </div>
                    <button class="nav-login-btn" onclick="openAuthModal()" title="लॉगिन करा">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i> <span>लॉगिन</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- ─── Left Sidebar Overlay Drawer (Appears only on extreme left hover or toggle) ─── -->
    <aside class="sidebar" id="appSidebar">
        <div class="sidebar-header">
            <div class="sidebar-title-bar">
                <span class="sidebar-title-label">
                    <i class="fa-solid fa-list-ul" style="color: #60a5fa;"></i> <?= htmlspecialchars($activeGoalInfo['name']) ?>
                </span>
                <button class="btn-sidebar-close" id="btnSidebarClose" title="सूची बंद करा (Close)">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Exam Switcher in Drawer -->
            <div class="sidebar-exam-switcher">
                <div class="exam-switcher-label"><i class="fa-solid fa-graduation-cap"></i> परीक्षा निवडा (Select Exam)</div>
                <div class="exam-switcher-pills">
                    <a href="practice.php?goal=mpsc-combined-group-c-2026" class="exam-pill <?= $currentGoal === 'mpsc-combined-group-c-2026' ? 'active' : '' ?>">
                        <i class="fa-solid fa-layer-group"></i> Group C
                    </a>
                    <a href="practice.php?goal=mpsc-rajyaseva-2027" class="exam-pill <?= $currentGoal === 'mpsc-rajyaseva-2027' ? 'active' : '' ?>">
                        <i class="fa-solid fa-crown"></i> Rajyaseva
                    </a>
                    <a href="practice.php?goal=group-c-1" class="exam-pill <?= $currentGoal === 'group-c-1' ? 'active' : '' ?>">
                        <i class="fa-solid fa-layer-group" style="color: #f97316;"></i> Group C 1
                    </a>
                    <a href="practice.php?goal=maharashtra-gk" class="exam-pill <?= $currentGoal === 'maharashtra-gk' ? 'active' : '' ?>">
                        <i class="fa-solid fa-landmark" style="color: #38bdf8;"></i> महा. GK
                    </a>
                </div>
            </div>

            <div class="sidebar-stats-row">
                <div class="stat-item">
                    <div class="num"><?= count($fullSubjectTree) ?></div>
                    <div class="label">विषय (Subj)</div>
                </div>
                <div class="stat-item">
                    <div class="num"><?= $totalAllSets ?></div>
                    <div class="label">सराव संच (Sets)</div>
                </div>
                <div class="stat-item">
                    <div class="num"><?= number_format($totalAllQuestions) ?></div>
                    <div class="label">प्रश्न (Ques)</div>
                </div>
            </div>

            <div class="sidebar-search-box">
                <i class="fa-solid fa-search sidebar-search-icon"></i>
                <input type="text" id="topicFilterInput" class="sidebar-search-input" placeholder="सराव संच किंवा घटक शोधा...">
            </div>
        </div>

        <!-- Left Pane Accordion Container -->
        <div class="sidebar-accordion" id="sidebarAccordion">
            <?php foreach ($fullSubjectTree as $sName => $sData): 
                $meta = $subjectMeta[$sName] ?? [
                    'title_mr' => $sName,
                    'icon' => 'fa-solid fa-book',
                    'gradient' => 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)',
                    'desc' => ''
                ];
                $isOpen = ($sName === $initialSubject);
            ?>
            <div class="accordion-subject-group <?= $isOpen ? 'expanded' : '' ?>" data-subject="<?= htmlspecialchars($sName) ?>">
                <div class="accordion-subject-header" onclick="toggleSubjectAccordion('<?= htmlspecialchars(addslashes($sName)) ?>')">
                    <div class="subj-icon-box" style="background: <?= $meta['gradient'] ?>;">
                        <i class="<?= $meta['icon'] ?>"></i>
                    </div>
                    <div class="subj-info">
                        <div class="subj-title-en"><?= htmlspecialchars($sName) ?></div>
                        <div class="subj-title-mr"><?= htmlspecialchars($meta['title_mr']) ?></div>
                    </div>
                    <div class="subj-meta-end">
                        <span class="subj-badge"><?= $sData['total_questions'] ?> Qs</span>
                        <i class="fa-solid fa-chevron-down accordion-arrow"></i>
                    </div>
                </div>
                <div class="accordion-subject-body" <?= $isOpen ? '' : 'style="display: none;"' ?>>
                    <?php foreach ($sData['topics'] as $tName => $tData): 
                        $tMr = $tData['topic_name_mr'] ?? $tName;
                        $tEn = $tData['topic_name_en'] ?? $tName;
                        $isTopicActive = ($sName === $initialSubject && $tName === $initialTopic);
                    ?>
                        <div class="accordion-topic-item" data-topic="<?= htmlspecialchars($tName) ?>" data-topic-mr="<?= htmlspecialchars($tMr) ?>" data-topic-en="<?= htmlspecialchars($tEn) ?>">
                            <!-- Click Main Topic: loads ALL questions from ALL subtopics of this topic -->
                            <div class="topic-title-bar <?= $isTopicActive ? 'active-topic' : '' ?>" 
                                 title="या घटकातील सर्व सराव संचांचे प्रश्न एकत्र सोडवा" 
                                 onclick="selectMainTopic('<?= htmlspecialchars(addslashes($sName)) ?>', '<?= htmlspecialchars(addslashes($tName)) ?>')">
                                <i class="fa-solid fa-folder-open topic-icon"></i>
                                <div class="topic-name-wrap">
                                    <span class="topic-name-mr"><?= htmlspecialchars($tMr) ?></span>
                                    <span class="topic-name-en"><?= htmlspecialchars($tEn) ?></span>
                                </div>
                                <span class="topic-total-badge" title="एकूण प्रश्न"><?= $tData['total_questions'] ?>Q</span>
                            </div>

                            <!-- Specific Subtopics / Practice Sets List -->
                            <div class="sets-sublist">
                                <?php foreach ($tData['sets'] as $s): 
                                    $isSetSelected = ($s['practice_id'] === $initialPracticeId);
                                ?>
                                    <div class="accordion-set-link <?= $isSetSelected ? 'active' : '' ?>" 
                                         data-practice-id="<?= htmlspecialchars($s['practice_id']) ?>"
                                         data-subject="<?= htmlspecialchars($sName) ?>"
                                         data-topic="<?= htmlspecialchars($tName) ?>"
                                         data-subtopic="<?= htmlspecialchars($s['subtopic_name']) ?>"
                                         onclick="selectPracticeSet('<?= htmlspecialchars($s['practice_id']) ?>', '<?= htmlspecialchars(addslashes($sName)) ?>', '<?= htmlspecialchars(addslashes($tName)) ?>', '<?= htmlspecialchars(addslashes($s['subtopic_name'])) ?>')">
                                        <span class="set-bullet"><i class="fa-regular fa-circle-dot"></i></span>
                                        <span class="set-link-name"><?= htmlspecialchars($s['subtopic_name']) ?></span>
                                        <span class="set-link-count"><?= $s['question_count'] ?>Q</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- ─── Mobile Sidebar Overlay Backdrop ─── -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ─── App Container (100% Full Width Questions) ─── -->
    <div class="app-container">
        
        <!-- ─── Main Panel: Direct Full-Screen Practice Questions View ─── -->
        <main class="main-panel">
            
            <section class="practice-deck-view" id="practiceDeckView">
                <!-- Deck Header (Shifts upward on page scroll) -->
                <div class="deck-header">
                    <div class="deck-header-left">
                        <div class="deck-breadcrumb">
                            <span class="crumb-clickable" id="deckSubjectName" onclick="focusSubjectInAccordion(activeSubject)">Subject</span>
                            <span class="sep"><i class="fa-solid fa-chevron-right"></i></span>
                            <span class="crumb-clickable" id="deckTopicName" onclick="focusTopicInAccordion(activeSubject, activeTopic)">Topic</span>
                            <span class="sep"><i class="fa-solid fa-chevron-right"></i></span>
                            <span class="active-title" id="deckSubtopicName">Practice Set</span>
                        </div>
                    </div>

                    <div class="deck-controls">
                        <div class="deck-score-badge" id="deckScoreBadge">
                            <i class="fa-solid fa-circle-check"></i>
                            <span id="scoreText">0 / 0 बरोबर</span>
                        </div>

                        <button class="btn-finish-deck" id="btnFinishDeck" title="सराव पूर्ण करा आणि निकाल पहा">
                            <i class="fa-solid fa-award"></i>
                            <span>निकाल पहा</span>
                        </button>
                    </div>
                </div>

                <!-- Deck Scrollable Body: Direct Question Cards Stream (Full Width) -->
                <div class="deck-stream-body" id="deckStreamBody">
                    <div class="deck-stream-layout">
                        <div class="deck-questions-col" id="questionsStreamContainer">
                            <div class="loading-spinner-wrap">
                                <div class="spinner"></div>
                                <div>सर्व प्रश्न लोड होत आहेत...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- ─── Performance / Score Modal ─── -->
    <div class="modal-backdrop" id="scoreModalBackdrop">
        <div class="modal-card">
            <div class="modal-icon">
                <i class="fa-solid fa-trophy"></i>
            </div>
            <h3>सराव पूर्ण झाला! (Practice Completed)</h3>
            <p id="modalSetTitle">Social Reformers of Maharashtra - 01</p>

            <div class="score-stats-grid">
                <div class="score-box blue">
                    <div class="num" id="modalTotalQ">10</div>
                    <div class="lbl">एकूण प्रश्न</div>
                </div>
                <div class="score-box green">
                    <div class="num" id="modalCorrectQ">8</div>
                    <div class="lbl">बरोबर (Correct)</div>
                </div>
                <div class="score-box red">
                    <div class="num" id="modalWrongQ">2</div>
                    <div class="lbl">चूक (Wrong)</div>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn-retake" id="btnRetakePractice">
                    <i class="fa-solid fa-rotate-left"></i> पुन्हा सोडवा (Retake)
                </button>
                <button class="btn-next-set" id="btnNextSetPractice">
                    पुढील सराव संच <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ─── Google Login Modal (No Manual Name/Phone Required) ─── -->
    <div class="auth-modal-backdrop" id="authModalBackdrop">
        <div class="auth-modal-card">
            <button class="modal-close-icon" id="authModalCloseBtn" onclick="closeAuthModal()" title="बंद करा">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div class="auth-badge-header" id="authModalBadge">
                <i class="fa-solid fa-lock"></i> सराव चाचणी सोडवण्यासाठी लॉगिन करा
            </div>
            <h3 class="auth-title" id="authModalTitle">MPSC अभ्यास - Google लॉगिन</h3>
            <p class="auth-sub" id="authModalSub">सराव चाचणी सोडवण्यासाठी व आपली प्रगती जतन करण्यासाठी कृपया Google खात्याने लॉगिन करा.</p>

            <!-- Google Sign-in Container -->
            <div class="google-auth-box" id="googleAuthBox" style="margin: 22px 0 12px 0;">
                <div id="googleSignInBtnContainer" style="display: flex; justify-content: center; width: 100%; min-height: 48px;"></div>
            </div>

            <div class="paywall-security-note" style="margin-top: 22px;">
                <i class="fa-solid fa-shield-halved"></i> सुरक्षित Google प्रमाणीकरण • पासवर्ड किंवा फोन नंबरची आवश्यकता नाही.
            </div>
        </div>
    </div>

    <!-- ─── ₹10 Daily Pass Paywall Modal (Expires at 12:00 AM Midnight) ─── -->
    <div class="paywall-modal-backdrop" id="paywallModalBackdrop">
        <div class="paywall-modal-card">
            <button class="modal-close-icon" id="paywallModalCloseBtn" onclick="closePaywallModal()" title="बंद करा">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div class="auth-badge-header" style="background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.3); color: #fbbf24;">
                <i class="fa-solid fa-crown"></i> दैनिक अभ्यास पास (Daily Pass)
            </div>
            <h3 class="paywall-title">MPSC दैनिक सराव ॲक्सेस</h3>
            <p class="paywall-sub">सर्व परीक्षा व सर्व सराव संच सोडवण्यासाठी आज रात्री १२:०० वाजेपर्यंत संपूर्ण अमर्यादित ॲक्सेस.</p>

            <div class="paywall-price-banner">
                <div class="price-left">
                    <span class="price-plan-name">MPSC Daily Study Pass</span>
                    <div class="price-amount-wrap">
                        <span class="price-currency">₹</span>
                        <span class="price-number">10</span>
                        <span class="price-period">/ १ दिवस (आज रात्री १२ वाजेपर्यंत)</span>
                    </div>
                </div>
                <div class="price-tag-pill">
                    <i class="fa-solid fa-bolt"></i> आजचा पास
                </div>
            </div>

            <ul class="paywall-features-list">
                <li><i class="fa-solid fa-circle-check"></i> सर्व विषयांचे सर्व प्रश्न व संच आज रात्री १२:०० पर्यंत १००% अनलॉक</li>
                <li><i class="fa-solid fa-circle-check"></i> प्रत्येक प्रश्नाचे सविस्तर मराठी स्पष्टीकरण व संदर्भ नोट्स</li>
                <li><i class="fa-solid fa-circle-check"></i> अमर्यादित वेळ पुन्हा सोडवा (Unlimited Retakes)</li>
                <li><i class="fa-solid fa-circle-check"></i> Google Pay, PhonePe, Paytm, Any UPI द्वारे फक्त ₹१० मध्ये सुरक्षित पेमेंट</li>
            </ul>

            <div class="paywall-user-preview">
                <i class="fa-brands fa-google" style="color: #4285f4; font-size: 20px;"></i>
                <div class="user-preview-info">
                    <span class="user-preview-label">लॉगिन केलेले गुगल खाते (Google Account):</span>
                    <strong id="paywallUserEmailDisplay"><?= htmlspecialchars($sessEmail ?? '') ?></strong>
                </div>
            </div>

            <button type="button" class="btn-proceed-pay" id="btnPayRazorpay" onclick="handleRazorpayPaymentClick(event)" style="width: 100%; margin-top: 14px;">
                <i class="fa-solid fa-bolt"></i> ₹१० भरा (Pay ₹10 via UPI / Razorpay)
            </button>

            <div class="paywall-status-msg" id="paywallStatusMsg">
                <i class="fa-solid fa-spinner fa-spin"></i> पेमेंट पेज उघडत आहे... पैसे भरल्यावर Razorpay Webhook द्वारे तात्काळ ॲक्सेस सक्रिय केला जाईल.
            </div>

            <div class="paywall-security-note">
                <i class="fa-solid fa-shield-halved"></i> 100% सुरक्षित पेमेंट • पेमेंट पूर्ण झाल्यावर सिस्टीम तात्काळ ॲक्सेस अनलॉक करते (Validity: आज रात्री 12:00 AM पर्यंत).
            </div>
        </div>
    </div>

    <!-- ─── Mobile Install as App / Chrome Home Screen Bookmark Popup ─── -->
    <div class="mobile-install-banner" id="mobileInstallBanner" style="display: none;">
        <div class="install-banner-header">
            <div class="install-app-icon">
                <img src="icon-192.png" alt="MPSC App Icon">
            </div>
            <div class="install-app-info">
                <div class="install-app-title-row">
                    <h4>MPSC अभ्यास (MPSC Abhyas)</h4>
                    <span class="install-badge">Chrome App</span>
                </div>
                <p>फोनवर ॲपप्रमाणे जलद अभ्यासासाठी होम स्क्रीनवर जोडा</p>
            </div>
            <button class="btn-install-dismiss" id="btnDismissInstall" title="बंद करा (Close)">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="install-banner-actions">
            <button class="btn-install-app" id="btnTriggerInstall">
                <i class="fa-solid fa-download"></i> ॲप जोडा (Add to Home screen)
            </button>
            <button class="btn-install-later" id="btnLaterInstall">
                नंतर (Later)
            </button>
        </div>

        <!-- Guided Instructions for iOS Safari or manual Chrome -->
        <div class="install-guide-box" id="installGuideBox" style="display: none;">
            <div class="guide-ios" id="guideIos" style="display: none;">
                <i class="fa-solid fa-arrow-up-from-bracket" style="color: #38bdf8;"></i> Safari मधील <strong>Share</strong> बटणावर टॅप करा आणि <strong>'Add to Home Screen'</strong> निवडा.
            </div>
            <div class="guide-android" id="guideAndroid" style="display: none;">
                <i class="fa-solid fa-ellipsis-vertical" style="color: #38bdf8;"></i> Chrome वरील <strong>तीन बिंदूवर (⋮)</strong> टॅप करा आणि <strong>'Add to Home screen'</strong> (किंवा Install app) निवडा.
            </div>
        </div>
    </div>

    <!-- ─── Application JavaScript ─── -->
    <script>
        const currentGoal = <?= json_encode($currentGoal) ?>;
        const subjectMetaConfig = <?= json_encode($subjectMeta, JSON_UNESCAPED_UNICODE) ?>;
        const topicTranslationsMap = <?= json_encode($topicTranslations, JSON_UNESCAPED_UNICODE) ?>;
        const fullSubjectTreeData = <?= json_encode($fullSubjectTree, JSON_UNESCAPED_UNICODE) ?>;

        // Current Active State
        let activePracticeId = '<?= addslashes($initialPracticeId) ?>';
        let activeSubject = '<?= addslashes($initialSubject) ?>';
        let activeTopic = '<?= addslashes($initialTopic) ?>';
        let activeSubtopic = '';
        let activePracticeSet = null;

        let currentQuestions = [];
        let userAnswers = {}; // qIndex -> { selectedOpt, isCorrect }

        // DOM Elements
        const appSidebar = document.getElementById('appSidebar');
        const sidebarEdgeTrigger = document.getElementById('sidebarEdgeTrigger');
        const btnToggleSidebar = document.getElementById('btnToggleSidebar');
        const btnSidebarClose = document.getElementById('btnSidebarClose');
        const topicFilterInput = document.getElementById('topicFilterInput');
        const deckSubjectName = document.getElementById('deckSubjectName');
        const deckTopicName = document.getElementById('deckTopicName');
        const deckSubtopicName = document.getElementById('deckSubtopicName');
        const questionsStreamContainer = document.getElementById('questionsStreamContainer');
        const scoreText = document.getElementById('scoreText');
        const btnFinishDeck = document.getElementById('btnFinishDeck');

        // Modal Elements
        const scoreModalBackdrop = document.getElementById('scoreModalBackdrop');
        const modalSetTitle = document.getElementById('modalSetTitle');
        const modalTotalQ = document.getElementById('modalTotalQ');
        const modalCorrectQ = document.getElementById('modalCorrectQ');
        const modalWrongQ = document.getElementById('modalWrongQ');
        const btnRetakePractice = document.getElementById('btnRetakePractice');
        const btnNextSetPractice = document.getElementById('btnNextSetPractice');

        // ─────────────────────────────────────────────────────────────────────
        // 1. EXTREME LEFT HOVER & AUTO-HIDE SIDEBAR
        // ─────────────────────────────────────────────────────────────────────
        let sidebarHideTimer = null;
        const EDGE_HOVER_ZONE = 20; // Extreme left 20px threshold for desktop hover

        function openSidebar() {
            clearTimeout(sidebarHideTimer);
            appSidebar.classList.add('open');
            if (window.innerWidth <= 900) {
                const backdrop = document.getElementById('sidebarBackdrop');
                if (backdrop) backdrop.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeSidebar() {
            clearTimeout(sidebarHideTimer);
            appSidebar.classList.remove('open');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (backdrop) backdrop.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Only activate mouse hover on desktop screens (> 900px)
        if (sidebarEdgeTrigger) {
            sidebarEdgeTrigger.addEventListener('mouseenter', () => {
                if (window.innerWidth > 900) {
                    openSidebar();
                }
            });
        }

        document.addEventListener('mousemove', (e) => {
            // STRICTLY desktop only. Mobile touch events simulate mousemove, which triggered unwanted sidebar opening!
            if (window.innerWidth <= 900) return;

            if (e.clientX <= EDGE_HOVER_ZONE) {
                openSidebar();
            } else if (e.clientX > 365 && appSidebar.classList.contains('open')) {
                clearTimeout(sidebarHideTimer);
                sidebarHideTimer = setTimeout(() => {
                    closeSidebar();
                }, 160);
            }
        });

        appSidebar.addEventListener('mouseenter', () => {
            if (window.innerWidth > 900) {
                clearTimeout(sidebarHideTimer);
                appSidebar.classList.add('open');
            }
        });

        appSidebar.addEventListener('mouseleave', () => {
            if (window.innerWidth > 900) {
                clearTimeout(sidebarHideTimer);
                sidebarHideTimer = setTimeout(() => {
                    closeSidebar();
                }, 180);
            }
        });

        if (btnSidebarClose) {
            btnSidebarClose.addEventListener('click', () => {
                closeSidebar();
            });
        }

        const sidebarBackdrop = document.getElementById('sidebarBackdrop');
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', () => {
                closeSidebar();
            });
        }

        btnToggleSidebar.addEventListener('click', (e) => {
            e.stopPropagation();
            if (appSidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        // Tap outside drawer to close on desktop/mobile
        document.addEventListener('click', (e) => {
            if (appSidebar.classList.contains('open') && !appSidebar.contains(e.target) && !btnToggleSidebar.contains(e.target)) {
                closeSidebar();
            }
        });

        // ─────────────────────────────────────────────────────────────────────
        // 2. ACCORDION TREE INTERACTIONS
        // ─────────────────────────────────────────────────────────────────────
        function toggleSubjectAccordion(subjectName) {
            const groups = document.querySelectorAll('.accordion-subject-group');
            groups.forEach(g => {
                const s = g.getAttribute('data-subject');
                const body = g.querySelector('.accordion-subject-body');
                if (s === subjectName) {
                    const isExpanded = g.classList.contains('expanded');
                    if (isExpanded) {
                        g.classList.remove('expanded');
                        if (body) body.style.display = 'none';
                    } else {
                        g.classList.add('expanded');
                        if (body) body.style.display = 'flex';
                    }
                }
            });
        }

        function focusSubjectInAccordion(subjectName) {
            openSidebar();
            toggleSubjectAccordion(subjectName);
            const target = document.querySelector(`.accordion-subject-group[data-subject="${subjectName}"]`);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function focusTopicInAccordion(subjectName, topicName) {
            openSidebar();
            const targetGroup = document.querySelector(`.accordion-subject-group[data-subject="${subjectName}"]`);
            if (targetGroup) {
                targetGroup.classList.add('expanded');
                const body = targetGroup.querySelector('.accordion-subject-body');
                if (body) body.style.display = 'flex';
                const topicEl = targetGroup.querySelector(`.accordion-topic-item[data-topic="${topicName}"]`);
                if (topicEl) {
                    topicEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // 3. SELECT MAIN TOPIC (LOADS ALL QUESTIONS ACROSS ALL SUBTOPICS)
        // ─────────────────────────────────────────────────────────────────────
        async function selectMainTopic(subjectName, topicName, isInitial = false) {
            if (!isInitial && !checkCanAttemptTest()) return;
            activeSubject = subjectName;
            activeTopic = topicName;
            activePracticeId = ''; // Indicates all subtopics
            activeSubtopic = 'सर्व सराव संच (All Subtopics)';

            // Highlight main topic in sidebar
            document.querySelectorAll('.topic-title-bar').forEach(el => {
                const parent = el.closest('.accordion-topic-item');
                const t = parent ? parent.getAttribute('data-topic') : '';
                const s = parent ? parent.closest('.accordion-subject-group').getAttribute('data-subject') : '';
                el.classList.toggle('active-topic', (t === topicName && s === subjectName));
            });

            // Remove active from specific set links
            document.querySelectorAll('.accordion-set-link').forEach(el => el.classList.remove('active'));

            // Ensure parent subject is expanded
            const parentGroup = document.querySelector(`.accordion-subject-group[data-subject="${subjectName}"]`);
            if (parentGroup) {
                parentGroup.classList.add('expanded');
                const body = parentGroup.querySelector('.accordion-subject-body');
                if (body) body.style.display = 'flex';
            }

            // Update Breadcrumb
            const sMeta = subjectMetaConfig[subjectName] || {};
            deckSubjectName.innerHTML = `<span class="crumb-mr">${sMeta.title_mr || subjectName}</span> <span class="crumb-en">(${subjectName})</span>`;

            const tTrans = topicTranslationsMap[topicName] || { mr: topicName, en: topicName };
            deckTopicName.innerHTML = `<span class="crumb-mr">${tTrans.mr}</span> <span class="crumb-en">(${tTrans.en})</span>`;
            deckSubtopicName.innerHTML = `<span style="color: #38bdf8;"><i class="fa-solid fa-layer-group" style="font-size: 11px; margin-right: 4px;"></i>सर्व घटक सराव (All Practice Sets)</span>`;

            // Close modal if open
            scoreModalBackdrop.style.display = 'none';

            // Auto-hide the sidebar
            setTimeout(() => {
                closeSidebar();
            }, 120);

            // Show loading spinner
            questionsStreamContainer.innerHTML = `
                <div class="loading-spinner-wrap">
                    <div class="spinner"></div>
                    <div>${tTrans.mr} मधील सर्व प्रश्न लोड होत आहेत...</div>
                </div>
            `;

            userAnswers = {};

            try {
                const res = await fetch(`practice.php?ajax=1&action=get_questions&subject_name=${encodeURIComponent(subjectName)}&topic_name=${encodeURIComponent(topicName)}&goal=${encodeURIComponent(currentGoal)}`);
                const data = await res.json();
                if (data.status === 'success') {
                    currentQuestions = data.questions;
                    activePracticeSet = data.meta;

                    deckSubtopicName.innerHTML = `<span style="color: #38bdf8;"><i class="fa-solid fa-layer-group" style="font-size: 11px; margin-right: 4px;"></i>सर्व घटक सराव (${currentQuestions.length} प्रश्न)</span>`;

                    updateScoreDisplay();
                    renderAllQuestions();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    questionsStreamContainer.innerHTML = `<div style="color: #ef4444; padding: 30px; text-align: center;">त्रुटी: ${data.message}</div>`;
                }
            } catch (err) {
                questionsStreamContainer.innerHTML = `<div style="color: #ef4444; padding: 30px; text-align: center;">नेटवर्क त्रुटी: ${err.message}</div>`;
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // 4. SELECT SPECIFIC PRACTICE SET (SINGLE SUBTOPIC)
        // ─────────────────────────────────────────────────────────────────────
        async function selectPracticeSet(practiceId, subjectName, topicName, subtopicName, isInitial = false) {
            if (!isInitial && !checkCanAttemptTest()) return;
            activePracticeId = practiceId;
            activeSubject = subjectName;
            activeTopic = topicName;
            activeSubtopic = subtopicName;

            // Remove active-topic from all main topics
            document.querySelectorAll('.topic-title-bar').forEach(el => el.classList.remove('active-topic'));

            // Highlight specific set link
            document.querySelectorAll('.accordion-set-link').forEach(el => {
                el.classList.toggle('active', el.getAttribute('data-practice-id') === practiceId);
            });

            // Ensure parent subject is expanded
            const parentGroup = document.querySelector(`.accordion-subject-group[data-subject="${subjectName}"]`);
            if (parentGroup) {
                parentGroup.classList.add('expanded');
                const body = parentGroup.querySelector('.accordion-subject-body');
                if (body) body.style.display = 'flex';
            }

            // Update Breadcrumb
            const sMeta = subjectMetaConfig[subjectName] || {};
            deckSubjectName.innerHTML = `<span class="crumb-mr">${sMeta.title_mr || subjectName}</span> <span class="crumb-en">(${subjectName})</span>`;

            const tTrans = topicTranslationsMap[topicName] || { mr: topicName, en: topicName };
            deckTopicName.innerHTML = `<span class="crumb-mr">${tTrans.mr}</span> <span class="crumb-en">(${tTrans.en})</span>`;
            deckSubtopicName.textContent = subtopicName;

            // Close modal if open
            scoreModalBackdrop.style.display = 'none';

            // Auto-hide the sidebar
            setTimeout(() => {
                closeSidebar();
            }, 120);

            // Show loading spinner
            questionsStreamContainer.innerHTML = `
                <div class="loading-spinner-wrap">
                    <div class="spinner"></div>
                    <div>सर्व प्रश्न लोड होत आहेत...</div>
                </div>
            `;

            userAnswers = {};

            try {
                const res = await fetch(`practice.php?ajax=1&action=get_questions&practice_id=${encodeURIComponent(practiceId)}&goal=${encodeURIComponent(currentGoal)}`);
                const data = await res.json();
                if (data.status === 'success') {
                    currentQuestions = data.questions;
                    activePracticeSet = data.meta;

                    updateScoreDisplay();
                    renderAllQuestions();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    questionsStreamContainer.innerHTML = `<div style="color: #ef4444; padding: 30px; text-align: center;">त्रुटी: ${data.message}</div>`;
                }
            } catch (err) {
                questionsStreamContainer.innerHTML = `<div style="color: #ef4444; padding: 30px; text-align: center;">नेटवर्क त्रुटी: ${err.message}</div>`;
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // 5. BILINGUAL SEARCH FILTER IN LEFT SIDEBAR ACCORDION
        // ─────────────────────────────────────────────────────────────────────
        topicFilterInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const subjectGroups = document.querySelectorAll('.accordion-subject-group');

            if (!query) {
                subjectGroups.forEach(g => {
                    g.style.display = 'block';
                    const sName = g.getAttribute('data-subject');
                    const body = g.querySelector('.accordion-subject-body');
                    const isCurrent = (sName === activeSubject);
                    g.classList.toggle('expanded', isCurrent);
                    if (body) body.style.display = isCurrent ? 'flex' : 'none';
                    g.querySelectorAll('.accordion-topic-item').forEach(t => t.style.display = 'flex');
                    g.querySelectorAll('.accordion-set-link').forEach(s => s.style.display = 'flex');
                });
                return;
            }

            subjectGroups.forEach(g => {
                let subjectMatches = false;
                const sName = (g.getAttribute('data-subject') || '').toLowerCase();
                const topics = g.querySelectorAll('.accordion-topic-item');

                topics.forEach(t => {
                    let topicMatches = false;
                    const tName = (t.getAttribute('data-topic') || '').toLowerCase();
                    const tMr = (t.getAttribute('data-topic-mr') || '').toLowerCase();
                    const tEn = (t.getAttribute('data-topic-en') || '').toLowerCase();
                    const sets = t.querySelectorAll('.accordion-set-link');

                    sets.forEach(s => {
                        const subtopic = (s.getAttribute('data-subtopic') || '').toLowerCase();
                        if (subtopic.includes(query) || tName.includes(query) || tMr.includes(query) || tEn.includes(query) || sName.includes(query)) {
                            s.style.display = 'flex';
                            topicMatches = true;
                            subjectMatches = true;
                        } else {
                            s.style.display = 'none';
                        }
                    });

                    const isDirectTopicMatch = topicMatches || tName.includes(query) || tMr.includes(query) || tEn.includes(query);
                    t.style.display = isDirectTopicMatch ? 'flex' : 'none';
                    if (isDirectTopicMatch) subjectMatches = true;
                });

                if (subjectMatches || sName.includes(query)) {
                    g.style.display = 'block';
                    g.classList.add('expanded');
                    const body = g.querySelector('.accordion-subject-body');
                    if (body) body.style.display = 'flex';
                } else {
                    g.style.display = 'none';
                }
            });
        });

        // ─────────────────────────────────────────────────────────────────────
        // 6. CELEBRATION EFFECT (Confetti Burst)
        // ─────────────────────────────────────────────────────────────────────
        function triggerCelebration() {
            if (typeof confetti === 'function') {
                confetti({
                    particleCount: 80,
                    spread: 70,
                    origin: { y: 0.65 },
                    colors: ['#10b981', '#3b82f6', '#f59e0b', '#ec4899', '#8b5cf6', '#34d399']
                });
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // 7. HTML CLEANER HELPER
        // ─────────────────────────────────────────────────────────────────────
        function cleanHtmlContent(html) {
            if (!html) return '';
            let str = String(html).trim();

            if (str.includes('&lt;') || str.includes('&gt;') || str.includes('&amp;')) {
                const txt = document.createElement('textarea');
                txt.innerHTML = str;
                str = txt.value;
            }

            str = str.replace(/src="\/\//gi, 'src="https://');
            str = str.replace(/\s*style="[^"]*"/gi, '');
            str = str.replace(/<span class="math-tex">\s*/gi, '');
            str = str.replace(/<span[^>]*>/gi, '').replace(/<\/span>/gi, '');
            str = str.replace(/<p>\s*(&nbsp;)?\s*<\/p>/gi, '');

            return str.trim();
        }

        // ─────────────────────────────────────────────────────────────────────
        // 8. QUESTION CARDS RENDERING & INTERACTION
        // ─────────────────────────────────────────────────────────────────────
        function updateScoreDisplay() {
            let correct = 0;
            let totalAnswered = Object.keys(userAnswers).length;
            Object.values(userAnswers).forEach(a => {
                if (a.isCorrect) correct++;
            });

            if (scoreText) {
                scoreText.textContent = `${correct} / ${currentQuestions.length} बरोबर (${totalAnswered} सोडवले)`;
            }
        }

        function renderAllQuestions() {
            let html = '';
            const isAllSubtopics = activePracticeSet && activePracticeSet.is_all_topic;

            for (let i = 0; i < currentQuestions.length; i++) {
                const q = currentQuestions[i];
                const corr = parseInt(q.correct_option) || 1;
                const answered = userAnswers[i];
                const isAttempted = !!answered;
                const hasMrSol = !!q.solution_mr;
                const hasEnSol = !!q.solution_en;
                const qNum = i + 1;

                // Clean plain text for Google search
                const plainQText = (q.question_mr || q.question_en || '').replace(/<[^>]*>/g, '').trim();
                const googleUrl = 'https://www.google.com/search?q=' + encodeURIComponent(plainQText + ' MPSC');

                html += `
                    <div class="question-card" id="qCard_${i}">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 8px;">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                ${isAllSubtopics && q.subtopic_name ? `
                                    <span class="q-subtopic-tag">
                                        <i class="fa-solid fa-layer-group" style="font-size: 10px; color: #38bdf8;"></i> ${cleanHtmlContent(q.subtopic_name)}
                                    </span>
                                ` : ''}
                            </div>
                            <a href="${googleUrl}" target="_blank" class="btn-google-ai-search" onclick="return handleGoogleSearchClick(event, this.href)" title="Search this question directly on Google">
                                <svg width="13" height="13" viewBox="0 0 24 24" style="vertical-align: middle;">
                                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                                </svg>
                                <i class="fa-solid fa-arrow-up-right-from-square" style="color: #a855f7; font-size: 10px;"></i>
                                <span>Search Google</span>
                            </a>
                        </div>

                        <div class="q-title" style="margin-bottom: 4px;">
                            Q${qNum}. ${cleanHtmlContent(q.question_mr || q.question_en)}
                        </div>

                        ${q.question_en && q.question_mr ? `<div class="q-title-en">Q. ${cleanHtmlContent(q.question_en)}</div>` : ''}

                        <div class="options-grid">
                            ${[1, 2, 3, 4].map(optNum => {
                                const optMr = q[`opt${optNum}_mr`] || '';
                                const optEn = q[`opt${optNum}_en`] || '';
                                let itemClass = 'option-item interactive';
                                let statusIcon = '';

                                if (isAttempted) {
                                    itemClass = 'option-item';
                                    if (optNum === corr) {
                                        itemClass += ' correct';
                                        statusIcon = '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>';
                                    }
                                    if (optNum === answered.selectedOpt) {
                                        if (answered.isCorrect) {
                                            itemClass += ' user-correct';
                                            statusIcon = '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>';
                                        } else {
                                            itemClass += ' user-wrong';
                                            statusIcon = '<i class="fa-solid fa-circle-xmark status-icon wrong-icon"></i>';
                                        }
                                    } else if (optNum !== corr) {
                                        itemClass += ' dimmed';
                                    }
                                }

                                return `
                                    <div id="opt_${i}_${optNum}" class="${itemClass}" onclick="handleOptionSelect(${i}, ${optNum}, ${corr})">
                                        <span class="opt-num">${optNum}</span>
                                        <div class="opt-text-container">
                                            <div class="opt-text-mr">${cleanHtmlContent(optMr || optEn)}</div>
                                            ${optEn && optEn !== optMr ? `<div class="opt-text-en">${cleanHtmlContent(optEn)}</div>` : ''}
                                        </div>
                                        ${statusIcon}
                                    </div>
                                `;
                            }).join('')}
                        </div>

                        <div id="feedback_${i}">
                            ${isAttempted ? (answered.isCorrect ? `
                                <div class="attempt-feedback success">
                                    <i class="fa-solid fa-trophy"></i> <strong>🎉 बरोबर उत्तर! खूप छान! (Correct Answer)</strong>
                                </div>
                            ` : `
                                <div class="attempt-feedback error">
                                    <i class="fa-solid fa-circle-xmark"></i> <strong>चुकीचा पर्याय!</strong> योग्य उत्तर <strong>पर्याय क्रमांक ${corr}</strong> आहे. खालील स्पष्टीकरण वाचा.
                                </div>
                            `) : ''}
                        </div>

                        ${(hasMrSol || hasEnSol) ? `
                            <div class="sol-action-bar">
                                <button class="sol-btn" onclick="toggleSolution('sol_${i}')">
                                    <i class="fa-solid fa-lightbulb"></i> View Explanation & Key Points
                                </button>

                                ${(hasMrSol && hasEnSol) ? `
                                    <div class="lang-switch-pills">
                                        <button class="lang-pill active" id="btn_mr_${i}" onclick="switchSolLang(${i}, 'mr', event)">मराठी</button>
                                        <button class="lang-pill" id="btn_en_${i}" onclick="switchSolLang(${i}, 'en', event)">English</button>
                                    </div>
                                ` : ''}
                            </div>

                            <div id="sol_${i}" class="sol-box ${isAttempted && !answered.isCorrect ? 'show' : ''}">
                                ${hasMrSol ? `
                                    <div id="sol_mr_${i}" class="sol-content">
                                        <strong><i class="fa-solid fa-circle-info"></i> स्पष्टीकरण (Marathi Solution):</strong><br>
                                        ${cleanHtmlContent(q.solution_mr)}
                                    </div>
                                ` : ''}
                                ${hasEnSol ? `
                                    <div id="sol_en_${i}" class="sol-content ${hasMrSol ? 'sol-hidden' : ''}">
                                        <strong><i class="fa-solid fa-circle-info"></i> Explanation (English Solution):</strong><br>
                                        ${cleanHtmlContent(q.solution_en)}
                                    </div>
                                ` : ''}
                            </div>
                        ` : ''}
                    </div>
                `;
            }

            questionsStreamContainer.innerHTML = html;

            // Typeset LaTeX/Math formulas
            if (window.MathJax && MathJax.typesetPromise) {
                MathJax.typesetPromise([questionsStreamContainer]).catch(() => {});
            }
        }

        function handleOptionSelect(qIndex, selectedOpt, correctOpt) {
            if (!checkCanAttemptTest()) return;
            if (userAnswers[qIndex]) return; // Already answered

            const isCorrect = (selectedOpt === correctOpt);
            userAnswers[qIndex] = { selectedOpt, isCorrect };

            updateScoreDisplay();

            const selectedEl = document.getElementById(`opt_${qIndex}_${selectedOpt}`);
            const correctEl = document.getElementById(`opt_${qIndex}_${correctOpt}`);
            const feedbackEl = document.getElementById(`feedback_${qIndex}`);

            // Lock options for this question
            for (let optNum = 1; optNum <= 4; optNum++) {
                const el = document.getElementById(`opt_${qIndex}_${optNum}`);
                if (el) {
                    el.classList.remove('interactive');
                    if (optNum !== selectedOpt && optNum !== correctOpt) {
                        el.classList.add('dimmed');
                    }
                }
            }

            if (isCorrect) {
                if (selectedEl) {
                    selectedEl.classList.add('user-correct');
                    selectedEl.innerHTML += '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>';
                }
                if (feedbackEl) {
                    feedbackEl.innerHTML = `
                        <div class="attempt-feedback success">
                            <i class="fa-solid fa-trophy"></i> <strong>🎉 बरोबर उत्तर! खूप छान! (Correct Answer)</strong>
                        </div>
                    `;
                }
                triggerCelebration();
            } else {
                if (selectedEl) {
                    selectedEl.classList.add('user-wrong');
                    selectedEl.innerHTML += '<i class="fa-solid fa-circle-xmark status-icon wrong-icon"></i>';
                }
                if (correctEl) {
                    correctEl.classList.add('correct');
                    correctEl.innerHTML += '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>';
                }
                if (feedbackEl) {
                    feedbackEl.innerHTML = `
                        <div class="attempt-feedback error">
                            <i class="fa-solid fa-circle-xmark"></i> <strong>चुकीचा पर्याय!</strong> योग्य उत्तर <strong>पर्याय क्रमांक ${correctOpt}</strong> आहे. खालील स्पष्टीकरण वाचा.
                        </div>
                    `;
                }
                // Auto reveal solution explanation on wrong answer
                toggleSolution('sol_' + qIndex, true);
            }

            // Auto-show completion modal if all answered
            if (Object.keys(userAnswers).length === currentQuestions.length) {
                setTimeout(showCompletionModal, 1200);
            }
        }

        function toggleSolution(id, forceShow = false) {
            if (!forceShow && !checkCanAttemptTest()) return;
            const box = document.getElementById(id);
            if (box) {
                if (forceShow) {
                    box.classList.add('show');
                } else {
                    box.classList.toggle('show');
                }
            }
        }

        function switchSolLang(qIndex, lang, event) {
            if (event) event.stopPropagation();
            if (!checkCanAttemptTest()) return;

            toggleSolution('sol_' + qIndex, true);

            const mrContent = document.getElementById(`sol_mr_${qIndex}`);
            const enContent = document.getElementById(`sol_en_${qIndex}`);
            const btnMr = document.getElementById(`btn_mr_${qIndex}`);
            const btnEn = document.getElementById(`btn_en_${qIndex}`);

            if (lang === 'mr') {
                if (mrContent) mrContent.classList.remove('sol-hidden');
                if (enContent) enContent.classList.add('sol-hidden');
                if (btnMr) btnMr.classList.add('active');
                if (btnEn) btnEn.classList.remove('active');
            } else {
                if (enContent) enContent.classList.remove('sol-hidden');
                if (mrContent) mrContent.classList.add('sol-hidden');
                if (btnEn) btnEn.classList.add('active');
                if (btnMr) btnMr.classList.remove('active');
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // 9. COMPLETION MODAL & NEXT SET LOGIC
        // ─────────────────────────────────────────────────────────────────────
        function showCompletionModal() {
            let correct = 0;
            let wrong = 0;
            Object.values(userAnswers).forEach(ans => {
                if (ans.isCorrect) correct++;
                else wrong++;
            });

            const tTrans = topicTranslationsMap[activeTopic] || { mr: activeTopic, en: activeTopic };
            modalSetTitle.innerHTML = `<span style="font-weight: 700; color: #fff;">${activeSubtopic || tTrans.mr}</span><br><span style="font-size: 12px; color: #94a3b8;">${tTrans.mr} • ${tTrans.en}</span>`;
            modalTotalQ.textContent = currentQuestions.length;
            modalCorrectQ.textContent = correct;
            modalWrongQ.textContent = wrong;

            scoreModalBackdrop.style.display = 'flex';
            if (correct > 0) {
                triggerCelebration();
            }
        }

        if (btnFinishDeck) {
            btnFinishDeck.addEventListener('click', () => { if (checkCanAttemptTest()) showCompletionModal(); });
        }

        btnRetakePractice.addEventListener('click', () => {
            if (!checkCanAttemptTest()) return;
            scoreModalBackdrop.style.display = 'none';
            userAnswers = {};
            renderAllQuestions();
            updateScoreDisplay();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        btnNextSetPractice.addEventListener('click', () => {
            if (!checkCanAttemptTest()) return;
            scoreModalBackdrop.style.display = 'none';
            // Find next practice set or next topic
            const nextSetInfo = findNextPracticeSet();
            if (nextSetInfo) {
                selectPracticeSet(nextSetInfo.practice_id, nextSetInfo.subject_name, nextSetInfo.topic_name, nextSetInfo.subtopic_name);
            } else {
                alert('अभिनंदन! तुम्ही या विषयातील सर्व सराव संच पूर्ण केले आहेत.');
            }
        });

        function findNextPracticeSet() {
            let foundCurrent = false;
            for (const sName in fullSubjectTreeData) {
                const sData = fullSubjectTreeData[sName];
                for (const tName in sData.topics) {
                    const tData = sData.topics[tName];
                    for (const s of tData.sets) {
                        if (foundCurrent) {
                            return {
                                practice_id: s.practice_id,
                                subject_name: sName,
                                topic_name: tName,
                                subtopic_name: s.subtopic_name
                            };
                        }
                        if (s.practice_id === activePracticeId) {
                            foundCurrent = true;
                        }
                    }
                }
            }
            return null;
        }

        // Close modal on outside click
        scoreModalBackdrop.addEventListener('click', (e) => {
            if (e.target === scoreModalBackdrop) {
                scoreModalBackdrop.style.display = 'none';
            }
        });

        // ─────────────────────────────────────────────────────────────────────
        // 10. INITIAL LOAD: LOAD ALL QUESTIONS OF DEFAULT MAIN TOPIC DIRECTLY
        // ─────────────────────────────────────────────────────────────────────
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const urlPracticeId = urlParams.get('practice_id');
            const urlSubject = urlParams.get('subject');
            const urlTopic = urlParams.get('topic');

            if (urlPracticeId) {
                selectPracticeSet(urlPracticeId, activeSubject, activeTopic, activeSubtopic, true);
            } else if (urlSubject && urlTopic) {
                selectMainTopic(urlSubject, urlTopic, true);
            } else {
                selectMainTopic(activeSubject, activeTopic, true);
            }
        });

        // ─────────────────────────────────────────────────────────────────────
        // 11. PWA & MOBILE CHROME APP INSTALL PROMPT
        // ─────────────────────────────────────────────────────────────────────
        let deferredPrompt = null;
        const mobileInstallBanner = document.getElementById('mobileInstallBanner');
        const btnTriggerInstall = document.getElementById('btnTriggerInstall');
        const btnLaterInstall = document.getElementById('btnLaterInstall');
        const btnDismissInstall = document.getElementById('btnDismissInstall');
        const installGuideBox = document.getElementById('installGuideBox');
        const guideIos = document.getElementById('guideIos');
        const guideAndroid = document.getElementById('guideAndroid');

        // Register Service Worker for PWA / Chrome App capability
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js').catch(() => {});
            });
        }

        // Capture Chrome's native beforeinstallprompt event
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            triggerMobileInstallBanner();
        });

        // Check if already running in standalone PWA / Home Screen mode
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

        function triggerMobileInstallBanner() {
            if (isStandalone) return;
            if (window.innerWidth > 900) return; // Mobile screens only

            // Check if user dismissed recently (within 24 hours)
            const lastDismissed = localStorage.getItem('mpsc_pwa_dismissed');
            if (lastDismissed && (Date.now() - parseInt(lastDismissed, 10)) < 24 * 60 * 60 * 1000) {
                return;
            }

            if (mobileInstallBanner) {
                mobileInstallBanner.style.display = 'block';
                setTimeout(() => {
                    mobileInstallBanner.classList.add('show');
                }, 1500); // 1.5s gentle delay after page loads
            }
        }

        // Auto-show check on mobile devices
        const isMobilePhone = /Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth <= 768;
        if (isMobilePhone && !isStandalone) {
            setTimeout(triggerMobileInstallBanner, 2000);
        }

        function dismissInstallBanner() {
            if (mobileInstallBanner) {
                mobileInstallBanner.classList.remove('show');
                setTimeout(() => {
                    mobileInstallBanner.style.display = 'none';
                }, 350);
            }
            localStorage.setItem('mpsc_pwa_dismissed', Date.now().toString());
        }

        if (btnLaterInstall) btnLaterInstall.addEventListener('click', dismissInstallBanner);
        if (btnDismissInstall) btnDismissInstall.addEventListener('click', dismissInstallBanner);

        if (btnTriggerInstall) {
            btnTriggerInstall.addEventListener('click', async () => {
                if (deferredPrompt) {
                    // Trigger Chrome's native Add to Home screen / Install dialog!
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    deferredPrompt = null;
                    if (outcome === 'accepted') {
                        dismissInstallBanner();
                    }
                } else {
                    // Guide user to add shortcut if browser doesn't support 1-tap prompt
                    const isIos = /iPhone|iPad|iPod/i.test(navigator.userAgent);
                    if (installGuideBox) {
                        installGuideBox.style.display = 'block';
                        if (isIos) {
                            if (guideIos) guideIos.style.display = 'block';
                            if (guideAndroid) guideAndroid.style.display = 'none';
                        } else {
                            if (guideAndroid) guideAndroid.style.display = 'block';
                            if (guideIos) guideIos.style.display = 'none';
                        }
                    }
                }
            });
        }

        window.addEventListener('appinstalled', () => {
            deferredPrompt = null;
            dismissInstallBanner();
        });
    
        // ─────────────────────────────────────────────────────────────────────
        // 12. MONETIZATION, 30-MIN TIMER & AUTH/PAYWALL LOGIC
        // ─────────────────────────────────────────────────────────────────────
        const IS_USER_SUBSCRIBED = <?= $isSubscribedUser ? 'true' : 'false' ?>;
        const SERVER_TRIAL_EXPIRED = <?= $isTrialTimeOver ? 'true' : 'false' ?>;
        const SERVER_SECONDS_REMAINING = <?= (int)$serverSecondsRemaining ?>;
        let LOGGED_IN_EMAIL = <?= json_encode($sessEmail ?? '') ?>;
        const USER_EXPIRES_AT = <?= json_encode($userExpiresAt ?? '') ?>;
        const FREE_TRIAL_LIMIT_SECONDS = <?= (int)$trialLimitSeconds ?>;
        const GOOGLE_CLIENT_ID = <?= json_encode(defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '') ?>;
        const RAZORPAY_PAYMENT_URL = <?= json_encode(defined('RAZORPAY_PAYMENT_URL') ? RAZORPAY_PAYMENT_URL : 'https://rzp.io/rzp/FTFoJWx') ?>;

        let elapsedSeconds = 0;
        let isTrialExpired = false;
        let timerInterval = null;
        let statusPollInterval = null;

        const navTimerDisplay = document.getElementById('navTimerDisplay');
        const navTrialTimerPill = document.getElementById('navTrialTimerPill');
        const authModalBackdrop = document.getElementById('authModalBackdrop');
        const authModalCloseBtn = document.getElementById('authModalCloseBtn');
        const authModalBadge = document.getElementById('authModalBadge');
        const authModalTitle = document.getElementById('authModalTitle');
        const authModalSub = document.getElementById('authModalSub');
        const paywallModalBackdrop = document.getElementById('paywallModalBackdrop');
        const paywallModalCloseBtn = document.getElementById('paywallModalCloseBtn');
        const paywallUserEmailDisplay = document.getElementById('paywallUserEmailDisplay');
        const paywallStatusMsg = document.getElementById('paywallStatusMsg');
        const btnPayRazorpay = document.getElementById('btnPayRazorpay');

        const isTestLockRequested = new URLSearchParams(window.location.search).get('test_lock') === '1';

        function initTrialTimer() {
            if (IS_USER_SUBSCRIBED) {
                return;
            }

            if (new URLSearchParams(window.location.search).get('reset_done') === '1') {
                localStorage.removeItem('mpsc_trial_completed');
                localStorage.removeItem('mpsc_practice_timer_elapsed');
            }

            // 1. Check if server already marked trial expired (either IP expired or registered user expired),
            // or if localStorage already has completed marker
            if (SERVER_TRIAL_EXPIRED || localStorage.getItem('mpsc_trial_completed') === '1') {
                elapsedSeconds = FREE_TRIAL_LIMIT_SECONDS;
                isTrialExpired = true;
                localStorage.setItem('mpsc_trial_completed', '1');
                localStorage.setItem('mpsc_practice_timer_elapsed', String(FREE_TRIAL_LIMIT_SECONDS));
                if (navTimerDisplay) navTimerDisplay.textContent = '00:00';
                if (navTrialTimerPill) {
                    navTrialTimerPill.classList.add('warning');
                    navTrialTimerPill.innerHTML = '<i class="fa-solid fa-clock"></i> <span>वेळ संपली</span>';
                }
                return;
            }

            // 2. Synchronize elapsed time with server state.
            // Server tracks elapsed time across all browsers via IP & account.
            const serverElapsed = Math.max(0, FREE_TRIAL_LIMIT_SECONDS - SERVER_SECONDS_REMAINING);
            const localElapsed = parseInt(localStorage.getItem('mpsc_practice_timer_elapsed') || '0', 10);
            elapsedSeconds = Math.max(localElapsed, serverElapsed);

            if (isTestLockRequested) {
                elapsedSeconds = FREE_TRIAL_LIMIT_SECONDS;
            }

            updateTimerDisplay();

            if (elapsedSeconds >= FREE_TRIAL_LIMIT_SECONDS) {
                isTrialExpired = true;
                localStorage.setItem('mpsc_trial_completed', '1');
                localStorage.setItem('mpsc_practice_timer_elapsed', String(FREE_TRIAL_LIMIT_SECONDS));
                if (navTimerDisplay) navTimerDisplay.textContent = '00:00';
                if (navTrialTimerPill) {
                    navTrialTimerPill.classList.add('warning');
                    navTrialTimerPill.innerHTML = '<i class="fa-solid fa-clock"></i> <span>वेळ संपली</span>';
                }
                return;
            }

            timerInterval = setInterval(() => {
                elapsedSeconds++;
                localStorage.setItem('mpsc_practice_timer_elapsed', elapsedSeconds.toString());
                updateTimerDisplay();

                if (elapsedSeconds >= FREE_TRIAL_LIMIT_SECONDS) {
                    clearInterval(timerInterval);
                    isTrialExpired = true;
                    localStorage.setItem('mpsc_trial_completed', '1');
                    if (navTimerDisplay) navTimerDisplay.textContent = '00:00';
                    if (navTrialTimerPill) {
                        navTrialTimerPill.classList.add('warning');
                        navTrialTimerPill.innerHTML = '<i class="fa-solid fa-clock"></i> <span>वेळ संपली</span>';
                    }
                }
            }, 1000);
        }

        function updateTimerDisplay() {
            if (!navTimerDisplay) return;
            const remaining = Math.max(0, FREE_TRIAL_LIMIT_SECONDS - elapsedSeconds);
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            navTimerDisplay.textContent = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');

            const warnThreshold = Math.min(300, Math.max(10, Math.floor(FREE_TRIAL_LIMIT_SECONDS * 0.25)));
            if (remaining <= warnThreshold && navTrialTimerPill) {
                navTrialTimerPill.classList.add('warning');
            }
        }

        // Check if user is allowed to attempt a test / answer question
        function checkCanAttemptTest() {
            if (IS_USER_SUBSCRIBED) {
                return true; // Active subscribers have 100% full access
            }
            if (!isTrialExpired) {
                return true; // Within free trial time
            }
            // Free trial expired -> push subscription/login box
            if (!LOGGED_IN_EMAIL) {
                openAuthModal(false);
            } else {
                openPaywallModal(false);
            }
            return false;
        }

        
        // Intercept Google Search button clicks
        function handleGoogleSearchClick(event, url) {
            if (!checkCanAttemptTest()) {
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                return false;
            }
            return true;
        }

        // Intercept Exam Tab & Goal Switching when trial expired
        document.addEventListener('click', (e) => {
            const examLink = e.target.closest('.nav-exam-tab') || e.target.closest('.exam-pill');
            if (examLink) {
                if (!checkCanAttemptTest()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }
        }, true);

        function openAuthModal() {
            if (!authModalBackdrop) return;
            if (authModalCloseBtn) authModalCloseBtn.style.display = 'block';
            authModalBackdrop.classList.add('show');

            // Render Google Sign-in button cleanly via Google Identity Services
            if (window.google && window.google.accounts && GOOGLE_CLIENT_ID && GOOGLE_CLIENT_ID.length > 5) {
                try {
                    google.accounts.id.initialize({
                        client_id: GOOGLE_CLIENT_ID,
                        callback: handleGoogleCredentialResponse,
                        auto_select: false,
                        cancel_on_tap_outside: true
                    });
                    const btnContainer = document.getElementById('googleSignInBtnContainer');
                    if (btnContainer && !btnContainer.hasChildNodes()) {
                        google.accounts.id.renderButton(btnContainer, {
                            type: 'standard',
                            shape: 'rectangular',
                            theme: 'outline',
                            text: 'signin_with',
                            size: 'large',
                            logo_alignment: 'left',
                            width: 320
                        });
                    }
                    google.accounts.id.prompt();
                } catch (err) {
                    console.log('Google Identity Services Init:', err);
                }
            }
        }

        function closeAuthModal() {
            if (authModalBackdrop) authModalBackdrop.classList.remove('show');
        }

        function openPaywallModal() {
            if (!paywallModalBackdrop) return;
            if (authModalBackdrop) authModalBackdrop.classList.remove('show');

            if (paywallModalCloseBtn) paywallModalCloseBtn.style.display = 'block';

            if (LOGGED_IN_EMAIL && paywallUserEmailDisplay) {
                paywallUserEmailDisplay.textContent = LOGGED_IN_EMAIL;
            }

            paywallModalBackdrop.classList.add('show');
            startSubscriptionStatusPolling();
        }

        function closePaywallModal() {
            if (paywallModalBackdrop) paywallModalBackdrop.classList.remove('show');
            stopSubscriptionStatusPolling();
        }

        // Close on backdrop click (user can dismiss and browse website anytime!)
        if (authModalBackdrop) {
            authModalBackdrop.addEventListener('click', (e) => {
                if (e.target === authModalBackdrop) closeAuthModal();
            });
        }
        if (paywallModalBackdrop) {
            paywallModalBackdrop.addEventListener('click', (e) => {
                if (e.target === paywallModalBackdrop) closePaywallModal();
            });
        }

        // Google One-Tap & Sign-in Credential Callback
        window.handleGoogleCredentialResponse = async function(response) {
            try {
                const formData = new FormData();
                formData.append('action', 'auth_google_user');
                formData.append('credential', response.credential);

                const res = await fetch('practice.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.status === 'success') {
                    LOGGED_IN_EMAIL = data.user.email;
                    if (paywallUserEmailDisplay) {
                        paywallUserEmailDisplay.textContent = LOGGED_IN_EMAIL;
                    }
                    if (data.is_subscribed) {
                        alert('अभिनंदन! तुमची अमर्यादित सदस्यता सक्रिय आहे.');
                        window.location.reload();
                    } else {
                        closeAuthModal();
                        openPaywallModal();
                    }
                } else {
                    alert(data.message || 'लॉगिन अयशस्वी झाले. कृपया पुन्हा प्रयत्न करा.');
                }
            } catch (err) {
                console.error('Google Auth Error:', err);
                alert('लॉगिन करताना त्रुटी आली. कृपया पुन्हा प्रयत्न करा.');
            }
        };

        // Razorpay UPI Payment Click & Redirect (Uses Google Email Directly)
        async function handleRazorpayPaymentClick(e) {
            if (e) e.preventDefault();
            const email = LOGGED_IN_EMAIL;

            if (!email) {
                openAuthModal();
                return;
            }

            if (btnPayRazorpay) {
                btnPayRazorpay.disabled = true;
                btnPayRazorpay.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> सुरक्षित पेमेंट सुरु करत आहे...';
            }
            if (paywallStatusMsg) {
                paywallStatusMsg.style.display = 'block';
            }

            try {
                // Record payment intent in backend database using Google Email
                const formData = new FormData();
                formData.append('action', 'record_payment_intent');
                formData.append('email', email);

                const res = await fetch('practice.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                const baseUrl = (data && data.payment_url) ? data.payment_url : RAZORPAY_PAYMENT_URL;
                let paymentUrl = baseUrl;
                const separator = paymentUrl.includes('?') ? '&' : '?';
                const params = new URLSearchParams();
                params.set('prefill[email]', email);
                paymentUrl += separator + params.toString();

                // Open Razorpay checkout in new tab
                const payWindow = window.open(paymentUrl, '_blank');
                if (!payWindow || payWindow.closed || typeof payWindow.closed === 'undefined') {
                    window.location.href = paymentUrl;
                }

                if (paywallStatusMsg) {
                    paywallStatusMsg.innerHTML = '<i class="fa-solid fa-circle-check" style="color: #10b981;"></i> पेमेंट पेज उघडले आहे. पैसे भरल्यावर Razorpay Webhook द्वारे तात्काळ दैनिक ॲक्सेस दिला जाईल. पेज आपोआप सुरू होईल.';
                }
            } catch (err) {
                console.error('Payment Error:', err);
                alert('पेमेंट पेज उघडताना त्रुटी आली. कृपया पुन्हा प्रयत्न करा.');
            } finally {
                if (btnPayRazorpay) {
                    btnPayRazorpay.disabled = false;
                    btnPayRazorpay.innerHTML = '<i class="fa-solid fa-bolt"></i> ₹१० भरा (Pay ₹10 via UPI / Razorpay)';
                }
            }
        }

        // Live Polling: Check if Admin or Webhook has activated subscription
        function startSubscriptionStatusPolling() {
            if (statusPollInterval) clearInterval(statusPollInterval);
            statusPollInterval = setInterval(async () => {
                if (!LOGGED_IN_EMAIL) return;
                try {
                    const res = await fetch(`practice.php?action=check_user_status&email=${encodeURIComponent(LOGGED_IN_EMAIL)}`);
                    const data = await res.json();
                    if (data && data.is_subscribed) {
                        clearInterval(statusPollInterval);
                        alert('अभिनंदन! तुमचे पेमेंट यशस्वी झाले असून दैनिक अभ्यास पास (आज रात्री १२:०० पर्यंत) सक्रिय झाला आहे. आता तुम्ही अमर्यादित सराव करू शकता.');
                        window.location.reload();
                    }
                } catch (e) {
                    // Polling silently ignores network glitches
                }
            }, 8000);
        }

        function stopSubscriptionStatusPolling() {
            if (statusPollInterval) {
                clearInterval(statusPollInterval);
                statusPollInterval = null;
            }
        }

        // User Logout
        async function handleUserLogout(e) {
            if (e) e.stopPropagation();
            if (!confirm('तुम्हाला लॉगआउट करायचे आहे का? (Do you want to log out?)')) return;

            try {
                await fetch('practice.php?action=logout_user');
            } catch (err) {}
            // Maintain trial completion so logging out cannot restart the free trial!
            localStorage.setItem('mpsc_trial_completed', '1');
            localStorage.setItem('mpsc_practice_timer_elapsed', String(FREE_TRIAL_LIMIT_SECONDS));
            window.location.reload();
        }

        // ─────────────────────────────────────────────────────────────────────
        // 13. REAL-TIME STUDENT ENGAGEMENT & TIME SPENT TRACKING
        // ─────────────────────────────────────────────────────────────────────
        let lastEngagementPing = Date.now();

        function sendEngagementHeartbeat(isSync = false) {
            if (document.visibilityState !== 'visible' && !isSync) return;

            const now = Date.now();
            const deltaSeconds = Math.min(60, Math.max(1, Math.round((now - lastEngagementPing) / 1000)));
            lastEngagementPing = now;

            const formData = new FormData();
            formData.append('action', 'track_engagement');
            formData.append('delta_seconds', String(deltaSeconds));
            formData.append('device', /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent) ? 'Mobile' : 'Desktop');
            if (LOGGED_IN_EMAIL) {
                formData.append('email', LOGGED_IN_EMAIL);
            }

            if (navigator.sendBeacon) {
                navigator.sendBeacon('practice.php', formData);
            } else {
                fetch('practice.php', { method: 'POST', body: formData, keepalive: true }).catch(() => {});
            }
        }

        // Send heartbeat every 15 seconds while student is active on page
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                sendEngagementHeartbeat(false);
            }
        }, 15000);

        // Pause/resume tracking when tab is hidden or closed
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                sendEngagementHeartbeat(true);
            } else {
                lastEngagementPing = Date.now();
            }
        });

        window.addEventListener('beforeunload', () => sendEngagementHeartbeat(true));
        window.addEventListener('pagehide', () => sendEngagementHeartbeat(true));

        // 14. Real-time Daily Pass Expiry Countdown Timer
        function initDailyCountdownTimer() {
            if (!IS_USER_SUBSCRIBED || !USER_EXPIRES_AT) return;
            const timerEl = document.getElementById('navDailyExpiryTimer');
            if (!timerEl) return;

            const expiryTs = new Date(USER_EXPIRES_AT.replace(' ', 'T')).getTime();

            function updateDailyTimer() {
                const now = Date.now();
                const diffSec = Math.max(0, Math.floor((expiryTs - now) / 1000));
                if (diffSec <= 0) {
                    timerEl.textContent = 'वेळ संपली';
                    setTimeout(() => window.location.reload(), 2000);
                    return;
                }
                const hours = Math.floor(diffSec / 3600);
                const mins = Math.floor((diffSec % 3600) / 60);
                if (hours >= 1) {
                    timerEl.textContent = `${hours} तास शिल्लक`;
                } else {
                    timerEl.textContent = `${mins} मिनिटे शिल्लक`;
                }
            }
            updateDailyTimer();
            setInterval(updateDailyTimer, 30000);
        }

        // Start timers on page load
        window.addEventListener('DOMContentLoaded', () => {
            initTrialTimer();
            initDailyCountdownTimer();
        });

    </script>
</body>
</html>
