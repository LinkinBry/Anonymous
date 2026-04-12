<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['error'=>'session_expired']); exit(); }
define('_GFR_TIMEOUT', 300);
if (isset($_SESSION['last_activity']) && (time()-$_SESSION['last_activity'])>_GFR_TIMEOUT) {
    session_unset(); session_destroy(); echo json_encode(['error'=>'session_expired']); exit();
}
$_SESSION['last_activity']   = time();
$_SESSION['session_expires'] = time()+_GFR_TIMEOUT;

$uid = intval($_SESSION['user_id']);
$chk = mysqli_query($conn,"SELECT role FROM users WHERE id='$uid' LIMIT 1");
if (!$chk||mysqli_num_rows($chk)===0||mysqli_fetch_assoc($chk)['role']!=='admin') {
    echo json_encode(['error'=>'unauthorized']); exit();
}

$faculty_id = intval($_GET['faculty_id'] ?? 0);
if ($faculty_id<=0 && ($_GET['action'] ?? '') !== 'delete_any') {
    echo json_encode(['error'=>'invalid_faculty']); exit();
}

$action = $_GET['action'] ?? 'reviews';

if ($action === 'delete') {
    $rid = intval($_GET['review_id'] ?? 0);
    if ($rid<=0) { echo json_encode(['error'=>'invalid_review']); exit(); }
    $rev = mysqli_fetch_assoc(mysqli_query($conn,"SELECT r.user_id,f.name AS fname FROM reviews r JOIN faculties f ON r.faculty_id=f.id WHERE r.id='$rid' LIMIT 1"));
    if ($rev) {
        $msg = mysqli_real_escape_string($conn,"Your approved review for {$rev['fname']} has been removed by the admin.");
        mysqli_query($conn,"INSERT INTO notifications (user_id,message,status,created_at) VALUES ('{$rev['user_id']}','$msg','unread',NOW())");
    }
    mysqli_query($conn,"DELETE FROM reviews WHERE id='$rid' AND status='approved'");
    echo json_encode(['success'=>true]); exit();
}

if ($action === 'delete_any') {
    $rid = intval($_GET['review_id'] ?? 0);
    if ($rid<=0) { echo json_encode(['error'=>'invalid_review']); exit(); }
    $rev = mysqli_fetch_assoc(mysqli_query($conn,"SELECT r.user_id,r.status,f.name AS fname FROM reviews r JOIN faculties f ON r.faculty_id=f.id WHERE r.id='$rid' LIMIT 1"));
    if ($rev) {
        $msg = mysqli_real_escape_string($conn,"Your review for {$rev['fname']} has been removed by the admin.");
        mysqli_query($conn,"INSERT INTO notifications (user_id,message,status,created_at) VALUES ('{$rev['user_id']}','$msg','unread',NOW())");
    }
    mysqli_query($conn,"DELETE FROM reviews WHERE id='$rid'");
    echo json_encode(['success'=>true]); exit();
}

if ($action === 'summary') {
    require_once __DIR__ . '/faculty_summary.php';
    exit();
}

$stats_res = mysqli_query($conn,"SELECT COUNT(*) AS total_approved,ROUND(AVG(rating_teaching),1) AS avg_teaching,ROUND(AVG(rating_communication),1) AS avg_communication,ROUND(AVG(rating_punctuality),1) AS avg_punctuality,ROUND(AVG(rating_fairness),1) AS avg_fairness,ROUND(AVG(rating_overall),1) AS avg_overall,SUM(CASE WHEN sentiment='positive' THEN 1 ELSE 0 END) AS positive_count FROM reviews WHERE faculty_id='$faculty_id' AND status='approved'");
$stats = mysqli_fetch_assoc($stats_res) ?: [];
$total_approved = intval($stats['total_approved']??0);
$positive_pct   = $total_approved>0 ? round(($stats['positive_count']/$total_approved)*100) : 0;

$reviews = [];
$rev_res = mysqli_query($conn,"SELECT r.id, r.review_text, r.sentiment, r.created_at, r.rating_teaching, r.rating_communication, r.rating_punctuality, r.rating_fairness, r.rating_overall, COALESCE(r.photo,'') AS photo FROM reviews r WHERE r.faculty_id='$faculty_id' AND r.status='approved' ORDER BY r.created_at DESC LIMIT 50");
if ($rev_res) {
    while ($row=mysqli_fetch_assoc($rev_res)) {
        $raw = $row['photo']; $photos = [];
        if (!empty($raw)) { $dec = json_decode($raw, true); $photos = is_array($dec) ? $dec : [$raw]; }
        $reviews[] = ['id'=>$row['id'],'review_text'=>$row['review_text'],'sentiment'=>$row['sentiment'],'rating_teaching'=>$row['rating_teaching'],'rating_communication'=>$row['rating_communication'],'rating_punctuality'=>$row['rating_punctuality'],'rating_fairness'=>$row['rating_fairness'],'rating_overall'=>$row['rating_overall'],'created_at'=>date("M j, Y",strtotime($row['created_at'])),'photos'=>$photos];
    }
}

echo json_encode(['faculty_id'=>$faculty_id,'total_approved'=>$total_approved,'avg_teaching'=>$stats['avg_teaching']??null,'avg_communication'=>$stats['avg_communication']??null,'avg_punctuality'=>$stats['avg_punctuality']??null,'avg_fairness'=>$stats['avg_fairness']??null,'avg_overall'=>$stats['avg_overall']??null,'positive_pct'=>$positive_pct,'reviews'=>$reviews]);