<?php
include "config.php";
include "email_helper.php";

if (isset($_POST['register'])) {
    $pseudo_name      = trim($_POST['pseudo_name'] ?? '');
    $username         = trim($_POST['username'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $error = '';

    if (empty($pseudo_name))        $error = "Pseudo-name is required.";
    elseif (empty($username))       $error = "Username is required.";
    elseif (empty($email))          $error = "Email is required.";
    elseif (strlen($password) < 6)  $error = "Password must be at least 6 characters.";
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
<title>Register - AnonymousReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--maroon:#8B0000;--maroon-light:#a30000;--gray-100:#f3f4f6;--gray-200:#e5e7eb;--gray-400:#9ca3af;--gray-600:#4b5563;--gray-800:#1f2937;--radius-sm:8px;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--gray-100);min-height:100vh;display:flex;}
.left-panel{width:55%;background:var(--maroon);color:white;padding:80px;border-top-right-radius:80px;border-bottom-right-radius:80px;display:flex;align-items:center;justify-content:center;flex-direction:column;}
.left-panel h1{font-family:'Playfair Display',serif;font-size:36px;line-height:1.3;margin-bottom:16px;}
.left-panel p{font-size:15px;opacity:0.75;max-width:340px;line-height:1.7;}
.highlight{color:#FFD700;}
.right-panel{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 24px;overflow-y:auto;}
.form-box{width:100%;max-width:400px;}
.form-box h2{font-family:'Playfair Display',serif;font-size:26px;color:var(--gray-800);margin-bottom:6px;}
.subtitle{font-size:14px;color:var(--gray-400);margin-bottom:28px;}
.form-group{margin-bottom:18px;}
.form-group label{display:block;font-size:13px;font-weight:600;color:var(--gray-600);margin-bottom:6px;}
.req{color:#ef4444;margin-left:2px;}
.hint{font-size:11px;color:var(--gray-400);margin-top:4px;}
.input-wrap{position:relative;}
.input-wrap input{width:100%;padding:11px 40px 11px 14px;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:14px;color:var(--gray-800);outline:none;transition:border-color 0.2s,box-shadow 0.2s;background:white;}
.input-wrap input:focus{border-color:var(--maroon);box-shadow:0 0 0 3px rgba(139,0,0,0.07);}
.eye-btn{position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-400);display:flex;align-items:center;padding:2px;transition:color 0.2s;}
.eye-btn:hover{color:var(--maroon);}
.pw-strength{height:4px;border-radius:2px;margin-top:6px;background:var(--gray-200);overflow:hidden;}
.pw-strength-bar{height:100%;border-radius:2px;transition:width 0.3s,background 0.3s;width:0%;}
.pw-strength-label{font-size:11px;margin-top:3px;}
.btn-register{width:100%;padding:12px;border-radius:20px;background:var(--maroon);color:white;border:none;font-family:'DM Sans',sans-serif;font-size:15px;font-weight:600;cursor:pointer;transition:background 0.2s,transform 0.15s;margin-top:6px;}
.btn-register:hover{background:var(--maroon-light);transform:translateY(-1px);}
.alert-error{background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px;border:1px solid #fca5a5;}
.link-text{font-size:13px;color:var(--gray-400);text-align:center;margin-top:20px;}
.link-text a{color:var(--maroon);font-weight:600;text-decoration:none;}
.link-text a:hover{text-decoration:underline;}
.divider{border:none;border-top:1px solid var(--gray-200);margin:16px 0;}
.pseudo-preview{display:none;align-items:center;gap:6px;background:#fff5f5;color:var(--maroon);border:1px solid rgba(139,0,0,0.15);border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;margin-top:6px;width:fit-content;}
@media(max-width:768px){body{flex-direction:column;}.left-panel{width:100%;border-radius:0 0 40px 40px;padding:40px 24px;}.left-panel h1{font-size:24px;}}
</style>
</head>
<body>
<div class="left-panel">
    <div>
        <h1>Anonymous Online<br><span class="highlight">Faculty Performance</span><br>Evaluation System</h1>
        <p>Share honest, anonymous feedback about your faculty members to help improve education quality.</p>
    </div>
</div>
<div class="right-panel">
    <div class="form-box">
        <h2>Create Account</h2>
        <p class="subtitle">Join anonymously — your identity stays private.</p>
        <?php if (!empty($error)): ?>
        <div class="alert-error">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        <form method="POST" id="regForm" novalidate>
            <div class="form-group">
                <label>Pseudo-Name <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="text" name="pseudo_name" id="pseudoInput" placeholder="e.g. SwiftFalcon042"
                        value="<?php echo htmlspecialchars($_POST['pseudo_name'] ?? ''); ?>"
                        required autocomplete="off" oninput="updatePseudoPreview(this.value)">
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
                <div class="input-wrap">
                    <input type="text" name="username" placeholder="Used for login only"
                        value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                        required autocomplete="username">
                </div>
                <div class="hint">Not shown publicly on reviews.</div>
            </div>
            <div class="form-group">
                <label>Email Address <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="email" name="email" placeholder="you@example.com"
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        required autocomplete="email">
                </div>
            </div>
            <div class="form-group">
                <label>Password <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="password" name="password" id="pw1" placeholder="Min. 6 characters"
                        required autocomplete="new-password" oninput="checkStrength(this.value);checkMatch()">
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
                    <input type="password" name="confirm_password" id="pw2" placeholder="Re-enter password"
                        required autocomplete="new-password" oninput="checkMatch()">
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
    input.type  = show ? 'text' : 'password';
    icon.innerHTML = show ? EYE_SLASH : EYE_OPEN;
}
function checkStrength(val) {
    const bar = document.getElementById('pwBar'), label = document.getElementById('pwLabel');
    if (!val) { bar.style.width='0%'; label.textContent=''; return; }
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels=[{w:'20%',bg:'#ef4444',txt:'Too short'},{w:'40%',bg:'#f97316',txt:'Weak'},{w:'60%',bg:'#f59e0b',txt:'Fair'},{w:'80%',bg:'#3b82f6',txt:'Good'},{w:'100%',bg:'#10b981',txt:'Strong'}];
    const l=levels[Math.min(score,4)];
    bar.style.width=l.w; bar.style.background=l.bg;
    label.style.color=l.bg; label.textContent=l.txt;
}
function checkMatch() {
    const p1=document.getElementById('pw1').value, p2=document.getElementById('pw2').value, msg=document.getElementById('matchMsg');
    if (!p2){msg.textContent='';return;}
    if(p1===p2){msg.style.color='#065f46';msg.textContent='✓ Passwords match';}
    else{msg.style.color='#991b1b';msg.textContent='✗ Passwords do not match';}
}
function updatePseudoPreview(val) {
    const p=document.getElementById('pseudoPreview'), t=document.getElementById('pseudoPreviewText');
    if(val.trim()){p.style.display='flex';t.textContent=val.trim();}
    else{p.style.display='none';}
}
window.addEventListener('DOMContentLoaded',()=>{
    const v=document.getElementById('pseudoInput').value;
    if(v)updatePseudoPreview(v);
});
document.getElementById('regForm').addEventListener('submit',function(e){
    const pw1=document.getElementById('pw1').value, pw2=document.getElementById('pw2').value;
    if(pw1.length<6){e.preventDefault();document.getElementById('pwLabel').textContent='✗ Must be at least 6 characters';document.getElementById('pwLabel').style.color='#991b1b';return;}
    if(pw1!==pw2){e.preventDefault();document.getElementById('matchMsg').style.color='#991b1b';document.getElementById('matchMsg').textContent='✗ Passwords do not match';}
});
</script>
</body>
</html>