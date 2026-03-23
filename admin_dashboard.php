<?php
include "config.php";
include "email_helper.php";

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$result  = mysqli_query($conn, "SELECT id, fullname, role, profile_pic FROM users WHERE id='$user_id' LIMIT 1");
if ($result && mysqli_num_rows($result) > 0) {
    $current_user = mysqli_fetch_assoc($result);
} else { header("Location: logout.php"); exit(); }

if ($current_user['role'] != 'admin') { header("Location: dashboard.php"); exit(); }

$admin_avatar = !empty($current_user['profile_pic']) && file_exists($current_user['profile_pic'])
    ? $current_user['profile_pic']
    : 'https://ui-avatars.com/api/?name=' . urlencode($current_user['fullname']) . '&background=6B0000&color=fff&size=80';

// Bulk Approve
if (isset($_POST['bulk_approve']) && !empty($_POST['selected_reviews'])) {
    foreach ($_POST['selected_reviews'] as $rid) {
        $rid = intval($rid);
        mysqli_query($conn, "UPDATE reviews SET status='approved' WHERE id='$rid'");
        $res = mysqli_query($conn, "SELECT r.user_id, r.faculty_id, u.username, u.email, u.fullname FROM reviews r JOIN users u ON r.user_id=u.id WHERE r.id='$rid' LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            $rev   = mysqli_fetch_assoc($res);
            $fres  = mysqli_query($conn, "SELECT name FROM faculties WHERE id='{$rev['faculty_id']}' LIMIT 1");
            $fname = ($fres && mysqli_num_rows($fres) > 0) ? mysqli_fetch_assoc($fres)['name'] : 'Unknown Faculty';
            $msg   = mysqli_real_escape_string($conn, "Your review for $fname has been approved and is now published.");
            mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) VALUES ('{$rev['user_id']}','$msg','unread',NOW())");
            sendBrevoEmail($rev['email'], $rev['fullname'], 'Your review has been approved — AnonymousReview', approvedEmailHtml($rev['username'], $fname));
        }
    }
    header("Location: admin_dashboard.php#pending"); exit();
}

// Bulk Reject
if (isset($_POST['bulk_reject']) && !empty($_POST['selected_reviews'])) {
    foreach ($_POST['selected_reviews'] as $rid) {
        $rid = intval($rid);
        mysqli_query($conn, "UPDATE reviews SET status='rejected' WHERE id='$rid'");
        $res = mysqli_query($conn, "SELECT r.user_id, r.faculty_id, u.username, u.email, u.fullname FROM reviews r JOIN users u ON r.user_id=u.id WHERE r.id='$rid' LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            $rev   = mysqli_fetch_assoc($res);
            $fres  = mysqli_query($conn, "SELECT name FROM faculties WHERE id='{$rev['faculty_id']}' LIMIT 1");
            $fname = ($fres && mysqli_num_rows($fres) > 0) ? mysqli_fetch_assoc($fres)['name'] : 'Unknown Faculty';
            $msg   = mysqli_real_escape_string($conn, "Your review for $fname has been rejected by the admin.");
            mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) VALUES ('{$rev['user_id']}','$msg','unread',NOW())");
            sendBrevoEmail($rev['email'], $rev['fullname'], 'Your review has been rejected — AnonymousReview', rejectedEmailHtml($rev['username'], $fname));
        }
    }
    header("Location: admin_dashboard.php#pending"); exit();
}

// Single Delete User
if (isset($_POST['delete_single_user'])) {
    $uid = intval($_POST['delete_user_id']);
    if ($uid != $user_id) mysqli_query($conn, "DELETE FROM users WHERE id='$uid' AND role='user'");
    header("Location: admin_dashboard.php#users"); exit();
}

// Bulk Delete Users
if (isset($_POST['bulk_delete_users']) && !empty($_POST['selected_users'])) {
    foreach ($_POST['selected_users'] as $uid) {
        $uid = intval($uid);
        if ($uid != $user_id) mysqli_query($conn, "DELETE FROM users WHERE id='$uid' AND role='user'");
    }
    header("Location: admin_dashboard.php#users"); exit();
}

// Bulk Delete Approved Reviews
if (isset($_POST['bulk_delete_approved']) && !empty($_POST['selected_approved'])) {
    foreach ($_POST['selected_approved'] as $rid) {
        $rid = intval($rid);
        mysqli_query($conn, "DELETE FROM reviews WHERE id='$rid' AND status='approved'");
    }
    header("Location: admin_dashboard.php#approved"); exit();
}

$users           = mysqli_query($conn, "SELECT id, fullname, username, email, profile_pic FROM users WHERE role='user' ORDER BY id DESC");
$faculties       = mysqli_query($conn, "SELECT * FROM faculties ORDER BY department ASC, name ASC");
$total_users     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='user'"))['c'];
$total_admins    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='admin'"))['c'];
$total_faculties = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM faculties"))['c'];
$total_reviews   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews"))['c'];
$pending_count   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE status='pending'"))['c'];
$approved_count  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE status='approved'"))['c'];
$rejected_count  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE status='rejected'"))['c'];

$pending_reviews = mysqli_query($conn, "
    SELECT r.id, u.username AS user_name, f.name AS faculty_name, r.review_text, r.sentiment, r.is_toxic, r.summary, r.created_at
    FROM reviews r JOIN users u ON r.user_id=u.id JOIN faculties f ON r.faculty_id=f.id
    WHERE r.status='pending' ORDER BY r.created_at DESC");

$approved_reviews = mysqli_query($conn, "
    SELECT r.id, f.name AS faculty_name, u.username AS user_name, r.review_text, r.created_at
    FROM reviews r JOIN faculties f ON r.faculty_id=f.id JOIN users u ON r.user_id=u.id
    WHERE r.status='approved' ORDER BY r.created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - AnonymousReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root {
    --maroon:#8B0000;--maroon-dark:#6B0000;--maroon-light:#a30000;--maroon-pale:#fff5f5;
    --sidebar-w:240px;--gray-100:#f3f4f6;--gray-200:#e5e7eb;--gray-400:#9ca3af;
    --gray-600:#4b5563;--gray-800:#1f2937;
    --shadow-sm:0 1px 3px rgba(0,0,0,0.08);--shadow-md:0 4px 16px rgba(0,0,0,0.10);
    --shadow-lg:0 8px 32px rgba(0,0,0,0.13);--radius:14px;--radius-sm:8px;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--gray-100);color:var(--gray-800);min-height:100vh;}
.sidebar{position:fixed;left:0;top:0;width:var(--sidebar-w);height:100%;background:var(--maroon);display:flex;flex-direction:column;padding:28px 16px 20px;box-shadow:2px 0 12px rgba(139,0,0,0.18);z-index:100;overflow-y:auto;}
.sidebar-brand{font-family:'Playfair Display',serif;font-size:17px;color:white;text-align:center;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,0.15);line-height:1.4;}
.sidebar-avatar{width:70px;height:70px;border-radius:50%;border:3px solid rgba(255,255,255,0.4);display:block;margin:0 auto 10px;object-fit:cover;}
.sidebar-name{text-align:center;color:white;font-size:14px;font-weight:600;margin-bottom:4px;}
.sidebar-role{text-align:center;color:rgba(255,255,255,0.6);font-size:11px;margin-bottom:24px;text-transform:uppercase;letter-spacing:1px;}
.sidebar nav{flex:1;}
.nav-label{font-size:10px;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,0.4);padding:0 10px;margin-bottom:6px;margin-top:16px;}
.sidebar a{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--radius-sm);color:rgba(255,255,255,0.85);text-decoration:none;font-size:14px;font-weight:500;transition:all 0.2s;margin-bottom:2px;}
.sidebar a:hover,.sidebar a.active{background:rgba(255,255,255,0.15);color:white;}
.sidebar-footer{border-top:1px solid rgba(255,255,255,0.15);padding-top:14px;margin-top:auto;}
.main{margin-left:var(--sidebar-w);padding:32px;min-height:100vh;}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;}
.topbar-left h1{font-family:'Playfair Display',serif;font-size:26px;color:var(--gray-800);}
.topbar-left p{color:var(--gray-400);font-size:14px;margin-top:3px;}
.today-date{font-size:13px;color:var(--gray-400);background:white;padding:6px 14px;border-radius:20px;border:1px solid var(--gray-200);}
.stats-grid{display:grid;gap:16px;margin-bottom:20px;}
.stats-row-4{grid-template-columns:repeat(4,1fr);}
.stats-row-3{grid-template-columns:repeat(3,1fr);margin-bottom:28px;}
.stat-card{background:white;border-radius:var(--radius);padding:20px 22px;box-shadow:var(--shadow-sm);border:1px solid var(--gray-200);border-top:3px solid transparent;position:relative;overflow:hidden;transition:box-shadow 0.2s,transform 0.2s;}
.stat-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.stat-card.s-users{border-top-color:#6366f1;} .stat-card.s-faculty{border-top-color:#f59e0b;}
.stat-card.s-pending{border-top-color:#f97316;} .stat-card.s-approved{border-top-color:#10b981;}
.stat-card.s-rejected{border-top-color:#ef4444;} .stat-card.s-total{border-top-color:var(--gray-400);}
.stat-card.s-admins{border-top-color:var(--maroon);}
.stat-label{font-size:12px;color:var(--gray-400);font-weight:500;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;}
.stat-value{font-size:32px;font-weight:700;line-height:1;color:var(--gray-800);}
.stat-icon{position:absolute;right:16px;top:50%;transform:translateY(-50%);}
.section{background:white;border-radius:var(--radius);box-shadow:var(--shadow-sm);border:1px solid var(--gray-200);margin-bottom:28px;overflow:hidden;}
.section-header{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid var(--gray-100);}
.section-title{font-size:16px;font-weight:600;color:var(--gray-800);display:flex;align-items:center;gap:8px;}
.section-badge{background:var(--maroon-pale);color:var(--maroon);font-size:12px;font-weight:600;padding:2px 8px;border-radius:20px;}
.admin-table{width:100%;border-collapse:collapse;}
.admin-table th{background:var(--gray-100);color:var(--gray-600);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;padding:10px 16px;text-align:left;border-bottom:1px solid var(--gray-200);}
.admin-table td{padding:12px 16px;border-bottom:1px solid var(--gray-100);font-size:14px;color:var(--gray-700);vertical-align:middle;}
.admin-table tr:last-child td{border-bottom:none;}
.admin-table tbody tr:hover td{background:#fafafa;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-positive{background:#d1fae5;color:#065f46;} .badge-negative{background:#fee2e2;color:#991b1b;}
.badge-neutral{background:#f3f4f6;color:#4b5563;} .badge-toxic{background:#fff7ed;color:#c2410c;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:500;cursor:pointer;border:none;font-family:'DM Sans',sans-serif;transition:all 0.18s;text-decoration:none;}
.btn-maroon{background:var(--maroon);color:white;} .btn-maroon:hover{background:var(--maroon-light);}
.btn-green{background:#10b981;color:white;} .btn-green:hover{background:#059669;}
.btn-red{background:#ef4444;color:white;} .btn-red:hover{background:#dc2626;}
.btn-gray{background:#6b7280;color:white;} .btn-gray:hover{background:#4b5563;}
.btn-outline{background:white;color:var(--gray-600);border:1px solid var(--gray-200);} .btn-outline:hover{border-color:var(--maroon);color:var(--maroon);}
input[type=checkbox]{width:15px;height:15px;accent-color:var(--maroon);cursor:pointer;}
.bulk-bar{padding:12px 24px;background:var(--maroon-pale);border-top:1px solid rgba(139,0,0,0.1);display:none;align-items:center;gap:10px;}
.bulk-bar.show{display:flex;}
.bulk-bar span{font-size:13px;color:var(--maroon);font-weight:500;flex:1;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
.modal-overlay.open{display:flex;}
.modal-box{background:white;border-radius:var(--radius);width:100%;max-width:560px;max-height:85vh;overflow-y:auto;box-shadow:var(--shadow-lg);animation:slideUp 0.25s ease;}
@keyframes slideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{display:flex;justify-content:space-between;align-items:center;padding:18px 24px;border-bottom:1px solid var(--gray-100);position:sticky;top:0;background:white;z-index:2;}
.modal-head h3{font-size:15px;font-weight:600;color:var(--gray-800);}
.modal-close{width:28px;height:28px;border-radius:50%;background:var(--gray-100);border:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;color:var(--gray-600);}
.modal-close:hover{background:var(--gray-200);}
.modal-body{padding:24px;font-size:14px;line-height:1.7;color:var(--gray-700);white-space:pre-wrap;}
.section-toolbar{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:12px 20px;border-bottom:1px solid var(--gray-100);flex-wrap:wrap;}
.search-box{display:flex;align-items:center;gap:6px;background:var(--gray-100);border:1px solid var(--gray-200);border-radius:8px;padding:6px 12px;min-width:220px;max-width:280px;}
.search-box input{border:none;background:transparent;outline:none;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--gray-800);width:100%;}
.search-box input::placeholder{color:var(--gray-400);}
.filter-sel{padding:6px 10px;border:1px solid var(--gray-200);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--gray-600);background:white;outline:none;cursor:pointer;}
.filter-sel:focus{border-color:var(--maroon);}
.no-results-row td{text-align:center;padding:28px;color:var(--gray-400);font-size:13px;}

.user-avatar{width:32px;height:32px;border-radius:50%;object-fit:cover;}
.user-info{display:flex;align-items:center;gap:10px;}
.user-info-text strong{display:block;font-size:13px;font-weight:600;color:var(--gray-800);}
.user-info-text span{font-size:12px;color:var(--gray-400);}
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">AnonymousReview<br><span style="font-size:11px;font-family:'DM Sans',sans-serif;font-weight:400;opacity:0.6;">Admin Panel</span></div>
    <img src="<?php echo htmlspecialchars($admin_avatar); ?>" class="sidebar-avatar" alt="Admin">
    <div class="sidebar-name"><?php echo htmlspecialchars($current_user['fullname']); ?></div>
    <div class="sidebar-role">Administrator</div>
    <nav>
        <div class="nav-label">Manage</div>
        <a href="#overview">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Overview
        </a>
        <a href="#users">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            Users
        </a>
        <a href="#faculties">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            Faculties
        </a>
        <a href="#pending">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Pending Reviews
            <?php if ($pending_count > 0): ?>
            <span style="background:white;color:var(--maroon);font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:auto;"><?php echo $pending_count; ?></span>
            <?php endif; ?>
        </a>
        <a href="#approved">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            Approved Reviews
        </a>
        <a href="#reports">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Reports
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            Logout
        </a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h1>Admin Dashboard</h1>
            <p>Manage users, faculties, and reviews across the system.</p>
        </div>
        <div class="today-date">📅 <?php echo date("F j, Y"); ?></div>
    </div>

    <!-- Stats Row 1 -->
    <div id="overview" class="stats-grid stats-row-4">
        <div class="stat-card s-users">
            <div class="stat-label">Total Users</div>
            <div class="stat-value"><?php echo $total_users; ?></div>
            <div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(99,102,241,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#6366f1" stroke-width="1.8" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div></div>
        </div>
        <div class="stat-card s-faculty">
            <div class="stat-label">Total Faculties</div>
            <div class="stat-value"><?php echo $total_faculties; ?></div>
            <div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(245,158,11,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#f59e0b" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg></div></div>
        </div>
        <div class="stat-card s-pending">
            <div class="stat-label">Pending Reviews</div>
            <div class="stat-value"><?php echo $pending_count; ?></div>
            <div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(249,115,22,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#f97316" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div></div>
        </div>
        <div class="stat-card s-approved">
            <div class="stat-label">Approved</div>
            <div class="stat-value"><?php echo $approved_count; ?></div>
            <div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(16,185,129,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#10b981" stroke-width="1.8" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div></div>
        </div>
    </div>

    <!-- Stats Row 2 -->
    <div class="stats-grid stats-row-3">
        <div class="stat-card s-rejected">
            <div class="stat-label">Rejected</div>
            <div class="stat-value"><?php echo $rejected_count; ?></div>
            <div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(239,68,68,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#ef4444" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div></div>
        </div>
        <div class="stat-card s-total">
            <div class="stat-label">Total Reviews</div>
            <div class="stat-value"><?php echo $total_reviews; ?></div>
            <div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(107,114,128,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#6b7280" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div></div>
        </div>
        <div class="stat-card s-admins">
            <div class="stat-label">Total Admins</div>
            <div class="stat-value"><?php echo $total_admins; ?></div>
            <div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(139,0,0,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#8B0000" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div></div>
        </div>
    </div>

    <!-- Users -->
    <div class="section" id="users">
        <div class="section-header">
            <div class="section-title">
                <svg width="17" height="17" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                User List <span class="section-badge"><?php echo $total_users; ?></span>
            </div>
            <button id="edit_users_btn" class="btn btn-outline">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit
            </button>
        </div>
        <div class="section-toolbar">
            <div class="search-box">
                <svg width="14" height="14" fill="none" stroke="var(--gray-400)" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="users-search" placeholder="Search by name, username or email..." oninput="filterTable('users-tbody','users-search',null,null)">
            </div>
        </div>
        <form method="POST">
        <table class="admin-table">
            <thead><tr>
                <th style="display:none;width:40px;" id="select_all_th"><input type="checkbox" id="select_all_users"></th>
                <th>User</th><th>Username</th><th>Email</th><th style="width:150px;">Action</th>
            </tr></thead>
            <tbody id="users-tbody">
            <?php while($u = mysqli_fetch_assoc($users)): ?>
            <tr>
                <td style="display:none;" class="checkbox_td"><input type="checkbox" name="selected_users[]" value="<?php echo $u['id']; ?>" class="user_checkbox"></td>
                <td><div class="user-info"><img class="user-avatar" src="<?php echo (!empty($u['profile_pic']) && file_exists($u['profile_pic'])) ? htmlspecialchars($u['profile_pic']) : 'https://ui-avatars.com/api/?name='.urlencode($u['fullname']).'&background=8B0000&color=fff&size=32'; ?>" alt=""><div class="user-info-text"><strong><?php echo htmlspecialchars($u['fullname']); ?></strong><span>#<?php echo $u['id']; ?></span></div></div></td>
                <td>@<?php echo htmlspecialchars($u['username']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td><div style="display:flex;gap:6px;">
                    <button type="button" class="btn btn-outline" onclick="openUserModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['fullname'])); ?>','<?php echo htmlspecialchars(addslashes($u['username'])); ?>','<?php echo htmlspecialchars(addslashes($u['email'])); ?>','<?php echo (!empty($u['profile_pic']) && file_exists($u['profile_pic'])) ? htmlspecialchars(addslashes($u['profile_pic'])) : 'https://ui-avatars.com/api/?name='.urlencode($u['fullname']).'&background=8B0000&color=fff&size=80'; ?>')">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        View
                    </button>
                    <button type="button" class="btn btn-red" onclick="confirmDeleteUser(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['fullname'])); ?>')">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                        Delete
                    </button>
                </div></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <div class="bulk-bar" id="bulk_actions">
            <span id="selected_count">0 users selected</span>
            <button type="submit" name="bulk_delete_users" class="btn btn-red" onclick="return confirm('Delete selected users?')">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                Delete Selected
            </button>
        </div>
        </form>
    </div>

    <!-- Faculties -->
    <div class="section" id="faculties">
        <div class="section-header">
            <div class="section-title">
                <svg width="17" height="17" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                Faculty List <span class="section-badge"><?php echo $total_faculties; ?></span>
            </div>
            <a href="add_faculty.php" class="btn btn-outline">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Faculty
            </a>
        </div>
        <div class="section-toolbar">
            <div class="search-box">
                <svg width="14" height="14" fill="none" stroke="var(--gray-400)" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="faculties-search" placeholder="Search by name..." oninput="filterTable('faculties-tbody','faculties-search','faculties-dept-filter',null)">
            </div>
            <select id="faculties-dept-filter" class="filter-sel" onchange="filterTable('faculties-tbody','faculties-search','faculties-dept-filter',null)">
                <option value="">All Departments</option>
                <?php
                $depts_res = mysqli_query($conn, "SELECT DISTINCT department FROM faculties WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
                while ($d = mysqli_fetch_assoc($depts_res)):
                ?>
                <option value="<?php echo htmlspecialchars($d['department']); ?>"><?php echo htmlspecialchars($d['department']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <table class="admin-table">
            <thead><tr><th>Name</th><th>Department</th><th style="width:150px;">Action</th></tr></thead>
            <tbody id="faculties-tbody">
            <?php while($f = mysqli_fetch_assoc($faculties)): ?>
            <tr>
                <td><div class="user-info"><img class="user-avatar" src="https://ui-avatars.com/api/?name=<?php echo urlencode($f['name']); ?>&background=8B0000&color=fff&size=32" alt=""><div class="user-info-text"><strong><?php echo htmlspecialchars($f['name']); ?></strong></div></div></td>
                <td><span style="font-size:12px;background:var(--gray-100);padding:3px 10px;border-radius:20px;color:var(--gray-600);"><?php echo htmlspecialchars($f['department'] ?? '—'); ?></span></td>
                <td><div style="display:flex;gap:6px;">
                    <a href="edit_faculty.php?id=<?php echo $f['id']; ?>" class="btn btn-outline"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</a>
                    <a href="delete_faculty.php?id=<?php echo $f['id']; ?>" class="btn btn-red" onclick="return confirm('Delete this faculty?')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>Delete</a>
                </div></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Pending Reviews -->
    <div class="section" id="pending">
        <div class="section-header">
            <div class="section-title">
                <svg width="17" height="17" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Pending Reviews <?php if($pending_count>0): ?><span class="section-badge"><?php echo $pending_count; ?></span><?php endif; ?>
            </div>
        </div>
        <div class="section-toolbar">
            <div class="search-box">
                <svg width="14" height="14" fill="none" stroke="var(--gray-400)" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="pending-search" placeholder="Search by user or faculty..." oninput="filterTable('pending-tbody','pending-search','pending-sentiment-filter',null)">
            </div>
            <select id="pending-sentiment-filter" class="filter-sel" onchange="filterTable('pending-tbody','pending-search','pending-sentiment-filter',null)">
                <option value="">All Sentiments</option>
                <option value="positive">Positive</option>
                <option value="neutral">Neutral</option>
                <option value="negative">Negative</option>
            </select>
        </div>
        <form method="POST">
        <table class="admin-table">
            <thead><tr>
                <th style="width:40px;"><input type="checkbox" id="select_all_reviews" onclick="document.querySelectorAll('#pending-tbody tr:not([style*=\'none\']) .review_cb').forEach(c=>c.checked=this.checked);updateReviewBulk();"></th>
                <th>User</th><th>Faculty</th><th>AI Analysis</th><th style="width:200px;">Action</th>
            </tr></thead>
            <tbody id="pending-tbody">
            <?php $has_pending=false; while($r=mysqli_fetch_assoc($pending_reviews)): $has_pending=true; ?>
            <tr>
                <td><input type="checkbox" name="selected_reviews[]" value="<?php echo $r['id']; ?>" class="review_cb" onchange="updateReviewBulk()"></td>
                <td><span style="font-weight:500;color:var(--gray-800);">@<?php echo htmlspecialchars($r['user_name']); ?></span></td>
                <td><span style="font-weight:500;"><?php echo htmlspecialchars($r['faculty_name']); ?></span></td>
                <td>
                    <?php $s=$r['sentiment']??'neutral';$cls=$s==='positive'?'badge-positive':($s==='negative'?'badge-negative':'badge-neutral'); ?>
                    <span class="badge <?php echo $cls; ?>"><?php echo ucfirst($s); ?></span>
                    <?php if($r['is_toxic']) echo "<span class='badge badge-toxic' style='margin-left:4px;'>Toxic</span>"; ?>
                    <?php if(!empty($r['summary'])): ?><br><small style="color:var(--gray-400);font-style:italic;font-size:11px;margin-top:3px;display:block;"><?php echo htmlspecialchars($r['summary']); ?></small><?php endif; ?>
                </td>
                <td><div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-outline" onclick="openModal('<?php echo htmlspecialchars(addslashes($r['review_text'])); ?>','<?php echo htmlspecialchars(addslashes($r['faculty_name'])); ?>','<?php echo htmlspecialchars(addslashes($r['user_name'])); ?>')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</button>
                    <a href="approve_review.php?id=<?php echo $r['id']; ?>" class="btn btn-green"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Approve</a>
                    <a href="reject_review.php?id=<?php echo $r['id']; ?>" class="btn btn-red"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Reject</a>
                </div></td>
            </tr>
            <?php endwhile; ?>
            <?php if(!$has_pending): ?><tr class="empty-row"><td colspan="5"><svg width="36" height="36" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom:8px;display:block;margin-left:auto;margin-right:auto;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>No pending reviews</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div class="bulk-bar" id="review_bulk_bar">
            <span id="review_selected_count">0 reviews selected</span>
            <button type="submit" name="bulk_approve" class="btn btn-green" onclick="return confirm('Approve selected?')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Bulk Approve</button>
            <button type="submit" name="bulk_reject" class="btn btn-gray" onclick="return confirm('Reject selected?')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Bulk Reject</button>
        </div>
        </form>
    </div>

    <!-- Approved Reviews -->
    <div class="section" id="approved">
        <div class="section-header">
            <div class="section-title">
                <svg width="17" height="17" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                Approved Reviews <span class="section-badge"><?php echo $approved_count; ?></span>
            </div>
        </div>
        <div class="section-toolbar">
            <div class="search-box">
                <svg width="14" height="14" fill="none" stroke="var(--gray-400)" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="approved-search" placeholder="Search by faculty or reviewer..." oninput="filterTable('approved-tbody','approved-search','approved-faculty-filter',null)">
            </div>
            <select id="approved-faculty-filter" class="filter-sel" onchange="filterTable('approved-tbody','approved-search','approved-faculty-filter',null)">
                <option value="">All Faculties</option>
                <?php
                $fac_res = mysqli_query($conn, "SELECT DISTINCT f.name FROM reviews r JOIN faculties f ON r.faculty_id=f.id WHERE r.status='approved' ORDER BY f.name ASC");
                while ($frow = mysqli_fetch_assoc($fac_res)):
                ?>
                <option value="<?php echo htmlspecialchars($frow['name']); ?>"><?php echo htmlspecialchars($frow['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <form method="POST">
        <table class="admin-table">
            <thead><tr><th style="width:40px;"><input type="checkbox" id="select_all_approved" onclick="document.querySelectorAll('#approved-tbody tr:not([style*=\'none\']) .approved_cb').forEach(c=>c.checked=this.checked);updateApprovedBulk();"></th><th>Faculty</th><th>Reviewer</th><th>Date</th><th style="width:120px;">Action</th></tr></thead>
            <tbody id="approved-tbody">
            <?php $has_approved=false; while($r=mysqli_fetch_assoc($approved_reviews)): $has_approved=true; ?>
            <tr>
                <td><input type="checkbox" name="selected_approved[]" value="<?php echo $r['id']; ?>" class="approved_cb" onchange="updateApprovedBulk()"></td>
                <td><strong><?php echo htmlspecialchars($r['faculty_name']); ?></strong></td>
                <td><span style="color:var(--gray-400);">@<?php echo htmlspecialchars($r['user_name']); ?></span></td>
                <td><span style="font-size:12px;color:var(--gray-400);"><?php echo date("M j, Y", strtotime($r['created_at'])); ?></span></td>
                <td><div style="display:flex;gap:6px;">
                    <button type="button" class="btn btn-outline" onclick="openModal('<?php echo htmlspecialchars(addslashes($r['review_text'])); ?>','<?php echo htmlspecialchars(addslashes($r['faculty_name'])); ?>','<?php echo htmlspecialchars(addslashes($r['user_name'])); ?>')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</button>
                    <a href="reject_review.php?id=<?php echo $r['id']; ?>" class="btn btn-red" onclick="return confirm('Delete this review?')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>Delete</a>
                </div></td>
            </tr>
            <?php endwhile; ?>
            <?php if(!$has_approved): ?><tr class="empty-row"><td colspan="5"><svg width="36" height="36" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom:8px;display:block;margin-left:auto;margin-right:auto;"><polyline points="20 6 9 17 4 12"/></svg>No approved reviews yet</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div class="bulk-bar" id="approved_bulk_bar">
            <span id="approved_selected_count">0 reviews selected</span>
            <button type="submit" name="bulk_delete_approved" class="btn btn-red" onclick="return confirm('Delete selected reviews?')">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                Delete Selected
            </button>
        </div>
        </form>
    </div>

    <!-- Reports -->
    <div class="section" id="reports">
        <div class="section-header">
            <div class="section-title">
                <svg width="17" height="17" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                System Reports
            </div>
        </div>
        <div style="padding:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;">
            <?php foreach([
                ['Total Users',$total_users,'#6366f1'],
                ['Total Admins',$total_admins,'#8B0000'],
                ['Total Faculties',$total_faculties,'#f59e0b'],
                ['Total Reviews',$total_reviews,'#6b7280'],
                ['Pending',$pending_count,'#f97316'],
                ['Approved',$approved_count,'#10b981'],
                ['Rejected',$rejected_count,'#ef4444'],
            ] as $c): ?>
            <div style="background:var(--gray-100);border-radius:var(--radius-sm);padding:18px;border:1px solid var(--gray-200);">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);font-weight:600;margin-bottom:8px;"><?php echo $c[0]; ?></div>
                <div style="font-size:28px;font-weight:700;color:<?php echo $c[2]; ?>;"><?php echo $c[1]; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- User Profile Modal -->
<div class="modal-overlay" id="userModal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-head">
            <h3>User Profile</h3>
            <button class="modal-close" onclick="document.getElementById('userModal').classList.remove('open')">&times;</button>
        </div>
        <div style="padding:28px;text-align:center;">
            <img id="userModalAvatar" src="" alt="" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--maroon-pale);margin-bottom:14px;">
            <div id="userModalName" style="font-size:17px;font-weight:700;color:var(--gray-800);margin-bottom:4px;"></div>
            <div id="userModalUsername" style="font-size:13px;color:var(--gray-400);margin-bottom:16px;"></div>
            <div style="background:var(--gray-100);border-radius:var(--radius-sm);padding:14px;text-align:left;">
                <div style="display:flex;align-items:center;gap:10px;font-size:13px;color:var(--gray-600);">
                    <svg width="15" height="15" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <span id="userModalEmail"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete User Confirm Modal -->
<div class="modal-overlay" id="deleteUserModal">
    <div class="modal-box" style="max-width:400px;">
        <div class="modal-head">
            <h3>Delete User</h3>
            <button class="modal-close" onclick="document.getElementById('deleteUserModal').classList.remove('open')">&times;</button>
        </div>
        <form method="POST">
            <div style="padding:28px;text-align:center;">
                <div style="width:56px;height:56px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <svg width="26" height="26" fill="none" stroke="#ef4444" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </div>
                <p style="font-size:15px;font-weight:600;color:var(--gray-800);margin-bottom:8px;">Delete this user?</p>
                <p style="font-size:13px;color:var(--gray-400);">All data for <strong id="deleteUserName"></strong> will be permanently removed. This cannot be undone.</p>
                <input type="hidden" name="delete_user_id" id="deleteUserIdInput">
            </div>
            <div style="display:flex;justify-content:center;gap:12px;padding:16px 24px;border-top:1px solid var(--gray-100);">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('deleteUserModal').classList.remove('open')">Cancel</button>
                <button type="submit" name="delete_single_user" class="btn btn-red">Delete User</button>
            </div>
        </form>
    </div>
</div>


<!-- Review Modal -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><span id="modalFaculty" style="color:var(--maroon);"></span><span style="color:var(--gray-400);font-weight:400;font-size:13px;margin-left:8px;">by @<span id="modalUser"></span></span></h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<script>
function openUserModal(id, fullname, username, email, avatar) {
    document.getElementById('userModalAvatar').src   = avatar;
    document.getElementById('userModalName').textContent     = fullname;
    document.getElementById('userModalUsername').textContent = '@' + username;
    document.getElementById('userModalEmail').textContent    = email;
    document.getElementById('userModal').classList.add('open');
}
function confirmDeleteUser(id, fullname) {
    document.getElementById('deleteUserIdInput').value      = id;
    document.getElementById('deleteUserName').textContent   = fullname;
    document.getElementById('deleteUserModal').classList.add('open');
}
document.getElementById('userModal').addEventListener('click', e => { if (e.target === document.getElementById('userModal')) document.getElementById('userModal').classList.remove('open'); });
document.getElementById('deleteUserModal').addEventListener('click', e => { if (e.target === document.getElementById('deleteUserModal')) document.getElementById('deleteUserModal').classList.remove('open'); });

function openModal(text,faculty,user){
    document.getElementById('modalBody').textContent=text;
    document.getElementById('modalFaculty').textContent=faculty;
    document.getElementById('modalUser').textContent=user;
    document.getElementById('reviewModal').classList.add('open');
}
function closeModal(){document.getElementById('reviewModal').classList.remove('open');}
document.getElementById('reviewModal').addEventListener('click',e=>{if(e.target===document.getElementById('reviewModal'))closeModal();});

function toggleUserBulk(){
    const checked=document.querySelectorAll('.user_checkbox:checked');
    document.getElementById('bulk_actions').classList.toggle('show',checked.length>0);
    document.getElementById('selected_count').textContent=checked.length+' user'+(checked.length!==1?'s':'')+' selected';
}
function updateApprovedBulk(){
    const checked = document.querySelectorAll('.approved_cb:checked');
    document.getElementById('approved_bulk_bar').classList.toggle('show', checked.length > 0);
    document.getElementById('approved_selected_count').textContent = checked.length + ' review' + (checked.length !== 1 ? 's' : '') + ' selected';
}

function updateReviewBulk(){
    const checked = document.querySelectorAll('.review_cb:checked');
    document.getElementById('review_bulk_bar').classList.toggle('show', checked.length > 0);
    document.getElementById('review_selected_count').textContent = checked.length + ' review' + (checked.length !== 1 ? 's' : '') + ' selected';
}

// ── Search + Filter + Pagination ─────────────────────────────────────────────
const PER_PAGE = 5;
const tableState = {}; // tracks current page per table

function filterTable(tbodyId, searchId, filterId, extraFilterId) {
    const tbody    = document.getElementById(tbodyId);
    if (!tbody) return;
    const search   = searchId   ? document.getElementById(searchId).value.toLowerCase()   : '';
    const filter   = filterId   ? document.getElementById(filterId).value.toLowerCase()   : '';

    const rows = [...tbody.querySelectorAll('tr:not(.empty-row):not(.no-results-row)')];
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const matchSearch = !search || text.includes(search);
        const matchFilter = !filter || text.includes(filter);
        row.dataset.visible = (matchSearch && matchFilter) ? 'true' : 'false';
    });

    tableState[tbodyId] = 1;
    renderPage(tbodyId);
}

function renderPage(tbodyId) {
    const tbody   = document.getElementById(tbodyId);
    if (!tbody) return;
    const rows    = [...tbody.querySelectorAll('tr:not(.empty-row):not(.no-results-row)')];
    const visible = rows.filter(r => r.dataset.visible !== 'false');
    const page    = tableState[tbodyId] || 1;
    const total   = visible.length;
    const totalPages = Math.ceil(total / PER_PAGE);
    const start   = (page - 1) * PER_PAGE;
    const end     = start + PER_PAGE;

    // Show/hide rows
    rows.forEach(r => r.style.display = 'none');
    visible.slice(start, end).forEach(r => r.style.display = '');

    // Uncheck select-all headers when page changes
    ['select_all_reviews','select_all_approved','select_all_users'].forEach(id => {
        const el = document.getElementById(id); if (el) el.checked = false;
    });

    // No results row
    let noRes = tbody.querySelector('.no-results-row');
    if (total === 0) {
        if (!noRes) {
            noRes = document.createElement('tr');
            noRes.className = 'no-results-row';
            const colspan = tbody.closest('table').querySelector('thead tr').children.length;
            noRes.innerHTML = `<td colspan="${colspan}">No results found.</td>`;
            tbody.appendChild(noRes);
        }
        noRes.style.display = '';
    } else {
        if (noRes) noRes.style.display = 'none';
    }

    // Pagination controls
    const pagId = tbodyId.replace('-tbody', '-pag');
    let pag = document.getElementById(pagId);
    if (!pag) {
        pag = document.createElement('div');
        pag.id = pagId;
        pag.style.cssText = 'display:flex;align-items:center;justify-content:flex-end;gap:6px;padding:12px 20px;border-top:1px solid var(--gray-100);flex-wrap:wrap;';
        tbody.closest('table').after(pag);
    }
    pag.innerHTML = '';
    if (totalPages <= 1 && total > 0) return;
    if (total === 0) return;

    const info = document.createElement('span');
    info.style.cssText = 'font-size:12px;color:var(--gray-400);margin-right:6px;flex:1;';
    info.textContent = `${start+1}–${Math.min(end, total)} of ${total}`;
    pag.appendChild(info);

    const prev = document.createElement('button');
    prev.className = 'btn btn-outline'; prev.style.padding = '4px 10px';
    prev.innerHTML = '←'; prev.disabled = page === 1;
    prev.onclick = () => { tableState[tbodyId] = page - 1; renderPage(tbodyId); };
    pag.appendChild(prev);

    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.className = 'btn ' + (i === page ? 'btn-maroon' : 'btn-outline');
        btn.style.padding = '4px 10px'; btn.textContent = i;
        btn.onclick = () => { tableState[tbodyId] = i; renderPage(tbodyId); };
        pag.appendChild(btn);
    }

    const next = document.createElement('button');
    next.className = 'btn btn-outline'; next.style.padding = '4px 10px';
    next.innerHTML = '→'; next.disabled = page === totalPages;
    next.onclick = () => { tableState[tbodyId] = page + 1; renderPage(tbodyId); };
    pag.appendChild(next);
}

document.addEventListener('DOMContentLoaded', () => {
    // Mark all rows visible by default
    document.querySelectorAll('.admin-table tbody tr:not(.empty-row)').forEach(r => r.dataset.visible = 'true');
    // Init pagination for all tables
    ['users-tbody','faculties-tbody','pending-tbody','approved-tbody'].forEach(id => {
        tableState[id] = 1;
        renderPage(id);
    });

    // Edit users button
    const editBtn = document.getElementById('edit_users_btn');
    if (editBtn) {
        editBtn.addEventListener('click', function() {
            const th  = document.getElementById('select_all_th');
            const tds = document.querySelectorAll('.checkbox_td');
            const showing = th.style.display !== 'none';
            th.style.display  = showing ? 'none' : 'table-cell';
            tds.forEach(td => td.style.display = showing ? 'none' : 'table-cell');
            this.innerHTML = showing
                ? '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit'
                : '✕ Cancel';
            if (showing) {
                document.querySelectorAll('.user_checkbox').forEach(c => c.checked = false);
                document.getElementById('select_all_users').checked = false;
                toggleUserBulk();
            }
        });
        document.getElementById('select_all_users').addEventListener('change', function() {
            document.querySelectorAll('.user_checkbox').forEach(c => c.checked = this.checked);
            toggleUserBulk();
        });
        document.querySelectorAll('.user_checkbox').forEach(c => c.addEventListener('change', toggleUserBulk));
    }
});
</script>
</body>
</html>