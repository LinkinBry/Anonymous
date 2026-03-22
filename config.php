<?php
session_start();


$host = "localhost";
$user = "root";
$password = "";
$dbname = "faculty_review_system";

$conn = mysqli_connect($host,$user,$password,$dbname);

if(!$conn){
die("Database connection failed");
}


?>