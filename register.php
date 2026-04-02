<?php
include "config.php";
include "email_helper.php";

if (isset($_POST['register'])) {
    $pseudo_name      = trim($_POST['pseudo_name']      ?? '');
    $username         = trim($_POST['username']         ?? '');
    $email            = trim($_POST['email']            ?? '');
    $password         = $_POST['password']              ?? '';
    $confirm_password = $_POST['confirm_password']      ?? '';

    $error = '';

    if (empty($pseudo_name))            $error = "Pseudo-name is required.";
    elseif (empty($username))           $error = "Username is required.";
    elseif (empty($email))              $error = "Email is required.";
    elseif (strlen($password) < 6)      $error = "Password must be at least 6 characters.";
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
            mysqli_query($conn, "INSERT INTO users (fullname, username, email, password)
                                  VALUES ('$pseudo_safe','$user_safe','$email_safe','$hashed_safe')");
            sendBrevoEmail($email, $pseudo_name, 'Welcome to AnonymousReview!', welcomeEmailHtml($pseudo_name));
            header("Location: index.php");
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
<title>Register — AnonymousReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-page">

<div class="left-panel">
    <div>
        <h1>
            Anonymous Online<br>
            <span class="highlight">Faculty Performance</span><br>
            Evaluation System
        </h1>
        <p>Share honest, anonymous feedback about your faculty members to help improve education quality.</p>
    </div>
</div>

<div class="right-panel">
    <div class="form-box">
        <h2>Create Account</h2>
        <p class="subtitle">Join anonymously — your identity stays private.</p>

        <?php if (!empty($error)): ?>
        <div class="auth-alert auth-alert-error">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="regForm" novalidate>

            <div class="form-group">
                <label>Pseudo-Name <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="text" name="pseudo_name" id="pseudoInput"
                           placeholder="e.g. SwiftFalcon042"
                           value="<?php echo htmlspecialchars($_POST['pseudo_name'] ?? ''); ?>"
                           required autocomplete="off"
                           oninput="updatePseudoPreview(this.value)">
                </div>
                <div class="hint">Your display name in the system — choose anything you like.</div>
                <div id="pseudoPreview" class="pseudo-preview">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                    <span id="pseudoPreviewText"></span>
                </div>
            </div>

            <hr class="divider">

            <div class="form-group">
                <label>Username <span class="req">*</span></label>
                <input type="text" name="username"
                       placeholder="Used for login only"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                       required autocomplete="username">
                <div class="hint">Not shown publicly on reviews.</div>
            </div>

            <div class="form-group">
                <label>Email Address <span class="req">*</span></label>
                <input type="email" name="email"
                       placeholder="you@example.com"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                       required autocomplete="email">
            </div>

            <div class="form-group">
                <label>Password <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="password" name="password" id="pw1"
                           placeholder="Min. 6 characters"
                           required autocomplete="new-password"
                           oninput="checkStrength(this.value); checkMatch()">
                    <button type="button" class="eye-btn" onclick="toggleEye('pw1','eye1')" tabindex="-1">
                        <svg id="eye1" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
                <div class="pw-strength-label" id="pwLabel" style="color:var(--gray-400);"></div>
            </div>

            <div class="form-group">
                <label>Confirm Password <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="password" name="confirm_password" id="pw2"
                           placeholder="Re-enter password"
                           required autocomplete="new-password"
                           oninput="checkMatch()">
                    <button type="button" class="eye-btn" onclick="toggleEye('pw2','eye2')" tabindex="-1">
                        <svg id="eye2" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div class="hint" id="matchMsg"></div>
            </div>

            <button type="submit" name="register" class="btn-register">Create Account</button>
        </form>

        <p class="link-text">Already have an account? <a href="index.php">Log in</a></p>
    </div>
</div>

<script>
const EYE_OPEN  = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
const EYE_SLASH = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';

function toggleEye(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    const show  = input.type === 'password';
    input.type     = show ? 'text' : 'password';
    icon.innerHTML = show ? EYE_SLASH : EYE_OPEN;
}

function checkStrength(val) {
    const bar   = document.getElementById('pwBar');
    const label = document.getElementById('pwLabel');
    if (!val) { bar.style.width = '0%'; label.textContent = ''; return; }
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        { w: '20%', bg: '#ef4444', txt: 'Too short' },
        { w: '40%', bg: '#f97316', txt: 'Weak'      },
        { w: '60%', bg: '#f59e0b', txt: 'Fair'      },
        { w: '80%', bg: '#3b82f6', txt: 'Good'      },
        { w: '100%',bg: '#10b981', txt: 'Strong'    }
    ];
    const l = levels[Math.min(score, 4)];
    bar.style.width      = l.w;
    bar.style.background = l.bg;
    label.style.color    = l.bg;
    label.textContent    = l.txt;
}

function checkMatch() {
    const p1  = document.getElementById('pw1').value;
    const p2  = document.getElementById('pw2').value;
    const msg = document.getElementById('matchMsg');
    if (!p2) { msg.textContent = ''; return; }
    if (p1 === p2) { msg.style.color = '#065f46'; msg.textContent = '✓ Passwords match'; }
    else           { msg.style.color = '#991b1b'; msg.textContent = '✗ Passwords do not match'; }
}

function updatePseudoPreview(val) {
    const p = document.getElementById('pseudoPreview');
    const t = document.getElementById('pseudoPreviewText');
    if (val.trim()) { p.style.display = 'flex'; t.textContent = val.trim(); }
    else              p.style.display = 'none';
}

window.addEventListener('DOMContentLoaded', () => {
    const v = document.getElementById('pseudoInput').value;
    if (v) updatePseudoPreview(v);
});

document.getElementById('regForm').addEventListener('submit', function(e) {
    const pw1 = document.getElementById('pw1').value;
    const pw2 = document.getElementById('pw2').value;
    if (pw1.length < 6) {
        e.preventDefault();
        document.getElementById('pwLabel').textContent  = '✗ Must be at least 6 characters';
        document.getElementById('pwLabel').style.color  = '#991b1b';
        return;
    }
    if (pw1 !== pw2) {
        e.preventDefault();
        document.getElementById('matchMsg').style.color = '#991b1b';
        document.getElementById('matchMsg').textContent = '✗ Passwords do not match';
    }
});
</script>

</body>
</html>