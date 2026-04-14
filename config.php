<?php
date_default_timezone_set('Asia/Manila');

// ── Cookie / Session lifetime constants ───────────────────────────────────────
define('SESSION_LIFETIME',    1800);   // 30 min — how long the cookie lives (seconds)
define('SESSION_IDLE_TIMEOUT', 1200);   // 20 min  — kick after inactivity (must be ≤ SESSION_LIFETIME)
define('SESSION_WARN_BEFORE',   10);   // 10 sec — show warning modal before auto-logout

if (session_status() === PHP_SESSION_NONE) {

    // ── Harden the session cookie ─────────────────────────────────────────────
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,   // cookie expires in 30 min (survives tab close)
        'path'     => '/',
        'domain'   => '',                 // current domain only
        'secure'   => true,               // HTTPS only
        'httponly' => true,               // no JS access to the cookie
        'samesite' => 'Strict',           // CSRF protection
    ]);

    session_start();

    // ── Refresh the cookie expiry on every request ────────────────────────────
    // Without this the cookie would expire SESSION_LIFETIME seconds after *login*,
    // not after the *last activity* — this makes it a sliding-window cookie.
    if (isset($_SESSION['user_id'])) {
        setcookie(
            session_name(),
            session_id(),
            [
                'expires'  => time() + SESSION_LIFETIME,
                'path'     => '/',
                'domain'   => '',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );
    }
}

// ── Database connection ───────────────────────────────────────────────────────
$env = parse_ini_file(__DIR__ . '/.env');

$host     = $env['DB_HOST'];
$user     = $env['DB_USER'];
$password = $env['DB_PASS'];
$dbname   = $env['DB_NAME'];

$conn = mysqli_connect($host, $user, $password, $dbname);
mysqli_query($conn, "SET time_zone = '+08:00'");

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}