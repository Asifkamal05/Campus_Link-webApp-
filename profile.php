<?php
session_start();
require_once 'connection.php';
require_once 'verification_helpers.php';

function isFilled($value)
{
    return trim((string) $value) !== '';
}

function normalizeValue($value, $fallback = 'Not provided')
{
    $trimmed = trim((string) $value);
    return $trimmed === '' ? $fallback : $trimmed;
}

function formatDateValue($value)
{
    if ($value === null || trim((string) $value) === '') {
        return 'Not provided';
    }

    $date = date_create((string) $value);
    if (!$date) {
        return (string) $value;
    }

    return date_format($date, 'F j, Y');
}

function formatDateTimeValue($value)
{
    if ($value === null || trim((string) $value) === '') {
        return 'Unknown time';
    }

    $date = date_create((string) $value);
    if (!$date) {
        return (string) $value;
    }

    return date_format($date, 'M j, Y g:i A');
}

function fetchCount($con, $sql, $id)
{
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return (int) ($row['total'] ?? 0);
}

function fetchRows($con, $sql, $id)
{
    $rows = [];
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) {
        return $rows;
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    mysqli_stmt_close($stmt);
    return $rows;
}

function buildInitials($name)
{
    $parts = preg_split('/\s+/', trim((string) $name));
    $letters = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $letters .= strtoupper(substr($part, 0, 1));
        if (strlen($letters) === 2) {
            break;
        }
    }

    return $letters === '' ? 'U' : $letters;
}

if (!in_array(($_SESSION['role'] ?? ''), ['student', 'company'], true)) {
    header('Location: index.php');
    exit();
}

$viewerRole = $_SESSION['role'];
$homePath = $viewerRole === 'company' ? 'compHomepage.php' : 'homepage.php';
$homeLabel = $viewerRole === 'company' ? 'Back to Company Homepage' : 'Back to Student Homepage';

$targetType = $_GET['type'] ?? '';
$targetType = in_array($targetType, ['student', 'company'], true) ? $targetType : '';
$targetId = (int) ($_GET['id'] ?? 0);

if ($targetType === '' || $targetId <= 0) {
    header('Location: ' . $homePath);
    exit();
}

$displayName = 'Unknown User';
$accountType = ucfirst($targetType);
$roleMessage = '';
$memberSince = '';
$usernameTag = '';
$profileCompletion = 0;
$profileData = [];
$profileStats = [];
$activityItems = [];
$found = false;
$isVerified = false;

if ($targetType === 'student') {
    $stmt = mysqli_prepare(
        $con,
        'SELECT student_id, username, f_name, m_name, l_name, address, birth_day, email, phone, created_at, is_verified
         FROM students
         WHERE student_id = ?
         LIMIT 1'
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $targetId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $student = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if ($student) {
            $fullName = trim(
                ($student['f_name'] ?? '') . ' ' .
                ($student['m_name'] ?? '') . ' ' .
                ($student['l_name'] ?? '')
            );
            if ($fullName === '') {
                $fullName = $student['username'];
            }

            $displayName = $fullName;
            $isVerified = isVerifiedUser($student['is_verified'] ?? 0);
            $usernameTag = '@' . normalizeValue($student['username'] ?? '');
            $memberSince = formatDateValue($student['created_at'] ?? null);
            $roleMessage = 'Student profile details and activity across services, products, and job applications.';
            $found = true;

            $profileData = [
                'Account Type' => $accountType,
                'Full Name' => normalizeValue($fullName),
                'Username' => normalizeValue($student['username'] ?? ''),
                'Verification' => renderVerificationStatus($isVerified),
                'Email' => normalizeValue($student['email'] ?? ''),
                'Phone' => normalizeValue($student['phone'] ?? ''),
                'Address' => normalizeValue($student['address'] ?? ''),
                'Birth Date' => formatDateValue($student['birth_day'] ?? null),
                'Joined On' => formatDateValue($student['created_at'] ?? null),
            ];

            $profileStats = [
                'Services Offered' => fetchCount($con, 'SELECT COUNT(*) AS total FROM services WHERE student_id = ?', $targetId),
                'Products Listed' => fetchCount($con, 'SELECT COUNT(*) AS total FROM products WHERE owner_id = ?', $targetId),
                'Jobs Applied' => fetchCount($con, 'SELECT COUNT(*) AS total FROM apply_job WHERE applicant_id = ?', $targetId),
                'Reviews Written' => fetchCount($con, 'SELECT COUNT(*) AS total FROM reviews WHERE reviewer_id = ?', $targetId),
            ];

            $completionFields = [
                $fullName,
                $student['username'] ?? '',
                $student['email'] ?? '',
                $student['address'] ?? '',
                $student['birth_day'] ?? '',
                $student['phone'] ?? '',
            ];

            $filledFields = 0;
            foreach ($completionFields as $field) {
                if (isFilled($field)) {
                    $filledFields++;
                }
            }
            $profileCompletion = (int) round(($filledFields / count($completionFields)) * 100);

            $serviceRows = fetchRows(
                $con,
                'SELECT service_title AS title, created_at
                 FROM services
                 WHERE student_id = ?
                 ORDER BY created_at DESC
                 LIMIT 3',
                $targetId
            );

            foreach ($serviceRows as $row) {
                $activityItems[] = [
                    'type' => 'Service Posted',
                    'title' => normalizeValue($row['title'] ?? ''),
                    'meta' => 'Published a service offer.',
                    'time_raw' => $row['created_at'] ?? '',
                ];
            }

            $productRows = fetchRows(
                $con,
                'SELECT product_title AS title, status, created_at
                 FROM products
                 WHERE owner_id = ?
                 ORDER BY created_at DESC
                 LIMIT 3',
                $targetId
            );

            foreach ($productRows as $row) {
                $status = ucfirst(strtolower((string) ($row['status'] ?? 'available')));
                $activityItems[] = [
                    'type' => 'Product Listed',
                    'title' => normalizeValue($row['title'] ?? ''),
                    'meta' => 'Current status: ' . $status,
                    'time_raw' => $row['created_at'] ?? '',
                ];
            }

            $applicationRows = fetchRows(
                $con,
                'SELECT j.job_title AS title, c.name AS company_name, a.applied_at AS created_at
                 FROM apply_job a
                 INNER JOIN jobs j ON a.job_id = j.job_id
                 INNER JOIN companies c ON j.company_id = c.company_id
                 WHERE a.applicant_id = ?
                 ORDER BY a.applied_at DESC
                 LIMIT 3',
                $targetId
            );

            foreach ($applicationRows as $row) {
                $activityItems[] = [
                    'type' => 'Job Application',
                    'title' => normalizeValue($row['title'] ?? ''),
                    'meta' => 'Applied to ' . normalizeValue($row['company_name'] ?? ''),
                    'time_raw' => $row['created_at'] ?? '',
                ];
            }
        }
    }
} else {
    $stmt = mysqli_prepare(
        $con,
        'SELECT company_id, username, name, address, email, phone, created_at
         FROM companies
         WHERE company_id = ?
         LIMIT 1'
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $targetId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $company = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if ($company) {
            $displayName = normalizeValue($company['name'] ?? '');
            $usernameTag = '@' . normalizeValue($company['username'] ?? '');
            $memberSince = formatDateValue($company['created_at'] ?? null);
            $roleMessage = 'Company profile details and hiring activity on CampusLink.';
            $found = true;

            $profileData = [
                'Account Type' => $accountType,
                'Company Name' => normalizeValue($company['name'] ?? ''),
                'Username' => normalizeValue($company['username'] ?? ''),
                'Email' => normalizeValue($company['email'] ?? ''),
                'Phone' => normalizeValue($company['phone'] ?? ''),
                'Address' => normalizeValue($company['address'] ?? ''),
                'Joined On' => formatDateValue($company['created_at'] ?? null),
            ];

            $profileStats = [
                'Jobs Posted' => fetchCount($con, 'SELECT COUNT(*) AS total FROM jobs WHERE company_id = ?', $targetId),
                'Applications Received' => fetchCount(
                    $con,
                    'SELECT COUNT(*) AS total
                     FROM apply_job a
                     INNER JOIN jobs j ON a.job_id = j.job_id
                     WHERE j.company_id = ?',
                    $targetId
                ),
                'Company Reviews' => fetchCount($con, 'SELECT COUNT(*) AS total FROM reviews WHERE company_id = ?', $targetId),
            ];

            $completionFields = [
                $company['name'] ?? '',
                $company['username'] ?? '',
                $company['email'] ?? '',
                $company['address'] ?? '',
                $company['phone'] ?? '',
            ];

            $filledFields = 0;
            foreach ($completionFields as $field) {
                if (isFilled($field)) {
                    $filledFields++;
                }
            }
            $profileCompletion = (int) round(($filledFields / count($completionFields)) * 100);

            $jobRows = fetchRows(
                $con,
                'SELECT job_title AS title, salary, created_at
                 FROM jobs
                 WHERE company_id = ?
                 ORDER BY created_at DESC
                 LIMIT 6',
                $targetId
            );

            foreach ($jobRows as $row) {
                $salary = isset($row['salary']) ? '$' . number_format((float) $row['salary'], 2) : 'Salary not set';
                $activityItems[] = [
                    'type' => 'Job Posted',
                    'title' => normalizeValue($row['title'] ?? ''),
                    'meta' => 'Compensation: ' . $salary,
                    'time_raw' => $row['created_at'] ?? '',
                ];
            }
        }
    }
}

if (!$found) {
    header('Location: ' . $homePath);
    exit();
}

if (count($activityItems) > 0) {
    usort($activityItems, static function ($a, $b) {
        return strtotime((string) ($b['time_raw'] ?? '')) <=> strtotime((string) ($a['time_raw'] ?? ''));
    });
    $activityItems = array_slice($activityItems, 0, 6);
    foreach ($activityItems as $index => $item) {
        $activityItems[$index]['time'] = formatDateTimeValue($item['time_raw'] ?? '');
    }
}

$avatarInitials = buildInitials($displayName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink | Profile</title>
    <?php $cssVersion = file_exists(__DIR__ . '/styles.css') ? (string) filemtime(__DIR__ . '/styles.css') : '1'; ?>
    <link rel="stylesheet" href="styles.css?v=<?php echo urlencode($cssVersion); ?>">
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
</head>
<body>
    <div class="container profile-page">
        <header class="topbar">
            <div class="brand">
                <h1>CampusLink Profile</h1>
                <p>Public profile details and recent activity.</p>
            </div>

            <div class="user-menu">
                <button class="user-trigger" type="button"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></button>
                <div class="dropdown">
                    <a href="<?php echo htmlspecialchars($homePath); ?>">Homepage</a>
                    <a href="myProfile.php">My Profile</a>
                    <form method="post" action="logout.php" class="logout-form">
                        <button type="submit" class="dropdown-action">Log Out</button>
                    </form>
                </div>
            </div>
        </header>

        <section class="profile-hero-card">
            <div class="profile-hero-copy">
                <p class="profile-badge"><?php echo htmlspecialchars($accountType); ?> Account</p>
                <h2><?php echo renderVerifiedName($displayName, $isVerified); ?></h2>
                <p class="profile-subtitle"><?php echo htmlspecialchars($roleMessage); ?></p>
                <div class="profile-pill-row">
                    <span class="profile-pill"><?php echo htmlspecialchars($usernameTag); ?></span>
                    <span class="profile-pill">Member since <?php echo htmlspecialchars($memberSince); ?></span>
                    <?php if ($targetType === 'student'): ?>
                        <span class="verified-status"><?php echo htmlspecialchars(renderVerificationStatus($isVerified)); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="profile-avatar" aria-hidden="true"><?php echo htmlspecialchars($avatarInitials); ?></div>
        </section>

        <div class="profile-layout">
            <section class="profile-card">
                <div class="profile-card-head">
                    <h3>Profile Details</h3>
                    <p>Basic information for this account.</p>
                </div>
                <div class="profile-details-grid">
                    <?php foreach ($profileData as $label => $value): ?>
                        <div class="profile-detail-item">
                            <p class="profile-detail-label"><?php echo htmlspecialchars($label); ?></p>
                            <p class="profile-detail-value"><?php echo htmlspecialchars((string) $value); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <aside class="profile-side-stack">
                <section class="profile-card">
                    <div class="profile-card-head">
                        <h3>Profile Strength</h3>
                        <p>Completion based on major profile fields.</p>
                    </div>
                    <div class="profile-progress-wrap">
                        <div class="profile-progress-track">
                            <span style="width: <?php echo (int) $profileCompletion; ?>%;"></span>
                        </div>
                        <p class="profile-progress-value"><?php echo (int) $profileCompletion; ?>% complete</p>
                    </div>
                </section>

                <section class="profile-card">
                    <div class="profile-card-head">
                        <h3>Navigation</h3>
                        <p>Return to your workspace quickly.</p>
                    </div>
                    <div class="profile-links">
                        <a class="profile-quick-link" href="<?php echo htmlspecialchars($homePath); ?>">Homepage</a>
                        <a class="profile-quick-link" href="myProfile.php">My Profile</a>
                    </div>
                </section>
            </aside>
        </div>

        <section class="section">
            <h3>At a Glance</h3>
            <div class="profile-stat-grid">
                <?php foreach ($profileStats as $label => $value): ?>
                    <article class="profile-stat-card">
                        <p class="profile-stat-label"><?php echo htmlspecialchars($label); ?></p>
                        <p class="profile-stat-value"><?php echo (int) $value; ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section">
            <h3>Recent Activity</h3>
            <?php if (count($activityItems) > 0): ?>
                <div class="profile-timeline">
                    <?php foreach ($activityItems as $item): ?>
                        <article class="profile-activity-item">
                            <div class="profile-activity-mark"></div>
                            <div>
                                <p class="profile-activity-type"><?php echo htmlspecialchars($item['type']); ?></p>
                                <h4 class="profile-activity-title"><?php echo htmlspecialchars($item['title']); ?></h4>
                                <p class="profile-activity-meta"><?php echo htmlspecialchars($item['meta']); ?></p>
                            </div>
                            <p class="profile-activity-time"><?php echo htmlspecialchars($item['time']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty">No recent activity available for this account.</p>
            <?php endif; ?>
        </section>

        <section class="section">
            <a class="profile-home-link" href="<?php echo htmlspecialchars($homePath); ?>"><?php echo htmlspecialchars($homeLabel); ?></a>
        </section>
    </div>
</body>
</html>
