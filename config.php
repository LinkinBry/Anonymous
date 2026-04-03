<?php
date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$env = parse_ini_file(__DIR__ . '/.env');

$host = $env['DB_HOST'];
$user = $env['DB_USER'];
$password = $env['DB_PASS'];
$dbname = $env['DB_NAME'];

$conn = mysqli_connect($host, $user, $password, $dbname);
mysqli_query($conn, "SET time_zone = '+8:00'");

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}
?>
