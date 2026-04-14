<?php
// ── /admin/login.php ──────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin'
        ? 'admin_dashboard.php'
        : '../dashboard.php'));
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $safe = mysqli_real_escape_string($conn, $username);
        $row  = mysqli_fetch_assoc(
            mysqli_query($conn, "SELECT * FROM users WHERE username='$safe' LIMIT 1")
        );

        if (!$row || $row['role'] !== 'admin' || !password_verify($password, $row['password'])) {
            $error = 'Invalid credentials. Access denied.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id']         = $row['id'];
            $_SESSION['fullname']        = $row['fullname'];
            $_SESSION['username']        = $row['username'];
            $_SESSION['role']            = $row['role'];
            $_SESSION['last_activity']   = time();
            $_SESSION['session_expires'] = time() + (defined('SESSION_IDLE_TIMEOUT') ? SESSION_IDLE_TIMEOUT : 1200);

            header('Location: admin_dashboard.php');
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
<title>Admin Portal — OlshcoReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --maroon:       #8B0000;
    --maroon-mid:   #6e0000;
    --maroon-deep:  #3d0000;
    --maroon-glow:  rgba(139,0,0,0.22);
    --maroon-rim:   rgba(139,0,0,0.45);
    --gold:         #e8a838;
    --gold-dim:     rgba(232,168,56,0.55);

    /* Lighter than before — more maroon-infused */
    --bg:           #1a0a0a;
    --mid:          #221010;
    --surface:      rgba(255,255,255,0.04);
    --border:       rgba(139,0,0,0.18);
    --border-focus: rgba(139,0,0,0.7);

    --text:         #f5eded;
    --muted:        rgba(245,237,237,0.42);
    --placeholder:  rgba(245,237,237,0.2);
}

html, body {
    height: 100%;
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    overflow: hidden;
}

/* ── FULL-PAGE ATMOSPHERIC LAYER ── */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    /* Warm maroon radial haze — lighter than solid dark */
    background:
        radial-gradient(ellipse 70% 65% at 50% 20%, rgba(100,0,0,0.28) 0%, transparent 70%),
        radial-gradient(ellipse 55% 50% at 20% 80%, rgba(80,0,0,0.18) 0%, transparent 60%),
        radial-gradient(ellipse 40% 35% at 80% 70%, rgba(60,0,0,0.12) 0%, transparent 55%);
    pointer-events: none;
}

/* Subtle repeating grain pattern */
body::after {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");
    opacity: 0.6;
    pointer-events: none;
}

/* Horizontal accent line near top */
.top-accent {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg,
        transparent 0%,
        var(--maroon) 30%,
        var(--gold-dim) 50%,
        var(--maroon) 70%,
        transparent 100%);
    z-index: 10;
}

/* ── CENTERED LAYOUT ── */
.page {
    position: relative;
    z-index: 1;
    height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 24px;
    gap: 0;
}

/* ── LOGO ── */
.logo-wrap {
    position: relative;
    margin-bottom: 20px;
    animation: fadeDown 0.6s ease both;
}

.logo-wrap img {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    object-fit: cover;
    display: block;
    border: 2px solid var(--maroon-rim);
    box-shadow:
        0 0 0 5px rgba(139,0,0,0.09),
        0 0 40px rgba(139,0,0,0.3),
        0 16px 48px rgba(0,0,0,0.6);
}

/* Glowing ring pulse */
.logo-wrap::after {
    content: '';
    position: absolute;
    inset: -8px;
    border-radius: 50%;
    border: 1px solid rgba(139,0,0,0.2);
    animation: ringPulse 3s ease-in-out infinite;
}

@keyframes ringPulse {
    0%, 100% { transform: scale(1);   opacity: 0.6; }
    50%       { transform: scale(1.06); opacity: 0; }
}

/* ── BRAND NAME ── */
.brand-name {
    font-family: 'Playfair Display', serif;
    font-size: 28px;
    font-weight: 700;
    color: #fff;
    text-align: center;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
    animation: fadeDown 0.6s 0.08s ease both;
}

/* ── ADMIN ACCESS LABEL ── */
.admin-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--gold);
    margin-bottom: 40px;
    animation: fadeDown 0.6s 0.14s ease both;
}

.admin-label .dot {
    width: 5px; height: 5px;
    border-radius: 50%;
    background: var(--gold);
    opacity: 0.7;
}

/* ── ALERTS ── */
.alert {
    width: 100%;
    max-width: 340px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    line-height: 1.55;
    margin-bottom: 22px;
    animation: fadeDown 0.4s ease both;
}
.alert-error {
    background: rgba(200,30,30,0.12);
    border: 1px solid rgba(200,30,30,0.3);
    color: #f07070;
}
.alert-timeout {
    background: rgba(200,140,30,0.1);
    border: 1px solid rgba(200,140,30,0.28);
    color: #e4b64a;
}
.alert svg { flex-shrink: 0; margin-top: 1px; }

/* ── FORM FIELDS (no enclosing box) ── */
.fields {
    width: 100%;
    max-width: 340px;
    display: flex;
    flex-direction: column;
    gap: 0;
    animation: fadeUp 0.6s 0.2s ease both;
}

.field-group {
    margin-bottom: 16px;
}

.field-label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--muted);
    margin-bottom: 7px;
}

.input-wrap {
    position: relative;
}
.input-wrap .ico {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: rgba(245,237,237,0.18);
    pointer-events: none;
    display: flex;
    align-items: center;
}
.input-wrap input {
    width: 100%;
    padding: 13px 16px 13px 42px;
    background: rgba(255,255,255,0.045);
    border: 1px solid var(--border);
    border-radius: 10px;
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--text);
    outline: none;
    transition: border-color 0.22s, background 0.22s, box-shadow 0.22s;
}
.input-wrap input::placeholder { color: var(--placeholder); }
.input-wrap input:focus {
    background: rgba(139,0,0,0.07);
    border-color: var(--border-focus);
    box-shadow: 0 0 0 3px rgba(139,0,0,0.14);
}
.input-wrap.has-eye input { padding-right: 44px; }

.eye-btn {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: var(--muted);
    display: flex;
    align-items: center;
    padding: 4px;
    transition: color 0.18s;
}
.eye-btn:hover { color: rgba(245,237,237,0.7); }

/* ── LOGIN BUTTON ── */
.btn-login {
    width: 10%;
    padding: 14px;
    margin-top: 8px;
    background: var(--maroon-mid);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-family: 'DM Sans', sans-serif;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
    box-shadow: 0 4px 24px rgba(139,0,0,0.38);
    animation: fadeUp 0.6s 0.28s ease both;
}
.btn-login:hover {
    background: #a50000;
    transform: translateY(-1px);
    box-shadow: 0 6px 32px rgba(139,0,0,0.5);
}
.btn-login:active { transform: translateY(0); }

/* ── BACK LINK ── */
.back-row {
    margin-top: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: fadeUp 0.5s 0.36s ease both;
}
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--muted);
    text-decoration: none;
    transition: color 0.18s;
}
.back-link:hover { color: rgba(245,237,237,0.7); }

/* ── FOOTER NOTE ── */
.footer-note {
    position: fixed;
    bottom: 18px;
    left: 0; right: 0;
    text-align: center;
    font-size: 10px;
    color: rgba(245,237,237,0.18);
    letter-spacing: 0.5px;
    z-index: 1;
}

/* ── ANIMATIONS ── */
@keyframes fadeDown {
    from { opacity: 0; transform: translateY(-14px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}

@media (max-width: 480px) {
    .brand-name { font-size: 22px; }
    .fields, .alert { max-width: 100%; }
}
</style>
</head>
<body>

<div class="top-accent"></div>

<div class="page">

    <!-- LOGO -->
    <div class="logo-wrap">
        <img src="../image/logo.png" alt="OlshcoReview"
             onerror="this.style.background='var(--maroon)';this.src='';this.alt='O';">
    </div>

    <!-- BRAND NAME -->
    <div class="brand-name">OLSHCOReview</div>

    <!-- ADMIN ACCESS LABEL -->
    <div class="admin-label">
        <span class="dot"></span>
        Admin Access
        <span class="dot"></span>
    </div>

    <!-- ALERTS -->
    <?php if (isset($_GET['timeout'])): ?>
    <div class="alert alert-timeout" style="max-width:340px;width:100%;">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        Your session expired due to inactivity. Please log in again.
    </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
    <div class="alert alert-error" style="max-width:340px;width:100%;">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- FORM FIELDS — no box, just floating on the dark bg -->
    <form method="POST" autocomplete="off" style="width:100%;max-width:340px;display:contents;">

        <div class="fields">

            <!-- USERNAME -->
            <div class="field-group">
                <label class="field-label" for="username">Username</label>
                <div class="input-wrap">
                    <span class="ico">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </span>
                    <input type="text" id="username" name="username"
                           placeholder="Admin username"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           required autofocus autocomplete="off">
                </div>
            </div>

            <!-- PASSWORD -->
            <div class="field-group">
                <label class="field-label" for="adminPw">Password</label>
                <div class="input-wrap has-eye">
                    <span class="ico">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0110 0v4"/>
                        </svg>
                    </span>
                    <input type="password" id="adminPw" name="password"
                           placeholder="Admin password"
                           required autocomplete="current-password">
                    <button type="button" class="eye-btn" onclick="toggleEye()" aria-label="Toggle password visibility">
                        <svg id="eye-open" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <svg id="eye-closed" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:none;">
                            <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                            <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                    </button>
                </div>
            </div>

        </div>

        <!-- LOGIN BUTTON -->
        <button type="submit" name="admin_login" class="btn-login">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/>
                <polyline points="10 17 15 12 10 7"/>
                <line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
            Sign in
        </button>

    </form>

    <!-- BACK LINK -->
    <div class="back-row">
        <a href="../login.php" class="back-link">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
            </svg>
            Back to student login
        </a>
    </div>

</div>

<div class="footer-note">OlshcoReview &mdash; Restricted Administrator Portal</div>

<script>
function toggleEye() {
    const inp = document.getElementById('adminPw');
    const hide = inp.type === 'password';
    inp.type = hide ? 'text' : 'password';
    document.getElementById('eye-open').style.display   = hide ? 'none' : '';
    document.getElementById('eye-closed').style.display = hide ? ''     : 'none';
}
</script>
</body>
</html>