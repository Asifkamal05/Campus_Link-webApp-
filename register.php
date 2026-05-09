<?php
session_start();
require_once 'connection.php';
require_once 'verification_mailer.php';

if (isset($_SESSION['role'])) {
    header('Location: homepage.php');
    exit();
}

$error = '';
$success = '';
$accountType = $_POST['account_type'] ?? 'student';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountType = ($_POST['account_type'] ?? 'student') === 'company' ? 'company' : 'student';

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($username === '' || $password === '' || $confirmPassword === '' || $address === '' || $email === '') {
        $error = 'Please fill in all required fields.';
    } elseif (strlen($username) < 3) {
        $error = 'Username must be at least 3 characters long.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if ($accountType === 'student') {
            $fName = trim($_POST['f_name'] ?? '');
            $mName = trim($_POST['m_name'] ?? '');
            $lName = trim($_POST['l_name'] ?? '');
            $birthDay = trim($_POST['birth_day'] ?? '');

            $dateObj = DateTime::createFromFormat('Y-m-d', $birthDay);
            $validBirthDate = $dateObj && $dateObj->format('Y-m-d') === $birthDay;

            if ($fName === '' || !$validBirthDate) {
                $error = 'For student registration, first name and a valid birth date are required.';
            } else {
                $mName = $mName === '' ? null : $mName;
                $lName = $lName === '' ? null : $lName;
                $phone = $phone === '' ? null : $phone;
                $verificationCode = generateVerificationCode();
                $fullName = trim($fName . ' ' . ($lName ?? ''));

                $stmt = mysqli_prepare(
                    $con,
                    'INSERT INTO students (username, passwd, f_name, m_name, l_name, address, birth_day, email, phone, verification_code, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
                );

                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ssssssssss', $username, $passwordHash, $fName, $mName, $lName, $address, $birthDay, $email, $phone, $verificationCode);

                    if (mysqli_stmt_execute($stmt)) {
                        try {
                            sendVerificationCodeEmail($email, $fullName, $verificationCode);
                            $_SESSION['pending_email'] = $email;
                            $_SESSION['verification_notice'] = 'We sent a 6-digit verification code to your email.';
                            header('Location: verify.php');
                            exit();
                        } catch (\Exception $mailException) {
                            $_SESSION['pending_email'] = $email;
                            $_SESSION['verification_notice'] = 'Account created, but email delivery failed. Request a fresh code.';
                            header('Location: verification_resume.php');
                            exit();
                        }
                    } else {
                        $error = mysqli_errno($con) === 1062
                            ? 'Username or email already exists.'
                            : 'Registration failed. Please try again.';
                    }

                    mysqli_stmt_close($stmt);
                } else {
                    $error = 'A server error occurred. Please try again.';
                }
            }
        } else {
            $companyName = trim($_POST['company_name'] ?? '');

            if ($companyName === '') {
                $error = 'For recruiter registration, company name is required.';
            } else {
                $phone = $phone === '' ? null : $phone;

                $stmt = mysqli_prepare(
                    $con,
                    'INSERT INTO companies (username, passwd, name, address, email, phone) VALUES (?, ?, ?, ?, ?, ?)'
                );

                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ssssss', $username, $passwordHash, $companyName, $address, $email, $phone);

                    if (mysqli_stmt_execute($stmt)) {
                        $success = 'Recruiter account created successfully. You can now log in.';
                    } else {
                        $error = mysqli_errno($con) === 1062
                            ? 'Username or email already exists.'
                            : 'Registration failed. Please try again.';
                    }

                    mysqli_stmt_close($stmt);
                } else {
                    $error = 'A server error occurred. Please try again.';
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
    <title>CampusLink | Register</title>
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container auth-container">
        <div class="auth-shell register-shell">
            <section class="auth-visual register-visual">
                <div class="auth-overlay">
                    <h2>Create your CampusLink account</h2>
                    <p>Join as a student or recruiter and connect with opportunities in one place.</p>
                </div>
            </section>

            <section class="auth-panel wide-panel">
                <div class="brand compact-brand">
                    <h1>CampusLink</h1>
                    <p>Register your account</p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="auth-message error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="auth-message success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <form class="auth-form" method="post" action="register.php" id="registerForm" novalidate>
                    <label for="account_type">Register As</label>
                    <select name="account_type" id="account_type">
                        <option value="student" <?php echo $accountType === 'student' ? 'selected' : ''; ?>>Student</option>
                        <option value="company" <?php echo $accountType === 'company' ? 'selected' : ''; ?>>Recruiter (Company)</option>
                    </select>

                    <div class="auth-grid-two">
                        <div>
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>
                        <div>
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="auth-grid-two">
                        <div>
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <div>
                            <label for="confirm_password">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                    </div>

                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" required value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">

                    <label for="phone">Phone (optional)</label>
                    <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">

                    <div id="studentFields" class="conditional-group">
                        <div class="auth-grid-two">
                            <div>
                                <label for="f_name">First Name</label>
                                <input type="text" id="f_name" name="f_name" value="<?php echo htmlspecialchars($_POST['f_name'] ?? ''); ?>">
                            </div>
                            <div>
                                <label for="birth_day">Birth Date</label>
                                <input type="date" id="birth_day" name="birth_day" value="<?php echo htmlspecialchars($_POST['birth_day'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="auth-grid-two">
                            <div>
                                <label for="m_name">Middle Name (optional)</label>
                                <input type="text" id="m_name" name="m_name" value="<?php echo htmlspecialchars($_POST['m_name'] ?? ''); ?>">
                            </div>
                            <div>
                                <label for="l_name">Last Name (optional)</label>
                                <input type="text" id="l_name" name="l_name" value="<?php echo htmlspecialchars($_POST['l_name'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div id="companyFields" class="conditional-group">
                        <label for="company_name">Company Name</label>
                        <input type="text" id="company_name" name="company_name" value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>">
                    </div>

                    <button type="submit">Create Account</button>
                </form>

                <p class="auth-switch">Already have a student account? <a href="login.php">Sign in</a></p>
                <p class="auth-switch">Already have a recruiter account? <a href="compLogin.php">Sign in</a></p>
                <p class="auth-switch"><a href="index.php">Back to role selection</a></p>
            </section>
        </div>
    </div>

    <script>
        (function () {
            const accountType = document.getElementById('account_type');
            const studentFields = document.getElementById('studentFields');
            const companyFields = document.getElementById('companyFields');
            const fName = document.getElementById('f_name');
            const birthDay = document.getElementById('birth_day');
            const companyName = document.getElementById('company_name');

            function toggleFields() {
                const isStudent = accountType.value === 'student';

                studentFields.style.display = isStudent ? 'block' : 'none';
                companyFields.style.display = isStudent ? 'none' : 'block';

                fName.required = isStudent;
                birthDay.required = isStudent;
                companyName.required = !isStudent;
            }

            toggleFields();
            accountType.addEventListener('change', toggleFields);
        })();
    </script>
</body>
</html>
