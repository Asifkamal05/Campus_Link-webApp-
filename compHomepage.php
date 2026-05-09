<?php
session_start();
require_once 'connection.php';

if (($_SESSION['role'] ?? '') !== 'company' || !isset($_SESSION['company_id'])) {
    if (($_SESSION['role'] ?? '') === 'student' && isset($_SESSION['student_id'])) {
        header('Location: homepage.php');
    } else {
        header('Location: index.php');
    }
    exit();
}

$companyName = htmlspecialchars($_SESSION['username'] ?? 'Company');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink | Company Homepage</title>
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <header class="topbar">
            <div class="brand">
                <h1>CampusLink Recruiter</h1>
                <p>Welcome back, <?php echo $companyName; ?>.</p>
            </div>

            <div class="user-menu">
                <button class="user-trigger" type="button"><?php echo $companyName; ?></button>
                <div class="dropdown">
                    <a href="myProfile.php">My Profile</a>
                    <form method="post" action="logout.php" class="logout-form">
                        <button type="submit" class="dropdown-action">Log Out</button>
                    </form>
                </div>
            </div>
        </header>

        <section class="hero">
            <h2>Company dashboard access is secured</h2>
            <p>Only authenticated recruiter accounts can open this page.</p>
        </section>
    </div>
</body>
</html>
