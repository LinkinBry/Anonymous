<?php
include "config.php";
session_unset();
session_destroy();

// Only redirect with ?timeout=1 when the session genuinely expired automatically.
// The logout button in the sidebar links to logout.php (no param), so manual
// logout will never hit the timeout branch and the banner won't appear.
if (isset($_GET['timeout'])) {
    header("Location: login.php?timeout=1");
} else {
    header("Location: login.php");
}
exit();
?>