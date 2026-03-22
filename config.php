<?php
session_start();

$host = "127.0.0.1";
$user = "root";
$password = "root";  // now has a password
$dbname = "faculty_review_system";

$conn = mysqli_connect($host, $user, $password, $dbname);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}
?>