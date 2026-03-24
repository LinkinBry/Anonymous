<?php
include "config.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'expired']);
    exit();
}

$_SESSION['last_activity']    = time();
$_SESSION['session_expires']  = time() + 300;

echo json_encode([
    'status'     => 'active',
    'expires_in' => $_SESSION['session_expires'] - time()
]);
?>