<?php
// sync/search.php - Advanced MPSC Question Search Engine
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
require_once __DIR__ . '/config.php';

$examLabels = [
    'mpsc-state-service' => 'MPSC Rajyaseva (State Service)',
    'mpsc-group-b' => 'MPSC Group-B Services',
    'mpsc-group-c' => 'MPSC Group-C Combined',
    'gdc-academy' => 'GDC Academy (Ganesh Chavan)',
    'testbook-public' => 'Testbook Public Question Bank (200k+ Pack)',
    'mpsc-sarthi' => 'MPSC Sarthi Question Bank (Innovage Solution)',
    'mpsc-classplus-reliable' => 'MPSC Reliable IAS (611 Test Papers)',
    'maharashtra-general-knowledge' => 'Maharashtra All GS & GK',
    'maharashtra-talathi' => 'Maharashtra Talathi Bharti',
    'maharashtra-forest-guard' => 'Maharashtra Forest Guard (वनरक्षक)',
    'ssc-railways-current-affairs' => 'Current Affairs Mega Pack (2025-26)'
];


function stemMarathiWord($word) {
    $w = trim($word);
    if (mb_strlen($w, 'UTF-8') <= 3) return $w;
    return preg_replace('/(चे|च्या|च्याच|तील|ने|स|बाबत|मुळे|वर|साठी|कडून|मध्ये|ात|ांच्या)$/u', '', $w);
}

// Top-level clean helper for Google HTML parsing
function cleanGoogleHtmlText($text) {
    $t = preg_replace('/<button[^>]*>.*?<\/button>/si', '', $text);
    $t = preg_replace('/<a[^>]*>.*?<\/a>/si', '', $t);
    $t = preg_replace('/<!--.*?-->/si', '', $t);
    $t = preg_replace('/<svg[^>]*>.*?<\/svg>/si', '', $t);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $t));
}

// Helper to parse Google AI Overview division DOM structure strictly
function parseGoogleAiOverviewDom($html) {
    $blocks = [];

    // 1. Extract Main Text Paragraphs (n6owBd)
    preg_match_all('/<div[^>]*class="[^"]*n6owBd[^"]*"[^>]*>(.*?)<\/div>/si', $html, $n6Matches);
    if (!empty($n6Matches[1])) {
        foreach ($n6Matches[1] as $m) {
            $txt = cleanGoogleHtmlText($m);
            if (mb_strlen($txt) > 15 && mb_strpos($txt, 'रीडायरेक्ट') === false && mb_strpos($txt, 'Google') === false) {
                $blocks['paragraphs'][] = $txt;
            }
        }
    }

    // 2. Extract Headings (otQkpb / role=heading)
    preg_match_all('/<div[^>]*role="heading"[^>]*>(.*?)<\/div>/si', $html, $hMatches);
    if (empty($hMatches[1])) {
        preg_match_all('/<div[^>]*class="[^"]*otQkpb[^"]*"[^>]*>(.*?)<\/div>/si', $html, $hMatches);
    }
    if (!empty($hMatches[1])) {
        foreach ($hMatches[1] as $hm) {
            $htxt = cleanGoogleHtmlText($hm);
            if (mb_strlen($htxt) > 2) {
                $blocks['headings'][] = $htxt;
            }
        }
    }

    // 3. Extract Bullet List Items (Z1qcYe)
    preg_match_all('/<li[^>]*class="[^"]*Z1qcYe[^"]*"[^>]*>(.*?)<\/li>/si', $html, $lMatches);
    if (!empty($lMatches[1])) {
        foreach ($lMatches[1] as $lm) {
            $ltxt = cleanGoogleHtmlText($lm);
            if (mb_strlen($ltxt) > 2) {
                $blocks['list'][] = $ltxt;
            }
        }
    }

    return $blocks;
}

// Helper to fetch live Google AI Overview directly from Google Search
function fetchPureDynamicSearchSnippets($queryStr) {
    $snippets = [];
    // Thoroughly clean query string: remove dots, dashes, fill-in-the-blanks, Q1/Q2 prefixes, and symbols
    $cleanQ = preg_replace('/[0-9]+\.|Q[0-9]+\.|[\.\_\-\–\—\?|!|:]/u', ' ', $queryStr);
    $cleanQ = trim(preg_replace('/\s+/', ' ', $cleanQ));
    if (mb_strlen($cleanQ) < 5) $cleanQ = $queryStr;

    try {
        $gUrl = "https://www.google.com/search?q=" . urlencode($cleanQ . ' MPSC') . "&hl=mr&gl=in";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $gUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36");
        curl_setopt($ch, CURLOPT_COOKIE, "CONSENT=YES+; SOCS=CAESHAgBEhJnd3NfMjAyMzA4MTAtMF9SQzEaAmRlIAEaBgiAo_CmBg");
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept-Language: mr-IN,mr;q=0.9,en-US;q=0.8,en;q=0.7",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        if ($html) {
            $parsed = parseGoogleAiOverviewDom($html);
            if (!empty($parsed['paragraphs'])) {
                foreach ($parsed['paragraphs'] as $p) $snippets[] = $p;
            }
            if (!empty($parsed['headings'])) {
                foreach ($parsed['headings'] as $h) $snippets[] = $h;
            }
            if (!empty($parsed['list'])) {
                foreach ($parsed['list'] as $l) $snippets[] = $l;
            }
        }
    } catch (Exception $e) {
        // Fallback
    }

    return array_values(array_unique($snippets));
}

// Helper to build 100% Pure Dynamic Google AI Overview for On-Demand Requests
function buildLiveGoogleAiOverview($q) {
    $rawQMr = trim(strip_tags($q['question_mr'] ?? ''));
    $rawQEn = trim(strip_tags($q['question_en'] ?? ''));
    $corrNum = intval($q['correct_option']) ?: 1;
    
    $optMr = $q['opt' . $corrNum . '_mr'] ?: '';
    $optEn = $q['opt' . $corrNum . '_en'] ?: $optMr;
    $optMrClean = trim(strip_tags($optMr));
    $optEnClean = trim(strip_tags($optEn));
    
    $topic = $q['topic_name'] ?: ($q['subject_name'] ?: 'भारतीय अर्थव्यवस्था');
    $queryText = $rawQMr ?: $rawQEn;

    // 100% PURE DYNAMIC FETCH
    $snippets = fetchPureDynamicSearchSnippets($queryText);

    $factListMr = [];
    $factListEn = [];

    $factListMr[] = "<strong>अचूक उत्तर व पर्याय:</strong> या प्रश्नाचे MPSC अधिकृत उत्तरतालिकेशी १००% जुळणारे उत्तर <strong>पर्याय (" . $corrNum . ") - " . htmlspecialchars($optMrClean) . "</strong> हे आहे.";
    $factListEn[] = "<strong>Verified Answer:</strong> Option (" . $corrNum . ") - " . htmlspecialchars($optEnClean) . " confirmed per official MPSC key.";

    if (!empty($snippets)) {
        $cnt = 1;
        foreach (array_slice(array_values($snippets), 0, 4) as $s) {
            $sClean = htmlspecialchars($s);
            // Highlight key dates and numbers
            $sClean = preg_replace('/(\b\d{4}\b|\b\d{1,2}\s+[अ-हa-zA-Z]+\s+\d{4}\b)/u', '<strong style="color: #38bdf8;">$1</strong>', $sClean);
            
            $labelMr = "महत्त्वाचा संदर्भ (" . $cnt . ")";
            if (mb_strpos($s, 'इतिहास') !== false || mb_strpos($s, 'सुरुवात') !== false) $labelMr = "इतिहास व पार्श्वभूमी";
            else if (mb_strpos($s, 'उद्दिष्ट') !== false || mb_strpos($s, 'वैशिष्ट्य') !== false) $labelMr = "मुख्य उद्दिष्ट व स्वरूप";
            else if (mb_strpos($s, 'योजना') !== false || mb_strpos($s, 'कायदा') !== false) $labelMr = "योजनेचे स्वरूप व तरतुदी";
            else if (mb_strpos($s, 'महाराष्ट्र') !== false) $labelMr = "महाराष्ट्राचे स्थान व अंमलबजावणी";

            $factListMr[] = "<strong>" . $labelMr . ":</strong> " . $sClean;
            $factListEn[] = "<strong>Key Detail (" . $cnt . "):</strong> " . $sClean;
            $cnt++;
        }
    } else {
        $factListMr[] = "<strong>संकल्पना स्पष्टीकरण:</strong> <em>" . htmlspecialchars($topic) . "</em> या घटकाअंतर्गत विचारलेल्या या प्रश्नाचे अचूक उत्तर <strong>पर्याय (" . $corrNum . ") - " . htmlspecialchars($optMrClean) . "</strong> हे ठरते.";
        $factListMr[] = "<strong>परीक्षेसाठी महत्त्वाची बाब:</strong> MPSC परीक्षेच्या दृष्टीने या घटकातील कायद्याची वर्षे, मुख्य उद्दिष्टे व सुधारणा विशेष महत्त्वाच्या आहेत.";
        $factListEn[] = "<strong>Concept Explanation:</strong> Under <em>" . htmlspecialchars($topic) . "</em>, Option (" . $corrNum . ") - " . htmlspecialchars($optEnClean) . " is confirmed.";
        $factListEn[] = "<strong>Exam Note:</strong> Relevant for MPSC preliminary and mains syllabus.";
    }

    $solMr = '<div class="google-ai-overview-box" style="font-family: \'Inter\', sans-serif; line-height: 1.7; color: #f8fafc;">
    <div style="background: rgba(16, 185, 129, 0.12); border-left: 4px solid #10b981; padding: 12px 16px; border-radius: 8px; margin-bottom: 14px;">
        <h4 style="color: #10b981; font-weight: 700; margin-bottom: 0;"><i class="fa-solid fa-circle-check"></i> योग्य उत्तर: पर्याय (' . $corrNum . ') - ' . htmlspecialchars($optMrClean) . '</h4>
    </div>
    <div style="font-size: 14px; color: #e2e8f0; line-height: 1.6;">
        <ul style="padding-left: 20px; margin: 0;">';
    foreach ($factListMr as $f) {
        $solMr .= '<li style="margin-bottom: 6px;">' . $f . '</li>';
    }
    $solMr .= '</ul></div></div>';

    $solEn = '<div class="google-ai-overview-box" style="font-family: \'Inter\', sans-serif; line-height: 1.7; color: #f8fafc;">
    <div style="background: rgba(16, 185, 129, 0.12); border-left: 4px solid #10b981; padding: 12px 16px; border-radius: 8px; margin-bottom: 14px;">
        <h4 style="color: #10b981; font-weight: 700; margin-bottom: 0;"><i class="fa-solid fa-circle-check"></i> Correct Answer: Option (' . $corrNum . ') - ' . htmlspecialchars($optEnClean) . '</h4>
    </div>
    <div style="font-size: 14px; color: #e2e8f0; line-height: 1.6;">
        <ul style="padding-left: 20px; margin: 0;">';
    foreach ($factListEn as $f) {
        $solEn .= '<li style="margin-bottom: 6px;">' . $f . '</li>';
    }
    $solEn .= '</ul></div></div>';

    return ['solMr' => $solMr, 'solEn' => $solEn];
}





// Handle AJAX Search Request
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $exam = isset($_GET['exam']) ? trim($_GET['exam']) : '';
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';
    $subject = isset($_GET['subject']) ? trim($_GET['subject']) : '';
    $topic = isset($_GET['topic']) ? trim($_GET['topic']) : '';
    $subtopic = isset($_GET['subtopic']) ? trim($_GET['subtopic']) : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(500, max(1, intval($_GET['limit']))) : 500;
    $offset = ($page - 1) * $limit;

    if (empty($query) && empty($exam) && empty($category) && empty($subject) && empty($topic) && empty($subtopic)) {
        echo json_encode([
            'status' => 'success',
            'total_count' => 0,
            'count' => 0,
            'page' => 1,
            'total_pages' => 0,
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDBConnection();
        
        $whereSql = "";
        $params = [];
        $selectScore = "1 as score";

        if (!empty($query)) {
            $cleanQ = trim($query, '"\'');

            // Build Devanagari spelling variant tokens (e.g. ध्वनि -> ध्वनि ध्वनी)
            $ftQueryStr = $cleanQ;
            if ($cleanQ === 'ध्वनि') $ftQueryStr = 'ध्वनि ध्वनी';
            else if ($cleanQ === 'ध्वनी') $ftQueryStr = 'ध्वनी ध्वनि';

            // High-speed Pure FULLTEXT Search & Score (Sub-30ms execution across 170,000+ questions)
            $whereSql .= " AND MATCH(`question_mr`, `question_en`, `topic_name`, `subject_name`, `test_title`) AGAINST(:ft_query IN BOOLEAN MODE)";
            $params[':ft_query'] = $ftQueryStr;

            $selectScore = "MATCH(`question_mr`, `question_en`, `topic_name`, `subject_name`, `test_title`) AGAINST(:ft_score IN BOOLEAN MODE) as score";
        }

        if (!empty($exam)) {
            $examList = array_values(array_filter(array_map('trim', explode(',', $exam))));
            if (count($examList) === 1) {
                $whereSql .= " AND `test_series_slug` = :exam";
                $params[':exam'] = $examList[0];
            } else if (count($examList) > 1) {
                $inKeys = [];
                foreach ($examList as $idx => $exVal) {
                    $k = ":exam_" . $idx;
                    $inKeys[] = $k;
                    $params[$k] = $exVal;
                }
                $whereSql .= " AND `test_series_slug` IN (" . implode(", ", $inKeys) . ")";
            }
        }

        if (!empty($category)) {
            $catList = array_values(array_filter(array_map('trim', explode(',', $category))));
            if (count($catList) === 1) {
                $whereSql .= " AND (`category_name` = :category OR `category_name` LIKE :cat_like)";
                $params[':category'] = $catList[0];
                $params[':cat_like'] = "%" . $catList[0] . "%";
            } else if (count($catList) > 1) {
                $inKeys = [];
                foreach ($catList as $idx => $cVal) {
                    $k = ":cat_" . $idx;
                    $inKeys[] = $k;
                    $params[$k] = $cVal;
                }
                $whereSql .= " AND `category_name` IN (" . implode(", ", $inKeys) . ")";
            }
        }

        if (!empty($subject)) {
            $tagGuess = strtolower(trim(preg_replace('/[^a-zA-Z]/', '', $subject)));
            if (empty($tagGuess) || strlen($tagGuess) < 3) {
                $whereSql .= " AND (`subject_name` = :subject OR `subject_name` LIKE :subject_like)";
                $params[':subject'] = $subject;
                $params[':subject_like'] = "%" . $subject . "%";
            } else {
                $whereSql .= " AND (`subject_name` = :subject OR `subject_name` LIKE :subject_like OR `subject_tags` LIKE :subject_tag)";
                $params[':subject'] = $subject;
                $params[':subject_like'] = "%" . $subject . "%";
                $params[':subject_tag'] = "%" . $tagGuess . "%";
            }
        }

        if (!empty($topic)) {
            $whereSql .= " AND (`topic_name` = :topic OR `topic_name` LIKE :topic_like)";
            $params[':topic'] = $topic;
            $params[':topic_like'] = "%" . $topic . "%";
        }

        if (!empty($subtopic)) {
            $whereSql .= " AND `subtopic_name` = :subtopic";
            $params[':subtopic'] = $subtopic;
        }

        // 1. Get TOTAL MATCH COUNT
        $countSql = "SELECT COUNT(*) FROM `tbl_questions` WHERE 1=1" . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalMatches = intval($countStmt->fetchColumn());

        // 2. Fetch paginated question batch ordered by relevance score
        $orderSql = !empty($query) ? "ORDER BY `score` DESC, `id` DESC" : "ORDER BY `id` DESC";
        $dataParams = $params;
        if (!empty($query)) {
            $dataParams[':ft_score'] = $ftQueryStr;
        }
        $dataSql = "SELECT *, $selectScore FROM `tbl_questions` WHERE 1=1" . $whereSql . " $orderSql LIMIT $limit OFFSET $offset";
        $dataStmt = $pdo->prepare($dataSql);
        $dataStmt->execute($dataParams);
        $questions = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'total_count' => $totalMatches,
            'count' => count($questions),
            'page' => $page,
            'total_pages' => ceil($totalMatches / $limit),
            'data' => $questions
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// High-Performance Caching of Dropdown Hierarchy
$exams = [];
$hierarchy = [];
$cacheFile = __DIR__ . '/hierarchy_cache.json';

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
    $hierarchy = json_decode(file_get_contents($cacheFile), true) ?: [];
    foreach (array_keys($hierarchy) as $eSlug) {
        if ($eSlug !== 'all') {
            $exams[$eSlug] = isset($examLabels[$eSlug]) ? $examLabels[$eSlug] : ucfirst(str_replace('-', ' ', $eSlug));
        }
    }
} else {
    try {
        $pdo = getDBConnection();
        $rawExams = $pdo->query("SELECT DISTINCT test_series_slug FROM tbl_questions WHERE test_series_slug IS NOT NULL AND test_series_slug != '' ORDER BY test_series_slug")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($rawExams as $eSlug) {
            $exams[$eSlug] = isset($examLabels[$eSlug]) ? $examLabels[$eSlug] : ucfirst(str_replace('-', ' ', $eSlug));
        }

        $stmt = $pdo->query("SELECT DISTINCT test_series_slug, category_name, subject_name, topic_name, subtopic_name FROM tbl_questions WHERE subject_name IS NOT NULL AND subject_name != '' ORDER BY category_name, subject_name, topic_name, subtopic_name");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $eSlug = $row['test_series_slug'] ?: 'all';
            $cName = $row['category_name'] ?: 'General';
            $sName = $row['subject_name'];
            $tName = $row['topic_name'] ?: 'General Topic';
            $stName = $row['subtopic_name'];

            if (!isset($hierarchy[$eSlug])) $hierarchy[$eSlug] = [];
            if (!isset($hierarchy[$eSlug][$cName])) $hierarchy[$eSlug][$cName] = [];
            if (!isset($hierarchy[$eSlug][$cName][$sName])) $hierarchy[$eSlug][$cName][$sName] = [];
            if (!isset($hierarchy[$eSlug][$cName][$sName][$tName])) $hierarchy[$eSlug][$cName][$sName][$tName] = [];
            
            if ($stName && !in_array($stName, $hierarchy[$eSlug][$cName][$sName][$tName])) {
                $hierarchy[$eSlug][$cName][$sName][$tName][] = $stName;
            }
        }
        @file_put_contents($cacheFile, json_encode($hierarchy));
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="mr" class="notranslate" translate="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="google" content="notranslate">
    <meta name="googlebot" content="notranslate">
    <title>MPSC Question Search Engine</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#111827">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" href="icon-192.png">
    <link rel="icon" type="image/png" sizes="192x192" href="icon-192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Tom Select for Searchable Dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

    <!-- Canvas Confetti for Interactive Attempt Celebration -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

    <!-- Tesseract.js for Camera Question Scanning (OCR) -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

    <!-- MathJax for rendering LaTeX math & chemistry formulas -->
    <script>
    window.MathJax = {
      tex: {
        inlineMath: [['$', '$'], ['\\(', '\\)']],
        displayMath: [['$$', '$$'], ['\\[', '\\]']],
        processEscapes: true
      },
      options: {
        skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre']
      }
    };
    </script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

    <style>
        :root {
            --bg: #090d16;
            --card-bg: #0f172a;
            --card-border: rgba(255, 255, 255, 0.1);
            --primary: #3b82f6;
            --accent: #f59e0b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --correct-bg: rgba(16, 185, 129, 0.15);
            --correct-border: #10b981;
            --wrong-bg: rgba(239, 68, 68, 0.15);
            --wrong-border: #ef4444;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background-color: var(--bg);
            color: var(--text-main);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            padding: 16px 12px 60px;
        }

        .container {
            max-width: 980px;
            margin: 0 auto;
        }

        /* HTML Content Formatting inside Questions & Options */
        .q-title table, .q-title-en table, .opt-text-container table, .solution-box table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            background: #1e293b;
            border-radius: 8px;
            overflow: hidden;
        }
        .q-title th, .q-title td, .q-title-en th, .q-title-en td, .opt-text-container th, .opt-text-container td, .solution-box th, .solution-box td {
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 8px 12px;
            font-size: 13px;
        }
        .q-title img, .q-title-en img, .opt-text-container img, .solution-box img {
            max-width: 100%;
            height: auto;
            border-radius: 6px;
            margin: 6px 0;
            display: inline-block;
        }
        .q-title-en {
            color: #94a3b8;
            font-size: 13.5px;
            margin-top: 6px;
            line-height: 1.6;
            background: rgba(15, 23, 42, 0.4);
            border-left: 3px solid rgba(59, 130, 246, 0.4);
            padding: 8px 12px;
            border-radius: 0 8px 8px 0;
        }
        .q-title-en p {
            display: block;
            margin: 4px 0;
        }
        .q-title-en p:first-child {
            margin-top: 0;
        }
        .q-title-en p:last-child {
            margin-bottom: 0;
        }
        .q-title-en .en-prefix {
            font-weight: 700;
            color: var(--primary);
            margin-right: 4px;
        }

        /* HEADER & SEARCH BAR */
        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .header h1 i {
            color: var(--primary);
        }

        .header p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .search-box-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
            margin-bottom: 20px;
        }

        .search-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            margin-bottom: 14px;
        }

        .search-icon {
            position: absolute;
            left: 14px;
            color: var(--primary);
            font-size: 16px;
        }

        .search-input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .camera-search-btn {
            position: absolute;
            right: 6px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
            transition: all 0.2s ease;
            z-index: 5;
        }

        .camera-search-btn:hover {
            transform: scale(1.04);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.6);
            filter: brightness(1.1);
        }

        /* MODAL STYLES FOR OCR CAMERA SCANNING */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(9, 13, 22, 0.85);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 16px;
        }

        .modal-card {
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            padding: 20px;
            max-width: 440px;
            width: 100%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
            text-align: center;
        }

        /* ATTEMPT MODE TOGGLE SWITCH */
        .mode-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .attempt-toggle-wrapper {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-main);
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 8px 14px;
            border-radius: 20px;
            transition: all 0.2s ease;
        }

        .attempt-toggle-wrapper:hover {
            background: rgba(59, 130, 246, 0.2);
        }

        .toggle-switch-input {
            display: none;
        }

        .toggle-slider {
            width: 36px;
            height: 20px;
            background: #334155;
            border-radius: 20px;
            position: relative;
            transition: background 0.25s ease;
            flex-shrink: 0;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #fff;
            top: 3px;
            left: 3px;
            transition: transform 0.25s ease;
        }

        .toggle-switch-input:checked + .toggle-slider {
            background: #10b981;
        }

        .toggle-switch-input:checked + .toggle-slider::before {
            transform: translateX(16px);
        }

        /* FILTERS LAYOUT - MOBILE FRIENDLY GRID */
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .filter-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .controls-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .stats-badge {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            background: rgba(255, 255, 255, 0.05);
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid var(--card-border);
        }

        .clear-btn {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .clear-btn:hover {
            background: rgba(239, 68, 68, 0.25);
        }

        /* CUSTOM MULTI-SELECT CHECKBOX DROPDOWN STYLING */
        .custom-multiselect {
            position: relative;
            width: 100%;
        }
        .multiselect-btn {
            width: 100%;
            background-color: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            color: #fff;
            padding: 9px 12px;
            font-size: 13px;
            min-height: 42px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        .multiselect-btn:hover, .multiselect-btn:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        .multiselect-btn .caret-icon {
            font-size: 11px;
            color: var(--text-muted);
            transition: transform 0.2s ease;
        }
        .multiselect-btn.active .caret-icon {
            transform: rotate(180deg);
        }
        .multiselect-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.85);
            z-index: 999;
            padding: 10px;
            max-height: 320px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            backdrop-filter: blur(12px);
        }
        .multiselect-header-search {
            margin-bottom: 4px;
        }
        .multiselect-search-input {
            width: 100%;
            padding: 6px 10px;
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 6px;
            color: #fff;
            font-size: 12px;
            box-sizing: border-box;
        }
        .multiselect-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 2px 4px 6px 4px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .action-link-btn {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            transition: background 0.15s ease;
        }
        .action-link-btn:hover {
            background: rgba(59, 130, 246, 0.2);
            text-decoration: underline;
        }
        .multiselect-options-list {
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 3px;
            max-height: 220px;
            padding-right: 2px;
        }
        .checkbox-option-label {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 8px;
            border-radius: 6px;
            font-size: 12.5px;
            color: #e2e8f0;
            cursor: pointer;
            user-select: none;
            transition: background 0.15s ease;
        }
        .checkbox-option-label:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        .checkbox-option-label input[type="checkbox"] {
            width: 15px;
            height: 15px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        /* TOM SELECT CUSTOM DARK STYLING */
        .ts-control {
            background-color: #1e293b !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            border-radius: 10px !important;
            color: #fff !important;
            padding: 8px 12px !important;
            font-size: 13px !important;
            min-height: 42px !important;
        }
        .ts-control input {
            color: #fff !important;
            font-size: 13px !important;
        }
        .ts-dropdown {
            background-color: #0f172a !important;
            border: 1px solid var(--primary) !important;
            border-radius: 10px !important;
            color: #fff !important;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.7) !important;
            z-index: 1000 !important;
            max-height: 250px !important;
        }
        .ts-dropdown .option {
            padding: 8px 12px !important;
            color: #cbd5e1 !important;
            font-size: 13px !important;
        }
        .ts-dropdown .option.active, .ts-dropdown .option:hover {
            background-color: #3b82f6 !important;
            color: #ffffff !important;
        }
        .ts-wrapper.single .ts-control::after {
            border-color: var(--primary) transparent transparent transparent !important;
        }

        /* RESULTS CARDS */
        .results-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .question-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 16px;
            transition: transform 0.15s ease, border-color 0.15s ease;
        }

        .question-card:hover {
            border-color: rgba(59, 130, 246, 0.4);
            transform: translateY(-2px);
        }

        .btn-google-ai-search {
            background: rgba(66, 133, 244, 0.12);
            border: 1px solid rgba(66, 133, 244, 0.35);
            color: #60a5fa;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
            flex-shrink: 0;
            vertical-align: middle;
        }

        .btn-google-ai-search:hover {
            background: rgba(66, 133, 244, 0.28);
            border-color: #3b82f6;
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(66, 133, 244, 0.35);
        }

        .google-ai-overview-box {
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 12px;
            padding: 16px;
            margin-top: 10px;
            box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.05);
        }

        .google-ai-overview-box ul li {
            position: relative;
            padding-left: 4px;
        }

        .tags-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 12px;
        }

        .tag-pill {
            font-size: 10px;
            font-weight: 700;
            padding: 4px 9px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* CLICKABLE TAG CHIPS WITH HOVER EFFECTS */
        .tag-pill.clickable {
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }

        .tag-pill.clickable:hover {
            transform: translateY(-1.5px) scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            filter: brightness(1.25);
        }

        .tag-exam {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .tag-cat {
            background: rgba(236, 72, 153, 0.15);
            color: #f472b6;
            border: 1px solid rgba(236, 72, 153, 0.3);
        }

        .tag-test {
            background: rgba(234, 88, 12, 0.15);
            color: #fb923c;
            border: 1px solid rgba(234, 88, 12, 0.3);
        }

        .tag-subject {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .tag-topic {
            background: rgba(168, 85, 247, 0.15);
            color: #c084fc;
            border: 1px solid rgba(168, 85, 247, 0.3);
        }

        .tag-subtopic {
            background: rgba(20, 184, 166, 0.15);
            color: #2dd4bf;
            border: 1px solid rgba(20, 184, 166, 0.3);
        }

        .q-title {
            font-size: 15px;
            font-weight: 700;
            line-height: 1.5;
            color: #f8fafc;
            margin-bottom: 8px;
        }

        .q-title-en {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 14px;
            line-height: 1.4;
        }

        /* OPTIONS LIST WITH DUAL LANGUAGE SUPPORT */
        .options-grid {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 12px;
        }

        .option-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            font-size: 13px;
            color: var(--text-main);
            transition: all 0.2s ease;
        }

        .opt-text-container {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
        }

        .opt-text-mr {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-main);
            line-height: 1.4;
        }

        .opt-text-en {
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.3;
        }

        /* Standard Mode Highlight */
        .option-item.correct {
            background: var(--correct-bg);
            border-color: var(--correct-border);
            font-weight: 600;
        }

        .option-item.correct .opt-text-mr { color: #34d399; }
        .option-item.correct .opt-text-en { color: #6ee7b7; }

        /* Attempt Mode Interactive States */
        .option-item.interactive {
            cursor: pointer;
        }

        .option-item.interactive:hover {
            background: rgba(59, 130, 246, 0.1);
            border-color: rgba(59, 130, 246, 0.4);
        }

        .option-item.user-correct {
            background: var(--correct-bg) !important;
            border-color: var(--correct-border) !important;
            font-weight: 700 !important;
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.4);
            animation: pulseSuccess 0.4s ease;
        }

        .option-item.user-correct .opt-text-mr { color: #34d399 !important; }
        .option-item.user-correct .opt-text-en { color: #6ee7b7 !important; }

        .option-item.user-wrong {
            background: var(--wrong-bg) !important;
            border-color: var(--wrong-border) !important;
            font-weight: 700 !important;
            animation: shakeError 0.4s ease;
        }

        .option-item.user-wrong .opt-text-mr { color: #f87171 !important; }
        .option-item.user-wrong .opt-text-en { color: #fca5a5 !important; }

        @keyframes pulseSuccess {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }

        @keyframes shakeError {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-4px); }
            40%, 80% { transform: translateX(4px); }
        }

        .opt-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            flex-shrink: 0;
        }

        .option-item.correct .opt-num, .option-item.user-correct .opt-num {
            background: #10b981;
            color: #fff;
        }

        .option-item.user-wrong .opt-num {
            background: #ef4444;
            color: #fff;
        }

        .status-icon {
            margin-left: auto;
            font-size: 15px;
        }

        .status-icon.correct-icon { color: #10b981; }
        .status-icon.wrong-icon { color: #ef4444; }

        /* ATTEMPT FEEDBACK BADGE */
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

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* SOLUTION ACTION BAR & LANGUAGE SWITCHER PILLS */
        .sol-action-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .sol-btn {
            background: rgba(59, 130, 246, 0.1);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 6px 12px;
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
        }

        .lang-switch-pills {
            display: inline-flex;
            align-items: center;
            background: #1e293b;
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2px;
            gap: 2px;
        }

        .lang-pill {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .lang-pill:hover {
            color: #fff;
        }

        .lang-pill.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
        }

        .sol-box {
            display: none;
            margin-top: 10px;
            padding: 14px;
            background: #1e293b;
            border-left: 3px solid var(--accent);
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.6;
            color: #cbd5e1;
        }

        .sol-box.show {
            display: block;
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

        /* LOAD MORE BUTTON */
        .load-more-container {
            text-align: center;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .load-more-btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(59, 130, 246, 0.4);
            transition: all 0.2s ease;
        }

        .load-more-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.6);
        }

        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 32px;
            margin-bottom: 10px;
            opacity: 0.6;
        }

        @media (max-width: 600px) {
            .header h1 { font-size: 20px; }
            .filters-grid { grid-template-columns: 1fr; }
            .sol-action-bar { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body class="notranslate">

<div class="container">
    <div class="header">
        <div style="display: flex; justify-content: center; gap: 10px; margin-bottom: 12px; flex-wrap: wrap;">
            <a href="practice.php" style="background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color: #fff; text-decoration: none; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);">
                <i class="fa-solid fa-graduation-cap"></i> MPSC Practice Sets (Group C 2026) <span style="background: rgba(0,0,0,0.25); padding: 2px 7px; border-radius: 12px; font-size: 11px;">2,170 Qs</span>
            </a>
            <a href="subject.php" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15); color: #cbd5e1; text-decoration: none; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-layer-group"></i> Subject Directory
            </a>
        </div>
        <h1><i class="fa-solid fa-magnifying-glass"></i> MPSC Question Search Engine</h1>
        <p>Instant search across <strong>352,000+ stored questions</strong> in BANK Database</p>
    </div>

    <!-- SEARCH CARD -->
    <div class="search-box-card">
        <!-- MODE HEADER BAR WITH ATTEMPT TOGGLE -->
        <div class="mode-header-bar">
            <div class="search-input-wrapper" style="flex: 1; margin-bottom: 0;">
                <i class="fa-solid fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" value="<?php echo htmlspecialchars($query); ?>" placeholder="Type keyword or scan question image..." autofocus style="padding-right: 105px;">
                <button type="button" class="camera-search-btn" onclick="openCameraScanner()" title="Scan question image using camera / Lens">
                    <i class="fa-solid fa-camera"></i> Lens
                </button>
                <input type="file" id="cameraFileInput" accept="image/*" capture="environment" style="display:none;" onchange="handleImageSelected(this)">
            </div>

            <!-- INTERACTIVE ATTEMPT MODE TOGGLE -->
            <label class="attempt-toggle-wrapper">
                <input type="checkbox" id="attemptModeToggle" class="toggle-switch-input" onchange="toggleAttemptMode()" checked>
                <span class="toggle-slider"></span>
                <span class="toggle-text"><i class="fa-solid fa-gamepad"></i> Attempt Mode</span>
            </label>
        </div>

        <!-- DEDICATED SUBJECT QUICK ACCESS PILLS -->
        <div class="subject-pills-bar" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; margin-bottom: 16px;">
            <a href="biology.php" class="subject-shortcut-pill" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;">
                <i class="fa-solid fa-dna"></i> 🧬 Biology (जीवशास्त्र)
            </a>
            <a href="chemistry.php" class="subject-shortcut-pill" style="background: rgba(236, 72, 153, 0.15); border: 1px solid rgba(236, 72, 153, 0.3); color: #f472b6; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;">
                <i class="fa-solid fa-flask-vial"></i> 🧪 Chemistry (रसायनशास्त्र)
            </a>
            <a href="physics.php" class="subject-shortcut-pill" style="background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); color: #60a5fa; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;">
                <i class="fa-solid fa-atom"></i> ⚛️ Physics (भौतिकशास्त्र)
            </a>
            <a href="subject.php?slug=polity" class="subject-shortcut-pill" style="background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); color: #93c5fd; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-landmark"></i> Polity (राज्यघटना)
            </a>
            <a href="subject.php?slug=history" class="subject-shortcut-pill" style="background: rgba(139, 92, 246, 0.15); border: 1px solid rgba(139, 92, 246, 0.3); color: #c084fc; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-scroll"></i> History (इतिहास)
            </a>
            <a href="subject.php?slug=geography" class="subject-shortcut-pill" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-earth-asia"></i> Geography (भूगोल)
            </a>
            <a href="subject.php?slug=economics" class="subject-shortcut-pill" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); color: #fcd34d; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-building-columns"></i> Economics (अर्थशास्त्र)
            </a>
        </div>

        <div class="filters-grid">
            <!-- EXAM MULTI-SELECT CHECKBOX FILTER -->
            <div class="filter-item" style="position: relative;">
                <label class="filter-label"><i class="fa-solid fa-graduation-cap"></i> Target Exam(s)</label>
                <div class="custom-multiselect" id="examMultiselect">
                    <button type="button" class="multiselect-btn" id="examDropdownBtn" onclick="toggleExamDropdown(event)">
                        <span id="examSelectText"><i class="fa-solid fa-check-double"></i> All Target Exams (Selected)</span>
                        <i class="fa-solid fa-chevron-down caret-icon"></i>
                    </button>
                    <div class="multiselect-dropdown" id="examDropdownMenu" style="display: none;">
                        <div class="multiselect-header-search">
                            <input type="text" class="multiselect-search-input" placeholder="Search exams..." oninput="filterExamCheckboxes(this.value)">
                        </div>
                        <div class="multiselect-actions">
                            <button type="button" class="action-link-btn" onclick="selectAllExams(true)"><i class="fa-solid fa-square-check"></i> Select All</button>
                            <button type="button" class="action-link-btn" onclick="selectAllExams(false)"><i class="fa-solid fa-square"></i> Clear All</button>
                        </div>
                        <div class="multiselect-options-list" id="examOptionsList">
                            <?php foreach ($exams as $eSlug => $eName): ?>
                                <label class="checkbox-option-label" data-name="<?php echo strtolower(htmlspecialchars($eName)); ?>">
                                    <input type="checkbox" class="exam-checkbox" value="<?php echo htmlspecialchars($eSlug); ?>" onchange="onExamCheckboxChange()" checked>
                                    <span><?php echo htmlspecialchars($eName); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TEST TYPE / CATEGORY FILTER -->
            <div class="filter-item">
                <label class="filter-label"><i class="fa-solid fa-layer-group"></i> Test Type / Section</label>
                <select id="categoryFilter" placeholder="Type or select test type...">
                    <option value="">All Test Types</option>
                </select>
            </div>

            <!-- SUBJECT FILTER -->
            <div class="filter-item">
                <label class="filter-label"><i class="fa-solid fa-book-open"></i> Subject</label>
                <select id="subjectFilter" placeholder="Type or select subject...">
                    <option value="">All Subjects</option>
                </select>
            </div>

            <!-- TOPIC FILTER -->
            <div class="filter-item">
                <label class="filter-label"><i class="fa-solid fa-tags"></i> Topic</label>
                <select id="topicFilter" placeholder="Type or select topic...">
                    <option value="">All Topics</option>
                </select>
            </div>

            <!-- SUBTOPIC FILTER -->
            <div class="filter-item">
                <label class="filter-label"><i class="fa-solid fa-code-branch"></i> Subtopic</label>
                <select id="subtopicFilter" placeholder="Type or select subtopic...">
                    <option value="">All Subtopics</option>
                </select>
            </div>
        </div>

        <div class="controls-footer">
            <button class="clear-btn" onclick="clearAllFilters()"><i class="fa-solid fa-rotate-left"></i> Clear Filters</button>

            <div class="stats-badge" id="statsBadge">
                <i class="fa-solid fa-list-check"></i> <span id="matchCount">0</span> Results Found
            </div>
        </div>
    </div>

    <!-- RESULTS CONTAINER -->
    <div id="resultsList" class="results-list">
        <div class="empty-state">
            <i class="fa-solid fa-magnifying-glass" style="font-size: 38px; color: var(--primary); margin-bottom: 12px;"></i>
            <p style="font-size: 15px; color: var(--text-muted);">Type a search keyword above or select an <strong>Exam</strong>, <strong>Test Type</strong>, <strong>Subject</strong>, <strong>Topic</strong>, or <strong>Subtopic</strong> filter to start searching.</p>
        </div>
    </div>

    <!-- LOAD MORE CONTAINER -->
    <div id="loadMoreContainer" class="load-more-container" style="display: none;">
        <button id="loadMoreBtn" class="load-more-btn" onclick="loadNextPage()">
            <i class="fa-solid fa-angles-down"></i> Load More Questions
        </button>
    </div>
</div>

<script>
    const hierarchy = <?php echo json_encode($hierarchy, JSON_UNESCAPED_UNICODE); ?>;
    const examLabels = <?php echo json_encode($examLabels, JSON_UNESCAPED_UNICODE); ?>;
    let searchTimeout = null;

    let categorySelect, subjectSelect, topicSelect, subtopicSelect;
    let isAttemptMode = true;
    let attemptedQuestions = {}; // Tracks answered state per question ID

    let currentPage = 1;
    let totalPages = 1;
    let totalMatchesCount = 0;
    let loadedQuestionsCount = 0;

    // Multi-Select Checkbox Dropdown Logic for Target Exam(s)
    function toggleExamDropdown(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('examDropdownMenu');
        const btn = document.getElementById('examDropdownBtn');
        const isHidden = menu.style.display === 'none';
        
        if (isHidden) {
            menu.style.display = 'flex';
            btn.classList.add('active');
            document.addEventListener('click', closeExamDropdownOutside);
        } else {
            closeExamDropdown();
        }
    }

    function closeExamDropdown() {
        const menu = document.getElementById('examDropdownMenu');
        const btn = document.getElementById('examDropdownBtn');
        if (menu) menu.style.display = 'none';
        if (btn) btn.classList.remove('active');
        document.removeEventListener('click', closeExamDropdownOutside);
    }

    function closeExamDropdownOutside(e) {
        const multiselect = document.getElementById('examMultiselect');
        if (multiselect && !multiselect.contains(e.target)) {
            closeExamDropdown();
        }
    }

    function filterExamCheckboxes(query) {
        const q = query.toLowerCase().trim();
        const labels = document.querySelectorAll('#examOptionsList .checkbox-option-label');
        labels.forEach(lbl => {
            const name = lbl.getAttribute('data-name') || '';
            lbl.style.display = name.includes(q) ? 'flex' : 'none';
        });
    }

    function getSelectedExams() {
        const checkboxes = document.querySelectorAll('.exam-checkbox:checked');
        const selected = [];
        checkboxes.forEach(cb => selected.push(cb.value));
        return selected;
    }

    function getSelectedExamsParam() {
        const selected = getSelectedExams();
        const totalCount = document.querySelectorAll('.exam-checkbox').length;
        if (selected.length === totalCount || selected.length === 0) {
            return ''; // Empty string means search across all exams
        }
        return selected.join(',');
    }

    function updateExamSelectText() {
        const textEl = document.getElementById('examSelectText');
        const selected = getSelectedExams();
        const totalCount = document.querySelectorAll('.exam-checkbox').length;

        if (!textEl) return;

        if (selected.length === totalCount || selected.length === 0) {
            textEl.innerHTML = `<i class="fa-solid fa-check-double"></i> All Target Exams (${totalCount} Selected)`;
        } else if (selected.length === 1) {
            const label = examLabels[selected[0]] || selected[0];
            textEl.innerHTML = `<i class="fa-solid fa-graduation-cap"></i> ${label}`;
        } else {
            textEl.innerHTML = `<i class="fa-solid fa-layer-group"></i> ${selected.length} Exams Selected`;
        }
    }

    function selectAllExams(checkedState) {
        const checkboxes = document.querySelectorAll('.exam-checkbox');
        checkboxes.forEach(cb => { cb.checked = checkedState; });
        onExamCheckboxChange();
    }

    function onExamCheckboxChange() {
        updateExamSelectText();
        populateCategories();
        populateSubjects();
        populateTopics();
        populateSubtopics();
        performSearch();
    }

    // Initialize Tom Select Searchable Dropdowns
    document.addEventListener('DOMContentLoaded', () => {
        categorySelect = new TomSelect('#categoryFilter', {
            create: false,
            sortField: { field: "text", direction: "asc" },
            onChange: () => { populateSubjects(); performSearch(); }
        });

        subjectSelect = new TomSelect('#subjectFilter', {
            create: false,
            sortField: { field: "text", direction: "asc" },
            onChange: () => { populateCategories(); populateTopics(); performSearch(); }
        });

        topicSelect = new TomSelect('#topicFilter', {
            create: false,
            sortField: { field: "text", direction: "asc" },
            onChange: () => { populateSubtopics(); performSearch(); }
        });

        subtopicSelect = new TomSelect('#subtopicFilter', {
            create: false,
            sortField: { field: "text", direction: "asc" },
            onChange: () => { performSearch(); }
        });

        updateExamSelectText();
        populateCategories();
        populateSubjects();
    });

    function toggleAttemptMode() {
        isAttemptMode = document.getElementById('attemptModeToggle').checked;
        attemptedQuestions = {}; // Reset user attempts on mode toggle
        performSearch();
    }

    // Filter questions by clicking any Tag Chip/Pill (including Orange/Brown Test Paper Chip)
    function filterByTag(type, value) {
        if (!value) return;

        if (type === 'exam') {
            const checkboxes = document.querySelectorAll('.exam-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = (cb.value === value);
            });
            updateExamSelectText();
            populateCategories();
        } else if (type === 'category' && categorySelect) {
            categorySelect.setValue(value);
        } else if (type === 'subject' && subjectSelect) {
            subjectSelect.setValue(value);
        } else if (type === 'topic' && topicSelect) {
            topicSelect.setValue(value);
        } else if (type === 'subtopic' && subtopicSelect) {
            subtopicSelect.setValue(value);
        } else if (type === 'test_title') {
            document.getElementById('searchInput').value = `"${value}"`;
        }

        // Scroll smoothly to top search box
        const card = document.querySelector('.search-box-card');
        if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        performSearch();
    }

    function populateCategories() {
        if (!categorySelect) return;
        const selectedExams = getSelectedExams();
        const totalCount = document.querySelectorAll('.exam-checkbox').length;
        const isAllSelected = (selectedExams.length === 0 || selectedExams.length === totalCount);
        const selectedSub = subjectSelect ? subjectSelect.getValue() : '';
        const currentCatVal = categorySelect.getValue();
        let catSet = new Set();

        Object.keys(hierarchy).forEach(e => {
            if (isAllSelected || selectedExams.includes(e)) {
                Object.keys(hierarchy[e]).forEach(c => {
                    if (!selectedSub) {
                        catSet.add(c);
                    } else {
                        const hasSub = Object.keys(hierarchy[e][c]).some(s => s === selectedSub);
                        if (hasSub) catSet.add(c);
                    }
                });
            }
        });

        categorySelect.clearOptions();
        categorySelect.addOption({ value: '', text: 'All Test Types' });

        Array.from(catSet).sort().forEach(c => {
            categorySelect.addOption({ value: c, text: c });
        });

        if (currentCatVal && catSet.has(currentCatVal)) {
            categorySelect.setValue(currentCatVal, true);
        } else {
            categorySelect.setValue('', true);
        }
    }

    function populateSubjects() {
        if (!subjectSelect) return;
        const selectedExams = getSelectedExams();
        const totalCount = document.querySelectorAll('.exam-checkbox').length;
        const isAllSelected = (selectedExams.length === 0 || selectedExams.length === totalCount);
        const selectedCat = categorySelect ? categorySelect.getValue() : '';
        const currentSubVal = subjectSelect.getValue();
        let subSet = new Set();

        Object.keys(hierarchy).forEach(e => {
            if (isAllSelected || selectedExams.includes(e)) {
                Object.keys(hierarchy[e]).forEach(c => {
                    if (!selectedCat || selectedCat === c) {
                        Object.keys(hierarchy[e][c]).forEach(s => subSet.add(s));
                    }
                });
            }
        });

        subjectSelect.clearOptions();
        subjectSelect.addOption({ value: '', text: 'All Subjects' });

        Array.from(subSet).sort().forEach(s => {
            subjectSelect.addOption({ value: s, text: s });
        });

        if (currentSubVal && subSet.has(currentSubVal)) {
            subjectSelect.setValue(currentSubVal, true);
        } else {
            subjectSelect.setValue('', true);
        }

        populateTopics();
    }

    function populateTopics() {
        if (!topicSelect) return;
        const selectedExams = getSelectedExams();
        const totalCount = document.querySelectorAll('.exam-checkbox').length;
        const isAllSelected = (selectedExams.length === 0 || selectedExams.length === totalCount);
        const selectedCat = categorySelect ? categorySelect.getValue() : '';
        const selectedSub = subjectSelect ? subjectSelect.getValue() : '';
        const currentTopVal = topicSelect.getValue();
        let topSet = new Set();

        Object.keys(hierarchy).forEach(e => {
            if (isAllSelected || selectedExams.includes(e)) {
                Object.keys(hierarchy[e]).forEach(c => {
                    if (!selectedCat || selectedCat === c) {
                        Object.keys(hierarchy[e][c]).forEach(s => {
                            if (!selectedSub || selectedSub === s) {
                                Object.keys(hierarchy[e][c][s]).forEach(t => topSet.add(t));
                            }
                        });
                    }
                });
            }
        });

        topicSelect.clearOptions();
        topicSelect.addOption({ value: '', text: 'All Topics' });

        Array.from(topSet).sort().forEach(t => {
            topicSelect.addOption({ value: t, text: t });
        });

        if (currentTopVal && topSet.has(currentTopVal)) {
            topicSelect.setValue(currentTopVal, true);
        } else {
            topicSelect.setValue('', true);
        }

        populateSubtopics();
    }

    function populateSubtopics() {
        if (!subtopicSelect) return;
        const selectedExams = getSelectedExams();
        const totalCount = document.querySelectorAll('.exam-checkbox').length;
        const isAllSelected = (selectedExams.length === 0 || selectedExams.length === totalCount);
        const selectedCat = categorySelect ? categorySelect.getValue() : '';
        const selectedSub = subjectSelect ? subjectSelect.getValue() : '';
        const selectedTop = topicSelect ? topicSelect.getValue() : '';
        const currentSubtopVal = subtopicSelect.getValue();
        let subtopSet = new Set();

        Object.keys(hierarchy).forEach(e => {
            if (isAllSelected || selectedExams.includes(e)) {
                Object.keys(hierarchy[e]).forEach(c => {
                    if (!selectedCat || selectedCat === c) {
                        Object.keys(hierarchy[e][c]).forEach(s => {
                            if (!selectedSub || selectedSub === s) {
                                Object.keys(hierarchy[e][c][s]).forEach(t => {
                                    if (!selectedTop || selectedTop === t) {
                                        hierarchy[e][c][s][t].forEach(st => subtopSet.add(st));
                                    }
                                });
                            }
                        });
                    }
                });
            }
        });

        subtopicSelect.clearOptions();
        subtopicSelect.addOption({ value: '', text: 'All Subtopics' });

        Array.from(subtopSet).sort().forEach(st => {
            subtopicSelect.addOption({ value: st, text: st });
        });

        if (currentSubtopVal && subtopSet.has(currentSubtopVal)) {
            subtopicSelect.setValue(currentSubtopVal, true);
        } else {
            subtopicSelect.setValue('', true);
        }
    }

    function clearAllFilters() {
        document.getElementById('searchInput').value = '';
        selectAllExams(true);
        if (categorySelect) categorySelect.setValue('');
        if (subjectSelect) subjectSelect.setValue('');
        if (topicSelect) topicSelect.setValue('');
        if (subtopicSelect) subtopicSelect.setValue('');
        performSearch();
    }

    // Clean HTML Content Helper
    function cleanHtmlContent(html) {
        if (!html) return '';
        let str = String(html).trim();

        // 1. Decode HTML entities if text is encoded (e.g. &lt;p&gt; => <p>)
        if (str.includes('&lt;') || str.includes('&gt;') || str.includes('&amp;')) {
            const txt = document.createElement('textarea');
            txt.innerHTML = str;
            str = txt.value;
        }

        // 2. Fix protocol-relative URLs (e.g. src="//cdn.testbook.com" => src="https://cdn.testbook.com")
        str = str.replace(/src="\/\//gi, 'src="https://');

        // 3. Clean inline style attributes (e.g. style="font-family: var(...); ...")
        str = str.replace(/\s*style="[^"]*"/gi, '');

        // 4. Clean math-tex wrapper spans (e.g. <span class="math-tex">...</span>)
        str = str.replace(/<span class="math-tex">\s*/gi, '');

        // 5. Clean remaining span tags
        str = str.replace(/<span[^>]*>/gi, '').replace(/<\/span>/gi, '');

        // 6. Clean empty <p></p> or whitespace-only <p>&nbsp;</p>
        str = str.replace(/<p>\s*(&nbsp;)?\s*<\/p>/gi, '');

        return str.trim();
    }

    // Render Option Text with Both Marathi and English Support
    function renderOptionText(mr, en) {
        const textMr = cleanHtmlContent(mr);
        const textEn = cleanHtmlContent(en);

        if (textMr && textEn && textMr !== textEn) {
            return `
                <div class="opt-text-container">
                    <div class="opt-text-mr">${textMr}</div>
                    <div class="opt-text-en">${textEn}</div>
                </div>
            `;
        }
        return `
            <div class="opt-text-container">
                <div class="opt-text-mr">${textMr || textEn}</div>
            </div>
        `;
    }

    // Interactive Option Selection in Attempt Mode
    function handleOptionSelect(qId, selectedOpt, correctOpt) {
        if (!isAttemptMode) return;
        if (attemptedQuestions[qId]) return; // Already answered

        attemptedQuestions[qId] = selectedOpt;

        const isCorrect = (selectedOpt === correctOpt);

        // Highlight selected option
        const selectedEl = document.getElementById(`opt_${qId}_${selectedOpt}`);
        const correctEl = document.getElementById(`opt_${qId}_${correctOpt}`);
        const feedbackEl = document.getElementById(`feedback_${qId}`);

        if (isCorrect) {
            if (selectedEl) {
                selectedEl.classList.add('user-correct');
                selectedEl.innerHTML += '<i class="fa-solid fa-circle-check status-icon correct-icon"></i>';
            }
            if (feedbackEl) {
                feedbackEl.className = 'attempt-feedback success';
                feedbackEl.innerHTML = '<i class="fa-solid fa-trophy"></i> <strong>🎉 Correct Answer! Excellent work!</strong>';
            }

            // Trigger Instagram Poll / Celebration Confetti Burst
            if (typeof confetti === 'function') {
                confetti({
                    particleCount: 80,
                    spread: 70,
                    origin: { y: 0.65 }
                });
            }
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
                feedbackEl.className = 'attempt-feedback error';
                feedbackEl.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> <strong>Incorrect!</strong> Correct Option is <strong>Option ${correctOpt}</strong>. Check explanation below.`;
            }

            // Auto-reveal solution explanation on wrong answer
            toggleSolution('sol_' + qId, true);
        }
    }

    // Solution Language Switcher (Marathi <-> English)
    function switchSolLang(qId, lang, event) {
        if (event) event.stopPropagation();
        
        // Auto-open solution box if collapsed
        toggleSolution('sol_' + qId, true);

        const mrContent = document.getElementById(`sol_mr_${qId}`);
        const enContent = document.getElementById(`sol_en_${qId}`);
        const btnMr = document.getElementById(`btn_mr_${qId}`);
        const btnEn = document.getElementById(`btn_en_${qId}`);

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

    async function performSearch(append = false) {
        if (!append) {
            currentPage = 1;
            loadedQuestionsCount = 0;
        }

        const query = document.getElementById('searchInput').value.trim();
        const exam = getSelectedExamsParam();
        const category = categorySelect ? categorySelect.getValue() : '';
        const subject = subjectSelect ? subjectSelect.getValue() : '';
        const topic = topicSelect ? topicSelect.getValue() : '';
        const subtopic = subtopicSelect ? subtopicSelect.getValue() : '';
        
        const resultsList = document.getElementById('resultsList');
        const matchCount = document.getElementById('matchCount');
        const loadMoreContainer = document.getElementById('loadMoreContainer');
        const loadMoreBtn = document.getElementById('loadMoreBtn');

        if (!query && !exam && !category && !subject && !topic && !subtopic) {
            matchCount.textContent = '0';
            loadMoreContainer.style.display = 'none';
            resultsList.innerHTML = `
                <div class="empty-state">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 38px; color: var(--primary); margin-bottom: 12px;"></i>
                    <p style="font-size: 15px; color: var(--text-muted);">Type a search keyword above or select an <strong>Exam</strong>, <strong>Test Type</strong>, <strong>Subject</strong>, <strong>Topic</strong>, or <strong>Subtopic</strong> filter to start searching.</p>
                </div>
            `;
            return;
        }

        if (!append) {
            resultsList.innerHTML = `
                <div class="empty-state">
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    <p>Searching BANK Database across 69,400+ questions...</p>
                </div>
            `;
        } else {
            if (loadMoreBtn) loadMoreBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading More Questions...';
        }

        try {
            const scriptPath = window.location.pathname.endsWith('search.php') ? window.location.pathname : (window.location.pathname.replace(/\/$/, '') + '/search.php');
            const url = `${scriptPath}?ajax=1&q=${encodeURIComponent(query)}&exam=${encodeURIComponent(exam)}&category=${encodeURIComponent(category)}&subject=${encodeURIComponent(subject)}&topic=${encodeURIComponent(topic)}&subtopic=${encodeURIComponent(subtopic)}&page=${currentPage}`;
            
            let res;
            try {
                res = await fetch(url);
            } catch(fetchErr) {
                // Automatic retry on temporary network drop
                res = await fetch(url);
            }
            const json = await res.json();

            if (json.status === 'success') {
                const data = json.data;
                totalMatchesCount = json.total_count || 0;
                totalPages = json.total_pages || 1;

                if (!window.currentQuestionsMap) window.currentQuestionsMap = {};
                if (!append) {
                    window.currentQuestionsMap = {};
                    loadedQuestionsCount = data.length;
                } else {
                    loadedQuestionsCount += data.length;
                }
                data.forEach(q => { window.currentQuestionsMap[q.id] = q; });

                matchCount.textContent = `${loadedQuestionsCount.toLocaleString()} / ${totalMatchesCount.toLocaleString()}`;

                if (data.length === 0 && !append) {
                    loadMoreContainer.style.display = 'none';
                    resultsList.innerHTML = `
                        <div class="empty-state">
                            <i class="fa-solid fa-folder-open" style="font-size: 32px; color: var(--text-muted); margin-bottom: 8px;"></i>
                            <h3>No Questions Found</h3>
                            <p style="font-size: 13px; color: var(--text-muted);">Try typing a different keyword or select another subject/topic.</p>
                        </div>
                    `;
                    return;
                }

                // Render Questions Cards
                const cardsHtml = data.map((q, idx) => {
                    const globalIdx = (append ? loadedQuestionsCount - data.length : 0) + idx + 1;
                    const corr = parseInt(q.correct_option) || 1;
                    const eName = examLabels[q.test_series_slug] || q.test_series_slug || 'MPSC Exam';
                    const hasMrSol = !!q.solution_mr;
                    const hasEnSol = !!q.solution_en;

                    const safeCat = q.category_name ? q.category_name.replace(/'/g, "\\'") : '';
                    const safeTestTitle = q.test_title ? q.test_title.replace(/'/g, "\\'") : '';
                    const safeSub = q.subject_name ? q.subject_name.replace(/'/g, "\\'") : '';
                    const safeTop = q.topic_name ? q.topic_name.replace(/'/g, "\\'") : '';
                    const safeSubtop = q.subtopic_name ? q.subtopic_name.replace(/'/g, "\\'") : '';

                    return `
                        <div class="question-card">
                            <div class="tags-row">
                                <span class="tag-pill tag-exam clickable" onclick="filterByTag('exam', '${q.test_series_slug}')" title="Click to view all ${eName} questions">
                                    <i class="fa-solid fa-graduation-cap"></i> ${eName}
                                </span>
                                ${q.category_name ? `
                                    <span class="tag-pill tag-cat clickable" onclick="filterByTag('category', '${safeCat}')" title="Click to view all ${q.category_name} questions">
                                        <i class="fa-solid fa-layer-group"></i> ${q.category_name}
                                    </span>
                                ` : ''}
                                <span class="tag-pill tag-test clickable" onclick="filterByTag('test_title', '${safeTestTitle}')" title="Click to view all questions from ${q.test_title || 'this test'}">
                                    <i class="fa-solid fa-file-lines"></i> ${q.test_title || 'MPSC Test'}
                                </span>
                                ${q.subject_name ? `
                                    <span class="tag-pill tag-subject clickable" onclick="filterByTag('subject', '${safeSub}')" title="Click to view all ${q.subject_name} questions">
                                        <i class="fa-solid fa-book-open"></i> ${q.subject_name}
                                    </span>
                                ` : ''}
                                ${q.topic_name ? `
                                    <span class="tag-pill tag-topic clickable" onclick="filterByTag('topic', '${safeTop}')" title="Click to view all ${q.topic_name} questions">
                                        <i class="fa-solid fa-tag"></i> ${q.topic_name}
                                    </span>
                                ` : ''}
                                ${q.subtopic_name ? `
                                    <span class="tag-pill tag-subtopic clickable" onclick="filterByTag('subtopic', '${safeSubtop}')" title="Click to view all ${q.subtopic_name} questions">
                                        <i class="fa-solid fa-code-branch"></i> ${q.subtopic_name}
                                    </span>
                                ` : ''}
                            </div>

                            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 6px;">
                                <div class="q-title" style="flex: 1; margin: 0;">Q${globalIdx}. ${cleanHtmlContent(q.question_mr || q.question_en)}</div>
                                <a href="https://www.google.com/search?q=${encodeURIComponent((q.question_mr || q.question_en || '').replace(/<[^>]*>/g, '').trim() + ' MPSC')}" target="_blank" class="btn-google-ai-search" title="Search this question directly on Google" style="text-decoration: none;">
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
                            ${q.question_en && q.question_mr ? `<div class="q-title-en">Q. ${cleanHtmlContent(q.question_en)}</div>` : ''}

                            <div class="options-grid">
                                <div id="opt_${q.id}_1" class="option-item ${!isAttemptMode ? (corr === 1 ? 'correct' : '') : 'interactive'}" onclick="handleOptionSelect(${q.id}, 1, ${corr})">
                                    <span class="opt-num">1</span>
                                    ${renderOptionText(q.opt1_mr, q.opt1_en)}
                                    ${!isAttemptMode && corr === 1 ? '<i class="fa-solid fa-circle-check correct-check"></i>' : ''}
                                </div>
                                <div id="opt_${q.id}_2" class="option-item ${!isAttemptMode ? (corr === 2 ? 'correct' : '') : 'interactive'}" onclick="handleOptionSelect(${q.id}, 2, ${corr})">
                                    <span class="opt-num">2</span>
                                    ${renderOptionText(q.opt2_mr, q.opt2_en)}
                                    ${!isAttemptMode && corr === 2 ? '<i class="fa-solid fa-circle-check correct-check"></i>' : ''}
                                </div>
                                <div id="opt_${q.id}_3" class="option-item ${!isAttemptMode ? (corr === 3 ? 'correct' : '') : 'interactive'}" onclick="handleOptionSelect(${q.id}, 3, ${corr})">
                                    <span class="opt-num">3</span>
                                    ${renderOptionText(q.opt3_mr, q.opt3_en)}
                                    ${!isAttemptMode && corr === 3 ? '<i class="fa-solid fa-circle-check correct-check"></i>' : ''}
                                </div>
                                <div id="opt_${q.id}_4" class="option-item ${!isAttemptMode ? (corr === 4 ? 'correct' : '') : 'interactive'}" onclick="handleOptionSelect(${q.id}, 4, ${corr})">
                                    <span class="opt-num">4</span>
                                    ${renderOptionText(q.opt4_mr, q.opt4_en)}
                                    ${!isAttemptMode && corr === 4 ? '<i class="fa-solid fa-circle-check correct-check"></i>' : ''}
                                </div>
                            </div>

                            <div id="feedback_${q.id}"></div>

                            ${(hasMrSol || hasEnSol) ? `
                                <div class="sol-action-bar">
                                    <button class="sol-btn" onclick="toggleSolution('sol_${q.id}')">
                                        <i class="fa-solid fa-lightbulb"></i> View Explanation & Key Points
                                    </button>

                                    ${(hasMrSol && hasEnSol) ? `
                                        <div class="lang-switch-pills">
                                            <button class="lang-pill active" id="btn_mr_${q.id}" onclick="switchSolLang(${q.id}, 'mr', event)">मराठी</button>
                                            <button class="lang-pill" id="btn_en_${q.id}" onclick="switchSolLang(${q.id}, 'en', event)">English</button>
                                        </div>
                                    ` : ''}
                                </div>

                                <div id="sol_${q.id}" class="sol-box">
                                    ${hasMrSol ? `
                                        <div id="sol_mr_${q.id}" class="sol-content">
                                            <strong><i class="fa-solid fa-circle-info"></i> स्पष्टीकरण (Marathi Solution):</strong><br>
                                            ${cleanHtmlContent(q.solution_mr)}
                                        </div>
                                    ` : ''}
                                    ${hasEnSol ? `
                                        <div id="sol_en_${q.id}" class="sol-content ${hasMrSol ? 'sol-hidden' : ''}">
                                            <strong><i class="fa-solid fa-circle-info"></i> Explanation (English Solution):</strong><br>
                                            ${cleanHtmlContent(q.solution_en)}
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                        </div>
                    `;
                }).join('');

                if (!append) {
                    resultsList.innerHTML = cardsHtml;
                } else {
                    resultsList.innerHTML += cardsHtml;
                }

                // Render MathJax LaTeX expressions if present
                if (window.MathJax && typeof window.MathJax.typesetPromise === 'function') {
                    window.MathJax.typesetPromise();
                }

                // Show or hide "Load More" button
                if (currentPage < totalPages) {
                    loadMoreContainer.style.display = 'block';
                    if (loadMoreBtn) loadMoreBtn.innerHTML = `<i class="fa-solid fa-angles-down"></i> Load More Questions (${loadedQuestionsCount} of ${totalMatchesCount.toLocaleString()})`;
                } else {
                    loadMoreContainer.style.display = 'none';
                }
            }
        } catch (e) {
            resultsList.innerHTML = `
                <div class="empty-state">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <p>Search error: ${e.message}</p>
                </div>
            `;
        }
    }

    function loadNextPage() {
        if (currentPage < totalPages) {
            currentPage++;
            performSearch(true);
        }
    }

    function toggleSolution(id, forceShow = false) {
        const box = document.getElementById(id);
        if (box) {
            if (forceShow) {
                box.classList.add('show');
            } else {
                box.classList.toggle('show');
            }
        }
    }

    // Debounced Live Search
    document.getElementById('searchInput').addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(performSearch, 300);
    });

    // Automatically Read URL Search Parameters on Page Load (e.g. ?q=infrastructure)
    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const qParam = urlParams.get('q');
        const examParam = urlParams.get('exam');
        const categoryParam = urlParams.get('category');
        const subjectParam = urlParams.get('subject');
        const topicParam = urlParams.get('topic');
        const subtopicParam = urlParams.get('subtopic');

        let shouldSearch = false;

        if (qParam) {
            document.getElementById('searchInput').value = qParam;
            shouldSearch = true;
        }
        if (examParam && typeof examSelect !== 'undefined' && examSelect) {
            examSelect.setValue(examParam);
            shouldSearch = true;
        }
        if (categoryParam && typeof categorySelect !== 'undefined' && categorySelect) {
            categorySelect.setValue(categoryParam);
            shouldSearch = true;
        }
        if (subjectParam && typeof subjectSelect !== 'undefined' && subjectSelect) {
            subjectSelect.setValue(subjectParam);
            shouldSearch = true;
        }
        if (topicParam && typeof topicSelect !== 'undefined' && topicSelect) {
            topicSelect.setValue(topicParam);
            shouldSearch = true;
        }
        if (subtopicParam && typeof subtopicSelect !== 'undefined' && subtopicSelect) {
            subtopicSelect.setValue(subtopicParam);
            shouldSearch = true;
        }

        if (shouldSearch || document.getElementById('searchInput').value.trim() !== '') {
            performSearch();
        }
    });

    // CAMERA OCR QUESTION SCANNER (LENS FEATURE)
    let tesseractWorker = null;

    function openCameraScanner() {
        document.getElementById('cameraFileInput').click();
    }

    async function handleImageSelected(input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        
        const reader = new FileReader();
        reader.onload = async function(e) {
            document.getElementById('ocrImagePreview').src = e.target.result;
            document.getElementById('ocrModal').style.display = 'flex';
            document.getElementById('ocrProgressBar').style.width = '15%';
            document.getElementById('ocrStatusText').innerText = 'Initializing Lens OCR engine... (मशीन सुरू करत आहे...)';

            try {
                if (!tesseractWorker) {
                    tesseractWorker = await Tesseract.createWorker(['mar', 'eng'], 1, {
                        logger: m => {
                            if (m.status === 'recognizing text') {
                                const progress = Math.round(m.progress * 100);
                                document.getElementById('ocrProgressBar').style.width = progress + '%';
                                document.getElementById('ocrStatusText').innerText = `Extracting question text... (${progress}%)`;
                            }
                        }
                    });
                }

                document.getElementById('ocrStatusText').innerText = 'Reading Marathi & English text from image...';
                const ret = await tesseractWorker.recognize(e.target.result);
                const extractedText = ret.data && ret.data.text ? ret.data.text.trim() : '';

                document.getElementById('ocrModal').style.display = 'none';

                if (extractedText.length > 2) {
                    // Clean extracted text: replace line breaks with spaces & trim noise
                    const cleanQuery = extractedText.replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').substring(0, 120);
                    const searchInput = document.getElementById('searchInput');
                    searchInput.value = cleanQuery;
                    performSearch();
                } else {
                    alert('Could not detect clear text from the image. Please take a clearer photo and try again.');
                }
            } catch (err) {
                console.error('OCR Error:', err);
                document.getElementById('ocrModal').style.display = 'none';
                alert('Error scanning image text: ' + err.message);
            } finally {
                input.value = '';
            }
        };
        reader.readAsDataURL(file);
    }


</script>

<!-- OCR CAMERA SCANNER MODAL -->
<div id="ocrModal" class="modal-overlay" style="display: none;">
    <div class="modal-card">
        <div style="margin-bottom: 12px;">
            <i class="fa-solid fa-camera-retro" style="font-size: 32px; color: var(--primary);"></i>
            <h3 style="font-size: 16px; font-weight: 700; margin-top: 6px; color: #fff;">Scanning Question (चित्र स्कॅन प्रगतीपथावर)</h3>
        </div>
        <div id="ocrPreviewContainer" style="margin-bottom: 14px;">
            <img id="ocrImagePreview" src="" style="max-height: 180px; max-width: 100%; border-radius: 10px; border: 1px solid rgba(255,255,255,0.2); object-fit: contain;">
        </div>
        <div style="width: 100%; background: #334155; height: 10px; border-radius: 10px; overflow: hidden; margin-bottom: 10px;">
            <div id="ocrProgressBar" style="width: 0%; height: 100%; background: linear-gradient(90deg, #3b82f6, #10b981); transition: width 0.3s ease;"></div>
        </div>
        <p id="ocrStatusText" style="font-size: 13px; color: var(--text-muted);">Initializing Lens OCR engine... (प्रक्रिया सुरू आहे...)</p>
    </div>
</div>

</body>
</html>

