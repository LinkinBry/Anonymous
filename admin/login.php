<?php
// ── /admin/login.php ──────────────────────────────────────────────────────────
// Separate admin login portal — only accepts accounts with role = 'admin'.
// URL: olshcoreview.great-site.net/admin/login

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config.php';   // session + DB ($conn available)

// Already logged in?
if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin'
        ? 'admin_dashboard.php'
        : '../dashboard.php'));
    exit();
}

$error   = '';
$success = '';

// ── Handle login submission ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']      ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $safe = mysqli_real_escape_string($conn, $username);
        $row  = mysqli_fetch_assoc(
            mysqli_query($conn, "SELECT * FROM users WHERE username='$safe' LIMIT 1")
        );

        if (!$row) {
            // Deliberately vague — don't reveal whether the account exists
            $error = 'Invalid credentials. Access denied.';

        } elseif ($row['role'] !== 'admin') {
            // Account exists but is not an admin — show the same vague error
            $error = 'Invalid credentials. Access denied.';

        } elseif (!password_verify($password, $row['password'])) {
            $error = 'Invalid credentials. Access denied.';

        } else {
            // ── Successful admin login ────────────────────────────────────────
            session_regenerate_id(true);   // prevent session-fixation attacks

            $_SESSION['user_id']         = $row['id'];
            $_SESSION['fullname']        = $row['fullname'];
            $_SESSION['username']        = $row['username'];
            $_SESSION['role']            = $row['role'];
            $_SESSION['last_activity']   = time();
            $_SESSION['session_expires'] = time() + (defined('SESSION_IDLE_TIMEOUT') ? SESSION_IDLE_TIMEOUT : 300);

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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*  { margin:0; padding:0; box-sizing:border-box; }
html, body { height:100%; overflow:hidden; }

body {
    font-family: 'Inter', sans-serif;
    background: #0f0f13;
    display: flex;
    align-items: stretch;
}

/* ── LEFT PANEL — dark brand panel ──────────────────────────────────────── */
.left {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 50px;
    background: linear-gradient(160deg, #1a0000 0%, #0f0f13 60%);
    position: relative;
    overflow: hidden;
}
/* subtle grid overlay */
.left::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(139,0,0,0.08) 1px, transparent 1px),
        linear-gradient(90deg, rgba(139,0,0,0.08) 1px, transparent 1px);
    background-size: 40px 40px;
    pointer-events: none;
}
.left-logo {
    width: 130px; height: 130px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(139,0,0,0.5);
    box-shadow: 0 0 40px rgba(139,0,0,0.3), 0 0 80px rgba(139,0,0,0.1);
    margin-bottom: 28px;
    position: relative;
    z-index: 1;
}
.left-title {
    font-size: clamp(18px, 2.2vw, 28px);
    font-weight: 700;
    color: #fff;
    line-height: 1.3;
    text-align: center;
    position: relative;
    z-index: 1;
}
.left-title .accent { color: #8B0000; }
.left-sub {
    margin-top: 12px;
    font-size: 13px;
    color: rgba(255,255,255,0.35);
    text-align: center;
    position: relative;
    z-index: 1;
    letter-spacing: 0.3px;
}

/* ── ADMIN BADGE ─────────────────────────────────────────────────────────── */
.admin-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(139,0,0,0.15);
    border: 1px solid rgba(139,0,0,0.35);
    border-radius: 20px;
    padding: 5px 14px;
    font-size: 11px;
    font-weight: 600;
    color: #c84b4b;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    margin-bottom: 20px;
    position: relative;
    z-index: 1;
}
.admin-badge svg { flex-shrink: 0; }

/* ── RIGHT PANEL — login form ────────────────────────────────────────────── */
.right {
    width: 480px;
    flex-shrink: 0;
    height: 100vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 52px;
    background: #17171d;
    border-left: 1px solid rgba(255,255,255,0.06);
}
.right::-webkit-scrollbar { width: 4px; }
.right::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }

.form-card { width: 100%; max-width: 380px; }

.form-title {
    font-size: clamp(26px, 3.5vw, 38px);
    font-weight: 800;
    color: #fff;
    margin-bottom: 6px;
    letter-spacing: -0.5px;
}
.form-sub {
    font-size: 14px;
    color: rgba(255,255,255,0.38);
    margin-bottom: 32px;
    line-height: 1.6;
}

/* ── ALERTS ──────────────────────────────────────────────────────────────── */
.alert {
    display: flex;
    align-items: center;
    gap: 9px;
    border-radius: 10px;
    padding: 11px 14px;
    font-size: 13px;
    margin-bottom: 20px;
    line-height: 1.5;
}
.alert-error {
    background: rgba(220,38,38,0.12);
    border: 1px solid rgba(220,38,38,0.3);
    color: #f87171;
}
.alert-timeout {
    background: rgba(245,158,11,0.1);
    border: 1px solid rgba(245,158,11,0.3);
    color: #fbbf24;
}

/* ── FIELD LABEL ─────────────────────────────────────────────────────────── */
.field-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255,255,255,0.5);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 8px;
}

/* ── INPUT ───────────────────────────────────────────────────────────────── */
.input-wrap { position: relative; margin-bottom: 18px; }
.input-wrap .icon {
    position: absolute;
    left: 15px; top: 50%;
    transform: translateY(-50%);
    color: rgba(255,255,255,0.25);
    pointer-events: none;
    display: flex;
    align-items: center;
}
.input-wrap input {
    width: 100%;
    padding: 13px 16px 13px 44px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    color: #fff;
    outline: none;
    transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
}
.input-wrap input:focus {
    border-color: rgba(139,0,0,0.7);
    background: rgba(139,0,0,0.06);
    box-shadow: 0 0 0 3px rgba(139,0,0,0.15);
}
.input-wrap input::placeholder { color: rgba(255,255,255,0.2); }
.input-wrap.has-eye input { padding-right: 44px; }

.eye-btn {
    position: absolute;
    right: 13px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    cursor: pointer; color: rgba(255,255,255,0.25);
    display: flex; align-items: center;
    padding: 4px;
    transition: color 0.2s;
}
.eye-btn:hover { color: rgba(255,255,255,0.6); }

/* ── SUBMIT BUTTON ───────────────────────────────────────────────────────── */
.btn-submit {
    width: 100%;
    padding: 14px;
    background: #8B0000;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-family: 'Inter', sans-serif;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
    margin-top: 4px;
    box-shadow: 0 4px 20px rgba(139,0,0,0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.btn-submit:hover {
    background: #a30000;
    transform: translateY(-1px);
    box-shadow: 0 6px 28px rgba(139,0,0,0.5);
}
.btn-submit:active { transform: translateY(0); }

/* ── DIVIDER / BACK LINK ─────────────────────────────────────────────────── */
.divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 24px 0;
}
.divider hr { flex: 1; border: none; border-top: 1px solid rgba(255,255,255,0.07); }
.divider span { font-size: 11px; color: rgba(255,255,255,0.2); white-space: nowrap; }

.back-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    font-size: 13px;
    color: rgba(255,255,255,0.3);
    text-decoration: none;
    transition: color 0.2s;
}
.back-link:hover { color: rgba(255,255,255,0.6); }

/* ── SECURITY NOTICE ─────────────────────────────────────────────────────── */
.security-notice {
    margin-top: 28px;
    padding: 12px 14px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 8px;
    display: flex;
    align-items: flex-start;
    gap: 9px;
}
.security-notice p {
    font-size: 11px;
    color: rgba(255,255,255,0.25);
    line-height: 1.6;
}

/* ── RESPONSIVE ──────────────────────────────────────────────────────────── */
@media (max-width: 760px) {
    html, body { overflow: auto; }
    .left { display: none; }
    .right { width: 100%; height: auto; padding: 40px 24px; }
}
</style>
</head>
<body>

<!-- ── Left brand panel ────────────────────────────────────────────────────── -->
<div class="left">
    <img src="../image/logo.png" alt="OLSHCO" class="left-logo"
    <div class="admin-badge">
        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2"
             viewBox="0 0 24 24">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        Administrator Portal
    </div>
    <div class="left-title">
        OlshcoReview<br>
        <span class="accent">Admin Access</span>
    </div>
    <div class="left-sub">
        Restricted area · Authorized personnel only<br>
        olshcoreview.great-site.net
    </div>
</div>

<!-- ── Right login form ────────────────────────────────────────────────────── -->
<div class="right">
    <div class="form-card">

        <h1 class="form-title">Admin Login</h1>
        <p class="form-sub">Sign in with your administrator credentials to access the control panel.</p>

        <?php if (isset($_GET['timeout'])): ?>
        <div class="alert alert-timeout">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                 viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            Your session expired due to inactivity. Please log in again.
        </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
        <div class="alert alert-error">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                 viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">

            <label class="field-label">Username</label>
            <div class="input-wrap">
                <span class="icon">
                    <svg width="16" height="16" fill="none" stroke="currentColor"
                         stroke-width="2" viewBox="0 0 24 24">
                        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </span>
                <input type="text" name="username"
                       placeholder="Admin username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       required autofocus autocomplete="off">
            </div>

            <label class="field-label">Password</label>
            <div class="input-wrap has-eye">
                <span class="icon">
                    <svg width="16" height="16" fill="none" stroke="currentColor"
                         stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0110 0v4"/>
                    </svg>
                </span>
                <input type="password" name="password" id="adminPw"
                       placeholder="Admin password"
                       required autocomplete="current-password">
                <button type="button" class="eye-btn"
                        onclick="toggleEye()" aria-label="Toggle password visibility">
                    <svg id="eye-open" width="16" height="16" fill="none"
                         stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg id="eye-closed" width="16" height="16" fill="none"
                         stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                         style="display:none;">
                        <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                        <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                </button>
            </div>

            <button type="submit" name="admin_login" class="btn-submit">
                <svg width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/>
                    <polyline points="10 17 15 12 10 7"/>
                    <line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                Sign in to Admin Panel
            </button>

        </form>

        <div class="divider">
            <hr><span>or go back</span><hr>
        </div>

        <a href="../login.php" class="back-link">
                 stroke-width="2" viewBox="0 0 24 24">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
            </svg>
            Student login page
        </a>

        <div class="security-notice">
            <svg width="14" height="14" fill="none" stroke="rgba(255,255,255,0.25)"
                 stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            <p>
                This portal is restricted to system administrators only.
                Unauthorized access attempts are logged.
                Students should use the <a href="../login.php"
                style="color:rgba(255,255,255,0.4);text-decoration:underline;">student login</a>.
            </p>
        </div>

    </div>
</div>

<script>
function toggleEye() {
    const inp    = document.getElementById('adminPw');
    const isHide = inp.type === 'password';
    inp.type     = isHide ? 'text' : 'password';
    document.getElementById('eye-open').style.display   = isHide ? 'none' : '';
    document.getElementById('eye-closed').style.display = isHide ? ''     : 'none';
}
</script>
</body>
</html>