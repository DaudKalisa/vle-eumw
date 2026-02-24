<?php
/**
 * Setup Sample Examinations & Examination Officer
 * Creates a sample exam officer account and several sample exams with questions
 * that students can see and take.
 * 
 * Run via browser: http://localhost/vle-eumw/setup_sample_exams.php
 */
require_once 'includes/config.php';
$conn = getDbConnection();

$output = [];
$errors = [];

function out($msg, $type = 'info') {
    global $output;
    $output[] = ['msg' => $msg, 'type' => $type];
}

// ============================================================
// 1. CREATE SAMPLE EXAMINATION OFFICER
// ============================================================
out("=== Setting Up Sample Examination Officer ===", 'header');

$officer_email = 'sarah.examofficer@university.edu';
$officer_username = 'sarah_exam';
$officer_password = 'ExamOfficer@2026';
$officer_fullname = 'Sarah Banda';

// Check if officer already exists
$check = $conn->prepare("SELECT manager_id FROM examination_managers WHERE email = ?");
$check->bind_param("s", $officer_email);
$check->execute();
$existing = $check->get_result()->fetch_assoc();

$officer_manager_id = null;
$officer_user_id = null;

if ($existing) {
    $officer_manager_id = $existing['manager_id'];
    out("Examination officer '$officer_fullname' already exists (manager_id=$officer_manager_id)", 'warning');
    
    // Get related user_id
    $u = $conn->prepare("SELECT user_id FROM users WHERE related_staff_id = ? AND email = ?");
    $u->bind_param("is", $officer_manager_id, $officer_email);
    $u->execute();
    $ur = $u->get_result()->fetch_assoc();
    $officer_user_id = $ur ? $ur['user_id'] : null;
} else {
    $conn->begin_transaction();
    try {
        // Insert examination_managers record
        $stmt = $conn->prepare("INSERT INTO examination_managers (full_name, email, phone, department, position, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        $phone = '+265 999 123 456';
        $dept = 'Academic Affairs';
        $pos = 'Senior Examination Officer';
        $stmt->bind_param("sssss", $officer_fullname, $officer_email, $phone, $dept, $pos);
        $stmt->execute();
        $officer_manager_id = $conn->insert_id;

        // Create user account
        $hashed = password_hash($officer_password, PASSWORD_DEFAULT);
        $role = 'staff';
        $stmt2 = $conn->prepare("INSERT INTO users (username, email, password_hash, role, related_staff_id) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param("ssssi", $officer_username, $officer_email, $hashed, $role, $officer_manager_id);
        $stmt2->execute();
        $officer_user_id = $conn->insert_id;

        $conn->commit();
        out("Created examination officer: $officer_fullname ($officer_email)", 'success');
        out("Username: $officer_username | Password: $officer_password", 'success');
        out("Manager ID: $officer_manager_id | User ID: $officer_user_id", 'info');
    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = "Failed to create exam officer: " . $e->getMessage();
        out("Failed to create exam officer: " . $e->getMessage(), 'error');
    }
}

if (!$officer_user_id) {
    // Fallback: use existing exam_manager user_id
    $fallback = $conn->query("SELECT user_id FROM users WHERE username = 'exam_manager' LIMIT 1");
    if ($fallback && $row = $fallback->fetch_assoc()) {
        $officer_user_id = $row['user_id'];
        out("Using fallback officer user_id=$officer_user_id", 'warning');
    }
}

// ============================================================
// 2. CREATE SAMPLE EXAMS WITH QUESTIONS
// ============================================================
out("", 'info');
out("=== Setting Up Sample Examinations ===", 'header');

// The student BAC/26/BT/CE/0001 is enrolled in courses: 9 (Computer Apps), 11 (Business Math II), 12 (Business Stats), 14 (Principles of Mgmt)
$sample_exams = [
    [
        'exam_code' => 'BAC2204-MID-26',
        'exam_name' => 'Computer Applications - Mid-Semester Exam',
        'exam_type' => 'mid_term',
        'course_id' => 9,
        'description' => 'Mid-semester examination covering computer fundamentals, operating systems, word processing, and spreadsheet applications.',
        'instructions' => "1. Read each question carefully before answering.\n2. You have 45 minutes to complete this exam.\n3. All multiple choice questions carry equal marks.\n4. Do not switch tabs or windows during the exam.\n5. Ensure your webcam is enabled for invigilation.",
        'duration_minutes' => 45,
        'total_marks' => 50,
        'passing_marks' => 25,
        'max_attempts' => 2,
        'shuffle_questions' => 1,
        'shuffle_options' => 1,
        'show_results' => 1,
        'allow_review' => 1,
        'require_camera' => 0,
        'require_token' => 0,
        'start_offset' => '-1 day',   // Already started (available now)
        'end_offset' => '+30 days',   // Ends in 30 days
        'questions' => [
            [
                'text' => 'Which of the following is an example of system software?',
                'type' => 'multiple_choice',
                'options' => ['Microsoft Word', 'Windows 11', 'Google Chrome', 'Adobe Photoshop'],
                'correct' => 'Windows 11',
                'marks' => 5,
                'explanation' => 'Windows 11 is an operating system, which is a type of system software. The others are application software.'
            ],
            [
                'text' => 'What does CPU stand for?',
                'type' => 'multiple_choice',
                'options' => ['Central Processing Unit', 'Computer Personal Unit', 'Central Program Utility', 'Computer Processing Unit'],
                'correct' => 'Central Processing Unit',
                'marks' => 5,
                'explanation' => 'CPU stands for Central Processing Unit, often called the brain of the computer.'
            ],
            [
                'text' => 'RAM is a type of volatile memory.',
                'type' => 'true_false',
                'options' => ['True', 'False'],
                'correct' => 'True',
                'marks' => 5,
                'explanation' => 'RAM (Random Access Memory) is volatile, meaning it loses its contents when power is turned off.'
            ],
            [
                'text' => 'Which keyboard shortcut is used to copy selected text?',
                'type' => 'multiple_choice',
                'options' => ['Ctrl+V', 'Ctrl+C', 'Ctrl+X', 'Ctrl+Z'],
                'correct' => 'Ctrl+C',
                'marks' => 5,
                'explanation' => 'Ctrl+C copies, Ctrl+V pastes, Ctrl+X cuts, and Ctrl+Z undoes.'
            ],
            [
                'text' => 'What is the primary function of a spreadsheet application?',
                'type' => 'multiple_choice',
                'options' => ['Creating presentations', 'Managing databases', 'Performing calculations and data analysis', 'Editing photographs'],
                'correct' => 'Performing calculations and data analysis',
                'marks' => 5,
                'explanation' => 'Spreadsheet applications like Microsoft Excel are primarily used for calculations, data analysis, and creating charts.'
            ],
            [
                'text' => 'In Microsoft Excel, which symbol must precede every formula?',
                'type' => 'multiple_choice',
                'options' => ['# (hash)', '= (equals)', '@ (at)', '$ (dollar)'],
                'correct' => '= (equals)',
                'marks' => 5,
                'explanation' => 'Every formula in Excel must begin with the equals sign (=).'
            ],
            [
                'text' => 'A firewall protects a computer from unauthorized network access.',
                'type' => 'true_false',
                'options' => ['True', 'False'],
                'correct' => 'True',
                'marks' => 5,
                'explanation' => 'A firewall monitors and controls incoming and outgoing network traffic to protect against unauthorized access.'
            ],
            [
                'text' => 'Explain the difference between hardware and software, giving two examples of each.',
                'type' => 'short_answer',
                'options' => [],
                'correct' => '',
                'marks' => 10,
                'explanation' => 'Hardware refers to physical components (e.g., keyboard, monitor). Software refers to programs and instructions (e.g., Windows OS, MS Word).'
            ],
            [
                'text' => 'Which of the following is NOT an input device?',
                'type' => 'multiple_choice',
                'options' => ['Mouse', 'Keyboard', 'Monitor', 'Scanner'],
                'correct' => 'Monitor',
                'marks' => 5,
                'explanation' => 'A monitor is an output device that displays information. Mouse, keyboard, and scanner are input devices.'
            ],
        ]
    ],
    [
        'exam_code' => 'BBA1202-FNL-26',
        'exam_name' => 'Business Mathematics II - End-Semester Examination',
        'exam_type' => 'final',
        'course_id' => 11,
        'description' => 'End-semester examination on Business Mathematics covering matrices, linear programming, calculus, and financial mathematics.',
        'instructions' => "1. This is an end-semester examination worth 100 marks.\n2. Answer ALL questions.\n3. Show your working where applicable.\n4. Time allowed: 90 minutes.\n5. No calculators are permitted for the MCQ section.",
        'duration_minutes' => 90,
        'total_marks' => 100,
        'passing_marks' => 50,
        'max_attempts' => 1,
        'shuffle_questions' => 0,
        'shuffle_options' => 1,
        'show_results' => 1,
        'allow_review' => 0,
        'require_camera' => 1,
        'require_token' => 0,
        'start_offset' => '-2 hours',
        'end_offset' => '+14 days',
        'questions' => [
            [
                'text' => 'What is the derivative of f(x) = 3x² + 2x - 5?',
                'type' => 'multiple_choice',
                'options' => ['6x + 2', '3x + 2', '6x² + 2', '6x - 5'],
                'correct' => '6x + 2',
                'marks' => 10,
                'explanation' => 'Using the power rule: d/dx(3x²) = 6x, d/dx(2x) = 2, d/dx(-5) = 0. So f\'(x) = 6x + 2.'
            ],
            [
                'text' => 'If A is a 2×3 matrix and B is a 3×4 matrix, the product AB is a 2×4 matrix.',
                'type' => 'true_false',
                'options' => ['True', 'False'],
                'correct' => 'True',
                'marks' => 10,
                'explanation' => 'When multiplying matrices, the result has rows from the first matrix and columns from the second: (2×3)(3×4) = 2×4.'
            ],
            [
                'text' => 'Calculate the simple interest on MWK 500,000 at 12% per annum for 3 years.',
                'type' => 'multiple_choice',
                'options' => ['MWK 150,000', 'MWK 180,000', 'MWK 200,000', 'MWK 160,000'],
                'correct' => 'MWK 180,000',
                'marks' => 10,
                'explanation' => 'Simple Interest = P × R × T = 500,000 × 0.12 × 3 = MWK 180,000.'
            ],
            [
                'text' => 'What is the integral of 4x³ dx?',
                'type' => 'multiple_choice',
                'options' => ['x⁴ + C', '12x² + C', '4x⁴ + C', 'x⁴/4 + C'],
                'correct' => 'x⁴ + C',
                'marks' => 10,
                'explanation' => '∫4x³ dx = 4 × (x⁴/4) + C = x⁴ + C.'
            ],
            [
                'text' => 'In linear programming, the feasible region represents the set of all possible solutions that satisfy all constraints.',
                'type' => 'true_false',
                'options' => ['True', 'False'],
                'correct' => 'True',
                'marks' => 10,
                'explanation' => 'The feasible region is the area where all constraints overlap, containing all valid solutions.'
            ],
            [
                'text' => 'Solve: If 2x + 5 = 17, what is the value of x?',
                'type' => 'multiple_choice',
                'options' => ['5', '6', '7', '8'],
                'correct' => '6',
                'marks' => 10,
                'explanation' => '2x + 5 = 17 → 2x = 12 → x = 6.'
            ],
            [
                'text' => 'A company invests MWK 1,000,000 at a compound interest rate of 10% per annum. Calculate the total amount after 2 years and explain the concept of compound interest.',
                'type' => 'essay',
                'options' => [],
                'correct' => '',
                'marks' => 20,
                'explanation' => 'Amount = P(1+r)^n = 1,000,000(1.10)² = MWK 1,210,000. Compound interest is interest earned on both the principal and accumulated interest.'
            ],
            [
                'text' => 'The determinant of a 2×2 matrix [[a,b],[c,d]] is calculated as:',
                'type' => 'multiple_choice',
                'options' => ['ad + bc', 'ad - bc', 'ac - bd', 'ab - cd'],
                'correct' => 'ad - bc',
                'marks' => 10,
                'explanation' => 'For a 2×2 matrix [[a,b],[c,d]], the determinant = ad - bc.'
            ],
            [
                'text' => 'Define the break-even point in business and state its formula.',
                'type' => 'short_answer',
                'options' => [],
                'correct' => '',
                'marks' => 10,
                'explanation' => 'Break-even point is where total revenue equals total costs (no profit, no loss). BEP = Fixed Costs / (Selling Price per Unit - Variable Cost per Unit).'
            ],
        ]
    ],
    [
        'exam_code' => 'BBA1203-QZ1-26',
        'exam_name' => 'Business Statistics - Quiz 1',
        'exam_type' => 'quiz',
        'course_id' => 12,
        'description' => 'Quick quiz covering measures of central tendency, dispersion, and probability basics.',
        'instructions' => "1. This is a timed quiz - 20 minutes only.\n2. All questions are multiple choice or true/false.\n3. Each question carries 5 marks.\n4. Good luck!",
        'duration_minutes' => 20,
        'total_marks' => 30,
        'passing_marks' => 15,
        'max_attempts' => 3,
        'shuffle_questions' => 1,
        'shuffle_options' => 1,
        'show_results' => 1,
        'allow_review' => 1,
        'require_camera' => 0,
        'require_token' => 0,
        'start_offset' => '-3 days',
        'end_offset' => '+60 days',
        'questions' => [
            [
                'text' => 'The mean of the dataset {4, 8, 6, 10, 12} is:',
                'type' => 'multiple_choice',
                'options' => ['6', '7', '8', '9'],
                'correct' => '8',
                'marks' => 5,
                'explanation' => 'Mean = (4+8+6+10+12)/5 = 40/5 = 8.'
            ],
            [
                'text' => 'The median is always the same as the mean.',
                'type' => 'true_false',
                'options' => ['True', 'False'],
                'correct' => 'False',
                'marks' => 5,
                'explanation' => 'The median and mean are only equal in perfectly symmetric distributions. They can differ, especially in skewed data.'
            ],
            [
                'text' => 'Which measure of dispersion is most affected by extreme values (outliers)?',
                'type' => 'multiple_choice',
                'options' => ['Interquartile Range', 'Standard Deviation', 'Range', 'Median Absolute Deviation'],
                'correct' => 'Range',
                'marks' => 5,
                'explanation' => 'Range (max - min) is most affected by outliers since it only considers the two extreme values.'
            ],
            [
                'text' => 'If the probability of an event occurring is 0.3, what is the probability of it NOT occurring?',
                'type' => 'multiple_choice',
                'options' => ['0.3', '0.5', '0.7', '1.0'],
                'correct' => '0.7',
                'marks' => 5,
                'explanation' => 'P(not A) = 1 - P(A) = 1 - 0.3 = 0.7.'
            ],
            [
                'text' => 'The mode is the value that appears most frequently in a dataset.',
                'type' => 'true_false',
                'options' => ['True', 'False'],
                'correct' => 'True',
                'marks' => 5,
                'explanation' => 'The mode is defined as the value with the highest frequency of occurrence in a dataset.'
            ],
            [
                'text' => 'Standard deviation can never be negative.',
                'type' => 'true_false',
                'options' => ['True', 'False'],
                'correct' => 'True',
                'marks' => 5,
                'explanation' => 'Standard deviation is the square root of variance, which is always non-negative. Therefore, SD is always ≥ 0.'
            ],
        ]
    ],
    [
        'exam_code' => 'BBA1204-MID-26',
        'exam_name' => 'Principles of Management - Mid-Semester Exam',
        'exam_type' => 'mid_term',
        'course_id' => 14,
        'description' => 'Mid-semester examination covering planning, organizing, leading, and controlling functions of management.',
        'instructions' => "1. Answer all questions carefully.\n2. Time: 60 minutes.\n3. Both objective and subjective questions are included.\n4. Write clearly for essay questions.",
        'duration_minutes' => 60,
        'total_marks' => 60,
        'passing_marks' => 30,
        'max_attempts' => 1,
        'shuffle_questions' => 1,
        'shuffle_options' => 1,
        'show_results' => 1,
        'allow_review' => 1,
        'require_camera' => 0,
        'require_token' => 0,
        'start_offset' => '+1 day',     // Upcoming exam - starts tomorrow
        'end_offset' => '+15 days',
        'questions' => [
            [
                'text' => 'Which of the following is NOT a function of management?',
                'type' => 'multiple_choice',
                'options' => ['Planning', 'Organizing', 'Manufacturing', 'Controlling'],
                'correct' => 'Manufacturing',
                'marks' => 5,
                'explanation' => 'The four functions of management are Planning, Organizing, Leading, and Controlling. Manufacturing is an operational activity.'
            ],
            [
                'text' => 'Henri Fayol is known as the father of modern management.',
                'type' => 'true_false',
                'options' => ['True', 'False'],
                'correct' => 'True',
                'marks' => 5,
                'explanation' => 'Henri Fayol developed 14 principles of management and is considered the father of modern management theory.'
            ],
            [
                'text' => 'SWOT analysis examines:',
                'type' => 'multiple_choice',
                'options' => ['Sales, Wages, Output, Taxes', 'Strengths, Weaknesses, Opportunities, Threats', 'Strategy, Workforce, Operations, Technology', 'Supply, Wholesale, Orders, Transport'],
                'correct' => 'Strengths, Weaknesses, Opportunities, Threats',
                'marks' => 5,
                'explanation' => 'SWOT stands for Strengths, Weaknesses, Opportunities, and Threats - a strategic planning framework.'
            ],
            [
                'text' => 'Which leadership style gives employees maximum freedom in decision-making?',
                'type' => 'multiple_choice',
                'options' => ['Autocratic', 'Democratic', 'Laissez-faire', 'Bureaucratic'],
                'correct' => 'Laissez-faire',
                'marks' => 5,
                'explanation' => 'Laissez-faire (free-rein) leadership gives subordinates complete freedom to make decisions with minimal supervision.'
            ],
            [
                'text' => 'Delegation means transferring both authority and accountability to a subordinate.',
                'type' => 'true_false',
                'options' => ['True', 'False'],
                'correct' => 'False',
                'marks' => 5,
                'explanation' => 'Delegation transfers authority and responsibility, but the manager retains ultimate accountability.'
            ],
            [
                'text' => 'Maslow\'s hierarchy places self-actualization at the top.',
                'type' => 'true_false',
                'options' => ['True', 'False'],
                'correct' => 'True',
                'marks' => 5,
                'explanation' => 'Maslow\'s hierarchy from bottom to top: Physiological, Safety, Social, Esteem, Self-Actualization.'
            ],
            [
                'text' => 'Explain the difference between strategic planning and operational planning. Provide examples relevant to a university setting.',
                'type' => 'essay',
                'options' => [],
                'correct' => '',
                'marks' => 15,
                'explanation' => 'Strategic planning is long-term, sets direction (e.g., university expansion plan). Operational planning is short-term, day-to-day activities (e.g., timetable scheduling).'
            ],
            [
                'text' => 'Which management theory emphasizes the importance of social relationships and employee satisfaction?',
                'type' => 'multiple_choice',
                'options' => ['Scientific Management', 'Human Relations Theory', 'Bureaucratic Management', 'Systems Theory'],
                'correct' => 'Human Relations Theory',
                'marks' => 5,
                'explanation' => 'Human Relations Theory (Hawthorne Studies, Elton Mayo) emphasizes that social factors and employee satisfaction significantly impact productivity.'
            ],
            [
                'text' => 'Define "span of control" and state two factors that determine the ideal span.',
                'type' => 'short_answer',
                'options' => [],
                'correct' => '',
                'marks' => 10,
                'explanation' => 'Span of control is the number of subordinates reporting directly to a manager. Factors include: complexity of work, competence of subordinates, geographical proximity, and level of standardization.'
            ],
        ]
    ],
];

// Insert each exam
$exams_created = 0;
$questions_created = 0;

foreach ($sample_exams as $exam_data) {
    // Check if exam_code already exists
    $check = $conn->prepare("SELECT exam_id FROM exams WHERE exam_code = ?");
    $check->bind_param("s", $exam_data['exam_code']);
    $check->execute();
    $existing_exam = $check->get_result()->fetch_assoc();

    if ($existing_exam) {
        out("Exam '{$exam_data['exam_code']}' already exists (ID={$existing_exam['exam_id']}), skipping.", 'warning');
        continue;
    }

    $conn->begin_transaction();
    try {
        // Calculate times
        $start_time = date('Y-m-d H:i:s', strtotime($exam_data['start_offset']));
        $end_time = date('Y-m-d H:i:s', strtotime($exam_data['end_offset']));

        // Insert exam
        $stmt = $conn->prepare("INSERT INTO exams (
            exam_code, exam_name, exam_type, course_id, description, instructions,
            start_time, end_time, duration_minutes, total_marks, passing_marks, max_attempts,
            shuffle_questions, shuffle_options, show_results, allow_review, require_camera, require_token,
            is_active, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())");

        $stmt->bind_param("sssissssiiiiiiiiiii",
            $exam_data['exam_code'],
            $exam_data['exam_name'],
            $exam_data['exam_type'],
            $exam_data['course_id'],
            $exam_data['description'],
            $exam_data['instructions'],
            $start_time,
            $end_time,
            $exam_data['duration_minutes'],
            $exam_data['total_marks'],
            $exam_data['passing_marks'],
            $exam_data['max_attempts'],
            $exam_data['shuffle_questions'],
            $exam_data['shuffle_options'],
            $exam_data['show_results'],
            $exam_data['allow_review'],
            $exam_data['require_camera'],
            $exam_data['require_token'],
            $officer_user_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to insert exam: " . $conn->error);
        }

        $exam_id = $conn->insert_id;
        $exams_created++;
        out("Created exam: {$exam_data['exam_name']} (ID=$exam_id) [{$exam_data['exam_type']}]", 'success');

        // Insert questions
        $q_order = 1;
        foreach ($exam_data['questions'] as $q) {
            $options_json = !empty($q['options']) ? json_encode($q['options']) : null;

            $q_stmt = $conn->prepare("INSERT INTO exam_questions (exam_id, question_text, question_type, options, correct_answer, marks, question_order, question_number, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $q_stmt->bind_param("issssiiis",
                $exam_id,
                $q['text'],
                $q['type'],
                $options_json,
                $q['correct'],
                $q['marks'],
                $q_order,
                $q_order,
                $q['explanation']
            );

            if ($q_stmt->execute()) {
                $questions_created++;
            } else {
                out("  Warning: Failed to insert question '$q[text]': " . $conn->error, 'warning');
            }
            $q_order++;
        }
        out("  Added " . count($exam_data['questions']) . " questions", 'info');

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = $e->getMessage();
        out("Failed to create exam '{$exam_data['exam_code']}': " . $e->getMessage(), 'error');
    }
}

// ============================================================
// 3. SUMMARY
// ============================================================
out("", 'info');
out("=== Setup Complete ===", 'header');
out("Examination Officer: $officer_fullname ($officer_email)", 'info');
out("Exams created: $exams_created | Questions created: $questions_created", 'info');

if (!empty($errors)) {
    out("Errors encountered: " . count($errors), 'error');
}

// Clean up temp file
if (file_exists('check_sample_data.php')) {
    unlink('check_sample_data.php');
}

// ============================================================
// OUTPUT
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Sample Examinations - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f7fa; font-family: 'Segoe UI', sans-serif; }
        .setup-container { max-width: 900px; margin: 40px auto; }
        .log-entry { padding: 6px 12px; border-radius: 4px; margin-bottom: 4px; font-size: 0.9rem; }
        .log-header { background: #1e3c72; color: #fff; font-weight: 600; font-size: 1rem; margin-top: 15px; }
        .log-success { background: #d4edda; color: #155724; }
        .log-warning { background: #fff3cd; color: #856404; }
        .log-error { background: #f8d7da; color: #721c24; }
        .log-info { background: #e8eef6; color: #333; }
        .cred-card { background: linear-gradient(135deg, #1e3c72, #2a5298); color: #fff; border-radius: 12px; padding: 25px; }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white">
                <h4 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Sample Examinations Setup</h4>
            </div>
            <div class="card-body">
                <?php foreach ($output as $entry): ?>
                    <div class="log-entry log-<?= $entry['type'] ?>">
                        <?php if ($entry['type'] === 'success'): ?>
                            <i class="bi bi-check-circle me-1"></i>
                        <?php elseif ($entry['type'] === 'error'): ?>
                            <i class="bi bi-x-circle me-1"></i>
                        <?php elseif ($entry['type'] === 'warning'): ?>
                            <i class="bi bi-exclamation-triangle me-1"></i>
                        <?php elseif ($entry['type'] === 'header'): ?>
                            <i class="bi bi-gear me-1"></i>
                        <?php endif; ?>
                        <?= htmlspecialchars($entry['msg']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($exams_created > 0 || $officer_manager_id): ?>
        <div class="cred-card mb-4">
            <h5><i class="bi bi-key me-2"></i>Sample Examination Officer Credentials</h5>
            <hr style="border-color: rgba(255,255,255,0.3);">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-1"><strong>Full Name:</strong> <?= htmlspecialchars($officer_fullname) ?></p>
                    <p class="mb-1"><strong>Email:</strong> <?= htmlspecialchars($officer_email) ?></p>
                    <p class="mb-1"><strong>Username:</strong> <code style="color: #ffc107;"><?= htmlspecialchars($officer_username) ?></code></p>
                    <p class="mb-0"><strong>Password:</strong> <code style="color: #ffc107;"><?= htmlspecialchars($officer_password) ?></code></p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>Department:</strong> Academic Affairs</p>
                    <p class="mb-1"><strong>Position:</strong> Senior Examination Officer</p>
                    <p class="mb-0"><strong>Manager ID:</strong> <?= $officer_manager_id ?></p>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-journal-check me-2"></i>Sample Exams Created</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Exam Code</th>
                            <th>Exam Name</th>
                            <th>Type</th>
                            <th>Duration</th>
                            <th>Marks</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sample_exams as $ex): ?>
                        <tr>
                            <td><code><?= $ex['exam_code'] ?></code></td>
                            <td><?= htmlspecialchars($ex['exam_name']) ?></td>
                            <td><span class="badge bg-<?= $ex['exam_type']==='final'?'danger':($ex['exam_type']==='quiz'?'info':'warning') ?>"><?= ['quiz'=>'Quiz','mid_term'=>'Mid-Semester Exam','final'=>'End-Semester Examination','assignment'=>'Assignment'][$ex['exam_type']] ?? ucfirst(str_replace('_', ' ', $ex['exam_type'])) ?></span></td>
                            <td><?= $ex['duration_minutes'] ?> min</td>
                            <td><?= $ex['total_marks'] ?></td>
                            <td>
                                <?php
                                    $start = strtotime($ex['start_offset']);
                                    if ($start > time()) echo '<span class="badge bg-secondary">Upcoming</span>';
                                    else echo '<span class="badge bg-success">Available Now</span>';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="text-center mb-4">
            <a href="admin/manage_examination_officers.php" class="btn btn-primary me-2"><i class="bi bi-shield-check me-1"></i>Manage Officers (Admin)</a>
            <a href="examination_officer/dashboard.php" class="btn btn-dark me-2"><i class="bi bi-speedometer2 me-1"></i>Exam Officer Dashboard</a>
            <a href="examination/exams.php" class="btn btn-success me-2"><i class="bi bi-mortarboard me-1"></i>Student Exams</a>
            <a href="login.php" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-right me-1"></i>Login</a>
        </div>
    </div>
</body>
</html>
