<?php
// ── /admin/index.php ──────────────────────────────────────────────────────────
// Gateway for the /admin/ directory.
// • Already logged-in admin  → forward to the real admin dashboard
// • Already logged-in user   → kick to student dashboard (wrong area)
// • Not logged in            → forward to /admin/login.php

require_once __DIR__ . '/../config.php';   // starts session + DB

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        // Redirect to the main admin dashboard (one level up)
        header('Location: admin_dashboard.php');
    } else {
        // Logged-in student accidentally hit /admin/ — send them home
        header('Location: ../dashboard.php');
    }
} else {
    header('Location: login.php');
}
exit();