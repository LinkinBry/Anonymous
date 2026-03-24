<?php
include "config.php";
session_unset();
session_destroy();

// Check if it was a timeout logout
if (isset($_GET['timeout'])) {
    header("Location: index.php?timeout=1");
} else {
    header("Location: index.php");
}
exit();
?>