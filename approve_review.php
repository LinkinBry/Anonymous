<?php
include "config.php";
include "email_helper.php";

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$review_id = intval($_GET['id']);

mysqli_query($conn, "UPDATE reviews SET status='approved' WHERE id='$review_id'");

$res = mysqli_query($conn, "
    SELECT r.user_id, r.faculty_id, u.username, u.email, u.fullname
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.id='$review_id' LIMIT 1
");

if ($res && mysqli_num_rows($res) > 0) {
    $row         = mysqli_fetch_assoc($res);
    $target_user = $row['user_id'];
    $faculty_id  = $row['faculty_id'];
    $username    = $row['username'];
    $email       = $row['email'];
    $fullname    = $row['fullname'];

    $fres        = mysqli_query($conn, "SELECT name FROM faculties WHERE id='$faculty_id' LIMIT 1");
    $faculty_name = ($fres && mysqli_num_rows($fres) > 0)
        ? mysqli_fetch_assoc($fres)['name'] : 'Unknown Faculty';

    // In-app notification
    $message = mysqli_real_escape_string($conn, "Your review for $faculty_name has been approved and is now published.");
    mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at)
                         VALUES ('$target_user', '$message', 'unread', NOW())");

    // Email notification
    sendBrevoEmail(
        $email,
        $fullname,
        'Your review has been approved — AnonymousReview',
        approvedEmailHtml($username, $faculty_name)
    );
}

header("Location: admin_dashboard.php");
exit();
?>