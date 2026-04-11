<?php
date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) session_start();

$env_path = __DIR__ . '/.env';
if (file_exists($env_path)) {
    $env = parse_ini_file($env_path);
    $conn = mysqli_connect($env['DB_HOST'], $env['DB_USER'], $env['DB_PASS'], $env['DB_NAME']);
    mysqli_query($conn, "SET time_zone = '+8:00'");
}

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php"); exit();
}

include "email_helper.php";

if (isset($_POST['register'])) {
    $pseudo_name      = trim($_POST['pseudo_name']      ?? '');
    $username         = trim($_POST['username']         ?? '');
    $email            = trim($_POST['email']            ?? '');
    $password         = $_POST['password']              ?? '';
    $confirm_password = $_POST['confirm_password']      ?? '';
    $error = '';

    if (empty($pseudo_name))              $error = "Pseudonym is required.";
    elseif (empty($username))             $error = "Username is required.";
    elseif (empty($email))                $error = "Email is required.";
    elseif (strlen($password) < 6)        $error = "Password must be at least 6 characters.";
    elseif ($password !== $confirm_password) $error = "Passwords do not match.";
    else {
        $pseudo_safe = mysqli_real_escape_string($conn, $pseudo_name);
        $user_safe   = mysqli_real_escape_string($conn, $username);
        $email_safe  = mysqli_real_escape_string($conn, $email);
        $check = mysqli_query($conn, "SELECT id FROM users WHERE username='$user_safe' OR email='$email_safe' LIMIT 1");
        if (mysqli_num_rows($check) > 0) {
            $error = "Username or Email already exists.";
        } else {
            $hashed      = password_hash($password, PASSWORD_DEFAULT);
            $hashed_safe = mysqli_real_escape_string($conn, $hashed);
            mysqli_query($conn, "INSERT INTO users (fullname, username, email, password) VALUES ('$pseudo_safe','$user_safe','$email_safe','$hashed_safe')");
            sendBrevoEmail($email, $pseudo_name, 'Welcome to AnonymousReview!', welcomeEmailHtml($pseudo_name));
            header("Location: login.php");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — OlshcoReview</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{
    font-family:'Inter',sans-serif;min-height:100vh;
    background:url('image/school_bg.jpg') center/cover no-repeat fixed;
    position:relative;display:flex;align-items:stretch;
}
body::before{
    content:'';position:fixed;inset:0;
    background:rgba(0,0,0,0.48);z-index:0;
}
.page-wrap{position:relative;z-index:1;display:flex;width:100%;min-height:100vh;}

/* ── LEFT PANEL ─────────────────────────────── */
.left-panel{
    flex:1;display:flex;flex-direction:column;
    align-items:flex-start;justify-content:center;
    padding:60px 50px;
}
/* Logo sits freely at left-center without any card wrapper */
.left-logo{
    width:180px;height:180px;border-radius:50%;
    object-fit:cover;
    border:4px solid rgba(255,255,255,0.35);
    margin-bottom:32px;
    box-shadow:0 8px 40px rgba(0,0,0,0.35);
    display:block;
}
.left-title{
    font-size:clamp(22px,2.8vw,36px);font-weight:700;
    color:#fff;line-height:1.2;
}
.left-title .gold{color:#F5A623;}

/* ── RIGHT PANEL ────────────────────────────── */
.right-panel{
    width:520px;flex-shrink:0;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    padding:50px 56px;
    overflow-y:auto;
}
.form-card{width:100%;max-width:420px;}
.form-card h2{
    font-size:clamp(30px,4vw,46px);font-weight:900;
    color:#fff;margin-bottom:24px;
    text-shadow:0 2px 12px rgba(0,0,0,0.3);
}
.field-label{
    font-size:14px;font-weight:600;color:#fff;
    margin-bottom:7px;display:block;
    text-shadow:0 1px 4px rgba(0,0,0,0.3);
}
.input-wrap{position:relative;margin-bottom:16px;}
.input-wrap svg{
    position:absolute;left:16px;top:50%;transform:translateY(-50%);
    color:#555;width:20px;height:20px;pointer-events:none;
}
.input-wrap input{
    width:100%;padding:13px 16px 13px 48px;
    background:rgba(255,255,255,0.95);
    border:none;border-radius:30px;
    font-family:'Inter',sans-serif;font-size:14px;color:#1a1a2e;
    outline:none;transition:box-shadow 0.2s;
}
.input-wrap input:focus{box-shadow:0 0 0 3px rgba(139,0,0,0.35);}
.input-wrap input::placeholder{color:#aaa;}
.btn-register{
    width:100%;padding:13px;
    background:#8B0000;color:#fff;border:none;
    border-radius:30px;font-size:16px;font-weight:700;
    cursor:pointer;font-family:'Inter',sans-serif;
    transition:background 0.2s,transform 0.15s;
    margin-top:6px;margin-bottom:16px;
    box-shadow:0 4px 18px rgba(139,0,0,0.45);
}
.btn-register:hover{background:#a30000;transform:translateY(-2px);}
.bottom-link{
    font-size:14px;font-weight:600;color:#fff;text-align:center;
    text-shadow:0 1px 4px rgba(0,0,0,0.3);
}
.bottom-link a{color:#F5A623;text-decoration:none;font-weight:700;}
.bottom-link a:hover{text-decoration:underline;}
.alert-error{
    background:rgba(220,38,38,0.18);border:1px solid rgba(220,38,38,0.45);
    color:#fff;border-radius:12px;padding:11px 16px;
    font-size:13px;margin-bottom:16px;display:flex;align-items:center;gap:8px;
    backdrop-filter:blur(4px);
}
.back-home{
    position:fixed;top:22px;left:26px;z-index:100;
    display:inline-flex;align-items:center;gap:7px;
    color:rgba(255,255,255,0.8);text-decoration:none;font-size:13px;font-weight:500;
    background:rgba(0,0,0,0.3);padding:7px 14px;border-radius:20px;
    border:1px solid rgba(255,255,255,0.2);transition:all 0.2s;backdrop-filter:blur(4px);
}
.back-home:hover{color:#fff;background:rgba(0,0,0,0.5);}
@media(max-width:768px){
    .left-panel{display:none;}
    .right-panel{width:100%;padding:40px 24px;}
}
</style>
</head>
<body>
<a href="index.php" class="back-home">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Back to Home
</a>
<div class="page-wrap">
    <!-- Left — logo + title, no container card -->
    <div class="left-panel">
        <img
            src="image/logo.png"
            alt="OLSHCO"
            class="left-logo"
            onerror="this.style.display='none'"
        >
        <div class="left-title">
            Anonymous Online<br>
            <span class="gold">Faculty Performance</span><br>
            <span class="gold">Evaluation and</span><br>
            <span class="gold">Feedback System</span>
        </div>
    </div>

    <!-- Right — register form -->
    <div class="right-panel">
        <div class="form-card">
            <h2>Register</h2>

            <?php if (!empty($error)): ?>
            <div class="alert-error">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="regForm" novalidate>
                <label class="field-label">Pseudonym:</label>
                <div class="input-wrap">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M7 15h2M11 15h6"/></svg>
                    <input type="text" name="pseudo_name" placeholder="Enter your display name"
                           value="<?php echo htmlspecialchars($_POST['pseudo_name'] ?? ''); ?>" required autocomplete="off">
                </div>

                <label class="field-label">User Name:</label>
                <div class="input-wrap">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" name="username" placeholder="Enter your Username"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required autocomplete="username">
                </div>

                <label class="field-label">Email:</label>
                <div class="input-wrap">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <input type="email" name="email" placeholder="example@gmail.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required autocomplete="email">
                </div>

                <label class="field-label">Password:</label>
                <div class="input-wrap">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    <input type="password" name="password" id="pw1" placeholder="Enter your Password" required autocomplete="new-password">
                </div>

                <label class="field-label">Confirm Password:</label>
                <div class="input-wrap">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    <input type="password" name="confirm_password" id="pw2" placeholder="Re-enter Password" required autocomplete="new-password">
                </div>

                <button type="submit" name="register" class="btn-register">Register</button>
            </form>
            <p class="bottom-link">Already have an account? <a href="login.php">Log in</a></p>
        </div>
    </div>
</div>
<script>
document.getElementById('regForm').addEventListener('submit', function(e) {
    const pw1 = document.getElementById('pw1').value;
    const pw2 = document.getElementById('pw2').value;
    if (pw1.length < 6) { e.preventDefault(); alert('Password must be at least 6 characters.'); return; }
    if (pw1 !== pw2) { e.preventDefault(); alert('Passwords do not match.'); }
});
</script>
</body>
</html>