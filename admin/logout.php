<?php
// ── /admin/logout.php ─────────────────────────────────────────────────────────
// Admin-specific logout — always redirects to /admin/login, never /login.

require_once __DIR__ . '/../config.php';

session_unset();
session_destroy();

// Expire the session cookie immediately
setcookie(
    session_name(),
    '',
    [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]
);

if (isset($_GET['timeout'])) {
    header('Location: login.php?timeout=1');
} else {
    header('Location: login.php');
}
exit();