<?php
session_start();
require_once 'connection.php';
require_once 'verification_mailer.php';

if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'company') {
        header('Location: compHomepage.php');
    } else {
        header('Location: homepage.php');
    }
    exit();
}

$error = '';
$notice = '';
$email = '';

if (isset($_SESSION['verification_notice'])) {
    $notice = (string) $_SESSION['verification_notice'];
    unset($_SESSION['verification_notice']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $stmt = mysqli_prepare($con, 'SELECT student_id, f_name, l_name, is_verified FROM students WHERE email = ? LIMIT 1');

        if (!$stmt) {
            $error = 'A server error occurred. Please try again.';
        } else {
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $student = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);

            if (!$student) {
                $error = 'No student account found with this email.';
            } elseif ((int) ($student['is_verified'] ?? 0) === 1) {
                $_SESSION['auth_success'] = 'Your email is already verified. Please sign in.';
                header('Location: login.php');
                exit();
            } else {
                $verificationCode = generateVerificationCode();
                $updateStmt = mysqli_prepare($con, 'UPDATE students SET verification_code = ? WHERE student_id = ?');

                if (!$updateStmt) {
                    $error = 'Could not start verification. Please try again.';
                } else {
                    $studentId = (int) $student['student_id'];
                    mysqli_stmt_bind_param($updateStmt, 'si', $verificationCode, $studentId);

                    if (mysqli_stmt_execute($updateStmt)) {
                        mysqli_stmt_close($updateStmt);

                        try {
                            $fullName = trim(($student['f_name'] ?? '') . ' ' . ($student['l_name'] ?? ''));
                            sendVerificationCodeEmail($email, $fullName, $verificationCode);
                            $_SESSION['pending_email'] = $email;
                            $_SESSION['verification_notice'] = 'A new 6-digit code has been sent to your email.';
                            header('Location: verify.php');
                            exit();
                        } catch (\Exception $mailException) {
                            $error = 'Could not send verification email right now. Please try again.';
                        }
                    } else {
                        mysqli_stmt_close($updateStmt);
                        $error = 'Could not generate a verification code. Please try again.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink | Resume Verification</title>
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container auth-container">
        <div class="auth-shell">
            <section class="auth-visual register-visual">
                <div class="auth-overlay">
                    <h2>Resume verification</h2>
                    <p>Enter your email and we will send a fresh 6-digit code.</p>
                </div>
            </section>

            <section class="auth-panel">
                <div class="brand compact-brand">
                    <h1>CampusLink</h1>
                    <p>Continue student account verification</p>
                </div>

                <?php if ($notice !== ''): ?>
                    <div class="auth-message success"><?php echo htmlspecialchars($notice); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="auth-message error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form class="auth-form" method="post" action="verification_resume.php" novalidate>
                    <label for="email">Registered Email</label>
                    <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($email); ?>">

                    <button type="submit">Send Verification Code</button>
                </form>

                <p class="auth-switch"><a href="login.php">Back to student login</a></p>
            </section>
        </div>
    </div>
</body>
</html>
