<?php
require_once __DIR__ . '/includes/config.php';

$conn = dmsGetDbConnection(true);

function runSql(mysqli $conn, string $sql, string $label): void {
    echo '<li>' . htmlspecialchars($label) . ': ';
    if ($conn->query($sql)) {
        echo '<span style="color:green;">OK</span>';
    } else {
        echo '<span style="color:red;">ERROR - ' . htmlspecialchars($conn->error) . '</span>';
    }
    echo '</li>';
}

$queries = [
    ['label' => 'Create users table', 'sql' => "CREATE TABLE IF NOT EXISTS users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(120) NOT NULL,
        username VARCHAR(60) NOT NULL UNIQUE,
        email VARCHAR(120) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','student','research_coordinator','supervisor','finance_officer') NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],

    ['label' => 'Create students table', 'sql' => "CREATE TABLE IF NOT EXISTS students (
        student_id VARCHAR(30) PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        program VARCHAR(120) DEFAULT NULL,
        year_of_study INT DEFAULT 1,
        semester ENUM('One','Two') DEFAULT 'One',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],

    ['label' => 'Create dissertations table', 'sql' => "CREATE TABLE IF NOT EXISTS dissertations (
        dissertation_id INT AUTO_INCREMENT PRIMARY KEY,
        student_user_id INT NOT NULL,
        title VARCHAR(500) DEFAULT NULL,
        topic_area VARCHAR(255) DEFAULT NULL,
        status ENUM('topic_submission','topic_approved','topic_rejected','chapter1_submitted','chapter2_submitted','proposal_submitted','ethics_submitted','final_submitted','completed') DEFAULT 'topic_submission',
        current_phase ENUM('topic','chapter1','chapter2','proposal','ethics','final_submission') DEFAULT 'topic',
        supervisor_user_id INT DEFAULT NULL,
        coordinator_user_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (supervisor_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
        FOREIGN KEY (coordinator_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
        INDEX idx_status (status),
        INDEX idx_phase (current_phase)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],

    ['label' => 'Create dissertation_submissions table', 'sql' => "CREATE TABLE IF NOT EXISTS dissertation_submissions (
        submission_id INT AUTO_INCREMENT PRIMARY KEY,
        dissertation_id INT NOT NULL,
        phase ENUM('topic','chapter1','chapter2','proposal','ethics','final_submission') NOT NULL,
        version INT NOT NULL DEFAULT 1,
        submission_text TEXT,
        file_path VARCHAR(255) DEFAULT NULL,
        status ENUM('submitted','approved','revision_requested','rejected') DEFAULT 'submitted',
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        FOREIGN KEY (dissertation_id) REFERENCES dissertations(dissertation_id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,
        INDEX idx_dissertation (dissertation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],

    ['label' => 'Create dissertation_feedback table', 'sql' => "CREATE TABLE IF NOT EXISTS dissertation_feedback (
        feedback_id INT AUTO_INCREMENT PRIMARY KEY,
        dissertation_id INT NOT NULL,
        submission_id INT DEFAULT NULL,
        reviewer_user_id INT NOT NULL,
        reviewer_role ENUM('research_coordinator','supervisor','finance_officer') NOT NULL,
        feedback_text TEXT NOT NULL,
        feedback_type ENUM('comment','approval','revision_request','rejection') DEFAULT 'comment',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (dissertation_id) REFERENCES dissertations(dissertation_id) ON DELETE CASCADE,
        FOREIGN KEY (submission_id) REFERENCES dissertation_submissions(submission_id) ON DELETE SET NULL,
        FOREIGN KEY (reviewer_user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],

    ['label' => 'Create dissertation_fees table', 'sql' => "CREATE TABLE IF NOT EXISTS dissertation_fees (
        fee_id INT AUTO_INCREMENT PRIMARY KEY,
        dissertation_id INT NOT NULL UNIQUE,
        student_user_id INT NOT NULL,
        total_fee DECIMAL(12,2) NOT NULL DEFAULT 250000.00,
        installment_amount DECIMAL(12,2) NOT NULL DEFAULT 83333.33,
        installment_1_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        installment_2_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        installment_3_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        balance DECIMAL(12,2) NOT NULL DEFAULT 250000.00,
        lock_before_proposal TINYINT(1) NOT NULL DEFAULT 1,
        lock_before_ethics TINYINT(1) NOT NULL DEFAULT 1,
        lock_before_final TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (dissertation_id) REFERENCES dissertations(dissertation_id) ON DELETE CASCADE,
        FOREIGN KEY (student_user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],

    ['label' => 'Create payment_transactions table', 'sql' => "CREATE TABLE IF NOT EXISTS payment_transactions (
        transaction_id INT AUTO_INCREMENT PRIMARY KEY,
        fee_id INT NOT NULL,
        student_user_id INT NOT NULL,
        installment_no TINYINT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_reference VARCHAR(120) DEFAULT NULL,
        payment_date DATE NOT NULL,
        recorded_by INT NOT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (fee_id) REFERENCES dissertation_fees(fee_id) ON DELETE CASCADE,
        FOREIGN KEY (student_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"]
];

echo '<h2>DMS + Finance Setup</h2>';
echo '<p>Database: <strong>' . DMS_DB_NAME . '</strong></p>';
echo '<ol>';
foreach ($queries as $q) {
    runSql($conn, $q['sql'], $q['label']);
}
echo '</ol>';

$seedUsers = [
    ['name' => 'System Admin', 'username' => 'admin', 'email' => 'admin@dms.local', 'password' => 'admin123', 'role' => 'admin'],
    ['name' => 'Research Coordinator', 'username' => 'coordinator', 'email' => 'coordinator@dms.local', 'password' => 'coord123', 'role' => 'research_coordinator'],
    ['name' => 'Supervisor One', 'username' => 'supervisor1', 'email' => 'supervisor@dms.local', 'password' => 'super123', 'role' => 'supervisor'],
    ['name' => 'Finance Officer', 'username' => 'finance1', 'email' => 'finance@dms.local', 'password' => 'finance123', 'role' => 'finance_officer'],
    ['name' => 'Student Demo', 'username' => 'student1', 'email' => 'student@dms.local', 'password' => 'student123', 'role' => 'student']
];

$insertUser = $conn->prepare('INSERT INTO users (full_name, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
$findUser = $conn->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
$studentUserId = 0;
$coordinatorUserId = 0;

foreach ($seedUsers as $u) {
    $findUser->bind_param('s', $u['username']);
    $findUser->execute();
    $existing = $findUser->get_result()->fetch_assoc();

    if (!$existing) {
        $hash = password_hash($u['password'], PASSWORD_DEFAULT);
        $insertUser->bind_param('sssss', $u['name'], $u['username'], $u['email'], $hash, $u['role']);
        $insertUser->execute();
        $uid = $conn->insert_id;
    } else {
        $uid = (int)$existing['user_id'];
    }

    if ($u['role'] === 'student') {
        $studentUserId = $uid;
    }
    if ($u['role'] === 'research_coordinator') {
        $coordinatorUserId = $uid;
    }
}

if ($studentUserId > 0) {
    $studentId = 'DMS/' . date('Y') . '/0001';
    $checkStudent = $conn->prepare('SELECT student_id FROM students WHERE user_id = ? LIMIT 1');
    $checkStudent->bind_param('i', $studentUserId);
    $checkStudent->execute();
    if (!$checkStudent->get_result()->fetch_assoc()) {
        $insertStudent = $conn->prepare("INSERT INTO students (student_id, user_id, program, year_of_study, semester) VALUES (?, ?, ?, 4, 'Two')");
        $program = 'MSc Information Systems';
        $insertStudent->bind_param('sis', $studentId, $studentUserId, $program);
        $insertStudent->execute();
    }

    $checkDissertation = $conn->prepare('SELECT dissertation_id FROM dissertations WHERE student_user_id = ? LIMIT 1');
    $checkDissertation->bind_param('i', $studentUserId);
    $checkDissertation->execute();
    $dRow = $checkDissertation->get_result()->fetch_assoc();

    if (!$dRow) {
        $insertDiss = $conn->prepare("INSERT INTO dissertations (student_user_id, title, topic_area, status, current_phase, coordinator_user_id) VALUES (?, ?, ?, 'topic_submission', 'topic', ?)");
        $title = 'AI Supported Decision Making in Higher Education';
        $topic = 'Artificial Intelligence in Education';
        $insertDiss->bind_param('issi', $studentUserId, $title, $topic, $coordinatorUserId);
        $insertDiss->execute();
        $did = $conn->insert_id;

        $insertFee = $conn->prepare('INSERT INTO dissertation_fees (dissertation_id, student_user_id) VALUES (?, ?)');
        $insertFee->bind_param('ii', $did, $studentUserId);
        $insertFee->execute();
    }
}

echo '<h3>Seeded Accounts</h3>';
echo '<table border="1" cellpadding="8" cellspacing="0">';
echo '<tr><th>Role</th><th>Username</th><th>Password</th></tr>';
foreach ($seedUsers as $u) {
    echo '<tr><td>' . htmlspecialchars($u['role']) . '</td><td>' . htmlspecialchars($u['username']) . '</td><td>' . htmlspecialchars($u['password']) . '</td></tr>';
}
echo '</table>';

echo '<p><a href="login.php">Go to Login</a></p>';
