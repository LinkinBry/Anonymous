<?php
include "config.php";

if(isset($_POST['register'])){

$fullname = $_POST['fullname'];
$username = $_POST['username'];
$email = $_POST['email'];
$password = $_POST['password'];
$confirm_password = $_POST['confirm_password'];

// Check if passwords match
if($password !== $confirm_password){
    $error = "Passwords do not match";
} else {
    // Check if username or email already exist
    $check_sql = "SELECT * FROM users WHERE username='$username' OR email='$email'";
    $check_result = mysqli_query($conn, $check_sql);

    if(mysqli_num_rows($check_result) > 0){
        $error = "Username or Email already exists";
    } else {
        // Save to database
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (fullname, username, email, password)
                VALUES ('$fullname','$username','$email','$hashed_password')";
        mysqli_query($conn,$sql);

        header("Location: index.php");
        exit();
    }
}
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Register</title>
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

<h2>Register</h2>

<?php if(isset($error)) { echo "<p style='color:red;margin-bottom:15px;'>$error</p>"; } ?>

<form method="POST">

<div class="input-group">
<input type="text" name="fullname" placeholder="Full Name" required>
</div>

<div class="input-group">
<input type="text" name="username" placeholder="Username" required>
</div>

<div class="input-group">
<input type="email" name="email" placeholder="Email" required>
</div>

<div class="input-group">
<input type="password" name="password" placeholder="Password" required>
</div>

<div class="input-group">
<input type="password" name="confirm_password" placeholder="Confirm Password" required>
</div>

<button type="submit" name="register" class="register-button">
Register
</button>

</form>

<p class="link-text">
Already have an account?
<a href="index.php">Log in</a>
</p>

</div>

</div>

</div>

</body>
</html>