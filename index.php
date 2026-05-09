<?php
session_start();

if (isset($_SESSION['role'])) {
    header('Location: homepage.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink | Choose Sign In</title>
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <header class="topbar">
            <div class="brand">
                <h1>CampusLink</h1>
                <p>Your mini internal campus economy</p>
            </div>
        </header>

        <section class="chooser-hero">
            <div class="chooser-copy">
                <h2>Welcome to your campus career and commerce hub</h2>
                <p>Sign in as a student to discover services, products, and jobs. Sign in as a recruiter to post opportunities and connect with talent.</p>
            </div>
            <div class="chooser-image" aria-hidden="true"></div>
        </section>

        <section class="role-grid">
            <a class="role-card" href="login.php">
                <h3>Sign In as Student</h3>
                <p>Access your student portal, apply to jobs, and manage services.</p>
            </a>
            <a class="role-card" href="compLogin.php">
                <h3>Sign In as Recruiter</h3>
                <p>Access your company account and manage your job listings.</p>
            </a>
        </section>

        <p class="auth-footnote">New here? <a href="register.php">Create an account</a></p>
    </div>
</body>
</html>
