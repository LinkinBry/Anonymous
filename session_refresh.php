<?php
// ── session_refresh.php ───────────────────────────────────────────────────────
// Called via fetch() every 10 s from session_timeout.js to keep the session
// alive while the user is active.  Returns JSON so JS can react.

include 'config.php';   // starts session + refreshes cookie sliding window

if (!defined('SESSION_IDLE_TIMEOUT')) define('SESSION_IDLE_TIMEOUT', 300);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'expired']);
    exit();
}

$_SESSION['last_activity']   = time();
$_SESSION['session_expires'] = time() + SESSION_IDLE_TIMEOUT;

echo json_encode([
    'status'     => 'active',
    'expires_in' => SESSION_IDLE_TIMEOUT,
]);