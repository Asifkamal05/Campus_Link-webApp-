<?php
session_start();
require_once 'connection.php';
require_once 'verification_helpers.php';

// Check if user is logged in as student
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

// Handle search and sort
$searchQuery = trim($_GET['q'] ?? '');
$sort = ($_GET['sort'] ?? 'recent');

$orderBy = "j.created_at DESC";
if ($sort === 'salary_high') {
    $orderBy = "j.salary DESC";
} elseif ($sort === 'salary_low') {
    $orderBy = "j.salary ASC";
}

// Fetch jobs
$jobs = [];
$jobSql = "
    SELECT j.job_id, j.company_id, j.job_title, j.description, j.salary, j.created_at, c.name AS company_name, c.address AS company_address, c.is_verified
    FROM jobs j
    INNER JOIN companies c ON j.company_id = c.company_id
    WHERE (? = '' OR j.job_title LIKE ? OR j.description LIKE ? OR c.name LIKE ?)
    ORDER BY $orderBy
";

$stmt = mysqli_prepare($con, $jobSql);
if ($stmt) {
    $searchTerm = '%' . $searchQuery . '%';
    mysqli_stmt_bind_param($stmt, 'ssss', $searchQuery, $searchTerm, $searchTerm, $searchTerm);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $jobs[] = $row;
    }
    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink | Career Opportunities</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="shortcut icon" href="img/logo.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            --secondary-gradient: linear-gradient(135deg, #3b82f6 0%, #2dd4bf 100%);
            --glass-bg: rgba(255, 255, 255, 0.8);
            --card-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }

        .jobs-hero {
            background: var(--primary-gradient);
            padding: 60px 20px;
            color: white;
            text-align: center;
            border-radius: 0 0 40px 40px;
            margin-bottom: -40px;
            position: relative;
            z-index: 1;
        }

        .jobs-hero h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 15px;
            letter-spacing: -1px;
        }

        .jobs-hero p {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }

        .content-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 20px 60px;
            position: relative;
            z-index: 2;
        }

        .filter-bar {
            background: white;
            padding: 24px;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 30px;
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 14px 20px;
            border: 2px solid #f1f5f9;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            outline: none;
        }

        .search-box input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .sort-select {
            padding: 14px 20px;
            border: 2px solid #f1f5f9;
            border-radius: 12px;
            background: white;
            color: #475569;
            font-weight: 500;
            outline: none;
            cursor: pointer;
        }

        .btn-search {
            background: #1e293b;
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-search:hover {
            background: #0f172a;
            transform: translateY(-1px);
        }

        .jobs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
        }

        .job-card {
            background: white;
            border-radius: 20px;
            padding: 28px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            gap: 15px;
            position: relative;
            overflow: hidden;
        }

        .job-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 30px -10px rgba(0, 0, 0, 0.08);
            border-color: #6366f1;
        }

        .job-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-gradient);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .job-card:hover::before {
            opacity: 1;
        }

        .company-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }

        .company-logo-placeholder {
            width: 40px;
            height: 40px;
            background: #f1f5f9;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #6366f1;
            font-size: 1.2rem;
        }

        .company-name {
            font-weight: 600;
            color: #64748b;
            font-size: 0.9rem;
        }

        .job-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.3;
        }

        .job-desc {
            color: #475569;
            font-size: 0.95rem;
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .job-footer {
            margin-top: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }

        .salary {
            font-weight: 700;
            color: #059669;
            font-size: 1.1rem;
        }

        .btn-apply {
            background: #f1f5f9;
            color: #1e293b;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .job-card:hover .btn-apply {
            background: var(--primary-gradient);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #64748b;
        }

        @media (max-width: 640px) {
            .jobs-hero h1 { font-size: 1.8rem; }
            .filter-bar { padding: 16px; }
            .jobs-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <header class="topbar">
        <div class="container" style="max-width: 1100px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 15px 20px;">
            <div class="brand">
                <h1 style="font-size: 1.5rem; margin: 0;">CampusLink</h1>
            </div>
            <div class="user-menu" style="display: flex; align-items: center; gap: 20px;">
                <a href="homepage.php" style="text-decoration: none; color: #475569; font-weight: 500;">Back to Home</a>
                <div style="background: white; padding: 8px 16px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); font-weight: 600;">
                    <?php echo $username; ?>
                </div>
            </div>
        </div>
    </header>

    <section class="jobs-hero">
        <h1>Find Your Dream Career</h1>
        <p>Explore exclusive job opportunities, internships, and part-time roles from top companies looking for student talent.</p>
    </section>

    <div class="content-container">
        <section class="filter-bar">
            <form method="get" action="jobs.php" class="search-form" style="display: contents;">
                <div class="search-box">
                    <input type="text" name="q" placeholder="Search job titles, companies, or keywords..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
                <select name="sort" class="sort-select" onchange="this.form.submit()">
                    <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Most Recent</option>
                    <option value="salary_high" <?php echo $sort === 'salary_high' ? 'selected' : ''; ?>>Highest Salary</option>
                    <option value="salary_low" <?php echo $sort === 'salary_low' ? 'selected' : ''; ?>>Lowest Salary</option>
                </select>
                <button type="submit" class="btn-search">Search Jobs</button>
            </form>
        </section>

        <div class="jobs-grid">
            <?php if (count($jobs) > 0): ?>
                <?php foreach ($jobs as $job): ?>
                    <div class="job-card">
                        <div class="company-badge">
                            <div class="company-logo-placeholder">
                                <?php echo substr($job['company_name'], 0, 1); ?>
                            </div>
                            <div class="company-info">
                                <div class="company-name"><?php echo renderVerifiedName($job['company_name'], isVerifiedUser($job['is_verified'])); ?></div>
                                <div style="font-size: 0.75rem; color: #94a3b8;"><?php echo htmlspecialchars($job['company_address']); ?></div>
                            </div>
                        </div>
                        <h2 class="job-title"><?php echo htmlspecialchars($job['job_title']); ?></h2>
                        <p class="job-desc"><?php echo htmlspecialchars($job['description']); ?></p>
                        <div class="job-footer">
                            <div class="salary">$<?php echo number_format($job['salary'], 0); ?>/mo</div>
                            <a href="profile.php?type=company&id=<?php echo $job['company_id']; ?>" class="btn-apply">View Details</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <h3>No jobs found</h3>
                    <p>Try adjusting your search or filters to find what you're looking for.</p>
                    <a href="jobs.php" style="display: inline-block; margin-top: 15px; color: #6366f1; font-weight: 600; text-decoration: none;">Clear all filters</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>
