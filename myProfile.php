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

    if ($letters === '') {
        return 'U';
    }

    return $letters;
}

$role = $_SESSION['role'] ?? '';
$displayName = $_SESSION['username'] ?? 'User';
$accountType = '';
$roleMessage = '';
$memberSince = '';
$usernameTag = '';
$profileCompletion = 0;
$homePath = 'index.php';
$homeLabel = 'Back to Home';
$profileData = [];
$profileStats = [];
$quickLinks = [];
$activityItems = [];
$userFound = false;
$isVerified = false;

if ($role === 'student' && isset($_SESSION['student_id'])) {
    $studentId = (int) $_SESSION['student_id'];
    $accountType = 'Student';
    $homePath = 'homepage.php';
    $homeLabel = 'Back to Student Homepage';

    $stmt = mysqli_prepare(
        $con,
        'SELECT username, f_name, m_name, l_name, address, birth_day, email, phone, created_at, is_verified
         FROM students
         WHERE student_id = ?
         LIMIT 1'
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $studentId);
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
            $usernameTag = '@' . ($student['username'] ?? '');
            $memberSince = formatDateValue($student['created_at'] ?? null);
            $roleMessage = 'Showcase your skills, products, and applications from one modern profile hub.';
            $userFound = true;

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
                'Services Offered' => fetchCount($con, 'SELECT COUNT(*) AS total FROM services WHERE student_id = ?', $studentId),
                'Products Listed' => fetchCount($con, 'SELECT COUNT(*) AS total FROM products WHERE owner_id = ?', $studentId),
                'Jobs Applied' => fetchCount($con, 'SELECT COUNT(*) AS total FROM apply_job WHERE applicant_id = ?', $studentId),
                'Reviews Written' => fetchCount($con, 'SELECT COUNT(*) AS total FROM reviews WHERE reviewer_id = ?', $studentId),
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

            $quickLinks = [
                ['href' => 'homepage.php', 'label' => 'Student Homepage'],
                ['href' => 'services.php', 'label' => 'Find Services'],
                ['href' => 'products.php', 'label' => 'Browse Products'],
                ['href' => 'offer.php', 'label' => 'Offer a Service'],
                ['href' => 'list.php', 'label' => 'List a Product'],
            ];

            $serviceRows = fetchRows(
                $con,
                'SELECT service_title AS title, created_at
                 FROM services
                 WHERE student_id = ?
                 ORDER BY created_at DESC
                 LIMIT 3',
                $studentId
            );

            foreach ($serviceRows as $row) {
                $activityItems[] = [
                    'type' => 'Service Posted',
                    'title' => normalizeValue($row['title'] ?? ''),
                    'meta' => 'You published a service offer.',
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
                $studentId
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
                $studentId
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
} elseif ($role === 'company' && isset($_SESSION['company_id'])) {
    $companyId = (int) $_SESSION['company_id'];
    $accountType = 'Company';
    $homePath = 'compHomepage.php';
    $homeLabel = 'Back to Company Homepage';

    $stmt = mysqli_prepare(
        $con,
        'SELECT username, name, address, email, phone, created_at
         FROM companies
         WHERE company_id = ?
         LIMIT 1'
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $companyId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $company = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if ($company) {
            $displayName = normalizeValue($company['name'] ?? '');
            $usernameTag = '@' . ($company['username'] ?? '');
            $memberSince = formatDateValue($company['created_at'] ?? null);
            $roleMessage = 'Manage hiring activity and keep your recruiter brand profile polished.';
            $userFound = true;

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
                'Jobs Posted' => fetchCount($con, 'SELECT COUNT(*) AS total FROM jobs WHERE company_id = ?', $companyId),
                'Applications Received' => fetchCount(
                    $con,
                    'SELECT COUNT(*) AS total
                     FROM apply_job a
                     INNER JOIN jobs j ON a.job_id = j.job_id
                     WHERE j.company_id = ?',
                    $companyId
                ),
                'Company Reviews' => fetchCount($con, 'SELECT COUNT(*) AS total FROM reviews WHERE company_id = ?', $companyId),
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

            $quickLinks = [
                ['href' => 'compHomepage.php', 'label' => 'Company Homepage'],
                ['href' => 'logout.php', 'label' => 'Log Out (Quick)', 'type' => 'logout'],
            ];

            $jobRows = fetchRows(
                $con,
                'SELECT job_title AS title, salary, created_at
                 FROM jobs
                 WHERE company_id = ?
                 ORDER BY created_at DESC
                 LIMIT 6',
                $companyId
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

if (!$userFound) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit();
}

if (count($quickLinks) === 0) {
    $quickLinks = [
        ['href' => $homePath, 'label' => 'Homepage'],
    ];
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
    <title>CampusLink | My Profile</title>
    <?php $cssVersion = file_exists(__DIR__ . '/styles.css') ? (string) filemtime(__DIR__ . '/styles.css') : '1'; ?>
    <link rel="stylesheet" href="styles.css?v=<?php echo urlencode($cssVersion); ?>">
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
</head>
<body>
    <div class="container profile-page">
        <header class="topbar">
            <div class="brand">
                <h1>CampusLink Profile</h1>
                <p>Your full account snapshot in one modern view.</p>
            </div>

            <div class="user-menu">
                <button class="user-trigger" type="button"><?php echo renderVerifiedName($displayName, $role === 'student' && $isVerified); ?></button>
                <div class="dropdown">
                    <a href="<?php echo htmlspecialchars($homePath); ?>">Homepage</a>
                    <form method="post" action="logout.php" class="logout-form">
                        <button type="submit" class="dropdown-action">Log Out</button>
                    </form>
                </div>
            </div>
        </header>

        <section class="profile-hero-card">
            <div class="profile-hero-copy">
                <p class="profile-badge"><?php echo htmlspecialchars($accountType); ?> Account</p>
                <h2><?php echo renderVerifiedName($displayName, $role === 'student' && $isVerified); ?></h2>
                <p class="profile-subtitle"><?php echo htmlspecialchars($roleMessage); ?></p>
                <div class="profile-pill-row">
                    <span class="profile-pill"><?php echo htmlspecialchars($usernameTag); ?></span>
                    <span class="profile-pill">Member since <?php echo htmlspecialchars($memberSince); ?></span>
                    <?php if ($role === 'student'): ?>
                        <span class="verified-status"><?php echo htmlspecialchars(renderVerificationStatus($isVerified)); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="profile-avatar" aria-hidden="true"><?php echo htmlspecialchars($avatarInitials); ?></div>
        </section>

        <div class="profile-layout">
            <section class="profile-card">
                <div class="profile-card-head">
                    <h3>Account Details</h3>
                    <p>Core information connected to your account.</p>
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
                        <p>Completion based on important profile fields.</p>
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
                        <h3>Quick Links</h3>
                        <p>Fast access to your most used pages.</p>
                    </div>
                    <div class="profile-links">
                        <?php foreach ($quickLinks as $link): ?>
                            <?php if (($link['type'] ?? '') === 'logout'): ?>
                                <form method="post" action="logout.php" class="profile-link-form">
                                    <button type="submit" class="profile-quick-link profile-quick-link-btn"><?php echo htmlspecialchars($link['label']); ?></button>
                                </form>
                            <?php else: ?>
                                <a class="profile-quick-link" href="<?php echo htmlspecialchars($link['href']); ?>"><?php echo htmlspecialchars($link['label']); ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
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
                <p class="empty">No recent activity yet. Once you post or apply, updates will appear here.</p>
            <?php endif; ?>
        </section>

        <section class="section">
            <a class="profile-home-link" href="<?php echo htmlspecialchars($homePath); ?>"><?php echo htmlspecialchars($homeLabel); ?></a>
        </section>
    </div>
</body>
</html>
