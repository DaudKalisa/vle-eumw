<?php
// examination_manager/generate_tokens.php - Generate Exam Tokens for Students
require_once '../includes/auth.php';
requireLogin();
requireRole(['examination_manager', 'examination_officer']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get exam details
$examId = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$exam = null;

if ($examId > 0) {
    $stmt = $conn->prepare("
        SELECT e.*, c.course_name, l.full_name as lecturer_name
        FROM exams e
        LEFT JOIN vle_courses c ON e.course_id = c.course_id
        LEFT JOIN lecturers l ON e.lecturer_id = l.lecturer_id
        WHERE e.exam_id = ? AND e.is_active = 1
    ");
    $stmt->bind_param("i", $examId);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
}

// Handle token generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_tokens'])) {
    $selectedStudents = isset($_POST['students']) ? $_POST['students'] : [];
    $tokenValidity = (int)$_POST['token_validity_hours'];

    if (empty($selectedStudents)) {
        $error = "Please select at least one student";
    } elseif (!$exam) {
        $error = "Invalid exam selected";
    } else {
        try {
            $conn->begin_transaction();
            $generatedTokens = 0;

            foreach ($selectedStudents as $studentId) {
                $checkStmt = $conn->prepare("SELECT token_id FROM exam_tokens WHERE exam_id = ? AND student_id = ?");
                $checkStmt->bind_param("is", $examId, $studentId);
                $checkStmt->execute();

                if ($checkStmt->get_result()->num_rows == 0) {
                    $token = generateUniqueToken();
                    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$tokenValidity} hours"));

                    $insertStmt = $conn->prepare("INSERT INTO exam_tokens (exam_id, student_id, token, expires_at) VALUES (?, ?, ?, ?)");
                    $insertStmt->bind_param("isss", $examId, $studentId, $token, $expiresAt);

                    if ($insertStmt->execute()) {
                        $generatedTokens++;
                    }
                }
            }

            $conn->commit();
            $success = "Generated $generatedTokens exam tokens successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to generate tokens: " . $e->getMessage();
        }
    }
}

// Get enrolled students for the course
$enrolledStudents = [];
if ($exam && $exam['course_id']) {
    $stmt = $conn->prepare("
        SELECT s.student_id, s.full_name, s.email,
               CASE WHEN et.token_id IS NOT NULL THEN 1 ELSE 0 END as has_token
        FROM students s
        JOIN vle_enrollments e ON s.student_id = e.student_id
        LEFT JOIN exam_tokens et ON et.exam_id = ? AND et.student_id = s.student_id
        WHERE e.course_id = ? AND s.is_active = 1
        ORDER BY s.full_name
    ");
    $stmt->bind_param("ii", $examId, $exam['course_id']);
    $stmt->execute();
    $enrolledStudents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get existing tokens
$existingTokens = [];
if ($examId > 0) {
    $stmt = $conn->prepare("
        SELECT et.*, s.full_name, s.email
        FROM exam_tokens et
        JOIN students s ON et.student_id = s.student_id
        WHERE et.exam_id = ?
        ORDER BY et.created_at DESC
    ");
    $stmt->bind_param("i", $examId);
    $stmt->execute();
    $existingTokens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get all exams for the exam selector (when no exam_id is provided)
$allExams = [];
$result = $conn->query("
    SELECT e.exam_id, e.exam_code, e.exam_name, c.course_name, e.start_time,
           (SELECT COUNT(*) FROM exam_tokens WHERE exam_id = e.exam_id) as token_count
    FROM exams e
    LEFT JOIN vle_courses c ON e.course_id = c.course_id
    WHERE e.is_active = 1
    ORDER BY e.created_at DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $allExams[] = $row;
    }
}

// Token stats
$tokenStats = ['total' => 0, 'active' => 0, 'used' => 0, 'expired' => 0];
$result = $conn->query("SELECT COUNT(*) as total FROM exam_tokens");
if ($result) $tokenStats['total'] = $result->fetch_assoc()['total'];
$result = $conn->query("SELECT COUNT(*) as total FROM exam_tokens WHERE is_used = 1");
if ($result) $tokenStats['used'] = $result->fetch_assoc()['total'];
$result = $conn->query("SELECT COUNT(*) as total FROM exam_tokens WHERE is_used = 0 AND expires_at > NOW()");
if ($result) $tokenStats['active'] = $result->fetch_assoc()['total'];
$result = $conn->query("SELECT COUNT(*) as total FROM exam_tokens WHERE is_used = 0 AND expires_at <= NOW()");
if ($result) $tokenStats['expired'] = $result->fetch_assoc()['total'];

function generateUniqueToken() {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Generate Exam Tokens - VLE Examination Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/admin-dashboard.css" rel="stylesheet">
    <?php include_once __DIR__ . '/../includes/pwa-head.php'; ?>
    <style>
        :root {
            --token-gradient: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            --card-hover-transform: translateY(-4px);
        }
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; margin: 0; }

        /* Page Header Card */
        .page-header-card {
            background: var(--token-gradient);
            border-radius: 24px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
            box-shadow: 0 15px 50px rgba(249, 115, 22, 0.35);
        }
        .page-header-card .header-content { display: flex; align-items: center; gap: 1.25rem; }
        .page-header-card .header-icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.75rem; font-weight: 700;
            border: 4px solid rgba(255,255,255,0.4);
        }
        .page-header-card .header-title { font-size: 1.75rem; font-weight: 700; margin: 0; }
        @media (min-width: 992px) {
            .page-header-card .header-title { font-size: 2rem; }
            .page-header-card .header-icon { width: 80px; height: 80px; }
        }
        .page-header-card .header-subtitle { opacity: 0.9; font-size: 1rem; margin: 0; }
        .page-header-card .header-stats {
            display: flex; gap: 1.5rem; margin-top: 1rem; flex-wrap: wrap;
        }
        .page-header-card .header-stat {
            display: flex; align-items: center; gap: 0.5rem;
            opacity: 0.85; font-size: 0.9rem;
        }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
        @media (min-width: 768px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }
        .stat-card {
            background: white; border-radius: 16px; padding: 1.25rem 1rem;
            display: flex; align-items: center; gap: 0.75rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            border-left: 4px solid var(--accent-color, #f97316);
        }
        .stat-card:hover { transform: var(--card-hover-transform); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
        .stat-card .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; color: white; flex-shrink: 0;
        }
        .stat-card .stat-value { font-size: 1.5rem; font-weight: 700; display: block; }
        .stat-card .stat-label { font-size: 0.8rem; color: #64748b; display: block; font-weight: 500; }

        /* Form Sections */
        .form-section {
            background: white; border-radius: 16px; overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            margin-bottom: 1.5rem;
        }
        .form-section-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; gap: 0.75rem;
        }
        .form-section-header .section-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; color: white; flex-shrink: 0;
        }
        .form-section-header h5 { font-size: 0.95rem; font-weight: 600; margin: 0; color: #1e293b; }
        .form-section-header p { font-size: 0.8rem; color: #64748b; margin: 0; }
        .form-section-body { padding: 1.25rem; }

        .form-label { font-weight: 500; color: #334155; font-size: 0.85rem; margin-bottom: 0.35rem; }
        .form-control, .form-select {
            border-radius: 10px; border: 1.5px solid #e2e8f0;
            padding: 0.6rem 0.85rem; font-size: 0.9rem;
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,0.15);
        }

        /* Token Cards */
        .token-card {
            background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
            border: 1.5px solid #fed7aa;
            border-radius: 14px;
            padding: 1rem 1.25rem;
            margin-bottom: 0.75rem;
            transition: all 0.2s;
        }
        .token-card:hover { border-color: #f97316; box-shadow: 0 4px 12px rgba(249,115,22,0.15); }
        .token-code {
            font-family: 'Courier New', monospace; font-weight: 700;
            font-size: 1.2rem; color: #c2410c; letter-spacing: 2px;
        }
        .token-status { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; font-weight: 600; padding: 0.2rem 0.6rem; border-radius: 6px; }
        .token-status.active { background: #dcfce7; color: #166534; }
        .token-status.used { background: #dbeafe; color: #1e40af; }
        .token-status.expired { background: #fee2e2; color: #991b1b; }

        /* Student List */
        .student-list { max-height: 350px; overflow-y: auto; }
        .student-item {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.6rem 0.75rem; border-radius: 10px;
            transition: background 0.2s; cursor: pointer;
        }
        .student-item:hover { background: #f8fafc; }
        .student-item.has-token { opacity: 0.6; }
        .student-item .student-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #f97316, #ea580c);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 0.8rem; flex-shrink: 0;
        }
        .student-item .student-info { flex: 1; }
        .student-item .student-name { font-size: 0.85rem; font-weight: 500; color: #1e293b; }
        .student-item .student-id-text { font-size: 0.75rem; color: #94a3b8; }

        /* Exam Selector Cards */
        .exam-select-card {
            background: white; border-radius: 14px; padding: 1rem 1.25rem;
            border: 1.5px solid #e2e8f0; transition: all 0.2s;
            text-decoration: none; color: inherit; display: block;
            margin-bottom: 0.75rem;
        }
        .exam-select-card:hover { border-color: #f97316; box-shadow: 0 4px 12px rgba(249,115,22,0.15); color: inherit; transform: translateX(4px); }
        .exam-select-card .exam-title { font-weight: 600; color: #1e293b; font-size: 0.9rem; }
        .exam-select-card .exam-meta { font-size: 0.8rem; color: #64748b; }
        .exam-select-card .token-badge {
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white; font-size: 0.7rem; font-weight: 600;
            padding: 0.2rem 0.5rem; border-radius: 6px;
        }

        /* Alert Styles */
        .alert-custom {
            border: none; border-radius: 14px; padding: 1rem 1.25rem;
            display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem;
        }
        .alert-custom.success { background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); color: #065f46; }
        .alert-custom.error { background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%); color: #991b1b; }
        .alert-custom.warning { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); color: #92400e; }
        .alert-custom .alert-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; color: white; flex-shrink: 0;
        }
        .alert-custom.success .alert-icon { background: #10b981; }
        .alert-custom.error .alert-icon { background: #ef4444; }
        .alert-custom.warning .alert-icon { background: #f59e0b; }

        /* Exam Info Bar */
        .exam-info-bar {
            background: white; border-radius: 16px; padding: 1.25rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            margin-bottom: 1.5rem;
            display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: center;
        }
        .exam-info-item { display: flex; align-items: center; gap: 0.5rem; }
        .exam-info-item .info-label { font-size: 0.75rem; color: #94a3b8; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .exam-info-item .info-value { font-size: 0.9rem; color: #1e293b; font-weight: 600; }

        /* Section Headers */
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .section-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; }

        /* Export Button */
        .btn-export {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white; border: none; padding: 0.5rem 1rem; border-radius: 10px;
            font-weight: 500; font-size: 0.85rem; transition: all 0.2s;
            display: flex; align-items: center; gap: 0.35rem;
        }
        .btn-export:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.3); color: white; }

        .btn-generate {
            background: var(--token-gradient); color: white; border: none;
            padding: 0.75rem 2rem; border-radius: 12px;
            font-weight: 600; font-size: 1rem;
            display: flex; align-items: center; gap: 0.5rem;
            transition: all 0.3s; width: 100%;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(249,115,22,0.3);
        }
        .btn-generate:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(249,115,22,0.4); color: white; }

        /* Footer Info */
        .admin-footer-info {
            background: white; border-radius: 16px; padding: 1rem 1.25rem;
            margin-top: 1.5rem; box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .info-grid { display: flex; flex-wrap: wrap; gap: 1rem; justify-content: center; }
        .info-item { text-align: center; min-width: 120px; }
        .info-item strong { display: block; font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem; }
        .info-item span { font-size: 0.85rem; color: #475569; }

        /* Wrapper */
        .page-wrapper { padding: 1rem; padding-bottom: 100px; }
        @media (min-width: 768px) { .page-wrapper { padding: 2rem; padding-bottom: 2rem; max-width: 1400px; margin: 0 auto; } }
        @media (min-width: 768px) { .exam-mobile-header, .exam-bottom-nav { display: none !important; } }
        @media (max-width: 767.98px) { .exam-desktop-nav { display: none !important; } }

        /* Mobile Header */
        .exam-mobile-header {
            background: var(--token-gradient); padding: 1rem;
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 100;
        }
        .exam-mobile-header .logo-section { display: flex; align-items: center; gap: 0.5rem; color: white; font-weight: 700; }
        .exam-mobile-header .logo-section img { height: 30px; width: auto; }
        .exam-mobile-header .header-actions { display: flex; gap: 0.5rem; }
        .exam-mobile-header .header-btn {
            background: rgba(255,255,255,0.15); border: none; color: white;
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
        }

        /* Desktop Nav */
        .exam-desktop-nav {
            background: white; border-bottom: 1px solid #e2e8f0;
            padding: 0.5rem 2rem; position: sticky; top: 0; z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        }
        .exam-desktop-nav .nav-container {
            display: flex; align-items: center; justify-content: space-between;
            max-width: 1600px; margin: 0 auto;
        }
        .exam-desktop-nav .nav-brand { display: flex; align-items: center; gap: 0.75rem; text-decoration: none; color: #1e293b; }
        .exam-desktop-nav .nav-brand img { height: 38px; }
        .exam-desktop-nav .nav-brand span { font-weight: 700; font-size: 1.1rem; }
        .exam-desktop-nav .nav-menu { display: flex; list-style: none; margin: 0; padding: 0; gap: 0.25rem; }
        .exam-desktop-nav .nav-link {
            text-decoration: none; color: #64748b; padding: 0.6rem 1rem;
            border-radius: 10px; font-weight: 500; font-size: 0.9rem;
            transition: all 0.2s; display: flex; align-items: center; gap: 0.4rem;
        }
        .exam-desktop-nav .nav-link:hover, .exam-desktop-nav .nav-link.active { background: #fff7ed; color: #c2410c; }
        .exam-desktop-nav .nav-right { display: flex; align-items: center; gap: 1rem; }
        .exam-desktop-nav .nav-user {
            display: flex; align-items: center; gap: 0.5rem; cursor: pointer;
            padding: 0.4rem 0.75rem; border-radius: 10px; transition: background 0.2s;
        }
        .exam-desktop-nav .nav-user:hover { background: #f8fafc; }
        .exam-desktop-nav .nav-user-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: var(--token-gradient);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 0.95rem;
        }
        .exam-desktop-nav .nav-user-name { font-weight: 500; color: #1e293b; font-size: 0.9rem; }
        .admin-dropdown { position: relative; }
        .admin-dropdown-menu {
            display: none; position: absolute; top: 100%; right: 0;
            background: white; border-radius: 12px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.15);
            min-width: 200px; padding: 0.5rem 0; z-index: 1000;
        }
        .admin-dropdown:hover .admin-dropdown-menu { display: block; }
        .admin-dropdown-menu a {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.6rem 1rem; text-decoration: none; color: #475569;
            font-size: 0.9rem; transition: background 0.2s;
        }
        .admin-dropdown-menu a:hover { background: #f8fafc; }
        .admin-dropdown-menu hr { margin: 0.25rem 0; border-color: #e2e8f0; }

        /* Mobile Bottom Nav */
        .exam-bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: white; display: flex; justify-content: space-around;
            padding: 0.5rem 0; box-shadow: 0 -2px 15px rgba(0,0,0,0.08);
            z-index: 1000; border-top: 1px solid #e2e8f0;
        }
        .exam-bottom-nav .nav-item {
            display: flex; flex-direction: column; align-items: center;
            text-decoration: none; color: #94a3b8; font-size: 0.65rem;
            font-weight: 500; padding: 0.25rem 0.5rem; border-radius: 8px;
            transition: all 0.2s;
        }
        .exam-bottom-nav .nav-item i { font-size: 1.25rem; margin-bottom: 0.15rem; }
        .exam-bottom-nav .nav-item.active { color: #ea580c; }

        /* Select All Bar */
        .select-all-bar {
            background: linear-gradient(135deg, #fff7ed, #ffedd5);
            border-radius: 10px; padding: 0.6rem 0.75rem;
            margin-bottom: 0.75rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .select-all-bar label { font-weight: 600; font-size: 0.85rem; color: #9a3412; }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <header class="exam-mobile-header">
        <div class="logo-section">
            <img src="../assets/img/Logo.png" alt="VLE Logo">
            <span>Exam Tokens</span>
        </div>
        <div class="header-actions">
            <button class="header-btn" onclick="location.href='dashboard.php'">
                <i class="bi bi-arrow-left"></i>
            </button>
            <button class="header-btn" onclick="location.href='../change_password.php'">
                <i class="bi bi-person-fill"></i>
            </button>
        </div>
    </header>

    <!-- Desktop Navigation -->
    <nav class="exam-desktop-nav">
        <div class="nav-container">
            <a href="dashboard.php" class="nav-brand">
                <img src="../assets/img/Logo.png" alt="VLE Logo">
                <span>VLE Exam Manager</span>
            </a>
            <ul class="nav-menu">
                <li><a href="dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                <li><a href="create_exam.php" class="nav-link"><i class="bi bi-plus-circle"></i> Create Exam</a></li>
                <li><a href="generate_tokens.php" class="nav-link active"><i class="bi bi-key"></i> Tokens</a></li>
                <li><a href="security_monitoring.php" class="nav-link"><i class="bi bi-shield-check"></i> Monitoring</a></li>
                <li><a href="semester_reports.php" class="nav-link"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a></li>
            </ul>
            <div class="nav-right">
                <div class="admin-dropdown">
                    <div class="nav-user">
                        <div class="nav-user-avatar"><?= strtoupper(substr($user['display_name'] ?? 'E', 0, 1)) ?></div>
                        <span class="nav-user-name"><?= htmlspecialchars($user['display_name'] ?? 'Manager') ?></span>
                        <i class="bi bi-chevron-down" style="font-size:0.7rem;color:#94a3b8;"></i>
                    </div>
                    <div class="admin-dropdown-menu">
                        <a href="../change_password.php"><i class="bi bi-key"></i> Change Password</a>
                        <hr>
                        <a href="../logout.php" class="text-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="page-wrapper">
        <!-- Page Header Card -->
        <div class="page-header-card">
            <div class="header-content">
                <div class="header-icon">
                    <i class="bi bi-key-fill"></i>
                </div>
                <div class="header-info">
                    <h1 class="header-title">Exam Tokens</h1>
                    <p class="header-subtitle">Generate and manage secure access tokens for examinations</p>
                </div>
            </div>
            <div class="header-stats">
                <div class="header-stat">
                    <i class="bi bi-key"></i>
                    <span><?= $tokenStats['total'] ?> total tokens</span>
                </div>
                <div class="header-stat">
                    <i class="bi bi-check-circle"></i>
                    <span><?= $tokenStats['active'] ?> active</span>
                </div>
                <div class="header-stat">
                    <i class="bi bi-calendar3"></i>
                    <span><?= date('l, F j, Y') ?></span>
                </div>
            </div>
        </div>

        <!-- Token Stats -->
        <div class="stats-grid mb-4">
            <div class="stat-card" style="--accent-color: #f97316;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f97316, #ea580c);"><i class="bi bi-key"></i></div>
                <div><span class="stat-value"><?= number_format($tokenStats['total']) ?></span><span class="stat-label">Total Tokens</span></div>
            </div>
            <div class="stat-card" style="--accent-color: #10b981;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="bi bi-check-circle"></i></div>
                <div><span class="stat-value"><?= number_format($tokenStats['active']) ?></span><span class="stat-label">Active</span></div>
            </div>
            <div class="stat-card" style="--accent-color: #3b82f6;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);"><i class="bi bi-check2-all"></i></div>
                <div><span class="stat-value"><?= number_format($tokenStats['used']) ?></span><span class="stat-label">Used</span></div>
            </div>
            <div class="stat-card" style="--accent-color: #ef4444;">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);"><i class="bi bi-clock-history"></i></div>
                <div><span class="stat-value"><?= number_format($tokenStats['expired']) ?></span><span class="stat-label">Expired</span></div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($success)): ?>
        <div class="alert-custom success mb-4">
            <div class="alert-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div><?php echo $success; ?></div>
        </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
        <div class="alert-custom error mb-4">
            <div class="alert-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div><?php echo $error; ?></div>
        </div>
        <?php endif; ?>

        <?php if (!$exam): ?>
        <!-- No Exam Selected - Show Exam Selector -->
        <div class="alert-custom warning mb-4">
            <div class="alert-icon"><i class="bi bi-info-circle-fill"></i></div>
            <div>Select an exam below to generate or manage tokens for students.</div>
        </div>

        <div class="section-header">
            <h5 class="section-title"><i class="bi bi-file-earmark-text me-2"></i>Select an Exam</h5>
        </div>
        <div class="form-section">
            <div class="form-section-body">
                <?php if (empty($allExams)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                        <p class="text-muted mt-2 mb-0">No exams found. <a href="create_exam.php" class="text-decoration-none">Create an exam</a> first.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($allExams as $e): ?>
                    <a href="generate_tokens.php?exam_id=<?= $e['exam_id'] ?>" class="exam-select-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="exam-title"><?= htmlspecialchars($e['exam_name']) ?></div>
                                <div class="exam-meta"><?= htmlspecialchars($e['exam_code']) ?> &bull; <?= htmlspecialchars($e['course_name'] ?? 'No Course') ?> &bull; <?= date('M d, Y', strtotime($e['start_time'])) ?></div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="token-badge"><i class="bi bi-key me-1"></i><?= $e['token_count'] ?> tokens</span>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <!-- Exam Selected - Show Token Management -->

        <!-- Exam Info Bar -->
        <div class="exam-info-bar">
            <div class="exam-info-item">
                <div>
                    <div class="info-label">Exam</div>
                    <div class="info-value"><?= htmlspecialchars($exam['exam_name']) ?></div>
                </div>
            </div>
            <div class="exam-info-item">
                <div>
                    <div class="info-label">Code</div>
                    <div class="info-value"><?= htmlspecialchars($exam['exam_code']) ?></div>
                </div>
            </div>
            <div class="exam-info-item">
                <div>
                    <div class="info-label">Course</div>
                    <div class="info-value"><?= htmlspecialchars($exam['course_name'] ?? 'N/A') ?></div>
                </div>
            </div>
            <div class="exam-info-item">
                <div>
                    <div class="info-label">Duration</div>
                    <div class="info-value"><?= $exam['duration_minutes'] ?> min</div>
                </div>
            </div>
            <div class="exam-info-item">
                <div>
                    <div class="info-label">Start</div>
                    <div class="info-value"><?= date('M d, H:i', strtotime($exam['start_time'])) ?></div>
                </div>
            </div>
            <div class="exam-info-item">
                <div>
                    <div class="info-label">Marks</div>
                    <div class="info-value"><?= $exam['total_marks'] ?></div>
                </div>
            </div>
            <div style="margin-left:auto;">
                <a href="generate_tokens.php" class="btn btn-sm btn-outline-secondary" style="border-radius:10px;">
                    <i class="bi bi-arrow-left me-1"></i>Change Exam
                </a>
            </div>
        </div>

        <div class="row g-4">
            <!-- Generate New Tokens -->
            <div class="col-lg-5">
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="section-icon" style="background: linear-gradient(135deg, #f97316, #ea580c);">
                            <i class="bi bi-plus-circle"></i>
                        </div>
                        <div>
                            <h5>Generate New Tokens</h5>
                            <p>Select students and set validity</p>
                        </div>
                    </div>
                    <div class="form-section-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Token Validity (hours)</label>
                                <input type="number" class="form-control" name="token_validity_hours" value="24" min="1" max="168" required>
                                <small class="text-muted">How long the token will remain valid</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Select Students</label>
                                <div class="border rounded-3 p-2 student-list">
                                    <?php if (empty($enrolledStudents)): ?>
                                        <div class="text-center py-3">
                                            <i class="bi bi-people" style="font-size: 1.5rem; color: #cbd5e1;"></i>
                                            <p class="text-muted small mb-0 mt-1">No students enrolled in this course</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="select-all-bar">
                                            <input class="form-check-input" type="checkbox" id="selectAll">
                                            <label for="selectAll">Select All Students</label>
                                        </div>
                                        <?php foreach ($enrolledStudents as $student): ?>
                                        <label class="student-item <?= $student['has_token'] ? 'has-token' : '' ?>">
                                            <input class="form-check-input student-check" type="checkbox"
                                                   name="students[]" value="<?= $student['student_id'] ?>"
                                                   <?= $student['has_token'] ? 'disabled' : '' ?>>
                                            <div class="student-avatar"><?= strtoupper(substr($student['full_name'], 0, 1)) ?></div>
                                            <div class="student-info">
                                                <div class="student-name">
                                                    <?= htmlspecialchars($student['full_name']) ?>
                                                    <?php if ($student['has_token']): ?>
                                                        <span class="token-status used" style="font-size:0.65rem;"><i class="bi bi-check"></i> Has Token</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="student-id-text"><?= htmlspecialchars($student['student_id']) ?></div>
                                            </div>
                                        </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <button type="submit" name="generate_tokens" class="btn-generate">
                                <i class="bi bi-key"></i> Generate Tokens
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Existing Tokens -->
            <div class="col-lg-7">
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="section-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                            <i class="bi bi-list-check"></i>
                        </div>
                        <div style="flex:1;">
                            <h5>Existing Tokens (<?= count($existingTokens) ?>)</h5>
                            <p>Generated tokens for this exam</p>
                        </div>
                        <?php if (!empty($existingTokens)): ?>
                        <button class="btn-export" onclick="exportTokens()">
                            <i class="bi bi-download"></i> Export CSV
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="form-section-body" style="max-height: 550px; overflow-y: auto;">
                        <?php if (empty($existingTokens)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-key" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                                <p class="text-muted mt-2 mb-0">No tokens generated yet for this exam.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($existingTokens as $token): ?>
                            <div class="token-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold" style="font-size:0.9rem; color:#1e293b;">
                                            <?= htmlspecialchars($token['full_name']) ?>
                                        </div>
                                        <div class="small text-muted"><?= htmlspecialchars($token['student_id']) ?></div>
                                    </div>
                                    <div class="text-end">
                                        <div class="token-code"><?= $token['token'] ?></div>
                                        <div class="small text-muted mt-1">
                                            <i class="bi bi-clock me-1"></i>Expires: <?= date('M d, H:i', strtotime($token['expires_at'])) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-2 d-flex align-items-center gap-2">
                                    <?php if ($token['is_used']): ?>
                                        <span class="token-status used"><i class="bi bi-check-circle-fill"></i> Used</span>
                                    <?php elseif (strtotime($token['expires_at']) < time()): ?>
                                        <span class="token-status expired"><i class="bi bi-x-circle-fill"></i> Expired</span>
                                    <?php else: ?>
                                        <span class="token-status active"><i class="bi bi-check-circle"></i> Active</span>
                                    <?php endif; ?>
                                    <span class="small text-muted">
                                        Created: <?= date('M d, H:i', strtotime($token['created_at'])) ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer Info -->
        <div class="admin-footer-info">
            <div class="info-grid">
                <div class="info-item">
                    <strong>System</strong>
                    <span>VLE Examination</span>
                </div>
                <div class="info-item">
                    <strong>Module</strong>
                    <span>Token Management</span>
                </div>
                <div class="info-item">
                    <strong>User</strong>
                    <span><?= htmlspecialchars($user['display_name'] ?? 'Manager') ?></span>
                </div>
                <div class="info-item">
                    <strong>Role</strong>
                    <span><i class="bi bi-shield-check me-1"></i>Examination Manager</span>
                </div>
            </div>
        </div>

        <?php
        $current_role_context = 'examination_manager';
        include '../includes/role_cards.php';
        ?>
    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="exam-bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="bi bi-speedometer2"></i>
            <span>Home</span>
        </a>
        <a href="create_exam.php" class="nav-item">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Create</span>
        </a>
        <a href="security_monitoring.php" class="nav-item">
            <i class="bi bi-shield-check-fill"></i>
            <span>Monitor</span>
        </a>
        <a href="generate_tokens.php" class="nav-item active">
            <i class="bi bi-key-fill"></i>
            <span>Tokens</span>
        </a>
        <a href="semester_reports.php" class="nav-item">
            <i class="bi bi-file-earmark-bar-graph-fill"></i>
            <span>Reports</span>
        </a>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/session-timeout.js"></script>
    <script>
        // Select all students checkbox
        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.student-check:not([disabled])');
                checkboxes.forEach(cb => cb.checked = this.checked);
            });
        }

        // Export tokens function
        function exportTokens() {
            const tokens = <?= json_encode($existingTokens ?? []) ?>;
            if (tokens.length === 0) {
                alert('No tokens to export');
                return;
            }

            let csv = 'Student ID,Student Name,Token,Expires At,Status\n';
            tokens.forEach(token => {
                const status = token.is_used == 1 ? 'Used' :
                              (new Date(token.expires_at) < new Date() ? 'Expired' : 'Active');
                csv += `"${token.student_id}","${token.full_name}","${token.token}","${token.expires_at}","${status}"\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'exam_tokens_<?= $exam ? $exam['exam_code'] : 'all' ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        // Bottom nav active state
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = window.location.pathname.split('/').pop().split('?')[0];
            document.querySelectorAll('.exam-bottom-nav .nav-item').forEach(item => {
                const href = item.getAttribute('href').split('?')[0];
                if (href === currentPage) item.classList.add('active');
                else if (currentPage !== '' && href !== currentPage) item.classList.remove('active');
            });
        });
    </script>
    <?php include_once __DIR__ . '/../includes/pwa-footer.php'; ?>
</body>
</html>