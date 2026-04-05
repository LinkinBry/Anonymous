<?php
session_start();
include "config.php";

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: /admin_dashboard");
    } else {
        header("Location: /dashboard");
    }
    exit();
}

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $sql    = "SELECT * FROM users WHERE username='$username'";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        die("Query failed: " . mysqli_error($conn));
    }

    $user = mysqli_fetch_assoc($result);

    if ($user) {
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id']         = $user['id'];
            $_SESSION['fullname']        = $user['fullname'];
            $_SESSION['username']        = $user['username'];
            $_SESSION['role']            = $user['role'];
            $_SESSION['last_activity']   = time();
            $_SESSION['session_expires'] = time() + 300;

            if ($user['role'] == 'admin') {
                header("Location: /admin_dashboard");
            } else {
                header("Location: /dashboard");
            }
            exit();
        } else {
            $error = "Incorrect password";
        }
    } else {
        $error = "Username not found";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — AnonymousReview</title>
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
            Evaluation and Feedback System
        </h1>
        <p>Share honest, anonymous feedback about your faculty members to help improve education quality.</p>
    </div>
</div>

<div class="right-panel">
    <div class="form-box">
        <h2>Log in</h2>
        <p class="subtitle">Welcome back. Enter your credentials to continue.</p>

        <?php if (isset($_GET['timeout'])): ?>
        <div class="auth-alert auth-alert-warning">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            You were logged out due to inactivity.
        </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
        <div class="auth-alert auth-alert-error">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <input type="text" name="username" placeholder="Username" required autocomplete="username">
            </div>
            <div class="input-group">
                <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
            </div>
            <button type="submit" name="login">Login</button>
        </form>

        <p class="link-text">
            Don't have an account? <a href="/register">Register</a>
        </p>
    </div>
</div>

</body>
</html>