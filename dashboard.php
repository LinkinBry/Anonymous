<?php
include "config.php";

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
} else {
    header("Location: login.php");
    exit();
}

$query = "SELECT fullname FROM users WHERE id='$user_id' LIMIT 1";
$result = mysqli_query($conn, $query);
$user = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : ['fullname' => 'User'];

$notif_count = 0;
$notifications = [];
$notif_res = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id='$user_id' ORDER BY created_at DESC LIMIT 5");
if ($notif_res && mysqli_num_rows($notif_res) > 0) {
    while ($row = mysqli_fetch_assoc($notif_res)) {
        $notifications[] = $row;
        if ($row['status'] === 'unread') $notif_count++;
    }
}

$faculties = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = mysqli_real_escape_string($conn, $_GET['search']);
    $faculty_query = "SELECT id, name, department FROM faculties WHERE name LIKE '%$search%' OR department LIKE '%$search%' ORDER BY name ASC";
} else {
    $faculty_query = "SELECT id, name, department FROM faculties ORDER BY name ASC";
}
$faculty_result = mysqli_query($conn, $faculty_query);
if ($faculty_result && mysqli_num_rows($faculty_result) > 0) {
    while ($row = mysqli_fetch_assoc($faculty_result)) $faculties[] = $row;
}

// Stats
$total_reviews = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id'"))['count'];
$pending_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id' AND status='pending'"))['count'];
$approved_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id' AND status='approved'"))['count'];
$rejected_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id' AND status='rejected'"))['count'];

if (isset($_POST['submit_review'])) {
    $faculty_id = intval($_POST['faculty_id']);
    $review_text = mysqli_real_escape_string($conn, $_POST['review_text']);
    mysqli_query($conn, "INSERT INTO reviews (user_id, faculty_id, review_text, status) VALUES ('$user_id','$faculty_id','$review_text','pending')");
    header("Location: dashboard.php?submitted=1");
    exit();
}

// Fetch faculties grouped by department for the modal
$departments = [];
$dept_res = mysqli_query($conn, "SELECT id, name, department FROM faculties ORDER BY department ASC, name ASC");
if ($dept_res && mysqli_num_rows($dept_res) > 0) {
    while ($row = mysqli_fetch_assoc($dept_res)) {
        $dept = $row['department'] ?: 'General';
        $departments[$dept][] = $row;
    }
}

// Fetch user's recent reviews
$recent_reviews = [];
$recent_res = mysqli_query($conn, "
    SELECT r.id, r.review_text, r.status, r.created_at, f.name AS faculty_name, f.department
    FROM reviews r
    JOIN faculties f ON r.faculty_id = f.id
    WHERE r.user_id='$user_id'
    ORDER BY r.created_at DESC LIMIT 5
");
if ($recent_res && mysqli_num_rows($recent_res) > 0) {
    while ($row = mysqli_fetch_assoc($recent_res)) $recent_reviews[] = $row;
}

$selected_faculty = null;
$faculty_reviews = [];
if (isset($_GET['faculty_id'])) {
    $faculty_id = intval($_GET['faculty_id']);
    $faculty_res = mysqli_query($conn, "SELECT * FROM faculties WHERE id='$faculty_id'");
    if ($faculty_res && mysqli_num_rows($faculty_res) > 0) $selected_faculty = mysqli_fetch_assoc($faculty_res);
    $review_res = mysqli_query($conn, "SELECT r.*, u.fullname FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.faculty_id='$faculty_id' ORDER BY r.created_at DESC");
    if ($review_res && mysqli_num_rows($review_res) > 0) {
        while ($row = mysqli_fetch_assoc($review_res)) $faculty_reviews[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - AnonymousReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root {
    --maroon: #8B0000;
    --maroon-dark: #6B0000;
    --maroon-light: #a30000;
    --maroon-pale: #fff5f5;
    --sidebar-w: 240px;
    --white: #ffffff;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-400: #9ca3af;
    --gray-600: #4b5563;
    --gray-800: #1f2937;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.10);
    --shadow-lg: 0 8px 32px rgba(0,0,0,0.13);
    --radius: 14px;
    --radius-sm: 8px;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--gray-100);
    color: var(--gray-800);
    min-height: 100vh;
}

/* ── Sidebar ── */
.sidebar {
    position: fixed; left: 0; top: 0;
    width: var(--sidebar-w); height: 100%;
    background: var(--maroon);
    display: flex; flex-direction: column;
    padding: 28px 16px 20px;
    box-shadow: 2px 0 12px rgba(139,0,0,0.18);
    z-index: 100;
}

.sidebar-brand {
    font-family: 'Playfair Display', serif;
    font-size: 17px;
    color: white;
    text-align: center;
    margin-bottom: 24px;
    line-height: 1.3;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(255,255,255,0.15);
}

.sidebar-avatar {
    width: 70px; height: 70px;
    border-radius: 50%;
    border: 3px solid rgba(255,255,255,0.4);
    display: block; margin: 0 auto 10px;
    object-fit: cover;
}

.sidebar-name {
    text-align: center;
    color: white;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 6px;
}

.sidebar-role {
    text-align: center;
    color: rgba(255,255,255,0.6);
    font-size: 11px;
    margin-bottom: 24px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.sidebar nav { flex: 1; }

.nav-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: rgba(255,255,255,0.4);
    padding: 0 10px;
    margin-bottom: 6px;
    margin-top: 16px;
}

.sidebar a {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    border-radius: var(--radius-sm);
    color: rgba(255,255,255,0.85);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
    margin-bottom: 2px;
}
.sidebar a:hover, .sidebar a.active {
    background: rgba(255,255,255,0.15);
    color: white;
}
.sidebar a svg { flex-shrink: 0; opacity: 0.8; }

.sidebar-footer {
    border-top: 1px solid rgba(255,255,255,0.15);
    padding-top: 14px;
}

/* ── Main ── */
.main { margin-left: var(--sidebar-w); padding: 32px 32px 60px; min-height: 100vh; }

/* ── Top bar ── */
.topbar {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 28px;
}
.topbar-left h1 {
    font-family: 'Playfair Display', serif;
    font-size: 26px; color: var(--gray-800);
}
.topbar-left p { color: var(--gray-400); font-size: 14px; margin-top: 3px; }
.topbar-right { display: flex; align-items: center; gap: 14px; }
.today-date {
    font-size: 13px; color: var(--gray-400);
    background: white; padding: 6px 14px;
    border-radius: 20px; border: 1px solid var(--gray-200);
}

/* Notification bell */
.notif-wrap { position: relative; cursor: pointer; }
.notif-btn {
    width: 38px; height: 38px; border-radius: 50%;
    background: white; border: 1px solid var(--gray-200);
    display: flex; align-items: center; justify-content: center;
    box-shadow: var(--shadow-sm);
    transition: box-shadow 0.2s;
}
.notif-btn:hover { box-shadow: var(--shadow-md); }
.notif-badge {
    position: absolute; top: -3px; right: -3px;
    background: var(--maroon); color: white;
    font-size: 10px; font-weight: 700;
    border-radius: 50%; width: 18px; height: 18px;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--gray-100);
}
.notif-dropdown {
    display: none; position: absolute; right: 0; top: 46px;
    background: white; border-radius: var(--radius); border: 1px solid var(--gray-200);
    width: 320px; box-shadow: var(--shadow-lg); z-index: 200; overflow: hidden;
}
.notif-dropdown-header {
    padding: 14px 16px; border-bottom: 1px solid var(--gray-100);
    font-weight: 600; font-size: 14px; color: var(--gray-800);
}
.notif-item { padding: 12px 16px; border-bottom: 1px solid var(--gray-100); font-size: 13px; }
.notif-item small { display: block; color: var(--gray-400); font-size: 11px; margin-top: 3px; }
.notif-empty { padding: 20px 16px; color: var(--gray-400); font-size: 13px; text-align: center; }

/* ── Stat Cards ── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}
.stat-card {
    background: white; border-radius: var(--radius);
    padding: 20px 22px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    border-top: 3px solid transparent;
    transition: box-shadow 0.2s, transform 0.2s;
    position: relative; overflow: hidden;
}
.stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.stat-card.total  { border-top-color: var(--gray-400); }
.stat-card.pending { border-top-color: #f59e0b; }
.stat-card.approved { border-top-color: #10b981; }
.stat-card.rejected { border-top-color: #ef4444; }
.stat-label { font-size: 12px; color: var(--gray-400); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
.stat-value { font-size: 32px; font-weight: 700; line-height: 1; }
.stat-card.total .stat-value  { color: var(--gray-600); }
.stat-card.pending .stat-value { color: #f59e0b; }
.stat-card.approved .stat-value { color: #10b981; }
.stat-card.rejected .stat-value { color: #ef4444; }
.stat-icon {
    position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
    opacity: 0.08;
}

/* ── Search ── */
.section-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px;
}
.section-title { font-size: 18px; font-weight: 600; color: var(--gray-800); }
.search-form {
    display: flex; align-items: center; gap: 8px;
    background: white; border: 1px solid var(--gray-200);
    border-radius: 30px; padding: 6px 14px;
    box-shadow: var(--shadow-sm);
}
.search-form input {
    border: none; outline: none; font-size: 13px;
    font-family: 'DM Sans', sans-serif;
    color: var(--gray-800); width: 200px; background: transparent;
}
.search-form button {
    background: var(--maroon); color: white;
    border: none; border-radius: 20px;
    padding: 5px 14px; font-size: 13px; cursor: pointer;
    font-family: 'DM Sans', sans-serif; font-weight: 500;
    transition: background 0.2s;
}
.search-form button:hover { background: var(--maroon-light); }
.search-clear {
    cursor: pointer; color: var(--gray-400);
    font-size: 16px; line-height: 1;
    display: none;
}

/* ── Faculty Grid ── */
.faculty-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 18px;
    margin-bottom: 32px;
}
.faculty-card {
    background: white; border-radius: var(--radius);
    padding: 24px 20px; text-align: center;
    box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200);
    transition: all 0.25s; cursor: pointer;
}
.faculty-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
    border-color: var(--maroon);
}
.faculty-card img {
    width: 70px; height: 70px; border-radius: 50%;
    margin-bottom: 14px;
    border: 3px solid var(--maroon-pale);
}
.faculty-card h3 { font-size: 15px; font-weight: 600; color: var(--gray-800); margin-bottom: 4px; }
.faculty-card p { font-size: 12px; color: var(--gray-400); margin-bottom: 14px; }
.faculty-card a {
    display: inline-block; text-decoration: none;
    background: var(--maroon); color: white;
    padding: 7px 18px; border-radius: 20px;
    font-size: 13px; font-weight: 500;
    transition: background 0.2s;
}
.faculty-card a:hover { background: var(--maroon-light); }

/* ── Empty state ── */
.empty-state {
    text-align: center; padding: 60px 20px;
    background: white; border-radius: var(--radius);
    border: 1px solid var(--gray-200);
}
.empty-state svg { margin-bottom: 16px; opacity: 0.35; }
.empty-state p { color: var(--gray-400); font-size: 15px; }

/* ── Review Section ── */
.review-section {
    background: white; border-radius: var(--radius);
    padding: 28px; box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200); margin-bottom: 28px;
}
.review-section h2 {
    font-size: 18px; font-weight: 600;
    color: var(--maroon); margin-bottom: 20px;
    padding-bottom: 14px; border-bottom: 1px solid var(--gray-100);
}
.review-list { list-style: none; }
.review-item {
    padding: 14px 0; border-bottom: 1px solid var(--gray-100);
    font-size: 14px; color: var(--gray-600); line-height: 1.6;
}
.review-item:last-child { border-bottom: none; }
.review-meta { font-size: 12px; color: var(--gray-400); margin-top: 5px; }
.status-badge {
    display: inline-block; font-size: 11px; font-weight: 600;
    padding: 2px 8px; border-radius: 20px; margin-left: 6px;
}
.status-pending  { background: #fef3c7; color: #92400e; }
.status-approved { background: #d1fae5; color: #065f46; }
.status-rejected { background: #fee2e2; color: #991b1b; }

/* Submit form */
.submit-form h3 { font-size: 15px; font-weight: 600; color: var(--gray-800); margin-bottom: 12px; margin-top: 20px; }
.submit-form textarea {
    width: 100%; padding: 12px 14px;
    border: 1px solid var(--gray-200); border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif; font-size: 14px;
    resize: vertical; outline: none; color: var(--gray-800);
    transition: border-color 0.2s;
    min-height: 100px;
}
.submit-form textarea:focus { border-color: var(--maroon); }
.submit-btn {
    margin-top: 10px;
    background: var(--maroon); color: white;
    border: none; padding: 10px 24px;
    border-radius: 20px; font-size: 14px; font-weight: 500;
    cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: background 0.2s;
}
.submit-btn:hover { background: var(--maroon-light); }
.success-msg {
    background: #d1fae5; color: #065f46;
    padding: 10px 16px; border-radius: var(--radius-sm);
    font-size: 13px; margin-bottom: 14px;
}

/* ── Review Card ── */
.review-card {
    background: white; border-radius: var(--radius);
    padding: 24px 26px; box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200); margin-bottom: 28px;
}
.review-card-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px; padding-bottom: 14px;
    border-bottom: 1px solid var(--gray-100);
}
.review-card-title {
    font-size: 16px; font-weight: 600; color: var(--gray-800);
    display: flex; align-items: center; gap: 8px;
}
.write-review-btn {
    display: inline-flex; align-items: center; gap: 7px;
    background: var(--maroon); color: white;
    border: none; border-radius: 20px;
    padding: 8px 18px; font-size: 13px; font-weight: 500;
    cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: background 0.2s, transform 0.15s;
    text-decoration: none;
}
.write-review-btn:hover { background: var(--maroon-light); transform: translateY(-1px); }

/* Empty review state */
.review-empty {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 40px 20px; text-align: center;
}
.review-empty-icon {
    width: 72px; height: 72px; border-radius: 50%;
    background: var(--maroon-pale);
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 16px;
}
.review-empty p { color: var(--gray-400); font-size: 14px; margin-bottom: 18px; }

/* Review rows */
.review-row {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 14px 0; border-bottom: 1px solid var(--gray-100);
}
.review-row:last-child { border-bottom: none; }
.review-row-icon {
    width: 38px; height: 38px; border-radius: 50%;
    background: var(--maroon-pale);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; margin-top: 2px;
}
.review-row-body { flex: 1; min-width: 0; }
.review-row-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.review-row-faculty { font-size: 14px; font-weight: 600; color: var(--gray-800); }
.review-row-dept { font-size: 11px; color: var(--gray-400); margin-bottom: 4px; }
.review-row-text { font-size: 13px; color: var(--gray-600); line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 480px; }
.review-row-date { font-size: 11px; color: var(--gray-400); margin-top: 4px; }

/* ── Modal ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.5); z-index: 1000;
    align-items: center; justify-content: center;
    backdrop-filter: blur(2px);
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: white; border-radius: var(--radius);
    width: 100%; max-width: 560px; max-height: 88vh;
    overflow-y: auto; box-shadow: var(--shadow-lg);
    animation: slideUp 0.25s ease;
}
@keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 24px; border-bottom: 1px solid var(--gray-100);
    position: sticky; top: 0; background: white; z-index: 2;
}
.modal-header h3 { font-size: 17px; font-weight: 600; color: var(--gray-800); }
.modal-close {
    width: 30px; height: 30px; border-radius: 50%;
    background: var(--gray-100); border: none; cursor: pointer;
    font-size: 18px; display: flex; align-items: center; justify-content: center;
    color: var(--gray-600); transition: background 0.2s;
}
.modal-close:hover { background: var(--gray-200); }
.modal-body { padding: 24px; }

/* Steps */
.step { display: none; }
.step.active { display: block; }
.step-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--gray-400); margin-bottom: 14px; font-weight: 600; }

/* Department accordion */
.dept-group { margin-bottom: 10px; border: 1px solid var(--gray-200); border-radius: var(--radius-sm); overflow: hidden; }
.dept-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 16px; cursor: pointer;
    background: var(--gray-50); font-size: 14px; font-weight: 600;
    color: var(--gray-800); transition: background 0.2s;
    user-select: none;
}
.dept-header:hover { background: var(--gray-100); }
.dept-header .chevron { transition: transform 0.2s; font-size: 12px; color: var(--gray-400); }
.dept-header.open .chevron { transform: rotate(180deg); }
.dept-body { display: none; padding: 8px; background: white; }
.dept-body.open { display: block; }
.faculty-option {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: var(--radius-sm);
    cursor: pointer; transition: background 0.15s;
    font-size: 14px; color: var(--gray-700);
}
.faculty-option:hover { background: var(--maroon-pale); color: var(--maroon); }
.faculty-option img { width: 34px; height: 34px; border-radius: 50%; }
.faculty-option.selected { background: var(--maroon-pale); color: var(--maroon); font-weight: 600; }

/* Selected faculty preview */
.selected-preview {
    display: none; align-items: center; gap: 12px;
    background: var(--maroon-pale); border-radius: var(--radius-sm);
    padding: 12px 16px; margin-bottom: 16px;
}
.selected-preview.show { display: flex; }
.selected-preview img { width: 42px; height: 42px; border-radius: 50%; }
.selected-preview-info strong { font-size: 14px; color: var(--maroon); display: block; }
.selected-preview-info span { font-size: 12px; color: var(--gray-400); }
.change-faculty-btn {
    margin-left: auto; font-size: 12px; color: var(--maroon);
    background: none; border: 1px solid var(--maroon);
    border-radius: 12px; padding: 4px 10px; cursor: pointer;
    font-family: 'DM Sans', sans-serif; transition: all 0.2s;
}
.change-faculty-btn:hover { background: var(--maroon); color: white; }

/* Textarea */
.modal-textarea {
    width: 100%; padding: 12px 14px;
    border: 1px solid var(--gray-200); border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif; font-size: 14px;
    resize: vertical; outline: none; color: var(--gray-800);
    min-height: 120px; transition: border-color 0.2s;
}
.modal-textarea:focus { border-color: var(--maroon); }
.modal-footer {
    display: flex; justify-content: flex-end; gap: 10px;
    padding: 16px 24px; border-top: 1px solid var(--gray-100);
    position: sticky; bottom: 0; background: white;
}
.btn-secondary {
    padding: 9px 20px; border-radius: 20px;
    border: 1px solid var(--gray-200); background: white;
    font-size: 13px; font-weight: 500; cursor: pointer;
    font-family: 'DM Sans', sans-serif; color: var(--gray-600);
    transition: background 0.2s;
}
.btn-secondary:hover { background: var(--gray-100); }
.btn-primary {
    padding: 9px 22px; border-radius: 20px;
    background: var(--maroon); color: white; border: none;
    font-size: 13px; font-weight: 500; cursor: pointer;
    font-family: 'DM Sans', sans-serif; transition: background 0.2s;
}
.btn-primary:hover { background: var(--maroon-light); }
.btn-primary:disabled { background: var(--gray-400); cursor: not-allowed; }

.success-banner {
    background: #d1fae5; color: #065f46; border-radius: var(--radius-sm);
    padding: 12px 16px; font-size: 13px; margin-bottom: 20px;
    display: flex; align-items: center; gap: 8px;
}

/* ── Pagination ── */
.pagination {
    display: flex; align-items: center; justify-content: center;
    gap: 6px; margin: 18px 0 28px;
}
.page-btn {
    min-width: 36px; height: 36px; border-radius: 8px;
    border: 1px solid var(--gray-200); background: white;
    font-size: 13px; font-weight: 500; cursor: pointer;
    font-family: 'DM Sans', sans-serif; color: var(--gray-600);
    transition: all 0.18s; display: flex; align-items: center; justify-content: center;
    padding: 0 10px;
}
.page-btn:hover { border-color: var(--maroon); color: var(--maroon); }
.page-btn.active { background: var(--maroon); color: white; border-color: var(--maroon); }
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.page-info { font-size: 12px; color: var(--gray-400); margin: 0 6px; }

#chat-bubble {
    position: fixed; bottom: 24px; right: 24px;
    width: 54px; height: 54px;
    background: var(--maroon); border-radius: 50%;
    cursor: pointer; z-index: 9999;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 16px rgba(139,0,0,0.35);
    transition: transform 0.2s, box-shadow 0.2s;
}
#chat-bubble:hover { transform: scale(1.08); box-shadow: 0 6px 24px rgba(139,0,0,0.4); }
#chat-window {
    display: none; position: fixed; bottom: 90px; right: 24px;
    width: 330px; height: 430px;
    background: white; border-radius: var(--radius);
    box-shadow: var(--shadow-lg); z-index: 9999;
    flex-direction: column; overflow: hidden;
    border: 1px solid var(--gray-200);
}
#chat-header {
    background: var(--maroon); color: white;
    padding: 14px 16px; font-weight: 500; font-size: 14px;
    display: flex; justify-content: space-between; align-items: center;
}
#chat-header span { cursor: pointer; font-size: 20px; opacity: 0.8; }
#chat-messages {
    flex: 1; overflow-y: auto; padding: 14px;
    display: flex; flex-direction: column; gap: 8px;
}
.chat-msg {
    border-radius: 10px; padding: 9px 12px;
    max-width: 88%; font-size: 13px; line-height: 1.5; word-wrap: break-word;
}
.chat-msg.bot { background: var(--gray-100); align-self: flex-start; color: var(--gray-800); }
.chat-msg.user { background: var(--maroon); color: white; align-self: flex-end; }
#chat-footer {
    padding: 10px; border-top: 1px solid var(--gray-100);
    display: flex; gap: 8px;
}
#chat-input {
    flex: 1; padding: 9px 12px;
    border: 1px solid var(--gray-200); border-radius: 20px;
    font-size: 13px; outline: none; font-family: 'DM Sans', sans-serif;
}
#chat-input:focus { border-color: var(--maroon); }
#chat-send {
    background: var(--maroon); color: white; border: none;
    border-radius: 50%; width: 36px; height: 36px;
    cursor: pointer; font-size: 15px; display: flex;
    align-items: center; justify-content: center;
    transition: background 0.2s;
}
#chat-send:hover { background: var(--maroon-light); }
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">AnonymousReview</div>
    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($user['fullname']); ?>&background=6B0000&color=fff&size=80" class="sidebar-avatar" alt="Avatar">
    <div class="sidebar-name"><?php echo htmlspecialchars($user['fullname']); ?></div>
    <div class="sidebar-role">Student</div>
    <nav>
        <div class="nav-label">Menu</div>
        <a href="dashboard.php" class="active">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Dashboard
        </a>
        <a href="#">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            Evaluation History
        </a>
        <a href="avatar.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            Profile
        </a>
        <a href="#" onclick="toggleChat(); return false;">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            FAQ Chat
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            Logout
        </a>
    </div>
</div>

<!-- Main Content -->
<div class="main">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $user['fullname'])[0]); ?>!</h1>
            <p>Here's what's happening with your faculty evaluations.</p>
        </div>
        <div class="topbar-right">
            <div class="today-date">
                📅 <?php echo date("F j, Y"); ?>
            </div>
            <!-- Notification Bell -->
            <div class="notif-wrap" id="notifWrap">
                <div class="notif-btn">
                    <svg width="18" height="18" fill="none" stroke="#4b5563" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
                </div>
                <?php if ($notif_count > 0): ?>
                    <div class="notif-badge"><?php echo $notif_count; ?></div>
                <?php endif; ?>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-dropdown-header">🔔 Notifications</div>
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $n): ?>
                            <div class="notif-item">
                                <?php echo htmlspecialchars($n['message']); ?>
                                <small><?php echo date("M j, g:i A", strtotime($n['created_at'])); ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notif-empty">No notifications yet</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-label">Total Reviews</div>
            <div class="stat-value"><?php echo $total_reviews; ?></div>
            <div class="stat-icon"><svg width="52" height="52" fill="#4b5563" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        </div>
        <div class="stat-card pending">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?php echo $pending_count; ?></div>
            <div class="stat-icon"><svg width="52" height="52" fill="#f59e0b" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        </div>
        <div class="stat-card approved">
            <div class="stat-label">Approved</div>
            <div class="stat-value"><?php echo $approved_count; ?></div>
            <div class="stat-icon"><svg width="52" height="52" fill="#10b981" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
        </div>
        <div class="stat-card rejected">
            <div class="stat-label">Rejected</div>
            <div class="stat-value"><?php echo $rejected_count; ?></div>
            <div class="stat-icon"><svg width="52" height="52" fill="#ef4444" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
        </div>
    </div>

    <!-- Faculty Section -->
    <div class="section-header">
        <div class="section-title">Faculty Members</div>
        <form class="search-form" method="GET" action="dashboard.php">
            <svg width="15" height="15" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="searchInput" name="search" placeholder="Search faculty..."
                value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            <span class="search-clear" id="clearSearch">×</span>
            <button type="submit">Search</button>
        </form>
    </div>

    <!-- Faculty Grid -->
    <?php if (!empty($faculties)): ?>
    <div class="faculty-grid" id="facultyGrid">
        <?php foreach ($faculties as $i => $faculty): ?>
        <div class="faculty-card" data-index="<?php echo $i; ?>">
            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($faculty['name']); ?>&background=8B0000&color=fff&size=80" alt="Faculty">
            <h3><?php echo htmlspecialchars($faculty['name']); ?></h3>
            <p><?php echo htmlspecialchars($faculty['department'] ?? ''); ?></p>
            <a href="dashboard.php?faculty_id=<?php echo $faculty['id']; ?>">Evaluate</a>
        </div>
        <?php endforeach; ?>
    </div>
    <!-- Pagination -->
    <div class="pagination" id="pagination"></div>
    <?php else: ?>
    <div class="empty-state">
        <svg width="64" height="64" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        <p>No faculty found<?php echo isset($_GET['search']) ? ' for your search.' : '.'; ?></p>
    </div>
    <?php endif; ?>

    <!-- Recent Reviews Card -->
    <div class="review-card">
        <div class="review-card-header">
            <div class="review-card-title">
                <svg width="18" height="18" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                Recent Reviews
            </div>
            <button class="write-review-btn" onclick="openReviewModal()">
                <svg width="14" height="14" fill="none" stroke="white" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Write a Review
            </button>
        </div>

        <?php if (isset($_GET['submitted'])): ?>
            <div class="success-banner">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Your review has been submitted and is pending admin approval.
            </div>
        <?php endif; ?>

        <?php if (empty($recent_reviews)): ?>
        <div class="review-empty">
            <div class="review-empty-icon">
                <svg width="32" height="32" fill="none" stroke="var(--maroon)" stroke-width="1.5" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </div>
            <p>You haven't submitted any reviews yet.<br>Share your feedback on a faculty member!</p>
            <button class="write-review-btn" onclick="openReviewModal()">
                <svg width="14" height="14" fill="none" stroke="white" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Write Your First Review
            </button>
        </div>
        <?php else: ?>
        <?php foreach ($recent_reviews as $rev): ?>
        <div class="review-row">
            <div class="review-row-icon">
                <svg width="18" height="18" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </div>
            <div class="review-row-body">
                <div class="review-row-top">
                    <div class="review-row-faculty"><?php echo htmlspecialchars($rev['faculty_name']); ?></div>
                    <span class="status-badge status-<?php echo $rev['status']; ?>"><?php echo ucfirst($rev['status']); ?></span>
                </div>
                <div class="review-row-dept"><?php echo htmlspecialchars($rev['department'] ?? ''); ?></div>
                <div class="review-row-text"><?php echo htmlspecialchars($rev['review_text']); ?></div>
                <div class="review-row-date"><?php echo date("F j, Y · g:i A", strtotime($rev['created_at'])); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- Write Review Modal -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>
                <span id="modalStepTitle">Select a Faculty Member</span>
            </h3>
            <button class="modal-close" onclick="closeReviewModal()">&times;</button>
        </div>
        <form method="POST" id="reviewForm">
            <div class="modal-body">

                <!-- Step 1: Choose Faculty -->
                <div class="step active" id="step1">
                    <div class="step-label">Step 1 of 2 · Choose Faculty</div>
                    <?php foreach ($departments as $dept => $dept_faculties): ?>
                    <div class="dept-group">
                        <div class="dept-header" onclick="toggleDept(this)">
                            <span><?php echo htmlspecialchars($dept); ?> <span style="font-weight:400;color:var(--gray-400);font-size:12px;">(<?php echo count($dept_faculties); ?>)</span></span>
                            <span class="chevron">▼</span>
                        </div>
                        <div class="dept-body">
                            <?php foreach ($dept_faculties as $f): ?>
                            <div class="faculty-option" onclick="selectFaculty(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($f['name'])); ?>', '<?php echo htmlspecialchars(addslashes($dept)); ?>')">
                                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($f['name']); ?>&background=8B0000&color=fff&size=40" alt="">
                                <?php echo htmlspecialchars($f['name']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Step 2: Write Review -->
                <div class="step" id="step2">
                    <div class="step-label">Step 2 of 2 · Write Your Review</div>
                    <div class="selected-preview show" id="selectedPreview">
                        <img id="previewImg" src="" alt="">
                        <div class="selected-preview-info">
                            <strong id="previewName"></strong>
                            <span id="previewDept"></span>
                        </div>
                        <button type="button" class="change-faculty-btn" onclick="goStep(1)">Change</button>
                    </div>
                    <textarea class="modal-textarea" name="review_text" id="reviewText" placeholder="Write your anonymous review here... Be honest, constructive, and respectful." required></textarea>
                    <input type="hidden" name="faculty_id" id="facultyIdInput">
                    <p style="font-size:12px;color:var(--gray-400);margin-top:8px;">🔒 Your identity remains anonymous. Reviews are reviewed by admin before publishing.</p>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="backBtn" style="display:none;" onclick="goStep(1)">← Back</button>
                <button type="button" class="btn-secondary" onclick="closeReviewModal()">Cancel</button>
                <button type="button" class="btn-primary" id="nextBtn" disabled onclick="goStep(2)">Next →</button>
                <button type="submit" name="submit_review" class="btn-primary" id="submitBtn" style="display:none;">Submit Review</button>
            </div>
        </form>
    </div>
</div>


<!-- Chatbot Bubble -->
<div id="chat-bubble" onclick="toggleChat()">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="white"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
</div>

<!-- Chatbot Window -->
<div id="chat-window">
    <div id="chat-header">
        <span>FAQ Assistant</span>
        <span onclick="toggleChat()">&times;</span>
    </div>
    <div id="chat-messages">
        <div class="chat-msg bot">Hi! Ask me anything about using AnonymousReview. 👋</div>
    </div>
    <div id="chat-footer">
        <input id="chat-input" type="text" placeholder="Type a question..." onkeydown="if(event.key==='Enter') sendChat()">
        <button id="chat-send" onclick="sendChat()">&#9658;</button>
    </div>
</div>

<script>
// Faculty Pagination
const CARDS_PER_PAGE = 6;
const cards = document.querySelectorAll('.faculty-card');
const pagination = document.getElementById('pagination');
let currentPage = 1;
const totalPages = Math.ceil(cards.length / CARDS_PER_PAGE);

function showPage(page) {
    currentPage = page;
    const start = (page - 1) * CARDS_PER_PAGE;
    const end = start + CARDS_PER_PAGE;
    cards.forEach((card, i) => {
        card.style.display = (i >= start && i < end) ? '' : 'none';
    });
    renderPagination();
}

function renderPagination() {
    if (totalPages <= 1) return;
    pagination.innerHTML = '';

    // Prev button
    const prev = document.createElement('button');
    prev.className = 'page-btn';
    prev.innerHTML = '← Prev';
    prev.disabled = currentPage === 1;
    prev.onclick = () => showPage(currentPage - 1);
    pagination.appendChild(prev);

    // Page info
    const info = document.createElement('span');
    info.className = 'page-info';
    info.textContent = `Page ${currentPage} of ${totalPages}`;
    pagination.appendChild(info);

    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.className = 'page-btn' + (i === currentPage ? ' active' : '');
        btn.textContent = i;
        btn.onclick = () => showPage(i);
        pagination.appendChild(btn);
    }

    // Next button
    const next = document.createElement('button');
    next.className = 'page-btn';
    next.innerHTML = 'Next →';
    next.disabled = currentPage === totalPages;
    next.onclick = () => showPage(currentPage + 1);
    pagination.appendChild(next);
}

if (cards.length > 0) showPage(1);

// Modal
let selectedFacultyId = null;

function openReviewModal() {
    document.getElementById('reviewModal').classList.add('open');
    goStep(1);
}
function closeReviewModal() {
    document.getElementById('reviewModal').classList.remove('open');
    selectedFacultyId = null;
    document.getElementById('reviewText').value = '';
    document.querySelectorAll('.faculty-option').forEach(o => o.classList.remove('selected'));
    document.getElementById('nextBtn').disabled = true;
}
function goStep(n) {
    document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
    document.getElementById('step' + n).classList.add('active');
    document.getElementById('modalStepTitle').textContent = n === 1 ? 'Select a Faculty Member' : 'Write Your Review';
    document.getElementById('backBtn').style.display = n === 2 ? 'inline-flex' : 'none';
    document.getElementById('nextBtn').style.display = n === 1 ? 'inline-flex' : 'none';
    document.getElementById('submitBtn').style.display = n === 2 ? 'inline-flex' : 'none';
}
function selectFaculty(id, name, dept) {
    selectedFacultyId = id;
    document.getElementById('facultyIdInput').value = id;
    document.getElementById('previewName').textContent = name;
    document.getElementById('previewDept').textContent = dept;
    document.getElementById('previewImg').src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=8B0000&color=fff&size=42';
    document.querySelectorAll('.faculty-option').forEach(o => o.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
    document.getElementById('nextBtn').disabled = false;
}
function toggleDept(header) {
    header.classList.toggle('open');
    const body = header.nextElementSibling;
    body.classList.toggle('open');
}
// Close modal on overlay click
document.getElementById('reviewModal').addEventListener('click', function(e) {
    if (e.target === this) closeReviewModal();
});
// Open first dept by default
document.addEventListener('DOMContentLoaded', () => {
    const first = document.querySelector('.dept-header');
    if (first) { first.classList.add('open'); first.nextElementSibling.classList.add('open'); }
});

const searchInput = document.getElementById('searchInput');
const clearSearch = document.getElementById('clearSearch');
function toggleClear() { clearSearch.style.display = searchInput.value.length > 0 ? 'inline' : 'none'; }
toggleClear();
searchInput.addEventListener('input', toggleClear);
clearSearch.addEventListener('click', () => { searchInput.value = ''; toggleClear(); window.location.href = 'dashboard.php'; });

// Notification dropdown
const notifWrap = document.getElementById('notifWrap');
const notifDropdown = document.getElementById('notifDropdown');
notifWrap.addEventListener('click', () => {
    notifDropdown.style.display = notifDropdown.style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', (e) => { if (!notifWrap.contains(e.target)) notifDropdown.style.display = 'none'; });

// Chatbot
function toggleChat() {
    var w = document.getElementById('chat-window');
    w.style.display = w.style.display === 'flex' ? 'none' : 'flex';
    if (w.style.display === 'flex') {
        w.style.flexDirection = 'column';
        document.getElementById('chat-input').focus();
    }
}
function sendChat() {
    var input = document.getElementById('chat-input');
    var msg = input.value.trim();
    if (!msg) return;
    addBubble(msg, 'user');
    input.value = '';
    var typing = addBubble('Typing...', 'bot', 'typing-indicator');
    fetch('chatbot.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'message=' + encodeURIComponent(msg)
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('typing-indicator').remove();
        addBubble(data.reply || 'Sorry, try again.', 'bot');
    })
    .catch(() => {
        document.getElementById('typing-indicator').remove();
        addBubble('Connection error. Please try again.', 'bot');
    });
}
function addBubble(text, from, id) {
    var box = document.getElementById('chat-messages');
    var d = document.createElement('div');
    d.className = 'chat-msg ' + from;
    if (id) d.id = id;
    d.textContent = text;
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
    return d;
}
</script>
</body>
</html>