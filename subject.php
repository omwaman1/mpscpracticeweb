<?php
// sync/subject.php - Subject-specific Question Listing Page
// URL: /search/economics, /search/polity, /search/geography, etc.
require_once __DIR__ . '/config.php';
header('X-Debug-Version: v2026-08-07-v1');

$slug = isset($_GET['slug']) ? rtrim(trim(strtolower($_GET['slug'])), '/') : '';

// ─── Subject Slug → Database Filter Map ───
// Each slug maps to: label, icon, gradient colors, and SQL WHERE conditions
$subjectMap = [
    'economics' => [
        'label'    => 'Economics / अर्थव्यवस्था',
        'icon'     => 'fa-building-columns',
        'gradient' => ['#f59e0b', '#d97706'],
        'where'    => "(category_name LIKE '%Economy%' OR category_name LIKE '%Economics%' OR category_name LIKE '%Indian Economy%' OR category_name LIKE '%Business%' OR category_name LIKE '%Financial%' OR category_name LIKE '%Banking%' OR subject_name LIKE '%Economy%' OR subject_name LIKE '%Economics%' OR subject_name LIKE '%Business%' OR test_title LIKE '%Economy%' OR test_title LIKE '%अर्थव्यवस्था%' OR test_title LIKE '%अर्थशास्त्र%')",
    ],
    'polity' => [
        'label'    => 'Polity / राज्यव्यवस्था',
        'icon'     => 'fa-landmark',
        'gradient' => ['#3b82f6', '#2563eb'],
        'where'    => "(category_name = 'Polity' OR subject_name LIKE '%Polity%' OR subject_name LIKE '%Political%' OR test_title LIKE '%Polity%' OR test_title LIKE '%राज्यव्यवस्था%')",
    ],
    'geography' => [
        'label'    => 'Geography / भूगोल',
        'icon'     => 'fa-earth-asia',
        'gradient' => ['#10b981', '#059669'],
        'where'    => "(category_name LIKE '%Geography%' OR subject_name LIKE '%Geography%' OR test_title LIKE '%Geography%' OR test_title LIKE '%भूगोल%')",
    ],
    'history' => [
        'label'    => 'History / इतिहास',
        'icon'     => 'fa-scroll',
        'gradient' => ['#8b5cf6', '#7c3aed'],
        'where'    => "(category_name LIKE '%History%' OR category_name LIKE '%Ancient%' OR category_name LIKE '%Medieval%' OR category_name LIKE '%Modern India%' OR category_name LIKE '%Art and Culture%' OR subject_name LIKE '%History%' OR test_title LIKE '%History%' OR test_title LIKE '%इतिहास%')",
    ],
    'science' => [
        'label'    => 'General Science / सामान्य विज्ञान',
        'icon'     => 'fa-flask-vial',
        'gradient' => ['#06b6d4', '#0891b2'],
        'where'    => "(category_name LIKE '%Physics%' OR category_name LIKE '%Chemistry%' OR category_name LIKE '%Biology%' OR subject_name LIKE '%Science%' OR subject_name LIKE '%Physics%' OR subject_name LIKE '%Chemistry%' OR subject_name LIKE '%Biology%' OR test_title LIKE '%Science%' OR test_title LIKE '%विज्ञान%')",
    ],
    'current-affairs' => [
        'label'    => 'Current Affairs / चालू घडामोडी',
        'icon'     => 'fa-newspaper',
        'gradient' => ['#ec4899', '#db2777'],
        'where'    => "(subject_name LIKE '%Current Affairs%' OR category_name LIKE '%Current Affairs%' OR category_name LIKE '%Monthly Current%' OR category_name LIKE '%Awards%' OR category_name LIKE '%Sports%' OR category_name LIKE '%States Affairs%' OR category_name LIKE '%National Affairs%' OR test_title LIKE '%Current Affairs%' OR test_title LIKE '%चालू घडामोडी%')",
    ],
    'marathi' => [
        'label'    => 'Marathi / मराठी व्याकरण',
        'icon'     => 'fa-language',
        'gradient' => ['#f97316', '#ea580c'],
        'where'    => "(subject_name = 'मराठी' OR subject_name = 'Marathi' OR category_name LIKE '%शब्द%' OR category_name LIKE '%वर्ण%' OR category_name LIKE '%वाक्य%' OR test_title LIKE '%Marathi%' OR test_title LIKE '%मराठी%')",
    ],
    'english' => [
        'label'    => 'English Language',
        'icon'     => 'fa-spell-check',
        'gradient' => ['#6366f1', '#4f46e5'],
        'where'    => "(subject_name = 'English' OR category_name LIKE '%Grammar%' OR category_name LIKE '%Vocabulary%' OR category_name LIKE '%Verbal Ability%' OR test_title LIKE '%English%')",
    ],
    'reasoning' => [
        'label'    => 'Logical Reasoning / तर्कशक्ती',
        'icon'     => 'fa-brain',
        'gradient' => ['#14b8a6', '#0d9488'],
        'where'    => "(subject_name LIKE '%Reasoning%' OR subject_name LIKE '%CSAT%' OR category_name LIKE '%Series%' OR category_name LIKE '%Number System%' OR category_name LIKE '%Coding%' OR test_title LIKE '%Reasoning%' OR test_title LIKE '%तर्कशक्ती%')",
    ],
    'maths' => [
        'label'    => 'Quantitative Aptitude / गणित',
        'icon'     => 'fa-calculator',
        'gradient' => ['#ef4444', '#dc2626'],
        'where'    => "(subject_name LIKE '%Quantitative%' OR subject_name LIKE '%Mathematics%' OR subject_name LIKE '%Data Interpretation%' OR category_name LIKE '%Paper II%' OR test_title LIKE '%Maths%' OR test_title LIKE '%गणित%')",
    ],
    'environment' => [
        'label'    => 'Environment / पर्यावरण',
        'icon'     => 'fa-leaf',
        'gradient' => ['#22c55e', '#16a34a'],
        'where'    => "(category_name LIKE '%Ecology%' OR category_name LIKE '%Environment%' OR subject_name LIKE '%Environment%' OR test_title LIKE '%Environment%' OR test_title LIKE '%पर्यावरण%')",
    ],
    'infrastructure' => [
        'label'    => 'Infrastructure & Development / पायाभूत सुविधा व विकास',
        'icon'     => 'fa-road-bridge',
        'gradient' => ['#059669', '#10b981'],
        'where'    => "(topic_name LIKE '%Infrastructure%' OR topic_name LIKE '%पायाभूत%' OR topic_name LIKE '%वाहतूक%' OR topic_name LIKE '%ऊर्जा%' OR topic_name LIKE '%उर्जा%' OR question_mr LIKE '%पायाभूत सुविधा%' OR subject_name LIKE '%Infrastructure%')",
    ],
    'biology' => [
        'label'    => 'Biology / जीवशास्त्र व आरोग्यशास्त्र',
        'icon'     => 'fa-dna',
        'gradient' => ['#10b981', '#047857'],
        'where'    => "(topic_name LIKE '%Biology%' OR topic_name LIKE '%जीवशास्त्र%' OR topic_name LIKE '%आरोग्यशास्त्र%' OR topic_name LIKE '%जैव तंत्रज्ञान%' OR subtopic_name IN ('प्राणी वर्गीकरण', 'वनस्पती शास्त्र', 'वनस्पती वर्गीकरण', 'पोषण', 'रक्ताचे कार्य', 'उती व पेशीचे कार्य', 'मानवातील समन्वय', 'श्वसन व उत्सर्जन संस्था', 'जैव तंत्रज्ञान', 'उत्क्रांती व सिद्धांत', 'प्रजनन संस्था', 'अस्थी संस्था', 'आरोग्यशास्त्र') OR subject_name = 'Biology' OR category_name LIKE '%Biology%')",
    ],
    'jivshastra' => [
        'label'    => 'Biology / जीवशास्त्र व आरोग्यशास्त्र',
        'icon'     => 'fa-dna',
        'gradient' => ['#10b981', '#047857'],
        'where'    => "(topic_name LIKE '%Biology%' OR topic_name LIKE '%जीवशास्त्र%' OR topic_name LIKE '%आरोग्यशास्त्र%' OR topic_name LIKE '%जैव तंत्रज्ञान%' OR subtopic_name IN ('प्राणी वर्गीकरण', 'वनस्पती शास्त्र', 'वनस्पती वर्गीकरण', 'पोषण', 'रक्ताचे कार्य', 'उती व पेशीचे कार्य', 'मानवातील समन्वय', 'श्वसन व उत्सर्जन संस्था', 'जैव तंत्रज्ञान', 'उत्क्रांती व सिद्धांत', 'प्रजनन संस्था', 'अस्थी संस्था', 'आरोग्यशास्त्र') OR subject_name = 'Biology' OR category_name LIKE '%Biology%')",
    ],
    'physics' => [
        'label'    => 'Physics / भौतिकशास्त्र',
        'icon'     => 'fa-atom',
        'gradient' => ['#3b82f6', '#1d4ed8'],
        'where'    => "(topic_name LIKE '%Physics%' OR topic_name LIKE '%भौतिकशास्त्र%' OR subject_name = 'Physics' OR category_name LIKE '%Physics%')",
    ],
    'chemistry' => [
        'label'    => 'Chemistry / रसायनशास्त्र',
        'icon'     => 'fa-flask',
        'gradient' => ['#ec4899', '#be185d'],
        'where'    => "(topic_name LIKE '%Chemistry%' OR topic_name LIKE '%रसायनशास्त्र%' OR subject_name = 'Chemistry' OR category_name LIKE '%Chemistry%')",
    ],
];

// ─── Handle AJAX Data Request ───
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$slug) {
        echo json_encode(['status' => 'error', 'message' => 'Missing subject slug']);
        exit;
    }

    if (isset($subjectMap[$slug])) {
        $subj = $subjectMap[$slug];
    } else {
        // Dynamic Fallback for custom slugs (e.g. /search/infrastructure, /search/agriculture)
        $cleanSlugName = ucwords(str_replace(['-', '_'], ' ', $slug));
        $subj = [
            'label'    => $cleanSlugName . ' Questions',
            'icon'     => 'fa-book-open',
            'gradient' => ['#4f46e5', '#3730a3'],
            'where'    => "(topic_name LIKE '%$slug%' OR subject_name LIKE '%$slug%' OR category_name LIKE '%$slug%' OR question_mr LIKE '%$slug%' OR question_en LIKE '%$slug%')"
        ];
    }
    $topic = isset($_GET['topic']) ? trim($_GET['topic']) : '';
    $limit = isset($_GET['limit']) ? min(max(intval($_GET['limit']), 1), 500) : 500;
    $offset = isset($_GET['offset']) ? max(intval($_GET['offset']), 0) : 0;

    try {
        $pdo = getDBConnection();
        
        $whereSql = $subj['where'];
        $params = [];

        if (!empty($topic)) {
            $whereSql .= " AND topic_name = :topic";
            $params[':topic'] = $topic;
        }

        // Count total matching questions
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_questions WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalCount = intval($countStmt->fetchColumn());

        // Fetch batch of max 500 questions
        $dataStmt = $pdo->prepare("SELECT * FROM tbl_questions WHERE {$whereSql} ORDER BY id ASC LIMIT {$limit} OFFSET {$offset}");
        $dataStmt->execute($params);
        $questions = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status'      => 'success',
            'total_count' => $totalCount,
            'count'       => count($questions),
            'limit'       => $limit,
            'offset'      => $offset,
            'has_more'    => ($offset + count($questions)) < $totalCount,
            'data'        => $questions
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        // Fallback to Cloudflare HTTPS API Bridge over HTTPS tunnel
        $apiUrl = "https://db.mpscabhyas.in/api.php?action=subject&slug=" . urlencode($slug) . "&limit={$limit}&offset={$offset}";
        if (!empty($topic)) $apiUrl .= "&topic=" . urlencode($topic);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, "HostingerBridge/1.0");
        $responseJson = curl_exec($ch);
        curl_close($ch);

        if ($responseJson) {
            echo $responseJson;
            exit;
        }

        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ─── Handle HTML Page Rendering ───
if (!$slug) {
    // If no slug, show subject index page
    $subjectCounts = [];
    try {
        $pdo = getDBConnection();
        foreach ($subjectMap as $key => $info) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_questions WHERE {$info['where']}");
            $subjectCounts[$key] = intval($stmt->fetchColumn());
        }
    } catch (Exception $e) {}
    
    // Render index page with all subject cards
    ?>
<!DOCTYPE html>
<html lang="mr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MPSC Question Bank - All Subjects | MPSC Abhyas</title>
    <meta name="description" content="Browse 23,000+ MPSC exam questions by subject. Economics, Polity, History, Geography, Science, Current Affairs and more.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #090d16;
            --card-bg: #0f172a;
            --card-border: rgba(255,255,255,0.1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; min-height: 100vh; padding: 24px 16px 60px; }
        .container { max-width: 900px; margin: 0 auto; }
        .page-header { text-align: center; margin-bottom: 32px; }
        .page-header h1 { font-family: 'Outfit', sans-serif; font-size: 28px; font-weight: 800; margin-bottom: 8px; }
        .page-header h1 i { color: #3b82f6; }
        .page-header p { font-size: 14px; color: var(--text-muted); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #60a5fa; font-size: 13px; font-weight: 600; text-decoration: none; margin-bottom: 20px; padding: 6px 14px; background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.25); border-radius: 8px; transition: all 0.2s ease; }
        .back-link:hover { background: rgba(59,130,246,0.2); transform: translateY(-1px); }
        .subjects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
        .subject-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 16px; padding: 20px; text-decoration: none; color: var(--text-main); transition: all 0.25s ease; position: relative; overflow: hidden; }
        .subject-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; border-radius: 16px 16px 0 0; }
        .subject-card:hover { transform: translateY(-4px); border-color: rgba(255,255,255,0.2); box-shadow: 0 12px 30px rgba(0,0,0,0.4); }
        .subject-card .card-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff; margin-bottom: 14px; }
        .subject-card .card-label { font-family: 'Outfit', sans-serif; font-size: 17px; font-weight: 700; margin-bottom: 6px; }
        .subject-card .card-count { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .subject-card .card-count strong { color: #f8fafc; font-size: 18px; }
        .subject-card .card-arrow { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); font-size: 18px; color: var(--text-muted); transition: all 0.2s ease; }
        .subject-card:hover .card-arrow { color: #fff; transform: translateY(-50%) translateX(4px); }
        @media (max-width: 600px) { .subjects-grid { grid-template-columns: 1fr; } .page-header h1 { font-size: 22px; } }
    </style>
</head>
<body>
<div class="container">
    <a href="/search/" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Search Engine</a>
    <div class="page-header">
        <h1><i class="fa-solid fa-book-open"></i> MPSC Question Bank</h1>
        <p>Browse all <strong>23,000+</strong> questions by subject — click any subject to view all questions</p>
    </div>
    <div class="subjects-grid">
        <?php foreach ($subjectMap as $sSlug => $sMeta):
            $count = $subjectCounts[$sSlug] ?? 0;
            $g1 = $sMeta['gradient'][0];
            $g2 = $sMeta['gradient'][1];
        ?>
        <a href="/search/<?php echo $sSlug; ?>" class="subject-card" style="--g1:<?php echo $g1; ?>; --g2:<?php echo $g2; ?>;">
            <style>.subject-card[style*="--g1:<?php echo $g1; ?>"]::before { background: linear-gradient(90deg, <?php echo $g1; ?>, <?php echo $g2; ?>); } .subject-card[style*="--g1:<?php echo $g1; ?>"] .card-icon { background: linear-gradient(135deg, <?php echo $g1; ?>, <?php echo $g2; ?>); }</style>
            <div class="card-icon"><i class="fa-solid <?php echo $sMeta['icon']; ?>"></i></div>
            <div class="card-label"><?php echo htmlspecialchars($sMeta['label']); ?></div>
            <div class="card-count"><strong><?php echo number_format($count); ?></strong> Questions</div>
            <i class="fa-solid fa-chevron-right card-arrow"></i>
        </a>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ─── Subject Detail Page ───
$subj = $subjectMap[$slug];
$g1 = $subj['gradient'][0];
$g2 = $subj['gradient'][1];

// Fetch topics for this subject's filter scope
$topics = [];
try {
    $pdo = getDBConnection();

    // Get total count
    $countStmt = $pdo->query("SELECT COUNT(*) FROM tbl_questions WHERE {$subj['where']}");
    $totalQuestions = intval($countStmt->fetchColumn());

    // Get distinct topics
    $topicStmt = $pdo->query("SELECT topic_name, COUNT(*) as cnt FROM tbl_questions WHERE {$subj['where']} AND topic_name IS NOT NULL AND topic_name != '' GROUP BY topic_name ORDER BY cnt DESC");
    $topics = $topicStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $totalQuestions = 0;
}

$examLabels = [
    'mpsc-state-service' => 'MPSC Rajyaseva',
    'mpsc-group-b' => 'MPSC Group-B',
    'mpsc-group-c' => 'MPSC Group-C',
    'maharashtra-general-knowledge' => 'Maharashtra GK',
    'maharashtra-talathi' => 'Talathi Bharti'
];
?>
<!DOCTYPE html>
<html lang="mr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($subj['label']); ?> - <?php echo number_format($totalQuestions); ?> Questions | MPSC Abhyas</title>
    <meta name="description" content="Practice all <?php echo number_format($totalQuestions); ?> <?php echo htmlspecialchars($subj['label']); ?> questions for MPSC, Talathi, and Maharashtra competitive exams.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        :root {
            --bg: #090d16;
            --card-bg: #0f172a;
            --card-border: rgba(255,255,255,0.1);
            --primary: <?php echo $g1; ?>;
            --primary-dark: <?php echo $g2; ?>;
            --accent: #f59e0b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --correct-bg: rgba(16,185,129,0.15);
            --correct-border: #10b981;
            --wrong-bg: rgba(239,68,68,0.15);
            --wrong-border: #ef4444;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; min-height: 100vh; padding: 16px 12px 60px; }
        .container { max-width: 980px; margin: 0 auto; }

        /* BACK LINK */
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #60a5fa; font-size: 13px; font-weight: 600; text-decoration: none; margin-bottom: 16px; padding: 6px 14px; background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.25); border-radius: 8px; transition: all 0.2s ease; }
        .back-link:hover { background: rgba(59,130,246,0.2); transform: translateY(-1px); }

        /* HERO HEADER */
        .hero { text-align: center; padding: 28px 20px; background: linear-gradient(135deg, var(--card-bg), #1e293b); border: 1px solid var(--card-border); border-radius: 20px; margin-bottom: 20px; position: relative; overflow: hidden; }
        .hero::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--primary), var(--primary-dark)); }
        .hero-icon { width: 64px; height: 64px; border-radius: 16px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); display: inline-flex; align-items: center; justify-content: center; font-size: 28px; color: #fff; margin-bottom: 14px; box-shadow: 0 8px 20px rgba(0,0,0,0.3); }
        .hero h1 { font-family: 'Outfit', sans-serif; font-size: 26px; font-weight: 800; margin-bottom: 6px; }
        .hero p { font-size: 13px; color: var(--text-muted); }
        .hero-stats { display: flex; align-items: center; justify-content: center; gap: 20px; margin-top: 14px; flex-wrap: wrap; }
        .hero-stat { font-size: 12px; font-weight: 600; color: var(--text-muted); display: flex; align-items: center; gap: 5px; }
        .hero-stat strong { font-size: 18px; color: var(--text-main); }

        /* TOPIC CHIPS */
        .topics-bar { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 14px; padding: 14px; margin-bottom: 20px; }
        .topics-bar-title { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
        .topic-chips { display: flex; flex-wrap: wrap; gap: 6px; max-height: 150px; overflow-y: auto; }
        .topic-chip { font-size: 11px; font-weight: 600; padding: 5px 10px; border-radius: 20px; cursor: pointer; transition: all 0.2s ease; user-select: none; border: 1px solid var(--card-border); background: rgba(255,255,255,0.04); color: var(--text-muted); }
        .topic-chip:hover { background: rgba(255,255,255,0.08); color: var(--text-main); transform: translateY(-1px); }
        .topic-chip.active { background: var(--primary); color: #fff; border-color: var(--primary); box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
        .topic-chip .chip-count { opacity: 0.7; font-weight: 400; margin-left: 3px; }

        /* MODE BAR */
        .mode-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        .stats-badge { font-size: 12px; font-weight: 600; color: var(--text-muted); background: rgba(255,255,255,0.05); padding: 6px 12px; border-radius: 8px; border: 1px solid var(--card-border); }
        .attempt-toggle-wrapper { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; user-select: none; font-size: 13px; font-weight: 700; color: var(--text-main); background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); padding: 8px 14px; border-radius: 20px; transition: all 0.2s ease; }
        .attempt-toggle-wrapper:hover { background: rgba(59,130,246,0.2); }
        .toggle-switch-input { display: none; }
        .toggle-slider { width: 36px; height: 20px; background: #334155; border-radius: 20px; position: relative; transition: background 0.25s ease; flex-shrink: 0; }
        .toggle-slider::before { content: ''; position: absolute; width: 14px; height: 14px; border-radius: 50%; background: #fff; top: 3px; left: 3px; transition: transform 0.25s ease; }
        .toggle-switch-input:checked + .toggle-slider { background: #10b981; }
        .toggle-switch-input:checked + .toggle-slider::before { transform: translateX(16px); }

        /* QUESTION CARDS */
        .results-list { display: flex; flex-direction: column; gap: 14px; }
        .question-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 14px; padding: 16px; transition: transform 0.15s ease, border-color 0.15s ease; }
        .question-card:hover { border-color: rgba(255,255,255,0.2); transform: translateY(-2px); }
        .tags-row { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
        .tag-pill { font-size: 10px; font-weight: 700; padding: 4px 9px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; }
        .tag-exam { background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); }
        .tag-cat { background: rgba(236,72,153,0.15); color: #f472b6; border: 1px solid rgba(236,72,153,0.3); }
        .tag-test { background: rgba(234,88,12,0.15); color: #fb923c; border: 1px solid rgba(234,88,12,0.3); }
        .tag-subject { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
        .tag-topic { background: rgba(168,85,247,0.15); color: #c084fc; border: 1px solid rgba(168,85,247,0.3); }
        .q-title { font-size: 15px; font-weight: 700; line-height: 1.5; color: #f8fafc; margin-bottom: 8px; }
        .q-title-en { font-size: 13px; color: #94a3b8; margin-bottom: 14px; line-height: 1.4; }
        .options-grid { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
        .option-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; font-size: 13px; color: var(--text-main); transition: all 0.2s ease; }
        .opt-text-container { display: flex; flex-direction: column; gap: 2px; flex: 1; }
        .opt-text-mr { font-size: 13px; font-weight: 500; color: var(--text-main); line-height: 1.4; }
        .opt-text-en { font-size: 12px; color: #94a3b8; line-height: 1.3; }
        .option-item.correct { background: var(--correct-bg); border-color: var(--correct-border); font-weight: 600; }
        .option-item.correct .opt-text-mr { color: #34d399; }
        .option-item.correct .opt-text-en { color: #6ee7b7; }
        .option-item.interactive { cursor: pointer; }
        .option-item.interactive:hover { background: rgba(59,130,246,0.1); border-color: rgba(59,130,246,0.4); }
        .option-item.user-correct { background: var(--correct-bg) !important; border-color: var(--correct-border) !important; font-weight: 700 !important; box-shadow: 0 0 12px rgba(16,185,129,0.4); animation: pulseSuccess 0.4s ease; }
        .option-item.user-correct .opt-text-mr { color: #34d399 !important; }
        .option-item.user-wrong { background: var(--wrong-bg) !important; border-color: var(--wrong-border) !important; font-weight: 700 !important; animation: shakeError 0.4s ease; }
        .option-item.user-wrong .opt-text-mr { color: #f87171 !important; }
        @keyframes pulseSuccess { 0% { transform: scale(1); } 50% { transform: scale(1.02); } 100% { transform: scale(1); } }
        @keyframes shakeError { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-4px); } 40%, 80% { transform: translateX(4px); } }
        .opt-num { width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--text-muted); flex-shrink: 0; }
        .option-item.correct .opt-num, .option-item.user-correct .opt-num { background: #10b981; color: #fff; }
        .option-item.user-wrong .opt-num { background: #ef4444; color: #fff; }
        .status-icon { margin-left: auto; font-size: 15px; }
        .status-icon.correct-icon { color: #10b981; }
        .status-icon.wrong-icon { color: #ef4444; }
        .attempt-feedback { margin-top: 10px; margin-bottom: 8px; font-size: 13px; font-weight: 700; padding: 8px 12px; border-radius: 8px; display: flex; align-items: center; gap: 8px; animation: fadeIn 0.3s ease; }
        .attempt-feedback.success { background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.3); color: #34d399; }
        .attempt-feedback.error { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #f87171; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
        .sol-action-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .sol-btn { background: rgba(59,130,246,0.1); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s ease; }
        .sol-btn:hover { background: rgba(59,130,246,0.2); }
        .btn-google-ai-search { display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg, rgba(66, 133, 244, 0.15) 0%, rgba(52, 168, 83, 0.15) 100%); border: 1px solid rgba(66, 133, 244, 0.35); color: #60a5fa; padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.25s ease; text-decoration: none; }
        .btn-google-ai-search:hover { background: linear-gradient(135deg, rgba(66, 133, 244, 0.28) 0%, rgba(52, 168, 83, 0.28) 100%); border-color: rgba(66, 133, 244, 0.6); transform: translateY(-1px); color: #93c5fd; }
        .lang-switch-pills { display: inline-flex; align-items: center; background: #1e293b; border: 1px solid var(--card-border); border-radius: 20px; padding: 2px; gap: 2px; }
        .lang-pill { background: transparent; border: none; color: var(--text-muted); font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 16px; cursor: pointer; transition: all 0.2s ease; }
        .lang-pill:hover { color: #fff; }
        .lang-pill.active { background: var(--primary); color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
        .sol-box { display: none; margin-top: 10px; padding: 14px; background: #1e293b; border-left: 3px solid var(--accent); border-radius: 8px; font-size: 13px; line-height: 1.6; color: #cbd5e1; }
        .sol-box.show { display: block; }
        .sol-content.sol-hidden { display: none !important; }
        .sol-box img { max-width: 100%; height: auto; border-radius: 6px; margin: 8px 0; }

        /* LOAD MORE */
        .load-more-container { text-align: center; margin-top: 20px; margin-bottom: 20px; }
        .load-more-btn { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: #fff; border: none; padding: 12px 24px; border-radius: 12px; font-size: 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px rgba(0,0,0,0.3); transition: all 0.2s ease; }
        .load-more-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.4); }

        .empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 32px; margin-bottom: 10px; opacity: 0.6; }

        @media (max-width: 600px) {
            .hero h1 { font-size: 20px; }
            .hero-stats { gap: 12px; }
            .sol-action-bar { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
<div class="container">
    <a href="/search/" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Search Engine</a>

    <!-- HERO HEADER -->
    <div class="hero">
        <div class="hero-icon"><i class="fa-solid <?php echo $subj['icon']; ?>"></i></div>
        <h1><?php echo htmlspecialchars($subj['label']); ?></h1>
        <p>Complete Question Bank — All Exams Combined</p>
        <div class="hero-stats">
            <div class="hero-stat"><i class="fa-solid fa-list-check"></i> <strong><?php echo number_format($totalQuestions); ?></strong> Total Questions</div>
            <div class="hero-stat"><i class="fa-solid fa-tags"></i> <strong><?php echo count($topics); ?></strong> Topics</div>
        </div>
    </div>

    <!-- TOPIC CHIPS FILTER -->
    <?php if (count($topics) > 0): ?>
    <div class="topics-bar">
        <div class="topics-bar-title"><i class="fa-solid fa-filter"></i> Filter by Topic</div>
        <div class="topic-chips">
            <span class="topic-chip active" onclick="filterTopic('')">All <span class="chip-count">(<?php echo number_format($totalQuestions); ?>)</span></span>
            <?php foreach ($topics as $t): ?>
                <span class="topic-chip" onclick="filterTopic('<?php echo htmlspecialchars(addslashes($t['topic_name'])); ?>')"><?php echo htmlspecialchars($t['topic_name']); ?> <span class="chip-count">(<?php echo $t['cnt']; ?>)</span></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODE BAR -->
    <div class="mode-bar">
        <div class="stats-badge" id="statsBadge">
            <i class="fa-solid fa-list-check"></i> <span id="matchCount">0</span> Loaded
        </div>
        <label class="attempt-toggle-wrapper">
            <input type="checkbox" id="attemptModeToggle" class="toggle-switch-input" checked onchange="toggleAttemptMode()">
            <span class="toggle-slider"></span>
            <span><i class="fa-solid fa-gamepad"></i> Attempt Mode</span>
        </label>
    </div>

    <!-- RESULTS -->
    <div id="resultsList" class="results-list">
        <div class="empty-state">
            <i class="fa-solid fa-spinner fa-spin" style="font-size: 38px; color: var(--primary); margin-bottom: 12px;"></i>
            <p>Loading questions...</p>
        </div>
    </div>


</div>

<script>
    const SLUG = '<?php echo $slug; ?>';
    const examLabels = <?php echo json_encode($examLabels, JSON_UNESCAPED_UNICODE); ?>;

    let isAttemptMode = true;
    let attemptedQuestions = {};
    let activeTopic = '';

    let currentOffset = 0;
    const batchLimit = 500;
    let totalQuestionsCount = 0;
    let isFetchingBatch = false;

    document.addEventListener('DOMContentLoaded', () => {
        performSearch(true);
    });

    function toggleAttemptMode() {
        isAttemptMode = document.getElementById('attemptModeToggle').checked;
        attemptedQuestions = {};
        performSearch(true);
    }

    function filterTopic(topic) {
        activeTopic = topic;
        document.querySelectorAll('.topic-chip').forEach(c => c.classList.remove('active'));
        if (event && event.target) {
            event.target.closest('.topic-chip').classList.add('active');
        }
        performSearch(true);
    }

    function renderOptionText(mr, en) {
        const textMr = mr ? mr.trim() : '';
        const textEn = en ? en.trim() : '';
        if (textMr && textEn && textMr !== textEn) {
            return `<div class="opt-text-container"><div class="opt-text-mr">${textMr}</div><div class="opt-text-en">${textEn}</div></div>`;
        }
        return `<div class="opt-text-container"><div class="opt-text-mr">${textMr || textEn}</div></div>`;
    }

    function handleOptionSelect(qId, selectedOpt, correctOpt) {
        if (!isAttemptMode) return;
        if (attemptedQuestions[qId]) return;
        attemptedQuestions[qId] = selectedOpt;
        const isCorrect = (selectedOpt === correctOpt);
        const selectedEl = document.getElementById(`opt_${qId}_${selectedOpt}`);
        const correctEl = document.getElementById(`opt_${qId}_${correctOpt}`);
        const feedbackEl = document.getElementById(`feedback_${qId}`);

        if (isCorrect) {
            if (selectedEl) { selectedEl.classList.add('user-correct'); selectedEl.innerHTML += '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>'; }
            if (feedbackEl) { feedbackEl.className = 'attempt-feedback success'; feedbackEl.innerHTML = '<i class="fa-solid fa-trophy"></i> <strong>Correct Answer!</strong>'; }
            if (typeof confetti === 'function') { confetti({ particleCount: 80, spread: 70, origin: { y: 0.65 } }); }
        } else {
            if (selectedEl) { selectedEl.classList.add('user-wrong'); selectedEl.innerHTML += '<i class="fa-solid fa-circle-xmark status-icon wrong-icon"></i>'; }
            if (correctEl) { correctEl.classList.add('correct'); correctEl.innerHTML += '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>'; }
            if (feedbackEl) { feedbackEl.className = 'attempt-feedback error'; feedbackEl.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> <strong>Incorrect!</strong> Correct: Option ${correctOpt}`; }
            toggleSolution('sol_' + qId, true);
        }
    }

    function switchSolLang(qId, lang, event) {
        if (event) event.stopPropagation();
        toggleSolution('sol_' + qId, true);
        const mrC = document.getElementById(`sol_mr_${qId}`);
        const enC = document.getElementById(`sol_en_${qId}`);
        const btnMr = document.getElementById(`btn_mr_${qId}`);
        const btnEn = document.getElementById(`btn_en_${qId}`);
        if (lang === 'mr') {
            if (mrC) mrC.classList.remove('sol-hidden');
            if (enC) enC.classList.add('sol-hidden');
            if (btnMr) btnMr.classList.add('active');
            if (btnEn) btnEn.classList.remove('active');
        } else {
            if (enC) enC.classList.remove('sol-hidden');
            if (mrC) mrC.classList.add('sol-hidden');
            if (btnEn) btnEn.classList.add('active');
            if (btnMr) btnMr.classList.remove('active');
        }
    }

    function toggleSolution(id, forceShow = false) {
        const box = document.getElementById(id);
        if (box) { if (forceShow) box.classList.add('show'); else box.classList.toggle('show'); }
    }

    async function performSearch(isReset = true) {
        if (isFetchingBatch) return;

        const resultsList = document.getElementById('resultsList');
        const matchCount = document.getElementById('matchCount');

        if (isReset) {
            currentOffset = 0;
            resultsList.innerHTML = '<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading questions (Batch of 500)...</p></div>';
        }

        isFetchingBatch = true;

        try {
            const url = `subject.php?ajax=1&slug=${SLUG}&topic=${encodeURIComponent(activeTopic)}&limit=${batchLimit}&offset=${currentOffset}`;
            const res = await fetch(url);
            const json = await res.json();

            if (json.status === 'success') {
                totalQuestionsCount = json.total_count || 0;
                const data = json.data || [];

                matchCount.textContent = `${(currentOffset + data.length).toLocaleString()} of ${totalQuestionsCount.toLocaleString()}`;

                if (isReset && data.length === 0) {
                    resultsList.innerHTML = '<div class="empty-state"><i class="fa-solid fa-circle-exclamation"></i><p>No questions found for this filter.</p></div>';
                    isFetchingBatch = false;
                    return;
                }

                const cardsHtml = data.map((q, idx) => {
                    const globalIdx = currentOffset + idx + 1;
                    const corr = parseInt(q.correct_option) || 1;
                    const eName = examLabels[q.test_series_slug] || q.test_series_slug || 'MPSC';
                    const hasMrSol = !!q.solution_mr;
                    const hasEnSol = !!q.solution_en;

                    return `
                    <div class="question-card">
                        <div class="tags-row">
                            <span class="tag-pill tag-exam"><i class="fa-solid fa-graduation-cap"></i> ${eName}</span>
                            ${q.category_name ? `<span class="tag-pill tag-cat"><i class="fa-solid fa-layer-group"></i> ${q.category_name}</span>` : ''}
                            ${q.test_title ? `<span class="tag-pill tag-test"><i class="fa-solid fa-file-lines"></i> ${q.test_title}</span>` : ''}
                            ${q.topic_name ? `<span class="tag-pill tag-topic"><i class="fa-solid fa-tag"></i> ${q.topic_name}</span>` : ''}
                        </div>
                        <div class="q-title">Q${globalIdx}. ${q.question_mr || q.question_en}</div>
                        ${q.question_en && q.question_mr ? `<div class="q-title-en">Q. ${q.question_en}</div>` : ''}
                        <div class="options-grid">
                            <div id="opt_${q.id}_1" class="option-item ${!isAttemptMode ? (corr === 1 ? 'correct' : '') : 'interactive'}" onclick="handleOptionSelect(${q.id}, 1, ${corr})">
                                <span class="opt-num">1</span>${renderOptionText(q.opt1_mr, q.opt1_en)}${!isAttemptMode && corr === 1 ? '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>' : ''}
                            </div>
                            <div id="opt_${q.id}_2" class="option-item ${!isAttemptMode ? (corr === 2 ? 'correct' : '') : 'interactive'}" onclick="handleOptionSelect(${q.id}, 2, ${corr})">
                                <span class="opt-num">2</span>${renderOptionText(q.opt2_mr, q.opt2_en)}${!isAttemptMode && corr === 2 ? '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>' : ''}
                            </div>
                            <div id="opt_${q.id}_3" class="option-item ${!isAttemptMode ? (corr === 3 ? 'correct' : '') : 'interactive'}" onclick="handleOptionSelect(${q.id}, 3, ${corr})">
                                <span class="opt-num">3</span>${renderOptionText(q.opt3_mr, q.opt3_en)}${!isAttemptMode && corr === 3 ? '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>' : ''}
                            </div>
                            <div id="opt_${q.id}_4" class="option-item ${!isAttemptMode ? (corr === 4 ? 'correct' : '') : 'interactive'}" onclick="handleOptionSelect(${q.id}, 4, ${corr})">
                                <span class="opt-num">4</span>${renderOptionText(q.opt4_mr, q.opt4_en)}${!isAttemptMode && corr === 4 ? '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>' : ''}
                            </div>
                        </div>
                        <div id="feedback_${q.id}"></div>
                        <div class="sol-action-bar">
                            ${(hasMrSol || hasEnSol) ? `
                                <button class="sol-btn" onclick="toggleSolution('sol_${q.id}')"><i class="fa-solid fa-lightbulb"></i> View Explanation</button>
                            ` : '<div></div>'}
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <a href="https://www.google.com/search?q=${encodeURIComponent((q.question_mr || q.question_en || '').replace(/<[^>]*>/g, '').trim() + ' MPSC')}" target="_blank" class="btn-google-ai-search" title="Search this question directly on Google">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/>
                                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/>
                                    </svg>
                                    <span>Search Google</span>
                                </a>
                                ${(hasMrSol && hasEnSol) ? `
                                    <div class="lang-switch-pills">
                                        <button class="lang-pill active" id="btn_mr_${q.id}" onclick="switchSolLang(${q.id}, 'mr', event)">मराठी</button>
                                        <button class="lang-pill" id="btn_en_${q.id}" onclick="switchSolLang(${q.id}, 'en', event)">English</button>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                        ${(hasMrSol || hasEnSol) ? `
                            <div id="sol_${q.id}" class="sol-box">
                                ${hasMrSol ? `<div id="sol_mr_${q.id}" class="sol-content"><strong><i class="fa-solid fa-circle-info"></i> स्पष्टीकरण:</strong><br>${q.solution_mr}</div>` : ''}
                                ${hasEnSol ? `<div id="sol_en_${q.id}" class="sol-content ${hasMrSol ? 'sol-hidden' : ''}"><strong><i class="fa-solid fa-circle-info"></i> Explanation:</strong><br>${q.solution_en}</div>` : ''}
                            </div>
                        ` : ''}
                    </div>`;
                }).join('');

                if (isReset) {
                    resultsList.innerHTML = cardsHtml;
                } else {
                    const oldLoadBtn = document.getElementById('loadMoreContainer');
                    if (oldLoadBtn) oldLoadBtn.remove();
                    resultsList.insertAdjacentHTML('beforeend', cardsHtml);
                }

                currentOffset += data.length;

                // Render "Load Next 500 Questions" button if more exist
                if (json.has_more) {
                    const remaining = totalQuestionsCount - currentOffset;
                    const nextBatchSize = Math.min(500, remaining);
                    const loadMoreHtml = `
                    <div id="loadMoreContainer" style="text-align: center; margin: 28px 0 14px;">
                        <button id="loadMoreBtn" onclick="performSearch(false)" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: #ffffff; border: none; padding: 14px 32px; border-radius: 30px; font-size: 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; box-shadow: 0 4px 16px rgba(59,130,246,0.4); transition: all 0.2s ease;">
                            <i class="fa-solid fa-arrows-rotate"></i> Load Next ${nextBatchSize.toLocaleString()} Questions (${currentOffset.toLocaleString()} / ${totalQuestionsCount.toLocaleString()} Loaded)
                        </button>
                    </div>`;
                    resultsList.insertAdjacentHTML('beforeend', loadMoreHtml);
                }
            }
        } catch (e) {
            resultsList.innerHTML = `<div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><p>Error: ${e.message}</p></div>`;
        } finally {
            isFetchingBatch = false;
        }
    }

</script>
</body>
</html>
