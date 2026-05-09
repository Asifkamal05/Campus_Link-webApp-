<?php
session_start();
require_once 'connection.php';
require_once 'verification_helpers.php';

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

// If DB was reset and session kept old id, prevent FK failures on buy/review.
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

function buildRedirectUrl(string $search, int $productId = 0, string $sort = 'recent'): string
{
    $params = [];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($sort === 'rating') {
        $params['sort'] = 'rating';
    }
    if ($productId > 0) {
        $params['product'] = $productId;
    }

    return 'products.php' . (count($params) > 0 ? '?' . http_build_query($params) : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $searchRedirect = trim($_POST['q'] ?? '');
    $sortRedirect = ($_POST['sort'] ?? 'recent') === 'rating' ? 'rating' : 'recent';
    $postProductId = (int) ($_POST['product_id'] ?? 0);

    if ($action === 'buy_product') {
        $buyQty = (int) ($_POST['buy_qty'] ?? 0);

        if ($postProductId <= 0 || $buyQty <= 0) {
            $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Invalid product or quantity.'];
            header('Location: ' . buildRedirectUrl($searchRedirect, $postProductId, $sortRedirect));
            exit();
        }

        $ok = true;
        $startedTransaction = false;
        try {
            mysqli_begin_transaction($con);
            $startedTransaction = true;
        } catch (mysqli_sql_exception $e) {
            $ok = false;
        }

        try {
            $checkStmt = mysqli_prepare(
                $con,
                'SELECT owner_id, COALESCE(qty, 0) AS qty, price FROM products WHERE product_id = ? LIMIT 1 FOR UPDATE'
            );

            if (!$checkStmt) {
                $ok = false;
            }

            $product = null;
            if ($ok) {
                mysqli_stmt_bind_param($checkStmt, 'i', $postProductId);
                mysqli_stmt_execute($checkStmt);
                $checkResult = mysqli_stmt_get_result($checkStmt);
                $product = $checkResult ? mysqli_fetch_assoc($checkResult) : null;
                mysqli_stmt_close($checkStmt);

                if (!$product) {
                    $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Product not found.'];
                    $ok = false;
                } elseif ((int) $product['owner_id'] === $studentId) {
                    $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'You cannot buy your own product.'];
                    $ok = false;
                } elseif ((int) $product['qty'] <= 0) {
                    $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'This product is sold out.'];
                    $ok = false;
                } elseif ($buyQty > (int) $product['qty']) {
                    $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Requested quantity is higher than available stock.'];
                    $ok = false;
                }
            }

            if ($ok) {
                $buyStmt = mysqli_prepare(
                    $con,
                    'INSERT INTO buy_product (product_id, buyer_id) VALUES (?, ?)'
                );

                if ($buyStmt) {
                    for ($i = 0; $i < $buyQty; $i++) {
                        mysqli_stmt_bind_param($buyStmt, 'ii', $postProductId, $studentId);
                        if (!mysqli_stmt_execute($buyStmt)) {
                            $ok = false;
                            break;
                        }
                    }
                    mysqli_stmt_close($buyStmt);
                } else {
                    $ok = false;
                }
            }

            if ($ok) {
                $updateStmt = mysqli_prepare(
                    $con,
                    "UPDATE products
                     SET qty = qty - ?,
                         status = CASE WHEN qty - ? <= 0 THEN 'sold' ELSE 'available' END
                     WHERE product_id = ?"
                );

                if ($updateStmt) {
                    mysqli_stmt_bind_param($updateStmt, 'iii', $buyQty, $buyQty, $postProductId);
                    $ok = mysqli_stmt_execute($updateStmt) && (mysqli_stmt_affected_rows($updateStmt) > 0);
                    mysqli_stmt_close($updateStmt);
                } else {
                    $ok = false;
                }
            }
        } catch (mysqli_sql_exception $e) {
            if (str_contains($e->getMessage(), 'buy_product_ibfk_2')) {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Session is out of sync with database. Please log in again.'];
            } else {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'A database error occurred while buying this product.'];
            }
            $ok = false;
        }

        if ($ok && $startedTransaction) {
            mysqli_commit($con);
            $totalAmount = ((float) ($product['price'] ?? 0)) * $buyQty;
            $_SESSION['product_flash'] = [
                'type' => 'success',
                'message' => 'Purchase successful. Total amount: $' . number_format($totalAmount, 2),
            ];
        } else {
            if ($startedTransaction) {
                mysqli_rollback($con);
            }
            if (!isset($_SESSION['product_flash'])) {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Could not complete purchase.'];
            }
        }

        if (is_array($_SESSION['product_flash'] ?? null)
            && ($_SESSION['product_flash']['message'] ?? '') === 'Session is out of sync with database. Please log in again.') {
            session_unset();
            session_destroy();
            header('Location: login.php');
            exit();
        }

        header('Location: ' . buildRedirectUrl($searchRedirect, $postProductId, $sortRedirect));
        exit();
    }

    if ($action === 'submit_review') {
        $rating = (int) ($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');

        if ($postProductId <= 0) {
            $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Invalid product for review.'];
            header('Location: ' . buildRedirectUrl($searchRedirect, 0, $sortRedirect));
            exit();
        }

        if ($rating < 1 || $rating > 5) {
            $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Rating must be between 1 and 5.'];
            header('Location: ' . buildRedirectUrl($searchRedirect, $postProductId, $sortRedirect));
            exit();
        }

        if ($comment === '') {
            $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Please write a review comment.'];
            header('Location: ' . buildRedirectUrl($searchRedirect, $postProductId, $sortRedirect));
            exit();
        }

        try {
            $existsStmt = mysqli_prepare($con, 'SELECT product_id FROM products WHERE product_id = ? LIMIT 1');

            if (!$existsStmt) {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Could not verify product.'];
                header('Location: ' . buildRedirectUrl($searchRedirect, $postProductId, $sortRedirect));
                exit();
            }

            mysqli_stmt_bind_param($existsStmt, 'i', $postProductId);
            mysqli_stmt_execute($existsStmt);
            $existsResult = mysqli_stmt_get_result($existsStmt);
            $exists = $existsResult ? mysqli_fetch_assoc($existsResult) : null;
            mysqli_stmt_close($existsStmt);

            if (!$exists) {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Product not found for review.'];
                header('Location: ' . buildRedirectUrl($searchRedirect, 0, $sortRedirect));
                exit();
            }

            $buyerStmt = mysqli_prepare(
                $con,
                'SELECT buy_id FROM buy_product WHERE product_id = ? AND buyer_id = ? LIMIT 1'
            );

            if (!$buyerStmt) {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Could not verify review eligibility.'];
                header('Location: ' . buildRedirectUrl($searchRedirect, $postProductId, $sortRedirect));
                exit();
            }

            mysqli_stmt_bind_param($buyerStmt, 'ii', $postProductId, $studentId);
            mysqli_stmt_execute($buyerStmt);
            $buyerResult = mysqli_stmt_get_result($buyerStmt);
            $hasBought = $buyerResult ? mysqli_fetch_assoc($buyerResult) : null;
            mysqli_stmt_close($buyerStmt);

            if (!$hasBought) {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Only users who bought this product can review it.'];
                header('Location: ' . buildRedirectUrl($searchRedirect, $postProductId, $sortRedirect));
                exit();
            }

            $reviewedStmt = mysqli_prepare(
                $con,
                'SELECT review_id FROM reviews WHERE product_id = ? AND reviewer_id = ? LIMIT 1'
            );

            if (!$reviewedStmt) {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Could not verify previous reviews.'];
                header('Location: ' . buildRedirectUrl($searchRedirect, $postProductId, $sortRedirect));
                exit();
            }

            mysqli_stmt_bind_param($reviewedStmt, 'ii', $postProductId, $studentId);
            mysqli_stmt_execute($reviewedStmt);
            $reviewedResult = mysqli_stmt_get_result($reviewedStmt);
            $alreadyReviewed = $reviewedResult ? mysqli_fetch_assoc($reviewedResult) : null;
            mysqli_stmt_close($reviewedStmt);

            if ($alreadyReviewed) {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'You can review this product only once.'];
                header('Location: ' . buildRedirectUrl($searchRedirect, $postProductId, $sortRedirect));
                exit();
            }

            $reviewStmt = mysqli_prepare(
                $con,
                'INSERT INTO reviews (reviewer_id, product_id, rating, comment) VALUES (?, ?, ?, ?)'
            );

            if ($reviewStmt) {
                mysqli_stmt_bind_param($reviewStmt, 'iiis', $studentId, $postProductId, $rating, $comment);
                $inserted = mysqli_stmt_execute($reviewStmt);
                mysqli_stmt_close($reviewStmt);

                if ($inserted) {
                    $_SESSION['product_flash'] = ['type' => 'success', 'message' => 'Your review was submitted.'];
                } else {
                    $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Could not submit review.'];
                }
            } else {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Could not submit review.'];
            }
        } catch (mysqli_sql_exception $e) {
            if (str_contains($e->getMessage(), 'buy_product_ibfk_2')) {
                $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'Session is out of sync with database. Please log in again.'];
                session_unset();
                session_destroy();
                header('Location: login.php');
                exit();
            }

            $_SESSION['product_flash'] = ['type' => 'error', 'message' => 'A database error occurred while submitting review.'];
        }

        header('Location: ' . buildRedirectUrl($searchRedirect, $postProductId, $sortRedirect));
        exit();
    }
}

$flash = $_SESSION['product_flash'] ?? null;
unset($_SESSION['product_flash']);

$searchQuery = trim($_GET['q'] ?? '');
$sort = ($_GET['sort'] ?? 'recent') === 'rating' ? 'rating' : 'recent';
$selectedProductId = (int) ($_GET['product'] ?? 0);
$products = [];
$reviewsByProduct = [];
$selectedProduct = null;
$orderByClause = $sort === 'rating'
    ? 'avg_rating DESC, review_count DESC, p.created_at DESC'
    : 'p.created_at DESC';

$productSql = "
    SELECT
        p.product_id,
        p.owner_id,
        p.product_title,
        p.description,
        p.price,
        COALESCE(p.qty, 0) AS qty,
        p.status,
        p.created_at,
        st.username AS owner_username,
        st.is_verified AS owner_verified,
        COALESCE(AVG(r.rating), 0) AS avg_rating,
        COUNT(r.review_id) AS review_count,
        EXISTS(
            SELECT 1
            FROM buy_product bp
            WHERE bp.product_id = p.product_id AND bp.buyer_id = ?
        ) AS has_bought,
        EXISTS(
            SELECT 1
            FROM reviews rr
            WHERE rr.product_id = p.product_id AND rr.reviewer_id = ?
        ) AS has_reviewed
    FROM products p
    INNER JOIN students st ON p.owner_id = st.student_id
    LEFT JOIN reviews r ON r.product_id = p.product_id
    WHERE (? = '' OR p.product_title LIKE ? OR COALESCE(p.description, '') LIKE ?)
    GROUP BY p.product_id, p.owner_id, p.product_title, p.description, p.price, p.qty, p.status, p.created_at, st.username, st.is_verified
    ORDER BY $orderByClause
";

$productStmt = mysqli_prepare($con, $productSql);

if ($productStmt) {
    $searchLike = '%' . $searchQuery . '%';
    mysqli_stmt_bind_param($productStmt, 'iisss', $studentId, $studentId, $searchQuery, $searchLike, $searchLike);

    mysqli_stmt_execute($productStmt);
    $productResult = mysqli_stmt_get_result($productStmt);

    if ($productResult) {
        while ($row = mysqli_fetch_assoc($productResult)) {
            $products[] = $row;
        }
    }

    mysqli_stmt_close($productStmt);
}

if (count($products) > 0) {
    $productIds = array_map(static function ($productRow) {
        return (int) $productRow['product_id'];
    }, $products);

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $reviewSql = "
        SELECT
            r.product_id,
            r.reviewer_id,
            r.rating,
            r.comment,
            r.created_at,
            st.username AS reviewer_username,
            st.is_verified AS reviewer_verified
        FROM reviews r
        INNER JOIN students st ON r.reviewer_id = st.student_id
        WHERE r.product_id IN ($placeholders)
        ORDER BY r.created_at DESC
    ";

    $reviewStmt = mysqli_prepare($con, $reviewSql);

    if ($reviewStmt) {
        $types = str_repeat('i', count($productIds));
        mysqli_stmt_bind_param($reviewStmt, $types, ...$productIds);
        mysqli_stmt_execute($reviewStmt);
        $reviewResult = mysqli_stmt_get_result($reviewStmt);

        if ($reviewResult) {
            while ($review = mysqli_fetch_assoc($reviewResult)) {
                $key = (int) $review['product_id'];
                if (!isset($reviewsByProduct[$key])) {
                    $reviewsByProduct[$key] = [];
                }
                $reviewsByProduct[$key][] = $review;
            }
        }

        mysqli_stmt_close($reviewStmt);
    }
}

foreach ($products as $productRow) {
    if ((int) $productRow['product_id'] === $selectedProductId) {
        $selectedProduct = $productRow;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink | Products</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <style>
        .products-wrap {
            display: grid;
            gap: 18px;
        }

        .products-hero {
            border: 1px solid #bfdbfe;
            background: linear-gradient(135deg, #f0f9ff, #ecfeff 60%, #f0fdfa);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 14px 34px rgba(2, 132, 199, 0.12);
        }

        .products-hero h2 {
            font-size: 1.45rem;
            margin-bottom: 4px;
        }

        .products-hero p {
            color: #475569;
            font-size: 0.94rem;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--line);
            background: var(--surface-solid);
            border-radius: 14px;
            padding: 12px;
        }

        .search-form {
            display: flex;
            gap: 10px;
            flex: 1 1 420px;
        }

        .search-form input {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            font: inherit;
            background: #fff;
        }

        .action-btn,
        .link-button {
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
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .action-btn:hover,
        .link-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(14, 116, 144, 0.24);
        }

        .link-button {
            background: linear-gradient(120deg, #1e293b, #334155);
        }

        .link-button.is-active {
            background: linear-gradient(120deg, #0ea5e9, #0f766e);
        }

        .sort-menu {
            position: relative;
        }

        .sort-trigger {
            list-style: none;
            user-select: none;
        }

        .sort-trigger::-webkit-details-marker {
            display: none;
        }

        .sort-options {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            min-width: 180px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 14px 24px rgba(15, 23, 42, 0.16);
            z-index: 30;
            overflow: hidden;
        }

        .sort-option {
            display: block;
            padding: 9px 12px;
            text-decoration: none;
            color: #334155;
            font-size: 0.9rem;
            border-bottom: 1px solid #e2e8f0;
            background: #fff;
        }

        .sort-option:last-child {
            border-bottom: 0;
        }

        .sort-option:hover {
            background: #f1f5f9;
        }

        .sort-option.active {
            background: #ecfeff;
            color: #0f766e;
            font-weight: 700;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .product-link {
            text-decoration: none;
            color: inherit;
        }

        .product-card {
            border: 1px solid #dbeafe;
            border-radius: 14px;
            padding: 14px;
            background: linear-gradient(180deg, #ffffff, #f8fafc);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
            display: grid;
            gap: 7px;
        }

        .product-link:hover .product-card {
            transform: translateY(-4px);
            box-shadow: 0 16px 28px rgba(14, 116, 144, 0.18);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: flex-start;
        }

        .product-title {
            font-size: 1rem;
            font-weight: 700;
        }

        .status-pill {
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 4px 8px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-available {
            background: #dcfce7;
            color: #166534;
        }

        .status-soldout {
            background: #fee2e2;
            color: #991b1b;
        }

        .meta {
            color: #64748b;
            font-size: 0.86rem;
        }

        .profile-link-inline {
            color: inherit;
            text-decoration: none;
            font-weight: 700;
        }

        .profile-link-inline:hover {
            color: #0f766e;
            text-decoration: underline;
        }

        .price {
            color: #0f766e;
            font-weight: 700;
        }

        .qty-text {
            font-size: 0.86rem;
            color: #334155;
            font-weight: 600;
        }

        .rating-text {
            color: #64748b;
            font-size: 0.84rem;
        }

        .modal {
            position: fixed;
            inset: 0;
            z-index: 120;
            background: rgba(2, 6, 23, 0.62);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal.open {
            display: flex;
        }

        .modal-card {
            width: min(820px, 100%);
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 16px;
            border: 1px solid #dbeafe;
            background: #fff;
            box-shadow: 0 26px 44px rgba(15, 23, 42, 0.28);
            padding: 16px;
            display: grid;
            gap: 14px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .close-link {
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            padding: 6px 10px;
            text-decoration: none;
            color: #334155;
            font-weight: 700;
            background: #fff;
        }

        .buy-panel {
            border: 1px solid #dbeafe;
            border-radius: 12px;
            padding: 12px;
            background: #f8fbff;
            display: grid;
            gap: 10px;
        }

        .buy-form-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .buy-form-row input[type="number"] {
            width: 140px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 9px 11px;
            font: inherit;
            background: #fff;
        }

        .total-amount {
            color: #0f172a;
            font-weight: 700;
            font-size: 0.92rem;
        }

        .action-btn[disabled] {
            background: #e2e8f0;
            color: #64748b;
            box-shadow: none;
            cursor: not-allowed;
            transform: none;
        }

        .reviews-list {
            display: grid;
            gap: 10px;
        }

        .review-item {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px;
            background: #f8fafc;
        }

        .review-head {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 5px;
            font-size: 0.84rem;
            color: #64748b;
        }

        .review-form {
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
            display: grid;
            gap: 8px;
        }

        .review-form select,
        .review-form textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            font: inherit;
            background: #fff;
        }

        .review-lock {
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
            color: #b45309;
            font-size: 0.92rem;
            font-weight: 600;
        }

        .empty {
            color: #64748b;
            font-size: 0.93rem;
        }

        @media (max-width: 980px) {
            .products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .products-grid {
                grid-template-columns: 1fr;
            }

            .modal-card {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="topbar">
            <div class="brand">
                <h1>CampusLink Products</h1>
                <p>Life became so easy :) </p>
            </div>
            <div class="user-menu">
                <button class="user-trigger" type="button"><?php echo $username; ?></button>
                <div class="dropdown">
                    <a href="homepage.php">Back to Homepage</a>
                    <form method="post" action="logout.php" class="logout-form">
                        <button type="submit" class="dropdown-action">Log Out</button>
                    </form>
                </div>
            </div>
        </header>

        <div class="products-wrap">
            <section class="products-hero">
                <h2>Listed Products</h2>
                <p>Buy Products with ease</p>
            </section>

            <?php if (is_array($flash) && isset($flash['message'])): ?>
                <div class="auth-message <?php echo ($flash['type'] ?? '') === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <section class="toolbar">
                <form class="search-form" method="get" action="products.php">
                    <input type="search" name="q" placeholder="Search products by title or description..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                    <button type="submit" class="action-btn">Search</button>
                </form>
                <details class="sort-menu">
                    <summary class="link-button sort-trigger">
                        Sort: <?php echo $sort === 'rating' ? 'Top Rated' : 'Newest'; ?>
                    </summary>
                    <div class="sort-options">
                        <a
                            class="sort-option <?php echo $sort === 'recent' ? 'active' : ''; ?>"
                            href="<?php echo htmlspecialchars(buildRedirectUrl($searchQuery, 0, 'recent')); ?>"
                        >
                            Newest
                        </a>
                        <a
                            class="sort-option <?php echo $sort === 'rating' ? 'active' : ''; ?>"
                            href="<?php echo htmlspecialchars(buildRedirectUrl($searchQuery, 0, 'rating')); ?>"
                        >
                            Top Rated
                        </a>
                    </div>
                </details>
                <a class="link-button" href="products.php">Reset</a>
            </section>

            <section class="section">
                <?php if ($searchQuery !== ''): ?>
                    <p class="meta">Search results for "<?php echo htmlspecialchars($searchQuery); ?>"</p>
                <?php endif; ?>

                <?php if (count($products) > 0): ?>
                    <div class="products-grid">
                        <?php foreach ($products as $product): ?>
                            <?php
                                $pid = (int) $product['product_id'];
                                $qty = max(0, (int) ($product['qty'] ?? 0));
                                $displayStatus = $qty > 0 ? 'available' : 'sold out';
                                $statusClass = $qty > 0 ? 'status-available' : 'status-soldout';
                                $queryParams = [];
                                if ($searchQuery !== '') {
                                    $queryParams['q'] = $searchQuery;
                                }
                                if ($sort === 'rating') {
                                    $queryParams['sort'] = 'rating';
                                }
                                $queryParams['product'] = $pid;
                                $cardHref = 'products.php?' . http_build_query($queryParams);
                            ?>
                            <a class="product-link" href="<?php echo htmlspecialchars($cardHref); ?>">
                                <article class="product-card">
                                    <div class="card-top">
                                        <h4 class="product-title"><?php echo htmlspecialchars($product['product_title']); ?></h4>
                                        <span class="status-pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars($displayStatus); ?></span>
                                    </div>
                                    <p class="meta">
                                        By
                                        <a class="profile-link-inline" href="profile.php?type=student&amp;id=<?php echo (int) $product['owner_id']; ?>">
                                            <?php echo renderVerifiedName((string) $product['owner_username'], isVerifiedUser($product['owner_verified'] ?? 0)); ?>
                                        </a>
                                    </p>
                                    <p><?php echo htmlspecialchars($product['description'] ?? 'No description provided.'); ?></p>
                                    <p class="qty-text">Stock left: <?php echo $qty; ?></p>
                                    <p class="price">$<?php echo number_format((float) $product['price'], 2); ?></p>
                                    <p class="rating-text">
                                        Rating: <?php echo number_format((float) $product['avg_rating'], 1); ?>/5
                                        (<?php echo (int) $product['review_count']; ?> review<?php echo ((int) $product['review_count']) === 1 ? '' : 's'; ?>)
                                    </p>
                                </article>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty">No products found for your search.</p>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <?php if ($selectedProduct): ?>
        <?php
            $pid = (int) $selectedProduct['product_id'];
            $qty = max(0, (int) ($selectedProduct['qty'] ?? 0));
            $displayStatus = $qty > 0 ? 'available' : 'sold out';
            $statusClass = $qty > 0 ? 'status-available' : 'status-soldout';
            $productReviews = $reviewsByProduct[$pid] ?? [];
            $isOwner = ((int) $selectedProduct['owner_id']) === $studentId;
            $canBuy = !$isOwner && $qty > 0;
            $hasBought = ((int) ($selectedProduct['has_bought'] ?? 0)) === 1;
            $hasReviewed = ((int) ($selectedProduct['has_reviewed'] ?? 0)) === 1;
            $canReview = $hasBought && !$hasReviewed;
            $closeParams = [];
            if ($searchQuery !== '') {
                $closeParams['q'] = $searchQuery;
            }
            if ($sort === 'rating') {
                $closeParams['sort'] = 'rating';
            }
            $closeHref = 'products.php' . (count($closeParams) > 0 ? '?' . http_build_query($closeParams) : '');
            $defaultQty = $qty > 0 ? 1 : 0;
            $defaultTotal = ((float) $selectedProduct['price']) * $defaultQty;
        ?>
        <div class="modal open" role="dialog" aria-modal="true" aria-label="Product details">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h3><?php echo htmlspecialchars($selectedProduct['product_title']); ?></h3>
                        <p class="meta">
                            By
                            <a class="profile-link-inline" href="profile.php?type=student&amp;id=<?php echo (int) $selectedProduct['owner_id']; ?>">
                                <?php echo renderVerifiedName((string) $selectedProduct['owner_username'], isVerifiedUser($selectedProduct['owner_verified'] ?? 0)); ?>
                            </a>
                            |
                            <span class="status-pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars($displayStatus); ?></span>
                        </p>
                    </div>
                    <a href="<?php echo htmlspecialchars($closeHref); ?>" class="close-link">Close</a>
                </div>

                <p><?php echo htmlspecialchars($selectedProduct['description'] ?? 'No description provided.'); ?></p>
                <p class="qty-text">Stock left: <?php echo $qty; ?></p>
                <p class="price">Unit Price: $<?php echo number_format((float) $selectedProduct['price'], 2); ?></p>

                <div class="buy-panel">
                    <form method="post" action="products.php" id="buyForm" class="buy-form-row">
                        <input type="hidden" name="action" value="buy_product">
                        <input type="hidden" name="product_id" value="<?php echo $pid; ?>">
                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">

                        <label for="buy_qty">Quantity</label>
                        <input
                            type="number"
                            id="buy_qty"
                            name="buy_qty"
                            min="1"
                            max="<?php echo $qty; ?>"
                            value="<?php echo $defaultQty; ?>"
                            <?php echo $canBuy ? '' : 'disabled'; ?>
                        >

                        <button type="submit" class="action-btn" <?php echo $canBuy ? '' : 'disabled'; ?>>
                            <?php
                                if ($isOwner) {
                                    echo 'You own this item';
                                } elseif ($qty <= 0) {
                                    echo 'Sold Out';
                                } else {
                                    echo 'Buy Now';
                                }
                            ?>
                        </button>
                    </form>

                    <p class="total-amount">
                        Total Amount: $<span id="totalAmount"><?php echo number_format($defaultTotal, 2); ?></span>
                    </p>
                </div>

                <section>
                    <h4>Previous Reviews</h4>
                    <?php if (count($productReviews) > 0): ?>
                        <div class="reviews-list">
                            <?php foreach ($productReviews as $review): ?>
                                <article class="review-item">
                                    <div class="review-head">
                                        <span>
                                            <a class="profile-link-inline" href="profile.php?type=student&amp;id=<?php echo (int) $review['reviewer_id']; ?>">
                                                <?php echo renderVerifiedName((string) $review['reviewer_username'], isVerifiedUser($review['reviewer_verified'] ?? 0)); ?>
                                            </a>
                                            | <?php echo (int) $review['rating']; ?>/5
                                        </span>
                                        <span><?php echo htmlspecialchars(date('M j, Y', strtotime((string) $review['created_at']))); ?></span>
                                    </div>
                                    <p><?php echo nl2br(htmlspecialchars($review['comment'] ?? '')); ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty">No reviews yet for this product.</p>
                    <?php endif; ?>
                </section>

                <?php if ($canReview): ?>
                    <form method="post" action="products.php" class="review-form">
                        <h4>Write a Review</h4>
                        <input type="hidden" name="action" value="submit_review">
                        <input type="hidden" name="product_id" value="<?php echo $pid; ?>">
                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">

                        <label for="rating">Rating</label>
                        <select id="rating" name="rating" required>
                            <option value="">Select rating</option>
                            <option value="5">5 - Excellent</option>
                            <option value="4">4 - Very Good</option>
                            <option value="3">3 - Good</option>
                            <option value="2">2 - Fair</option>
                            <option value="1">1 - Poor</option>
                        </select>

                        <label for="comment">Your review</label>
                        <textarea id="comment" name="comment" rows="4" maxlength="1000" placeholder="Share your experience..." required></textarea>

                        <button type="submit" class="action-btn">Submit Review</button>
                    </form>
                <?php else: ?>
                    <p class="review-lock">
                        <?php
                            if (!$hasBought) {
                                echo 'Only users who purchased this product can submit a review.';
                            } else {
                                echo 'You already submitted your one allowed review for this product.';
                            }
                        ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <script>
            (function () {
                const qtyInput = document.getElementById('buy_qty');
                const totalNode = document.getElementById('totalAmount');
                if (!qtyInput || !totalNode) {
                    return;
                }

                const unitPrice = <?php echo json_encode((float) $selectedProduct['price']); ?>;
                const maxQty = <?php echo json_encode($qty); ?>;

                function updateTotal() {
                    let value = parseInt(qtyInput.value, 10);
                    if (Number.isNaN(value) || value < 1) {
                        value = 1;
                    }
                    if (value > maxQty) {
                        value = maxQty;
                    }
                    qtyInput.value = value;
                    totalNode.textContent = (unitPrice * value).toFixed(2);
                }

                qtyInput.addEventListener('input', updateTotal);
                updateTotal();
            })();
        </script>
    <?php endif; ?>
</body>
</html>
