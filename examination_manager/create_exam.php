<?php
// examination_manager/create_exam.php - Create New Exam
require_once '../includes/auth.php';
requireLogin();
requireRole(['examination_manager', 'examination_officer']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get examination manager ID
$managerResult = $conn->query("SELECT manager_id FROM examination_managers WHERE email = '{$user['email']}'");
$managerId = $managerResult ? $managerResult->fetch_assoc()['manager_id'] : null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_exam'])) {
    $examData = [
        'exam_code' => strtoupper(trim($_POST['exam_code'])),
        'exam_name' => trim($_POST['exam_name']),
        'course_id' => (int)$_POST['course_id'],
        'lecturer_id' => (int)$_POST['lecturer_id'],
        'exam_type' => $_POST['exam_type'],
        'description' => trim($_POST['description']),
        'total_questions' => (int)$_POST['total_questions'],
        'total_marks' => (int)$_POST['total_marks'],
        'passing_marks' => (int)$_POST['passing_marks'],
        'duration_minutes' => (int)$_POST['duration_minutes'],
        'start_time' => $_POST['start_time'],
        'end_time' => $_POST['end_time'],
        'instructions' => trim($_POST['instructions']),
        'exam_manager_id' => $managerId
    ];

    // Validate required fields
    $errors = [];
    if (empty($examData['exam_code'])) $errors[] = "Exam code is required";
    if (empty($examData['exam_name'])) $errors[] = "Exam name is required";
    if ($examData['course_id'] <= 0) $errors[] = "Please select a course";
    if ($examData['lecturer_id'] <= 0) $errors[] = "Please select a lecturer";
    if ($examData['duration_minutes'] <= 0) $errors[] = "Duration must be greater than 0";
    if (strtotime($examData['start_time']) >= strtotime($examData['end_time'])) {
        $errors[] = "End time must be after start time";
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            // Insert exam
            $stmt = $conn->prepare("INSERT INTO exams (exam_code, exam_name, course_id, lecturer_id, exam_manager_id, exam_type, description, total_questions, total_marks, passing_marks, duration_minutes, start_time, end_time, instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiisssiiiisss",
                $examData['exam_code'],
                $examData['exam_name'],
                $examData['course_id'],
                $examData['lecturer_id'],
                $examData['exam_manager_id'],
                $examData['exam_type'],
                $examData['description'],
                $examData['total_questions'],
                $examData['total_marks'],
                $examData['passing_marks'],
                $examData['duration_minutes'],
                $examData['start_time'],
                $examData['end_time'],
                $examData['instructions']
            );

            if ($stmt->execute()) {
                $examId = $conn->insert_id;

                // Handle questions if provided
                if (isset($_POST['questions']) && is_array($_POST['questions'])) {
                    foreach ($_POST['questions'] as $qIndex => $question) {
                        if (!empty($question['text'])) {
                            $questionType = $question['type'];
                            $options = null;

                            if ($questionType === 'multiple_choice' && isset($question['options'])) {
                                $options = json_encode(array_filter($question['options']));
                            }

                            $stmt = $conn->prepare("INSERT INTO exam_questions (exam_id, question_number, question_text, question_type, options, correct_answer, marks) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $marks = (int)($question['marks'] ?? 1);
                            $stmt->bind_param("iissssi", $examId, $qIndex, $question['text'], $questionType, $options, $question['correct_answer'], $marks);
                            $stmt->execute();
                        }
                    }
                }

                $conn->commit();
                $success = "Exam created successfully! <a href='generate_tokens.php?exam_id=$examId' class='btn btn-sm btn-success ms-2'>Generate Tokens</a>";
            } else {
                throw new Exception("Failed to create exam");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to create exam: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get courses and lecturers for dropdowns
$courses = $conn->query("SELECT course_id, course_code, course_name FROM vle_courses WHERE is_active = 1 ORDER BY course_name");
$lecturers = $conn->query("SELECT lecturer_id, full_name, department FROM lecturers WHERE is_active = 1 ORDER BY full_name");

// Stats for header card
$totalExams = 0;
$result = $conn->query("SELECT COUNT(*) as total FROM exams WHERE is_active = 1");
if ($result) $totalExams = $result->fetch_assoc()['total'];

$totalCourses = 0;
$result = $conn->query("SELECT COUNT(*) as total FROM vle_courses WHERE is_active = 1");
if ($result) $totalCourses = $result->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Create New Exam - VLE Examination Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/admin-dashboard.css" rel="stylesheet">
    <?php include_once __DIR__ . '/../includes/pwa-head.php'; ?>
    <style>
        :root {
            --create-gradient: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            --card-hover-transform: translateY(-4px);
        }
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; margin: 0; }

        /* Page Header Card */
        .page-header-card {
            background: var(--create-gradient);
            border-radius: 24px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
            box-shadow: 0 15px 50px rgba(59, 130, 246, 0.35);
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
            display: flex; gap: 1.5rem; margin-top: 1rem;
            flex-wrap: wrap;
        }
        .page-header-card .header-stat {
            display: flex; align-items: center; gap: 0.5rem;
            opacity: 0.85; font-size: 0.9rem;
        }
        .page-header-card .header-stat i { font-size: 1.1rem; }

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
        .form-label .required { color: #ef4444; }
        .form-control, .form-select {
            border-radius: 10px; border: 1.5px solid #e2e8f0;
            padding: 0.6rem 0.85rem; font-size: 0.9rem;
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }
        .form-control::placeholder { color: #94a3b8; }

        /* Question Cards */
        .question-card {
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            background: #fafbfc;
            transition: all 0.2s;
        }
        .question-card:hover { border-color: #3b82f6; box-shadow: 0 4px 12px rgba(59,130,246,0.1); }
        .question-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 0.75rem;
        }
        .question-number {
            font-weight: 600; color: #1e293b; font-size: 0.9rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .question-number .q-badge {
            background: var(--create-gradient); color: white; font-size: 0.7rem;
            padding: 0.2rem 0.5rem; border-radius: 6px; font-weight: 600;
        }
        .option-input { margin-bottom: 0.5rem; }

        /* Submit Area */
        .submit-area {
            background: white; border-radius: 16px; padding: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            display: flex; justify-content: center; gap: 1rem;
            flex-wrap: wrap;
        }
        .btn-create-exam {
            background: var(--create-gradient); color: white; border: none;
            padding: 0.75rem 2.5rem; border-radius: 12px;
            font-weight: 600; font-size: 1rem;
            display: flex; align-items: center; gap: 0.5rem;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(59,130,246,0.3);
        }
        .btn-create-exam:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59,130,246,0.4);
            color: white;
        }
        .btn-back {
            background: #f1f5f9; color: #475569; border: none;
            padding: 0.75rem 2rem; border-radius: 12px;
            font-weight: 500; font-size: 0.95rem;
            display: flex; align-items: center; gap: 0.5rem;
            transition: all 0.2s;
        }
        .btn-back:hover { background: #e2e8f0; color: #1e293b; }

        /* Alert Styles */
        .alert-custom {
            border: none; border-radius: 14px; padding: 1rem 1.25rem;
            display: flex; align-items: center; gap: 0.75rem;
            font-size: 0.9rem;
        }
        .alert-custom.success {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            color: #065f46;
        }
        .alert-custom.error {
            background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%);
            color: #991b1b;
        }
        .alert-custom .alert-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; color: white; flex-shrink: 0;
        }
        .alert-custom.success .alert-icon { background: #10b981; }
        .alert-custom.error .alert-icon { background: #ef4444; }

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
            background: var(--create-gradient); padding: 1rem;
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
        .exam-desktop-nav .nav-link:hover, .exam-desktop-nav .nav-link.active { background: #dbeafe; color: #1d4ed8; }
        .exam-desktop-nav .nav-right { display: flex; align-items: center; gap: 1rem; }
        .exam-desktop-nav .nav-user {
            display: flex; align-items: center; gap: 0.5rem; cursor: pointer;
            padding: 0.4rem 0.75rem; border-radius: 10px; transition: background 0.2s;
        }
        .exam-desktop-nav .nav-user:hover { background: #f8fafc; }
        .exam-desktop-nav .nav-user-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: var(--create-gradient);
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
        .exam-bottom-nav .nav-item.active { color: #2563eb; }

        /* Add Question Button */
        .btn-add-question {
            background: var(--create-gradient); color: white; border: none;
            padding: 0.5rem 1rem; border-radius: 10px;
            font-weight: 500; font-size: 0.85rem;
            display: flex; align-items: center; gap: 0.35rem;
            transition: all 0.2s;
        }
        .btn-add-question:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); color: white; }

        /* Empty Questions Placeholder */
        .questions-empty {
            text-align: center; padding: 2rem 1rem;
            color: #94a3b8;
        }
        .questions-empty i { font-size: 2.5rem; margin-bottom: 0.5rem; display: block; opacity: 0.5; }
        .questions-empty p { font-size: 0.85rem; margin: 0; }

        /* Responsive form tweaks */
        @media (max-width: 767.98px) {
            .form-row-reverse { flex-direction: column-reverse !important; }
        }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <header class="exam-mobile-header">
        <div class="logo-section">
            <img src="../assets/img/Logo.png" alt="VLE Logo">
            <span>Create Exam</span>
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
                <li><a href="create_exam.php" class="nav-link active"><i class="bi bi-plus-circle"></i> Create Exam</a></li>
                <li><a href="generate_tokens.php" class="nav-link"><i class="bi bi-key"></i> Tokens</a></li>
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
                    <i class="bi bi-plus-circle-fill"></i>
                </div>
                <div class="header-info">
                    <h1 class="header-title">Create New Exam</h1>
                    <p class="header-subtitle">Set up a new examination with questions and settings</p>
                </div>
            </div>
            <div class="header-stats">
                <div class="header-stat">
                    <i class="bi bi-file-earmark-text"></i>
                    <span><?= $totalExams ?> exams in system</span>
                </div>
                <div class="header-stat">
                    <i class="bi bi-book"></i>
                    <span><?= $totalCourses ?> active courses</span>
                </div>
                <div class="header-stat">
                    <i class="bi bi-calendar3"></i>
                    <span><?= date('l, F j, Y') ?></span>
                </div>
            </div>
        </div>

        <!-- Success/Error Alerts -->
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

        <!-- Exam Form -->
        <form method="POST" id="examForm">
            <div class="row g-4 form-row-reverse">
                <!-- Left Column - Basic Info & Settings -->
                <div class="col-lg-8">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <div class="section-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                                <i class="bi bi-info-circle"></i>
                            </div>
                            <div>
                                <h5>Basic Information</h5>
                                <p>Enter the exam details and assign it to a course</p>
                            </div>
                        </div>
                        <div class="form-section-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Exam Code <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="exam_code" required
                                           placeholder="e.g., CS101-MID-2024">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Exam Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="exam_name" required
                                           placeholder="e.g., Computer Science Mid-Semester Exam">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Course <span class="required">*</span></label>
                                    <select class="form-select" name="course_id" required>
                                        <option value="">Select Course</option>
                                        <?php while ($course = $courses->fetch_assoc()): ?>
                                            <option value="<?php echo $course['course_id']; ?>">
                                                <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Lecturer <span class="required">*</span></label>
                                    <select class="form-select" name="lecturer_id" required>
                                        <option value="">Select Lecturer</option>
                                        <?php
                                        $lecturers->data_seek(0);
                                        while ($lecturer = $lecturers->fetch_assoc()):
                                        ?>
                                            <option value="<?php echo $lecturer['lecturer_id']; ?>">
                                                <?php echo htmlspecialchars($lecturer['full_name'] . ' (' . $lecturer['department'] . ')'); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Exam Type</label>
                                    <select class="form-select" name="exam_type">
                                        <option value="mid_term">Mid-Semester Exam</option>
                                        <option value="final">End-Semester Examination</option>
                                        <option value="quiz">Quiz</option>
                                        <option value="assignment">Assignment</option>
                                    </select>
                                </div>
                                <div class="col-md-6"></div>
                                <div class="col-12">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" name="description" rows="3"
                                              placeholder="Brief description of the exam..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Exam Settings -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <div class="section-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                                <i class="bi bi-gear"></i>
                            </div>
                            <div>
                                <h5>Exam Settings</h5>
                                <p>Configure duration, marks, and scheduling</p>
                            </div>
                        </div>
                        <div class="form-section-body">
                            <div class="row g-3">
                                <div class="col-6 col-md-3">
                                    <label class="form-label">Total Questions</label>
                                    <input type="number" class="form-control" name="total_questions" min="1" value="10">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label">Total Marks</label>
                                    <input type="number" class="form-control" name="total_marks" min="1" value="100">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label">Passing Marks</label>
                                    <input type="number" class="form-control" name="passing_marks" min="1" value="50">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label">Duration (min) <span class="required">*</span></label>
                                    <input type="number" class="form-control" name="duration_minutes" min="1" required value="120">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Start Time <span class="required">*</span></label>
                                    <input type="datetime-local" class="form-control" name="start_time" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">End Time <span class="required">*</span></label>
                                    <input type="datetime-local" class="form-control" name="end_time" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Exam Instructions</label>
                                    <textarea class="form-control" name="instructions" rows="4"
                                              placeholder="Instructions for students taking this exam..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Questions -->
                <div class="col-lg-4">
                    <div class="form-section" style="position: sticky; top: 80px;">
                        <div class="form-section-header">
                            <div class="section-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                                <i class="bi bi-question-circle"></i>
                            </div>
                            <div style="flex:1;">
                                <h5>Questions</h5>
                                <p>Add exam questions</p>
                            </div>
                            <button type="button" class="btn-add-question" id="addQuestionBtn">
                                <i class="bi bi-plus"></i> Add
                            </button>
                        </div>
                        <div class="form-section-body" style="max-height: 600px; overflow-y: auto;">
                            <div id="questionsContainer">
                                <!-- Questions will be added here dynamically -->
                            </div>
                            <div class="questions-empty" id="questionsEmpty" style="display:none;">
                                <i class="bi bi-patch-question"></i>
                                <p>No questions added yet.<br>Click <strong>Add</strong> to get started.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Area -->
            <div class="submit-area mt-4">
                <a href="dashboard.php" class="btn-back">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
                <button type="submit" name="create_exam" class="btn-create-exam">
                    <i class="bi bi-check-circle"></i> Create Exam
                </button>
            </div>
        </form>

        <!-- Footer Info -->
        <div class="admin-footer-info">
            <div class="info-grid">
                <div class="info-item">
                    <strong>System</strong>
                    <span>VLE Examination</span>
                </div>
                <div class="info-item">
                    <strong>Module</strong>
                    <span>Create Exam</span>
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
        <a href="create_exam.php" class="nav-item active">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Create</span>
        </a>
        <a href="security_monitoring.php" class="nav-item">
            <i class="bi bi-shield-check-fill"></i>
            <span>Monitor</span>
        </a>
        <a href="generate_tokens.php" class="nav-item">
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
        let questionCount = 0;

        document.getElementById('addQuestionBtn').addEventListener('click', function() {
            addQuestion();
        });

        function updateEmptyState() {
            const container = document.getElementById('questionsContainer');
            const emptyState = document.getElementById('questionsEmpty');
            if (container.children.length === 0) {
                emptyState.style.display = 'block';
            } else {
                emptyState.style.display = 'none';
            }
        }

        function addQuestion() {
            questionCount++;
            const container = document.getElementById('questionsContainer');

            const questionCard = document.createElement('div');
            questionCard.className = 'question-card';
            questionCard.innerHTML = `
                <div class="question-header">
                    <div class="question-number">
                        <span class="q-badge">Q${questionCount}</span>
                        Question ${questionCount}
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQuestion(this)" style="border-radius:8px;">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="mb-3">
                    <textarea class="form-control" name="questions[${questionCount}][text]" rows="3"
                              placeholder="Enter question text..." required></textarea>
                </div>
                <div class="row g-2">
                    <div class="col-12">
                        <select class="form-select" name="questions[${questionCount}][type]"
                                onchange="changeQuestionType(this, ${questionCount})" required>
                            <option value="multiple_choice">Multiple Choice</option>
                            <option value="true_false">True/False</option>
                            <option value="short_answer">Short Answer</option>
                            <option value="essay">Essay</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <input type="number" class="form-control" name="questions[${questionCount}][marks]"
                               placeholder="Marks" min="1" value="1" required>
                    </div>
                    <div class="col-6">
                        <input type="text" class="form-control" name="questions[${questionCount}][correct_answer]"
                               placeholder="Correct answer" required>
                    </div>
                </div>
                <div class="options-container mt-2" id="options-${questionCount}">
                </div>
            `;

            container.appendChild(questionCard);
            changeQuestionType(questionCard.querySelector('select'), questionCount);
            updateEmptyState();
        }

        function changeQuestionType(select, questionNum) {
            const type = select.value;
            const optionsContainer = document.getElementById(`options-${questionNum}`);
            const correctAnswerInput = select.closest('.question-card').querySelector('input[name*="[correct_answer]"]');

            optionsContainer.innerHTML = '';

            if (type === 'multiple_choice') {
                correctAnswerInput.placeholder = 'Correct option (A, B, C, D)';
                addMultipleChoiceOptions(optionsContainer, questionNum);
            } else if (type === 'true_false') {
                correctAnswerInput.placeholder = 'Correct answer (True/False)';
            } else {
                correctAnswerInput.placeholder = 'Correct answer';
            }
        }

        function addMultipleChoiceOptions(container, questionNum) {
            const options = ['A', 'B', 'C', 'D'];
            options.forEach(option => {
                const optionDiv = document.createElement('div');
                optionDiv.className = 'option-input';
                optionDiv.innerHTML = `
                    <input type="text" class="form-control" name="questions[${questionNum}][options][${option}]"
                           placeholder="Option ${option}" required style="font-size:0.85rem;">
                `;
                container.appendChild(optionDiv);
            });
        }

        function removeQuestion(button) {
            button.closest('.question-card').remove();
            questionCount--;
            updateQuestionNumbers();
            updateEmptyState();
        }

        function updateQuestionNumbers() {
            const cards = document.querySelectorAll('.question-card');
            cards.forEach((card, index) => {
                const num = index + 1;
                card.querySelector('.question-number').innerHTML = `<span class="q-badge">Q${num}</span> Question ${num}`;
            });
        }

        // Add first question by default
        addQuestion();

        // Bottom nav active state
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.exam-bottom-nav .nav-item').forEach(item => {
                if (item.getAttribute('href') === currentPage) item.classList.add('active');
                else if (currentPage !== '' && item.getAttribute('href') !== currentPage) item.classList.remove('active');
            });
        });
    </script>
    <?php include_once __DIR__ . '/../includes/pwa-footer.php'; ?>
</body>
</html>