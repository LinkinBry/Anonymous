<?php
/**
 * get_user_reviews.php
 * AJAX endpoint — returns JSON with all reviews made by a specific user with pagination
 */
error_reporting(0);
ini_set('display_errors', 0);

include "config.php";
header('Content-Type: application/json');

// ── Session check ─────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) { 
    echo json_encode(['error'=>'session_expired']); 
    exit(); 
}

// ── Auth: admin only ──────────────────────────────────────────────────────
$uid = intval($_SESSION['user_id']);
$chk = mysqli_query($conn,"SELECT role FROM users WHERE id='$uid' LIMIT 1");
if (!$chk||mysqli_num_rows($chk)===0||mysqli_fetch_assoc($chk)['role']!=='admin') {
    echo json_encode(['error'=>'unauthorized']); 
    exit();
}

$user_id = intval($_GET['user_id'] ?? 0);
$page = intval($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

if ($user_id <= 0) { 
    echo json_encode(['error'=>'invalid_user']); 
    exit(); 
}

$per_page = 5;
$offset = ($page - 1) * $per_page;

// Get total count
$count_res = mysqli_query($conn,"SELECT COUNT(*) as total FROM reviews WHERE user_id='$user_id'");
$count_row = mysqli_fetch_assoc($count_res);
$total_reviews = intval($count_row['total']);
$total_pages = ceil($total_reviews / $per_page);

// Fetch paginated reviews
$reviews = [];
$rev_res = mysqli_query($conn,"
    SELECT r.id, r.review_text, r.sentiment, r.status, r.created_at, r.rating_overall,
           r.rating_teaching, r.rating_communication, r.rating_punctuality, r.rating_fairness,
           COALESCE(r.photo, '') AS photo,
           f.name AS faculty_name
    FROM reviews r
    JOIN faculties f ON r.faculty_id = f.id
    WHERE r.user_id = '$user_id'
    ORDER BY r.created_at DESC
    LIMIT $per_page OFFSET $offset
");

if ($rev_res) {
    while ($row = mysqli_fetch_assoc($rev_res)) {
        $reviews[] = [
            'id'            => $row['id'],
            'review_text'   => $row['review_text'],
            'sentiment'     => $row['sentiment'],
            'status'        => $row['status'],
            'faculty_name'  => $row['faculty_name'],
            'rating_overall'=> intval($row['rating_overall']),
            'rating_teaching' => intval($row['rating_teaching']),
            'rating_communication' => intval($row['rating_communication']),
            'rating_punctuality' => intval($row['rating_punctuality']),
            'rating_fairness' => intval($row['rating_fairness']),
            'photo'         => $row['photo'],
            'created_at'    => date("M j, Y", strtotime($row['created_at'])),
        ];
    }
}

echo json_encode([
    'user_id'     => $user_id,
    'reviews'     => $reviews,
    'page'        => $page,
    'per_page'    => $per_page,
    'total'       => $total_reviews,
    'total_pages' => $total_pages,
]);
?>
