<?php
include "config.php";


if(isset($_POST['login'])){
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Find user in database
    $sql = "SELECT * FROM users WHERE username='$username'";
    $result = mysqli_query($conn, $sql);

    if(!$result){
        // Query failed, show error
        die("Query failed: " . mysqli_error($conn));
    }

    $user = mysqli_fetch_assoc($result);

    if($user){
        if(password_verify($password, $user['password'])){
            // Success: set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role']; // <-- store role in session

            // Redirect based on role
            if($user['role'] == 'admin'){
                header("Location: admin_dashboard.php");
            } else {
                header("Location: dashboard.php");
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
<html>
<head>
<title>Login</title>
<link rel="stylesheet" href="style.css">
</head>

<body>

<div class="container">

<div class="left-panel">

<h1>
Anonymous Online<br>
<span class="highlight">Faculty Performance</span><br>
Evaluation and Feedback System
</h1>

</div>

<div class="right-panel">

<div class="form-box">

<h2>Log in</h2>

<?php if(isset($error)) { echo "<p style='color:red;margin-bottom:15px;'>$error</p>"; } ?>

<form method="POST">

<div class="input-group">
<input type="text" name="username" placeholder="Username" required>
</div>

<div class="input-group">
<input type="password" name="password" placeholder="Password" required>
</div>

<button type="submit" name="login" class="login-button">Login</button>

</form>

<p class="link-text">
Don't have an account?
<a href="register.php">Register</a>
</p>

</div>

</div>

</div>

</body>
</html>