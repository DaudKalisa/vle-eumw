<?php
// examination_manager/generate_tokens.php - Generate Exam Tokens for Students
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'examination_manager']);

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
                // Check if token already exists for this student and exam
                $checkStmt = $conn->prepare("SELECT token_id FROM exam_tokens WHERE exam_id = ? AND student_id = ?");
                $checkStmt->bind_param("is", $examId, $studentId);
                $checkStmt->execute();

                if ($checkStmt->get_result()->num_rows == 0) {
                    // Generate unique token
                    $token = generateUniqueToken();
                    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$tokenValidity} hours"));

                    // Insert token
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

function generateUniqueToken() {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}

$pageTitle = "Generate Exam Tokens";
$breadcrumbs = [['title' => 'Generate Tokens']];
include 'header_nav.php';
?>
<style>
    .token-card {
        background: var(--vle-gradient-accent);
        color: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 15px;
    }
    .student-checkbox {
        margin-bottom: 8px;
    }
</style>
    <div class="vle-content">
        <div class="vle-page-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-key me-2"></i>Generate Exam Tokens</h1>
                    <p class="text-muted mb-0">Create secure access tokens for students to take examinations</p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <?php if (!$exam): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> No exam selected. Please go back to the dashboard and select an exam.
            </div>
        <?php else: ?>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Exam Information -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Exam Details</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6><?php echo htmlspecialchars($exam['exam_name']); ?></h6>
                        <p class="text-muted mb-1">Code: <?php echo htmlspecialchars($exam['exam_code']); ?></p>
                        <p class="text-muted mb-1">Course: <?php echo htmlspecialchars($exam['course_name']); ?></p>
                        <p class="text-muted mb-0">Lecturer: <?php echo htmlspecialchars($exam['lecturer_name']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Duration:</strong> <?php echo $exam['duration_minutes']; ?> minutes</p>
                        <p class="mb-1"><strong>Total Marks:</strong> <?php echo $exam['total_marks']; ?></p>
                        <p class="mb-1"><strong>Start Time:</strong> <?php echo date('M d, Y H:i', strtotime($exam['start_time'])); ?></p>
                        <p class="mb-0"><strong>End Time:</strong> <?php echo date('M d, Y H:i', strtotime($exam['end_time'])); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Generate New Tokens -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Generate New Tokens</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Token Validity (hours)</label>
                                <input type="number" class="form-control" name="token_validity_hours" value="24" min="1" max="168" required>
                                <small class="text-muted">How long should the token be valid for?</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Select Students</label>
                                <div class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                                    <?php if (empty($enrolledStudents)): ?>
                                        <p class="text-muted">No students enrolled in this course.</p>
                                    <?php else: ?>
                                        <div class="mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="selectAll">
                                                <label class="form-check-label fw-bold" for="selectAll">
                                                    Select All Students
                                                </label>
                                            </div>
                                        </div>
                                        <?php foreach ($enrolledStudents as $student): ?>
                                            <div class="student-checkbox">
                                                <div class="form-check">
                                                    <input class="form-check-input student-check" type="checkbox"
                                                           name="students[]" value="<?php echo $student['student_id']; ?>"
                                                           id="student_<?php echo $student['student_id']; ?>"
                                                           <?php echo $student['has_token'] ? 'disabled' : ''; ?>>
                                                    <label class="form-check-label" for="student_<?php echo $student['student_id']; ?>">
                                                        <?php echo htmlspecialchars($student['full_name']); ?>
                                                        <small class="text-muted">(<?php echo htmlspecialchars($student['student_id']); ?>)</small>
                                                        <?php if ($student['has_token']): ?>
                                                            <span class="badge bg-success ms-2">Token Exists</span>
                                                        <?php endif; ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <button type="submit" name="generate_tokens" class="btn btn-success w-100">
                                <i class="bi bi-key"></i> Generate Tokens
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Existing Tokens -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list me-2"></i>Existing Tokens (<?php echo count($existingTokens); ?>)</h5>
                        <button class="btn btn-sm btn-outline-primary" onclick="exportTokens()">
                            <i class="bi bi-download"></i> Export
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($existingTokens)): ?>
                            <p class="text-muted text-center py-4">No tokens generated yet.</p>
                        <?php else: ?>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($existingTokens as $token): ?>
                                    <div class="token-card">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($token['full_name']); ?></h6>
                                                <small><?php echo htmlspecialchars($token['student_id']); ?></small>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-bold fs-5"><?php echo $token['token']; ?></div>
                                                <small>Expires: <?php echo date('M d, H:i', strtotime($token['expires_at'])); ?></small>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <small>
                                                Status:
                                                <?php if ($token['is_used']): ?>
                                                    <span class="badge bg-success">Used</span>
                                                <?php elseif (strtotime($token['expires_at']) < time()): ?>
                                                    <span class="badge bg-danger">Expired</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Active</span>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select all students checkbox
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.student-check:not([disabled])');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });

        // Export tokens function
        function exportTokens() {
            const tokens = <?php echo json_encode($existingTokens); ?>;
            if (tokens.length === 0) {
                alert('No tokens to export');
                return;
            }

            let csv = 'Student ID,Student Name,Token,Expires At,Status\n';
            tokens.forEach(token => {
                const status = token.is_used ? 'Used' :
                              (new Date(token.expires_at) < new Date() ? 'Expired' : 'Active');
                csv += `"${token.student_id}","${token.full_name}","${token.token}","${token.expires_at}","${status}"\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'exam_tokens_<?php echo $exam['exam_code']; ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>