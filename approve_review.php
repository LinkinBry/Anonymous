<?php
include "config.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$review_id = intval($_GET['id']);

// Approve the review
mysqli_query($conn, "UPDATE reviews SET status='approved' WHERE id='$review_id'");

// Fetch review info
$res = mysqli_query($conn, "SELECT user_id, faculty_id FROM reviews WHERE id='$review_id' LIMIT 1");
if ($res && mysqli_num_rows($res) > 0) {
    $review      = mysqli_fetch_assoc($res);
    $target_user = $review['user_id'];
    $faculty_id  = $review['faculty_id'];

    // Fetch faculty name
    $fres        = mysqli_query($conn, "SELECT name FROM faculties WHERE id='$faculty_id' LIMIT 1");
    $faculty_name = ($fres && mysqli_num_rows($fres) > 0)
        ? mysqli_fetch_assoc($fres)['name']
        : 'Unknown Faculty';

    $message = mysqli_real_escape_string($conn, "Your review for $faculty_name has been approved and is now published.");
    mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) 
                         VALUES ('$target_user', '$message', 'unread', NOW())");
}

header("Location: admin_dashboard.php");
exit();
?>