<?php

$uri  = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

if ($path !== '/' && substr($path, -1) === '/') {
    $path = substr($path, 0, -1);
}

switch ($path) {
    case '/':
    case '/index':
    case '/home':
        include 'index.php';
        break;
    case '/login':
        include 'login.php';
        break;
    case '/register':
        include 'register.php';
        break;
    case '/dashboard':
        include 'dashboard.php';
        break;
    case '/admin_dashboard':
    case '/admin':
        include 'admin_dashboard.php';
        break;
    case '/profile':
        include 'profile.php';
        break;
    case '/logout':
        include 'logout.php';
        break;
    case '/add_faculty':
        include 'add_faculty.php';
        break;
    case '/edit_faculty':
        include 'edit_faculty.php';
        break;
    case '/faculty_summary':
        include 'faculty_summary.php';
        break;
    case '/approve_review':
        include 'approve_review.php';
        break;
    case '/reject_review':
        include 'reject_review.php';
        break;
    case '/submit_review':
        include 'submit_review.php';
        break;
    case '/chatbot':
        include 'chatbot.php';
        break;
    case '/clear_notifications':
        include 'clear_notifications.php';
        break;
    case '/mark_notifications_read':
        include 'mark_notifications_read.php';
        break;
    case '/get_faculty_reviews':
        include 'get_faculty_reviews.php';
        break;
    case '/get_user_reviews':
        include 'get_user_reviews.php';
        break;
    case '/session_check':
        include 'session_check.php';
        break;
    case '/session_refresh':
        include 'session_refresh.php';
        break;
    case '/email_helper':
        include 'email_helper.php';
        break;
    case '/run':
        include 'run.php';
        break;
    case '/e':
        include 'e.php';
        break;
    default:
        $file = __DIR__ . $path;
        if (file_exists($file) && !is_dir($file)) {
            return false;
        } else {
            http_response_code(404);
            echo '404 Not Found';
        }
        break;
}
?>