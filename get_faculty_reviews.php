<?php
/**
 * get_faculty_reviews.php
 * AJAX endpoint — returns JSON with approved reviews + stats for a given faculty.
 * Session expiry returns {"error":"session_expired"} instead of an HTML redirect.
 */

include "config.php";
header('Content-Type: application/json');

// ── Session check (AJAX-safe) ─────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'session_expired']);
    exit();
}

define('SESSION_TIMEOUT', 300);
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    echo json_encode(['error' => 'session_expired']);
    exit();
}
$_SESSION['last_activity']   = time();
$_SESSION['session_expires'] = time() + SESSION_TIMEOUT;

// ── Auth: admin only ──────────────────────────────────────────────────────
$user_id = intval($_SESSION['user_id']);
$chk = mysqli_query($conn, "SELECT role FROM users WHERE id='$user_id' LIMIT 1");
if (!$chk || mysqli_num_rows($chk) === 0) {
    echo json_encode(['error' => 'unauthorized']); exit();
}
$role = mysqli_fetch_assoc($chk)['role'];
if ($role !== 'admin') {
    echo json_encode(['error' => 'unauthorized']); exit();
}

// ── Validate input ────────────────────────────────────────────────────────
$faculty_id = intval($_GET['faculty_id'] ?? 0);
if ($faculty_id <= 0) {
    echo json_encode(['error' => 'invalid_faculty']); exit();
}

// ── Aggregate stats ───────────────────────────────────────────────────────
$stats_res = mysqli_query($conn, "
    SELECT
        COUNT(*)                                         AS total_approved,
        ROUND(AVG(rating_teaching), 1)                  AS avg_teaching,
        ROUND(AVG(rating_communication), 1)             AS avg_communication,
        ROUND(AVG(rating_punctuality), 1)               AS avg_punctuality,
        ROUND(AVG(rating_fairness), 1)                  AS avg_fairness,
        ROUND(AVG(rating_overall), 1)                   AS avg_overall,
        SUM(CASE WHEN sentiment='positive' THEN 1 ELSE 0 END) AS positive_count
    FROM reviews
    WHERE faculty_id = '$faculty_id' AND status = 'approved'
");

$stats = $stats_res ? mysqli_fetch_assoc($stats_res) : [];
$total_approved = intval($stats['total_approved'] ?? 0);
$positive_pct   = $total_approved > 0
    ? round(($stats['positive_count'] / $total_approved) * 100)
    : 0;

// ── Individual reviews ────────────────────────────────────────────────────
$reviews = [];
$rev_res = mysqli_query($conn, "
    SELECT r.review_text, r.sentiment, r.rating_overall, r.created_at
    FROM reviews r
    WHERE r.faculty_id = '$faculty_id' AND r.status = 'approved'
    ORDER BY r.created_at DESC
    LIMIT 50
");
if ($rev_res) {
    while ($row = mysqli_fetch_assoc($rev_res)) {
        $reviews[] = [
            'review_text'   => $row['review_text'],
            'sentiment'     => $row['sentiment'],
            'rating_overall'=> $row['rating_overall'],
            'created_at'    => date("M j, Y", strtotime($row['created_at'])),
        ];
    }
}

// ── Output ────────────────────────────────────────────────────────────────
echo json_encode([
    'total_approved'    => $total_approved,
    'avg_teaching'      => $stats['avg_teaching']      ?? null,
    'avg_communication' => $stats['avg_communication'] ?? null,
    'avg_punctuality'   => $stats['avg_punctuality']   ?? null,
    'avg_fairness'      => $stats['avg_fairness']      ?? null,
    'avg_overall'       => $stats['avg_overall']       ?? null,
    'positive_pct'      => $positive_pct,
    'reviews'           => $reviews,
]);