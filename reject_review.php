<?php
include "config.php";
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$id = intval($_GET['id']);
mysqli_query($conn, "UPDATE reviews SET status='rejected' WHERE id='$id'");
header("Location: admin_dashboard.php");
exit();
?>
