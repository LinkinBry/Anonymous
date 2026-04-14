<?php
// ── /admin/admin_dashboard.php ────────────────────────────────────────────────

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../email_helper.php';
require_once __DIR__ . '/../session_check.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$result  = mysqli_query($conn, "SELECT id, fullname, role, profile_pic FROM users WHERE id='$user_id' LIMIT 1");
if ($result && mysqli_num_rows($result) > 0) {
    $current_user = mysqli_fetch_assoc($result);
} else { header("Location: logout.php"); exit(); }

if ($current_user['role'] != 'admin') { header("Location: ../dashboard.php"); exit(); }

$admin_avatar = !empty($current_user['profile_pic']) && file_exists(__DIR__ . '/../' . $current_user['profile_pic'])
    ? '../' . $current_user['profile_pic']
    : 'https://ui-avatars.com/api/?name=' . urlencode($current_user['fullname']) . '&background=6B0000&color=fff&size=80';

/* ── Bulk Approve ─────────────────────────────────────────────────────── */
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
            sendBrevoEmail($rev['email'], $rev['fullname'], 'Your review has been approved — OlshcoReview', approvedEmailHtml($rev['username'], $fname));
        }
    }
    header("Location: admin_dashboard.php#pending"); exit();
}

/* ── Bulk Reject ──────────────────────────────────────────────────────── */
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
            sendBrevoEmail($rev['email'], $rev['fullname'], 'Your review has been rejected — OlshcoReview', rejectedEmailHtml($rev['username'], $fname));
        }
    }
    header("Location: admin_dashboard.php#pending"); exit();
}

/* ── Single Delete User ───────────────────────────────────────────────── */
if (isset($_POST['delete_single_user'])) {
    $uid = intval($_POST['delete_user_id']);
    if ($uid != $user_id) {
        mysqli_query($conn, "DELETE FROM reviews WHERE user_id='$uid'");
        mysqli_query($conn, "DELETE FROM users WHERE id='$uid' AND role='user'");
    }
    header("Location: admin_dashboard.php#users"); exit();
}

/* ── Bulk Delete Users ────────────────────────────────────────────────── */
if (isset($_POST['bulk_delete_users']) && !empty($_POST['selected_users'])) {
    foreach ($_POST['selected_users'] as $uid) {
        $uid = intval($uid);
        if ($uid != $user_id) {
            mysqli_query($conn, "DELETE FROM reviews WHERE user_id='$uid'");
            mysqli_query($conn, "DELETE FROM users WHERE id='$uid' AND role='user'");
        }
    }
    header("Location: admin_dashboard.php#users"); exit();
}

/* ── Bulk Delete Approved Reviews ─────────────────────────────────────── */
if (isset($_POST['bulk_delete_approved']) && !empty($_POST['selected_approved'])) {
    foreach ($_POST['selected_approved'] as $rid) {
        $rid = intval($rid);
        mysqli_query($conn, "DELETE FROM reviews WHERE id='$rid' AND status='approved'");
    }
    header("Location: admin_dashboard.php#approved"); exit();
}

/* ── Add Faculty ──────────────────────────────────────────────────────── */
if (isset($_POST['add_faculty_modal'])) {
    $fname      = trim(mysqli_real_escape_string($conn, $_POST['faculty_name'] ?? ''));
    $fdept      = trim(mysqli_real_escape_string($conn, $_POST['faculty_dept'] ?? ''));
    $add_errors = [];
    if (empty($fname)) $add_errors[] = 'Name required';
    if (empty($fdept)) $add_errors[] = 'Department required';
    if (empty($add_errors)) {
        $dup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM faculties WHERE name='$fname' AND department='$fdept' LIMIT 1"));
        if ($dup) $add_errors[] = 'Faculty already exists in that department';
    }
    if (empty($add_errors)) {
        mysqli_query($conn, "INSERT INTO faculties (name, department) VALUES ('$fname','$fdept')");
        $new_fid = mysqli_insert_id($conn);
        if (!empty($_FILES['faculty_photo']['name']) && $_FILES['faculty_photo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
            $ftype         = mime_content_type($_FILES['faculty_photo']['tmp_name']);
            if (in_array($ftype, $allowed_types) && $_FILES['faculty_photo']['size'] <= 3*1024*1024) {
                $ext        = pathinfo($_FILES['faculty_photo']['name'], PATHINFO_EXTENSION);
                $filename   = 'uploads/faculty_' . $new_fid . '_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                if (move_uploaded_file($_FILES['faculty_photo']['tmp_name'], $upload_dir . basename($filename))) {
                    $fs = mysqli_real_escape_string($conn, $filename);
                    mysqli_query($conn, "UPDATE faculties SET photo='$fs' WHERE id='$new_fid'");
                }
            }
        }
        header("Location: admin_dashboard.php?added=1#faculties"); exit();
    }
    $add_faculty_error = implode(', ', $add_errors);
}

/* ── Delete Faculty ───────────────────────────────────────────────────── */
if (isset($_GET['delete_faculty']) && is_numeric($_GET['delete_faculty'])) {
    $dfid = intval($_GET['delete_faculty']);
    mysqli_query($conn, "DELETE FROM faculties WHERE id='$dfid'");
    header("Location: admin_dashboard.php?deleted_faculty=1#faculties"); exit();
}

/* ── Edit Faculty ─────────────────────────────────────────────────────── */
if (isset($_POST['edit_faculty_modal'])) {
    $efid   = intval($_POST['ef_faculty_id']);
    $efname = trim(mysqli_real_escape_string($conn, $_POST['ef_name'] ?? ''));
    $efdept = trim(mysqli_real_escape_string($conn, $_POST['ef_dept'] ?? ''));
    $ef_errors = [];
    if (empty($efname)) $ef_errors[] = 'Name required';
    if (empty($efdept)) $ef_errors[] = 'Department required';
    if (empty($ef_errors)) {
        $dup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM faculties WHERE name='$efname' AND department='$efdept' AND id!='$efid' LIMIT 1"));
        if ($dup) $ef_errors[] = 'Another faculty with this name already exists in that department';
    }
    if (empty($ef_errors)) {
        mysqli_query($conn, "UPDATE faculties SET name='$efname', department='$efdept' WHERE id='$efid'");
        if (!empty($_FILES['ef_faculty_photo']['name']) && $_FILES['ef_faculty_photo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
            $ftype         = mime_content_type($_FILES['ef_faculty_photo']['tmp_name']);
            if (in_array($ftype, $allowed_types) && $_FILES['ef_faculty_photo']['size'] <= 3*1024*1024) {
                $ext        = pathinfo($_FILES['ef_faculty_photo']['name'], PATHINFO_EXTENSION);
                $filename   = 'uploads/faculty_' . $efid . '_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                if (move_uploaded_file($_FILES['ef_faculty_photo']['tmp_name'], $upload_dir . basename($filename))) {
                    $fs = mysqli_real_escape_string($conn, $filename);
                    mysqli_query($conn, "UPDATE faculties SET photo='$fs' WHERE id='$efid'");
                }
            }
        }
        header("Location: admin_dashboard.php?edited_faculty=1#faculties"); exit();
    }
    $edit_faculty_error   = implode(', ', $ef_errors);
    $edit_faculty_prefill = ['id' => $efid, 'name' => $_POST['ef_name'], 'dept' => $_POST['ef_dept']];
}

/* ── Data Queries ─────────────────────────────────────────────────────── */
$users = mysqli_query($conn, "SELECT id, fullname, username, email, profile_pic FROM users WHERE role='user' ORDER BY id DESC");

$sort_star = isset($_GET['sort_star']) ? $_GET['sort_star'] : 'desc';
$sort_dir  = $sort_star === 'asc' ? 'ASC' : 'DESC';
$faculties = mysqli_query($conn, "
    SELECT f.*,
        COALESCE(f.photo,'') AS photo,
        ROUND(AVG(r.rating_teaching),1)      AS avg_teaching,
        ROUND(AVG(r.rating_communication),1) AS avg_communication,
        ROUND(AVG(r.rating_punctuality),1)   AS avg_punctuality,
        ROUND(AVG(r.rating_fairness),1)      AS avg_fairness,
        ROUND(AVG(r.rating_overall),1)       AS avg_overall,
        ROUND((AVG(r.rating_teaching)+AVG(r.rating_communication)+AVG(r.rating_punctuality)+AVG(r.rating_fairness)+AVG(r.rating_overall))/5,1) AS avg_all,
        COUNT(r.id) AS review_count
    FROM faculties f
    LEFT JOIN reviews r ON r.faculty_id=f.id AND r.status='approved'
    GROUP BY f.id
    ORDER BY avg_all $sort_dir, f.name ASC");

$total_users     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='user'"))['c'];
$total_admins    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='admin'"))['c'];
$total_faculties = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM faculties"))['c'];
$total_reviews   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews r JOIN users u ON r.user_id=u.id"))['c'];
$pending_count   = intval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews r JOIN users u ON r.user_id=u.id WHERE r.status='pending'"))['c']);
$approved_count  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews r JOIN users u ON r.user_id=u.id WHERE r.status='approved'"))['c'];
$rejected_count  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews r JOIN users u ON r.user_id=u.id WHERE r.status='rejected'"))['c'];

$pending_reviews = mysqli_query($conn, "
    SELECT r.id, r.user_id, u.fullname AS user_fullname, u.username, COALESCE(u.profile_pic,'') AS user_pic,
        f.name AS faculty_name,
        r.review_text, r.sentiment, r.is_toxic, r.summary, r.created_at,
        r.rating_teaching, r.rating_communication, r.rating_punctuality, r.rating_fairness, r.rating_overall,
        COALESCE(r.photo,'') AS review_photo
    FROM reviews r
    JOIN users u ON r.user_id=u.id
    JOIN faculties f ON r.faculty_id=f.id
    WHERE r.status='pending' ORDER BY r.created_at DESC");

$approved_reviews = mysqli_query($conn, "
    SELECT r.id, r.user_id, f.name AS faculty_name, u.fullname AS user_fullname,
        r.review_text, r.created_at,
        r.rating_teaching, r.rating_communication, r.rating_punctuality, r.rating_fairness, r.rating_overall,
        COALESCE(r.photo,'') AS review_photo
    FROM reviews r
    JOIN faculties f ON r.faculty_id=f.id
    JOIN users u ON r.user_id=u.id
    WHERE r.status='approved' ORDER BY r.created_at DESC");

/* ── Weekly chart data ────────────────────────────────────────────────── */
$weekly = [];
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-$i days"));
    $label = date('D', strtotime("-$i days"));
    $weekly[] = [
        'date'     => $date,
        'label'    => $label,
        'reviews'  => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews r JOIN users u ON r.user_id=u.id WHERE DATE(r.created_at)='$date'"))['c'],
        'approved' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews r JOIN users u ON r.user_id=u.id WHERE DATE(r.created_at)='$date' AND r.status='approved'"))['c'],
        'rejected' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews r JOIN users u ON r.user_id=u.id WHERE DATE(r.created_at)='$date' AND r.status='rejected'"))['c'],
        'users'    => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE DATE(created_at)='$date'"))['c'],
    ];
}
$week_reviews  = array_sum(array_column($weekly, 'reviews'));
$week_approved = array_sum(array_column($weekly, 'approved'));
$week_rejected = array_sum(array_column($weekly, 'rejected'));
$week_users    = array_sum(array_column($weekly, 'users'));

$af_d   = mysqli_query($conn, "SELECT DISTINCT department FROM faculties WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$af_arr = [];
while ($ad = mysqli_fetch_assoc($af_d)) $af_arr[] = $ad['department'];
$ef_arr = $af_arr;

/* ── Helper: resolve photo path ───────────────────────────────────────── */
function resolvePhoto(string $path, $fallbackUrl): ?string {
    if (empty($path)) return $fallbackUrl;
    $abs = __DIR__ . '/../' . ltrim($path, '/');
    return file_exists($abs) ? '../' . ltrim($path, '/') : $fallbackUrl;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — OlshcoReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<!-- Base shared CSS -->
<link rel="stylesheet" href="../assets/css/style.css">
<!-- Admin-specific CSS (dark maroon theme) — loaded AFTER to override -->
<link rel="stylesheet" href="../assets/css/admin.css">
<link rel="stylesheet" href="../assets/css/admin_theme.css">
</head>
<body>

<!-- ══ Sidebar Toggle ═══════════════════════════════════════════════════ -->
<button class="sidebar-toggle" id="sidebarToggle" title="Toggle sidebar">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <polyline points="15 18 9 12 15 6"/>
    </svg>
</button>

<!-- ══ Sidebar ══════════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-top">
        <div class="sidebar-logo">
            <img src="../image/logo.png" alt="Logo"
                 onerror="this.parentElement.innerHTML='<svg width=&quot;20&quot; height=&quot;20&quot; fill=&quot;none&quot; stroke=&quot;rgba(255,255,255,0.9)&quot; stroke-width=&quot;2&quot; viewBox=&quot;0 0 24 24&quot;><path d=&quot;M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z&quot;/></svg>'">
        </div>
        <div class="sidebar-brand-text">OlshcoReview<span class="sidebar-brand-sub">Admin Panel</span></div>
    </div>
    <div class="sidebar-user-wrap">
        <img src="<?php echo htmlspecialchars($admin_avatar); ?>" class="sidebar-avatar" alt="Admin">
        <div class="sidebar-name"><?php echo htmlspecialchars($current_user['fullname']); ?></div>
        <div class="sidebar-role">Administrator</div>
    </div>
    <nav>
        <div class="nav-label">Manage</div>
        <a href="#overview">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span class="nav-link-text">Overview</span>
        </a>
        <a href="#users">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            <span class="nav-link-text">Users</span>
        </a>
        <a href="#faculties">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            <span class="nav-link-text">Faculty</span>
        </a>
        <a href="#pending" class="nav-pending-link">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span class="nav-link-text">Pending Reviews</span>
            <?php if ($pending_count > 0): ?>
            <span class="sidebar-nav-badge" style="background:#E24B4A;" id="pendingNavBadge"><?php echo $pending_count; ?></span>
            <span class="sidebar-pending-badge" id="pendingNavBadgeCollapsed"><?php echo $pending_count > 9 ? '9+' : $pending_count; ?></span>
            <?php endif; ?>
        </a>
        <a href="#approved">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <span class="nav-link-text">Approved Reviews</span>
        </a>
        <a href="#reports">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <span class="nav-link-text">Reports</span>
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            <span class="nav-link-text">Logout</span>
        </a>
    </div>
</div>

<!-- ══ Main Content ══════════════════════════════════════════════════════ -->
<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h1>Admin Dashboard</h1>
            <p>Manage users, faculties, and reviews across the system.</p>
        </div>
        <div class="topbar-right">
            <div class="today-date">📅 <?php echo date("F j, Y"); ?></div>
        </div>
    </div>

    <?php if (isset($_GET['added'])): ?>
    <div class="toast toast-success" id="toast1">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Faculty added successfully!
    </div>
    <script>setTimeout(()=>{const t=document.getElementById('toast1');if(t){t.style.transition='opacity 0.5s';t.style.opacity='0';setTimeout(()=>t.remove(),500);}},3500);</script>
    <?php endif; ?>
    <?php if (isset($_GET['edited_faculty'])): ?>
    <div class="toast toast-info" id="toast2">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        Faculty updated successfully!
    </div>
    <script>setTimeout(()=>{const t=document.getElementById('toast2');if(t){t.style.transition='opacity 0.5s';t.style.opacity='0';setTimeout(()=>t.remove(),500);}},3500);</script>
    <?php endif; ?>
    <?php if (isset($_GET['deleted_faculty'])): ?>
    <div class="toast toast-error" id="toast3">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
        Faculty deleted.
    </div>
    <script>setTimeout(()=>{const t=document.getElementById('toast3');if(t){t.style.transition='opacity 0.5s';t.style.opacity='0';setTimeout(()=>t.remove(),500);}},3500);</script>
    <?php endif; ?>

    <div class="content">

    <!-- ── Overview Stats ─────────────────────────────────────────────── -->
    <div id="overview" class="stats-grid stats-row-4">
        <div class="stat-card s-users">
            <div class="stat-label">Total Users</div>
            <div class="stat-value"><?php echo $total_users; ?></div>
            <div class="stat-sub">+<?php echo $week_users; ?> this week</div>
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#818cf8" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
        </div>
        <div class="stat-card s-faculty">
            <div class="stat-label">Total Faculty</div>
            <div class="stat-value"><?php echo $total_faculties; ?></div>
            <div class="stat-sub">Across departments</div>
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg></div>
        </div>
        <div class="stat-card s-pending">
            <div class="stat-label">Pending Reviews</div>
            <div class="stat-value" id="pendingStatVal"><?php echo $pending_count; ?></div>
            <div class="stat-sub">Needs your action</div>
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#fb923c" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        </div>
        <div class="stat-card s-approved">
            <div class="stat-label">Approved Reviews</div>
            <div class="stat-value"><?php echo $approved_count; ?></div>
            <div class="stat-sub">Total published</div>
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="1.8"><polyline points="20 6 9 17 4 12"/></svg></div>
        </div>
    </div>

    <div class="stats-grid stats-row-3">
        <div class="stat-card s-rejected">
            <div class="stat-label">Rejected</div>
            <div class="stat-value"><?php echo $rejected_count; ?></div>
            <div class="stat-sub">Not published</div>
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#f87171" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
        </div>
        <div class="stat-card s-total">
            <div class="stat-label">Total Reviews</div>
            <div class="stat-value"><?php echo $total_reviews; ?></div>
            <div class="stat-sub">All time</div>
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
        </div>
        <div class="stat-card s-admins">
            <div class="stat-label">Admins</div>
            <div class="stat-value"><?php echo $total_admins; ?></div>
            <div class="stat-sub">System admins</div>
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#e07070" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        </div>
    </div>

    <!-- ══ Pending Reviews ════════════════════════════════════════════════ -->
    <div class="section" id="pending">
        <div class="section-header">
            <div class="section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="#e07070" stroke-width="2" style="width:16px;height:16px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Pending Reviews
                <?php if ($pending_count > 0): ?><span class="section-badge" id="pendingSecBadge"><?php echo $pending_count; ?></span><?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <div class="search-box" style="min-width:180px;">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="pending-search" placeholder="Search…" oninput="filterPendingRows()">
                </div>
                <select id="pending-sentiment-filter" class="filter-sel" onchange="filterPendingRows()">
                    <option value="">All Sentiments</option>
                    <option value="positive">Positive</option>
                    <option value="neutral">Neutral</option>
                    <option value="negative">Negative</option>
                </select>
            </div>
        </div>
        <form method="POST">
        <div class="bulk-bar" id="review_bulk_bar">
            <span id="review_selected_count">0 reviews selected</span>
            <button type="submit" name="bulk_approve" class="btn btn-green" style="padding:8px 10px;font-size:11px;" onclick="return confirm('Approve selected reviews?')">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:4px;display:inline;"><polyline points="20 6 9 17 4 12"/></svg>Approve Selected
            </button>
            <button type="submit" name="bulk_reject" class="btn btn-red" style="padding:8px 10px;font-size:11px;" onclick="return confirm('Reject selected reviews?')">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:4px;display:inline;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Reject Selected
            </button>
        </div>
        <div id="pending-rows-wrap">
        <?php $has_pending = false; while ($r = mysqli_fetch_assoc($pending_reviews)): $has_pending = true;
            $s   = $r['sentiment'] ?? 'neutral';
            $cls = $s === 'positive' ? 'ai-pos' : ($s === 'negative' ? 'ai-neg' : 'ai-neu');
        ?>
        <div class="pend-row admin-pageable-row" data-section="pending"
             data-text="<?php echo htmlspecialchars(strtolower($r['review_text'].' '.$r['user_fullname'].' '.$r['faculty_name'])); ?>"
             data-sentiment="<?php echo $s; ?>">
            <input type="checkbox" name="selected_reviews[]" value="<?php echo $r['id']; ?>" class="pend-check review_cb" onchange="updateReviewBulk()">
            <div class="pend-avatar" style="overflow:hidden;padding:0;">
                <?php $upic = resolvePhoto($r['user_pic'], ''); if ($upic): ?>
                    <img src="<?php echo htmlspecialchars($upic); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">
                <?php else: ?>
                    <span style="font-size:13px;font-weight:700;"><?php echo strtoupper(substr($r['user_fullname'], 0, 2)); ?></span>
                <?php endif; ?>
            </div>
            <div class="pend-body">
                <div class="pend-meta">
                    <span class="pend-user"><?php echo htmlspecialchars($r['user_fullname']); ?></span>
                    <svg class="pend-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    <span class="pend-fac"><?php echo htmlspecialchars($r['faculty_name']); ?></span>
                    <span class="ai-tag <?php echo $cls; ?>"><?php echo ucfirst($s); ?></span>
                    <?php if ($r['is_toxic']): ?><span class="ai-tag" style="background:rgba(251,146,60,0.12);color:#fb923c;">Toxic</span><?php endif; ?>
                </div>
                <div class="pend-text"><?php echo htmlspecialchars($r['review_text']); ?></div>
                <?php if (!empty($r['summary'])): ?><div class="pend-time" style="font-style:italic;">"<?php echo htmlspecialchars($r['summary']); ?>"</div><?php endif; ?>
                <div class="pend-time"><?php echo date("M j, Y · g:i A", strtotime($r['created_at'])); ?></div>
            </div>
            <div class="pend-actions">
                <button type="button" class="btn btn-outline" style="padding:4px 10px;font-size:11px;"
                        onclick="openModal('<?php echo htmlspecialchars(addslashes($r['review_text'])); ?>','<?php echo htmlspecialchars(addslashes($r['faculty_name'])); ?>','<?php echo htmlspecialchars(addslashes($r['user_fullname'])); ?>',<?php echo intval($r['rating_teaching']); ?>,<?php echo intval($r['rating_communication']); ?>,<?php echo intval($r['rating_punctuality']); ?>,<?php echo intval($r['rating_fairness']); ?>,<?php echo intval($r['rating_overall']); ?>,'<?php echo htmlspecialchars(addslashes($r['review_photo'])); ?>')">View</button>
                <a href="approve_review.php?id=<?php echo $r['id']; ?>" class="btn btn-green" style="padding:4px 10px;font-size:11px;">Approve</a>
                <a href="reject_review.php?id=<?php echo $r['id']; ?>" class="btn btn-red" style="padding:4px 10px;font-size:11px;">Reject</a>
            </div>
        </div>
        <?php endwhile; ?>
        <?php if (!$has_pending): ?>
        <div style="text-align:center;padding:36px 20px;">
            <svg width="44" height="44" fill="none" stroke="rgba(245,237,237,0.15)" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 12px;display:block;"><polyline points="20 6 9 17 4 12"/></svg>
            <div style="font-size:14px;font-weight:500;margin-bottom:4px;">All caught up!</div>
            <div style="font-size:13px;">No pending reviews to process.</div>
        </div>
        <?php endif; ?>
        <div id="no-pending-results" style="display:none;text-align:center;padding:24px;font-size:13px;">No results match your search.</div>
        </div>
        <div id="pending-pagination" class="section-pagination"></div>
        </form>
    </div>

    <!-- ══ Faculty + Users ═══════════════════════════════════════════════ -->
    <div class="users-faculty-row">

        <!-- Faculty -->
        <div class="section" id="faculties">
            <div class="section-header">
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#e07070" stroke-width="2" style="width:16px;height:16px"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                    Faculty <span class="section-badge"><?php echo $total_faculties; ?></span>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <a href="?sort_star=<?php echo $sort_star === 'desc' ? 'asc' : 'desc'; ?>#faculties" class="btn btn-outline" style="font-size:11px;padding:4px 10px;">★ <?php echo $sort_star === 'desc' ? 'Highest' : 'Lowest'; ?></a>
                    <button type="button" class="btn btn-maroon" style="font-size:11px;padding:4px 10px;" onclick="document.getElementById('addFacultyModal').classList.add('open')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add
                    </button>
                </div>
            </div>
            <div class="section-toolbar">
                <div class="search-box" style="flex:1;min-width:0;">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="faculties-search" placeholder="Search faculty…" oninput="filterFacRows()">
                </div>
                <select id="faculties-dept-filter" class="filter-sel" style="font-size:11px;" onchange="filterFacRows()">
                    <option value="">All Departments</option>
                    <?php $depts_res = mysqli_query($conn, "SELECT DISTINCT department FROM faculties WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
                    while ($d = mysqli_fetch_assoc($depts_res)): ?>
                    <option value="<?php echo htmlspecialchars(strtolower($d['department'])); ?>"><?php echo htmlspecialchars($d['department']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div id="fac-rows-wrap">
            <?php $fac_rank = 0; while ($f = mysqli_fetch_assoc($faculties)):
                $fac_rank++;
                $avg    = floatval($f['avg_all'] ?? 0);
                $full   = floor($avg);
                $fphoto = resolvePhoto($f['photo'], null);
            ?>
            <div class="fac-row admin-pageable-row" data-section="faculty"
                 data-text="<?php echo htmlspecialchars(strtolower($f['name'].' '.($f['department']??''))); ?>"
                 data-dept="<?php echo htmlspecialchars(strtolower($f['department']??'')); ?>">
                <span class="fac-row-rank"><?php echo $fac_rank; ?></span>
                <div class="fac-row-av">
                    <?php if ($fphoto): ?><img src="<?php echo htmlspecialchars($fphoto); ?>" alt="">
                    <?php else: ?><?php echo strtoupper(substr($f['name'], 0, 2)); ?>
                    <?php endif; ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="fac-row-name"><?php echo htmlspecialchars($f['name']); ?></div>
                    <div class="fac-row-dept"><?php echo htmlspecialchars($f['department'] ?? '—'); ?></div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <?php if ($avg > 0): ?>
                    <div class="fac-row-stars">
                        <?php for ($si = 1; $si <= 5; $si++): ?><span class="<?php echo $si <= $full ? 'lit' : ''; ?>">★</span><?php endfor; ?>
                        <span class="fac-row-score"><?php echo number_format($avg, 1); ?></span>
                    </div>
                    <div style="font-size:10px;"><?php echo $f['review_count']; ?> reviews</div>
                    <?php else: ?><div style="font-size:10px;">No ratings yet</div>
                    <?php endif; ?>
                </div>
                <div style="margin-left:8px;display:flex;gap:3px;flex-shrink:0;">
                    <button type="button" class="btn btn-outline" style="padding:3px 8px;font-size:10px;"
                            onclick="openFacultyModal(<?php echo $f['id']; ?>,'<?php echo htmlspecialchars(addslashes($f['name'])); ?>','<?php echo htmlspecialchars(addslashes($f['department']??'')); ?>','<?php echo $fphoto ? htmlspecialchars($fphoto) : ''; ?>')">View</button>
                    <button type="button" class="btn btn-outline" style="padding:3px 8px;font-size:10px;"
                            onclick="openEditFacultyModal(<?php echo $f['id']; ?>,'<?php echo htmlspecialchars(addslashes($f['name'])); ?>','<?php echo htmlspecialchars(addslashes($f['department']??'')); ?>','<?php echo $fphoto ?? ''; ?>')">Edit</button>
                    <a href="?delete_faculty=<?php echo $f['id']; ?>#faculties" class="btn btn-red" style="padding:3px 8px;font-size:10px;" onclick="return confirm('Delete this faculty and all their reviews?')">
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                    </a>
                </div>
            </div>
            <?php endwhile; ?>
            <div id="no-fac-results" style="display:none;text-align:center;padding:24px;font-size:13px;">No faculty found.</div>
            </div>
            <div id="faculty-pagination" class="section-pagination"></div>
        </div>

        <!-- Users -->
        <div class="section" id="users">
            <div class="section-header">
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#e07070" stroke-width="2" style="width:16px;height:16px"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    Users <span class="section-badge"><?php echo $total_users; ?></span>
                </div>
                <button type="button" id="edit_users_btn" class="btn btn-outline" style="font-size:11px;padding:4px 10px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit
                </button>
            </div>
            <div class="section-toolbar">
                <div class="search-box" style="flex:1;min-width:0;">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="users-search" placeholder="Search users…" oninput="filterUserRows()">
                </div>
            </div>
            <form method="POST" id="usersForm">
            <div id="users-rows-wrap">
            <?php while ($u = mysqli_fetch_assoc($users)):
                $upic = resolvePhoto($u['profile_pic'] ?? '', null);
            ?>
            <div class="fac-row admin-pageable-row" data-section="users"
                 data-text="<?php echo htmlspecialchars(strtolower($u['fullname'].' '.$u['username'])); ?>">
                <div class="fac-row-rank" style="visibility:hidden;">#</div>
                <div class="fac-row-av">
                    <?php if ($upic): ?><img src="<?php echo htmlspecialchars($upic); ?>" alt="">
                    <?php else: ?><div style="width:100%;height:100%;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;"><?php echo strtoupper(substr($u['fullname'], 0, 2)); ?></div>
                    <?php endif; ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="fac-row-name"><?php echo htmlspecialchars($u['fullname']); ?></div>
                    <div class="fac-row-dept">@<?php echo htmlspecialchars($u['username']); ?></div>
                </div>
                <div style="margin-left:8px;display:flex;gap:3px;flex-shrink:0;">
                    <button type="button" class="btn btn-outline" style="padding:3px 8px;font-size:10px;"
                            onclick="openUserModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['fullname'])); ?>','<?php echo htmlspecialchars(addslashes($u['username'])); ?>','<?php echo htmlspecialchars(addslashes($u['email'])); ?>','<?php echo $upic ? htmlspecialchars(addslashes($upic)) : 'https://ui-avatars.com/api/?name='.urlencode($u['fullname']).'&background=7C0A02&color=fff&size=120'; ?>')">View</button>
                    <button type="button" class="btn btn-red" style="padding:3px 8px;font-size:10px;"
                            onclick="confirmDeleteUser(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['fullname'])); ?>')">
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                    </button>
                </div>
            </div>
            <?php endwhile; ?>
            </div>
            <div id="users-pagination" class="section-pagination"></div>
            <div class="bulk-bar" id="bulk_actions">
                <span id="selected_count">0 users selected</span>
                <button type="submit" name="bulk_delete_users" class="btn btn-red" style="padding:8px 10px;font-size:11px;" onclick="return confirm('Delete selected users permanently?')">Delete Selected</button>
            </div>
            </form>
        </div>

    </div><!-- end .users-faculty-row -->

    <!-- ══ Approved Reviews ══════════════════════════════════════════════ -->
    <div class="section" id="approved">
        <div class="section-header">
            <div class="section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                Approved Reviews <span class="section-badge"><?php echo $approved_count; ?></span>
            </div>
        </div>
        <div class="section-toolbar">
            <div class="search-box" style="min-width:200px;">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="approved-search" placeholder="Search by faculty or reviewer…" oninput="filterApprovedRows()">
            </div>
            <select id="approved-faculty-filter" class="filter-sel" style="font-size:11px;" onchange="filterApprovedRows()">
                <option value="">All Faculties</option>
                <?php $fac_res = mysqli_query($conn, "SELECT DISTINCT f.name FROM reviews r JOIN faculties f ON r.faculty_id=f.id WHERE r.status='approved' ORDER BY f.name ASC");
                while ($frow = mysqli_fetch_assoc($fac_res)): ?>
                <option value="<?php echo htmlspecialchars(strtolower($frow['name'])); ?>"><?php echo htmlspecialchars($frow['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <form method="POST">
        <div style="padding:8px 16px;border-bottom:1px solid var(--adm-border-soft);display:flex;align-items:center;gap:8px;">
            <input type="checkbox" id="select_all_approved" onchange="document.querySelectorAll('.approved_cb').forEach(c=>c.checked=this.checked);updateApprovedBulk();">
            <label for="select_all_approved" style="font-size:12px;cursor:pointer;">Select all visible</label>
        </div>
        <div id="approved-rows-wrap">
        <?php $has_approved = false; while ($r = mysqli_fetch_assoc($approved_reviews)): $has_approved = true; ?>
        <div class="fac-row admin-pageable-row" data-section="approved"
             data-text="<?php echo htmlspecialchars(strtolower($r['faculty_name'].' '.$r['user_fullname'])); ?>">
            <div style="width:22px;flex-shrink:0;text-align:center;">
                <input type="checkbox" name="selected_approved[]" value="<?php echo $r['id']; ?>" class="approved_cb" onchange="updateApprovedBulk()">
            </div>
            <div class="fac-row-av">
                <div style="width:100%;height:100%;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;"><?php echo strtoupper(substr($r['faculty_name'], 0, 2)); ?></div>
            </div>
            <div style="flex:1;min-width:0;">
                <div class="fac-row-name"><?php echo htmlspecialchars($r['faculty_name']); ?></div>
                <div class="fac-row-dept"><?php echo htmlspecialchars($r['user_fullname']); ?> · <?php echo date("M j, Y", strtotime($r['created_at'])); ?></div>
            </div>
            <div style="margin-left:8px;display:flex;gap:3px;flex-shrink:0;">
                <button type="button" class="btn btn-outline" style="padding:3px 8px;font-size:10px;"
                        onclick="openModal('<?php echo htmlspecialchars(addslashes($r['review_text'])); ?>','<?php echo htmlspecialchars(addslashes($r['faculty_name'])); ?>','<?php echo htmlspecialchars(addslashes($r['user_fullname'])); ?>',<?php echo intval($r['rating_teaching']); ?>,<?php echo intval($r['rating_communication']); ?>,<?php echo intval($r['rating_punctuality']); ?>,<?php echo intval($r['rating_fairness']); ?>,<?php echo intval($r['rating_overall']); ?>,'<?php echo htmlspecialchars(addslashes($r['review_photo'])); ?>')">View</button>
                <a href="reject_review.php?id=<?php echo $r['id']; ?>" class="btn btn-red" style="padding:3px 8px;font-size:10px;" onclick="return confirm('Delete this approved review?')">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </a>
            </div>
        </div>
        <?php endwhile; ?>
        <?php if (!$has_approved): ?><div style="text-align:center;padding:28px;font-size:13px;">No approved reviews yet.</div><?php endif; ?>
        </div>
        <div id="no-approved-results" style="display:none;text-align:center;padding:16px;font-size:13px;">No results match your search.</div>
        <div id="approved-pagination" class="section-pagination"></div>
        <div class="bulk-bar" id="approved_bulk_bar">
            <span id="approved_selected_count">0 reviews selected</span>
            <button type="submit" name="bulk_delete_approved" class="btn btn-red" style="padding:8px 10px;font-size:11px;" onclick="return confirm('Delete selected reviews permanently?')">Delete Selected</button>
        </div>
        </form>
    </div>

    <!-- ══ Reports ════════════════════════════════════════════════════════ -->
    <div class="section" id="reports">
        <div class="section-header">
            <div class="section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="#e07070" stroke-width="2" style="width:16px;height:16px"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                System Reports
            </div>
        </div>
        <div style="padding:16px 16px 0;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.8px;font-weight:600;margin-bottom:10px;opacity:0.5;">Overall Statistics</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:9px;margin-bottom:20px;">
                <?php foreach ([
                    ['Total Users',   $total_users,     '#818cf8'],
                    ['Admins',        $total_admins,    '#e07070'],
                    ['Faculties',     $total_faculties, '#fbbf24'],
                    ['Total Reviews', $total_reviews,   '#9ca3af'],
                    ['Pending',       $pending_count,   '#fb923c'],
                    ['Approved',      $approved_count,  '#4ade80'],
                    ['Rejected',      $rejected_count,  '#f87171'],
                ] as $c): ?>
                <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(139,0,0,0.15);border-radius:var(--adm-radius-sm);padding:11px;">
                    <div style="font-size:9px;text-transform:uppercase;letter-spacing:0.4px;font-weight:600;margin-bottom:4px;opacity:0.45;"><?php echo $c[0]; ?></div>
                    <div style="font-size:20px;font-weight:700;color:<?php echo $c[2]; ?>;"><?php echo $c[1]; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="padding:0 16px 16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.8px;font-weight:600;opacity:0.5;">Weekly Activity</div>
                <div style="display:flex;gap:5px;">
                    <button onclick="switchChart('reviews')" id="chartBtnReviews" class="btn btn-maroon" style="font-size:10px;padding:3px 10px;">Reviews</button>
                    <button onclick="switchChart('users')"   id="chartBtnUsers"   class="btn btn-outline" style="font-size:10px;padding:3px 10px;">Users</button>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                <div style="background:rgba(251,146,60,0.12);color:#fb923c;padding:4px 11px;border-radius:20px;font-size:11px;font-weight:500;"><?php echo $week_reviews; ?> submitted</div>
                <div style="background:rgba(74,222,128,0.12);color:#4ade80;padding:4px 11px;border-radius:20px;font-size:11px;font-weight:500;"><?php echo $week_approved; ?> approved</div>
                <div style="background:rgba(248,113,113,0.12);color:#f87171;padding:4px 11px;border-radius:20px;font-size:11px;font-weight:500;"><?php echo $week_rejected; ?> rejected</div>
                <div style="background:rgba(129,140,248,0.12);color:#818cf8;padding:4px 11px;border-radius:20px;font-size:11px;font-weight:500;"><?php echo $week_users; ?> new users</div>
            </div>
            <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(139,0,0,0.14);border-radius:var(--adm-radius-sm);padding:12px;">
                <canvas id="weeklyChart" height="110"></canvas>
            </div>
        </div>
        <div style="padding:0 16px 16px;border-top:1px solid rgba(139,0,0,0.12);padding-top:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.8px;font-weight:600;opacity:0.5;">
                    Monthly Faculty Summary
                    <span style="font-weight:400;text-transform:none;letter-spacing:0;margin-left:5px;font-size:10px;">Powered by Groq AI</span>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <select id="summaryMonth" class="filter-sel" style="font-size:10px;">
                        <?php for ($m = 1; $m <= 12; $m++) { $sel = ($m == date('n')) ? 'selected' : ''; echo "<option value='$m' $sel>" . date('F', mktime(0,0,0,$m,1)) . "</option>"; } ?>
                    </select>
                    <select id="summaryYear" class="filter-sel" style="font-size:10px;">
                        <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--) { echo "<option value='$y'>$y</option>"; } ?>
                    </select>
                    <button onclick="generateFacultySummary()" id="summaryBtn" class="btn btn-maroon" style="font-size:10px;padding:4px 10px;">Generate</button>
                </div>
            </div>
            <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(139,0,0,0.14);border-radius:var(--adm-radius-sm);padding:14px;">
                <div id="aiSummaryText" style="font-size:13px;line-height:1.8;white-space:pre-wrap;opacity:0.45;">
                    Select a month and click Generate.
                </div>
            </div>
        </div>
    </div>

    </div><!-- end .content -->
</div><!-- end .main -->

<!-- ══ User Profile Modal ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="userModal">
    <div class="modal-box" style="max-width:440px;">
        <div class="modal-head"><h3>User Profile</h3><button class="modal-close" onclick="document.getElementById('userModal').classList.remove('open')">&times;</button></div>
        <div style="padding:28px;text-align:center;">
            <img id="userModalAvatar" src="" alt="" style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:3px solid rgba(139,0,0,0.35);box-shadow:0 4px 20px rgba(0,0,0,0.5);display:block;margin:0 auto 16px;">
            <div id="userModalName"     style="font-size:18px;font-weight:700;margin-bottom:4px;"></div>
            <div id="userModalUsername" style="font-size:13px;margin-bottom:16px;opacity:0.5;"></div>
            <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(139,0,0,0.15);border-radius:8px;padding:10px;">
                <div style="display:flex;align-items:center;justify-content:center;gap:7px;font-size:13px;">
                    <svg width="14" height="14" fill="none" stroke="#e07070" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <span id="userModalEmail"></span>
                </div>
            </div>
        </div>
        <div style="padding:0 20px 20px;">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;padding-bottom:10px;border-top:1px solid rgba(139,0,0,0.12);padding-top:14px;opacity:0.5;">Reviews Made</div>
            <div id="userReviewsList" style="font-size:12px;opacity:0.4;">Loading...</div>
        </div>
    </div>
</div>

<!-- ══ Delete User Modal ═════════════════════════════════════════════════ -->
<div class="modal-overlay" id="deleteUserModal">
    <div class="modal-box" style="max-width:380px;">
        <div class="modal-head"><h3>Delete User</h3><button class="modal-close" onclick="document.getElementById('deleteUserModal').classList.remove('open')">&times;</button></div>
        <form method="POST">
            <div style="padding:28px;text-align:center;">
                <div style="width:52px;height:52px;border-radius:50%;background:rgba(220,38,38,0.15);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                    <svg width="22" height="22" fill="none" stroke="#f87171" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </div>
                <p style="font-size:14px;font-weight:600;margin-bottom:6px;">Delete this user?</p>
                <p style="font-size:12px;opacity:0.5;">All data for <strong id="deleteUserName"></strong> will be permanently removed.</p>
                <input type="hidden" name="delete_user_id" id="deleteUserIdInput">
            </div>
            <div style="display:flex;justify-content:center;gap:10px;padding:14px 20px;border-top:1px solid rgba(139,0,0,0.12);">
                <button type="button" class="btn-secondary" onclick="document.getElementById('deleteUserModal').classList.remove('open')">Cancel</button>
                <button type="submit" name="delete_single_user" class="btn btn-red">Delete User</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Faculty Reviews Modal ════════════════════════════════════════════ -->
<div class="modal-overlay" id="facultyModal">
    <div class="modal-box" style="max-width:660px;">
        <div class="modal-head" style="flex-direction:column;align-items:flex-start;gap:0;padding-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;width:100%;padding-bottom:12px;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <img id="facModalPhoto" src="" alt="" style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:3px solid rgba(139,0,0,0.35);box-shadow:0 4px 20px rgba(0,0,0,0.5);display:block;">
                    <div>
                        <h3 id="facModalName" style="color:#e07070;font-size:16px;margin-bottom:3px;"></h3>
                        <div id="facModalDept" style="font-size:12px;opacity:0.45;"></div>
                    </div>
                </div>
                <button class="modal-close" onclick="document.getElementById('facultyModal').classList.remove('open')">&times;</button>
            </div>
            <div style="display:flex;gap:5px;border-bottom:2px solid rgba(139,0,0,0.18);width:100%;">
                <button class="fac-tab-btn" data-tab="reviews" style="padding:6px 14px;font-size:12px;font-weight:500;border:1px solid var(--adm-maroon);border-radius:5px 5px 0 0;cursor:pointer;font-family:'DM Sans',sans-serif;background:var(--adm-maroon);color:white;margin-bottom:-2px;" onclick="setFacTab('reviews');loadFacultyReviews(_cFid);">Reviews</button>
                <button class="fac-tab-btn" data-tab="summary" style="padding:6px 14px;font-size:12px;font-weight:500;border:1px solid rgba(139,0,0,0.2);border-radius:5px 5px 0 0;cursor:pointer;font-family:'DM Sans',sans-serif;background:transparent;color:rgba(245,237,237,0.5);margin-bottom:-2px;" onclick="setFacTab('summary');loadFacultySummary(_cFid);">AI Report</button>
            </div>
        </div>
        <div id="facModalContent" style="padding:16px 20px 20px;"></div>
    </div>
</div>

<!-- ══ Review Detail Modal ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><span id="modalFaculty" style="color:#e07070;"></span><span style="opacity:0.4;font-weight:400;font-size:12px;margin-left:7px;">by <span id="modalUser"></span></span></h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div style="padding:14px 20px 0;" id="modalRatings"></div>
        <div id="modalPhotoWrap" style="padding:10px 20px 0;display:none;"><img id="modalPhotoImg" src="" alt="" style="max-width:100%;max-height:180px;border-radius:9px;object-fit:cover;border:1px solid rgba(139,0,0,0.2);"></div>
        <div style="padding:14px 20px 0;"><div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;opacity:0.4;margin-bottom:5px;">Review</div></div>
        <div style="padding:0 20px 20px;font-size:13px;line-height:1.7;white-space:pre-wrap;" id="modalBody"></div>
    </div>
</div>

<!-- ══ Add Faculty Modal ═════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addFacultyModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:32px;height:32px;border-radius:50%;background:rgba(139,0,0,0.15);display:flex;align-items:center;justify-content:center;"><svg width="15" height="15" fill="none" stroke="#e07070" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
                <div><h3>Add New Faculty</h3><div style="font-size:11px;opacity:0.4;">Fill in the details below</div></div>
            </div>
            <button class="modal-close" onclick="closeAddFacultyModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" onsubmit="return validateAddFaculty()">
            <div style="padding:18px 20px;">
                <div class="af-alert" id="afAlert"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span id="afAlertText"></span></div>
                <div class="af-group">
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;opacity:0.45;display:block;margin-bottom:6px;">Profile Photo <span style="opacity:0.6;font-weight:400;">(optional)</span></label>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <img id="afPhotoPreview" src="https://ui-avatars.com/api/?name=Faculty&background=7C0A02&color=fff&size=56" class="fac-photo-preview" alt="" style="width:52px;height:52px;border-radius:50%;">
                        <div>
                            <input type="file" name="faculty_photo" id="afPhoto" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="previewFacultyPhoto(this,'afPhotoPreview')">
                            <button type="button" class="btn btn-outline" style="font-size:11px;" onclick="document.getElementById('afPhoto').click()">Upload Photo</button>
                            <div style="font-size:10px;opacity:0.4;margin-top:3px;">JPG, PNG, WEBP · Max 3MB</div>
                        </div>
                    </div>
                </div>
                <div class="af-group" style="margin-top:14px;">
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;opacity:0.45;display:block;margin-bottom:6px;">Full Name <span style="color:#f87171">*</span></label>
                    <div class="af-field"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input type="text" name="faculty_name" id="afName" class="af-input" placeholder="e.g. Dr. Juan dela Cruz" required></div>
                </div>
                <div class="af-group" style="margin-top:14px;">
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;opacity:0.45;display:block;margin-bottom:6px;">Department <span style="color:#f87171">*</span></label>
                    <input type="hidden" name="faculty_dept" id="afDept">
                    <div style="display:flex;flex-direction:column;gap:5px;">
                        <?php if (!empty($af_arr)): ?>
                        <div class="af-field" style="position:relative;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;opacity:0.3;z-index:1;width:14px;height:14px;"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none;opacity:0.3;width:14px;height:14px;"><polyline points="6 9 12 15 18 9"/></svg>
                            <select id="afDeptSelect" onchange="afDeptChange(this)" style="width:100%;padding:9px 30px 9px 34px;border:1px solid rgba(139,0,0,0.2);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:13px;outline:none;background:rgba(255,255,255,0.04);appearance:none;-webkit-appearance:none;cursor:pointer;">
                                <option value="">— Select a department —</option>
                                <?php foreach ($af_arr as $afd): ?><option value="<?php echo htmlspecialchars($afd); ?>"><?php echo htmlspecialchars($afd); ?></option><?php endforeach; ?>
                                <option value="__new__">+ Add new department…</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div id="afNewDeptWrap" style="<?php echo empty($af_arr) ? '' : 'display:none;'; ?>">
                            <div class="af-field"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg><input type="text" id="afNewDeptInput" class="af-input" placeholder="Type new department name…" oninput="document.getElementById('afDept').value=this.value.trim()"></div>
                            <?php if (!empty($af_arr)): ?><button type="button" onclick="afCancelNewDept()" style="margin-top:4px;font-size:10px;opacity:0.4;background:none;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;padding:0;">← Back to existing departments</button><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;padding:12px 20px;border-top:1px solid rgba(139,0,0,0.12);">
                <button type="button" class="btn-secondary" onclick="closeAddFacultyModal()">Cancel</button>
                <button type="submit" name="add_faculty_modal" class="btn btn-maroon">Add Faculty</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Edit Faculty Modal ════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editFacultyModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:32px;height:32px;border-radius:50%;background:rgba(99,102,241,0.15);display:flex;align-items:center;justify-content:center;"><svg width="15" height="15" fill="none" stroke="#818cf8" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div>
                <div><h3>Edit Faculty</h3><div style="font-size:11px;opacity:0.4;">Update faculty details</div></div>
            </div>
            <button class="modal-close" onclick="closeEditFacultyModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" onsubmit="return validateEditFaculty()">
            <input type="hidden" name="ef_faculty_id" id="efFacultyId">
            <div style="padding:18px 20px;">
                <div class="af-alert" id="efAlert"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span id="efAlertText"></span></div>
                <div class="af-group">
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;opacity:0.45;display:block;margin-bottom:6px;">Profile Photo <span style="opacity:0.6;font-weight:400;">(optional)</span></label>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <img id="efPhotoPreview" src="" class="fac-photo-preview" alt="" style="width:52px;height:52px;border-radius:50%;">
                        <div>
                            <input type="file" name="ef_faculty_photo" id="efPhoto" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="previewFacultyPhoto(this,'efPhotoPreview')">
                            <button type="button" class="btn btn-outline" style="font-size:11px;" onclick="document.getElementById('efPhoto').click()">Change Photo</button>
                        </div>
                    </div>
                </div>
                <div class="af-group" style="margin-top:14px;">
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;opacity:0.45;display:block;margin-bottom:6px;">Full Name <span style="color:#f87171">*</span></label>
                    <div class="af-field"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input type="text" name="ef_name" id="efName" class="af-input" placeholder="e.g. Dr. Juan dela Cruz" required></div>
                </div>
                <div class="af-group" style="margin-top:14px;">
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;opacity:0.45;display:block;margin-bottom:6px;">Department <span style="color:#f87171">*</span></label>
                    <div class="af-field"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg><input type="text" name="ef_dept" id="efDept" class="af-input" placeholder="e.g. College of Engineering" list="efDeptList" required autocomplete="off"></div>
                    <datalist id="efDeptList"><?php foreach ($ef_arr as $efd): ?><option value="<?php echo htmlspecialchars($efd); ?>"><?php endforeach; ?></datalist>
                    <?php if (!empty($ef_arr)): ?>
                    <div style="font-size:10px;opacity:0.4;margin-top:5px;margin-bottom:5px;">Quick select:</div>
                    <div class="af-chips">
                        <?php foreach ($ef_arr as $efd): ?><button type="button" class="af-chip" onclick="document.getElementById('efDept').value='<?php echo htmlspecialchars(addslashes($efd)); ?>'"><?php echo htmlspecialchars($efd); ?></button><?php endforeach; ?>
                        <button type="button" class="af-chip af-chip-new" onclick="document.getElementById('efDept').value='';document.getElementById('efDept').focus();">+ New dept</button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;padding:12px 20px;border-top:1px solid rgba(139,0,0,0.12);">
                <button type="button" class="btn-secondary" onclick="closeEditFacultyModal()">Cancel</button>
                <button type="submit" name="edit_faculty_modal" class="btn btn-maroon">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Scripts ═══════════════════════════════════════════════════════════ -->
<script>const weeklyData = <?php echo json_encode($weekly); ?>;</script>

<?php if (!empty($add_faculty_error)): ?>
<script>document.addEventListener('DOMContentLoaded',()=>{document.getElementById('addFacultyModal').classList.add('open');document.getElementById('afAlertText').textContent='<?php echo htmlspecialchars(addslashes($add_faculty_error)); ?>';document.getElementById('afAlert').style.display='flex';});</script>
<?php endif; ?>
<?php if (!empty($edit_faculty_error) && !empty($edit_faculty_prefill)): ?>
<script>document.addEventListener('DOMContentLoaded',()=>{openEditFacultyModal(<?php echo intval($edit_faculty_prefill['id']); ?>,'<?php echo htmlspecialchars(addslashes($edit_faculty_prefill['name'])); ?>','<?php echo htmlspecialchars(addslashes($edit_faculty_prefill['dept'])); ?>','');document.getElementById('efAlertText').textContent='<?php echo htmlspecialchars(addslashes($edit_faculty_error)); ?>';document.getElementById('efAlert').style.display='flex';});</script>
<?php endif; ?>

<script src="../assets/js/admin.js"></script>

<script>
/* ── Override AJAX endpoints to local /admin/ paths ────────────────── */
window.loadFacultyReviews = function(fid) {
    document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:40px;opacity:0.4;">Loading reviews...</div>';
    fetch('get_faculty_reviews.php?faculty_id=' + fid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.error === 'session_expired') { window.location.href = 'login.php?timeout=1'; return; }
            if (data.error) { document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:30px;color:#f87171;">' + data.error + '</div>'; return; }
            renderFacultyReviews(data);
        })
        .catch(() => { document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:30px;color:#f87171;">Failed to load.</div>'; });
};

window.loadFacultySummary = function(fid) {
    const el = document.getElementById('facModalContent');
    el.innerHTML = '<div style="text-align:center;padding:40px;opacity:0.4;">Generating AI report...</div>';
    fetch('get_faculty_reviews.php?faculty_id=' + fid + '&action=summary', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(text => {
            try {
                const d = JSON.parse(text);
                if (d.error === 'session_expired') { window.location.href = 'login.php?timeout=1'; return; }
                if (d.error) { el.innerHTML = '<div style="padding:20px;color:#f87171;">Error: ' + d.error + '</div>'; return; }
                el.innerHTML = '<div style="padding:4px 0 16px;"><div style="background:rgba(255,255,255,0.04);border:1px solid rgba(139,0,0,0.15);border-radius:10px;padding:18px;font-size:13px;line-height:1.85;white-space:pre-wrap;">' + (d.summary || '').replace(/</g,'&lt;') + '</div></div>';
            } catch(e) { el.innerHTML = '<div style="padding:20px;color:#f87171;">Server error.</div>'; }
        })
        .catch(() => { el.innerHTML = '<div style="padding:20px;color:#f87171;">Network error.</div>'; });
};

window.loadUserReviews = function(uid, page) {
    page = page || 1;
    const list = document.getElementById('userReviewsList');
    list.innerHTML = '<div style="opacity:0.4;font-size:13px;padding:8px 0;">Loading...</div>';
    fetch('get_user_reviews.php?user_id=' + uid + '&page=' + page, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.error === 'session_expired') { window.location.href = 'login.php?timeout=1'; return; }
            if (!data.reviews || data.reviews.length === 0) {
                list.innerHTML = '<div style="opacity:0.4;font-size:13px;padding:8px 0;">No reviews made yet.</div>'; return;
            }
            let html = '';
            data.reviews.forEach(rev => {
                const stBg = rev.status === 'approved' ? 'rgba(74,222,128,0.12)' : rev.status === 'rejected' ? 'rgba(248,113,113,0.12)' : 'rgba(251,191,36,0.12)';
                const stC  = rev.status === 'approved' ? '#4ade80' : rev.status === 'rejected' ? '#f87171' : '#fbbf24';
                const rd   = JSON.stringify({ text: rev.review_text, sentiment: rev.sentiment, date: rev.created_at, rt: rev.rating_teaching, rc: rev.rating_communication, rp: rev.rating_punctuality, rf: rev.rating_fairness, ro: rev.rating_overall, photos: rev.photos || [] });
                html += `<div style="padding:8px 0 10px;border-bottom:1px solid rgba(139,0,0,0.1);">
                    <div style="display:flex;gap:8px;align-items:flex-start;">
                        <div class="fac-rev-card" style="flex:1;cursor:pointer;" onclick='openRevDetail(${rd})'>
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;flex-wrap:wrap;">
                                <span style="font-size:12px;font-weight:600;">${esc(rev.faculty_name)}</span>
                                <span style="font-size:10px;padding:1px 6px;border-radius:20px;font-weight:600;background:${stBg};color:${stC};">${rev.status.charAt(0).toUpperCase()+rev.status.slice(1)}</span>
                                <span style="font-size:10px;opacity:0.35;margin-left:auto;">${rev.created_at}</span>
                            </div>
                            <div style="font-size:12px;opacity:0.5;line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">${esc(rev.review_text)}</div>
                        </div>
                        <button type="button" onclick="deleteUserReview(${rev.id},${uid},${page})" style="flex-shrink:0;background:none;border:1px solid rgba(139,0,0,0.2);border-radius:6px;padding:5px 8px;cursor:pointer;opacity:0.4;font-size:11px;display:flex;align-items:center;gap:3px;transition:all 0.18s;" onmouseover="this.style.borderColor='#f87171';this.style.color='#f87171';this.style.opacity='1';" onmouseout="this.style.borderColor='rgba(139,0,0,0.2)';this.style.color='';this.style.opacity='0.4';">
                            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                        </button>
                    </div>
                </div>`;
            });
            if (data.total_pages > 1) {
                html += '<div style="display:flex;align-items:center;gap:5px;padding-top:8px;flex-wrap:wrap;">';
                if (page > 1) html += `<button type="button" onclick="loadUserReviews(${uid},${page-1})" class="btn btn-outline" style="padding:3px 8px;font-size:11px;">←</button>`;
                for (let i = 1; i <= data.total_pages; i++) html += `<button type="button" onclick="loadUserReviews(${uid},${i})" class="btn ${i===page?'btn-maroon':'btn-outline'}" style="padding:3px 8px;font-size:11px;">${i}</button>`;
                if (page < data.total_pages) html += `<button type="button" onclick="loadUserReviews(${uid},${page+1})" class="btn btn-outline" style="padding:3px 8px;font-size:11px;">→</button>`;
                html += '</div>';
            }
            list.innerHTML = html;
        })
        .catch(() => { list.innerHTML = '<div style="color:#f87171;font-size:13px;">Failed to load.</div>'; });
};

window.deleteUserReview = function(rid, uid, pg) {
    if (!confirm('Delete this review permanently?')) return;
    fetch('get_faculty_reviews.php?faculty_id=0&action=delete_any&review_id=' + rid, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(d => { if (d.success) loadUserReviews(uid, pg); else alert('Failed to delete.'); })
        .catch(() => alert('Network error.'));
};

window.generateFacultySummary = function() {
    const btn = document.getElementById('summaryBtn'), el = document.getElementById('aiSummaryText');
    const month = document.getElementById('summaryMonth').value, year = document.getElementById('summaryYear').value;
    btn.disabled = true; btn.innerHTML = 'Generating...';
    el.innerHTML = '<span style="opacity:0.4;">Analyzing data...</span>';
    fetch('faculty_summary.php?month=' + month + '&year=' + year, { credentials: 'include', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(text => {
            try {
                const d = JSON.parse(text);
                if (d.error === 'session_expired') { window.location.href = 'login.php?timeout=1'; return; }
                if (d.error) { el.innerHTML = '<span style="color:#f87171;">Error: ' + d.error + '</span>'; }
                else { el.style.opacity = '0.8'; el.textContent = d.summary || 'No summary available.'; }
            } catch(e) { el.innerHTML = '<span style="color:#f87171;">Server error.</span>'; }
            btn.innerHTML = 'Regenerate'; btn.disabled = false;
        })
        .catch(() => { el.innerHTML = '<span style="color:#f87171;">Network error.</span>'; btn.innerHTML = 'Generate'; btn.disabled = false; });
};

window.openFacultyModal = function(fid, name, dept, photo) {
    _cFid = fid;
    document.getElementById('facModalName').textContent = name;
    document.getElementById('facModalDept').textContent = dept || '—';
    const photoEl = document.getElementById('facModalPhoto');
    photoEl.src = (photo && photo.trim()) ? photo : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=8B0000&color=fff&size=120';
    photoEl.onerror = function() { this.src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=8B0000&color=fff&size=120'; };
    document.getElementById('facModalContent').innerHTML = '<div style="text-align:center;padding:40px;opacity:0.4;">Loading...</div>';
    document.getElementById('facultyModal').classList.add('open');
    setFacTab('reviews');
    loadFacultyReviews(fid);
};
</script>

<script>
window.SESSION_IDLE_TIMEOUT = <?= defined('SESSION_IDLE_TIMEOUT') ? SESSION_IDLE_TIMEOUT : 1200 ?>;
window.SESSION_WARN_BEFORE  = <?= defined('SESSION_WARN_BEFORE')  ? SESSION_WARN_BEFORE  : 10  ?>;
</script>
<script src="../assets/js/session_timeout.js"></script>
</body>
</html>