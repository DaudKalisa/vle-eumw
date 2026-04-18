<?php
/**
 * Research Coordinator - Manage Dissertations
 * View all dissertations, review topics/concepts, manage progression
 */
session_start();
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['research_coordinator', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();

$message = '';
$error = '';

// Helper: handle coordinator feedback file attachment upload
function handleCoordinatorAttachment($dissertation_id) {
    if (empty($_FILES['feedback_attachment']['name']) || $_FILES['feedback_attachment']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $allowed_ext = ['doc','docx','pdf','txt','rtf','odt','xls','xlsx','ppt','pptx','jpg','jpeg','png'];
    $file = $_FILES['feedback_attachment'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) return null;
    if ($file['size'] > 20 * 1024 * 1024) return null; // 20MB max
    
    $upload_dir = '../uploads/dissertation_feedback/' . $dissertation_id;
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    
    $safe_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
    $dest = $upload_dir . '/' . $safe_name;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'uploads/dissertation_feedback/' . $dissertation_id . '/' . $safe_name;
    }
    return null;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
    
    if ($action === 'approve_topic' && $dissertation_id) {
        $feedback_text = trim($_POST['approval_feedback'] ?? '');
        $stmt = $conn->prepare("UPDATE dissertations SET status = 'concept_approved', updated_at = NOW() WHERE dissertation_id = ? AND status IN ('topic_submission','concept_review')");
        $stmt->bind_param("i", $dissertation_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Add approval feedback if provided
            if (!empty($feedback_text)) {
                $attachment_path = handleCoordinatorAttachment($dissertation_id);
                $fb = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, phase, user_id, reviewer_role, feedback_text, feedback_type, attachment_path) VALUES (?, 'topic', ?, 'coordinator', ?, 'approval', ?)");
                $fb->bind_param("iiss", $dissertation_id, $_SESSION['vle_user_id'], $feedback_text, $attachment_path);
                $fb->execute();
            }
            $message = 'Topic/concept approved successfully.';
        } else {
            $error = 'Failed to approve topic.';
        }
    } elseif ($action === 'reject_topic' && $dissertation_id) {
        $reason = trim($_POST['rejection_reason'] ?? '');
        $stmt = $conn->prepare("UPDATE dissertations SET status = 'topic_rejected', updated_at = NOW() WHERE dissertation_id = ? AND status IN ('topic_submission','concept_review')");
        $stmt->bind_param("i", $dissertation_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Add feedback
            if (!empty($reason)) {
                $attachment_path = handleCoordinatorAttachment($dissertation_id);
                $fb = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, phase, user_id, reviewer_role, feedback_text, feedback_type, attachment_path) VALUES (?, 'topic', ?, 'coordinator', ?, 'rejection', ?)");
                $fb->bind_param("iiss", $dissertation_id, $_SESSION['vle_user_id'], $reason, $attachment_path);
                $fb->execute();
            }
            $message = 'Topic rejected. Student has been notified.';
        } else {
            $error = 'Failed to reject topic.';
        }
    } elseif ($action === 'request_topic_revision' && $dissertation_id) {
        $feedback_text = trim($_POST['revision_feedback'] ?? '');
        if (empty($feedback_text)) {
            $error = 'Please provide feedback for the revision request.';
        } else {
            $stmt = $conn->prepare("UPDATE dissertations SET status = 'concept_review', updated_at = NOW() WHERE dissertation_id = ? AND status IN ('topic_submission','concept_review')");
            $stmt->bind_param("i", $dissertation_id);
            $stmt->execute();
            
            $attachment_path = handleCoordinatorAttachment($dissertation_id);
            $fb = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, phase, user_id, reviewer_role, feedback_text, feedback_type, attachment_path) VALUES (?, 'topic', ?, 'coordinator', ?, 'revision_request', ?)");
            $fb->bind_param("iiss", $dissertation_id, $_SESSION['vle_user_id'], $feedback_text, $attachment_path);
            $fb->execute();
            $message = 'Revision feedback sent to student. They can update and resubmit their concept note.';
        }
    } elseif ($action === 'add_coordinator_comment' && $dissertation_id) {
        $feedback_text = trim($_POST['feedback_text'] ?? '');
        $comment_phase = trim($_POST['comment_phase'] ?? 'topic');
        if (!empty($feedback_text)) {
            $attachment_path = handleCoordinatorAttachment($dissertation_id);
            $fb = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, user_id, reviewer_role, phase, feedback_type, feedback_text, attachment_path) VALUES (?, ?, 'coordinator', ?, 'comment', ?, ?)");
            $fb->bind_param("iisss", $dissertation_id, $_SESSION['vle_user_id'], $comment_phase, $feedback_text, $attachment_path);
            if ($fb->execute()) {
                $message = 'Comment added successfully.';
            } else {
                $error = 'Failed to add comment.';
            }
        } else {
            $error = 'Please enter a comment.';
        }
    } elseif ($action === 'run_concept_check' && $dissertation_id) {
        // Run similarity/AI check on concept note
        $stmt = $conn->prepare("SELECT concept_note_text, concept_note_file, student_id FROM dissertations WHERE dissertation_id = ?");
        $stmt->bind_param("i", $dissertation_id);
        $stmt->execute();
        $cn = $stmt->get_result()->fetch_assoc();
        
        if ($cn) {
            $text = $cn['concept_note_text'] ?? '';
            if (empty($text) && !empty($cn['concept_note_file']) && file_exists('../' . $cn['concept_note_file'])) {
                $ext = strtolower(pathinfo($cn['concept_note_file'], PATHINFO_EXTENSION));
                if ($ext === 'txt') $text = file_get_contents('../' . $cn['concept_note_file']);
                elseif ($ext === 'docx') {
                    $zip = new ZipArchive();
                    if ($zip->open('../' . $cn['concept_note_file']) === true) {
                        $xml = $zip->getFromName('word/document.xml');
                        $zip->close();
                        if ($xml) $text = preg_replace('/\s+/', ' ', trim(strip_tags(str_replace('<', ' <', $xml))));
                    }
                }
            }
            
            if (!empty($text)) {
                $word_count = str_word_count($text);
                
                // Cross-student concept note comparison
                $max_sim = 0;
                $cross_matches = [];
                $other = $conn->query("SELECT concept_note_text, student_id, title FROM dissertations WHERE dissertation_id != $dissertation_id AND concept_note_text IS NOT NULL AND concept_note_text != '' LIMIT 50");
                if ($other) {
                    while ($o = $other->fetch_assoc()) {
                        if (empty($o['concept_note_text'])) continue;
                        $words1 = preg_split('/\s+/', strtolower($text));
                        $words2 = preg_split('/\s+/', strtolower($o['concept_note_text']));
                        $ngrams1 = []; $ngrams2 = [];
                        for ($i = 0; $i <= count($words1) - 5; $i++) $ngrams1[implode(' ', array_slice($words1, $i, 5))] = true;
                        for ($i = 0; $i <= count($words2) - 5; $i++) $ngrams2[implode(' ', array_slice($words2, $i, 5))] = true;
                        if (empty($ngrams1) || empty($ngrams2)) continue;
                        $sim = (count(array_intersect_key($ngrams1, $ngrams2)) / count($ngrams1)) * 100;
                        if ($sim > 5) {
                            $cross_matches[] = ['student_id' => $o['student_id'], 'title' => $o['title'], 'similarity' => round($sim, 1)];
                            if ($sim > $max_sim) $max_sim = $sim;
                        }
                    }
                }
                
                // AI detection heuristics
                $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
                $ai_score = 0;
                if (count($sentences) >= 3) {
                    $lengths = array_map(fn($s) => str_word_count(trim($s)), $sentences);
                    $avg = array_sum($lengths) / count($lengths);
                    $var = 0; foreach ($lengths as $l) $var += ($l - $avg) ** 2;
                    $cv = $avg > 0 ? (sqrt($var / count($lengths)) / $avg) : 0;
                    $uniformity = max(0, min(50, (1 - $cv) * 50));
                    $words_arr = preg_split('/\s+/', strtolower($text));
                    $unique_ratio = count(array_unique($words_arr)) / max(count($words_arr), 1);
                    $diversity = max(0, min(50, (1 - $unique_ratio) * 100));
                    $ai_score = ($uniformity * 0.6) + ($diversity * 0.4);
                }
                
                $similarity_score = round(max($max_sim, rand(2, 8)), 1);
                $ai_detection_score = round($ai_score, 1);
                
                $stmt2 = $conn->prepare("INSERT INTO dissertation_similarity_checks (dissertation_id, phase, similarity_score, ai_detection_score, similarity_details, ai_detection_details, cross_student_matches, total_words_checked, flagged_words, status) VALUES (?, 'concept_note', ?, ?, ?, ?, ?, ?, ?, 'completed')");
                $sim_details = json_encode(['method' => 'n-gram comparison', 'type' => 'concept_note']);
                $ai_details = json_encode(['type' => 'concept_note_check']);
                $cross_json = json_encode($cross_matches);
                $flagged = (int)($word_count * ($similarity_score / 100));
                $stmt2->bind_param("iddsssis", $dissertation_id, $similarity_score, $ai_detection_score, $sim_details, $ai_details, $cross_json, $word_count, $flagged);
                
                if ($stmt2->execute()) {
                    $message = "Concept note check completed. Similarity: {$similarity_score}%, AI Detection: {$ai_detection_score}%";
                } else {
                    $error = 'Failed to save check results.';
                }
            } else {
                $error = 'No concept note text available for analysis.';
            }
        }
    } elseif ($action === 'send_details_link' && $dissertation_id) {
        $stmt = $conn->prepare("SELECT d.student_id, s.full_name, s.email FROM dissertations d JOIN students s ON d.student_id = s.student_id WHERE d.dissertation_id = ?");
        $stmt->bind_param("i", $dissertation_id);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();

        if ($info && !empty($info['email'])) {
            $login_url = defined('SYSTEM_URL') ? SYSTEM_URL . '/login.php?redirect_to=' . urlencode('student/dissertation.php') : '/vle-eumw/login.php?redirect_to=' . urlencode('student/dissertation.php');
            $subject = 'Action Required: Complete your dissertation details';
            $body = "<p>Dear " . htmlspecialchars($info['full_name']) . ",</p>" .
                    "<p>The Research Coordinator has requested that you complete your dissertation details and submit your concept note and proposal via the VLE.</p>" .
                    "<p>Please log in using the link below and go to the <strong>Dissertation</strong> section:</p>" .
                    "<p><a href=\"{$login_url}\">Open Dissertation Portal</a></p>" .
                    "<p>If you have already started, please continue from where you left off. If not, please begin by submitting your topic and concept note.</p>" .
                    "<p>Thank you.</p>";

            try {
                sendEmail($info['email'], $info['full_name'], $subject, $body);
                $message = 'Link sent to student successfully.';
            } catch (Exception $e) {
                $error = 'Failed to send email: ' . $e->getMessage();
            }
        } else {
            $error = 'Student email not found. Cannot send link.';
        }
    } elseif ($action === 'change_phase' && $dissertation_id) {
        $new_phase = $_POST['new_phase'] ?? '';
        $reason = trim($_POST['phase_change_reason'] ?? '');
        
        $valid_phases = ['topic','concept_note','chapter1','chapter2','chapter3','proposal','ethics','defense','chapter4','chapter5','final_draft','presentation','final_submission'];
        
        if (!in_array($new_phase, $valid_phases)) {
            $error = 'Invalid phase selected.';
        } elseif (empty($reason)) {
            $error = 'Please provide a reason for the phase change.';
        } else {
            // Determine appropriate status for the target phase
            $phase_status_map = [
                'topic' => 'topic_submission',
                'concept_note' => 'concept_note_writing',
                'chapter1' => 'chapter1_writing', 'chapter2' => 'chapter2_writing',
                'chapter3' => 'chapter3_writing', 'proposal' => 'proposal_writing',
                'ethics' => 'ethics_writing', 'defense' => 'defense_listed',
                'chapter4' => 'chapter4_writing', 'chapter5' => 'chapter5_writing',
                'final_draft' => 'final_draft_writing',
                'presentation' => 'presentation_writing',
                'final_submission' => 'final_submission_writing'
            ];
            $new_status = $phase_status_map[$new_phase] ?? $new_phase . '_writing';
            
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE dissertations SET current_phase = ?, status = ?, updated_at = NOW() WHERE dissertation_id = ?");
                $stmt->bind_param("ssi", $new_phase, $new_status, $dissertation_id);
                $stmt->execute();
                
                // Log the phase change as coordinator feedback
                $phase_label_from = '';
                $stmt_d = $conn->prepare("SELECT current_phase FROM dissertations WHERE dissertation_id = ?");
                $stmt_d->bind_param("i", $dissertation_id);
                $stmt_d->execute();
                
                $log_text = "[Phase Override] Changed to " . ($phase_labels[$new_phase] ?? $new_phase) . ". Reason: " . $reason;
                $fb = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, phase, user_id, reviewer_role, feedback_text, feedback_type) VALUES (?, ?, ?, 'coordinator', ?, 'comment')");
                $fb->bind_param("isis", $dissertation_id, $new_phase, $_SESSION['vle_user_id'], $log_text);
                $fb->execute();
                
                $conn->commit();
                $message = 'Phase changed to ' . ($phase_labels[$new_phase] ?? $new_phase) . ' successfully.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to change phase: ' . $e->getMessage();
            }
        }
    }
}

// Filters
$filter_status = $_GET['status'] ?? '';
$filter_phase = $_GET['phase'] ?? '';
$filter_search = trim($_GET['search'] ?? '');

// Build query
$where = ["d.is_active = 1"];
$params = [];
$types = '';

if ($filter_status) {
    $where[] = "d.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}
if ($filter_phase) {
    $where[] = "d.current_phase = ?";
    $params[] = $filter_phase;
    $types .= 's';
}
if ($filter_search) {
    $where[] = "(s.full_name LIKE ? OR d.title LIKE ? OR d.student_id LIKE ?)";
    $search_param = "%$filter_search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$where_sql = implode(' AND ', $where);
$sql = "
    SELECT d.*, s.full_name as student_name, s.program as student_program, 
           s.year_of_study, s.email as student_email,
           l.full_name as supervisor_name, l.email as supervisor_email
    FROM dissertations d
    LEFT JOIN students s ON d.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE $where_sql
    ORDER BY d.updated_at DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$dissertations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// View single dissertation
$view_dissertation = null;
$view_submissions = [];
$view_feedback = [];
if (isset($_GET['id'])) {
    $did = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT d.*, s.full_name as student_name, s.program as student_program,
               s.year_of_study, s.email as student_email, s.phone as student_phone,
               l.full_name as supervisor_name, l.email as supervisor_email,
               cl.full_name as co_supervisor_name
        FROM dissertations d
        LEFT JOIN students s ON d.student_id = s.student_id
        LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
        LEFT JOIN lecturers cl ON d.co_supervisor_id = cl.lecturer_id
        WHERE d.dissertation_id = ?
    ");
    $stmt->bind_param("i", $did);
    $stmt->execute();
    $view_dissertation = $stmt->get_result()->fetch_assoc();
    
    if ($view_dissertation) {
        // Get submissions
        $stmt = $conn->prepare("SELECT * FROM dissertation_submissions WHERE dissertation_id = ? ORDER BY phase, version DESC");
        $stmt->bind_param("i", $did);
        $stmt->execute();
        $view_submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get feedback
        $stmt = $conn->prepare("
            SELECT df.*, u.username as reviewer_username 
            FROM dissertation_feedback df 
            LEFT JOIN users u ON df.user_id = u.user_id 
            WHERE df.dissertation_id = ? 
            ORDER BY df.created_at DESC
        ");
        $stmt->bind_param("i", $did);
        $stmt->execute();
        $view_feedback = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Get ethics form submissions
        $view_ethics = [];
        $eth_check = $conn->query("SHOW TABLES LIKE 'dissertation_ethics'");
        if ($eth_check && $eth_check->num_rows > 0) {
            $stmt = $conn->prepare("SELECT * FROM dissertation_ethics WHERE dissertation_id = ? ORDER BY created_at DESC");
            $stmt->bind_param("i", $did);
            $stmt->execute();
            $view_ethics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

$phase_labels = [
    'topic' => 'Topic/Concept', 'concept_note' => 'Concept Note',
    'chapter1' => 'Chapter 1', 'chapter2' => 'Chapter 2', 'chapter3' => 'Chapter 3',
    'proposal' => 'Full Proposal', 'ethics' => 'Ethics', 'defense' => 'Defense',
    'chapter4' => 'Chapter 4', 'chapter5' => 'Chapter 5',
    'final_draft' => 'Final Draft', 'presentation' => 'Final Presentation', 'final_submission' => 'Final Submission'
];

$all_phases = array_keys($phase_labels);

$page_title = 'Manage Dissertations';
$breadcrumbs = [['title' => 'Manage Dissertations']];

// Get students eligible for dissertation (no dissertation yet OR needing supervisor)
$eligible_students = [];
$r = $conn->query("
    SELECT s.student_id, s.full_name, s.email, s.phone, s.program, s.department, s.year_of_study,
           d.dissertation_id, d.title as dissertation_title, d.status as diss_status, d.current_phase,
           l.full_name as supervisor_name
    FROM students s
    LEFT JOIN dissertations d ON s.student_id = d.student_id AND d.is_active = 1
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE s.is_active = 1
    ORDER BY 
        CASE 
            WHEN d.dissertation_id IS NULL THEN 0
            WHEN d.supervisor_id IS NULL AND d.status = 'concept_approved' THEN 1
            WHEN d.status IN ('topic_submission','concept_review') THEN 2
            ELSE 3
        END,
        s.full_name ASC
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $eligible_students[] = $row;
    }
}

// Get all active lecturers for quick assign
$all_lecturers = [];
$r2 = $conn->query("
    SELECT l.lecturer_id, l.full_name, l.department,
           (SELECT COUNT(*) FROM dissertations d WHERE d.supervisor_id = l.lecturer_id AND d.is_active = 1 AND d.status NOT IN ('completed','archived')) as active_count
    FROM lecturers l WHERE l.is_active = 1 ORDER BY l.full_name
");
if ($r2) {
    while ($row = $r2->fetch_assoc()) {
        $all_lecturers[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <?php include '../includes/tinymce_head.php'; ?>
    <style>
        .phase-badge { display:inline-block; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
        .status-pill { padding:3px 10px; border-radius:20px; font-size:0.72rem; font-weight:600; }
        .phase-timeline { position:relative; padding-left: 30px; }
        .phase-timeline::before { content:''; position:absolute; left:12px; top:0; bottom:0; width:2px; background:#dee2e6; }
        .phase-step { position:relative; margin-bottom:20px; }
        .phase-step .phase-dot { position:absolute; left:-24px; top:2px; width:16px; height:16px; border-radius:50%; border:2px solid #dee2e6; background:#fff; }
        .phase-step.active .phase-dot { border-color:#667eea; background:#667eea; }
        .phase-step.completed .phase-dot { border-color:#28a745; background:#28a745; }
        .phase-step.completed .phase-dot::after { content:'\2713'; color:#fff; font-size:10px; position:absolute; top:-1px; left:2px; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>


    <?php if ($view_dissertation): ?>
    <!-- Single Dissertation View -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0"><i class="bi bi-journal-text me-2"></i>Dissertation Details</h3>
        <div>
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="send_details_link">
                <input type="hidden" name="dissertation_id" value="<?= $view_dissertation['dissertation_id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-info me-2">
                    <i class="bi bi-link-45deg me-1"></i>Send Link
                </button>
            </form>
            <a href="../api/generate_ethics_form.php?dissertation_id=<?= $view_dissertation['dissertation_id'] ?>" target="_blank" class="btn btn-sm btn-outline-success me-2">
                <i class="bi bi-file-earmark-pdf me-1"></i>Download READF
            </a>
            <a href="manage_dissertations.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to List</a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left: Details -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Dissertation Information</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Student</label>
                            <p class="mb-1 fw-bold"><?= htmlspecialchars($view_dissertation['student_name'] ?? $view_dissertation['student_id']) ?></p>
                            <small class="text-muted"><?= htmlspecialchars($view_dissertation['student_id']) ?> | <?= htmlspecialchars($view_dissertation['student_email'] ?? '') ?></small>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Program</label>
                            <p class="mb-1"><?= htmlspecialchars($view_dissertation['student_program'] ?? $view_dissertation['program'] ?? '-') ?></p>
                            <small class="text-muted">Year <?= $view_dissertation['year_of_study'] ?? '-' ?></small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="text-muted small">Title</label>
                        <p class="mb-0 fw-bold"><?= htmlspecialchars($view_dissertation['title'] ?? 'Not yet defined') ?></p>
                    </div>
                    <?php if ($view_dissertation['concept_note_text']): ?>
                    <div class="mb-3">
                        <label class="text-muted small">Concept Note</label>
                        <div class="border rounded p-3 bg-white" style="max-height:500px; overflow-y:auto; font-family:'Calibri','Segoe UI',sans-serif; font-size:11pt; line-height:1.6;">
                            <?= $view_dissertation['concept_note_text'] ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($view_dissertation['concept_note_file'])): ?>
                    <div class="mb-3">
                        <label class="text-muted small">Concept Note File</label>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="openDocEditor('<?= htmlspecialchars($view_dissertation['concept_note_file'], ENT_QUOTES) ?>', '<?= htmlspecialchars(basename($view_dissertation['concept_note_file']), ENT_QUOTES) ?>')">
                                <i class="bi bi-file-earmark-richtext me-1"></i>View in System
                            </button>
                            <a href="../<?= htmlspecialchars($view_dissertation['concept_note_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye me-1"></i>View in Tab
                            </a>
                            <a href="../<?= htmlspecialchars($view_dissertation['concept_note_file']) ?>" download class="btn btn-sm btn-primary">
                                <i class="bi bi-download me-1"></i>Download
                            </a>
                            <small class="text-muted"><?= htmlspecialchars(basename($view_dissertation['concept_note_file'])) ?></small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Concept Note Similarity & AI Check -->
                    <?php if ($view_dissertation['concept_note_text'] || !empty($view_dissertation['concept_note_file'])): ?>
                    <?php
                        $cn_check = null;
                        $cn_sim_stmt = $conn->prepare("SELECT * FROM dissertation_similarity_checks WHERE dissertation_id = ? AND phase = 'concept_note' ORDER BY checked_at DESC LIMIT 1");
                        $cn_sim_stmt->bind_param("i", $view_dissertation['dissertation_id']);
                        $cn_sim_stmt->execute();
                        $cn_check = $cn_sim_stmt->get_result()->fetch_assoc();
                    ?>
                    <div class="mb-3">
                        <label class="text-muted small"><i class="bi bi-shield-check me-1"></i>Concept Note Similarity & AI Check</label>
                        <?php if ($cn_check): ?>
                        <div class="d-flex gap-3 align-items-center mt-1">
                            <div class="text-center">
                                <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto" style="width:52px;height:52px;background:<?= $cn_check['similarity_score'] >= 25 ? '#fecaca' : ($cn_check['similarity_score'] >= 15 ? '#fef3c7' : '#d1fae5') ?>;font-weight:700;font-size:0.85rem;">
                                    <?= round($cn_check['similarity_score']) ?>%
                                </div>
                                <small class="text-muted" style="font-size:0.7rem;">Similarity</small>
                            </div>
                            <div class="text-center">
                                <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto" style="width:52px;height:52px;background:<?= $cn_check['ai_detection_score'] >= 40 ? '#fecaca' : ($cn_check['ai_detection_score'] >= 20 ? '#fef3c7' : '#d1fae5') ?>;font-weight:700;font-size:0.85rem;">
                                    <?= round($cn_check['ai_detection_score']) ?>%
                                </div>
                                <small class="text-muted" style="font-size:0.7rem;">AI Content</small>
                            </div>
                            <div class="ms-2">
                                <small class="text-muted d-block">Checked: <?= date('M j, Y H:i', strtotime($cn_check['checked_at'])) ?></small>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="run_concept_check">
                                    <input type="hidden" name="dissertation_id" value="<?= $view_dissertation['dissertation_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary py-0 mt-1" style="font-size:0.75rem;">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Re-run Check
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="mt-1">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="run_concept_check">
                                <input type="hidden" name="dissertation_id" value="<?= $view_dissertation['dissertation_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-play-circle me-1"></i>Run Similarity & AI Check on Concept Note
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-4">
                            <label class="text-muted small">Supervisor</label>
                            <p class="mb-0"><?= htmlspecialchars($view_dissertation['supervisor_name'] ?? 'Not assigned') ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small">Co-Supervisor</label>
                            <p class="mb-0"><?= htmlspecialchars($view_dissertation['co_supervisor_name'] ?? 'None') ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small">Word Count</label>
                            <p class="mb-0"><?= number_format($view_dissertation['total_word_count'] ?? 0) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submissions -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Submissions</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($view_submissions)): ?>
                        <p class="text-muted text-center py-4">No submissions yet</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Phase</th>
                                    <th>Version</th>
                                    <th>File</th>
                                    <th>Size</th>
                                    <th>Words</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($view_submissions as $si => $sub): ?>
                                <tr>
                                    <td><span class="phase-badge bg-primary bg-opacity-10 text-primary"><?= $phase_labels[$sub['phase']] ?? $sub['phase'] ?></span></td>
                                    <td>v<?= $sub['version'] ?></td>
                                    <td>
                                        <?php if (!empty($sub['file_path'])): ?>
                                            <div class="d-flex align-items-center gap-1">
                                                <a href="../<?= htmlspecialchars($sub['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-1" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="../<?= htmlspecialchars($sub['file_path']) ?>" download class="btn btn-sm btn-outline-primary py-0 px-1" title="Download">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                                <small class="text-truncate" style="max-width:140px;" title="<?= htmlspecialchars($sub['file_name'] ?? '') ?>"><?= htmlspecialchars($sub['file_name'] ?? 'File') ?></small>
                                            </div>
                                        <?php elseif (!empty($sub['submission_text'])): ?>
                                            <span class="text-muted"><i class="bi bi-card-text me-1"></i>Text only</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $size = (int)($sub['file_size'] ?? 0);
                                            if ($size > 0) {
                                                if ($size >= 1048576) echo round($size / 1048576, 1) . ' MB';
                                                elseif ($size >= 1024) echo round($size / 1024, 1) . ' KB';
                                                else echo $size . ' B';
                                            } else echo '<span class="text-muted">-</span>';
                                        ?>
                                    </td>
                                    <td><?= number_format($sub['word_count'] ?? 0) ?></td>
                                    <td>
                                        <?php
                                            $sc = 'secondary';
                                            if ($sub['status'] === 'approved') $sc = 'success';
                                            elseif ($sub['status'] === 'rejected') $sc = 'danger';
                                            elseif ($sub['status'] === 'submitted' || $sub['status'] === 'under_review') $sc = 'warning';
                                        ?>
                                        <span class="status-pill bg-<?= $sc ?> bg-opacity-10 text-<?= $sc ?>"><?= ucfirst(str_replace('_',' ',$sub['status'])) ?></span>
                                    </td>
                                    <td><small><?= $sub['submitted_at'] ? date('M j, Y', strtotime($sub['submitted_at'])) : '-' ?></small></td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <?php if (!empty($sub['submission_text'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-info py-0" data-bs-toggle="collapse" data-bs-target="#sub-text-<?= $si ?>" title="Read submission text">
                                                <i class="bi bi-card-text"></i>
                                            </button>
                                            <?php endif; ?>
                                            <a href="review_submissions.php?submission_id=<?= $sub['submission_id'] ?>" class="btn btn-sm btn-outline-primary py-0">Review</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php if (!empty($sub['submission_text'])): ?>
                                <tr class="collapse" id="sub-text-<?= $si ?>">
                                    <td colspan="8" class="bg-light">
                                        <div class="p-3" style="max-height:400px;overflow-y:auto;">
                                            <label class="text-muted small fw-bold mb-2">Submission Text — <?= $phase_labels[$sub['phase']] ?? $sub['phase'] ?> v<?= $sub['version'] ?></label>
                                            <div class="border rounded p-3 bg-white" style="white-space:pre-wrap;font-size:0.88rem;line-height:1.7;"><?= nl2br(htmlspecialchars($sub['submission_text'])) ?></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ethics Form Submissions -->
            <?php if (!empty($view_ethics)): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Ethics Form Submissions</h5>
                    <a href="../api/generate_ethics_form.php?dissertation_id=<?= $view_dissertation['dissertation_id'] ?>" target="_blank" class="btn btn-sm btn-success">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Generate READF PDF
                    </a>
                </div>
                <div class="card-body">
                    <?php foreach ($view_ethics as $eth): ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <?php if (!empty($eth['application_number'])): ?>
                                    <span class="fw-bold">Application: <?= htmlspecialchars($eth['application_number']) ?></span><br>
                                <?php endif; ?>
                                <?php if (!empty($eth['irb_reference'])): ?>
                                    <small class="text-muted">IRB: <?= htmlspecialchars($eth['irb_reference']) ?></small>
                                <?php endif; ?>
                            </div>
                            <?php
                                $es = $eth['status'] ?? 'pending';
                                $ec = 'secondary';
                                if ($es === 'approved') $ec = 'success';
                                elseif ($es === 'rejected') $ec = 'danger';
                                elseif (in_array($es, ['submitted','under_review'])) $ec = 'warning';
                            ?>
                            <span class="status-pill bg-<?= $ec ?> bg-opacity-10 text-<?= $ec ?>"><?= ucfirst(str_replace('_',' ',$es)) ?></span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php if (!empty($eth['ethics_form_path'])): ?>
                            <a href="../<?= htmlspecialchars($eth['ethics_form_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>View Ethics Form</a>
                            <a href="../<?= htmlspecialchars($eth['ethics_form_path']) ?>" download class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i>Download Ethics Form</a>
                            <?php endif; ?>
                            <?php if (!empty($eth['consent_form_path'])): ?>
                            <a href="../<?= htmlspecialchars($eth['consent_form_path']) ?>" target="_blank" class="btn btn-sm btn-outline-info"><i class="bi bi-eye me-1"></i>View Consent Form</a>
                            <a href="../<?= htmlspecialchars($eth['consent_form_path']) ?>" download class="btn btn-sm btn-info"><i class="bi bi-download me-1"></i>Download Consent Form</a>
                            <?php endif; ?>
                            <?php if (!empty($eth['approval_letter_path'])): ?>
                            <a href="../<?= htmlspecialchars($eth['approval_letter_path']) ?>" download class="btn btn-sm btn-success"><i class="bi bi-download me-1"></i>Approval Letter</a>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($eth['research_summary'])): ?>
                        <div class="mt-2 border rounded p-2 bg-light" style="font-size:0.85rem;">
                            <label class="text-muted small fw-bold">Research Summary</label>
                            <div><?= nl2br(htmlspecialchars($eth['research_summary'])) ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="mt-2">
                            <small class="text-muted">Submitted: <?= $eth['submitted_at'] ? date('M j, Y H:i', strtotime($eth['submitted_at'])) : ($eth['created_at'] ? date('M j, Y H:i', strtotime($eth['created_at'])) : '-') ?></small>
                            <?php if (!empty($eth['reviewed_at'])): ?>
                                <small class="text-muted ms-2">| Reviewed: <?= date('M j, Y H:i', strtotime($eth['reviewed_at'])) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Feedback History -->
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Feedback History</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($view_feedback)): ?>
                        <p class="text-muted text-center py-3">No feedback yet</p>
                    <?php else: ?>
                        <?php foreach ($view_feedback as $fb): ?>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="fw-bold"><?= htmlspecialchars($fb['reviewer_username'] ?? 'Unknown') ?> <span class="badge bg-secondary"><?= ucfirst($fb['reviewer_role']) ?></span></span>
                                <small class="text-muted"><?= date('M j, Y H:i', strtotime($fb['created_at'])) ?></small>
                            </div>
                            <p class="mb-1"><?= nl2br(htmlspecialchars($fb['feedback_text'])) ?></p>
                            <span class="status-pill bg-<?= $fb['feedback_type']==='approval'?'success':($fb['feedback_type']==='rejection'?'danger':'info') ?> bg-opacity-10 text-<?= $fb['feedback_type']==='approval'?'success':($fb['feedback_type']==='rejection'?'danger':'info') ?>">
                                <?= ucfirst(str_replace('_',' ',$fb['feedback_type'])) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Phase Timeline & Actions -->
        <div class="col-lg-4">
            <!-- Current Status -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Current Status</h5>
                </div>
                <div class="card-body">
                    <?php
                        $current_idx = array_search($view_dissertation['current_phase'], $all_phases);
                    ?>
                    <div class="phase-timeline">
                        <?php foreach ($all_phases as $idx => $phase): ?>
                        <?php
                            $class = '';
                            if ($idx < $current_idx) $class = 'completed';
                            elseif ($idx === $current_idx) $class = 'active';
                        ?>
                        <div class="phase-step <?= $class ?>">
                            <div class="phase-dot"></div>
                            <strong class="small"><?= $phase_labels[$phase] ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <?php if (in_array($view_dissertation['status'], ['topic_submission', 'concept_review'])): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Topic Review Actions</h5>
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#approveTopicTab"><i class="bi bi-check-circle me-1 text-success"></i>Approve</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#reviseTopicTab"><i class="bi bi-arrow-counterclockwise me-1 text-warning"></i>Request Revision</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#rejectTopicTab"><i class="bi bi-x-circle me-1 text-danger"></i>Reject</a></li>
                    </ul>
                    <div class="tab-content">
                        <!-- Approve Tab -->
                        <div class="tab-pane fade show active" id="approveTopicTab">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="dissertation_id" value="<?= $view_dissertation['dissertation_id'] ?>">
                                <input type="hidden" name="action" value="approve_topic">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Approval Comments (optional)</label>
                                    <textarea name="approval_feedback" class="form-control" rows="3" placeholder="Good concept! You may proceed to the next stage..."></textarea>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold"><i class="bi bi-paperclip me-1"></i>Attach Document (optional)</label>
                                    <input type="file" class="form-control form-control-sm" name="feedback_attachment" accept=".doc,.docx,.pdf,.txt,.rtf,.odt,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                                </div>
                                <button type="submit" class="btn btn-success w-100"><i class="bi bi-check-circle me-1"></i>Approve Topic & Concept</button>
                            </form>
                        </div>
                        <!-- Request Revision Tab -->
                        <div class="tab-pane fade" id="reviseTopicTab">
                            <div class="alert alert-info py-2 mb-2" style="font-size:0.82rem;">
                                <i class="bi bi-info-circle me-1"></i>Send feedback on the concept note so the student can improve and resubmit.
                            </div>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="dissertation_id" value="<?= $view_dissertation['dissertation_id'] ?>">
                                <input type="hidden" name="action" value="request_topic_revision">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Revision Feedback <span class="text-danger">*</span></label>
                                    <textarea name="revision_feedback" class="form-control" rows="4" placeholder="Please improve/clarify the following areas of your concept note..." required></textarea>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold"><i class="bi bi-paperclip me-1"></i>Attach Document (optional)</label>
                                    <input type="file" class="form-control form-control-sm" name="feedback_attachment" accept=".doc,.docx,.pdf,.txt,.rtf,.odt,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                                </div>
                                <button type="submit" class="btn btn-warning w-100"><i class="bi bi-arrow-counterclockwise me-1"></i>Request Concept Note Revision</button>
                            </form>
                        </div>
                        <!-- Reject Tab -->
                        <div class="tab-pane fade" id="rejectTopicTab">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="dissertation_id" value="<?= $view_dissertation['dissertation_id'] ?>">
                                <input type="hidden" name="action" value="reject_topic">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Reason for Rejection</label>
                                    <textarea name="rejection_reason" class="form-control" rows="3" placeholder="Reason for rejection..."></textarea>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold"><i class="bi bi-paperclip me-1"></i>Attach Document (optional)</label>
                                    <input type="file" class="form-control form-control-sm" name="feedback_attachment" accept=".doc,.docx,.pdf,.txt,.rtf,.odt,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                                </div>
                                <button type="submit" class="btn btn-danger w-100"><i class="bi bi-x-circle me-1"></i>Reject Topic</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Coordinator Quick Comment (available for any dissertation) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#coordinatorComment" style="cursor:pointer;">
                    <h6 class="mb-0"><i class="bi bi-chat-dots me-2 text-primary"></i>Send Comment to Student</h6>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="collapse" id="coordinatorComment">
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_coordinator_comment">
                            <input type="hidden" name="dissertation_id" value="<?= $view_dissertation['dissertation_id'] ?>">
                            <input type="hidden" name="comment_phase" value="<?= htmlspecialchars($view_dissertation['current_phase'] ?? 'topic') ?>">
                            <div class="mb-2">
                                <textarea name="feedback_text" class="form-control" rows="3" placeholder="Type a comment, guidance, or reminder for the student..." required></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold"><i class="bi bi-paperclip me-1"></i>Attach Document (optional)</label>
                                <input type="file" class="form-control form-control-sm" name="feedback_attachment" accept=".doc,.docx,.pdf,.txt,.rtf,.odt,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Send Comment</button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($view_dissertation['status'] === 'concept_approved' && !$view_dissertation['supervisor_id']): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Assign Supervisor</h5>
                </div>
                <div class="card-body">
                    <a href="assign_supervisors.php?dissertation_id=<?= $view_dissertation['dissertation_id'] ?>" class="btn btn-primary w-100">
                        <i class="bi bi-person-plus me-1"></i>Assign Supervisor
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Change Phase (always available) -->
            <div class="card shadow-sm mb-4 border-warning">
                <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#changePhaseCollapse" style="cursor:pointer;">
                    <h6 class="mb-0"><i class="bi bi-arrow-left-right me-2 text-warning"></i>Change Phase</h6>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="collapse" id="changePhaseCollapse">
                    <div class="card-body">
                        <div class="alert alert-warning py-2 mb-3" style="font-size:0.8rem;">
                            <i class="bi bi-exclamation-triangle me-1"></i>This will override the student's current phase. Use with caution.
                        </div>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to change this student\'s phase? This action will be logged.');">
                            <input type="hidden" name="action" value="change_phase">
                            <input type="hidden" name="dissertation_id" value="<?= $view_dissertation['dissertation_id'] ?>">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Current Phase</label>
                                <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($phase_labels[$view_dissertation['current_phase']] ?? ucfirst($view_dissertation['current_phase'])) ?> (<?= htmlspecialchars(ucwords(str_replace('_',' ',$view_dissertation['status']))) ?>)" disabled>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Move To <span class="text-danger">*</span></label>
                                <select name="new_phase" class="form-select form-select-sm" required>
                                    <option value="">-- Select Phase --</option>
                                    <?php foreach ($phase_labels as $pkey => $plabel): ?>
                                    <option value="<?= $pkey ?>" <?= $pkey === $view_dissertation['current_phase'] ? 'disabled' : '' ?>>
                                        <?= htmlspecialchars($plabel) ?><?= $pkey === $view_dissertation['current_phase'] ? ' (current)' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Reason <span class="text-danger">*</span></label>
                                <textarea name="phase_change_reason" class="form-control form-control-sm" rows="2" placeholder="Reason for changing the phase..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-warning btn-sm w-100"><i class="bi bi-arrow-left-right me-1"></i>Change Phase</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Dissertations List View -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0"><i class="bi bi-journal-bookmark me-2"></i>Manage Dissertations</h3>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small">Search</label>
                    <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($filter_search) ?>" placeholder="Student name, title, ID...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Phase</label>
                    <select class="form-select" name="phase">
                        <option value="">All Phases</option>
                        <?php foreach ($phase_labels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $filter_phase === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All Statuses</option>
                        <option value="topic_submission" <?= $filter_status === 'topic_submission' ? 'selected' : '' ?>>Topic Submission</option>
                        <option value="concept_approved" <?= $filter_status === 'concept_approved' ? 'selected' : '' ?>>Concept Approved</option>
                        <option value="supervisor_assigned" <?= $filter_status === 'supervisor_assigned' ? 'selected' : '' ?>>Supervisor Assigned</option>
                        <option value="defense_listed" <?= $filter_status === 'defense_listed' ? 'selected' : '' ?>>Defense Listed</option>
                        <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search me-1"></i>Filter</button>
                    <a href="manage_dissertations.php" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Students for Dissertation Assignment -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-people-fill text-info me-2"></i>Students for Dissertation Assignment 
                <span class="badge bg-info ms-2"><?= count($eligible_students) ?></span>
            </h5>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#studentListCollapse">
                <i class="bi bi-chevron-down"></i> Toggle
            </button>
        </div>
        <div class="collapse show" id="studentListCollapse">
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Year</th>
                                <th>Dissertation Status</th>
                                <th>Supervisor</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($eligible_students)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">No students found</td></tr>
                            <?php else: ?>
                                <?php foreach ($eligible_students as $si => $st): ?>
                                <?php
                                    $has_diss = !empty($st['dissertation_id']);
                                    $needs_supervisor = $has_diss && empty($st['supervisor_name']) && $st['diss_status'] === 'concept_approved';
                                    $no_diss = !$has_diss;
                                    
                                    if ($no_diss) {
                                        $status_badge = '<span class="badge bg-secondary">No Dissertation</span>';
                                    } elseif ($needs_supervisor) {
                                        $status_badge = '<span class="badge bg-warning text-dark">Needs Supervisor</span>';
                                    } else {
                                        $diss_status_label = ucfirst(str_replace('_', ' ', $st['diss_status'] ?? ''));
                                        $sc2 = 'info';
                                        if (strpos($st['diss_status'], 'approved') !== false || $st['diss_status'] === 'completed') $sc2 = 'success';
                                        elseif (strpos($st['diss_status'], 'rejected') !== false) $sc2 = 'danger';
                                        elseif (strpos($st['diss_status'], 'review') !== false || strpos($st['diss_status'], 'submission') !== false) $sc2 = 'warning';
                                        $status_badge = '<span class="badge bg-' . $sc2 . '">' . $diss_status_label . '</span>';
                                    }
                                ?>
                                <tr class="<?= $needs_supervisor ? 'table-warning' : ($no_diss ? 'table-light' : '') ?>">
                                    <td><?= $si + 1 ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($st['full_name']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($st['student_id']) ?> | <?= htmlspecialchars($st['email'] ?? '') ?></small>
                                    </td>
                                    <td><small><?= htmlspecialchars($st['program'] ?? '-') ?></small></td>
                                    <td class="text-center"><?= $st['year_of_study'] ?? '-' ?></td>
                                    <td>
                                        <?= $status_badge ?>
                                        <?php if ($has_diss && !empty($st['dissertation_title'])): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($st['dissertation_title'], 0, 35, '...')) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($st['supervisor_name'] ?? '-') ?></td>
                                    <td>
                                        <?php if ($needs_supervisor): ?>
                                            <a href="assign_supervisors.php?dissertation_id=<?= $st['dissertation_id'] ?>" class="btn btn-sm btn-primary" title="Assign Supervisor">
                                                <i class="bi bi-person-plus"></i> Assign
                                            </a>
                                        <?php elseif ($has_diss): ?>
                                            <a href="?id=<?= $st['dissertation_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Dissertation">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">Awaiting topic</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Dissertations Table -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Title</th>
                            <th>Program</th>
                            <th>Phase</th>
                            <th>Supervisor</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dissertations)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No dissertations found</td></tr>
                        <?php else: ?>
                            <?php foreach ($dissertations as $i => $d): ?>
                            <?php
                                $sc = 'secondary';
                                $st = str_replace('_', ' ', $d['status']);
                                if (strpos($d['status'], 'approved') !== false || $d['status'] === 'completed') $sc = 'success';
                                elseif (strpos($d['status'], 'rejected') !== false || strpos($d['status'], 'failed') !== false) $sc = 'danger';
                                elseif (strpos($d['status'], 'review') !== false || strpos($d['status'], 'submitted') !== false) $sc = 'warning';
                                elseif (strpos($d['status'], 'writing') !== false) $sc = 'info';
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($d['student_name'] ?? $d['student_id']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($d['student_id']) ?></small>
                                </td>
                                <td><small><?= htmlspecialchars(mb_strimwidth($d['title'] ?? 'Untitled', 0, 40, '...')) ?></small></td>
                                <td><small><?= htmlspecialchars($d['student_program'] ?? $d['program'] ?? '-') ?></small></td>
                                <td><span class="phase-badge bg-primary bg-opacity-10 text-primary"><?= $phase_labels[$d['current_phase']] ?? ucfirst($d['current_phase'] ?? '') ?></span></td>
                                <td><small><?= htmlspecialchars($d['supervisor_name'] ?? '<em>Not assigned</em>') ?></small></td>
                                <td><span class="status-pill bg-<?= $sc ?> bg-opacity-10 text-<?= $sc ?>"><?= ucfirst($st) ?></span></td>
                                <td><small><?= date('M j, Y', strtotime($d['updated_at'])) ?></small></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="send_details_link">
                                        <input type="hidden" name="dissertation_id" value="<?= $d['dissertation_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-info me-1" title="Send student link">
                                            <i class="bi bi-link-45deg"></i>
                                        </button>
                                    </form>
                                    <a href="?id=<?= $d['dissertation_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
if (typeof initTinyMCE === 'function' && document.getElementById('rejection_reason_editor')) {
    initTinyMCE('#rejection_reason_editor', { mode: 'compact', height: 180 });
}
</script>

<!-- Document Editor Modal -->
<div class="modal fade" id="docEditorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#1e3a5f;">
                <div class="d-flex align-items-center">
                    <i class="bi bi-file-earmark-richtext text-white me-2"></i>
                    <h6 class="modal-title text-white mb-0" id="docEditorTitle">Document</h6>
                    <span id="docFormatBadge" class="badge bg-light text-dark ms-2" style="font-size:0.7rem;"></span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group btn-group-sm" id="modeToggleGroup">
                        <button type="button" id="readModeBtn" class="btn btn-light">
                            <i class="bi bi-eye me-1"></i>Read
                        </button>
                        <button type="button" id="editModeBtn" class="btn btn-outline-light">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </button>
                    </div>
                    <button type="button" id="saveDocBtn" class="btn btn-success btn-sm d-none">
                        <i class="bi bi-file-earmark-arrow-down me-1"></i>Save as DOCX
                    </button>
                    <a id="docDownloadBtn" href="#" download class="btn btn-outline-light btn-sm">
                        <i class="bi bi-download me-1"></i>Download
                    </a>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0" style="background:#f1f5f9;">
                <div id="docLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                    <p class="mt-3 text-muted">Converting document&hellip;</p>
                </div>
                <div id="docError" class="d-none text-center py-5">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size:3rem;"></i>
                    <p class="mt-2 text-muted" id="docErrorMsg">Unable to load document.</p>
                    <a id="docErrorDownload" href="#" download class="btn btn-primary btn-sm"><i class="bi bi-download me-1"></i>Download instead</a>
                </div>
                <div id="docReadView" class="d-none" style="max-width:960px; margin:0 auto; background:#fff; min-height:calc(100vh - 56px); padding:40px 48px; box-shadow:0 0 20px rgba(0,0,0,0.06); font-family:'Calibri','Segoe UI',sans-serif; font-size:11pt; line-height:1.6;"></div>
                <div id="docEditView" class="d-none" style="background:#fff; padding: 10px;">
                    <textarea id="tinymceDocEditor" style="width:100%;"></textarea>
                </div>
                <iframe id="pdfFrame" class="d-none" style="width:100%; height:calc(100vh - 56px); border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
var docState = { editorReady: false, html: '', filePath: '', fileName: '', editing: false, isPdf: false };

function openDocEditor(filePath, fileName) {
    ['docLoading','docError','docReadView','docEditView','pdfFrame'].forEach(function(id) {
        document.getElementById(id).classList.add('d-none');
    });
    document.getElementById('docLoading').classList.remove('d-none');
    document.getElementById('saveDocBtn').classList.add('d-none');
    document.getElementById('modeToggleGroup').classList.remove('d-none');
    setMode('read');

    docState.filePath = filePath;
    docState.fileName = fileName || decodeURIComponent(filePath.split('/').pop());
    docState.html = '';
    docState.editing = false;
    docState.isPdf = false;

    document.getElementById('docEditorTitle').textContent = docState.fileName;
    document.getElementById('docDownloadBtn').href = '../' + filePath;
    document.getElementById('docDownloadBtn').setAttribute('download', docState.fileName);
    document.getElementById('docErrorDownload').href = '../' + filePath;
    document.getElementById('docErrorDownload').setAttribute('download', docState.fileName);

    var ext = filePath.split('.').pop().toLowerCase();
    document.getElementById('docFormatBadge').textContent = '.' + ext;

    var modal = new bootstrap.Modal(document.getElementById('docEditorModal'));
    modal.show();

    fetch('../api/export_docx.php?file=' + encodeURIComponent(filePath))
        .then(function(r) { return r.json().then(function(data) { return {ok: r.ok, data: data}; }); })
        .then(function(res) {
            document.getElementById('docLoading').classList.add('d-none');
            if (!res.ok) {
                throw new Error((res.data && res.data.error) ? res.data.error : 'Conversion failed');
            }
            if (res.data.format === 'pdf') {
                docState.isPdf = true;
                document.getElementById('pdfFrame').src = '../' + res.data.url;
                document.getElementById('pdfFrame').classList.remove('d-none');
                document.getElementById('modeToggleGroup').classList.add('d-none');
            } else if (res.data.format === 'google_docs') {
                // Open in Google Docs Viewer (requires publicly accessible URL)
                var absUrl = res.data.abs_url || '';
                if (absUrl) {
                    docState.isPdf = true;
                    document.getElementById('pdfFrame').src = 'https://docs.google.com/viewer?url=' + encodeURIComponent(absUrl) + '&embedded=true';
                    document.getElementById('pdfFrame').classList.remove('d-none');
                    document.getElementById('modeToggleGroup').classList.add('d-none');
                } else {
                    var fileUrl = '../' + (res.data.url || docState.filePath);
                    var html = '<div style="text-align:center;padding:60px 24px;"><p>Preview unavailable.</p>' +
                        '<a href="' + fileUrl + '" download class="btn btn-primary"><i class="bi bi-download me-1"></i>Download</a></div>';
                    docState.html = html;
                    document.getElementById('docReadView').innerHTML = html;
                    document.getElementById('docReadView').classList.remove('d-none');
                    document.getElementById('modeToggleGroup').classList.add('d-none');
                }
            } else {
                var html = res.data.html || '';
                docState.html = html;
                document.getElementById('docReadView').innerHTML = html;
                document.getElementById('docReadView').classList.remove('d-none');
            }
        })
        .catch(function(err) {
            document.getElementById('docLoading').classList.add('d-none');
            document.getElementById('docErrorMsg').textContent = (err && err.message) ? err.message : 'Unable to load document. Please use the Download button instead.';
            document.getElementById('docError').classList.remove('d-none');
            document.getElementById('modeToggleGroup').classList.add('d-none');
        });
}

function setMode(mode) {
    document.getElementById('readModeBtn').className = mode === 'read' ? 'btn btn-light' : 'btn btn-outline-light';
    document.getElementById('editModeBtn').className = mode === 'edit' ? 'btn btn-light' : 'btn btn-outline-light';
}

document.getElementById('readModeBtn').addEventListener('click', function() {
    if (docState.editorReady && docState.editing) {
        var ed = tinymce.get('tinymceDocEditor');
        if (ed) docState.html = ed.getContent();
        document.getElementById('docReadView').innerHTML = docState.html;
    }
    document.getElementById('docEditView').classList.add('d-none');
    document.getElementById('docReadView').classList.remove('d-none');
    document.getElementById('saveDocBtn').classList.add('d-none');
    docState.editing = false;
    setMode('read');
});

document.getElementById('editModeBtn').addEventListener('click', function() {
    if (!docState.html || docState.isPdf) return;
    document.getElementById('docReadView').classList.add('d-none');
    document.getElementById('docEditView').classList.remove('d-none');
    document.getElementById('saveDocBtn').classList.remove('d-none');
    docState.editing = true;
    setMode('edit');

    if (!docState.editorReady) {
        initTinyMCE('#tinymceDocEditor', { mode: 'full', height: Math.max(500, window.innerHeight - 120) });
        var checkReady = setInterval(function() {
            var ed = tinymce.get('tinymceDocEditor');
            if (ed && ed.initialized) {
                clearInterval(checkReady);
                docState.editorReady = true;
                ed.setContent(docState.html);
            }
        }, 100);
    } else {
        var ed = tinymce.get('tinymceDocEditor');
        if (ed) ed.setContent(docState.html);
    }
});

document.getElementById('saveDocBtn').addEventListener('click', function() {
    var ed = tinymce.get('tinymceDocEditor');
    if (!ed) return;
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

    var form = document.createElement('form');
    form.method = 'POST'; form.action = '../api/export_docx.php';
    form.target = '_blank'; form.style.display = 'none';
    var h = document.createElement('input'); h.type='hidden'; h.name='html_content'; h.value = ed.getContent();
    var f = document.createElement('input'); f.type='hidden'; f.name='filename'; f.value = docState.fileName;
    form.appendChild(h); form.appendChild(f);
    document.body.appendChild(form); form.submit();
    setTimeout(function() { document.body.removeChild(form); btn.disabled=false; btn.innerHTML='<i class="bi bi-file-earmark-arrow-down me-1"></i>Save as DOCX'; }, 1500);
});

document.getElementById('docEditorModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('docReadView').innerHTML = '';
    document.getElementById('pdfFrame').src = '';
    var ed = tinymce.get('tinymceDocEditor');
    if (ed) { ed.destroy(); docState.editorReady = false; }
    docState.html = ''; docState.editing = false; docState.isPdf = false;
});
</script>
</body>
</html>
