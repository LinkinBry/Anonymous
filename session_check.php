<?php

define('SESSION_TIMEOUT', 300);
define('SESSION_WARNING', 290);

if (isset($_SESSION['user_id'])) {
    $now = time();

    if (isset($_SESSION['last_activity'])) {
        $elapsed = $now - $_SESSION['last_activity'];

        if ($elapsed > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();

            // If this is an AJAX/JSON request, return JSON instead of redirect
            $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                       strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            $wants_json = isset($_SERVER['HTTP_ACCEPT']) && 
                          strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

            if ($is_ajax || $wants_json) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'session_expired']);
                exit();
            }

            header("Location: index.php?timeout=1");
            exit();
        }
    }

    $_SESSION['last_activity']   = $now;
    $_SESSION['session_expires'] = $now + SESSION_TIMEOUT;
}