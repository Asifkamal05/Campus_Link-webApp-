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

$error = '';

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
        $stmt = mysqli_prepare($con, 'SELECT company_id, username, passwd, name FROM companies WHERE username = ? LIMIT 1');

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $company = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);

            if ($company && verifyPassword($password, $company['passwd'])) {
                $_SESSION['role'] = 'company';
                $_SESSION['company_id'] = (int) $company['company_id'];
                $_SESSION['username'] = $company['name'];
                $_SESSION['auth_username'] = $company['username'];

                $storedInfo = password_get_info($company['passwd']);
                if (($storedInfo['algo'] ?? 0) === 0) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $update = mysqli_prepare($con, 'UPDATE companies SET passwd = ? WHERE company_id = ?');
                    if ($update) {
                        mysqli_stmt_bind_param($update, 'si', $newHash, $company['company_id']);
                        mysqli_stmt_execute($update);
                        mysqli_stmt_close($update);
                    }
                }

                header('Location: compHomepage.php');
                exit();
            }

            $error = 'Invalid recruiter credentials.';
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
    <title>CampusLink | Recruiter Login</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
</head>
<body>
    <div class="container auth-container">
        <div class="auth-shell">
            <section class="auth-visual recruiter-visual">
                <div class="auth-overlay">
                    <h2>Recruiter Portal</h2>
                    <p>Find top student talent and manage opportunities from one dashboard.</p>
                </div>
            </section>

            <section class="auth-panel">
                <div class="brand compact-brand">
                    <h1>CampusLink</h1>
                    <p>Sign in as recruiter</p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="auth-message error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form class="auth-form" method="post" action="compLogin.php" novalidate>
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">

                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>

                    <button type="submit">Sign In</button>
                </form>

                <p class="auth-switch">Student account? <a href="login.php">Sign in here</a></p>
                <p class="auth-switch">No account? <a href="register.php">Register now</a></p>
                <p class="auth-switch"><a href="index.php">Back to role selection</a></p>
            </section>
        </div>
    </div>
</body>
</html>
