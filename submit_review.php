<?php
include "config.php";
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['faculty_id'])) {
    die("No faculty selected.");
}

$faculty_id = intval($_GET['faculty_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $review_text = mysqli_real_escape_string($conn, $_POST['review_text']);

    // Insert review as pending for admin approval
    mysqli_query($conn, "INSERT INTO reviews (user_id, faculty_id, review_text, status) 
                         VALUES ('$user_id', '$faculty_id', '$review_text', 'pending')");

    header("Location: dashboard.php?submitted=1");
    exit();
}

// Optional: fetch faculty info to display name
$faculty_result = mysqli_query($conn, "SELECT name FROM faculties WHERE id='$faculty_id' LIMIT 1");
$faculty = mysqli_fetch_assoc($faculty_result);
?>
<!DOCTYPE html>
<html>
<head><title>Submit Review</title></head>
<body>
<h2>Submit Review for <?php echo htmlspecialchars($faculty['name']); ?></h2>
<form method="POST">
    <textarea name="review_text" required placeholder="Write your anonymous review"></textarea><br><br>
    <button type="submit">Submit Review</button>
</form>
</body>
</html>