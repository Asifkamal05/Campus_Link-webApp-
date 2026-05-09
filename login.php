<?php
session_start();
require_once 'connection.php';

if (isset($_SESSION['role'])) {
    header('Location: homepage.php');
    exit();
}

$error = '';
$success = '';

if (isset($_SESSION['auth_success'])) {
    $success = (string) $_SESSION['auth_success'];
    unset($_SESSION['auth_success']);
}

function verifyPassword(string $inputPassword, string $storedPassword): bool
{
    $info = password_get_info($storedPassword);

    if (($info['algo'] ?? 0) !== 0) {
        return password_verify($inputPassword, $storedPassword);
    }

    return hash_equals($storedPassword, $inputPassword);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = mysqli_prepare($con, 'SELECT student_id, username, passwd, f_name, l_name, email, is_verified FROM students WHERE username = ? LIMIT 1');

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $student = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);

            if ($student && verifyPassword($password, $student['passwd'])) {
                if ((int) ($student['is_verified'] ?? 0) !== 1) {
                    $_SESSION['pending_email'] = $student['email'];
                    $_SESSION['verification_notice'] = 'Verify your email to continue. Enter your latest 6-digit code.';
                    header('Location: verify.php');
                    exit();
                }

                $displayName = trim(($student['f_name'] ?? '') . ' ' . ($student['l_name'] ?? ''));
                if ($displayName === '') {
                    $displayName = $student['username'];
                }
                
                $_SESSION['role'] = 'student';
                $_SESSION['student_id'] = (int) $student['student_id'];
                $_SESSION['username'] = $displayName;
                $_SESSION['auth_username'] = $student['username'];

                $storedInfo = password_get_info($student['passwd']);
                if (($storedInfo['algo'] ?? 0) === 0) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $update = mysqli_prepare($con, 'UPDATE students SET passwd = ? WHERE student_id = ?');
                    if ($update) {
                        mysqli_stmt_bind_param($update, 'si', $newHash, $student['student_id']);
                        mysqli_stmt_execute($update);
                        mysqli_stmt_close($update);
                    }
                }

                header('Location: homepage.php');
                exit();
            }

            $error = 'Invalid student credentials.';
        } else {
            $error = 'A server error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink | Student Login</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
</head>
<body>
    <div class="container auth-container">
        <div class="auth-shell">
            <section class="auth-visual student-visual">
                <div class="auth-overlay">
                    <h2>Student Portal</h2>
                    <p>Build your campus career, list your skills, and discover opportunities.</p>
                </div>
            </section>

            <section class="auth-panel">
                <div class="brand compact-brand">
                    <h1>CampusLink</h1>
                    <p>Sign in as student</p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="auth-message error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="auth-message success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <form class="auth-form" method="post" action="login.php" novalidate>
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">

                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>

                    <button type="submit">Sign In</button>
                </form>

                <p class="auth-switch">Recruiter account? <a href="compLogin.php">Sign in here</a></p>
                <p class="auth-switch">No account? <a href="register.php">Register now</a></p>
                <p class="auth-switch"><a href="index.php">Back to role selection</a></p>
            </section>
        </div>
    </div>
</body>
</html>
