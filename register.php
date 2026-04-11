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

// ── Ensure OTP table exists ────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `email_otps` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `email`         VARCHAR(255) NOT NULL,
        `pseudo_name`   VARCHAR(255) NOT NULL,
        `username`      VARCHAR(100) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `otp`           VARCHAR(6)   NOT NULL,
        `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `expires_at`    DATETIME NOT NULL,
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_email` (`email`),
        INDEX `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Purge expired OTPs ─────────────────────────────────────────────────────
mysqli_query($conn, "DELETE FROM email_otps WHERE expires_at < NOW()");

$error   = '';
$success = '';
$step    = isset($_SESSION['otp_pending_email']) ? 'verify' : 'register';

// ══════════════════════════════════════════════════════════════════════════
// STEP 1 — Registration form submitted → generate & send OTP
// ══════════════════════════════════════════════════════════════════════════
if (isset($_POST['send_otp'])) {
    $pseudo_name      = trim($_POST['pseudo_name']      ?? '');
    $username         = trim($_POST['username']         ?? '');
    $email            = trim($_POST['email']            ?? '');
    $password         = $_POST['password']              ?? '';
    $confirm_password = $_POST['confirm_password']      ?? '';

    if (empty($pseudo_name))                 $error = "Pseudonym is required.";
    elseif (empty($username))                $error = "Username is required.";
    elseif (empty($email))                   $error = "Email is required.";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = "Invalid email address.";
    elseif (strlen($password) < 6)           $error = "Password must be at least 6 characters.";
    elseif ($password !== $confirm_password) $error = "Passwords do not match.";
    else {
        $pseudo_safe = mysqli_real_escape_string($conn, $pseudo_name);
        $user_safe   = mysqli_real_escape_string($conn, $username);
        $email_safe  = mysqli_real_escape_string($conn, $email);

        // Check duplicates in users table
        $dup = mysqli_query($conn, "SELECT id FROM users WHERE username='$user_safe' OR email='$email_safe' LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            $error = "Username or Email is already registered.";
        } else {
            // Generate secure 6-digit OTP
            $otp         = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at  = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
            $hash_safe   = mysqli_real_escape_string($conn, $hashed_pass);
            $otp_safe    = mysqli_real_escape_string($conn, $otp);

            // Upsert OTP record (replace if same email re-registers)
            mysqli_query($conn, "
                INSERT INTO email_otps (email, pseudo_name, username, password_hash, otp, attempts, expires_at)
                VALUES ('$email_safe', '$pseudo_safe', '$user_safe', '$hash_safe', '$otp_safe', 0, '$expires_at')
                ON DUPLICATE KEY UPDATE
                    pseudo_name   = VALUES(pseudo_name),
                    username      = VALUES(username),
                    password_hash = VALUES(password_hash),
                    otp           = VALUES(otp),
                    attempts      = 0,
                    expires_at    = VALUES(expires_at),
                    created_at    = NOW()
            ");

            // Send OTP email via Brevo
            $sent = sendOtpEmail($email, $pseudo_name, $otp);

            if ($sent) {
                $_SESSION['otp_pending_email'] = $email;
                $step    = 'verify';
                $success = "A 6-digit verification code has been sent to <strong>" . htmlspecialchars($email) . "</strong>. Please check your inbox (and spam folder).";
            } else {
                // Clean up if email fails so user can retry
                mysqli_query($conn, "DELETE FROM email_otps WHERE email='$email_safe'");
                $error = "Failed to send verification email. Please try again.";
            }
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════
// STEP 2 — OTP verification form submitted
// ══════════════════════════════════════════════════════════════════════════
if (isset($_POST['verify_otp'])) {
    $entered_otp = trim($_POST['otp_code'] ?? '');
    $email       = $_SESSION['otp_pending_email'] ?? '';

    if (empty($email)) {
        $error = "Session expired. Please register again.";
        $step  = 'register';
    } elseif (!preg_match('/^\d{6}$/', $entered_otp)) {
        $error = "Please enter the 6-digit code.";
        $step  = 'verify';
    } else {
        $email_safe = mysqli_real_escape_string($conn, $email);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM email_otps WHERE email='$email_safe' LIMIT 1"
        ));

        if (!$row) {
            $error = "Verification record not found. Please register again.";
            $step  = 'register';
            unset($_SESSION['otp_pending_email']);
        } elseif (strtotime($row['expires_at']) < time()) {
            mysqli_query($conn, "DELETE FROM email_otps WHERE email='$email_safe'");
            unset($_SESSION['otp_pending_email']);
            $error = "Your OTP has expired. Please register again.";
            $step  = 'register';
        } elseif ($row['attempts'] >= 3) {
            mysqli_query($conn, "DELETE FROM email_otps WHERE email='$email_safe'");
            unset($_SESSION['otp_pending_email']);
            $error = "Too many incorrect attempts. Please register again.";
            $step  = 'register';
        } elseif ($entered_otp !== $row['otp']) {
            // Increment attempt counter
            mysqli_query($conn, "UPDATE email_otps SET attempts = attempts + 1 WHERE email='$email_safe'");
            $remaining = 2 - intval($row['attempts']);
            $error = "Incorrect code. " . max(0, $remaining) . " attempt(s) remaining.";
            $step  = 'verify';
        } else {
            // ✅ OTP correct — create the user account
            $pseudo_safe = mysqli_real_escape_string($conn, $row['pseudo_name']);
            $user_safe   = mysqli_real_escape_string($conn, $row['username']);
            $hash_safe   = mysqli_real_escape_string($conn, $row['password_hash']);

            mysqli_query($conn, "
                INSERT INTO users (fullname, username, email, password)
                VALUES ('$pseudo_safe', '$user_safe', '$email_safe', '$hash_safe')
            ");

            // Send welcome email
            sendBrevoEmail($email, $row['pseudo_name'], 'Welcome to OlshcoReview!', welcomeEmailHtml($row['pseudo_name']));

            // Clean up OTP record and session
            mysqli_query($conn, "DELETE FROM email_otps WHERE email='$email_safe'");
            unset($_SESSION['otp_pending_email']);

            header("Location: login.php?registered=1");
            exit();
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════
// STEP 2 — Resend OTP
// ══════════════════════════════════════════════════════════════════════════
if (isset($_POST['resend_otp'])) {
    $email = $_SESSION['otp_pending_email'] ?? '';
    if (!empty($email)) {
        $email_safe = mysqli_real_escape_string($conn, $email);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM email_otps WHERE email='$email_safe' LIMIT 1"
        ));
        if ($row) {
            $otp        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $otp_safe   = mysqli_real_escape_string($conn, $otp);
            mysqli_query($conn, "
                UPDATE email_otps
                SET otp='$otp_safe', attempts=0, expires_at='$expires_at'
                WHERE email='$email_safe'
            ");
            $sent = sendOtpEmail($email, $row['pseudo_name'], $otp);
            if ($sent) {
                $success = "A new code has been sent to <strong>" . htmlspecialchars($email) . "</strong>.";
            } else {
                $error = "Failed to resend. Please try again.";
            }
        } else {
            $error = "Session expired. Please register again.";
            $step  = 'register';
            unset($_SESSION['otp_pending_email']);
        }
    }
    $step = 'verify';
}

// ── Cancel / go back to register form ─────────────────────────────────────
if (isset($_POST['cancel_otp'])) {
    $email = $_SESSION['otp_pending_email'] ?? '';
    if (!empty($email)) {
        $email_safe = mysqli_real_escape_string($conn, $email);
        mysqli_query($conn, "DELETE FROM email_otps WHERE email='$email_safe'");
    }
    unset($_SESSION['otp_pending_email']);
    $step = 'register';
}

// ══════════════════════════════════════════════════════════════════════════
// OTP Email helper (defined here, uses Brevo API same as email_helper.php)
// ══════════════════════════════════════════════════════════════════════════
function sendOtpEmail(string $to_email, string $to_name, string $otp): bool {
    $env      = parse_ini_file(__DIR__ . '/.env');
    $api_key  = $env['BREVO_API_KEY'];
    $from_email = $env['MAIL_FROM'];
    $from_name  = $env['MAIL_FROM_NAME'] ?? 'OlshcoReview';

    $html = '
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">
        <div style="max-width:520px;margin:40px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
            <div style="background:#8B0000;padding:28px 32px;text-align:center;">
                <h1 style="color:white;margin:0;font-size:22px;font-family:Georgia,serif;">OlshcoReview</h1>
                <p style="color:rgba(255,255,255,0.75);margin:5px 0 0;font-size:13px;">Faculty Performance Evaluation System</p>
            </div>
            <div style="padding:36px 32px;">
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="width:56px;height:56px;background:#fff0f0;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                        <span style="font-size:26px;">🔐</span>
                    </div>
                    <h2 style="color:#1f2937;margin:0;font-size:20px;">Email Verification</h2>
                </div>
                <p style="color:#4b5563;font-size:15px;line-height:1.6;">Hi <strong>' . htmlspecialchars($to_name) . '</strong>,</p>
                <p style="color:#4b5563;font-size:15px;line-height:1.6;">
                    Use the verification code below to complete your registration. This code expires in <strong>10 minutes</strong>.
                </p>
                <div style="background:#f9fafb;border:2px dashed #d1d5db;border-radius:12px;padding:24px;text-align:center;margin:24px 0;">
                    <p style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:2px;color:#9ca3af;font-weight:600;">Your Verification Code</p>
                    <div style="font-size:42px;font-weight:800;letter-spacing:10px;color:#8B0000;font-family:monospace;">' . $otp . '</div>
                </div>
                <div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;margin-bottom:20px;">
                    <p style="margin:0;color:#92400e;font-size:13px;">⚠️ Do not share this code with anyone. OlshcoReview staff will never ask for it.</p>
                </div>
                <p style="color:#9ca3af;font-size:13px;">If you did not request this, you can safely ignore this email.</p>
            </div>
            <div style="background:#f9fafb;padding:18px;text-align:center;border-top:1px solid #e5e7eb;">
                <p style="color:#9ca3af;font-size:12px;margin:0;">This is an automated message from OlshcoReview. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>';

    $payload = json_encode([
        'sender'      => ['name' => $from_name, 'email' => $from_email],
        'to'          => [['email' => $to_email, 'name' => $to_name]],
        'subject'     => 'Your OlshcoReview Verification Code: ' . $otp,
        'htmlContent' => $html,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: ' . $api_key,
        ],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status === 201);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — OLSHCOReview</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
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

/* ── LEFT PANEL ─────────────────────── */
.left-panel {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;        /* center logo + text horizontally */
    justify-content: center;
    padding: 60px 50px;
}

.left-logo {
    width: 300px;
    height: 300px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid rgba(255,255,255,0.35);
    margin-bottom: 32px;
    box-shadow: 0 8px 40px rgba(0,0,0,0.35);
    display: block;
}

.left-title {
    font-size: clamp(22px, 2.8vw, 36px);
    font-weight: 700;
    color: #fff;
    line-height: 1.2;
    text-align: center;         /* center text lines */
}

.left-title .gold {
    color: #F5A623;
}


/* ── RIGHT PANEL ────────────────────── */
.right-panel{
    width:520px;flex-shrink:0;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    padding:50px 56px;overflow-y:auto;
}
.form-card{width:100%;max-width:420px;}
.form-card h2{
    font-size:clamp(30px,4vw,46px);font-weight:900;
    color:#fff;margin-bottom:24px;
    text-shadow:0 2px 12px rgba(0,0,0,0.3);
}

/* ── FIELD LABEL ────────────────────── */
.field-label{
    font-size:14px;font-weight:600;color:#fff;
    margin-bottom:7px;display:block;
    text-shadow:0 1px 4px rgba(0,0,0,0.3);
}

/* ── INPUT WRAP ─────────────────────── */
.input-wrap{position:relative;margin-bottom:16px;}
.input-wrap .icon-left{
    position:absolute;left:16px;top:50%;transform:translateY(-50%);
    color:#555;width:20px;height:20px;pointer-events:none;
    display:flex;align-items:center;justify-content:center;
}
.input-wrap input{
    width:100%;padding:13px 16px 13px 48px;
    background:rgba(255,255,255,0.95);border:none;border-radius:30px;
    font-family:'Inter',sans-serif;font-size:14px;color:#1a1a2e;
    outline:none;transition:box-shadow 0.2s;
}
/* Password inputs also need right padding for the eye button */
.input-wrap.has-eye input{padding-right:48px;}
.input-wrap input:focus{box-shadow:0 0 0 3px rgba(139,0,0,0.35);}
.input-wrap input::placeholder{color:#aaa;}

/* ── EYE TOGGLE ─────────────────────── */
.eye-btn{
    position:absolute;right:14px;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;
    color:#888;display:flex;align-items:center;padding:4px;
    transition:color 0.2s;
}
.eye-btn:hover{color:#8B0000;}

/* ── BUTTONS ────────────────────────── */
.btn-primary{
    width:100%;padding:13px;background:#8B0000;color:#fff;border:none;
    border-radius:30px;font-size:16px;font-weight:700;cursor:pointer;
    font-family:'Inter',sans-serif;transition:background 0.2s,transform 0.15s;
    margin-top:6px;margin-bottom:16px;box-shadow:0 4px 18px rgba(139,0,0,0.45);
}
.btn-primary:hover{background:#a30000;transform:translateY(-2px);}
.btn-primary:disabled{background:#666;cursor:not-allowed;transform:none;}

.btn-outline-white{
    width:100%;padding:11px;background:rgba(255,255,255,0.1);color:#fff;
    border:2px solid rgba(255,255,255,0.45);border-radius:30px;
    font-size:14px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;
    transition:all 0.2s;margin-bottom:10px;
}
.btn-outline-white:hover{background:rgba(255,255,255,0.2);border-color:#fff;}

.btn-ghost{
    background:none;border:none;color:rgba(255,255,255,0.7);
    font-size:13px;cursor:pointer;font-family:'Inter',sans-serif;
    text-decoration:underline;padding:0;transition:color 0.2s;
}
.btn-ghost:hover{color:#fff;}

/* ── OTP INPUT ──────────────────────── */
.otp-fields{display:flex;gap:10px;justify-content:center;margin:8px 0 18px;}
.otp-fields input{
    width:48px;height:56px;text-align:center;
    font-size:22px;font-weight:700;color:#1a1a2e;
    background:rgba(255,255,255,0.95);border:2px solid transparent;
    border-radius:12px;outline:none;font-family:'Inter',sans-serif;
    transition:border-color 0.2s,box-shadow 0.2s;
    caret-color:#8B0000;
}
.otp-fields input:focus{border-color:#8B0000;box-shadow:0 0 0 3px rgba(139,0,0,0.25);}
.otp-fields input.filled{border-color:#8B0000;}

/* ── ALERTS ─────────────────────────── */
.alert-error{
    background:rgba(220,38,38,0.18);border:1px solid rgba(220,38,38,0.45);
    color:#fff;border-radius:12px;padding:11px 16px;
    font-size:13px;margin-bottom:16px;display:flex;align-items:center;gap:8px;
    backdrop-filter:blur(4px);
}
.alert-success{
    background:rgba(16,185,129,0.18);border:1px solid rgba(16,185,129,0.4);
    color:#fff;border-radius:12px;padding:11px 16px;
    font-size:13px;margin-bottom:16px;backdrop-filter:blur(4px);line-height:1.6;
}
.bottom-link{
    font-size:14px;font-weight:600;color:#fff;text-align:center;
    text-shadow:0 1px 4px rgba(0,0,0,0.3);
}
.bottom-link a{color:#F5A623;text-decoration:none;font-weight:700;}
.bottom-link a:hover{text-decoration:underline;}

/* ── EMAIL PILL ─────────────────────── */
.email-pill{
    display:inline-flex;align-items:center;gap:6px;
    background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);
    border-radius:20px;padding:5px 14px;font-size:13px;color:#fff;
    margin:0 auto 20px;width:100%;justify-content:center;
    word-break:break-all;
}

/* ── TIMER ──────────────────────────── */
.otp-timer{font-size:12px;color:rgba(255,255,255,0.6);text-align:center;margin-bottom:14px;}
.otp-timer .countdown{color:#F5A623;font-weight:700;}

/* ── BACK LINK ──────────────────────── */
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

/* ── PROGRESS STEPS ─────────────────── */
.progress-steps{display:flex;align-items:center;gap:0;margin-bottom:28px;}
.step-dot{
    width:32px;height:32px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:13px;font-weight:700;flex-shrink:0;
    transition:all 0.3s;
}
.step-dot.active{background:#8B0000;color:#fff;box-shadow:0 0 0 3px rgba(139,0,0,0.3);}
.step-dot.done{background:#10b981;color:#fff;}
.step-dot.inactive{background:rgba(255,255,255,0.2);color:rgba(255,255,255,0.6);}
.step-line{flex:1;height:2px;background:rgba(255,255,255,0.2);}
.step-line.done{background:#10b981;}
.step-labels{display:flex;justify-content:space-between;margin-top:6px;margin-bottom:24px;}
.step-labels span{font-size:10px;color:rgba(255,255,255,0.6);font-weight:500;text-align:center;width:80px;}
.step-labels span.active-label{color:#fff;}
</style>
</head>
<body>
<a href="index.php" class="back-home">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Back to Home
</a>
<div class="page-wrap">

    <!-- ── Left Panel ── -->
    <div class="left-panel">
        <img src="image/logo.png" alt="OLSHCO" class="left-logo" onerror="this.style.display='none'">
        <div class="left-title">
            OLSHCOReview<br>
            <span class="gold">Faculty Evaluation and</span><br>
            <span class="gold">Feedback System</span>
        </div>
    </div>

    <!-- ── Right Panel ── -->
    <div class="right-panel">
        <div class="form-card">

            <!-- Progress indicator -->
            <div class="progress-steps">
                <div class="step-dot <?php echo $step === 'register' ? 'active' : 'done'; ?>">
                    <?php if ($step === 'verify'): ?>
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>1<?php endif; ?>
                </div>
                <div class="step-line <?php echo $step === 'verify' ? 'done' : ''; ?>"></div>
                <div class="step-dot <?php echo $step === 'verify' ? 'active' : 'inactive'; ?>">2</div>
            </div>
            <div class="step-labels">
                <span class="<?php echo $step === 'register' ? 'active-label' : ''; ?>" style="margin-left:-8px;">Register</span>
                <span class="<?php echo $step === 'verify' ? 'active-label' : ''; ?>" style="margin-right:-8px;">Verify Email</span>
            </div>

            <?php if ($step === 'register'): ?>
            <!-- ════════════════════════════════
                 STEP 1 — Registration Form
                 ════════════════════════════════ -->
            <h2>Register</h2>

            <?php if (!empty($error)): ?>
            <div class="alert-error">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="regForm" novalidate>
                <label class="field-label">Pseudonym</label>
                <div class="input-wrap">
                    <span class="icon-left">
                        <svg width="18" height="18" fill="none" stroke="#555" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                    </span>
                    <input type="text" name="pseudo_name" placeholder="Your display name"
                           value="<?php echo htmlspecialchars($_POST['pseudo_name'] ?? ''); ?>" required autocomplete="off">
                </div>

                <label class="field-label">Username</label>
                <div class="input-wrap">
                    <span class="icon-left">
                        <svg width="18" height="18" fill="none" stroke="#555" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    <input type="text" name="username" placeholder="Choose a username"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required autocomplete="username">
                </div>

                <label class="field-label">Email Address</label>
                <div class="input-wrap">
                    <span class="icon-left">
                        <svg width="18" height="18" fill="none" stroke="#555" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </span>
                    <input type="email" name="email" placeholder="example@gmail.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required autocomplete="email">
                </div>

                <label class="field-label">Password</label>
                <div class="input-wrap has-eye">
                    <span class="icon-left">
                        <svg width="18" height="18" fill="none" stroke="#555" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </span>
                    <input type="password" name="password" id="pw1" placeholder="Min. 6 characters" required autocomplete="new-password">
                    <button type="button" class="eye-btn" onclick="toggleEye('pw1', this)" aria-label="Show/hide password">
                        <!-- Eye open (default: password hidden, show the "eye" icon) -->
                        <svg id="pw1-eye-open" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <!-- Eye closed (hidden by default) -->
                        <svg id="pw1-eye-closed" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>

                <label class="field-label">Confirm Password</label>
                <div class="input-wrap has-eye">
                    <span class="icon-left">
                        <svg width="18" height="18" fill="none" stroke="#555" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </span>
                    <input type="password" name="confirm_password" id="pw2" placeholder="Re-enter password" required autocomplete="new-password">
                    <button type="button" class="eye-btn" onclick="toggleEye('pw2', this)" aria-label="Show/hide password">
                        <svg id="pw2-eye-open" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg id="pw2-eye-closed" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <!-- Password match hint -->
                <div id="pwHint" style="font-size:12px;color:rgba(255,255,255,0.7);margin-top:-10px;margin-bottom:14px;min-height:18px;"></div>

                <button type="submit" name="send_otp" class="btn-primary" id="sendOtpBtn">
                    Continue — Verify Email
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="display:inline;margin-left:8px;vertical-align:middle;"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </button>
            </form>
            <p class="bottom-link">Already have an account? <a href="login.php">Log in</a></p>

            <?php else: ?>
            <!-- ════════════════════════════════
                 STEP 2 — OTP Verification
                 ════════════════════════════════ -->
            <h2>Verify Email</h2>

            <?php if (!empty($error)): ?>
            <div class="alert-error">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
            <div class="alert-success">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;display:inline;margin-right:6px;vertical-align:middle;"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?php echo $success; ?>
            </div>
            <?php else: ?>
            <p style="color:rgba(255,255,255,0.8);font-size:14px;line-height:1.65;margin-bottom:20px;">
                We've sent a 6-digit code to:
            </p>
            <?php endif; ?>

            <div class="email-pill">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <?php echo htmlspecialchars($_SESSION['otp_pending_email'] ?? ''); ?>
            </div>

            <!-- OTP countdown timer -->
            <div class="otp-timer">
                Code expires in <span class="countdown" id="otpTimer">10:00</span>
            </div>

            <form method="POST" id="otpForm">
                <label class="field-label" style="text-align:center;display:block;">Enter 6-Digit Code</label>
                <!-- Hidden field to hold combined OTP value -->
                <input type="hidden" name="otp_code" id="otpHidden">

                <div class="otp-fields" id="otpFields">
                    <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
                    <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]">
                </div>

                <button type="submit" name="verify_otp" class="btn-primary" id="verifyBtn" disabled>
                    Verify &amp; Create Account
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="display:inline;margin-left:8px;vertical-align:middle;"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </button>
            </form>

            <!-- Resend / cancel -->
            <div style="display:flex;flex-direction:column;align-items:center;gap:10px;margin-top:4px;">
                <form method="POST" style="width:100%;">
                    <button type="submit" name="resend_otp" class="btn-outline-white" id="resendBtn" disabled>
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline;margin-right:6px;vertical-align:middle;"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Resend Code (<span id="resendCountdown">60</span>s)
                    </button>
                </form>
                <form method="POST">
                    <button type="submit" name="cancel_otp" class="btn-ghost">← Change email / start over</button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script>
/* ── Eye toggle ─────────────────────────────────────────────── */
function toggleEye(inputId, btn) {
    const inp    = document.getElementById(inputId);
    const isHide = inp.type === 'password';
    inp.type     = isHide ? 'text' : 'password';
    document.getElementById(inputId + '-eye-open').style.display   = isHide ? 'none'  : '';
    document.getElementById(inputId + '-eye-closed').style.display = isHide ? ''      : 'none';
}

/* ── Password match hint ─────────────────────────────────────── */
const pw1   = document.getElementById('pw1');
const pw2   = document.getElementById('pw2');
const hint  = document.getElementById('pwHint');
if (pw1 && pw2 && hint) {
    function checkMatch() {
        if (!pw2.value) { hint.textContent = ''; return; }
        if (pw1.value === pw2.value) {
            hint.style.color = '#6ee7b7';
            hint.textContent = '✓ Passwords match';
        } else {
            hint.style.color = '#fca5a5';
            hint.textContent = '✗ Passwords do not match';
        }
    }
    pw1.addEventListener('input', checkMatch);
    pw2.addEventListener('input', checkMatch);

    /* Prevent submit if mismatch */
    const regForm = document.getElementById('regForm');
    if (regForm) {
        regForm.addEventListener('submit', function(e) {
            if (pw1.value.length < 6) {
                e.preventDefault();
                hint.style.color = '#fca5a5';
                hint.textContent = '✗ Password must be at least 6 characters.';
                pw1.focus();
                return;
            }
            if (pw1.value !== pw2.value) {
                e.preventDefault();
                hint.style.color = '#fca5a5';
                hint.textContent = '✗ Passwords do not match.';
                pw2.focus();
            }
        });
    }
}

/* ── OTP digit-box auto-advance ─────────────────────────────── */
const digits   = document.querySelectorAll('.otp-digit');
const hidden   = document.getElementById('otpHidden');
const verifyBtn = document.getElementById('verifyBtn');

function syncOtp() {
    const val = [...digits].map(d => d.value).join('');
    if (hidden) hidden.value = val;
    if (verifyBtn) verifyBtn.disabled = val.length < 6;
    digits.forEach(d => d.classList.toggle('filled', d.value !== ''));
}

digits.forEach((inp, idx) => {
    inp.addEventListener('input', function() {
        this.value = this.value.replace(/\D/g, '').slice(-1);
        if (this.value && idx < digits.length - 1) digits[idx + 1].focus();
        syncOtp();
    });
    inp.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && !this.value && idx > 0) {
            digits[idx - 1].focus();
            digits[idx - 1].value = '';
            syncOtp();
        }
    });
    inp.addEventListener('paste', function(e) {
        e.preventDefault();
        const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        paste.split('').forEach((ch, i) => { if (digits[i]) digits[i].value = ch; });
        const next = Math.min(paste.length, digits.length - 1);
        digits[next].focus();
        syncOtp();
    });
});

/* Auto-focus first digit */
if (digits.length) digits[0].focus();

/* ── OTP expiry countdown (10 min) ─────────────────────────── */
const timerEl = document.getElementById('otpTimer');
if (timerEl) {
    let totalSec = 10 * 60;
    const tick = setInterval(() => {
        totalSec--;
        if (totalSec <= 0) {
            clearInterval(tick);
            timerEl.textContent = 'Expired';
            timerEl.style.color = '#fca5a5';
            if (verifyBtn) { verifyBtn.disabled = true; verifyBtn.textContent = 'Code expired — resend below'; }
            return;
        }
        const m = String(Math.floor(totalSec / 60)).padStart(2, '0');
        const s = String(totalSec % 60).padStart(2, '0');
        timerEl.textContent = m + ':' + s;
        if (totalSec <= 60) timerEl.style.color = '#fca5a5';
    }, 1000);
}

/* ── Resend cooldown (60 s) ─────────────────────────────────── */
const resendBtn      = document.getElementById('resendBtn');
const resendCountdown = document.getElementById('resendCountdown');
if (resendBtn && resendCountdown) {
    let cooldown = 60;
    const rTick = setInterval(() => {
        cooldown--;
        resendCountdown.textContent = cooldown;
        if (cooldown <= 0) {
            clearInterval(rTick);
            resendBtn.disabled = false;
            resendBtn.innerHTML = `<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline;margin-right:6px;vertical-align:middle;"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>Resend Code`;
        }
    }, 1000);
}
</script>
</body>
</html>