<?php
/**
 * get_faculty_reviews.php
 * AJAX endpoint — returns JSON with approved reviews + stats for a given faculty.
 * Also supports ?action=summary for AI-generated individual faculty report.
 */
error_reporting(0);
ini_set('display_errors', 0);

include "config.php";
header('Content-Type: application/json');

// ── Session check ─────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) { echo json_encode(['error'=>'session_expired']); exit(); }
define('_GFR_TIMEOUT', 300);
if (isset($_SESSION['last_activity']) && (time()-$_SESSION['last_activity'])>_GFR_TIMEOUT) {
    session_unset(); session_destroy();
    echo json_encode(['error'=>'session_expired']); exit();
}
$_SESSION['last_activity']   = time();
$_SESSION['session_expires'] = time()+_GFR_TIMEOUT;

// ── Auth: admin only ──────────────────────────────────────────────────────
$uid = intval($_SESSION['user_id']);
$chk = mysqli_query($conn,"SELECT role FROM users WHERE id='$uid' LIMIT 1");
if (!$chk||mysqli_num_rows($chk)===0||mysqli_fetch_assoc($chk)['role']!=='admin') {
    echo json_encode(['error'=>'unauthorized']); exit();
}

$faculty_id = intval($_GET['faculty_id'] ?? 0);
if ($faculty_id<=0) { echo json_encode(['error'=>'invalid_faculty']); exit(); }

$action = $_GET['action'] ?? 'reviews';

// ── DELETE a single review ────────────────────────────────────────────────
if ($action === 'delete') {
    $rid = intval($_GET['review_id'] ?? 0);
    if ($rid<=0) { echo json_encode(['error'=>'invalid_review']); exit(); }

    // Notify user
    $rev = mysqli_fetch_assoc(mysqli_query($conn,"SELECT r.user_id,f.name AS fname FROM reviews r JOIN faculties f ON r.faculty_id=f.id WHERE r.id='$rid' LIMIT 1"));
    if ($rev) {
        $msg = mysqli_real_escape_string($conn,"Your approved review for {$rev['fname']} has been removed by the admin.");
        mysqli_query($conn,"INSERT INTO notifications (user_id,message,status,created_at) VALUES ('{$rev['user_id']}','$msg','unread',NOW())");
    }
    mysqli_query($conn,"DELETE FROM reviews WHERE id='$rid' AND status='approved'");
    echo json_encode(['success'=>true]); exit();
}

// ── AI individual faculty summary ─────────────────────────────────────────
if ($action === 'summary') {
    // Fetch all approved reviews for this faculty
    $fac = mysqli_fetch_assoc(mysqli_query($conn,"SELECT name,department FROM faculties WHERE id='$faculty_id' LIMIT 1"));
    if (!$fac) { echo json_encode(['error'=>'Faculty not found']); exit(); }

    $rev_res = mysqli_query($conn,"
        SELECT review_text,sentiment,rating_teaching,rating_communication,
               rating_punctuality,rating_fairness,rating_overall,created_at
        FROM reviews
        WHERE faculty_id='$faculty_id' AND status='approved'
        ORDER BY created_at DESC LIMIT 30
    ");
    $reviews = [];
    while ($row=mysqli_fetch_assoc($rev_res)) $reviews[]=$row;

    if (empty($reviews)) {
        echo json_encode(['summary'=>'No approved reviews found for this faculty member. There is insufficient data to generate a report.']);
        exit();
    }

    $stats_res = mysqli_query($conn,"
        SELECT COUNT(*) AS total,
            ROUND(AVG(rating_teaching),1) AS avg_t,
            ROUND(AVG(rating_communication),1) AS avg_c,
            ROUND(AVG(rating_punctuality),1) AS avg_p,
            ROUND(AVG(rating_fairness),1) AS avg_f,
            ROUND(AVG(rating_overall),1) AS avg_o,
            SUM(CASE WHEN sentiment='positive' THEN 1 ELSE 0 END) AS pos,
            SUM(CASE WHEN sentiment='negative' THEN 1 ELSE 0 END) AS neg,
            SUM(CASE WHEN sentiment='neutral' THEN 1 ELSE 0 END) AS neu
        FROM reviews WHERE faculty_id='$faculty_id' AND status='approved'
    ");
    $st = mysqli_fetch_assoc($stats_res);

    $review_lines = [];
    foreach ($reviews as $rv) {
        $review_lines[] = '- ['.ucfirst($rv['sentiment']).'] Overall:'.$rv['rating_overall'].'/5 | "'.$rv['review_text'].'"';
    }

    $env = parse_ini_file(__DIR__.'/.env');
    $api_key = $env['GROQ_API_KEY'] ?? '';
    if (empty($api_key)) { echo json_encode(['error'=>'API key not configured']); exit(); }

    $prompt = "You are an academic performance analyst. Write an individual performance report for the faculty member below.\n\n"
        ."FACULTY: {$fac['name']} ({$fac['department']})\n"
        ."TOTAL REVIEWS: {$st['total']} | Positive: {$st['pos']} | Negative: {$st['neg']} | Neutral: {$st['neu']}\n"
        ."AVERAGE RATINGS: Teaching:{$st['avg_t']}/5 | Communication:{$st['avg_c']}/5 | Punctuality:{$st['avg_p']}/5 | Fairness:{$st['avg_f']}/5 | Overall:{$st['avg_o']}/5\n\n"
        ."STUDENT REVIEWS (up to 30):\n".implode("\n",$review_lines)."\n\n"
        ."Write a structured individual faculty performance report with:\n"
        ."1. Overall performance summary\n"
        ."2. Key strengths identified from student feedback\n"
        ."3. Areas needing improvement\n"
        ."4. Notable patterns or recurring themes in reviews\n"
        ."5. Recommendation for the faculty member\n\n"
        ."Professional tone, plain text only — no markdown, no asterisks, no bullet symbols. Use numbered sections. Maximum 350 words.";

    $payload = json_encode([
        'model'=>'llama-3.3-70b-versatile','max_tokens'=>600,
        'messages'=>[
            ['role'=>'system','content'=>'You are a professional academic performance analyst. Write clear, fair, evidence-based reports in plain text only.'],
            ['role'=>'user','content'=>$prompt]
        ]
    ]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$api_key]]);
    $response=curl_exec($ch); $err=curl_error($ch); curl_close($ch);
    if ($err) { echo json_encode(['error'=>'Network error: '.$err]); exit(); }
    $data=json_decode($response,true);
    $summary=$data['choices'][0]['message']['content']??null;
    if (!$summary) { echo json_encode(['error'=>'AI did not return a response. Please try again.']); exit(); }
    echo json_encode(['summary'=>trim($summary)]); exit();
}

// ── Default: fetch reviews + stats ───────────────────────────────────────
$stats_res = mysqli_query($conn,"
    SELECT COUNT(*) AS total_approved,
        ROUND(AVG(rating_teaching),1) AS avg_teaching,
        ROUND(AVG(rating_communication),1) AS avg_communication,
        ROUND(AVG(rating_punctuality),1) AS avg_punctuality,
        ROUND(AVG(rating_fairness),1) AS avg_fairness,
        ROUND(AVG(rating_overall),1) AS avg_overall,
        SUM(CASE WHEN sentiment='positive' THEN 1 ELSE 0 END) AS positive_count
    FROM reviews WHERE faculty_id='$faculty_id' AND status='approved'
");
$stats = mysqli_fetch_assoc($stats_res) ?: [];
$total_approved = intval($stats['total_approved']??0);
$positive_pct   = $total_approved>0 ? round(($stats['positive_count']/$total_approved)*100) : 0;

$reviews = [];
$rev_res = mysqli_query($conn,"
    SELECT r.id, r.review_text, r.sentiment, r.created_at,
        r.rating_teaching, r.rating_communication,
        r.rating_punctuality, r.rating_fairness, r.rating_overall
    FROM reviews r
    WHERE r.faculty_id='$faculty_id' AND r.status='approved'
    ORDER BY r.created_at DESC LIMIT 50
");
if ($rev_res) {
    while ($row=mysqli_fetch_assoc($rev_res)) {
        $reviews[] = [
            'id'                  => $row['id'],
            'review_text'         => $row['review_text'],
            'sentiment'           => $row['sentiment'],
            'rating_teaching'     => $row['rating_teaching'],
            'rating_communication'=> $row['rating_communication'],
            'rating_punctuality'  => $row['rating_punctuality'],
            'rating_fairness'     => $row['rating_fairness'],
            'rating_overall'      => $row['rating_overall'],
            'created_at'          => date("M j, Y",strtotime($row['created_at'])),
        ];
    }
}

echo json_encode([
    'faculty_id'        => $faculty_id,
    'total_approved'    => $total_approved,
    'avg_teaching'      => $stats['avg_teaching']      ?? null,
    'avg_communication' => $stats['avg_communication'] ?? null,
    'avg_punctuality'   => $stats['avg_punctuality']   ?? null,
    'avg_fairness'      => $stats['avg_fairness']      ?? null,
    'avg_overall'       => $stats['avg_overall']       ?? null,
    'positive_pct'      => $positive_pct,
    'reviews'           => $reviews,
]);