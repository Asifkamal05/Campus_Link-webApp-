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

function buildRedirectUrl(string $search, string $sort): string
{
    $params = [];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($sort === 'rating') {
        $params['sort'] = 'rating';
    }
    return 'services.php' . (count($params) > 0 ? '?' . http_build_query($params) : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $searchRedirect = trim($_POST['q'] ?? '');
    $sortRedirect = ($_POST['sort'] ?? 'recent') === 'rating' ? 'rating' : 'recent';

    if ($action === 'request_service') {
        if ($serviceId <= 0) {
            $_SESSION['service_flash'] = ['type' => 'error', 'message' => 'Invalid service.'];
            header('Location: ' . buildRedirectUrl($searchRedirect, $sortRedirect));
            exit();
        }

        $ok = true;
        try {
            $serviceStmt = mysqli_prepare($con, 'SELECT student_id FROM services WHERE service_id = ? LIMIT 1');
            if ($serviceStmt) {
                mysqli_stmt_bind_param($serviceStmt, 'i', $serviceId);
                mysqli_stmt_execute($serviceStmt);
                $serviceResult = mysqli_stmt_get_result($serviceStmt);
                $service = $serviceResult ? mysqli_fetch_assoc($serviceResult) : null;
                mysqli_stmt_close($serviceStmt);
                if (!$service) {
                    $_SESSION['service_flash'] = ['type' => 'error', 'message' => 'Service not found.'];
                    $ok = false;
                } elseif ((int) $service['student_id'] === $studentId) {
                    $_SESSION['service_flash'] = ['type' => 'error', 'message' => 'You cannot request your own service.'];
                    $ok = false;
                }
            } else {
                $ok = false;
            }

            if ($ok) {
                $activeStmt = mysqli_prepare(
                    $con,
                    "SELECT request_id FROM req_service WHERE service_id = ? AND requester_id = ? AND status IN ('pending', 'accepted') LIMIT 1"
                );
                if ($activeStmt) {
                    mysqli_stmt_bind_param($activeStmt, 'ii', $serviceId, $studentId);
                    mysqli_stmt_execute($activeStmt);
                    $activeResult = mysqli_stmt_get_result($activeStmt);
                    $active = $activeResult ? mysqli_fetch_assoc($activeResult) : null;
                    mysqli_stmt_close($activeStmt);
                    if ($active) {
                        $_SESSION['service_flash'] = ['type' => 'error', 'message' => 'You already have an active request for this service.'];
                        $ok = false;
                    }
                } else {
                    $ok = false;
                }
            }

            if ($ok) {
                $insertStmt = mysqli_prepare($con, 'INSERT INTO req_service (service_id, requester_id) VALUES (?, ?)');
                if ($insertStmt) {
                    mysqli_stmt_bind_param($insertStmt, 'ii', $serviceId, $studentId);
                    $ok = mysqli_stmt_execute($insertStmt);
                    mysqli_stmt_close($insertStmt);
                } else {
                    $ok = false;
                }
            }
        } catch (mysqli_sql_exception $e) {
            $ok = false;
        }

        $_SESSION['service_flash'] = $_SESSION['service_flash'] ?? [
            'type' => ($ok ? 'success' : 'error'),
            'message' => ($ok ? 'Service request submitted.' : 'Could not submit service request.'),
        ];

        header('Location: ' . buildRedirectUrl($searchRedirect, $sortRedirect));
        exit();
    }

    if ($action === 'submit_review') {
        $rating = (int) ($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');

        if ($serviceId <= 0 || $rating < 1 || $rating > 5 || $comment === '') {
            $_SESSION['service_flash'] = ['type' => 'error', 'message' => 'Invalid review submission.'];
            header('Location: ' . buildRedirectUrl($searchRedirect, $sortRedirect));
            exit();
        }

        $canReview = false;
        try {
            $serviceStmt = mysqli_prepare($con, 'SELECT student_id FROM services WHERE service_id = ? LIMIT 1');
            if ($serviceStmt) {
                mysqli_stmt_bind_param($serviceStmt, 'i', $serviceId);
                mysqli_stmt_execute($serviceStmt);
                $serviceResult = mysqli_stmt_get_result($serviceStmt);
                $service = $serviceResult ? mysqli_fetch_assoc($serviceResult) : null;
                mysqli_stmt_close($serviceStmt);

                if ($service && (int) $service['student_id'] !== $studentId) {
                    $eligibleStmt = mysqli_prepare(
                        $con,
                        "SELECT request_id FROM req_service WHERE service_id = ? AND requester_id = ? AND status IN ('accepted', 'completed') LIMIT 1"
                    );
                    if ($eligibleStmt) {
                        mysqli_stmt_bind_param($eligibleStmt, 'ii', $serviceId, $studentId);
                        mysqli_stmt_execute($eligibleStmt);
                        $eligibleResult = mysqli_stmt_get_result($eligibleStmt);
                        $eligible = $eligibleResult ? mysqli_fetch_assoc($eligibleResult) : null;
                        mysqli_stmt_close($eligibleStmt);
                        $canReview = (bool) $eligible;
                    }
                }
            }

            if ($canReview) {
                $alreadyStmt = mysqli_prepare($con, 'SELECT review_id FROM reviews WHERE service_id = ? AND reviewer_id = ? LIMIT 1');
                if ($alreadyStmt) {
                    mysqli_stmt_bind_param($alreadyStmt, 'ii', $serviceId, $studentId);
                    mysqli_stmt_execute($alreadyStmt);
                    $alreadyResult = mysqli_stmt_get_result($alreadyStmt);
                    $already = $alreadyResult ? mysqli_fetch_assoc($alreadyResult) : null;
                    mysqli_stmt_close($alreadyStmt);
                    if ($already) {
                        $_SESSION['service_flash'] = ['type' => 'error', 'message' => 'You can review this service only once.'];
                        header('Location: ' . buildRedirectUrl($searchRedirect, $sortRedirect));
                        exit();
                    }
                } else {
                    $canReview = false;
                }
            }

            if ($canReview) {
                $reviewStmt = mysqli_prepare(
                    $con,
                    'INSERT INTO reviews (reviewer_id, service_id, rating, comment) VALUES (?, ?, ?, ?)'
                );
                if ($reviewStmt) {
                    mysqli_stmt_bind_param($reviewStmt, 'iiis', $studentId, $serviceId, $rating, $comment);
                    $inserted = mysqli_stmt_execute($reviewStmt);
                    mysqli_stmt_close($reviewStmt);
                    $_SESSION['service_flash'] = [
                        'type' => ($inserted ? 'success' : 'error'),
                        'message' => ($inserted ? 'Review submitted.' : 'Could not submit review.'),
                    ];
                } else {
                    $_SESSION['service_flash'] = ['type' => 'error', 'message' => 'Could not submit review.'];
                }
            } else {
                $_SESSION['service_flash'] = ['type' => 'error', 'message' => 'Review allowed only after accepted/completed request.'];
            }
        } catch (mysqli_sql_exception $e) {
            $_SESSION['service_flash'] = ['type' => 'error', 'message' => 'A database error occurred while submitting review.'];
        }

        header('Location: ' . buildRedirectUrl($searchRedirect, $sortRedirect));
        exit();
    }
}

$flash = $_SESSION['service_flash'] ?? null;
unset($_SESSION['service_flash']);

$searchQuery = trim($_GET['q'] ?? '');
$sort = ($_GET['sort'] ?? 'recent') === 'rating' ? 'rating' : 'recent';
$services = [];
$reviewsByService = [];
$orderByClause = $sort === 'rating' ? 'avg_rating DESC, review_count DESC, s.created_at DESC' : 's.created_at DESC';

$serviceSql = "
    SELECT
        s.service_id,
        s.student_id,
        s.service_title,
        s.description,
        s.price,
        s.created_at,
        st.username AS owner_username,
        st.is_verified AS owner_verified,
        COALESCE(AVG(r.rating), 0) AS avg_rating,
        COUNT(r.review_id) AS review_count,
        (
            SELECT rq.status
            FROM req_service rq
            WHERE rq.service_id = s.service_id AND rq.requester_id = ?
            ORDER BY rq.requested_at DESC, rq.request_id DESC
            LIMIT 1
        ) AS request_status,
        EXISTS(
            SELECT 1
            FROM req_service rq
            WHERE rq.service_id = s.service_id AND rq.requester_id = ? AND rq.status IN ('accepted', 'completed')
        ) AS can_review,
        EXISTS(
            SELECT 1
            FROM reviews rr
            WHERE rr.service_id = s.service_id AND rr.reviewer_id = ?
        ) AS has_reviewed
    FROM services s
    INNER JOIN students st ON s.student_id = st.student_id
    LEFT JOIN reviews r ON r.service_id = s.service_id
    WHERE (? = '' OR s.service_title LIKE ? OR COALESCE(s.description, '') LIKE ?)
    GROUP BY s.service_id, s.student_id, s.service_title, s.description, s.price, s.created_at, st.username, st.is_verified
    ORDER BY $orderByClause
";

$serviceStmt = mysqli_prepare($con, $serviceSql);
if ($serviceStmt) {
    $searchLike = '%' . $searchQuery . '%';
    mysqli_stmt_bind_param($serviceStmt, 'iiisss', $studentId, $studentId, $studentId, $searchQuery, $searchLike, $searchLike);
    mysqli_stmt_execute($serviceStmt);
    $serviceResult = mysqli_stmt_get_result($serviceStmt);
    if ($serviceResult) {
        while ($row = mysqli_fetch_assoc($serviceResult)) {
            $services[] = $row;
        }
    }
    mysqli_stmt_close($serviceStmt);
}

if (count($services) > 0) {
    $serviceIds = array_map(static fn($row) => (int) $row['service_id'], $services);
    $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $reviewSql = "
        SELECT r.service_id, r.reviewer_id, r.rating, r.comment, r.created_at, st.username AS reviewer_username, st.is_verified AS reviewer_verified
        FROM reviews r
        INNER JOIN students st ON r.reviewer_id = st.student_id
        WHERE r.service_id IN ($placeholders)
        ORDER BY r.created_at DESC
    ";
    $reviewStmt = mysqli_prepare($con, $reviewSql);
    if ($reviewStmt) {
        $types = str_repeat('i', count($serviceIds));
        mysqli_stmt_bind_param($reviewStmt, $types, ...$serviceIds);
        mysqli_stmt_execute($reviewStmt);
        $reviewResult = mysqli_stmt_get_result($reviewStmt);
        if ($reviewResult) {
            while ($review = mysqli_fetch_assoc($reviewResult)) {
                $key = (int) $review['service_id'];
                $reviewsByService[$key] = $reviewsByService[$key] ?? [];
                $reviewsByService[$key][] = $review;
            }
        }
        mysqli_stmt_close($reviewStmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink | Services</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <style>
        .services-tools { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
        .services-tools form { display: flex; gap: 10px; flex: 1 1 420px; }
        .services-tools input, .review-form select, .review-form textarea { border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px 12px; font: inherit; background: #fff; }
        .services-tools input { width: 100%; }
        .btn { border: 0; border-radius: 10px; background: linear-gradient(120deg, #0ea5e9, #0f766e); color: #fff; font: inherit; font-weight: 700; padding: 10px 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-dark { background: linear-gradient(120deg, #1e293b, #334155); }
        .services-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .service-card { border: 1px solid var(--line); border-radius: 14px; padding: 14px; background: var(--surface-solid); box-shadow: 0 4px 14px rgba(17, 24, 39, 0.05); display: grid; gap: 8px; }
        .meta-small { color: #64748b; font-size: 0.86rem; }
        .profile-link-inline { color: inherit; text-decoration: none; font-weight: 700; }
        .profile-link-inline:hover { color: #0f766e; text-decoration: underline; }
        .pill { border-radius: 999px; padding: 4px 8px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; width: fit-content; }
        .status-none { background: #e2e8f0; color: #334155; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-accepted { background: #dcfce7; color: #166534; }
        .status-completed { background: #dbeafe; color: #1d4ed8; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .review-list { display: grid; gap: 8px; }
        .review-item { border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px; background: #f8fafc; }
        .review-form { display: grid; gap: 8px; }
        .review-form textarea { min-height: 80px; resize: vertical; }
        @media (max-width: 860px) { .services-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <header class="topbar">
            <div class="brand">
                <h1>CampusLink Services</h1>
                <p>Find and request services from other students.</p>
            </div>
            <div class="user-menu">
                <button class="user-trigger" type="button"><?php echo $username; ?></button>
                <div class="dropdown">
                    <a href="homepage.php">Back to Homepage</a>
                    <a href="offer.php">Offer Service</a>
                    <form method="post" action="logout.php" class="logout-form">
                        <button type="submit" class="dropdown-action">Log Out</button>
                    </form>
                </div>
            </div>
        </header>

        <?php if (is_array($flash) && isset($flash['message'])): ?>
            <div class="auth-message <?php echo ($flash['type'] ?? '') === 'success' ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <section class="services-tools">
            <form method="get" action="services.php">
                <input type="search" name="q" placeholder="Search services..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                <button type="submit" class="btn">Search</button>
            </form>
            <a class="btn btn-dark" href="services.php?<?php echo http_build_query(array_filter(['q' => $searchQuery, 'sort' => 'recent'])); ?>">Newest</a>
            <a class="btn btn-dark" href="services.php?<?php echo http_build_query(array_filter(['q' => $searchQuery, 'sort' => 'rating'])); ?>">Top Rated</a>
            <a class="btn btn-dark" href="services.php">Reset</a>
        </section>

        <section class="services-grid">
            <?php if (count($services) === 0): ?>
                <p class="empty">No services found.</p>
            <?php endif; ?>

            <?php foreach ($services as $service): ?>
                <?php
                    $sid = (int) $service['service_id'];
                    $requestStatus = (string) ($service['request_status'] ?? '');
                    $statusKey = $requestStatus === '' ? 'none' : $requestStatus;
                    $isOwner = ((int) $service['student_id']) === $studentId;
                    $canRequest = !$isOwner && !in_array($requestStatus, ['pending', 'accepted'], true);
                    $canReview = !$isOwner && ((int) $service['can_review'] === 1) && ((int) $service['has_reviewed'] === 0);
                    $serviceReviews = $reviewsByService[$sid] ?? [];
                ?>
                <article class="service-card">
                    <h3><?php echo htmlspecialchars($service['service_title']); ?></h3>
                    <p class="meta-small">
                        By
                        <a class="profile-link-inline" href="profile.php?type=student&amp;id=<?php echo (int) $service['student_id']; ?>">
                            <?php echo renderVerifiedName((string) $service['owner_username'], isVerifiedUser($service['owner_verified'] ?? 0)); ?>
                        </a>
                    </p>
                    <p><?php echo htmlspecialchars($service['description'] ?? 'No description provided.'); ?></p>
                    <p class="price">$<?php echo number_format((float) $service['price'], 2); ?></p>
                    <p class="meta-small">Rating: <?php echo number_format((float) $service['avg_rating'], 1); ?>/5 (<?php echo (int) $service['review_count']; ?>)</p>
                    <span class="pill status-<?php echo htmlspecialchars($statusKey); ?>">Request: <?php echo htmlspecialchars($statusKey); ?></span>

                    <form method="post" action="services.php">
                        <input type="hidden" name="action" value="request_service">
                        <input type="hidden" name="service_id" value="<?php echo $sid; ?>">
                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                        <button type="submit" class="btn" <?php echo $canRequest ? '' : 'disabled'; ?>>
                            <?php echo $isOwner ? 'Your Service' : ($canRequest ? 'Request Service' : 'Request Active'); ?>
                        </button>
                    </form>

                    <div>
                        <p class="meta-small">Reviews</p>
                        <?php if (count($serviceReviews) > 0): ?>
                            <div class="review-list">
                                <?php foreach (array_slice($serviceReviews, 0, 2) as $review): ?>
                                    <div class="review-item">
                                        <p class="meta-small">
                                            <a class="profile-link-inline" href="profile.php?type=student&amp;id=<?php echo (int) $review['reviewer_id']; ?>">
                                                <?php echo renderVerifiedName((string) $review['reviewer_username'], isVerifiedUser($review['reviewer_verified'] ?? 0)); ?>
                                            </a>
                                            | <?php echo (int) $review['rating']; ?>/5
                                        </p>
                                        <p><?php echo nl2br(htmlspecialchars($review['comment'] ?? '')); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="meta-small">No reviews yet.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($canReview): ?>
                        <form method="post" action="services.php" class="review-form">
                            <input type="hidden" name="action" value="submit_review">
                            <input type="hidden" name="service_id" value="<?php echo $sid; ?>">
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>">
                            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                            <select name="rating" required>
                                <option value="">Select rating</option>
                                <option value="5">5 - Excellent</option>
                                <option value="4">4 - Very Good</option>
                                <option value="3">3 - Good</option>
                                <option value="2">2 - Fair</option>
                                <option value="1">1 - Poor</option>
                            </select>
                            <textarea name="comment" maxlength="1000" placeholder="Write your review..." required></textarea>
                            <button type="submit" class="btn">Submit Review</button>
                        </form>
                    <?php else: ?>
                        <p class="meta-small">Review available only after accepted/completed request, and once per user.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
</body>
</html>
