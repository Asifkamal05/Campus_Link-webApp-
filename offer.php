<?php
session_start();
require_once 'connection.php';

if (($_SESSION['role'] ?? '') !== 'student' || !isset($_SESSION['student_id'])) {
    if (($_SESSION['role'] ?? '') === 'company' && isset($_SESSION['company_id'])) {
        header('Location: compHomepage.php');
    } else {
        header('Location: index.php');
    }
    exit();
}

$studentId = (int) $_SESSION['student_id'];
$username = htmlspecialchars($_SESSION['username'] ?? 'Student');
$message = '';
$messageType = '';

$title = '';
$description = '';
$priceInput = '';

// Protect against stale sessions when DB was reset.
$studentCheckStmt = mysqli_prepare($con, 'SELECT student_id FROM students WHERE student_id = ? LIMIT 1');
if ($studentCheckStmt) {
    mysqli_stmt_bind_param($studentCheckStmt, 'i', $studentId);
    mysqli_stmt_execute($studentCheckStmt);
    $studentCheckResult = mysqli_stmt_get_result($studentCheckStmt);
    $studentExists = $studentCheckResult ? mysqli_fetch_assoc($studentCheckResult) : null;
    mysqli_stmt_close($studentCheckStmt);

    if (!$studentExists) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['service_title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priceInput = trim($_POST['price'] ?? '');
    $errors = [];

    if ($title === '') {
        $errors[] = 'Service title is required.';
    } elseif (mb_strlen($title) > 255) {
        $errors[] = 'Service title must be 255 characters or fewer.';
    }

    if ($description !== '' && mb_strlen($description) > 3000) {
        $errors[] = 'Description is too long.';
    }

    if ($priceInput === '') {
        $errors[] = 'Price is required.';
    } elseif (!is_numeric($priceInput)) {
        $errors[] = 'Price must be a valid number.';
    }

    $price = is_numeric($priceInput) ? (float) $priceInput : -1;
    if ($price < 0) {
        $errors[] = 'Price cannot be negative.';
    }

    if (count($errors) === 0) {
        $insertSql = '
            INSERT INTO services (student_id, service_title, description, price)
            VALUES (?, ?, ?, ?)
        ';
        $stmt = mysqli_prepare($con, $insertSql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'issd', $studentId, $title, $description, $price);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($ok) {
                $messageType = 'success';
                $message = 'Service posted successfully.';
                $title = '';
                $description = '';
                $priceInput = '';
            } else {
                $messageType = 'error';
                $message = 'Could not post service. Please try again.';
            }
        } else {
            $messageType = 'error';
            $message = 'Database error while preparing your offer.';
        }
    } else {
        $messageType = 'error';
        $message = implode(' ', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink | Offer Service</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <style>
        .offer-wrap {
            display: grid;
            gap: 18px;
        }

        .offer-hero {
            border: 1px solid #bfdbfe;
            background: linear-gradient(135deg, #f0f9ff, #ecfeff 60%, #f0fdfa);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 14px 34px rgba(2, 132, 199, 0.12);
        }

        .offer-hero h2 {
            font-size: 1.45rem;
            margin-bottom: 4px;
        }

        .offer-hero p {
            color: #475569;
            font-size: 0.94rem;
        }

        .offer-panel {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--surface-solid);
            padding: 16px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }

        .offer-form {
            display: grid;
            gap: 12px;
        }

        .offer-form label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }

        .offer-form input,
        .offer-form textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            font: inherit;
            background: #fff;
        }

        .offer-form textarea {
            resize: vertical;
            min-height: 120px;
        }

        .offer-form input:focus,
        .offer-form textarea:focus {
            outline: none;
            border-color: #14b8a6;
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.18);
        }

        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-btn,
        .link-btn {
            border: 0;
            border-radius: 10px;
            background: linear-gradient(120deg, #0ea5e9, #0f766e);
            color: #fff;
            font: inherit;
            font-weight: 700;
            padding: 10px 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .link-btn {
            background: linear-gradient(120deg, #1e293b, #334155);
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="topbar">
            <div class="brand">
                <h1>CampusLink Service Offer</h1>
                <p>Publish your skills for other students.</p>
            </div>

            <div class="user-menu">
                <button class="user-trigger" type="button"><?php echo $username; ?></button>
                <div class="dropdown">
                    <a href="homepage.php">Back to Homepage</a>
                    <a href="services.php">Browse Services</a>
                    <form method="post" action="logout.php" class="logout-form">
                        <button type="submit" class="dropdown-action">Log Out</button>
                    </form>
                </div>
            </div>
        </header>

        <div class="offer-wrap">
            <section class="offer-hero">
                <h2>Offer a Service</h2>
                <p>Fill out the form below to let others hire you.</p>
            </section>

            <section class="offer-panel">
                <?php if ($message !== ''): ?>
                    <div class="auth-message <?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form class="offer-form" method="post" action="offer.php" novalidate>
                    <div>
                        <label for="service_title">Service Title *</label>
                        <input
                            type="text"
                            id="service_title"
                            name="service_title"
                            maxlength="255"
                            required
                            value="<?php echo htmlspecialchars($title); ?>"
                            placeholder="Example: Assignment tutoring for CSE courses"
                        >
                    </div>

                    <div>
                        <label for="description">Description</label>
                        <textarea
                            id="description"
                            name="description"
                            maxlength="3000"
                            placeholder="Describe scope, delivery time, and experience."
                        ><?php echo htmlspecialchars($description); ?></textarea>
                    </div>

                    <div>
                        <label for="price">Price (BDT) *</label>
                        <input
                            type="number"
                            id="price"
                            name="price"
                            min="0"
                            step="0.01"
                            required
                            value="<?php echo htmlspecialchars($priceInput); ?>"
                            placeholder="0.00"
                        >
                    </div>

                    <div class="action-row">
                        <button type="submit" class="action-btn">Publish Service</button>
                        <a class="link-btn" href="services.php">View Services</a>
                        <a class="link-btn" href="homepage.php">Cancel</a>
                    </div>
                </form>
            </section>
        </div>
    </div>
</body>
</html>
