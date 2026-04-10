<?php
include "config.php";
include "session_check.php";

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$user_id = $_SESSION['user_id'];

$me = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, fullname, profile_pic FROM users WHERE id='$user_id' LIMIT 1"));
if ($me['role'] !== 'admin') { header("Location: dashboard.php"); exit(); }

$admin_avatar = !empty($me['profile_pic']) && file_exists($me['profile_pic'])
    ? $me['profile_pic']
    : 'https://ui-avatars.com/api/?name=' . urlencode($me['fullname']) . '&background=6B0000&color=fff&size=80';

$errors  = [];
$success = false;

if (isset($_POST['add_faculty'])) {
    $name       = trim(mysqli_real_escape_string($conn, $_POST['name']       ?? ''));
    $department = trim(mysqli_real_escape_string($conn, $_POST['department'] ?? ''));

    if (empty($name))       $errors[] = "Faculty name is required.";
    if (empty($department)) $errors[] = "Department is required.";

    if (empty($errors)) {
        $dup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM faculties WHERE name='$name' AND department='$department' LIMIT 1"));
        if ($dup) $errors[] = "A faculty with this name already exists in that department.";
    }

    if (empty($errors)) {
        mysqli_query($conn, "INSERT INTO faculties (name, department) VALUES ('$name', '$department')");
        header("Location: admin_dashboard.php?added=1#faculties");
        exit();
    }
}

$dept_res        = mysqli_query($conn, "SELECT DISTINCT department FROM faculties WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$existing_depts  = [];
while ($d = mysqli_fetch_assoc($dept_res)) $existing_depts[] = $d['department'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Faculty — AnonymousReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">AnonymousReview<br><span style="font-size:11px;font-family:'DM Sans',sans-serif;font-weight:400;opacity:0.6;">Admin Panel</span></div>
    <img src="<?php echo htmlspecialchars($admin_avatar); ?>" class="sidebar-avatar" alt="Admin">
    <div class="sidebar-name"><?php echo htmlspecialchars($me['fullname']); ?></div>
    <div class="sidebar-role">Administrator</div>
    <nav>
        <div class="nav-label">Manage</div>
        <a href="admin_dashboard.php#overview">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Overview
        </a>
        <a href="admin_dashboard.php#users">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            Users
        </a>
        <a href="admin_dashboard.php#faculties" class="active">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            Faculties
        </a>
        <a href="admin_dashboard.php#pending">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Pending Reviews
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            Logout
        </a>
    </div>
</div>

<div class="main" style="display:flex;align-items:flex-start;justify-content:center;">
    <div class="card">
        <div class="card-header">
            <div style="width:44px;height:44px;border-radius:50%;background:var(--maroon-pale);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="22" height="22" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            </div>
            <div>
                <h1>Add New Faculty</h1>
                <p>Fill in the details to add a new faculty member to the system.</p>
            </div>
        </div>

        <form method="POST">
            <div class="card-body">

                <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Full Name <span style="color:#ef4444">*</span></label>
                    <div class="field-icon">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" name="name"
                               placeholder="e.g. Dr. Juan dela Cruz"
                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                               required autofocus>
                    </div>
                    <div class="form-hint">Enter the faculty member's full name including title (Dr., Prof., etc.)</div>
                </div>

                <div class="form-group">
                    <label>Department <span style="color:#ef4444">*</span></label>
                    <div class="field-icon">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                        <input type="text" name="department" id="deptInput"
                               placeholder="e.g. College of Engineering"
                               value="<?php echo htmlspecialchars($_POST['department'] ?? ''); ?>"
                               list="deptList" required>
                    </div>
                    <datalist id="deptList">
                        <?php foreach ($existing_depts as $d): ?>
                        <option value="<?php echo htmlspecialchars($d); ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <?php if (!empty($existing_depts)): ?>
                    <div class="form-hint" style="margin-bottom:6px;">Or click an existing department:</div>
                    <div class="dept-suggestions">
                        <?php foreach ($existing_depts as $d): ?>
                        <button type="button" class="dept-chip"
                                onclick="document.getElementById('deptInput').value='<?php echo htmlspecialchars(addslashes($d)); ?>'">
                            <?php echo htmlspecialchars($d); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="form-hint">No departments yet — type a new one above.</div>
                    <?php endif; ?>
                </div>

            </div>

            <div class="card-footer">
                <a href="admin_dashboard.php#faculties" class="btn-secondary">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Cancel
                </a>
                <button type="submit" name="add_faculty" class="btn-primary">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Faculty
                </button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/session_timeout.js"></script>
</body>
</html>