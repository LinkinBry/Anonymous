<?php
include "config.php";
include "session_check.php";

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$user_id = $_SESSION['user_id'];

if (isset($_POST['update_profile'])) {
    $fullname = mysqli_real_escape_string($conn, trim($_POST['fullname']));
    $username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $email    = mysqli_real_escape_string($conn, trim($_POST['email']));
    $errors   = [];

    $check = mysqli_query($conn, "SELECT id FROM users WHERE username='$username' AND id!='$user_id' LIMIT 1");
    if (mysqli_num_rows($check) > 0) $errors[] = "Username is already taken.";

    $check2 = mysqli_query($conn, "SELECT id FROM users WHERE email='$email' AND id!='$user_id' LIMIT 1");
    if (mysqli_num_rows($check2) > 0) $errors[] = "Email is already in use.";

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

$res  = mysqli_query($conn, "SELECT fullname, username, email, profile_pic, created_at FROM users WHERE id='$user_id' LIMIT 1");
$user = mysqli_fetch_assoc($res);

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
<title>My Profile — AnonymousReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/profile.css">
</head>
<body>

<!-- ══ Sidebar toggle button ════════════════════════════════ -->
<button class="sidebar-toggle" id="sidebarToggle" title="Toggle sidebar">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <polyline points="15 18 9 12 15 6"/>
    </svg>
</button>

<!-- ══ Sidebar ══════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-top">
        <div class="sidebar-logo-fallback">
            <svg width="20" height="20" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        </div>
        <div class="sidebar-brand-text">AnonymousReview<span class="sidebar-brand-sub">Student Portal</span></div>
    </div>
    <div class="sidebar-user-wrap">
        <a href="profile.php" style="display:block;text-align:center;">
            <img src="<?php echo htmlspecialchars($avatar); ?>" class="sidebar-avatar" alt="Avatar">
        </a>
        <div class="sidebar-name"><?php echo htmlspecialchars($user['fullname']); ?></div>
        <div class="sidebar-role">@<?php echo htmlspecialchars($user['username']); ?></div>
    </div>
    <nav>
        <div class="nav-label">Menu</div>
        <a href="dashboard.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span class="nav-link-text">Dashboard</span>
        </a>
        <a href="profile.php" class="active">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            <span class="nav-link-text">Profile</span>
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            <span class="nav-link-text">Logout</span>
        </a>
    </div>
</div>

<!-- ══ Main ═════════════════════════════════════════════════ -->
<div class="main">
    <div class="profile-page-wrap">

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

        <!-- ── Hero Banner ───────────────────────────────────── -->
        <div class="profile-hero">
            <div class="hero-avatar-wrap">
                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="Avatar" id="avatarPreview">
                <div class="hero-avatar-edit" onclick="document.getElementById('picInput').click()" title="Change photo">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                </div>
            </div>
            <div class="hero-info">
                <div class="hero-name"><?php echo htmlspecialchars($user['fullname']); ?></div>
                <div class="hero-username">@<?php echo htmlspecialchars($user['username']); ?></div>
                <p class="avatar-preview-note" id="picNote"></p>
                <div class="hero-stats">
                    <div class="hero-stat"><strong><?php echo $total; ?></strong><span>Reviews</span></div>
                    <div class="hero-stat"><strong><?php echo $approved; ?></strong><span>Approved</span></div>
                    <div class="hero-stat"><strong><?php echo $pending; ?></strong><span>Pending</span></div>
                </div>
            </div>
            <div class="hero-meta">
                <div class="hero-badge">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Student
                </div>
                <div class="hero-since">Member since <?php echo date("F Y", strtotime($user['created_at'])); ?></div>
            </div>
        </div>

        <!-- ── Two-Column Form Grid ───────────────────────────── -->
        <div class="profile-grid">

            <!-- Left: Personal Info -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-icon blue">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div>
                        <div class="form-card-title">Personal Information</div>
                        <div class="form-card-sub">Update your display name and contact details</div>
                    </div>
                </div>
                <div class="form-card-body">
                    <form method="POST" enctype="multipart/form-data" id="profileForm">
                        <input type="file" name="profile_pic" id="picInput" accept="image/*" onchange="previewPic(this)">

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

                        <div style="height:1px;background:var(--gray-100);margin:18px 0;"></div>
                        <div class="form-section-title">Change Password <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:11px;color:var(--gray-400);">(leave blank to keep current)</span></div>
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

                        <button type="submit" name="update_profile" class="save-btn">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right: Account Info -->
            <div>
                <div class="form-card" style="margin-bottom:20px;">
                    <div class="form-card-header">
                        <div class="form-card-icon maroon">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        </div>
                        <div>
                            <div class="form-card-title">Account Details</div>
                            <div class="form-card-sub">Your account overview</div>
                        </div>
                    </div>
                    <div class="form-card-body" style="padding-top:4px;padding-bottom:4px;">
                        <div class="info-list">
                            <div class="info-row">
                                <div class="info-row-icon"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg></div>
                                <div><div class="info-row-label">Full Name</div><div class="info-row-value"><?php echo htmlspecialchars($user['fullname']); ?></div></div>
                            </div>
                            <div class="info-row">
                                <div class="info-row-icon"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                                <div><div class="info-row-label">Username</div><div class="info-row-value">@<?php echo htmlspecialchars($user['username']); ?></div></div>
                            </div>
                            <div class="info-row">
                                <div class="info-row-icon"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
                                <div><div class="info-row-label">Email</div><div class="info-row-value"><?php echo htmlspecialchars($user['email']); ?></div></div>
                            </div>
                            <div class="info-row">
                                <div class="info-row-icon"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                                <div><div class="info-row-label">Member Since</div><div class="info-row-value"><?php echo date("F j, Y", strtotime($user['created_at'])); ?></div></div>
                            </div>
                            <div class="info-row">
                                <div class="info-row-icon"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
                                <div><div class="info-row-label">Role</div><div class="info-row-value">Student</div></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <div class="form-card-header">
                        <div class="form-card-icon green">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        </div>
                        <div>
                            <div class="form-card-title">Review Summary</div>
                            <div class="form-card-sub">Your evaluation activity</div>
                        </div>
                    </div>
                    <div class="form-card-body">
                        <?php
                        $rejected = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE user_id='$user_id' AND status='rejected'"))['c'];
                        $items = [
                            ['Total Submitted', $total, '#6366f1', 'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z'],
                            ['Approved', $approved, '#10b981', 'M22 11.08V12a10 10 0 11-5.93-9.14 M22 4L12 14.01l-3-3'],
                            ['Pending Review', $pending, '#f59e0b', 'M12 22 M12 6v6l4 2'],
                            ['Rejected', $rejected, '#ef4444', 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z'],
                        ];
                        foreach ($items as $item): ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--gray-100);">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:30px;height:30px;border-radius:8px;background:<?php echo $item[2]; ?>20;display:flex;align-items:center;justify-content:center;">
                                    <svg width="14" height="14" fill="none" stroke="<?php echo $item[2]; ?>" stroke-width="2" viewBox="0 0 24 24"><path d="<?php echo $item[3]; ?>"/></svg>
                                </div>
                                <span style="font-size:14px;color:var(--gray-700);"><?php echo $item[0]; ?></span>
                            </div>
                            <span style="font-size:18px;font-weight:700;color:<?php echo $item[2]; ?>;"><?php echo $item[1]; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="assets/js/profile.js"></script>
<script src="assets/js/session_timeout.js"></script>
</body>
</html>