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
$qtyInput = '';
$statusInput = 'available';

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
    $title = trim($_POST['product_title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priceInput = trim($_POST['price'] ?? '');
    $qtyInput = trim($_POST['qty'] ?? '');
    $statusInput = $_POST['status'] ?? 'available';

    if (!in_array($statusInput, ['available', 'reserved', 'sold'], true)) {
        $statusInput = 'available';
    }

    $errors = [];

    if ($title === '') {
        $errors[] = 'Product title is required.';
    } elseif (mb_strlen($title) > 255) {
        $errors[] = 'Product title must be 255 characters or fewer.';
    }

    if ($description !== '' && mb_strlen($description) > 3000) {
        $errors[] = 'Description is too long.';
    }

    if ($priceInput === '') {
        $errors[] = 'Price is required.';
    } elseif (!is_numeric($priceInput)) {
        $errors[] = 'Price must be a valid number.';
    }

    if ($qtyInput === '') {
        $errors[] = 'Quantity is required.';
    } elseif (filter_var($qtyInput, FILTER_VALIDATE_INT) === false) {
        $errors[] = 'Quantity must be a whole number.';
    }

    $price = is_numeric($priceInput) ? (float) $priceInput : -1;
    $qty = (filter_var($qtyInput, FILTER_VALIDATE_INT) !== false) ? (int) $qtyInput : -1;

    if ($price < 0) {
        $errors[] = 'Price cannot be negative.';
    }

    if ($qty < 0 || $qty > 999999) {
        $errors[] = 'Quantity must be between 0 and 999999.';
    }

    if (count($errors) === 0) {
        $insertSql = '
            INSERT INTO products (owner_id, product_title, description, price, qty, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ';
        $stmt = mysqli_prepare($con, $insertSql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'issdis', $studentId, $title, $description, $price, $qty, $statusInput);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($ok) {
                $messageType = 'success';
                $message = 'Product listed successfully.';
                $title = '';
                $description = '';
                $priceInput = '';
                $qtyInput = '';
                $statusInput = 'available';
            } else {
                $messageType = 'error';
                $message = 'Could not list product. Please try again.';
            }
        } else {
            $messageType = 'error';
            $message = 'Database error while preparing your listing.';
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
    <title>CampusLink | List Product</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <style>
        .list-wrap {
            display: grid;
            gap: 18px;
        }

        .list-hero {
            border: 1px solid #bfdbfe;
            background: linear-gradient(135deg, #f0f9ff, #ecfeff 60%, #f0fdfa);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 14px 34px rgba(2, 132, 199, 0.12);
        }

        .list-hero h2 {
            font-size: 1.45rem;
            margin-bottom: 4px;
        }

        .list-hero p {
            color: #475569;
            font-size: 0.94rem;
        }

        .list-panel {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--surface-solid);
            padding: 16px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }

        .list-form {
            display: grid;
            gap: 12px;
        }

        .list-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .list-form label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }

        .list-form input,
        .list-form textarea,
        .list-form select {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            font: inherit;
            background: #fff;
        }

        .list-form textarea {
            resize: vertical;
            min-height: 120px;
        }

        .list-form input:focus,
        .list-form textarea:focus,
        .list-form select:focus {
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

        .helper {
            color: #64748b;
            font-size: 0.9rem;
        }

        @media (max-width: 760px) {
            .list-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="topbar">
            <div class="brand">
                <h1>CampusLink Product Listing</h1>
                <p>Post your product for other students to discover.</p>
            </div>

            <div class="user-menu">
                <button class="user-trigger" type="button"><?php echo $username; ?></button>
                <div class="dropdown">
                    <a href="homepage.php">Back to Homepage</a>
                    <a href="products.php">Browse Products</a>
                    <form method="post" action="logout.php" class="logout-form">
                        <button type="submit" class="dropdown-action">Log Out</button>
                    </form>
                </div>
            </div>
        </header>

        <div class="list-wrap">
            <section class="list-hero">
                <h2>List a Product</h2>
                <p>Fill out the form below to publish your item on CampusLink.</p>
            </section>

            <section class="list-panel">
                <?php if ($message !== ''): ?>
                    <div class="auth-message <?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form class="list-form" method="post" action="list.php" novalidate>
                    <div>
                        <label for="product_title">Product Title *</label>
                        <input
                            type="text"
                            id="product_title"
                            name="product_title"
                            maxlength="255"
                            required
                            value="<?php echo htmlspecialchars($title); ?>"
                            placeholder="Example: Scientific Calculator FX-991ES Plus"
                        >
                    </div>

                    <div>
                        <label for="description">Description</label>
                        <textarea
                            id="description"
                            name="description"
                            maxlength="3000"
                            placeholder="Describe condition, usage duration, and any important details."
                        ><?php echo htmlspecialchars($description); ?></textarea>
                    </div>

                    <div class="list-grid">
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

                        <div>
                            <label for="qty">Quantity *</label>
                            <input
                                type="number"
                                id="qty"
                                name="qty"
                                min="0"
                                max="999999"
                                step="1"
                                required
                                value="<?php echo htmlspecialchars($qtyInput); ?>"
                                placeholder="1"
                            >
                        </div>
                    </div>

                    <div>
                        <label for="status">Initial Status</label>
                        <select id="status" name="status">
                            <option value="available" <?php echo $statusInput === 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="reserved" <?php echo $statusInput === 'reserved' ? 'selected' : ''; ?>>Reserved</option>
                            <option value="sold" <?php echo $statusInput === 'sold' ? 'selected' : ''; ?>>Sold</option>
                        </select>
                        <p class="helper">If quantity is 0, choose "Sold" to avoid confusion for buyers.</p>
                    </div>

                    <div class="action-row">
                        <button type="submit" class="action-btn">Publish Product</button>
                        <a class="link-btn" href="products.php">View Products</a>
                        <a class="link-btn" href="homepage.php">Cancel</a>
                    </div>
                </form>
            </section>
        </div>
    </div>
</body>
</html>
