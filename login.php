<?php
date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) session_start();
include "config.php";

if (isset($_SESSION['user_id'])) {
    header("Location: " . ($_SESSION['role'] === 'admin' ? "admin_dashboard.php" : "dashboard.php"));
    exit();
}

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $sql      = "SELECT * FROM users WHERE username='$username'";
    $result   = mysqli_query($conn, $sql);
    if (!$result) die("Query failed: " . mysqli_error($conn));
    $user = mysqli_fetch_assoc($result);
    if ($user) {
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id']         = $user['id'];
            $_SESSION['fullname']        = $user['fullname'];
            $_SESSION['username']        = $user['username'];
            $_SESSION['role']            = $user['role'];
            $_SESSION['last_activity']   = time();
            $_SESSION['session_expires'] = time() + 300;
            header("Location: " . ($user['role'] == 'admin' ? "admin_dashboard.php" : "dashboard.php"));
            exit();
        } else { $error = "Incorrect password."; }
    } else { $error = "Username not found."; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log In — OlshcoReview</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
html, body {
    height: 100%;
    overflow: hidden;
}
body{
    font-family:'Inter',sans-serif;
    background:url('image/school_bg.jpg') center/cover no-repeat fixed;
    position:relative;display:flex;align-items:stretch;
}
body::before{
    content:'';position:fixed;inset:0;
    background:rgba(0,0,0,0.48);z-index:0;
}
.page-wrap{
    position:relative;z-index:1;display:flex;
    width:100%;height:100vh;
}

/* ── LEFT PANEL — centered, matching register page ── */
.left-panel{
    flex:1;display:flex;flex-direction:column;
    align-items:center;          /* centered horizontally */
    justify-content:center;
    padding:60px 50px;
}
.left-logo{
    width:200px;
    height:200px;
    border-radius:50%;
    object-fit:cover;
    border:4px solid rgba(255,255,255,0.35);
    margin-bottom:32px;
    box-shadow:0 8px 40px rgba(0,0,0,0.35);
    display:block;
}
.left-title{
    font-size:clamp(20px,2.5vw,32px);
    font-weight:700;
    color:#fff;
    line-height:1.25;
    text-align:center;           /* centered text */
}
.left-title .gold{color:#F5A623;}

/* ── RIGHT PANEL — vertically centered, no scroll needed for login ── */
.right-panel{
    width:520px;flex-shrink:0;
    height:100vh;
    overflow-y:auto;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:60px 56px;
}
.right-panel::-webkit-scrollbar { width: 5px; }
.right-panel::-webkit-scrollbar-track { background: transparent; }
.right-panel::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.25); border-radius: 10px; }

.form-card{width:100%;max-width:400px;}
.form-card h2{
    font-size:clamp(32px,4vw,48px);font-weight:900;
    color:#fff;margin-bottom:28px;
    text-shadow:0 2px 12px rgba(0,0,0,0.3);
}
.field-label{
    font-size:14px;font-weight:600;color:#fff;
    margin-bottom:7px;display:block;
    text-shadow:0 1px 4px rgba(0,0,0,0.3);
}

/* ── INPUT WRAP ── */
.input-wrap{position:relative;margin-bottom:20px;}
.input-wrap .icon-left{
    position:absolute;left:16px;top:50%;transform:translateY(-50%);
    color:#555;pointer-events:none;
    display:flex;align-items:center;justify-content:center;
}
.input-wrap input{
    width:100%;padding:14px 16px 14px 48px;
    background:rgba(255,255,255,0.95);
    border:none;border-radius:30px;
    font-family:'Inter',sans-serif;font-size:15px;color:#1a1a2e;
    outline:none;transition:box-shadow 0.2s;
}
.input-wrap.has-eye input{padding-right:48px;}
.input-wrap input:focus{box-shadow:0 0 0 3px rgba(139,0,0,0.35);}
.input-wrap input::placeholder{color:#aaa;}

/* ── EYE TOGGLE ── */
.eye-btn{
    position:absolute;right:14px;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;
    color:#888;display:flex;align-items:center;padding:4px;
    transition:color 0.2s;
}
.eye-btn:hover{color:#8B0000;}

/* ── BUTTONS ── */
.btn-login{
    width:100%;padding:14px;
    background:#8B0000;color:#fff;border:none;
    border-radius:30px;font-size:16px;font-weight:700;
    cursor:pointer;font-family:'Inter',sans-serif;
    transition:background 0.2s,transform 0.15s;
    margin-top:4px;margin-bottom:16px;
    box-shadow:0 4px 18px rgba(139,0,0,0.45);
}
.btn-login:hover{background:#a30000;transform:translateY(-2px);}
.bottom-link{
    font-size:14px;font-weight:600;color:#fff;text-align:center;
    text-shadow:0 1px 4px rgba(0,0,0,0.3);
}
.bottom-link a{color:#F5A623;text-decoration:none;font-weight:700;}
.bottom-link a:hover{text-decoration:underline;}

/* ── ALERTS ── */
.alert-error{
    background:rgba(220,38,38,0.18);border:1px solid rgba(220,38,38,0.45);
    color:#fff;border-radius:12px;padding:12px 16px;
    font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;
    backdrop-filter:blur(4px);
}
.alert-timeout{
    background:rgba(245,158,11,0.18);border:1px solid rgba(245,158,11,0.4);
    color:#fff;border-radius:12px;padding:12px 16px;
    font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;
    backdrop-filter:blur(4px);
}
.alert-success{
    background:rgba(16,185,129,0.18);border:1px solid rgba(16,185,129,0.4);
    color:#fff;border-radius:12px;padding:12px 16px;
    font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;
    backdrop-filter:blur(4px);
}

/* ── BACK HOME ── */
.back-home{
    position:fixed;top:22px;left:26px;z-index:100;
    display:inline-flex;align-items:center;gap:7px;
    color:rgba(255,255,255,0.8);text-decoration:none;font-size:13px;font-weight:500;
    background:rgba(0,0,0,0.3);padding:7px 14px;border-radius:20px;
    border:1px solid rgba(255,255,255,0.2);transition:all 0.2s;backdrop-filter:blur(4px);
}
.back-home:hover{color:#fff;background:rgba(0,0,0,0.5);}

@media(max-width:768px){
    html, body { overflow: auto; }
    .left-panel{display:none;}
    .right-panel{width:100%;height:auto;overflow-y:visible;padding:40px 24px;}
}
</style>
</head>
<body>
<a href="index.php" class="back-home">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Back to Home
</a>
<div class="page-wrap">
    <!-- Left: Centered Logo + Title (matching register page) -->
    <div class="left-panel">
        <img src="image/logo.png" alt="OLSHCO" class="left-logo" onerror="this.style.display='none'">
        <div class="left-title">
            OLSHCOReview<br>
            <span class="gold">Faculty Evaluation and</span><br>
            <span class="gold">Feedback System</span>
        </div>
    </div>

    <!-- Right: Form -->
    <div class="right-panel">
        <div class="form-card">
            <h2>Log in</h2>

            <?php if (isset($_GET['registered'])): ?>
            <div class="alert-success">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Account created! Your email has been verified. You can now log in.
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['timeout'])): ?>
            <div class="alert-timeout">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                You were logged out due to inactivity.
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="alert-error">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <label class="field-label">Username</label>
                <div class="input-wrap">
                    <span class="icon-left">
                        <svg width="20" height="20" fill="none" stroke="#555" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    <input type="text" name="username" placeholder="Enter your Username" required autocomplete="username">
                </div>

                <label class="field-label">Password</label>
                <div class="input-wrap has-eye">
                    <span class="icon-left">
                        <svg width="20" height="20" fill="none" stroke="#555" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </span>
                    <input type="password" name="password" id="loginPw" placeholder="Enter your Password" required autocomplete="current-password">
                    <button type="button" class="eye-btn" onclick="toggleEye('loginPw', this)" aria-label="Show/hide password">
                        <svg id="loginPw-eye-open" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <svg id="loginPw-eye-closed" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:none;">
                            <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                            <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                    </button>
                </div>

                <button type="submit" name="login" class="btn-login">Log in</button>
            </form>
            <p class="bottom-link">Don't have an account? <a href="register.php">Register</a></p>
        </div>
    </div>
</div>

<script>
function toggleEye(inputId, btn) {
    const inp    = document.getElementById(inputId);
    const isHide = inp.type === 'password';
    inp.type     = isHide ? 'text' : 'password';
    document.getElementById(inputId + '-eye-open').style.display   = isHide ? 'none' : '';
    document.getElementById(inputId + '-eye-closed').style.display = isHide ? ''     : 'none';
}
</script>
</body>
</html>