<?php
// ── session_check.php ─────────────────────────────────────────────────────────
// Included at the top of every protected page (after config.php).
// Handles server-side idle-timeout independently of the cookie lifetime.
// The cookie itself is refreshed on every request by config.php.

// Guard: constants may already be defined if config.php was included first.
if (!defined('SESSION_IDLE_TIMEOUT')) define('SESSION_IDLE_TIMEOUT', 300);
if (!defined('SESSION_WARN_BEFORE'))  define('SESSION_WARN_BEFORE',   10);

if (isset($_SESSION['user_id'])) {
    $now = time();

    // ── Idle-timeout check ────────────────────────────────────────────────────
    if (isset($_SESSION['last_activity'])) {
        $idle = $now - $_SESSION['last_activity'];

        if ($idle > SESSION_IDLE_TIMEOUT) {
            // Destroy server session AND expire the cookie immediately
            session_unset();
            session_destroy();

            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 3600,   // force-expire the cookie
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => true,
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]
            );

            $is_ajax   = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                         && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            $wants_json = isset($_SERVER['HTTP_ACCEPT'])
                         && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

            if ($is_ajax || $wants_json) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'session_expired']);
                exit();
            }

            header('Location: login.php?timeout=1');
            exit();
        }
    }

    // ── Stamp the last activity time ──────────────────────────────────────────
    $_SESSION['last_activity']   = $now;
    $_SESSION['session_expires'] = $now + SESSION_IDLE_TIMEOUT;
}