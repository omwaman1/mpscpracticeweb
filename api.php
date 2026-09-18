<?php
// sync/api.php - High-Speed Cloudflare HTTPS API Bridge for Local MySQL BANK Database
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';

function stemMarathiWord($word) {
    $w = trim($word);
    if (mb_strlen($w, 'UTF-8') <= 3) return $w;
    return preg_replace('/(चे|च्या|च्याच|तील|ने|स|बाबत|मुळे|वर|साठी|कडून|मध्ये|ात|ांच्या)$/u', '', $w);
}

try {
    $pdo = getDBConnection();

    $action   = $_REQUEST['action']   ?? 'search';
    $slug     = $_REQUEST['slug']     ?? '';
    $query    = $_REQUEST['q']        ?? '';
    $exam     = $_REQUEST['exam']     ?? '';
    $category = $_REQUEST['category'] ?? '';
    $subject  = $_REQUEST['subject']  ?? '';
    $topic    = $_REQUEST['topic']    ?? '';
    $subtopic = $_REQUEST['subtopic'] ?? '';
    $page     = max(1, intval($_REQUEST['page'] ?? 1));
    $limit    = min(500, max(1, intval($_REQUEST['limit'] ?? 500)));
    $offset   = intval($_REQUEST['offset'] ?? (($page - 1) * $limit));

    // Handle Subject / Category Direct Slug Requests
    if ($action === 'subject' || (!empty($slug) && empty($query))) {
        $cleanSlug = trim($slug);
        
        $whereSql = "WHERE (category_slug = :slug OR subject_slug = :slug OR topic_slug = :slug OR category_name LIKE :slug_like OR subject_name LIKE :slug_like)";
        $params = [
            ':slug' => $cleanSlug,
            ':slug_like' => '%' . str_replace('-', ' ', $cleanSlug) . '%'
        ];

        if (!empty($topic)) {
            $whereSql .= " AND topic_name = :topic";
            $params[':topic'] = $topic;
        }

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tbl_questions $whereSql");
        $stmtCount->execute($params);
        $totalMatches = (int)$stmtCount->fetchColumn();

        $dataSql = "SELECT * FROM tbl_questions $whereSql ORDER BY id ASC LIMIT :limit OFFSET :offset";
        $dataStmt = $pdo->prepare($dataSql);
        foreach ($params as $k => $v) {
            $dataStmt->bindValue($k, $v);
        }
        $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $questions = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status'      => 'success',
            'total_count' => $totalMatches,
            'count'       => count($questions),
            'page'        => $page,
            'limit'       => $limit,
            'offset'      => $offset,
            'total_pages' => ceil($totalMatches / $limit),
            'has_more'    => ($offset + count($questions)) < $totalMatches,
            'data'        => $questions,
            'questions'   => $questions
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Handle Advanced Search Requests with Synonym & Stemming Support
    $whereSql = "";
    $params = [];
    $selectScore = "1 as score";

    if (!empty($query)) {
        $cleanQ = trim($query, '"\'');
        $exactParam = '%' . $cleanQ . '%';

        $synMap = [
            'प्रधानमंत्री' => ['प्रधानमंत्री', 'पंतप्रधान', 'Pradhan Mantri', 'PMRY', 'PMEGP'],
            'पंतप्रधान'   => ['पंतप्रधान', 'प्रधानमंत्री', 'Pradhan Mantri', 'PMRY', 'PMEGP'],
            'योजना'        => ['योजना', 'योजनेत', 'योजनेचा', 'योजनेचे', 'योजनेअंतर्गत', 'योजनेद्वारे', 'Yojana', 'Scheme'],
            'अधिनियम'     => ['अधिनियम', 'कायदा', 'कायद्यानुसार', 'अधिनियमानुसार', 'Act'],
            'कायदा'        => ['कायदा', 'अधिनियम', 'कायद्यानुसार', 'Act'],
            'दुष्काळ'      => ['दुष्काळ', 'दुष्काळप्रवण', 'Drought'],
            'आरोग्य'       => ['आरोग्य', 'Health', 'NRHM', 'NHM'],
            'महामार्ग'     => ['महामार्ग', 'राष्ट्रीय महामार्ग', 'द्रुतगती मार्ग', 'समृद्धी महामार्ग', 'Highway', 'NH'],
            'राष्ट्रीय'     => ['राष्ट्रीय', 'National']
        ];

        $tokens = array_values(array_filter(explode(' ', $cleanQ), function($w) {
            return mb_strlen(trim($w)) > 1;
        }));

        if (count($tokens) > 0) {
            $tokenConds = [];
            foreach ($tokens as $idx => $token) {
                $synList = isset($synMap[$token]) ? $synMap[$token] : [$token];
                $stemmed = stemMarathiWord($token);
                if ($stemmed !== $token && !in_array($stemmed, $synList)) {
                    $synList[] = $stemmed;
                }
                $orSyns = [];
                foreach ($synList as $sIdx => $synWord) {
                    $pKey = ":t_" . $idx . "_" . $sIdx;
                    $orSyns[] = "(`question_mr` LIKE $pKey OR `question_en` LIKE $pKey OR `opt1_mr` LIKE $pKey OR `opt2_mr` LIKE $pKey OR `opt3_mr` LIKE $pKey OR `opt4_mr` LIKE $pKey OR `solution_mr` LIKE $pKey OR `topic_name` LIKE $pKey OR `subject_name` LIKE $pKey OR `test_title` LIKE $pKey)";
                    $params[$pKey] = '%' . $synWord . '%';
                }
                $tokenConds[] = "(" . implode(" OR ", $orSyns) . ")";
            }
            $whereSql .= " AND (" . implode(" AND ", $tokenConds) . ")";
            $params[':exact'] = $exactParam;

            $selectScore = "(CASE 
                WHEN `question_mr` LIKE :exact THEN 100
                WHEN `question_en` LIKE :exact THEN 90
                WHEN `topic_name` LIKE :exact THEN 80
                WHEN `subject_name` LIKE :exact THEN 70
                WHEN `test_title` LIKE :exact THEN 60
                ELSE 40
            END) as score";
        }
    }

    if (!empty($exam)) {
        $whereSql .= " AND `test_series_slug` = :exam";
        $params[':exam'] = $exam;
    }

    if (!empty($category)) {
        $whereSql .= " AND `category_name` = :category";
        $params[':category'] = $category;
    }

    if (!empty($subject)) {
        $whereSql .= " AND `subject_name` = :subject";
        $params[':subject'] = $subject;
    }

    if (!empty($topic)) {
        $whereSql .= " AND `topic_name` = :topic";
        $params[':topic'] = $topic;
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

    // 2. Fetch paginated question batch
    $orderSql = "ORDER BY `id` DESC";
    $dataSql = "SELECT * FROM `tbl_questions` WHERE 1=1" . $whereSql . " $orderSql LIMIT :limit OFFSET :offset";
    $dataStmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $questions = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'      => 'success',
        'total_count' => $totalMatches,
        'count'       => count($questions),
        'page'        => $page,
        'limit'       => $limit,
        'offset'      => $offset,
        'total_pages' => ceil($totalMatches / max(1, $limit)),
        'has_more'    => ($offset + count($questions)) < $totalMatches,
        'data'        => $questions,
        'questions'   => $questions
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}
