<?php
include "config.php";
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit(); }
$user_id = $_SESSION['user_id'];
mysqli_query($conn, "DELETE FROM notifications WHERE user_id='$user_id'");
echo json_encode(['success' => true]);
?>