<?php
session_start();
require_once 'connection.php';

if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'company') {
        header('Location: compHomepage.php');
    } else {
        header('Location: homepage.php');
    }
    exit();
}

$pendingEmail = trim((string) ($_SESSION['pending_email'] ?? ''));
if ($pendingEmail === '' || !filter_var($pendingEmail, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['verification_notice'] = 'Enter your email to continue verification.';
    header('Location: verification_resume.php');
    exit();
}

$error = '';
$notice = '';
if (isset($_SESSION['verification_notice'])) {
    $notice = (string) $_SESSION['verification_notice'];
    unset($_SESSION['verification_notice']);
}

function maskEmail(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }

    $name = $parts[0];
    $domain = $parts[1];
    if (strlen($name) <= 2) {
        return str_repeat('*', strlen($name)) . '@' . $domain;
    }

    return substr($name, 0, 1) . str_repeat('*', max(strlen($name) - 2, 1)) . substr($name, -1) . '@' . $domain;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $verificationCode = trim($_POST['verification_code'] ?? '');

    if (!preg_match('/^\d{6}$/', $verificationCode)) {
        $error = 'Enter a valid 6-digit verification code.';
    } else {
        $stmt = mysqli_prepare($con, 'SELECT student_id, verification_code, is_verified FROM students WHERE email = ? LIMIT 1');

        if (!$stmt) {
            $error = 'A server error occurred. Please try again.';
        } else {
            mysqli_stmt_bind_param($stmt, 's', $pendingEmail);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $student = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);

            if (!$student) {
                unset($_SESSION['pending_email']);
                $_SESSION['verification_notice'] = 'No pending account found for that email. Please try again.';
                header('Location: verification_resume.php');
                exit();
            }

            if ((int) ($student['is_verified'] ?? 0) === 1) {
                unset($_SESSION['pending_email']);
                $_SESSION['auth_success'] = 'Email already verified. You can sign in now.';
                header('Location: login.php');
                exit();
            }

            $storedCode = (string) ($student['verification_code'] ?? '');
            if ($storedCode === '') {
                $error = 'No active verification code found. Please request a new code.';
            } elseif (hash_equals($storedCode, $verificationCode)) {
                $updateStmt = mysqli_prepare($con, 'UPDATE students SET is_verified = 1, verification_code = NULL WHERE student_id = ?');

                if (!$updateStmt) {
                    $error = 'A server error occurred. Please try again.';
                } else {
                    $studentId = (int) $student['student_id'];
                    mysqli_stmt_bind_param($updateStmt, 'i', $studentId);

                    if (mysqli_stmt_execute($updateStmt)) {
                        unset($_SESSION['pending_email']);
                        $_SESSION['auth_success'] = 'Email verified successfully. Please sign in.';
                        mysqli_stmt_close($updateStmt);
                        header('Location: login.php');
                        exit();
                    }

                    mysqli_stmt_close($updateStmt);
                    $error = 'Could not verify your account right now. Please try again.';
                }
            } else {
                $error = 'Invalid verification code. Please try again.';
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
    <title>CampusLink | Verify Email</title>
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container auth-container">
        <div class="auth-shell">
            <section class="auth-visual student-visual">
                <div class="auth-overlay">
                    <h2>Verify your email</h2>
                    <p>Enter the 6-digit code sent to your inbox to activate your student account.</p>
                </div>
            </section>

            <section class="auth-panel">
                <div class="brand compact-brand">
                    <h1>CampusLink</h1>
                    <p>Verification required for <?php echo htmlspecialchars(maskEmail($pendingEmail)); ?></p>
                </div>

                <?php if ($notice !== ''): ?>
                    <div class="auth-message success"><?php echo htmlspecialchars($notice); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="auth-message error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form class="auth-form" method="post" action="verify.php" novalidate>
                    <label for="verification_code">6-Digit Verification Code</label>
                    <input
                        type="text"
                        id="verification_code"
                        name="verification_code"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        autocomplete="one-time-code"
                        placeholder="Enter code"
                        required
                    >

                    <button type="submit">Verify Email</button>
                </form>

                <p class="auth-switch">Session missing or wrong email? <a href="verification_resume.php">Resume verification</a></p>
                <p class="auth-switch"><a href="login.php">Back to student login</a></p>
            </section>
        </div>
    </div>

    <script>
        (function () {
            const codeInput = document.getElementById('verification_code');
            codeInput.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
            });
        })();
    </script>
</body>
</html>
