<?php
include "config.php";
include "email_helper.php";

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$review_id = intval($_GET['id']);

// Fetch review details before any action
$check = mysqli_query($conn, "
    SELECT r.user_id, r.faculty_id, r.status, u.username, u.email, u.fullname
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.id='$review_id' LIMIT 1
");

if ($check && mysqli_num_rows($check) > 0) {
    $row    = mysqli_fetch_assoc($check);
    $status = $row['status'];

    $fres  = mysqli_query($conn, "SELECT name FROM faculties WHERE id='{$row['faculty_id']}' LIMIT 1");
    $fname = ($fres && mysqli_num_rows($fres) > 0)
        ? mysqli_fetch_assoc($fres)['name'] : 'Unknown Faculty';

    if ($status === 'approved') {
        // Admin is DELETING an approved review — remove it so user can resubmit
        mysqli_query($conn, "DELETE FROM reviews WHERE id='$review_id'");

        // Notify user their review was deleted
        $message = mysqli_real_escape_string($conn, "Your approved review for $fname has been removed by the admin.");
        mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at)
                             VALUES ('{$row['user_id']}','$message','unread',NOW())");

        // Email notification
        sendBrevoEmail(
            $row['email'],
            $row['fullname'],
            'Your review has been removed — AnonymousReview',
            deletedEmailHtml($row['username'], $fname)
        );

    } else {
        // Admin is REJECTING a pending review
        mysqli_query($conn, "UPDATE reviews SET status='rejected' WHERE id='$review_id'");

        $message = mysqli_real_escape_string($conn, "Your review for $fname has been rejected by the admin.");
        mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at)
                             VALUES ('{$row['user_id']}','$message','unread',NOW())");

        sendBrevoEmail(
            $row['email'],
            $row['fullname'],
            'Your review has been rejected — AnonymousReview',
            rejectedEmailHtml($row['username'], $fname)
        );
    }
}

header("Location: admin_dashboard.php");
exit();
?>