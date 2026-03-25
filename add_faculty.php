<?php
include "config.php";
include "session_check.php";

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$user_id = $_SESSION['user_id'];

// Check admin
$me = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, fullname, profile_pic FROM users WHERE id='$user_id' LIMIT 1"));
if ($me['role'] !== 'admin') { header("Location: dashboard.php"); exit(); }

$admin_avatar = !empty($me['profile_pic']) && file_exists($me['profile_pic'])
    ? $me['profile_pic']
    : 'https://ui-avatars.com/api/?name=' . urlencode($me['fullname']) . '&background=6B0000&color=fff&size=80';

$errors  = [];
$success = false;

if (isset($_POST['add_faculty'])) {
    $name       = trim(mysqli_real_escape_string($conn, $_POST['name'] ?? ''));
    $department = trim(mysqli_real_escape_string($conn, $_POST['department'] ?? ''));
    $email      = trim(mysqli_real_escape_string($conn, $_POST['email'] ?? ''));
    $position   = trim(mysqli_real_escape_string($conn, $_POST['position'] ?? ''));

    if (empty($name))       $errors[] = "Faculty name is required.";
    if (empty($department)) $errors[] = "Department is required.";

    // Check duplicate
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

// Get existing departments for datalist
$dept_res = mysqli_query($conn, "SELECT DISTINCT department FROM faculties WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$existing_depts = [];
while ($d = mysqli_fetch_assoc($dept_res)) $existing_depts[] = $d['department'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Faculty - AnonymousReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root {
    --maroon:#8B0000;--maroon-light:#a30000;--maroon-pale:#fff5f5;
    --sidebar-w:240px;--gray-100:#f3f4f6;--gray-200:#e5e7eb;
    --gray-400:#9ca3af;--gray-600:#4b5563;--gray-800:#1f2937;
    --shadow-sm:0 1px 3px rgba(0,0,0,0.08);--shadow-md:0 4px 16px rgba(0,0,0,0.10);
    --radius:14px;--radius-sm:8px;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--gray-100);color:var(--gray-800);min-height:100vh;}
.sidebar{position:fixed;left:0;top:0;width:var(--sidebar-w);height:100%;background:var(--maroon);display:flex;flex-direction:column;padding:28px 16px 20px;box-shadow:2px 0 12px rgba(139,0,0,0.18);z-index:100;}
.sidebar-brand{font-family:'Playfair Display',serif;font-size:17px;color:white;text-align:center;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,0.15);line-height:1.4;}
.sidebar-avatar{width:70px;height:70px;border-radius:50%;border:3px solid rgba(255,255,255,0.4);display:block;margin:0 auto 10px;object-fit:cover;}
.sidebar-name{text-align:center;color:white;font-size:14px;font-weight:600;margin-bottom:4px;}
.sidebar-role{text-align:center;color:rgba(255,255,255,0.6);font-size:11px;margin-bottom:24px;text-transform:uppercase;letter-spacing:1px;}
.sidebar nav{flex:1;}
.nav-label{font-size:10px;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,0.4);padding:0 10px;margin-bottom:6px;margin-top:16px;}
.sidebar a{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--radius-sm);color:rgba(255,255,255,0.85);text-decoration:none;font-size:14px;font-weight:500;transition:all 0.2s;margin-bottom:2px;}
.sidebar a:hover,.sidebar a.active{background:rgba(255,255,255,0.15);color:white;}
.sidebar-footer{border-top:1px solid rgba(255,255,255,0.15);padding-top:14px;margin-top:auto;}
.main{margin-left:var(--sidebar-w);padding:32px;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;}
.card{background:white;border-radius:var(--radius);box-shadow:var(--shadow-md);border:1px solid var(--gray-200);width:100%;max-width:580px;overflow:hidden;}
.card-header{padding:24px 28px;border-bottom:1px solid var(--gray-100);display:flex;align-items:center;gap:14px;}
.card-header h1{font-family:'Playfair Display',serif;font-size:22px;color:var(--gray-800);}
.card-header p{font-size:13px;color:var(--gray-400);margin-top:2px;}
.card-body{padding:28px;}
.form-group{margin-bottom:20px;}
.form-group label{display:block;font-size:13px;font-weight:600;color:var(--gray-700);margin-bottom:7px;}
.form-group label span{color:#ef4444;margin-left:2px;}
.form-group input,.form-group select,.form-group textarea{
    width:100%;padding:11px 14px;border:1.5px solid var(--gray-200);
    border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;
    font-size:14px;color:var(--gray-800);outline:none;transition:border-color 0.2s;
    background:white;
}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--maroon);box-shadow:0 0 0 3px rgba(139,0,0,0.07);}
.form-group textarea{resize:vertical;min-height:80px;}
.form-hint{font-size:11px;color:var(--gray-400);margin-top:5px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.alert{padding:12px 16px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:20px;display:flex;align-items:flex-start;gap:8px;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.card-footer{padding:16px 28px;border-top:1px solid var(--gray-100);display:flex;gap:12px;justify-content:flex-end;background:var(--gray-100);}
.btn-primary{background:var(--maroon);color:white;border:none;padding:10px 28px;border-radius:20px;font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background 0.2s;display:inline-flex;align-items:center;gap:8px;}
.btn-primary:hover{background:var(--maroon-light);}
.btn-secondary{background:white;color:var(--gray-600);border:1px solid var(--gray-200);padding:10px 22px;border-radius:20px;font-size:14px;cursor:pointer;font-family:'DM Sans',sans-serif;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;}
.btn-secondary:hover{border-color:var(--maroon);color:var(--maroon);}
.dept-suggestions{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.dept-chip{padding:4px 12px;background:var(--maroon-pale);color:var(--maroon);border:1px solid rgba(139,0,0,0.15);border-radius:12px;font-size:12px;cursor:pointer;transition:all 0.15s;font-family:'DM Sans',sans-serif;}
.dept-chip:hover{background:var(--maroon);color:white;}
.field-icon{position:relative;}
.field-icon input{padding-left:38px;}
.field-icon svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--gray-400);}
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">AnonymousReview<br><span style="font-size:11px;font-family:'DM Sans',sans-serif;font-weight:400;opacity:0.6;">Admin Panel</span></div>
    <img src="<?php echo htmlspecialchars($admin_avatar); ?>" class="sidebar-avatar" alt="Admin">
    <div class="sidebar-name"><?php echo htmlspecialchars($me['fullname']); ?></div>
    <div class="sidebar-role">Administrator</div>
    <nav>
        <div class="nav-label">Manage</div>
        <a href="admin_dashboard.php#overview"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Overview</a>
        <a href="admin_dashboard.php#users"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Users</a>
        <a href="admin_dashboard.php#faculties" class="active"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Faculties</a>
        <a href="admin_dashboard.php#pending"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Pending Reviews</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>Logout</a>
    </div>
</div>

<div class="main">
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
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
                </div>
                <?php endif; ?>

                <!-- Name -->
                <div class="form-group">
                    <label>Full Name <span>*</span></label>
                    <div class="field-icon">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" name="name" placeholder="e.g. Dr. Juan dela Cruz"
                            value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required autofocus>
                    </div>
                    <div class="form-hint">Enter the faculty member's full name including title (Dr., Prof., etc.)</div>
                </div>

                <!-- Department -->
                <div class="form-group">
                    <label>Department <span>*</span></label>
                    <div class="field-icon">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                        <input type="text" name="department" id="deptInput" placeholder="e.g. College of Engineering"
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
                        <button type="button" class="dept-chip" onclick="document.getElementById('deptInput').value='<?php echo htmlspecialchars(addslashes($d)); ?>'">
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

<script src="session_timeout.js"></script>
</body>
</html>