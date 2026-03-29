<?php
include "config.php";
include "email_helper.php";
include "session_check.php";

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

// Ensure faculties.photo column exists (migration-safe — runs before any INSERT/UPDATE)


// Add Faculty (modal form)
if (isset($_POST['add_faculty_modal'])) {
    $fname = trim(mysqli_real_escape_string($conn, $_POST['faculty_name'] ?? ''));
    $fdept = trim(mysqli_real_escape_string($conn, $_POST['faculty_dept'] ?? ''));
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
        // Handle photo upload
        if (!empty($_FILES['faculty_photo']['name']) && $_FILES['faculty_photo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
            $ftype = mime_content_type($_FILES['faculty_photo']['tmp_name']);
            if (in_array($ftype, $allowed_types) && $_FILES['faculty_photo']['size'] <= 3*1024*1024) {
                $ext      = pathinfo($_FILES['faculty_photo']['name'], PATHINFO_EXTENSION);
                $filename = 'uploads/faculty_' . $new_fid . '_' . time() . '.' . $ext;
                if (!is_dir('uploads')) mkdir('uploads', 0755, true);
                if (move_uploaded_file($_FILES['faculty_photo']['tmp_name'], $filename)) {
                    $fs = mysqli_real_escape_string($conn, $filename);
                    mysqli_query($conn, "UPDATE faculties SET photo='$fs' WHERE id='$new_fid'");
                }
            }
        }
        header("Location: admin_dashboard.php?added=1#faculties"); exit();
    }
    $add_faculty_error = implode(', ', $add_errors);
}

// Delete Faculty
if (isset($_GET['delete_faculty']) && is_numeric($_GET['delete_faculty'])) {
    $dfid = intval($_GET['delete_faculty']);
    mysqli_query($conn, "DELETE FROM faculties WHERE id='$dfid'");
    header("Location: admin_dashboard.php?deleted_faculty=1#faculties"); exit();
}

// Edit Faculty (modal)
if (isset($_POST['edit_faculty_modal'])) {
    $efid   = intval($_POST['ef_faculty_id']);
    $efname = trim(mysqli_real_escape_string($conn, $_POST['ef_name'] ?? ''));
    $efdept = trim(mysqli_real_escape_string($conn, $_POST['ef_dept'] ?? ''));
    $ef_errors = [];
    if (empty($efname)) $ef_errors[] = 'Name required';
    if (empty($efdept)) $ef_errors[] = 'Department required';
    if (empty($ef_errors)) {
        $dup = mysqli_fetch_assoc(mysqli_query($conn,"SELECT id FROM faculties WHERE name='$efname' AND department='$efdept' AND id!='$efid' LIMIT 1"));
        if ($dup) $ef_errors[] = 'Another faculty with this name already exists in that department';
    }
    if (empty($ef_errors)) {
        mysqli_query($conn,"UPDATE faculties SET name='$efname', department='$efdept' WHERE id='$efid'");
        if (!empty($_FILES['ef_faculty_photo']['name']) && $_FILES['ef_faculty_photo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
            $ftype = mime_content_type($_FILES['ef_faculty_photo']['tmp_name']);
            if (in_array($ftype, $allowed_types) && $_FILES['ef_faculty_photo']['size'] <= 3*1024*1024) {
                $ext      = pathinfo($_FILES['ef_faculty_photo']['name'], PATHINFO_EXTENSION);
                $filename = 'uploads/faculty_' . $efid . '_' . time() . '.' . $ext;
                if (!is_dir('uploads')) mkdir('uploads', 0755, true);
                if (move_uploaded_file($_FILES['ef_faculty_photo']['tmp_name'], $filename)) {
                    $fs = mysqli_real_escape_string($conn, $filename);
                    mysqli_query($conn, "UPDATE faculties SET photo='$fs' WHERE id='$efid'");
                }
            }
        }
        header("Location: admin_dashboard.php?edited_faculty=1#faculties"); exit();
    }
    $edit_faculty_error   = implode(', ', $ef_errors);
    $edit_faculty_prefill = ['id'=>$efid,'name'=>$_POST['ef_name'],'dept'=>$_POST['ef_dept']];
}

$users = mysqli_query($conn, "SELECT id, fullname, username, email, profile_pic FROM users WHERE role='user' ORDER BY id DESC");

// Faculties with average star ratings
$sort_star = isset($_GET['sort_star']) ? $_GET['sort_star'] : 'desc';
$sort_dir  = $sort_star === 'asc' ? 'ASC' : 'DESC';
$faculties = mysqli_query($conn, "
    SELECT f.*,
        COALESCE(f.photo,'') AS photo,
        ROUND(AVG(r.rating_teaching),1) AS avg_teaching,
        ROUND(AVG(r.rating_communication),1) AS avg_communication,
        ROUND(AVG(r.rating_punctuality),1) AS avg_punctuality,
        ROUND(AVG(r.rating_fairness),1) AS avg_fairness,
        ROUND(AVG(r.rating_overall),1) AS avg_overall,
        ROUND((AVG(r.rating_teaching)+AVG(r.rating_communication)+AVG(r.rating_punctuality)+AVG(r.rating_fairness)+AVG(r.rating_overall))/5,1) AS avg_all,
        COUNT(r.id) AS review_count
    FROM faculties f
    LEFT JOIN reviews r ON r.faculty_id=f.id AND r.status='approved'
    GROUP BY f.id
    ORDER BY avg_all $sort_dir, f.name ASC");

$total_users     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='user'"))['c'];
$total_admins    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='admin'"))['c'];
$total_faculties = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM faculties"))['c'];
$total_reviews   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews"))['c'];
$pending_count   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews r JOIN users u ON r.user_id=u.id JOIN faculties f ON r.faculty_id=f.id WHERE r.status='pending'"))['c'];
$approved_count  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE status='approved'"))['c'];
$rejected_count  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE status='rejected'"))['c'];

// Use user's registered pseudo-name (fullname) — NOT a generated one
$pending_reviews = mysqli_query($conn, "
    SELECT r.id, r.user_id, u.fullname AS user_fullname, f.name AS faculty_name,
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

// Weekly chart data
$weekly = [];
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-$i days"));
    $label = date('D', strtotime("-$i days"));
    $reviews_created = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE DATE(created_at)='$date'"))['c'];
    $approved_day    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE DATE(created_at)='$date' AND status='approved'"))['c'];
    $rejected_day    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE DATE(created_at)='$date' AND status='rejected'"))['c'];
    $users_day       = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE DATE(created_at)='$date'"))['c'];
    $weekly[] = ['date'=>$date,'label'=>$label,'reviews'=>$reviews_created,'approved'=>$approved_day,'rejected'=>$rejected_day,'users'=>$users_day];
}
$week_reviews  = array_sum(array_column($weekly,'reviews'));
$week_approved = array_sum(array_column($weekly,'approved'));
$week_rejected = array_sum(array_column($weekly,'rejected'));
$week_users    = array_sum(array_column($weekly,'users'));
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
.stat-card.s-users{border-top-color:#6366f1;}.stat-card.s-faculty{border-top-color:#f59e0b;}
.stat-card.s-pending{border-top-color:#f97316;}.stat-card.s-approved{border-top-color:#10b981;}
.stat-card.s-rejected{border-top-color:#ef4444;}.stat-card.s-total{border-top-color:var(--gray-400);}
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
.badge-positive{background:#d1fae5;color:#065f46;}.badge-negative{background:#fee2e2;color:#991b1b;}
.badge-neutral{background:#f3f4f6;color:#4b5563;}.badge-toxic{background:#fff7ed;color:#c2410c;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:500;cursor:pointer;border:none;font-family:'DM Sans',sans-serif;transition:all 0.18s;text-decoration:none;}
.btn-maroon{background:var(--maroon);color:white;}.btn-maroon:hover{background:var(--maroon-light);}
.btn-green{background:#10b981;color:white;}.btn-green:hover{background:#059669;}
.btn-red{background:#ef4444;color:white;}.btn-red:hover{background:#dc2626;}
.btn-gray{background:#6b7280;color:white;}.btn-gray:hover{background:#4b5563;}
.btn-outline{background:white;color:var(--gray-600);border:1px solid var(--gray-200);}.btn-outline:hover{border-color:var(--maroon);color:var(--maroon);}
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
/* SVG stars */
.star-svg-wrap{display:inline-flex;align-items:center;vertical-align:middle;line-height:1;overflow:hidden;}
.avg-num{font-size:12px;color:var(--gray-600);margin-left:5px;font-weight:600;}
.pseudo-name{font-family:monospace;font-size:12px;background:var(--gray-100);padding:2px 7px;border-radius:4px;color:var(--gray-600);}
/* Faculty photo uniform size */
.fac-avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--gray-200);flex-shrink:0;}
/* Add/Edit Faculty modal fields */
.af-group{margin-bottom:16px;}
.af-group label{display:block;font-size:13px;font-weight:600;color:var(--gray-600);margin-bottom:6px;}
.af-group label span{color:#ef4444;}
.af-input{width:100%;padding:10px 14px 10px 36px;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:14px;color:var(--gray-800);outline:none;transition:border-color 0.2s;background:white;}
.af-input:focus{border-color:var(--maroon);box-shadow:0 0 0 3px rgba(139,0,0,0.07);}
.af-field{position:relative;}.af-field svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--gray-400);}
.af-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.af-chip{padding:3px 12px;background:var(--maroon-pale);color:var(--maroon);border:1px solid rgba(139,0,0,0.15);border-radius:12px;font-size:12px;cursor:pointer;transition:all 0.15s;font-family:'DM Sans',sans-serif;}
.af-chip:hover{background:var(--maroon);color:white;}
.af-chip-new{background:white;color:var(--gray-600);border-color:var(--gray-200);}
.af-chip-new:hover{border-color:var(--maroon);color:var(--maroon);background:white;}
.af-alert{background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:14px;display:none;align-items:center;gap:8px;}
/* Faculty photo preview — uniform 64px circle */
.fac-photo-preview{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--gray-200);flex-shrink:0;}
/* Empty state rows */
.empty-state-row td{text-align:center;padding:40px 20px;color:var(--gray-400);}
/* Faculty reviews modal */
.fac-rev-card{padding:10px 12px;border-radius:8px;border:1px solid var(--gray-200);background:white;cursor:pointer;transition:border-color 0.18s,background 0.18s,transform 0.15s;}
.fac-rev-card:hover{border-color:var(--maroon);background:#fff8f8;transform:translateY(-1px);box-shadow:0 2px 8px rgba(139,0,0,0.08);}
/* Scrollable user review list */
.user-rev-item{padding:10px 0;border-bottom:1px solid var(--gray-100);cursor:pointer;border-radius:6px;transition:background 0.15s;}
.user-rev-item:hover{background:var(--maroon-pale);}
.user-rev-item:last-child{border-bottom:none;}
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
        <a href="#overview"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Overview</a>
        <a href="#users"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Users</a>
        <a href="#faculties"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Faculties</a>
        <a href="#pending"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Pending Reviews
            <?php if ($pending_count > 0): ?><span style="background:white;color:var(--maroon);font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:auto;" id="pendingNavBadge"><?php echo $pending_count; ?></span><?php endif; ?>
        </a>
        <a href="#approved"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Approved Reviews</a>
        <a href="#reports"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Reports</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>Logout</a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left"><h1>Admin Dashboard</h1><p>Manage users, faculties, and reviews across the system.</p></div>
        <div class="today-date">📅 <?php echo date("F j, Y"); ?></div>
    </div>

    <?php if (isset($_GET['added'])): ?>
    <div id="toast1" style="position:fixed;top:24px;right:24px;z-index:9999;background:#d1fae5;color:#065f46;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,0.12);display:flex;align-items:center;gap:8px;animation:slideUp 0.3s ease;"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Faculty added successfully!</div>
    <script>setTimeout(()=>{const t=document.getElementById('toast1');if(t){t.style.transition='opacity 0.5s';t.style.opacity='0';setTimeout(()=>t.remove(),500);}},3500);</script>
    <?php endif; ?>
    <?php if (isset($_GET['edited_faculty'])): ?>
    <div id="toast2" style="position:fixed;top:24px;right:24px;z-index:9999;background:#dbeafe;color:#1e40af;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,0.12);display:flex;align-items:center;gap:8px;animation:slideUp 0.3s ease;"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Faculty updated successfully!</div>
    <script>setTimeout(()=>{const t=document.getElementById('toast2');if(t){t.style.transition='opacity 0.5s';t.style.opacity='0';setTimeout(()=>t.remove(),500);}},3500);</script>
    <?php endif; ?>
    <?php if (isset($_GET['deleted_faculty'])): ?>
    <div id="toast3" style="position:fixed;top:24px;right:24px;z-index:9999;background:#fee2e2;color:#991b1b;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,0.12);display:flex;align-items:center;gap:8px;animation:slideUp 0.3s ease;"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>Faculty deleted.</div>
    <script>setTimeout(()=>{const t=document.getElementById('toast3');if(t){t.style.transition='opacity 0.5s';t.style.opacity='0';setTimeout(()=>t.remove(),500);}},3500);</script>
    <?php endif; ?>

    <!-- Stats Row 1 -->
    <div id="overview" class="stats-grid stats-row-4">
        <div class="stat-card s-users"><div class="stat-label">Total Users</div><div class="stat-value"><?php echo $total_users; ?></div><div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(99,102,241,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#6366f1" stroke-width="1.8" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div></div></div>
        <div class="stat-card s-faculty"><div class="stat-label">Total Faculties</div><div class="stat-value"><?php echo $total_faculties; ?></div><div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(245,158,11,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#f59e0b" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg></div></div></div>
        <div class="stat-card s-pending"><div class="stat-label">Pending Reviews</div><div class="stat-value"><?php echo $pending_count; ?></div><div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(249,115,22,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#f97316" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div></div></div>
        <div class="stat-card s-approved"><div class="stat-label">Approved</div><div class="stat-value"><?php echo $approved_count; ?></div><div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(16,185,129,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#10b981" stroke-width="1.8" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div></div></div>
    </div>
    <!-- Stats Row 2 -->
    <div class="stats-grid stats-row-3">
        <div class="stat-card s-rejected"><div class="stat-label">Rejected</div><div class="stat-value"><?php echo $rejected_count; ?></div><div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(239,68,68,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#ef4444" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div></div></div>
        <div class="stat-card s-total"><div class="stat-label">Total Reviews</div><div class="stat-value"><?php echo $total_reviews; ?></div><div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(107,114,128,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#6b7280" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div></div></div>
        <div class="stat-card s-admins"><div class="stat-label">Total Admins</div><div class="stat-value"><?php echo $total_admins; ?></div><div class="stat-icon"><div style="width:44px;height:44px;border-radius:50%;background:rgba(139,0,0,0.1);display:flex;align-items:center;justify-content:center;"><svg width="20" height="20" fill="none" stroke="#8B0000" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div></div></div>
    </div>

    <!-- Users -->
    <div class="section" id="users">
        <div class="section-header">
            <div class="section-title"><svg width="17" height="17" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>User List <span class="section-badge"><?php echo $total_users; ?></span></div>
            <button id="edit_users_btn" class="btn btn-outline"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button>
        </div>
        <div class="section-toolbar">
            <div class="search-box"><svg width="14" height="14" fill="none" stroke="var(--gray-400)" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" id="users-search" placeholder="Search by name, username or email..." oninput="filterTable('users-tbody','users-search',null,null)"></div>
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
                    <button type="button" class="btn btn-outline" onclick="openUserModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['fullname'])); ?>','<?php echo htmlspecialchars(addslashes($u['username'])); ?>','<?php echo htmlspecialchars(addslashes($u['email'])); ?>','<?php echo (!empty($u['profile_pic']) && file_exists($u['profile_pic'])) ? htmlspecialchars(addslashes($u['profile_pic'])) : 'https://ui-avatars.com/api/?name='.urlencode($u['fullname']).'&background=8B0000&color=fff&size=80'; ?>')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</button>
                    <button type="button" class="btn btn-red" onclick="confirmDeleteUser(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['fullname'])); ?>')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>Delete</button>
                </div></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <div class="bulk-bar" id="bulk_actions"><span id="selected_count">0 users selected</span><button type="submit" name="bulk_delete_users" class="btn btn-red" onclick="return confirm('Delete selected users?')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>Delete Selected</button></div>
        </form>
    </div>

    <!-- Faculties -->
    <div class="section" id="faculties">
        <div class="section-header">
            <div class="section-title"><svg width="17" height="17" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Faculty List <span class="section-badge"><?php echo $total_faculties; ?></span></div>
            <div style="display:flex;gap:8px;align-items:center;">
                <a href="?sort_star=<?php echo $sort_star==='desc'?'asc':'desc'; ?>#faculties" class="btn btn-outline">★ <?php echo $sort_star==='desc'?'Highest First':'Lowest First'; ?></a>
                <button type="button" class="btn btn-outline" onclick="document.getElementById('addFacultyModal').classList.add('open')"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Faculty</button>
            </div>
        </div>
        <div class="section-toolbar">
            <div class="search-box"><svg width="14" height="14" fill="none" stroke="var(--gray-400)" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" id="faculties-search" placeholder="Search by name..." oninput="filterTable('faculties-tbody','faculties-search','faculties-dept-filter',null)"></div>
            <select id="faculties-dept-filter" class="filter-sel" onchange="filterTable('faculties-tbody','faculties-search','faculties-dept-filter',null)">
                <option value="">All Departments</option>
                <?php $depts_res = mysqli_query($conn, "SELECT DISTINCT department FROM faculties WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
                while ($d = mysqli_fetch_assoc($depts_res)): ?>
                <option value="<?php echo htmlspecialchars($d['department']); ?>"><?php echo htmlspecialchars($d['department']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <table class="admin-table">
            <thead><tr><th>Name</th><th>Department</th><th>Avg Rating</th><th style="width:170px;">Action</th></tr></thead>
            <tbody id="faculties-tbody">
            <?php while($f = mysqli_fetch_assoc($faculties)):
                $avg = floatval($f['avg_all'] ?? 0);
                $fphoto = (!empty($f['photo']) && file_exists($f['photo'])) ? htmlspecialchars($f['photo']) : 'https://ui-avatars.com/api/?name='.urlencode($f['name']).'&background=8B0000&color=fff&size=36';
            ?>
            <tr>
                <td><div class="user-info"><img class="fac-avatar" src="<?php echo $fphoto; ?>" alt=""><div class="user-info-text"><strong><?php echo htmlspecialchars($f['name']); ?></strong></div></div></td>
                <td><span style="font-size:12px;background:var(--gray-100);padding:3px 10px;border-radius:20px;color:var(--gray-600);"><?php echo htmlspecialchars($f['department'] ?? '—'); ?></span></td>
                <td>
                    <?php if ($avg > 0): ?>
                    <?php
                    // SVG clipPath stars — pixel-perfect partial stars, properly aligned
                    $sz   = 15; $gap = 2;
                    $sw   = $sz * 5 + $gap * 4;
                    $base = round($sz * 0.85);
                    $pct  = min(100, ($avg / 5) * 100);
                    $cw   = round($pct / 100 * $sw, 2);
                    $uid  = 'fs'.substr(md5($f['id'].$avg),0,7);
                    $emp  = $fil = '';
                    for ($si=0;$si<5;$si++){$x=$si*($sz+$gap);$emp.='<text x="'.$x.'" y="'.$base.'" font-size="'.$sz.'" fill="#d1d5db">★</text>';$fil.='<text x="'.$x.'" y="'.$base.'" font-size="'.$sz.'" fill="#f59e0b">★</text>';}
                    ?>
                    <span style="display:inline-flex;align-items:center;vertical-align:middle;line-height:1;overflow:hidden;">
                    <svg width="<?php echo $sw;?>" height="<?php echo $sz;?>" viewBox="0 0 <?php echo $sw;?> <?php echo $sz;?>" xmlns="http://www.w3.org/2000/svg" style="display:block;overflow:hidden;">
                        <defs><clipPath id="<?php echo $uid;?>"><rect x="0" y="0" width="<?php echo $cw;?>" height="<?php echo $sz;?>"/></clipPath></defs>
                        <?php echo $emp;?><g clip-path="url(#<?php echo $uid;?>)"><?php echo $fil;?></g>
                    </svg></span>
                    <span class="avg-num"><?php echo number_format($avg,1); ?></span>
                    <span style="font-size:11px;color:var(--gray-400);margin-left:4px;">(<?php echo $f['review_count']; ?>)</span>
                    <?php else: ?>
                    <span style="font-size:12px;color:var(--gray-400);">No ratings yet</span>
                    <?php endif; ?>
                </td>
                <td><div style="display:flex;gap:6px;">
                    <button type="button" class="btn btn-outline" onclick="openFacultyModal(<?php echo $f['id']; ?>,'<?php echo htmlspecialchars(addslashes($f['name'])); ?>','<?php echo htmlspecialchars(addslashes($f['department']??'')); ?>')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>Reviews</button>
                    <button type="button" class="btn btn-outline" onclick="openEditFacultyModal(<?php echo $f['id']; ?>,'<?php echo htmlspecialchars(addslashes($f['name'])); ?>','<?php echo htmlspecialchars(addslashes($f['department']??'')); ?>','<?php echo (!empty($f['photo'])&&file_exists($f['photo']))?htmlspecialchars(addslashes($f['photo'])):''; ?>')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button>
                    <a href="?delete_faculty=<?php echo $f['id']; ?>#faculties" class="btn btn-red" onclick="return confirm('Delete this faculty?')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>Delete</a>
                </div></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Pending Reviews -->
    <div class="section" id="pending">
        <div class="section-header">
            <div class="section-title"><svg width="17" height="17" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Pending Reviews <?php if($pending_count>0): ?><span class="section-badge" id="pendingSecBadge"><?php echo $pending_count; ?></span><?php endif; ?>
            </div>
        </div>
        <div class="section-toolbar">
            <div class="search-box"><svg width="14" height="14" fill="none" stroke="var(--gray-400)" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" id="pending-search" placeholder="Search by user or faculty..." oninput="filterTable('pending-tbody','pending-search','pending-sentiment-filter',null)"></div>
            <select id="pending-sentiment-filter" class="filter-sel" onchange="filterTable('pending-tbody','pending-search','pending-sentiment-filter',null)">
                <option value="">All Sentiments</option><option value="positive">Positive</option><option value="neutral">Neutral</option><option value="negative">Negative</option>
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
                <td><span class="pseudo-name"><?php echo htmlspecialchars($r['user_fullname']); ?></span></td>
                <td><span style="font-weight:500;"><?php echo htmlspecialchars($r['faculty_name']); ?></span></td>
                <td>
                    <?php $s=$r['sentiment']??'neutral';$cls=$s==='positive'?'badge-positive':($s==='negative'?'badge-negative':'badge-neutral'); ?>
                    <span class="badge <?php echo $cls; ?>"><?php echo ucfirst($s); ?></span>
                    <?php if($r['is_toxic']) echo "<span class='badge badge-toxic' style='margin-left:4px;'>Toxic</span>"; ?>
                    <?php if(!empty($r['summary'])): ?><br><small style="color:var(--gray-400);font-style:italic;font-size:11px;margin-top:3px;display:block;"><?php echo htmlspecialchars($r['summary']); ?></small><?php endif; ?>
                </td>
                <td><div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-outline" onclick="openModal('<?php echo htmlspecialchars(addslashes($r['review_text'])); ?>','<?php echo htmlspecialchars(addslashes($r['faculty_name'])); ?>','<?php echo htmlspecialchars(addslashes($r['user_fullname'])); ?>',<?php echo intval($r['rating_teaching']); ?>,<?php echo intval($r['rating_communication']); ?>,<?php echo intval($r['rating_punctuality']); ?>,<?php echo intval($r['rating_fairness']); ?>,<?php echo intval($r['rating_overall']); ?>,'<?php echo htmlspecialchars(addslashes($r['review_photo'])); ?>')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</button>
                    <a href="approve_review.php?id=<?php echo $r['id']; ?>" class="btn btn-green"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Approve</a>
                    <a href="reject_review.php?id=<?php echo $r['id']; ?>" class="btn btn-red"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Reject</a>
                </div></td>
            </tr>
            <?php endwhile; ?>
            <?php if(!$has_pending): ?>
            <tr class="empty-state-row"><td colspan="5">
                <svg width="48" height="48" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 12px;display:block;"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1" ry="1"/><path d="M9 12h6M9 16h4"/></svg>
                <div style="font-size:14px;font-weight:600;color:var(--gray-400);margin-bottom:4px;">No pending reviews</div>
                <div style="font-size:12px;color:var(--gray-400);">All reviews have been processed.</div>
            </td></tr>
            <?php endif; ?>
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
            <div class="section-title"><svg width="17" height="17" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Approved Reviews <span class="section-badge"><?php echo $approved_count; ?></span></div>
        </div>
        <div class="section-toolbar">
            <div class="search-box"><svg width="14" height="14" fill="none" stroke="var(--gray-400)" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" id="approved-search" placeholder="Search by faculty or reviewer..." oninput="filterTable('approved-tbody','approved-search','approved-faculty-filter',null)"></div>
            <select id="approved-faculty-filter" class="filter-sel" onchange="filterTable('approved-tbody','approved-search','approved-faculty-filter',null)">
                <option value="">All Faculties</option>
                <?php $fac_res=mysqli_query($conn,"SELECT DISTINCT f.name FROM reviews r JOIN faculties f ON r.faculty_id=f.id WHERE r.status='approved' ORDER BY f.name ASC");
                while($frow=mysqli_fetch_assoc($fac_res)): ?>
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
                <td><span class="pseudo-name"><?php echo htmlspecialchars($r['user_fullname']); ?></span></td>
                <td><span style="font-size:12px;color:var(--gray-400);"><?php echo date("M j, Y",strtotime($r['created_at'])); ?></span></td>
                <td><div style="display:flex;gap:6px;">
                    <button type="button" class="btn btn-outline" onclick="openModal('<?php echo htmlspecialchars(addslashes($r['review_text'])); ?>','<?php echo htmlspecialchars(addslashes($r['faculty_name'])); ?>','<?php echo htmlspecialchars(addslashes($r['user_fullname'])); ?>',<?php echo intval($r['rating_teaching']); ?>,<?php echo intval($r['rating_communication']); ?>,<?php echo intval($r['rating_punctuality']); ?>,<?php echo intval($r['rating_fairness']); ?>,<?php echo intval($r['rating_overall']); ?>,'<?php echo htmlspecialchars(addslashes($r['review_photo'])); ?>')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</button>
                    <a href="reject_review.php?id=<?php echo $r['id']; ?>" class="btn btn-red" onclick="return confirm('Delete this review?')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>Delete</a>
                </div></td>
            </tr>
            <?php endwhile; ?>
            <?php if(!$has_approved): ?><tr><td colspan="5" style="text-align:center;padding:28px;color:var(--gray-400);font-size:13px;">No approved reviews yet</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div class="bulk-bar" id="approved_bulk_bar">
            <span id="approved_selected_count">0 reviews selected</span>
            <button type="submit" name="bulk_delete_approved" class="btn btn-red" onclick="return confirm('Delete selected reviews?')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>Delete Selected</button>
        </div>
        </form>
    </div>

    <!-- Reports -->
    <div class="section" id="reports">
        <div class="section-header"><div class="section-title"><svg width="17" height="17" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>System Reports</div></div>
        <div style="padding:20px 24px 0;">
            <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--gray-400);font-weight:600;margin-bottom:14px;">Overall Statistics</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:28px;">
                <?php foreach([['Total Users',$total_users,'#6366f1'],['Total Admins',$total_admins,'#8B0000'],['Faculties',$total_faculties,'#f59e0b'],['Total Reviews',$total_reviews,'#6b7280'],['Pending',$pending_count,'#f97316'],['Approved',$approved_count,'#10b981'],['Rejected',$rejected_count,'#ef4444']] as $c): ?>
                <div style="background:var(--gray-100);border-radius:var(--radius-sm);padding:14px;border:1px solid var(--gray-200);">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);font-weight:600;margin-bottom:6px;"><?php echo $c[0]; ?></div>
                    <div style="font-size:24px;font-weight:700;color:<?php echo $c[2]; ?>;"><?php echo $c[1]; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        $week_reviews=$week_approved=$week_rejected=$week_users=0;
        foreach($weekly as $w){$week_reviews+=$w['reviews'];$week_approved+=$w['approved'];$week_rejected+=$w['rejected'];$week_users+=$w['users'];}
        ?>
        <div style="padding:0 24px 20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--gray-400);font-weight:600;">Weekly Activity (Last 7 Days)</div>
                <div style="display:flex;gap:6px;">
                    <button onclick="switchChart('reviews')" id="chartBtnReviews" class="btn btn-maroon" style="font-size:11px;padding:4px 12px;">Reviews</button>
                    <button onclick="switchChart('users')" id="chartBtnUsers" class="btn btn-outline" style="font-size:11px;padding:4px 12px;">Users</button>
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
                <div style="background:#fef3c7;color:#92400e;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;"><?php echo $week_reviews; ?> reviews created</div>
                <div style="background:#d1fae5;color:#065f46;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;"><?php echo $week_approved; ?> approved</div>
                <div style="background:#fee2e2;color:#991b1b;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;"><?php echo $week_rejected; ?> rejected</div>
                <div style="background:#ede9fe;color:#5b21b6;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;"><?php echo $week_users; ?> new users</div>
            </div>
            <div style="background:var(--gray-100);border-radius:var(--radius-sm);padding:16px;border:1px solid var(--gray-200);"><canvas id="weeklyChart" height="120"></canvas></div>
        </div>
        <div style="padding:0 24px 24px;border-top:1px solid var(--gray-100);padding-top:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--gray-400);font-weight:600;">Monthly Faculty Performance Summary <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:11px;margin-left:6px;">Powered by Groq AI</span></div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <select id="summaryMonth" class="filter-sel" style="font-size:12px;"><?php for($m=1;$m<=12;$m++){$sel=($m==date('n'))?'selected':'';echo "<option value='$m' $sel>".date('F',mktime(0,0,0,$m,1))."</option>";}?></select>
                    <select id="summaryYear" class="filter-sel" style="font-size:12px;"><?php for($y=date('Y');$y>=date('Y')-2;$y--){echo "<option value='$y'>$y</option>";}?></select>
                    <button onclick="generateFacultySummary()" id="summaryBtn" class="btn btn-maroon" style="font-size:12px;"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>Generate</button>
                </div>
            </div>
            <div style="background:var(--gray-100);border-radius:var(--radius-sm);padding:16px;border:1px solid var(--gray-200);">
                <div id="aiSummaryText" style="font-size:14px;color:var(--gray-700);line-height:1.8;"><span style="color:var(--gray-400);">Select a month and click Generate to create an AI-powered monthly faculty performance summary.</span></div>
            </div>
        </div>
    </div>
</div><!-- end .main -->

<!-- ══ MODALS ══════════════════════════════════════════════════════════════ -->

<!-- User Profile Modal -->
<div class="modal-overlay" id="userModal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-head"><h3>User Profile</h3><button class="modal-close" onclick="document.getElementById('userModal').classList.remove('open')">&times;</button></div>
        <div style="padding:28px;text-align:center;">
            <img id="userModalAvatar" src="" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--maroon-pale);margin-bottom:14px;">
            <div id="userModalName" style="font-size:17px;font-weight:700;color:var(--gray-800);margin-bottom:4px;"></div>
            <div id="userModalUsername" style="font-size:13px;color:var(--gray-400);margin-bottom:16px;"></div>
            <div style="background:var(--gray-100);border-radius:var(--radius-sm);padding:12px;text-align:center;">
                <div style="display:flex;align-items:center;justify-content:center;gap:8px;font-size:13px;color:var(--gray-600);"><svg width="14" height="14" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg><span id="userModalEmail"></span></div>
            </div>
        </div>
        <div id="userReviewsSection" style="padding:0 20px 20px;">
            <div style="font-size:12px;font-weight:600;color:var(--gray-600);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;padding-bottom:8px;border-top:1px solid var(--gray-100);padding-top:14px;">Reviews Made</div>
            <div id="userReviewsList" style="font-size:13px;color:var(--gray-400);">Loading...</div>
        </div>
    </div>
</div>

<!-- Delete User Modal -->
<div class="modal-overlay" id="deleteUserModal">
    <div class="modal-box" style="max-width:400px;">
        <div class="modal-head"><h3>Delete User</h3><button class="modal-close" onclick="document.getElementById('deleteUserModal').classList.remove('open')">&times;</button></div>
        <form method="POST">
            <div style="padding:28px;text-align:center;">
                <div style="width:56px;height:56px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;"><svg width="26" height="26" fill="none" stroke="#ef4444" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg></div>
                <p style="font-size:15px;font-weight:600;color:var(--gray-800);margin-bottom:8px;">Delete this user?</p>
                <p style="font-size:13px;color:var(--gray-400);">All data for <strong id="deleteUserName"></strong> will be permanently removed.</p>
                <input type="hidden" name="delete_user_id" id="deleteUserIdInput">
            </div>
            <div style="display:flex;justify-content:center;gap:12px;padding:16px 24px;border-top:1px solid var(--gray-100);">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('deleteUserModal').classList.remove('open')">Cancel</button>
                <button type="submit" name="delete_single_user" class="btn btn-red">Delete User</button>
            </div>
        </form>
    </div>
</div>

<!-- Faculty Reviews Modal -->
<div class="modal-overlay" id="facultyModal">
    <div class="modal-box" style="max-width:680px;">
        <div class="modal-head" style="flex-direction:column;align-items:flex-start;gap:0;padding-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;width:100%;padding-bottom:12px;">
                <div><h3 id="facModalName" style="color:var(--maroon);font-size:16px;"></h3><div id="facModalDept" style="font-size:12px;color:var(--gray-400);margin-top:2px;"></div></div>
                <button class="modal-close" onclick="document.getElementById('facultyModal').classList.remove('open')">&times;</button>
            </div>
            <div style="display:flex;gap:6px;border-bottom:2px solid var(--gray-100);width:100%;">
                <button class="fac-tab-btn" data-tab="reviews" style="padding:7px 16px;font-size:13px;font-weight:500;border:1px solid var(--maroon);border-radius:6px 6px 0 0;cursor:pointer;font-family:'DM Sans',sans-serif;background:var(--maroon);color:white;margin-bottom:-2px;" onclick="setFacTab('reviews');loadFacultyReviews(_currentFacultyId);"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:4px;"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>Reviews</button>
                <button class="fac-tab-btn" data-tab="summary" style="padding:7px 16px;font-size:13px;font-weight:500;border:1px solid var(--gray-200);border-radius:6px 6px 0 0;cursor:pointer;font-family:'DM Sans',sans-serif;background:white;color:var(--gray-600);margin-bottom:-2px;" onclick="setFacTab('summary');loadFacultySummary(_currentFacultyId);"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:4px;"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>AI Report</button>
            </div>
        </div>
        <div id="facModalContent" style="padding:20px 24px 24px;"><div style="text-align:center;padding:30px;color:var(--gray-400);">Loading...</div></div>
    </div>
</div>

<!-- Review Detail Modal (pending/approved view) — stars only, no bar chart, with photo -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><span id="modalFaculty" style="color:var(--maroon);"></span><span style="color:var(--gray-400);font-weight:400;font-size:13px;margin-left:8px;">by <span id="modalUser"></span></span></h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div style="padding:16px 24px 0;" id="modalRatings"></div>
        <div id="modalPhotoWrap" style="padding:12px 24px 0;display:none;">
            <img id="modalPhotoImg" src="" alt="Review photo" style="max-width:100%;max-height:200px;border-radius:10px;object-fit:cover;border:1px solid var(--gray-200);">
        </div>
        <div style="padding:16px 24px 24px;font-size:14px;line-height:1.7;color:var(--gray-700);white-space:pre-wrap;" id="modalBody"></div>
    </div>
</div>

<script>
// ── Escape helper ──────────────────────────────────────────────────────────
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── SVG ClipPath star renderer — pixel-perfect, all stars aligned ──────────
let _starUid = 0;
function starsHtml(val, size) {
    size = size || 15;
    if (!val || parseFloat(val) <= 0) return '<span style="color:var(--gray-400);font-size:12px;">—</span>';
    const pct = Math.min(100, Math.max(0, (parseFloat(val) / 5) * 100));
    const gap = 2, w = size * 5 + gap * 4;
    // baseline at 85% of size keeps glyph within viewBox height
    const base = Math.round(size * 0.85);
    const uid  = 'sr' + (++_starUid);
    const clipW = (pct / 100 * w).toFixed(2);
    let empty = '', filled = '';
    for (let i = 0; i < 5; i++) {
        const x = i * (size + gap);
        empty  += `<text x="${x}" y="${base}" font-size="${size}" fill="#d1d5db">★</text>`;
        filled += `<text x="${x}" y="${base}" font-size="${size}" fill="#f59e0b">★</text>`;
    }
    return `<span class="star-svg-wrap"><svg width="${w}" height="${size}" viewBox="0 0 ${w} ${size}" xmlns="http://www.w3.org/2000/svg" style="display:block;overflow:hidden;"><defs><clipPath id="${uid}"><rect x="0" y="0" width="${clipW}" height="${size}"/></clipPath></defs>${empty}<g clip-path="url(#${uid})">${filled}</g></svg></span>`;
}

// ── User modal ─────────────────────────────────────────────────────────────
let _currentUserName = '', _currentUserId = 0;
function openUserModal(id, fullname, username, email, avatar) {
    document.getElementById('userModalAvatar').src = avatar;
    document.getElementById('userModalName').textContent  = fullname;
    document.getElementById('userModalUsername').textContent = '@' + username;
    document.getElementById('userModalEmail').textContent = email;
    _currentUserName = username; _currentUserId = id;
    document.getElementById('userModal').classList.add('open');
    loadUserReviews(id);
}
function confirmDeleteUser(id, fullname) {
    document.getElementById('deleteUserIdInput').value    = id;
    document.getElementById('deleteUserName').textContent = fullname;
    document.getElementById('deleteUserModal').classList.add('open');
}
document.getElementById('userModal').addEventListener('click',e=>{if(e.target===document.getElementById('userModal'))document.getElementById('userModal').classList.remove('open');});
document.getElementById('deleteUserModal').addEventListener('click',e=>{if(e.target===document.getElementById('deleteUserModal'))document.getElementById('deleteUserModal').classList.remove('open');});

function loadUserReviews(userId, page=1) {
    const list = document.getElementById('userReviewsList');
    list.innerHTML = '<div style="color:var(--gray-400);font-size:12px;padding:8px 0;">Loading...</div>';
    fetch('get_user_reviews.php?user_id='+userId+'&page='+page,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json())
    .then(data=>{
        if (!data.reviews||data.reviews.length===0){list.innerHTML='<div style="color:var(--gray-400);font-size:13px;padding:8px 0;">No reviews made yet.</div>';return;}
        let html='';
        data.reviews.forEach(rev=>{
            const sc=rev.sentiment==='positive'?'badge-positive':(rev.sentiment==='negative'?'badge-negative':'badge-neutral');
            const sl=rev.sentiment?rev.sentiment.charAt(0).toUpperCase()+rev.sentiment.slice(1):'Neutral';
            const rd=JSON.stringify({text:rev.review_text,sentiment:rev.sentiment,date:rev.created_at,rt:rev.rating_teaching,rc:rev.rating_communication,rp:rev.rating_punctuality,rf:rev.rating_fairness,ro:rev.rating_overall,photo:rev.photo||''});
            html+=`<div class="user-rev-item" onclick='openRevDetail(${rd})' style="padding:10px 8px;border-bottom:1px solid var(--gray-100);cursor:pointer;border-radius:6px;transition:background 0.15s;" onmouseover="this.style.background='var(--maroon-pale)'" onmouseout="this.style.background=''">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">
                    <div style="font-weight:600;color:var(--gray-700);font-size:13px;">${esc(rev.faculty_name)}</div>
                    <span class="badge ${sc}" style="font-size:10px;">${sl}</span>
                </div>
                <div style="margin-bottom:3px;">${starsHtml(rev.rating_overall,12)}</div>
                <div style="font-size:12px;color:var(--gray-600);overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.4;">${esc(rev.review_text)}</div>
                <div style="font-size:10px;color:var(--gray-400);margin-top:3px;">${rev.created_at}</div>
            </div>`;
        });
        if(data.total_pages>1){
            html+='<div style="display:flex;align-items:center;justify-content:flex-end;gap:5px;padding-top:10px;flex-wrap:wrap;">';
            html+=`<span style="font-size:11px;color:var(--gray-400);flex:1;">${(page-1)*5+1}–${Math.min(page*5,data.total)} of ${data.total}</span>`;
            if(page>1)html+=`<button onclick="loadUserReviews(${userId},${page-1})" class="btn btn-outline" style="padding:3px 9px;font-size:11px;">←</button>`;
            for(let i=1;i<=data.total_pages;i++)html+=`<button onclick="loadUserReviews(${userId},${i})" class="btn ${i===page?'btn-maroon':'btn-outline'}" style="padding:3px 9px;font-size:11px;">${i}</button>`;
            if(page<data.total_pages)html+=`<button onclick="loadUserReviews(${userId},${page+1})" class="btn btn-outline" style="padding:3px 9px;font-size:11px;">→</button>`;
            html+='</div>';
        }
        list.innerHTML=html;
    })
    .catch(()=>{list.innerHTML='<div style="color:#ef4444;font-size:12px;">Failed to load.</div>';});
}

// ── Detail pop-up modal (faculty reviews + user reviews) ─────────────────
function openRevDetail(revData) {
    let m = document.getElementById('facRevDetail');
    if (!m) {
        m = document.createElement('div');
        m.id = 'facRevDetail';
        m.style.cssText='display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(2px);';
        m.innerHTML=`<div style="background:white;border-radius:14px;width:100%;max-width:500px;max-height:82vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);animation:slideUp 0.22s ease;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--gray-100);position:sticky;top:0;background:white;z-index:2;">
                <div id="frd-title" style="font-size:15px;font-weight:600;color:var(--gray-800);"></div>
                <button onclick="document.getElementById('facRevDetail').style.display='none'" style="width:28px;height:28px;border-radius:50%;background:var(--gray-100);border:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;color:var(--gray-600);">&times;</button>
            </div>
            <div id="frd-body" style="padding:20px;"></div>
        </div>`;
        m.addEventListener('click',function(e){if(e.target===m)m.style.display='none';});
        document.body.appendChild(m);
    }
    const cats=[['Teaching Effectiveness',revData.rt],['Communication Skills',revData.rc],['Punctuality & Availability',revData.rp],['Fairness in Grading',revData.rf],['Overall Satisfaction',revData.ro]];
    const sc=revData.sentiment==='positive'?'badge-positive':(revData.sentiment==='negative'?'badge-negative':'badge-neutral');
    const sl=revData.sentiment?revData.sentiment.charAt(0).toUpperCase()+revData.sentiment.slice(1):'Neutral';
    document.getElementById('frd-title').innerHTML=`Detailed Review <span class="badge ${sc}" style="margin-left:8px;font-size:11px;">${sl}</span>`;
    let body=`<div style="font-size:11px;color:var(--gray-400);margin-bottom:14px;">${esc(revData.date||'')}</div>`;
    body+='<div style="margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--gray-100);"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:8px;">Ratings</div>';
    cats.forEach(([label,val])=>{
        body+=`<div style="display:flex;align-items:center;padding:4px 0;font-size:13px;gap:10px;"><span style="min-width:170px;flex-shrink:0;color:var(--gray-600);">${label}</span>${starsHtml(val,14)}<span style="font-size:11px;color:var(--gray-400);margin-left:4px;">${val||'—'}/5</span></div>`;
    });
    body+='</div>';
    // Photo if present
    if (revData.photo && revData.photo.trim() && revData.photo !== 'null') {
        body+=`<div style="margin-bottom:14px;"><img src="${esc(revData.photo)}" alt="Review photo" style="max-width:100%;max-height:220px;border-radius:10px;object-fit:cover;border:1px solid var(--gray-200);"></div>`;
    }
    body+=`<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:8px;">Review</div><div style="font-size:13px;color:var(--gray-700);line-height:1.7;white-space:pre-wrap;">${esc(revData.text)}</div>`;
    document.getElementById('frd-body').innerHTML=body;
    m.style.display='flex';
}

// ── openModal — pending/approved View button — stars only, with photo ──────
function openModal(text, faculty, user, rt, rc, rp, rf, ro, photo) {
    document.getElementById('modalBody').textContent = text;
    document.getElementById('modalFaculty').textContent = faculty;
    document.getElementById('modalUser').textContent = user;
    const cats=[['Teaching Effectiveness',rt],['Communication Skills',rc],['Punctuality & Availability',rp],['Fairness in Grading',rf],['Overall Satisfaction',ro]];
    const hasRatings = cats.some(c=>c[1]>0);
    const ratingsEl = document.getElementById('modalRatings');
    if (hasRatings) {
        ratingsEl.style.display='';
        ratingsEl.innerHTML = cats.map(([label,val])=>
            `<div style="display:flex;align-items:center;padding:5px 0;font-size:13px;gap:10px;">
                <span style="min-width:170px;flex-shrink:0;color:var(--gray-600);">${label}</span>
                ${starsHtml(val,14)}
                <span style="font-size:11px;color:var(--gray-400);margin-left:4px;">${val}/5</span>
            </div>`
        ).join('')+'<div style="height:10px;"></div>';
    } else { ratingsEl.style.display='none'; }
    const photoWrap = document.getElementById('modalPhotoWrap');
    const photoImg  = document.getElementById('modalPhotoImg');
    if (photo && photo.trim()) { photoImg.src=photo; photoWrap.style.display=''; }
    else { photoWrap.style.display='none'; }
    document.getElementById('reviewModal').classList.add('open');
}
function closeModal(){document.getElementById('reviewModal').classList.remove('open');}
document.getElementById('reviewModal').addEventListener('click',e=>{if(e.target===document.getElementById('reviewModal'))closeModal();});

// ── Faculty modal ──────────────────────────────────────────────────────────
let _currentFacultyId = null;
function openFacultyModal(fid, name, dept) {
    _currentFacultyId=fid;
    document.getElementById('facModalName').textContent=name;
    document.getElementById('facModalDept').textContent=dept||'—';
    document.getElementById('facModalContent').innerHTML='<div style="text-align:center;padding:40px;color:var(--gray-400);">Loading...</div>';
    document.getElementById('facultyModal').classList.add('open');
    setFacTab('reviews'); loadFacultyReviews(fid);
}
function setFacTab(tab){
    document.querySelectorAll('.fac-tab-btn').forEach(t=>{
        const a=t.dataset.tab===tab;
        t.style.background=a?'var(--maroon)':'white';
        t.style.color=a?'white':'var(--gray-600)';
        t.style.borderColor=a?'var(--maroon)':'var(--gray-200)';
    });
}
document.getElementById('facultyModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});

function loadFacultyReviews(fid) {
    document.getElementById('facModalContent').innerHTML='<div style="text-align:center;padding:40px;color:var(--gray-400);">Loading reviews...</div>';
    fetch('get_faculty_reviews.php?faculty_id='+fid,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>{const ct=r.headers.get('content-type')||'';if(!ct.includes('application/json'))throw new Error('Session expired.');return r.json();})
    .then(data=>{if(data.error==='session_expired'){window.location.href='index.php?timeout=1';return;}if(data.error){document.getElementById('facModalContent').innerHTML='<div style="text-align:center;padding:30px;color:#ef4444;">'+esc(data.error)+'</div>';return;}renderFacultyReviews(data);})
    .catch(()=>{document.getElementById('facModalContent').innerHTML='<div style="text-align:center;padding:30px;color:#ef4444;">Failed to load. Please try again.</div>';});
}
function loadFacultySummary(fid) {
    const el=document.getElementById('facModalContent');
    el.innerHTML='<div style="text-align:center;padding:40px;color:var(--gray-400);">Generating AI report...</div>';
    fetch('get_faculty_reviews.php?faculty_id='+fid+'&action=summary',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.text()).then(text=>{
        try{const d=JSON.parse(text);if(d.error==='session_expired'){window.location.href='index.php?timeout=1';return;}if(d.error){el.innerHTML='<div style="padding:20px;color:#ef4444;">Error: '+esc(d.error)+'</div>';return;}
        el.innerHTML=`<div style="padding:4px 0 16px;"><div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;"><div style="width:32px;height:32px;border-radius:50%;background:var(--maroon-pale);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="16" height="16" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div><div><div style="font-size:13px;font-weight:600;color:var(--gray-800);">Individual Faculty Report</div><div style="font-size:11px;color:var(--gray-400);">AI-generated · Powered by Groq</div></div></div><div style="background:var(--gray-100);border-radius:10px;padding:18px;font-size:13px;line-height:1.85;color:var(--gray-700);white-space:pre-wrap;border:1px solid var(--gray-200);">${esc(d.summary)}</div></div>`;}
        catch(e){el.innerHTML='<div style="padding:20px;color:#ef4444;">Server error. Please try again.</div>';}
    }).catch(()=>{el.innerHTML='<div style="padding:20px;color:#ef4444;">Network error. Please try again.</div>';});
}
function deleteFacultyReview(rid,fid) {
    if(!confirm('Delete this review permanently?'))return;
    fetch('get_faculty_reviews.php?faculty_id='+fid+'&action=delete&review_id='+rid,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.success){const card=document.getElementById('fac-rev-'+rid);if(card){card.style.transition='opacity 0.3s';card.style.opacity='0';setTimeout(()=>card.remove(),300);}const c=document.getElementById('facTotalCount');if(c)c.textContent=Math.max(0,parseInt(c.textContent)-1);}
        else alert('Failed to delete review.');
    }).catch(()=>alert('Network error.'));
}

// ── Faculty reviews renderer — fully clickable cards, overall star + snippet ─
function renderFacultyReviews(data) {
    const cats=[{key:'avg_teaching',label:'Teaching Effectiveness'},{key:'avg_communication',label:'Communication Skills'},{key:'avg_punctuality',label:'Punctuality & Availability'},{key:'avg_fairness',label:'Fairness in Grading'},{key:'avg_overall',label:'Overall Satisfaction'}];
    const fid=data.faculty_id;
    let h=`<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px;">
        <div style="background:var(--gray-100);border-radius:10px;padding:14px;text-align:center;border:1px solid var(--gray-200);"><strong style="display:block;font-size:22px;font-weight:700;color:var(--maroon);" id="facTotalCount">${data.total_approved}</strong><span style="font-size:11px;color:var(--gray-400);text-transform:uppercase;">Approved Reviews</span></div>
        <div style="background:var(--gray-100);border-radius:10px;padding:14px;text-align:center;border:1px solid var(--gray-200);"><div style="display:flex;align-items:center;justify-content:center;gap:4px;margin-bottom:2px;"><strong style="font-size:22px;font-weight:700;color:var(--maroon);">${data.avg_overall||'—'}</strong>${data.avg_overall?starsHtml(data.avg_overall,12):''}</div><span style="font-size:11px;color:var(--gray-400);text-transform:uppercase;">Avg Overall</span></div>
        <div style="background:var(--gray-100);border-radius:10px;padding:14px;text-align:center;border:1px solid var(--gray-200);"><strong style="display:block;font-size:22px;font-weight:700;color:var(--maroon);">${data.positive_pct}%</strong><span style="font-size:11px;color:var(--gray-400);text-transform:uppercase;">Positive Sentiment</span></div>
    </div>`;
    if(data.total_approved>0){
        h+='<div style="margin-bottom:18px;padding-bottom:18px;border-bottom:1px solid var(--gray-100);"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:10px;">Rating Breakdown</div>';
        cats.forEach(c=>{const val=parseFloat(data[c.key])||0;const pct=(val/5)*100;h+=`<div style="display:flex;align-items:center;padding:5px 0;font-size:13px;"><span style="min-width:180px;color:var(--gray-600);flex-shrink:0;">${c.label}</span><div style="flex:1;height:7px;background:var(--gray-200);border-radius:4px;margin:0 12px;overflow:hidden;"><div style="height:7px;border-radius:4px;background:#f59e0b;width:${pct}%;transition:width 0.6s ease;"></div></div><span style="min-width:50px;text-align:right;font-weight:600;font-size:13px;color:var(--gray-800);">${val?val.toFixed(1)+'/5':'N/A'}</span></div>`;});
        h+='</div>';
    }
    h+='<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--gray-400);margin-bottom:8px;">Approved Reviews <span style="font-weight:400;text-transform:none;font-size:11px;">(click to expand)</span></div>';
    if(!data.reviews||data.reviews.length===0){h+='<div style="text-align:center;padding:24px;color:var(--gray-400);">No approved reviews yet.</div>';}
    else {
        data.reviews.forEach(rev=>{
            const sc=rev.sentiment==='positive'?'badge-positive':(rev.sentiment==='negative'?'badge-negative':'badge-neutral');
            const sl=rev.sentiment?rev.sentiment.charAt(0).toUpperCase()+rev.sentiment.slice(1):'Neutral';
            const rd=JSON.stringify({text:rev.review_text,sentiment:rev.sentiment,date:rev.created_at,rt:rev.rating_teaching,rc:rev.rating_communication,rp:rev.rating_punctuality,rf:rev.rating_fairness,ro:rev.rating_overall,photo:rev.photo||''});
            h+=`<div id="fac-rev-${rev.id}" style="padding:8px 0 10px;border-bottom:1px solid var(--gray-100);">
                <div style="display:flex;gap:8px;align-items:flex-start;">
                    <div class="fac-rev-card" style="flex:1;" onclick='openRevDetail(${rd})'>
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;flex-wrap:wrap;">
                            <span style="font-size:12px;font-weight:600;color:var(--gray-700);">Anonymous Reviewer</span>
                            <span class="badge ${sc}" style="font-size:10px;">${sl}</span>
                            ${rev.photo?'<svg width="11" height="11" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>':''}
                            <span style="font-size:10px;color:var(--gray-400);margin-left:auto;">${esc(rev.created_at)}</span>
                        </div>
                        ${rev.rating_overall?`<div style="margin-bottom:4px;">${starsHtml(rev.rating_overall,13)}</div>`:''}
                        <div style="font-size:12px;color:var(--gray-600);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">${esc(rev.review_text)}</div>
                    </div>
                    <button onclick="deleteFacultyReview(${rev.id},${fid})" style="flex-shrink:0;background:none;border:1px solid var(--gray-200);border-radius:6px;padding:5px 8px;cursor:pointer;color:var(--gray-400);font-size:11px;display:flex;align-items:center;gap:3px;transition:all 0.18s;" onmouseover="this.style.borderColor='#ef4444';this.style.color='#ef4444';" onmouseout="this.style.borderColor='var(--gray-200)';this.style.color='var(--gray-400)';"><svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg> Delete</button>
                </div>
            </div>`;
        });
    }
    document.getElementById('facModalContent').innerHTML=h;
}
</script>

<script>
// ── Chart ──────────────────────────────────────────────────────────────────
const weeklyData = <?php echo json_encode($weekly); ?>;
let chartMode='reviews', weeklyChart=null;
function switchChart(mode){
    chartMode=mode;
    document.getElementById('chartBtnReviews').className=mode==='reviews'?'btn btn-maroon':'btn btn-outline';
    document.getElementById('chartBtnUsers').className=mode==='users'?'btn btn-maroon':'btn btn-outline';
    ['chartBtnReviews','chartBtnUsers'].forEach(id=>{document.getElementById(id).style.fontSize='11px';document.getElementById(id).style.padding='4px 12px';});
    renderChart();
}
function renderChart(){
    if(weeklyChart)weeklyChart.destroy();
    const ctx=document.getElementById('weeklyChart').getContext('2d');
    const datasets=chartMode==='reviews'?[
        {label:'Created',data:weeklyData.map(d=>d.reviews),backgroundColor:'rgba(249,115,22,0.7)',borderRadius:4},
        {label:'Approved',data:weeklyData.map(d=>d.approved),backgroundColor:'rgba(16,185,129,0.7)',borderRadius:4},
        {label:'Rejected',data:weeklyData.map(d=>d.rejected),backgroundColor:'rgba(239,68,68,0.7)',borderRadius:4}
    ]:[{label:'New Users',data:weeklyData.map(d=>d.users),backgroundColor:'rgba(99,102,241,0.7)',borderRadius:4}];
    weeklyChart=new Chart(ctx,{type:'bar',data:{labels:weeklyData.map(d=>d.label),datasets},options:{responsive:true,plugins:{legend:{position:'top',labels:{font:{size:11},boxWidth:12}}},scales:{x:{grid:{display:false},ticks:{font:{size:11}}},y:{beginAtZero:true,ticks:{stepSize:1,font:{size:11}},grid:{color:'#f3f4f6'}}}}});
}
document.addEventListener('DOMContentLoaded',()=>{
    if(document.getElementById('weeklyChart')){
        const s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';s.onload=renderChart;document.head.appendChild(s);
    }
});

// ── Summary ────────────────────────────────────────────────────────────────
function generateFacultySummary(){
    const btn=document.getElementById('summaryBtn'),el=document.getElementById('aiSummaryText');
    const month=document.getElementById('summaryMonth').value,year=document.getElementById('summaryYear').value;
    btn.disabled=true;btn.innerHTML='Generating...';
    el.innerHTML='<span style="color:var(--gray-400);">Analyzing...</span>';
    fetch('faculty_summary.php?month='+month+'&year='+year,{credentials:'include',headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.text()).then(text=>{
        try{const d=JSON.parse(text);if(d.error==='session_expired'){window.location.href='index.php?timeout=1';return;}if(d.error){el.innerHTML='<span style="color:#ef4444;">Error: '+esc(d.error)+'</span>';}else{el.style.whiteSpace='pre-wrap';el.textContent=d.summary||'No summary.';}
        }catch(e){el.innerHTML='<span style="color:#ef4444;">Server error — check faculty_summary.php is deployed.</span>';console.error(text.substring(0,300));}
        btn.innerHTML='<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Regenerate';btn.disabled=false;
    }).catch(err=>{el.innerHTML='<span style="color:#ef4444;">Network error: '+esc(err.message)+'</span>';btn.innerHTML='Generate';btn.disabled=false;});
}

// ── Table filter + pagination ──────────────────────────────────────────────
const PER_PAGE=5, tableState={};
function filterTable(tbodyId,searchId,filterId){
    const tbody=document.getElementById(tbodyId);if(!tbody)return;
    const search=searchId?document.getElementById(searchId).value.toLowerCase():'';
    const filter=filterId?document.getElementById(filterId).value.toLowerCase():'';
    [...tbody.querySelectorAll('tr:not(.empty-state-row):not(.no-results-row)')].forEach(row=>{
        const text=row.textContent.toLowerCase();
        row.dataset.visible=((!search||text.includes(search))&&(!filter||text.includes(filter)))?'true':'false';
    });
    tableState[tbodyId]=1;renderPage(tbodyId);
}
function renderPage(tbodyId){
    const tbody=document.getElementById(tbodyId);if(!tbody)return;
    const rows=[...tbody.querySelectorAll('tr:not(.empty-state-row):not(.no-results-row)')];
    const visible=rows.filter(r=>r.dataset.visible!=='false');
    const page=tableState[tbodyId]||1,total=visible.length,totalPages=Math.ceil(total/PER_PAGE);
    const start=(page-1)*PER_PAGE,end=start+PER_PAGE;
    rows.forEach(r=>r.style.display='none');
    visible.slice(start,end).forEach(r=>r.style.display='');
    ['select_all_reviews','select_all_approved','select_all_users'].forEach(id=>{const el=document.getElementById(id);if(el)el.checked=false;});
    let noRes=tbody.querySelector('.no-results-row');
    //if(total===0){if(!noRes){noRes=document.createElement('tr');noRes.className='no-results-row';const cols=tbody.closest('table').querySelector('thead tr').children.length;noRes.innerHTML=`<td colspan="${cols}" style="text-align:center;padding:24px;color:var(--gray-400);font-size:13px;">No results found.</td>`;tbody.appendChild(noRes);}noRes.style.display='';}
    //else{if(noRes)noRes.style.display='none';}
    const pagId=tbodyId.replace('-tbody','-pag');let pag=document.getElementById(pagId);
    if(!pag){pag=document.createElement('div');pag.id=pagId;pag.style.cssText='display:flex;align-items:center;justify-content:flex-end;gap:6px;padding:12px 20px;border-top:1px solid var(--gray-100);flex-wrap:wrap;';tbody.closest('table').after(pag);}
    pag.innerHTML='';
    if(totalPages<=1&&total>0)return;if(total===0)return;
    const info=document.createElement('span');info.style.cssText='font-size:12px;color:var(--gray-400);margin-right:6px;flex:1;';info.textContent=`${start+1}–${Math.min(end,total)} of ${total}`;pag.appendChild(info);
    const prev=document.createElement('button');prev.className='btn btn-outline';prev.style.padding='4px 10px';prev.innerHTML='←';prev.disabled=page===1;prev.onclick=()=>{tableState[tbodyId]=page-1;renderPage(tbodyId);};pag.appendChild(prev);
    for(let i=1;i<=totalPages;i++){const btn=document.createElement('button');btn.className='btn '+(i===page?'btn-maroon':'btn-outline');btn.style.padding='4px 10px';btn.textContent=i;btn.onclick=()=>{tableState[tbodyId]=i;renderPage(tbodyId);};pag.appendChild(btn);}
    const next=document.createElement('button');next.className='btn btn-outline';next.style.padding='4px 10px';next.innerHTML='→';next.disabled=page===totalPages;next.onclick=()=>{tableState[tbodyId]=page+1;renderPage(tbodyId);};pag.appendChild(next);
}

// ── Bulk helpers ───────────────────────────────────────────────────────────
function toggleUserBulk(){const c=document.querySelectorAll('.user_checkbox:checked');document.getElementById('bulk_actions').classList.toggle('show',c.length>0);document.getElementById('selected_count').textContent=c.length+' user'+(c.length!==1?'s':'')+' selected';}
function updateReviewBulk(){const c=document.querySelectorAll('.review_cb:checked');document.getElementById('review_bulk_bar').classList.toggle('show',c.length>0);document.getElementById('review_selected_count').textContent=c.length+' review'+(c.length!==1?'s':'')+' selected';}
function updateApprovedBulk(){const c=document.querySelectorAll('.approved_cb:checked');document.getElementById('approved_bulk_bar').classList.toggle('show',c.length>0);document.getElementById('approved_selected_count').textContent=c.length+' review'+(c.length!==1?'s':'')+' selected';}

// ── Sidebar smooth scroll ──────────────────────────────────────────────────
document.querySelectorAll('.sidebar a[href^="#"]').forEach(link=>{
    link.addEventListener('click',function(e){e.preventDefault();const el=document.getElementById(this.getAttribute('href').slice(1));if(el)el.scrollIntoView({behavior:'smooth',block:'start'});});
});

document.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('.admin-table tbody tr:not(.empty-state-row)').forEach(r=>r.dataset.visible='true');
    ['users-tbody','faculties-tbody','pending-tbody','approved-tbody'].forEach(id=>{tableState[id]=1;renderPage(id);});

    // Sync pending badge
    const realPending=document.querySelectorAll('#pending-tbody tr:not(.empty-state-row)').length;
    const nb=document.getElementById('pendingNavBadge');if(nb){if(realPending===0)nb.style.display='none';else nb.textContent=realPending;}
    const sb=document.getElementById('pendingSecBadge');if(sb){if(realPending===0)sb.style.display='none';else sb.textContent=realPending;}
    const sv=document.querySelector('.stat-card.s-pending .stat-value');if(sv)sv.textContent=realPending;

    // Edit users toggle
    const editBtn=document.getElementById('edit_users_btn');
    if(editBtn){
        editBtn.addEventListener('click',function(){
            const th=document.getElementById('select_all_th'),tds=document.querySelectorAll('.checkbox_td'),showing=th.style.display!=='none';
            th.style.display=showing?'none':'table-cell';tds.forEach(td=>td.style.display=showing?'none':'table-cell');
            this.innerHTML=showing?'<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit':'✕ Cancel';
            if(showing){document.querySelectorAll('.user_checkbox').forEach(c=>c.checked=false);document.getElementById('select_all_users').checked=false;toggleUserBulk();}
        });
        document.getElementById('select_all_users').addEventListener('change',function(){document.querySelectorAll('.user_checkbox').forEach(c=>c.checked=this.checked);toggleUserBulk();});
        document.querySelectorAll('.user_checkbox').forEach(c=>c.addEventListener('change',toggleUserBulk));
    }

    // Hash scroll
    if('scrollRestoration' in history)history.scrollRestoration='manual';
    const hash=window.location.hash;
    if(hash){const el=document.getElementById(hash.slice(1));if(el)setTimeout(()=>el.scrollIntoView({behavior:'auto',block:'start'}),60);}
});
</script>

<!-- ── Add Faculty Modal ──────────────────────────────────────────────────── -->
<div class="modal-overlay" id="addFacultyModal">
    <div class="modal-box" style="max-width:500px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:36px;height:36px;border-radius:50%;background:var(--maroon-pale);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="18" height="18" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>
                <div><h3 style="color:var(--gray-800);">Add New Faculty</h3><div style="font-size:12px;color:var(--gray-400);">Fill in the details below.</div></div>
            </div>
            <button class="modal-close" onclick="closeAddFacultyModal()">&times;</button>
        </div>
        <form method="POST" id="addFacultyForm" enctype="multipart/form-data" onsubmit="return validateAddFaculty()">
            <div style="padding:22px 24px;">
                <div id="afAlert" class="af-alert"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span id="afAlertText"></span></div>
                <!-- Photo upload -->
                <div class="af-group">
                    <label>Profile Photo <span style="color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <div style="display:flex;align-items:center;gap:14px;">
                        <img id="afPhotoPreview" src="https://ui-avatars.com/api/?name=Faculty&background=8B0000&color=fff&size=64" class="fac-photo-preview" alt="">
                        <div>
                            <input type="file" name="faculty_photo" id="afPhoto" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="previewFacultyPhoto(this,'afPhotoPreview')">
                            <button type="button" class="btn btn-outline" style="font-size:12px;" onclick="document.getElementById('afPhoto').click()"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>Upload Photo</button>
                            <div style="font-size:11px;color:var(--gray-400);margin-top:4px;">JPG, PNG, WEBP · Max 3MB</div>
                        </div>
                    </div>
                </div>
                <div class="af-group">
                    <label>Full Name <span>*</span></label>
                    <div class="af-field"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input type="text" name="faculty_name" id="afName" class="af-input" placeholder="e.g. Dr. Juan dela Cruz" required></div>
                </div>
                <div class="af-group">
                    <label>Department <span>*</span></label>
                    <div class="af-field"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg><input type="text" name="faculty_dept" id="afDept" class="af-input" placeholder="Type or select..." list="afDeptList" required autocomplete="off"></div>
                    <datalist id="afDeptList"><?php $af_d=mysqli_query($conn,"SELECT DISTINCT department FROM faculties WHERE department IS NOT NULL AND department!='' ORDER BY department ASC");$af_arr=[];while($ad=mysqli_fetch_assoc($af_d)){$af_arr[]=$ad['department'];echo '<option value="'.htmlspecialchars($ad['department']).'">';}?></datalist>
                    <?php if(!empty($af_arr)): ?><div style="font-size:11px;color:var(--gray-400);margin-top:5px;margin-bottom:5px;">Quick select:</div>
                    <div class="af-chips"><?php foreach($af_arr as $afd): ?><button type="button" class="af-chip" onclick="document.getElementById('afDept').value='<?php echo htmlspecialchars(addslashes($afd)); ?>'"><?php echo htmlspecialchars($afd); ?></button><?php endforeach; ?><button type="button" class="af-chip af-chip-new" onclick="document.getElementById('afDept').value='';document.getElementById('afDept').focus();">+ New dept</button></div>
                    <?php else: ?><div style="font-size:11px;color:var(--gray-400);margin-top:4px;">No departments yet — type a new one.</div><?php endif; ?>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;padding:14px 24px;border-top:1px solid var(--gray-100);background:var(--gray-100);">
                <button type="button" class="btn btn-outline" onclick="closeAddFacultyModal()">Cancel</button>
                <button type="submit" name="add_faculty_modal" class="btn btn-maroon"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Faculty</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Faculty Modal ─────────────────────────────────────────────────── -->
<div class="modal-overlay" id="editFacultyModal">
    <div class="modal-box" style="max-width:500px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:36px;height:36px;border-radius:50%;background:#dbeafe;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="18" height="18" fill="none" stroke="#1e40af" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div>
                <div><h3 style="color:var(--gray-800);">Edit Faculty</h3><div style="font-size:12px;color:var(--gray-400);">Update faculty details.</div></div>
            </div>
            <button class="modal-close" onclick="closeEditFacultyModal()">&times;</button>
        </div>
        <form method="POST" id="editFacultyForm" enctype="multipart/form-data" onsubmit="return validateEditFaculty()">
            <input type="hidden" name="ef_faculty_id" id="efFacultyId">
            <div style="padding:22px 24px;">
                <div id="efAlert" class="af-alert"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span id="efAlertText"></span></div>
                <div class="af-group">
                    <label>Profile Photo <span style="color:var(--gray-400);font-weight:400;">(optional — replaces current)</span></label>
                    <div style="display:flex;align-items:center;gap:14px;">
                        <img id="efPhotoPreview" src="" class="fac-photo-preview" alt="">
                        <div>
                            <input type="file" name="ef_faculty_photo" id="efPhoto" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="previewFacultyPhoto(this,'efPhotoPreview')">
                            <button type="button" class="btn btn-outline" style="font-size:12px;" onclick="document.getElementById('efPhoto').click()"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>Change Photo</button>
                            <div style="font-size:11px;color:var(--gray-400);margin-top:4px;">JPG, PNG, WEBP · Max 3MB</div>
                        </div>
                    </div>
                </div>
                <div class="af-group">
                    <label>Full Name <span>*</span></label>
                    <div class="af-field"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input type="text" name="ef_name" id="efName" class="af-input" placeholder="e.g. Dr. Juan dela Cruz" required></div>
                </div>
                <div class="af-group">
                    <label>Department <span>*</span></label>
                    <div class="af-field"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg><input type="text" name="ef_dept" id="efDept" class="af-input" placeholder="e.g. College of Engineering" list="efDeptList" required autocomplete="off"></div>
                    <datalist id="efDeptList"><?php $ef_d=mysqli_query($conn,"SELECT DISTINCT department FROM faculties WHERE department IS NOT NULL AND department!='' ORDER BY department ASC");$ef_arr=[];while($efd=mysqli_fetch_assoc($ef_d)){$ef_arr[]=$efd['department'];echo '<option value="'.htmlspecialchars($efd['department']).'">';}?></datalist>
                    <?php if(!empty($ef_arr)): ?><div style="font-size:11px;color:var(--gray-400);margin-top:5px;margin-bottom:5px;">Quick select:</div>
                    <div class="af-chips"><?php foreach($ef_arr as $efd): ?><button type="button" class="af-chip" onclick="document.getElementById('efDept').value='<?php echo htmlspecialchars(addslashes($efd)); ?>'"><?php echo htmlspecialchars($efd); ?></button><?php endforeach; ?><button type="button" class="af-chip af-chip-new" onclick="document.getElementById('efDept').value='';document.getElementById('efDept').focus();">+ New dept</button></div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;padding:14px 24px;border-top:1px solid var(--gray-100);background:var(--gray-100);">
                <button type="button" class="btn btn-outline" onclick="closeEditFacultyModal()">Cancel</button>
                <button type="submit" name="edit_faculty_modal" class="btn btn-maroon"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Faculty modal JS ───────────────────────────────────────────────────────
function previewFacultyPhoto(input, previewId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { document.getElementById(previewId).src = e.target.result; };
        reader.readAsDataURL(input.files[0]);
    }
}
function closeAddFacultyModal() {
    document.getElementById('addFacultyModal').classList.remove('open');
    document.getElementById('afName').value='';document.getElementById('afDept').value='';
    document.getElementById('afPhoto').value='';
    document.getElementById('afPhotoPreview').src='https://ui-avatars.com/api/?name=Faculty&background=8B0000&color=fff&size=64';
    document.getElementById('afAlert').style.display='none';
}
function validateAddFaculty() {
    const name=document.getElementById('afName').value.trim(),dept=document.getElementById('afDept').value.trim();
    if(!name||!dept){document.getElementById('afAlertText').textContent=!name?'Faculty name is required.':'Department is required.';document.getElementById('afAlert').style.display='flex';return false;}
    document.getElementById('afAlert').style.display='none';return true;
}
function openEditFacultyModal(id, name, dept, photo) {
    document.getElementById('efFacultyId').value=id;
    document.getElementById('efName').value=name;
    document.getElementById('efDept').value=dept;
    document.getElementById('efPhotoPreview').src=photo||('https://ui-avatars.com/api/?name='+encodeURIComponent(name)+'&background=8B0000&color=fff&size=64');
    document.getElementById('efAlert').style.display='none';
    document.getElementById('editFacultyModal').classList.add('open');
}
function closeEditFacultyModal() {
    document.getElementById('editFacultyModal').classList.remove('open');
    document.getElementById('efAlert').style.display='none';
}
function validateEditFaculty() {
    const name=document.getElementById('efName').value.trim(),dept=document.getElementById('efDept').value.trim();
    if(!name||!dept){document.getElementById('efAlertText').textContent=!name?'Faculty name is required.':'Department is required.';document.getElementById('efAlert').style.display='flex';return false;}
    document.getElementById('efAlert').style.display='none';return true;
}
document.getElementById('addFacultyModal').addEventListener('click',function(e){if(e.target===this)closeAddFacultyModal();});
document.getElementById('editFacultyModal').addEventListener('click',function(e){if(e.target===this)closeEditFacultyModal();});

<?php if(!empty($add_faculty_error)): ?>
document.addEventListener('DOMContentLoaded',()=>{document.getElementById('addFacultyModal').classList.add('open');document.getElementById('afAlertText').textContent='<?php echo htmlspecialchars($add_faculty_error); ?>';document.getElementById('afAlert').style.display='flex';});
<?php endif; ?>
<?php if(!empty($edit_faculty_error)&&!empty($edit_faculty_prefill)): ?>
document.addEventListener('DOMContentLoaded',()=>{openEditFacultyModal(<?php echo intval($edit_faculty_prefill['id']); ?>,'<?php echo htmlspecialchars(addslashes($edit_faculty_prefill['name'])); ?>','<?php echo htmlspecialchars(addslashes($edit_faculty_prefill['dept'])); ?>','');document.getElementById('efAlertText').textContent='<?php echo htmlspecialchars($edit_faculty_error); ?>';document.getElementById('efAlert').style.display='flex';});
<?php endif; ?>
</script>

<script src="session_timeout.js"></script>
</body>
</html>