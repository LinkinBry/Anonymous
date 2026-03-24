<?php
include "config.php";
include "session_check.php";

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$user_id = $_SESSION['user_id'];

// Handle profile update
if (isset($_POST['update_profile'])) {
    $fullname = mysqli_real_escape_string($conn, trim($_POST['fullname']));
    $username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $email    = mysqli_real_escape_string($conn, trim($_POST['email']));
    $errors   = [];

    // Check username taken by someone else
    $check = mysqli_query($conn, "SELECT id FROM users WHERE username='$username' AND id!='$user_id' LIMIT 1");
    if (mysqli_num_rows($check) > 0) $errors[] = "Username is already taken.";

    // Check email taken by someone else
    $check2 = mysqli_query($conn, "SELECT id FROM users WHERE email='$email' AND id!='$user_id' LIMIT 1");
    if (mysqli_num_rows($check2) > 0) $errors[] = "Email is already in use.";

    // Handle password change
    $pw_sql = '';
    if (!empty($_POST['new_password'])) {
        if (strlen($_POST['new_password']) < 6) {
            $errors[] = "Password must be at least 6 characters.";
        } else {
            $hashed = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
            $hashed = mysqli_real_escape_string($conn, $hashed);
            $pw_sql = ", password='$hashed'";
        }
    }

    // Handle profile picture upload
    $pic_sql = '';
    if (!empty($_FILES['profile_pic']['name'])) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $ftype   = mime_content_type($_FILES['profile_pic']['tmp_name']);
        if (!in_array($ftype, $allowed)) {
            $errors[] = "Only JPG, PNG, GIF, WEBP images are allowed.";
        } elseif ($_FILES['profile_pic']['size'] > 2 * 1024 * 1024) {
            $errors[] = "Image must be under 2MB.";
        } else {
            $ext      = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $filename = 'uploads/profile_' . $user_id . '_' . time() . '.' . $ext;
            if (!is_dir('uploads')) mkdir('uploads', 0755, true);
            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $filename)) {
                $pic_sql = ", profile_pic='$filename'";
            } else {
                $errors[] = "Failed to upload image.";
            }
        }
    }

    if (empty($errors)) {
        mysqli_query($conn, "UPDATE users SET fullname='$fullname', username='$username', email='$email' $pw_sql $pic_sql WHERE id='$user_id'");
        $_SESSION['username'] = $username;
        $_SESSION['fullname'] = $fullname;
        header("Location: profile.php?updated=1");
        exit();
    }
}

// Fetch user data
$res  = mysqli_query($conn, "SELECT fullname, username, email, profile_pic, created_at FROM users WHERE id='$user_id' LIMIT 1");
$user = mysqli_fetch_assoc($res);

// Review stats
$total    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE user_id='$user_id'"))['c'];
$approved = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE user_id='$user_id' AND status='approved'"))['c'];
$pending  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE user_id='$user_id' AND status='pending'"))['c'];

$avatar = !empty($user['profile_pic']) && file_exists($user['profile_pic'])
    ? $user['profile_pic']
    : 'https://ui-avatars.com/api/?name=' . urlencode($user['fullname']) . '&background=8B0000&color=fff&size=120';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile - AnonymousReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root {
    --maroon: #8B0000; --maroon-light: #a30000; --maroon-pale: #fff5f5;
    --sidebar-w: 240px; --gray-100: #f3f4f6; --gray-200: #e5e7eb;
    --gray-400: #9ca3af; --gray-600: #4b5563; --gray-800: #1f2937;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08); --shadow-md: 0 4px 16px rgba(0,0,0,0.10);
    --radius: 14px; --radius-sm: 8px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', sans-serif; background: var(--gray-100); color: var(--gray-800); min-height: 100vh; }

/* Sidebar */
.sidebar { position:fixed; left:0; top:0; width:var(--sidebar-w); height:100%; background:var(--maroon); display:flex; flex-direction:column; padding:28px 16px 20px; box-shadow:2px 0 12px rgba(139,0,0,0.18); z-index:100; }
.sidebar-brand { font-family:'Playfair Display',serif; font-size:17px; color:white; text-align:center; margin-bottom:24px; padding-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.15); }
.sidebar-avatar { width:70px; height:70px; border-radius:50%; border:3px solid rgba(255,255,255,0.4); display:block; margin:0 auto 10px; object-fit:cover; }
.sidebar-name { text-align:center; color:white; font-size:14px; font-weight:600; margin-bottom:6px; }
.sidebar-role { text-align:center; color:rgba(255,255,255,0.6); font-size:11px; margin-bottom:24px; text-transform:uppercase; letter-spacing:1px; }
.sidebar nav { flex:1; }
.nav-label { font-size:10px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.4); padding:0 10px; margin-bottom:6px; margin-top:16px; }
.sidebar a { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:var(--radius-sm); color:rgba(255,255,255,0.85); text-decoration:none; font-size:14px; font-weight:500; transition:all 0.2s; margin-bottom:2px; }
.sidebar a:hover, .sidebar a.active { background:rgba(255,255,255,0.15); color:white; }
.sidebar-footer { border-top:1px solid rgba(255,255,255,0.15); padding-top:14px; }

/* Main */
.main { margin-left:var(--sidebar-w); padding:32px; min-height:100vh; }
.page-header { margin-bottom:28px; }
.page-header h1 { font-family:'Playfair Display',serif; font-size:26px; color:var(--gray-800); }
.page-header p { color:var(--gray-400); font-size:14px; margin-top:3px; }

/* Profile layout */
.profile-grid { display:grid; grid-template-columns:300px 1fr; gap:24px; align-items:start; }

/* Profile card */
.profile-card { background:white; border-radius:var(--radius); padding:28px; box-shadow:var(--shadow-sm); border:1px solid var(--gray-200); text-align:center; }
.avatar-wrap { position:relative; display:inline-block; margin-bottom:16px; }
.avatar-wrap img { width:110px; height:110px; border-radius:50%; object-fit:cover; border:4px solid var(--maroon-pale); }
.avatar-edit-btn {
    position:absolute; bottom:4px; right:4px;
    width:30px; height:30px; border-radius:50%;
    background:var(--maroon); border:2px solid white;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:background 0.2s;
}
.avatar-edit-btn:hover { background:var(--maroon-light); }
.avatar-edit-btn svg { pointer-events:none; }
#picInput { display:none; }
.profile-name { font-size:18px; font-weight:700; color:var(--gray-800); margin-bottom:4px; }
.profile-username { font-size:14px; color:var(--gray-400); margin-bottom:16px; }
.profile-stats { display:flex; justify-content:center; gap:20px; padding-top:16px; border-top:1px solid var(--gray-100); }
.stat-item { text-align:center; }
.stat-item strong { display:block; font-size:20px; font-weight:700; color:var(--maroon); }
.stat-item span { font-size:11px; color:var(--gray-400); text-transform:uppercase; letter-spacing:0.5px; }
.member-since { font-size:12px; color:var(--gray-400); margin-top:14px; }

/* Form card */
.form-card { background:white; border-radius:var(--radius); padding:28px; box-shadow:var(--shadow-sm); border:1px solid var(--gray-200); }
.form-card h2 { font-size:16px; font-weight:600; color:var(--gray-800); margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid var(--gray-100); }
.form-section { margin-bottom:24px; }
.form-section-title { font-size:12px; text-transform:uppercase; letter-spacing:1px; color:var(--gray-400); font-weight:600; margin-bottom:12px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:500; color:var(--gray-600); margin-bottom:6px; }
.form-group input {
    width:100%; padding:10px 14px;
    border:1px solid var(--gray-200); border-radius:var(--radius-sm);
    font-family:'DM Sans',sans-serif; font-size:14px; color:var(--gray-800);
    outline:none; transition:border-color 0.2s;
}
.form-group input:focus { border-color:var(--maroon); }
.form-group input:disabled { background:var(--gray-100); color:var(--gray-400); cursor:not-allowed; }
.form-hint { font-size:11px; color:var(--gray-400); margin-top:4px; }
.save-btn {
    background:var(--maroon); color:white; border:none;
    padding:10px 28px; border-radius:20px; font-size:14px; font-weight:500;
    cursor:pointer; font-family:'DM Sans',sans-serif; transition:background 0.2s;
    display:inline-flex; align-items:center; gap:8px;
}
.save-btn:hover { background:var(--maroon-light); }

/* Alerts */
.alert { padding:12px 16px; border-radius:var(--radius-sm); font-size:13px; margin-bottom:20px; display:flex; align-items:center; gap:8px; }
.alert-success { background:#d1fae5; color:#065f46; }
.alert-error { background:#fee2e2; color:#991b1b; }

/* Avatar preview overlay */
.avatar-preview-note { font-size:11px; color:var(--gray-400); margin-top:8px; }
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">AnonymousReview</div>
    <img src="<?php echo htmlspecialchars($avatar); ?>" class="sidebar-avatar" alt="Avatar">
    <div class="sidebar-name"><?php echo htmlspecialchars($user['fullname']); ?></div>
    <div class="sidebar-role">Student</div>
    <nav>
        <div class="nav-label">Menu</div>
        <a href="dashboard.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Dashboard
        </a>
        <a href="#">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            Evaluation History
        </a>
        <a href="profile.php" class="active">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            Profile
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            Logout
        </a>
    </div>
</div>

<!-- Main -->
<div class="main">
    <div class="page-header">
        <h1>My Profile</h1>
        <p>Manage your account information and settings.</p>
    </div>

    <?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Profile updated successfully!
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php echo implode(' &nbsp;·&nbsp; ', array_map('htmlspecialchars', $errors)); ?>
    </div>
    <?php endif; ?>

    <div class="profile-grid">

        <!-- Left: Profile Card -->
        <div class="profile-card">
            <div class="avatar-wrap">
                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="Avatar" id="avatarPreview">
                <div class="avatar-edit-btn" onclick="document.getElementById('picInput').click()" title="Change photo">
                    <svg width="14" height="14" fill="none" stroke="white" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                </div>
            </div>
            <p class="avatar-preview-note" id="picNote"></p>
            <div class="profile-name"><?php echo htmlspecialchars($user['fullname']); ?></div>
            <div class="profile-username">@<?php echo htmlspecialchars($user['username']); ?></div>
            <div class="profile-stats">
                <div class="stat-item">
                    <strong><?php echo $total; ?></strong>
                    <span>Reviews</span>
                </div>
                <div class="stat-item">
                    <strong><?php echo $approved; ?></strong>
                    <span>Approved</span>
                </div>
                <div class="stat-item">
                    <strong><?php echo $pending; ?></strong>
                    <span>Pending</span>
                </div>
            </div>
            <div class="member-since">
                Member since <?php echo date("F Y", strtotime($user['created_at'])); ?>
            </div>
        </div>

        <!-- Right: Edit Form -->
        <div class="form-card">
            <h2>Edit Profile</h2>
            <form method="POST" enctype="multipart/form-data" id="profileForm">
                <input type="file" name="profile_pic" id="picInput" accept="image/*" onchange="previewPic(this)">

                <div class="form-section">
                    <div class="form-section-title">Personal Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" name="fullname" value="<?php echo htmlspecialchars($user['fullname']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">Change Password <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--gray-400);font-size:11px;">(leave blank to keep current)</span></div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" placeholder="Min. 6 characters">
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" id="confirmPw" placeholder="Re-enter new password">
                            <div class="form-hint" id="pwMatch"></div>
                        </div>
                    </div>
                </div>

                <button type="submit" name="update_profile" class="save-btn">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Save Changes
                </button>
            </form>
        </div>

    </div>
</div>

<script>
// Avatar preview
function previewPic(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('avatarPreview').src = e.target.result;
            document.getElementById('picNote').textContent = '📷 New photo selected — save to apply.';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Password match check
const newPw  = document.querySelector('input[name="new_password"]');
const confPw = document.getElementById('confirmPw');
const pwMsg  = document.getElementById('pwMatch');

function checkPw() {
    if (!confPw.value) { pwMsg.textContent = ''; return; }
    if (newPw.value === confPw.value) {
        pwMsg.style.color = '#065f46';
        pwMsg.textContent = '✓ Passwords match';
    } else {
        pwMsg.style.color = '#991b1b';
        pwMsg.textContent = '✗ Passwords do not match';
    }
}
newPw.addEventListener('input', checkPw);
confPw.addEventListener('input', checkPw);

// Prevent submit if passwords don't match
document.getElementById('profileForm').addEventListener('submit', function(e) {
    if (newPw.value && newPw.value !== confPw.value) {
        e.preventDefault();
        pwMsg.style.color = '#991b1b';
        pwMsg.textContent = '✗ Passwords do not match';
        confPw.focus();
    }
});
</script>
<script src="session_timeout.js"></script>
</body>
</body>
</html>