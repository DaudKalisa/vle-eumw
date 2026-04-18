<?php
/**
 * Lecturer Dissertation Supervision
 * View assigned students, review submissions, provide feedback, approve/request revision
 * Features: file download, in-system document viewer/editor, email notifications, revision loop
 */
session_start();
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['lecturer', 'admin']);

$user = getCurrentUser();
$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'] ?? null;
$user_id = $_SESSION['vle_user_id'] ?? 0;
$lecturer_name = $user['full_name'] ?? $user['username'] ?? 'Supervisor';
$message = '';
$error = '';

if (!$lecturer_id) {
    $uid = $_SESSION['vle_user_id'] ?? 0;
    $r = $conn->query("SELECT lecturer_id FROM lecturers WHERE email = '" . $conn->real_escape_string($user['email'] ?? '') . "'");
    if ($r && $row = $r->fetch_assoc()) $lecturer_id = $row['lecturer_id'];
}

// Helper: send email notification to student
function notifyStudent($conn, $student_email, $student_name, $subject, $body_html) {
    if (function_exists('sendEmail') && function_exists('isEmailEnabled') && isEmailEnabled() && !empty($student_email)) {
        try {
            sendEmail($student_email, $student_name, $subject, $body_html);
        } catch (Exception $e) {
            // Silently fail - email is best effort
        }
    }
}

// Helper: handle feedback file attachment upload
function handleFeedbackAttachment($dissertation_id) {
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

$phase_config = [
    'topic' => 'Topic', 'concept_note' => 'Concept Note',
    'chapter1' => 'Ch 1 - Introduction', 'chapter2' => 'Ch 2 - Literature Review',
    'chapter3' => 'Ch 3 - Methodology', 'proposal' => 'Full Proposal',
    'ethics' => 'Ethics', 'defense' => 'Defense',
    'chapter4' => 'Ch 4 - Results', 'chapter5' => 'Ch 5 - Conclusions',
    'final_draft' => 'Final Draft', 'presentation' => 'Final Result Presentation', 'final_submission' => 'Final'
];

// Phase advancement map
$next_phase = [
    'chapter1' => 'chapter2', 'chapter2' => 'chapter3', 'chapter3' => 'proposal',
    'proposal' => 'ethics', 'ethics' => 'defense',
    'chapter4' => 'chapter5', 'chapter5' => 'final_draft', 'final_draft' => 'presentation', 'presentation' => 'final_submission'
];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $submission_id = (int)($_POST['submission_id'] ?? 0);
    $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
    
    if ($action === 'approve' && $submission_id) {
        $feedback_text = trim($_POST['feedback_text'] ?? '');
        
        $stmt = $conn->prepare("
            SELECT ds.*, d.current_phase, d.title as diss_title, d.student_id,
                   s.full_name as student_name, s.email as student_email
            FROM dissertation_submissions ds 
            JOIN dissertations d ON ds.dissertation_id = d.dissertation_id 
            LEFT JOIN students s ON d.student_id = s.student_id
            WHERE ds.submission_id = ? AND d.supervisor_id = ?
        ");
        $stmt->bind_param("ii", $submission_id, $lecturer_id);
        $stmt->execute();
        $sub = $stmt->get_result()->fetch_assoc();
        
        if ($sub) {
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE dissertation_submissions SET status = 'approved', reviewed_at = NOW(), reviewed_by = $user_id WHERE submission_id = $submission_id");
                
                if (!empty($feedback_text)) {
                    $attachment_path = handleFeedbackAttachment($sub['dissertation_id']);
                    $stmt2 = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, submission_id, user_id, reviewer_role, phase, feedback_type, feedback_text, attachment_path) VALUES (?, ?, ?, 'supervisor', ?, 'approval', ?, ?)");
                    $phase = $sub['phase'];
                    $stmt2->bind_param("iiisss", $sub['dissertation_id'], $submission_id, $user_id, $phase, $feedback_text, $attachment_path);
                    $stmt2->execute();
                }
                
                $cur = $sub['phase'];
                $next_phase_name = '';
                if (isset($next_phase[$cur])) {
                    $np = $next_phase[$cur];
                    $next_phase_name = $phase_config[$np] ?? ucfirst($np);
                    $new_status = $np . '_writing';
                    if ($np === 'defense') $new_status = 'defense_listed';
                    
                    // Presentation approved by supervisor → list for final defense (RC schedules & grades)
                    if ($cur === 'presentation') {
                        $new_status = 'defense_listed';
                        $next_phase_name = 'Final Presentation Defense';
                        $conn->query("UPDATE dissertations SET status = 'defense_listed', updated_at = NOW() WHERE dissertation_id = {$sub['dissertation_id']}");
                    } else {
                        $conn->query("UPDATE dissertations SET current_phase = '$np', status = '$new_status', updated_at = NOW() WHERE dissertation_id = {$sub['dissertation_id']}");
                    }
                }
                
                $conn->commit();
                $message = 'Submission approved and student advanced to next phase.';
                
                // Email notification to student
                $phase_label = $phase_config[$cur] ?? ucfirst($cur);
                $email_body = "
                    <h2 style='color: #10b981;'>✅ {$phase_label} Approved!</h2>
                    <p>Dear <strong>{$sub['student_name']}</strong>,</p>
                    <p>Your supervisor <strong>{$lecturer_name}</strong> has <span style='color:#10b981;font-weight:700;'>approved</span> your submission for <strong>{$phase_label}</strong> (Version {$sub['version']}).</p>
                    " . (!empty($feedback_text) ? "<div style='background:#f0fdf4; border-left:4px solid #10b981; padding:12px; margin:16px 0;'><strong>Supervisor Comments:</strong><br>" . nl2br(htmlspecialchars($feedback_text)) . "</div>" : "") . "
                    " . (!empty($next_phase_name) ? "<p>You may now proceed to <strong>{$next_phase_name}</strong>. Please log in to the VLE to begin your next chapter.</p>" : "<p>Congratulations! Please log in to the VLE for next steps.</p>") . "
                    <p style='margin-top:20px;'><a href='" . (defined('SYSTEM_URL') ? SYSTEM_URL : '') . "/student/dissertation.php' style='background:#2563eb; color:white; padding:10px 24px; text-decoration:none; border-radius:6px;'>Open Dissertation Portal</a></p>
                ";
                notifyStudent($conn, $sub['student_email'], $sub['student_name'], "Dissertation: {$phase_label} Approved - " . ($sub['diss_title'] ?? ''), $email_body);
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to process approval: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'revision' && $submission_id) {
        $feedback_text = trim($_POST['feedback_text'] ?? '');
        $raw_sections = trim($_POST['feedback_sections'] ?? '');
        // Convert flagged sections to JSON array (column has json_valid CHECK constraint)
        $feedback_sections = null;
        if (!empty($raw_sections)) {
            $lines = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $raw_sections)));
            if (!empty($lines)) $feedback_sections = json_encode(array_values($lines));
        }
        
        if (empty($feedback_text)) {
            $error = 'Please provide feedback for the revision request.';
        } else {
            $stmt = $conn->prepare("
                SELECT ds.*, d.title as diss_title, d.student_id,
                       s.full_name as student_name, s.email as student_email
                FROM dissertation_submissions ds 
                JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
                LEFT JOIN students s ON d.student_id = s.student_id 
                WHERE ds.submission_id = ? AND d.supervisor_id = ?
            ");
            $stmt->bind_param("ii", $submission_id, $lecturer_id);
            $stmt->execute();
            $sub = $stmt->get_result()->fetch_assoc();
            
            if ($sub) {
                $conn->begin_transaction();
                try {
                    $conn->query("UPDATE dissertation_submissions SET status = 'revision_requested', reviewed_at = NOW(), reviewed_by = $user_id WHERE submission_id = $submission_id");
                    
                    $attachment_path = handleFeedbackAttachment($sub['dissertation_id']);
                    $stmt2 = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, submission_id, user_id, reviewer_role, phase, feedback_type, feedback_text, flagged_sections, attachment_path) VALUES (?, ?, ?, 'supervisor', ?, 'revision_request', ?, ?, ?)");
                    $phase = $sub['phase'];
                    $stmt2->bind_param("iiissss", $sub['dissertation_id'], $submission_id, $user_id, $phase, $feedback_text, $feedback_sections, $attachment_path);
                    $stmt2->execute();
                    
                    $new_status = $sub['phase'] . '_revision';
                    $conn->query("UPDATE dissertations SET status = '$new_status', updated_at = NOW() WHERE dissertation_id = {$sub['dissertation_id']}");
                    
                    $conn->commit();
                    $message = 'Revision requested. The student has been notified via email.';
                    
                    // Email notification to student
                    $phase_label = $phase_config[$sub['phase']] ?? ucfirst($sub['phase']);
                    $email_body = "
                        <h2 style='color: #f59e0b;'>📝 Revision Requested - {$phase_label}</h2>
                        <p>Dear <strong>{$sub['student_name']}</strong>,</p>
                        <p>Your supervisor <strong>{$lecturer_name}</strong> has reviewed your submission for <strong>{$phase_label}</strong> (Version {$sub['version']}) and is requesting revisions.</p>
                        <div style='background:#fffbeb; border-left:4px solid #f59e0b; padding:12px; margin:16px 0;'>
                            <strong>Feedback from Supervisor:</strong><br>" . nl2br(htmlspecialchars($feedback_text)) . "
                        </div>
                        " . (!empty($raw_sections) ? "<div style='background:#fef2f2; border-left:4px solid #ef4444; padding:12px; margin:16px 0;'><strong>Sections Flagged:</strong><br>" . nl2br(htmlspecialchars($raw_sections)) . "</div>" : "") . "
                        <p><strong>What to do:</strong></p>
                        <ol>
                            <li>Read the feedback carefully</li>
                            <li>Address all the comments and suggestions</li>
                            <li>Resubmit your revised work through the VLE</li>
                        </ol>
                        <p style='margin-top:20px;'><a href='" . (defined('SYSTEM_URL') ? SYSTEM_URL : '') . "/student/dissertation.php' style='background:#f59e0b; color:white; padding:10px 24px; text-decoration:none; border-radius:6px;'>Revise & Resubmit</a></p>
                    ";
                    notifyStudent($conn, $sub['student_email'], $sub['student_name'], "Dissertation: Revision Requested - {$phase_label}", $email_body);
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = 'Failed to process revision request: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'add_comment' && $dissertation_id) {
        // Quick comment without changing status
        $feedback_text = trim($_POST['feedback_text'] ?? '');
        $comment_phase = trim($_POST['comment_phase'] ?? '');
        
        if (!empty($feedback_text)) {
            $stmt = $conn->prepare("
                SELECT d.*, s.full_name as student_name, s.email as student_email
                FROM dissertations d
                LEFT JOIN students s ON d.student_id = s.student_id
                WHERE d.dissertation_id = ? AND (d.supervisor_id = ? OR d.co_supervisor_id = ?)
            ");
            $stmt->bind_param("iii", $dissertation_id, $lecturer_id, $lecturer_id);
            $stmt->execute();
            $diss_info = $stmt->get_result()->fetch_assoc();
            
            if ($diss_info) {
                $attachment_path = handleFeedbackAttachment($dissertation_id);
                $stmt2 = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, user_id, reviewer_role, phase, feedback_type, feedback_text, attachment_path) VALUES (?, ?, 'supervisor', ?, 'comment', ?, ?)");
                $stmt2->bind_param("iisss", $dissertation_id, $user_id, $comment_phase, $feedback_text, $attachment_path);
                if ($stmt2->execute()) {
                    $message = 'Comment added successfully.';
                    
                    // Email notification
                    $phase_label = $phase_config[$comment_phase] ?? ucfirst($comment_phase);
                    $email_body = "
                        <h2 style='color: #2563eb;'>💬 New Comment from Supervisor</h2>
                        <p>Dear <strong>{$diss_info['student_name']}</strong>,</p>
                        <p>Your supervisor <strong>{$lecturer_name}</strong> has left a comment on your <strong>{$phase_label}</strong>:</p>
                        <div style='background:#eff6ff; border-left:4px solid #2563eb; padding:12px; margin:16px 0;'>" . nl2br(htmlspecialchars($feedback_text)) . "</div>
                        <p style='margin-top:20px;'><a href='" . (defined('SYSTEM_URL') ? SYSTEM_URL : '') . "/student/dissertation.php' style='background:#2563eb; color:white; padding:10px 24px; text-decoration:none; border-radius:6px;'>View in VLE</a></p>
                    ";
                    notifyStudent($conn, $diss_info['student_email'], $diss_info['student_name'], "Dissertation: New Comment from Supervisor", $email_body);
                }
            }
        }
    } elseif ($action === 'supervisor_upload' && $submission_id && $dissertation_id) {
        // Supervisor uploads an edited document back to a submission
        if (!empty($_FILES['supervisor_file']['name']) && $_FILES['supervisor_file']['error'] === UPLOAD_ERR_OK) {
            $allowed_ext = ['doc','docx','pdf','txt','rtf','odt'];
            $file = $_FILES['supervisor_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($ext, $allowed_ext)) {
                $error = 'Invalid file type. Allowed: ' . implode(', ', $allowed_ext);
            } elseif ($file['size'] > 20 * 1024 * 1024) {
                $error = 'File too large. Maximum 20MB.';
            } else {
                // Verify this submission belongs to a dissertation supervised by this lecturer
                $chk = $conn->prepare("SELECT ds.submission_id, d.dissertation_id, d.student_id, s.full_name as student_name, s.email as student_email 
                    FROM dissertation_submissions ds 
                    JOIN dissertations d ON ds.dissertation_id = d.dissertation_id 
                    LEFT JOIN students s ON d.student_id = s.student_id
                    WHERE ds.submission_id = ? AND d.dissertation_id = ? AND (d.supervisor_id = ? OR d.co_supervisor_id = ?)");
                $chk->bind_param("iiii", $submission_id, $dissertation_id, $lecturer_id, $lecturer_id);
                $chk->execute();
                $sub_info = $chk->get_result()->fetch_assoc();
                
                if ($sub_info) {
                    $upload_dir = '../uploads/dissertations/' . $dissertation_id . '/supervisor';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    
                    $safe_name = time() . '_supervisor_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
                    $dest = $upload_dir . '/' . $safe_name;
                    $rel_path = 'uploads/dissertations/' . $dissertation_id . '/supervisor/' . $safe_name;
                    
                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        // Log as feedback with attachment
                        $phase_stmt = $conn->prepare("SELECT phase FROM dissertation_submissions WHERE submission_id = ?");
                        $phase_stmt->bind_param("i", $submission_id);
                        $phase_stmt->execute();
                        $phase_row = $phase_stmt->get_result()->fetch_assoc();
                        $phase = $phase_row['phase'] ?? '';
                        
                        $fb_text = 'Supervisor uploaded an edited document: ' . htmlspecialchars(basename($file['name']));
                        $stmt2 = $conn->prepare("INSERT INTO dissertation_feedback (dissertation_id, submission_id, user_id, reviewer_role, phase, feedback_type, feedback_text, attachment_path) VALUES (?, ?, ?, 'supervisor', ?, 'comment', ?, ?)");
                        $stmt2->bind_param("iiisss", $dissertation_id, $submission_id, $user_id, $phase, $fb_text, $rel_path);
                        $stmt2->execute();
                        
                        $message = 'Document uploaded successfully.';
                        
                        // Notify student
                        if ($sub_info['student_email']) {
                            $phase_label = $phase_config[$phase] ?? ucfirst($phase);
                            $email_body = "
                                <h2 style='color: #2563eb;'>📎 Supervisor Uploaded a Document</h2>
                                <p>Dear <strong>{$sub_info['student_name']}</strong>,</p>
                                <p>Your supervisor <strong>{$lecturer_name}</strong> has uploaded an edited document for your <strong>{$phase_label}</strong> submission.</p>
                                <p>Please log in to the VLE to download and review the document.</p>
                                <p style='margin-top:20px;'><a href='" . (defined('SYSTEM_URL') ? SYSTEM_URL : '') . "/student/dissertation.php' style='background:#2563eb; color:white; padding:10px 24px; text-decoration:none; border-radius:6px;'>View in VLE</a></p>
                            ";
                            notifyStudent($conn, $sub_info['student_email'], $sub_info['student_name'], "Dissertation: Supervisor Uploaded a Document", $email_body);
                        }
                    } else {
                        $error = 'Failed to upload file.';
                    }
                } else {
                    $error = 'Unauthorized: submission not found or not your student.';
                }
            }
        } else {
            $error = 'No file selected.';
        }
    }
}

// Check if viewing a specific student's dissertation
$view_id = (int)($_GET['id'] ?? 0);

if ($view_id) {
    // Detail view
    $stmt = $conn->prepare("
        SELECT d.*, s.full_name as student_name, s.program, s.student_id as sid, s.email as student_email,
               l2.full_name as co_supervisor_name
        FROM dissertations d
        LEFT JOIN students s ON d.student_id = s.student_id
        LEFT JOIN lecturers l2 ON d.co_supervisor_id = l2.lecturer_id
        WHERE d.dissertation_id = ? AND (d.supervisor_id = ? OR d.co_supervisor_id = ?)
    ");
    $stmt->bind_param("iii", $view_id, $lecturer_id, $lecturer_id);
    $stmt->execute();
    $diss = $stmt->get_result()->fetch_assoc();
    
    if (!$diss) {
        header('Location: dissertation_supervision.php');
        exit;
    }
    
    // Get submissions
    $subs = [];
    $r = $conn->query("SELECT * FROM dissertation_submissions WHERE dissertation_id = $view_id ORDER BY phase, version DESC");
    if ($r) while ($row = $r->fetch_assoc()) $subs[] = $row;
    
    // Get feedback
    $fbs = [];
    $r = $conn->query("
        SELECT df.*, COALESCE(l.full_name, u.username) as reviewer_name
        FROM dissertation_feedback df
        LEFT JOIN users u ON df.user_id = u.user_id
        LEFT JOIN lecturers l ON u.related_staff_id = l.lecturer_id
        WHERE df.dissertation_id = $view_id
        ORDER BY df.created_at DESC
    ");
    if ($r) while ($row = $r->fetch_assoc()) $fbs[] = $row;
    
    // Get guidelines for current phase
    $cur_phase = $diss['current_phase'] ?? '';
    $guidelines = [];
    $r = $conn->query("SELECT * FROM dissertation_guidelines WHERE phase IN ('$cur_phase', 'formatting') AND is_active = 1 ORDER BY section_order");
    if ($r) while ($row = $r->fetch_assoc()) $guidelines[] = $row;

    // Get ethics submissions
    $ethics_submissions = [];
    $r = $conn->query("SELECT * FROM dissertation_ethics WHERE dissertation_id = $view_id ORDER BY submitted_at DESC");
    if ($r) while ($row = $r->fetch_assoc()) $ethics_submissions[] = $row;
}

// List of all supervised students
$my_students = [];
$r = $conn->query("
    SELECT d.*, s.full_name as student_name, s.program, s.student_id as sid
    FROM dissertations d
    LEFT JOIN students s ON d.student_id = s.student_id
    WHERE (d.supervisor_id = $lecturer_id OR d.co_supervisor_id = $lecturer_id) AND d.is_active = 1
    ORDER BY d.updated_at DESC
");
if ($r) while ($row = $r->fetch_assoc()) $my_students[] = $row;

// Pending reviews
$pending_reviews = [];
$r = $conn->query("
    SELECT ds.*, d.title, d.student_id, s.full_name as student_name
    FROM dissertation_submissions ds
    JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON d.student_id = s.student_id
    WHERE (d.supervisor_id = $lecturer_id OR d.co_supervisor_id = $lecturer_id) AND ds.status IN ('submitted','under_review')
    ORDER BY ds.submitted_at ASC
");
if ($r) while ($row = $r->fetch_assoc()) $pending_reviews[] = $row;

$page_title = ($view_id && isset($diss)) 
    ? 'Supervising: ' . ($diss['student_name'] ?? 'Student') . ' — ' . mb_strimwidth($diss['title'] ?? 'Dissertation', 0, 60, '…')
    : 'Dissertation Supervision';
$breadcrumbs = [['title' => 'Dissertation Supervision']];
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
        .student-card { transition: all 0.2s; border-left: 4px solid transparent; }
        .student-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .phase-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }
        .feedback-entry { border-left: 3px solid #818cf8; padding-left: 12px; margin-bottom: 0.75rem; }
        .feedback-entry.approval { border-left-color: #10b981; }
        .feedback-entry.revision_request { border-left-color: #f59e0b; }
        .feedback-entry.comment { border-left-color: #3b82f6; }
        .file-card { background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 1.25rem; transition: all 0.2s; }
        .file-card:hover { border-color: #3b82f6; background: #eff6ff; }
        .revision-timeline { position: relative; }
        .revision-timeline::before { content:''; position:absolute; left:8px; top:0; bottom:0; width:2px; background:#e2e8f0; }
        .revision-item { position:relative; padding-left:28px; margin-bottom:1rem; }
        .revision-item::before { content:''; position:absolute; left:3px; top:6px; width:12px; height:12px; border-radius:50%; background:#3b82f6; border:2px solid #fff; box-shadow:0 0 0 2px #3b82f6; }
        .revision-item.approved::before { background:#10b981; box-shadow:0 0 0 2px #10b981; }
        .revision-item.revision_requested::before { background:#f59e0b; box-shadow:0 0 0 2px #f59e0b; }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if ($view_id && isset($diss)): ?>
    <!-- DETAIL VIEW -->

    <!-- Sticky Back Navigation -->
    <nav class="navbar navbar-light bg-white border-bottom shadow-sm py-2 mb-3" style="position:sticky;top:66px;z-index:200;">
        <div class="container-fluid px-0">
            <a href="dissertation_supervision.php" class="btn btn-primary">
                <i class="bi bi-arrow-left me-1"></i>Back to Students
            </a>
            <div class="d-flex align-items-center gap-2 ms-3 flex-grow-1 overflow-hidden">
                <span class="badge bg-primary" style="white-space:nowrap;"><?= $phase_config[$diss['current_phase']] ?? ucfirst($diss['current_phase'] ?? '') ?></span>
                <span class="badge bg-<?= (strpos($diss['status'] ?? '', 'submitted') !== false) ? 'warning text-dark' : ((strpos($diss['status'] ?? '', 'approved') !== false) ? 'success' : 'secondary') ?>" style="white-space:nowrap;"><?= ucfirst(str_replace('_',' ',$diss['status'] ?? '')) ?></span>
                <span class="fw-semibold text-truncate d-none d-md-inline"><?= htmlspecialchars($diss['title'] ?? 'Untitled') ?></span>
            </div>
        </div>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0"><?= htmlspecialchars($diss['title'] ?? 'Untitled') ?></h3>
            <p class="text-muted mb-0"><?= htmlspecialchars($diss['student_name'] ?? '') ?> — <?= htmlspecialchars($diss['program'] ?? '') ?>
                <?php if (!empty($diss['student_email'])): ?>
                    <a href="mailto:<?= htmlspecialchars($diss['student_email']) ?>" class="ms-2 text-primary"><i class="bi bi-envelope me-1"></i>Email</a>
                <?php endif; ?>
            </p>
        </div>
        <div class="text-end">
            <?php if (!empty($diss['co_supervisor_name'])): ?>
            <small class="text-muted d-block mb-1">Co-supervisor: <?= htmlspecialchars($diss['co_supervisor_name']) ?></small>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <!-- Submissions & Review Column -->
        <div class="col-lg-8">
            <?php 
            // Group submissions by phase, latest first
            $diss_pending = array_filter($subs, fn($s) => in_array($s['status'], ['submitted','under_review']));
            $phase_subs = [];
            foreach ($subs as $s) { $phase_subs[$s['phase']][] = $s; }
            ?>

            <!-- PENDING SUBMISSIONS FOR REVIEW -->
            <?php foreach ($diss_pending as $ps): ?>
            <div class="card shadow-sm mb-3 border-warning">
                <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-exclamation-circle text-warning me-2"></i>Review: <?= $phase_config[$ps['phase']] ?? ucfirst($ps['phase']) ?> (v<?= $ps['version'] ?>)</h5>
                    <small class="text-muted"><?= $ps['submitted_at'] ? date('M j, Y \a\t H:i', strtotime($ps['submitted_at'])) : '' ?></small>
                </div>
                <div class="card-body">
                    <!-- File Download & View -->
                    <?php if ($ps['file_path']): ?>
                    <div class="file-card mb-3">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center">
                                <?php 
                                $ext = strtolower(pathinfo($ps['file_name'] ?? '', PATHINFO_EXTENSION));
                                if (!$ext) $ext = strtolower(pathinfo($ps['file_path'] ?? '', PATHINFO_EXTENSION));
                                $icon = 'bi-file-earmark';
                                $icon_color = '#64748b';
                                if (in_array($ext, ['doc','docx'])) { $icon = 'bi-file-earmark-word'; $icon_color = '#2b579a'; }
                                elseif ($ext === 'pdf') { $icon = 'bi-file-earmark-pdf'; $icon_color = '#d63031'; }
                                elseif (in_array($ext, ['ppt','pptx'])) { $icon = 'bi-file-earmark-slides'; $icon_color = '#d24726'; }
                                elseif (in_array($ext, ['xls','xlsx'])) { $icon = 'bi-file-earmark-excel'; $icon_color = '#217346'; }
                                ?>
                                <i class="bi <?= $icon ?> me-2" style="font-size:2rem; color:<?= $icon_color ?>;"></i>
                                <div>
                                    <strong style="font-size:0.9rem;"><?= htmlspecialchars(!empty($ps['file_name']) ? $ps['file_name'] : 'Submitted File') ?></strong>
                                    <br><small class="text-muted">
                                        <?= $ps['file_size'] ? number_format($ps['file_size']/1024, 0) . ' KB' : '' ?>
                                        <?= $ps['file_type'] ? ' · ' . htmlspecialchars($ps['file_type']) : '' ?>
                                    </small>
                                </div>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <a href="../<?= htmlspecialchars($ps['file_path']) ?>" download class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-download me-1"></i>Download
                                </a>
                                <button class="btn btn-success btn-sm" onclick="openDocEditor('<?= htmlspecialchars($ps['file_path']) ?>', '<?= htmlspecialchars(!empty($ps['file_name']) ? $ps['file_name'] : basename($ps['file_path']), ENT_QUOTES) ?>')" title="Open and read/edit this document inside the VLE system">
                                    <i class="bi bi-eye me-1"></i>Open in System
                                </button>
                                <a href="../<?= htmlspecialchars($ps['file_path']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm" title="Open file directly in new tab">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>Open
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Submission Text Preview -->
                    <?php if ($ps['submission_text']): ?>
                    <div class="bg-light p-3 rounded mb-3" style="max-height:250px;overflow-y:auto; font-size:0.88rem; line-height:1.7;">
                        <?= nl2br(htmlspecialchars($ps['submission_text'])) ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="d-flex gap-3 flex-wrap mb-3">
                        <?php if ($ps['word_count']): ?>
                        <span class="badge bg-light text-dark border"><i class="bi bi-type me-1"></i><?= number_format($ps['word_count']) ?> words</span>
                        <?php endif; ?>
                        <span class="badge bg-light text-dark border"><i class="bi bi-arrow-repeat me-1"></i>Version <?= $ps['version'] ?></span>
                    </div>
                    
                    <!-- Integrity Check Results + Run Button -->
                    <div id="integrityResult_<?= $ps['submission_id'] ?>">
                    <?php
                        $sim_data_pending = null;
                        if ($ps['similarity_check_id']) {
                            $sc_r = $conn->query("SELECT * FROM dissertation_similarity_checks WHERE check_id = {$ps['similarity_check_id']}");
                            $sim_data_pending = $sc_r ? $sc_r->fetch_assoc() : null;
                        }
                    ?>
                    <?php if ($sim_data_pending): ?>
                    <div class="d-flex gap-3 mb-2 align-items-center flex-wrap">
                        <div class="text-center">
                            <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto"
                                 style="width:54px;height:54px;background:<?= $sim_data_pending['similarity_score'] >= 25 ? '#fecaca' : ($sim_data_pending['similarity_score'] >= 15 ? '#fef3c7' : '#d1fae5') ?>;font-weight:700;font-size:0.8rem;color:<?= $sim_data_pending['similarity_score'] >= 25 ? '#dc2626' : ($sim_data_pending['similarity_score'] >= 15 ? '#92400e' : '#065f46') ?>;">
                                <?= $sim_data_pending['similarity_score'] ?>%
                            </div>
                            <small class="text-muted d-block">Similarity</small>
                        </div>
                        <div class="text-center">
                            <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto"
                                 style="width:54px;height:54px;background:<?= $sim_data_pending['ai_detection_score'] >= 40 ? '#fecaca' : ($sim_data_pending['ai_detection_score'] >= 20 ? '#fef3c7' : '#d1fae5') ?>;font-weight:700;font-size:0.8rem;color:<?= $sim_data_pending['ai_detection_score'] >= 40 ? '#dc2626' : ($sim_data_pending['ai_detection_score'] >= 20 ? '#92400e' : '#065f46') ?>;">
                                <?= $sim_data_pending['ai_detection_score'] ?>%
                            </div>
                            <small class="text-muted d-block">AI Score</small>
                        </div>
                        <div>
                            <small class="text-muted d-block" style="font-size:0.74rem;"><i class="bi bi-clock me-1"></i>Checked: <?= !empty($sim_data_pending['checked_at']) ? date('M j, Y H:i', strtotime($sim_data_pending['checked_at'])) : 'N/A' ?></small>
                            <small class="text-muted d-block" style="font-size:0.74rem;"><i class="bi bi-type me-1"></i><?= number_format($sim_data_pending['total_words_checked'] ?? 0) ?> words analysed</small>
                        </div>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-2" style="font-size:0.82rem;"><i class="bi bi-shield-exclamation me-1"></i>No integrity check has been run yet.</p>
                    <?php endif; ?>
                    </div>
                    <button class="btn btn-outline-info btn-sm mb-3"
                            onclick="runIntegrityCheck(<?= $ps['submission_id'] ?>, '<?= addslashes($phase_config[$ps['phase']] ?? ucfirst($ps['phase'])) ?> v<?= $ps['version'] ?>')">
                        <i class="bi bi-shield-check me-1"></i>
                        <?= $ps['similarity_check_id'] ? 'Re-run' : 'Run' ?> Plagiarism &amp; AI Check
                    </button>
                    
                    <hr>
                    
                    <!-- Review Actions -->
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#revisionTab<?= $ps['submission_id'] ?>"><i class="bi bi-arrow-counterclockwise me-1"></i>Request Revision</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#approveTab<?= $ps['submission_id'] ?>"><i class="bi bi-check-circle me-1"></i>Approve & Advance</a></li>
                    </ul>
                    <div class="tab-content">
                        <!-- Revision Tab (default - supervisor reviews before approving) -->
                        <div class="tab-pane fade show active" id="revisionTab<?= $ps['submission_id'] ?>">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="revision">
                                <input type="hidden" name="submission_id" value="<?= $ps['submission_id'] ?>">
                                <input type="hidden" name="dissertation_id" value="<?= $view_id ?>">
                                <div class="mb-2">
                                    <label class="form-label fw-bold" style="font-size:0.85rem;"><i class="bi bi-chat-left-text me-1"></i>Detailed Feedback <span class="text-danger">*</span></label>
                                    <textarea name="feedback_text" class="form-control" rows="4" placeholder="Provide detailed feedback on what needs to be revised. Be specific about sections, arguments, formatting issues, etc." required></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold" style="font-size:0.85rem;"><i class="bi bi-flag me-1"></i>Flagged Sections (optional)</label>
                                    <textarea name="feedback_sections" class="form-control form-control-sm" rows="2" placeholder="e.g. Introduction paragraph 2, Literature Review - source citations, Methodology section 3.1..."></textarea>
                                    <small class="text-muted">List specific sections that need attention</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold" style="font-size:0.85rem;"><i class="bi bi-paperclip me-1"></i>Attach Document (optional)</label>
                                    <input type="file" class="form-control form-control-sm" name="feedback_attachment" accept=".doc,.docx,.pdf,.txt,.rtf,.odt,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                                    <small class="text-muted">Upload an annotated document or reference file for the student</small>
                                </div>
                                <button type="button" class="btn btn-warning w-100" onclick="showConfirmModal('Request Revision', '<p>Send revision request to this student?</p><p class=\'text-muted small\'>They will be notified via email with your feedback.</p>', this.closest('form'), 'btn-warning')">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Request Revision & Notify Student
                                </button>
                            </form>
                        </div>
                        <!-- Approval Tab -->
                        <div class="tab-pane fade" id="approveTab<?= $ps['submission_id'] ?>">
                            <div class="alert alert-info py-2 mb-3" style="font-size:0.82rem;">
                                <i class="bi bi-info-circle me-1"></i>Approving will advance the student to <strong><?= $phase_config[$next_phase[$ps['phase']] ?? ''] ?? 'the next phase' ?></strong>. Only approve when fully satisfied.
                            </div>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="submission_id" value="<?= $ps['submission_id'] ?>">
                                <input type="hidden" name="dissertation_id" value="<?= $view_id ?>">
                                <div class="mb-3">
                                    <label class="form-label fw-bold" style="font-size:0.85rem;">Approval Comments (optional)</label>
                                    <textarea name="feedback_text" class="form-control" rows="3" placeholder="Well done! You may now proceed to the next chapter..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold" style="font-size:0.85rem;"><i class="bi bi-paperclip me-1"></i>Attach Document (optional)</label>
                                    <input type="file" class="form-control form-control-sm" name="feedback_attachment" accept=".doc,.docx,.pdf,.txt,.rtf,.odt,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                                </div>
                                <?php
                                $is_defense_phase = ($ps['phase'] === 'defense');
                                $approve_label = $is_defense_phase ? 'Approve Presentation for Defense' : 'Approve &amp; Advance to Next Chapter';
                                if ($is_defense_phase) {
                                    $approve_confirm = '<p>Are you sure you want to approve this defense presentation?</p><p class=\\\'text-muted small\\\'>The presentation will be marked as approved. The Research Coordinator will schedule the defense.</p>';
                                } else {
                                    $approve_confirm = '<p>Are you sure you want to approve this submission and advance the student to the next chapter?</p><p class=\\\'text-muted small\\\'>The student will be notified via email.</p>';
                                }
                                ?>
                                <button type="button" class="btn btn-success w-100" onclick="showConfirmModal('Approve Submission', '<?= $approve_confirm ?>', this.closest('form'))">
                                    <i class="bi bi-check-lg me-1"></i><?= $approve_label ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Quick Comment Form (for guidance without changing status) -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#quickComment" style="cursor:pointer;">
                    <h6 class="mb-0"><i class="bi bi-chat-dots me-2 text-primary"></i>Send Quick Comment to Student</h6>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="collapse" id="quickComment">
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_comment">
                            <input type="hidden" name="dissertation_id" value="<?= $view_id ?>">
                            <input type="hidden" name="comment_phase" value="<?= htmlspecialchars($diss['current_phase'] ?? '') ?>">
                            <div class="mb-2">
                                <textarea name="feedback_text" class="form-control" rows="3" placeholder="Type a quick comment, guidance, or reminder for the student... (they will be notified by email)" required></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold" style="font-size:0.85rem;"><i class="bi bi-paperclip me-1"></i>Attach Document (optional)</label>
                                <input type="file" class="form-control form-control-sm" name="feedback_attachment" accept=".doc,.docx,.pdf,.txt,.rtf,.odt,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Send Comment & Notify</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Submission History Timeline -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Submission History (<?= count($subs) ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($subs)): ?>
                        <p class="text-muted text-center py-3">No submissions yet.</p>
                    <?php else: ?>
                    <?php 
                    // Group by phase
                    foreach ($phase_config as $pk => $plabel):
                        $psubs = $phase_subs[$pk] ?? [];
                        if (empty($psubs)) continue;
                    ?>
                    <h6 class="fw-bold text-primary mb-2 mt-3"><i class="bi bi-journal-text me-1"></i><?= $plabel ?></h6>
                    <div class="revision-timeline mb-3">
                        <?php foreach ($psubs as $s): ?>
                        <?php $sc = $s['status'] === 'approved' ? 'success' : ($s['status'] === 'revision_requested' ? 'warning' : ($s['status'] === 'rejected' ? 'danger' : 'primary')); ?>
                        <div class="revision-item <?= $s['status'] ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="badge bg-<?= $sc ?> me-1"><?= ucfirst(str_replace('_',' ',$s['status'])) ?></span>
                                    <strong style="font-size:0.85rem;">Version <?= $s['version'] ?></strong>
                                    <?php if ($s['word_count']): ?>
                                    <small class="text-muted ms-2"><?= number_format($s['word_count']) ?> words</small>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php if ($s['file_path']): ?>
                                    <a href="../<?= htmlspecialchars($s['file_path']) ?>" download class="btn btn-outline-primary btn-sm py-0 px-1" title="Download">
                                        <i class="bi bi-download" style="font-size:0.75rem;"></i>
                                    </a>
                                    <button class="btn btn-outline-success btn-sm py-0 px-1" onclick="openDocEditor('<?= htmlspecialchars($s['file_path']) ?>', '<?= htmlspecialchars(!empty($s['file_name']) ? $s['file_name'] : basename($s['file_path']), ENT_QUOTES) ?>')" title="Open & Edit in TinyMCE">
                                        <i class="bi bi-pencil-square" style="font-size:0.75rem;"></i>
                                    </button>
                                    <button class="btn btn-outline-warning btn-sm py-0 px-1" onclick="document.getElementById('uploadInput_<?= $s['submission_id'] ?>').click()" title="Upload Edited Document">
                                        <i class="bi bi-upload" style="font-size:0.75rem;"></i>
                                    </button>
                                    <form method="POST" enctype="multipart/form-data" class="d-none" id="uploadForm_<?= $s['submission_id'] ?>">
                                        <input type="hidden" name="action" value="supervisor_upload">
                                        <input type="hidden" name="submission_id" value="<?= $s['submission_id'] ?>">
                                        <input type="hidden" name="dissertation_id" value="<?= $view_id ?>">
                                        <input type="file" id="uploadInput_<?= $s['submission_id'] ?>" name="supervisor_file" accept=".doc,.docx,.pdf,.txt,.rtf,.odt" onchange="if(this.files.length) showConfirmModal('Upload Document', '<p>Upload edited document for <strong>Version <?= $s['version'] ?></strong>?</p><p class=\'text-muted small\'>File: ' + this.files[0].name + '</p>', this.closest('form'), 'btn-warning');">
                                    </form>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-info btn-sm py-0 px-1"
                                            onclick="runIntegrityCheck(<?= $s['submission_id'] ?>, '<?= addslashes($phase_config[$s['phase']] ?? ucfirst($s['phase'])) ?> v<?= $s['version'] ?>')"
                                            title="<?= $s['similarity_check_id'] ? 'Re-run' : 'Run' ?> Plagiarism &amp; AI Check" id="checkBtn_<?= $s['submission_id'] ?>">
                                        <i class="bi bi-shield-check" style="font-size:0.75rem;"></i>
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted"><?= $s['submitted_at'] ? date('M j, Y \a\t H:i', strtotime($s['submitted_at'])) : '' ?></small>
                            <?php if ($s['reviewed_at']): ?>
                            <br><small class="text-muted"><i class="bi bi-check2 me-1"></i>Reviewed: <?= date('M j, Y H:i', strtotime($s['reviewed_at'])) ?></small>
                            <?php endif; ?>

                            <?php if (!empty($s['similarity_check_id'])):
                                $hist_sc = $conn->query("SELECT similarity_score, ai_detection_score, checked_at FROM dissertation_similarity_checks WHERE check_id = {$s['similarity_check_id']}");
                                $hist_sim = $hist_sc ? $hist_sc->fetch_assoc() : null;
                                if ($hist_sim): ?>
                            <div class="d-flex gap-2 mt-1 flex-wrap" id="histIntegrity_<?= $s['submission_id'] ?>">
                                <span class="badge <?= $hist_sim['similarity_score'] >= 25 ? 'bg-danger' : ($hist_sim['similarity_score'] >= 15 ? 'bg-warning text-dark' : 'bg-success') ?>" style="font-size:0.7rem;">
                                    <i class="bi bi-shield-check me-1"></i>Similarity: <?= round($hist_sim['similarity_score']) ?>%
                                </span>
                                <span class="badge <?= $hist_sim['ai_detection_score'] >= 40 ? 'bg-danger' : ($hist_sim['ai_detection_score'] >= 20 ? 'bg-warning text-dark' : 'bg-success') ?>" style="font-size:0.7rem;">
                                    <i class="bi bi-robot me-1"></i>AI: <?= round($hist_sim['ai_detection_score']) ?>%
                                </span>
                                <span class="text-muted" style="font-size:0.65rem;align-self:center;"><i class="bi bi-clock me-1"></i><?= !empty($hist_sim['checked_at']) ? date('M j, Y', strtotime($hist_sim['checked_at'])) : '' ?></span>
                            </div>
                            <?php else: ?>
                            <div class="d-flex gap-2 mt-1 flex-wrap" id="histIntegrity_<?= $s['submission_id'] ?>"></div>
                            <?php endif; else: ?>
                            <div class="d-flex gap-2 mt-1 flex-wrap" id="histIntegrity_<?= $s['submission_id'] ?>"></div>
                            <?php endif; ?>
                            
                            <?php 
                            // Show feedback for this submission
                            $sub_fbs = array_filter($fbs, fn($f) => ($f['submission_id'] ?? 0) == $s['submission_id']);
                            foreach ($sub_fbs as $sfb): ?>
                            <div class="mt-2 p-2 rounded" style="background:#f8fafc; border-left:3px solid <?= ($sfb['feedback_type'] ?? '') === 'approval' ? '#10b981' : '#f59e0b' ?>; font-size:0.8rem;">
                                <strong><?= htmlspecialchars($sfb['reviewer_name'] ?? 'Supervisor') ?></strong>
                                <span class="badge bg-<?= ($sfb['feedback_type'] ?? '') === 'approval' ? 'success' : 'warning' ?>" style="font-size:0.6rem;"><?= ucfirst(str_replace('_',' ',$sfb['feedback_type'] ?? '')) ?></span>
                                <div class="mb-0 mt-1"><?= $sfb['feedback_text'] ?? '' ?></div>
                                <?php if (!empty($sfb['attachment_path'])): ?>
                                <div class="mt-1"><a href="../<?= htmlspecialchars($sfb['attachment_path']) ?>" download class="text-decoration-none" style="font-size:0.75rem;"><i class="bi bi-paperclip me-1"></i><?= htmlspecialchars(basename($sfb['attachment_path'])) ?></a></div>
                                <?php endif; ?>
                                <?php if (!empty($sfb['flagged_sections'])): ?>
                                <?php $sections_arr = json_decode($sfb['flagged_sections'], true); ?>
                                <div class="mt-1 text-danger" style="font-size:0.75rem;"><i class="bi bi-flag me-1"></i>
                                    <?php if (is_array($sections_arr)): ?>
                                        <?php foreach ($sections_arr as $sec): ?><span class="badge bg-danger bg-opacity-10 text-danger me-1 mb-1"><?= htmlspecialchars($sec) ?></span><?php endforeach; ?>
                                    <?php else: ?>
                                        <?= nl2br(htmlspecialchars($sfb['flagged_sections'])) ?>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right sidebar: Feedback & Guidelines -->
        <div class="col-lg-4">
            <!-- Student Info Card -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-person me-1"></i>Student Info</h6>
                </div>
                <div class="card-body py-2">
                    <small><strong>Name:</strong> <?= htmlspecialchars($diss['student_name'] ?? '') ?></small><br>
                    <small><strong>ID:</strong> <?= htmlspecialchars($diss['sid'] ?? '') ?></small><br>
                    <small><strong>Program:</strong> <?= htmlspecialchars($diss['program'] ?? '') ?></small><br>
                    <?php if (!empty($diss['student_email'])): ?>
                    <small><strong>Email:</strong> <a href="mailto:<?= htmlspecialchars($diss['student_email']) ?>"><?= htmlspecialchars($diss['student_email']) ?></a></small><br>
                    <?php endif; ?>
                    <small><strong>Semester:</strong> <?= htmlspecialchars($diss['semester'] ?? '') ?></small>
                </div>
            </div>

            <!-- Ethics Submissions Card -->
            <?php if (!empty($ethics_submissions)): ?>
            <div class="card shadow-sm mb-3 border-info">
                <div class="card-header bg-info bg-opacity-10 py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-medical me-1 text-info"></i>Ethics Submissions (<?= count($ethics_submissions) ?>)</h6>
                </div>
                <div class="card-body py-2" style="max-height:350px;overflow-y:auto;">
                    <?php foreach ($ethics_submissions as $eth): ?>
                    <div class="p-2 mb-2 rounded border <?= ($eth['status'] ?? '') === 'approved' ? 'border-success bg-success bg-opacity-10' : (($eth['status'] ?? '') === 'pending' ? 'border-warning bg-warning bg-opacity-10' : '') ?>">
                        <?php if (!empty($eth['application_number'])): ?>
                        <small class="fw-bold text-primary"><?= htmlspecialchars($eth['application_number']) ?></small><br>
                        <?php endif; ?>
                        <small><strong>Status:</strong> 
                            <span class="badge bg-<?= ($eth['status'] ?? '') === 'approved' ? 'success' : (($eth['status'] ?? '') === 'pending' ? 'warning' : (($eth['status'] ?? '') === 'rejected' ? 'danger' : 'secondary')) ?>">
                                <?= ucfirst($eth['status'] ?? 'pending') ?>
                            </span>
                        </small><br>
                        <small><strong>Submitted:</strong> <?= !empty($eth['submitted_at']) ? date('M j, Y', strtotime($eth['submitted_at'])) : 'N/A' ?></small>
                        <?php if (!empty($eth['ethics_form_path'])): ?>
                        <div class="mt-1">
                            <a href="../<?= htmlspecialchars($eth['ethics_form_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0" style="font-size:0.75rem;">
                                <i class="bi bi-download me-1"></i>Download Form
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($eth['reviewer_comments'])): ?>
                        <div class="mt-1 p-1 bg-white rounded" style="font-size:0.75rem;">
                            <i class="bi bi-chat-dots me-1"></i><?= htmlspecialchars($eth['reviewer_comments']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Full Feedback History -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-chat-left-quote me-1"></i>All Feedback (<?= count($fbs) ?>)</h6>
                </div>
                <div class="card-body p-2" style="max-height:400px;overflow-y:auto;">
                    <?php if (empty($fbs)): ?>
                        <p class="text-muted text-center small py-2">No feedback given yet.</p>
                    <?php else: ?>
                        <?php foreach ($fbs as $fb): ?>
                        <div class="feedback-entry <?= $fb['feedback_type'] ?? '' ?>">
                            <div class="d-flex justify-content-between">
                                <small class="fw-bold"><?= htmlspecialchars($fb['reviewer_name'] ?? 'Supervisor') ?></small>
                                <?php 
                                $ft = $fb['feedback_type'] ?? '';
                                $ft_color = $ft === 'approval' ? 'success' : ($ft === 'revision_request' ? 'warning' : ($ft === 'comment' ? 'info' : 'secondary'));
                                ?>
                                <span class="badge bg-<?= $ft_color ?>" style="font-size:0.6rem;"><?= ucfirst(str_replace('_',' ',$ft)) ?></span>
                            </div>
                            <small class="text-muted"><?= ucfirst(str_replace('_',' ',$fb['phase'] ?? '')) ?></small>
                            <div class="mb-0 mt-1" style="font-size:0.78rem;"><?= $fb['feedback_text'] ?? '' ?></div>
                            <?php if (!empty($fb['flagged_sections'])): ?>
                            <?php $fb_sec = json_decode($fb['flagged_sections'], true); ?>
                            <div class="mt-1" style="font-size:0.7rem;"><i class="bi bi-flag text-danger me-1"></i>
                                <?php if (is_array($fb_sec)): ?>
                                    <?php foreach ($fb_sec as $sec): ?><span class="badge bg-danger bg-opacity-10 text-danger me-1"><?= htmlspecialchars($sec) ?></span><?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-danger"><?= htmlspecialchars(mb_strimwidth($fb['flagged_sections'], 0, 150, '...')) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($fb['attachment_path'])): ?>
                            <div class="mt-1"><a href="../<?= htmlspecialchars($fb['attachment_path']) ?>" download class="text-decoration-none" style="font-size:0.75rem;"><i class="bi bi-paperclip me-1"></i><?= htmlspecialchars(basename($fb['attachment_path'])) ?></a></div>
                            <?php endif; ?>
                            <br><small class="text-muted"><?= $fb['created_at'] ? date('M j, Y H:i', strtotime($fb['created_at'])) : '' ?></small>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Phase Progress -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-bar-chart-steps me-1"></i>Phase Progress</h6>
                </div>
                <div class="card-body py-2">
                    <?php 
                    $all_phases = array_keys($phase_config);
                    $current_idx = array_search($diss['current_phase'] ?? '', $all_phases);
                    foreach ($phase_config as $pk => $plabel):
                        $pidx = array_search($pk, $all_phases);
                        $done = $pidx < $current_idx;
                        $active = $pk === ($diss['current_phase'] ?? '');
                        $has_subs = !empty($phase_subs[$pk] ?? []);
                    ?>
                    <div class="d-flex align-items-center mb-1" style="font-size:0.78rem;">
                        <i class="bi <?= $done ? 'bi-check-circle-fill text-success' : ($active ? 'bi-arrow-right-circle-fill text-primary' : 'bi-circle text-muted') ?> me-2"></i>
                        <span class="<?= $active ? 'fw-bold text-primary' : ($done ? 'text-success' : 'text-muted') ?>"><?= $plabel ?></span>
                        <?php if ($has_subs): ?>
                        <small class="ms-auto text-muted"><?= count($phase_subs[$pk]) ?> sub</small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Guidelines -->
            <?php if (!empty($guidelines)): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-book me-1"></i>Chapter Guidelines</h6>
                </div>
                <div class="card-body" style="max-height:350px;overflow-y:auto;">
                    <?php foreach ($guidelines as $g): ?>
                    <div class="p-2 mb-2 rounded" style="border-left:3px solid #6366f1; background:#f8fafc;">
                        <small class="fw-bold"><?= htmlspecialchars($g['section_title']) ?></small>
                        <?php if ($g['content']): ?>
                        <p class="mb-0 mt-1" style="font-size:0.72rem;color:#475569;"><?= nl2br(htmlspecialchars(mb_strimwidth($g['content'], 0, 250, '...'))) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Links -->
            <div class="card shadow-sm">
                <div class="card-body py-2">
                    <a href="dissertation_guidelines.php" class="btn btn-outline-primary btn-sm w-100 mb-2"><i class="bi bi-journal-text me-1"></i>Phase Guidelines</a>
                    <a href="guidelinebook.php" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-book me-1"></i>Full Guideline Book</a>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- LIST VIEW -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0"><i class="bi bi-mortarboard me-2"></i>Dissertation Supervision</h3>
        <div>
            <a href="dissertation_guidelines.php" class="btn btn-outline-primary btn-sm me-1">
                <i class="bi bi-journal-text me-1"></i>Phase Guidelines
            </a>
            <a href="guidelinebook.php" class="btn btn-primary btn-sm">
                <i class="bi bi-book me-1"></i>Full Guideline Book
            </a>
        </div>
    </div>

    <!-- Pending Reviews Alert -->
    <?php if (!empty($pending_reviews)): ?>
    <div class="card shadow-sm mb-4 border-warning">
        <div class="card-header bg-warning bg-opacity-10">
            <h5 class="mb-0 text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Pending Reviews (<?= count($pending_reviews) ?>)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Student</th><th>Phase</th><th>Version</th><th>Submitted</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_reviews as $pr): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($pr['student_name'] ?? $pr['student_id']) ?></strong></td>
                            <td><span class="badge bg-primary"><?= $phase_config[$pr['phase']] ?? ucfirst($pr['phase']) ?></span></td>
                            <td>v<?= $pr['version'] ?></td>
                            <td><small><?= $pr['submitted_at'] ? date('M j, Y H:i', strtotime($pr['submitted_at'])) : '' ?></small></td>
                            <td><a href="dissertation_supervision.php?id=<?= $pr['dissertation_id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-eye me-1"></i>Review</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- My Students -->
    <div class="row">
        <?php if (empty($my_students)): ?>
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-journal-x text-muted" style="font-size:3rem;"></i>
                    <h5 class="mt-3 text-muted">No Dissertation Students Assigned</h5>
                    <p class="text-muted">You will see your students here once the Research Coordinator assigns them to you.</p>
                </div>
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($my_students as $ms): ?>
        <?php
            $has_pending = false;
            foreach ($pending_reviews as $pr) {
                if ($pr['dissertation_id'] == $ms['dissertation_id']) { $has_pending = true; break; }
            }
            $border_color = $has_pending ? '#f59e0b' : ($ms['status'] === 'completed' ? '#10b981' : '#3b82f6');
        ?>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="card shadow-sm student-card h-100" style="border-left-color: <?= $border_color ?>;">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <h6 class="fw-bold mb-0"><?= htmlspecialchars($ms['student_name'] ?? $ms['student_id']) ?></h6>
                        <?php if ($has_pending): ?>
                        <span class="badge bg-warning">Needs Review</span>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted"><?= htmlspecialchars($ms['program'] ?? '') ?></small>
                    <p class="mb-2 mt-1" style="font-size:0.85rem;"><strong><?= htmlspecialchars(mb_strimwidth($ms['title'] ?? 'Untitled', 0, 60, '...')) ?></strong></p>
                    
                    <div class="d-flex gap-2 mb-2">
                        <span class="badge bg-primary"><?= $phase_config[$ms['current_phase']] ?? ucfirst($ms['current_phase'] ?? '') ?></span>
                        <span class="badge bg-light text-dark"><?= ucfirst(str_replace('_',' ',$ms['status'] ?? '')) ?></span>
                    </div>
                    
                    <a href="dissertation_supervision.php?id=<?= $ms['dissertation_id'] ?>" class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-eye me-1"></i>View Details
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

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
                    <a id="docOpenTabBtn" href="#" target="_blank" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open
                    </a>
                    <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-arrow-left me-1"></i>Back
                    </button>
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
    // Reset all views
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
    document.getElementById('docOpenTabBtn').href = '../' + filePath;
    document.getElementById('docErrorDownload').href = '../' + filePath;
    document.getElementById('docErrorDownload').setAttribute('download', docState.fileName);

    var ext = filePath.split('.').pop().toLowerCase();
    document.getElementById('docFormatBadge').textContent = '.' + ext;

    var modal = new bootstrap.Modal(document.getElementById('docEditorModal'));
    modal.show();

    // Use server-side PhpWord conversion for ALL document types
    fetch('../api/export_docx.php?file=' + encodeURIComponent(filePath), { credentials: 'same-origin' })
        .then(function(r) {
            var status = r.status;
            return r.text().then(function(text) {
                var data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    if (status === 302 || status === 0 || text === '') {
                        throw new Error('Session expired. Please refresh the page and log in again.');
                    }
                    throw new Error('Server returned an unreadable response. Try downloading the file instead.');
                }
                return {ok: status >= 200 && status < 300, data: data};
            });
        })
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

// Initialize TinyMCE on all feedback textareas
if (typeof initTinyMCE === 'function') {
    document.querySelectorAll('textarea[name="feedback_text"]').forEach(function(ta, i) {
        if (!ta.id) ta.id = 'feedback_text_' + i;
        initTinyMCE('#' + ta.id, { mode: 'dissertation', height: 250 });
    });
    document.querySelectorAll('textarea[name="feedback_sections"]').forEach(function(ta, i) {
        if (!ta.id) ta.id = 'feedback_sections_' + i;
        initTinyMCE('#' + ta.id, { mode: 'compact', height: 120 });
    });

    // Re-initialize TinyMCE when quick comment collapse opens
    var quickComment = document.getElementById('quickComment');
    if (quickComment) {
        quickComment.addEventListener('shown.bs.collapse', function() {
            var ta = quickComment.querySelector('textarea[name="feedback_text"]');
            if (ta && !ta.classList.contains('tinymce-done')) {
                ta.classList.add('tinymce-done');
                if (!ta.id) ta.id = 'quick_comment_feedback';
                initTinyMCE('#' + ta.id, { mode: 'dissertation', height: 250 });
            }
        });
    }
}

// Confirmation modal handler
function showConfirmModal(title, message, form, btnClass) {
    btnClass = btnClass || 'btn-success';
    var modal = document.getElementById('confirmActionModal');
    if (!modal) return form.submit();
    modal.querySelector('#confirmModalTitle').textContent = title;
    modal.querySelector('#confirmModalBody').innerHTML = message;
    var confirmBtn = modal.querySelector('#confirmModalBtn');
    confirmBtn.className = 'btn ' + btnClass;
    confirmBtn.textContent = title;
    confirmBtn.onclick = function() {
        bootstrap.Modal.getInstance(modal).hide();
        form.submit();
    };
    new bootstrap.Modal(modal).show();
}
</script>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="confirmModalTitle">Confirm</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirmModalBody"></div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="confirmModalBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Integrity Check Result Toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:2000;">
    <div id="integrityToast" class="toast" role="alert" aria-live="assertive">
        <div class="toast-header">
            <i class="bi bi-shield-check text-info me-2"></i>
            <strong class="me-auto">Integrity Check</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body" id="integrityToastBody"></div>
    </div>
</div>

<script>
/**
 * Run Plagiarism & AI Content check via AJAX for a dissertation submission.
 * Updates the UI in-place without a full page reload.
 */
function runIntegrityCheck(submissionId, label) {
    // Disable button & show spinner
    var btn = document.getElementById('checkBtn_' + submissionId);
    var pendBtn = document.querySelector('[onclick*="runIntegrityCheck(' + submissionId + ',"]');
    [btn, pendBtn].forEach(function(b) {
        if (!b) return;
        b.disabled = true;
        b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Checking…';
    });

    var fd = new FormData();
    fd.append('submission_id', submissionId);

    fetch('../api/dissertation_integrity_check.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    })
    .then(function(r) {
        var status = r.status;
        return r.text().then(function(text) {
            var data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                if (text === '') {
                    throw new Error('Server returned an empty response. Please try again.');
                }
                throw new Error('Server error — could not process integrity check. Please try again.');
            }
            return data;
        });
    })
    .then(function(data) {
        if (!data.success) {
            alert('Integrity check failed:\n' + (data.error || 'Unknown error'));
            [btn, pendBtn].forEach(function(b) {
                if (!b) return;
                b.disabled = false;
                b.innerHTML = '<i class="bi bi-shield-check"></i> Re-run Check';
            });
            return;
        }

        var simPct   = data.similarity_score;
        var aiPct    = data.ai_score;
        var now      = data.checked_at;
        var words    = (data.word_count || 0).toLocaleString();

        var simBadge = simPct >= 25 ? 'bg-danger' : (simPct >= 15 ? 'bg-warning text-dark' : 'bg-success');
        var aiBadge  = aiPct  >= 40 ? 'bg-danger' : (aiPct  >= 20 ? 'bg-warning text-dark' : 'bg-success');
        var simRing  = simPct >= 25 ? '#fecaca'   : (simPct >= 15 ? '#fef3c7'              : '#d1fae5');
        var aiRing   = aiPct  >= 40 ? '#fecaca'   : (aiPct  >= 20 ? '#fef3c7'              : '#d1fae5');
        var simCol   = simPct >= 25 ? '#dc2626'   : (simPct >= 15 ? '#92400e'              : '#065f46');
        var aiCol    = aiPct  >= 40 ? '#dc2626'   : (aiPct  >= 20 ? '#92400e'              : '#065f46');

        // Update pending-review big circles
        var pendBox = document.getElementById('integrityResult_' + submissionId);
        if (pendBox) {
            pendBox.innerHTML =
                '<div class="d-flex gap-3 mb-2 align-items-center flex-wrap">' +
                  '<div class="text-center"><div class="rounded-circle d-flex align-items-center justify-content-center mx-auto" style="width:54px;height:54px;background:' + simRing + ';font-weight:700;font-size:0.8rem;color:' + simCol + ';">' + simPct + '%</div><small class="text-muted d-block">Similarity</small></div>' +
                  '<div class="text-center"><div class="rounded-circle d-flex align-items-center justify-content-center mx-auto" style="width:54px;height:54px;background:' + aiRing  + ';font-weight:700;font-size:0.8rem;color:' + aiCol  + ';">' + aiPct  + '%</div><small class="text-muted d-block">AI Score</small></div>' +
                  '<div><small class="text-muted d-block" style="font-size:0.74rem;"><i class="bi bi-clock me-1"></i>' + now + '</small><small class="text-muted d-block" style="font-size:0.74rem;"><i class="bi bi-type me-1"></i>' + words + ' words analysed</small></div>' +
                '</div>';
        }

        // Update history timeline badges
        var histBox = document.getElementById('histIntegrity_' + submissionId);
        if (histBox) {
            histBox.innerHTML =
                '<span class="badge ' + simBadge + '" style="font-size:0.7rem;"><i class="bi bi-shield-check me-1"></i>Similarity: ' + Math.round(simPct) + '%</span>' +
                '<span class="badge ' + aiBadge  + '" style="font-size:0.7rem;"><i class="bi bi-robot me-1"></i>AI: ' + Math.round(aiPct) + '%</span>' +
                '<span class="text-muted" style="font-size:0.65rem;align-self:center;"><i class="bi bi-clock me-1"></i>' + now + '</span>';
        }

        // Re-enable buttons
        [btn, pendBtn].forEach(function(b) {
            if (!b) return;
            b.disabled = false;
            b.innerHTML = (b.tagName === 'BUTTON' && b.title && b.title.includes('Re-run'))
                ? '<i class="bi bi-shield-check" style="font-size:0.75rem;"></i>'
                : '<i class="bi bi-shield-check me-1"></i>Re-run Plagiarism &amp; AI Check';
        });

        // Toast
        var toastBody = document.getElementById('integrityToastBody');
        if (toastBody) {
            var flag = (simPct >= 25 || aiPct >= 40)
                ? '<span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>Flagged for review</span><br>'
                : '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Within acceptable range</span><br>';
            toastBody.innerHTML = '<strong>' + label + '</strong><br>' +
                flag +
                'Similarity: <strong>' + simPct + '%</strong> &nbsp;|&nbsp; AI: <strong>' + aiPct + '%</strong><br>' +
                '<small class="text-muted">' + words + ' words &bull; ' + now + '</small>';
            var toastEl = document.getElementById('integrityToast');
            if (toastEl) bootstrap.Toast.getOrCreateInstance(toastEl).show();
        }
    })
    .catch(function(err) {
        alert('Network error during integrity check. Please try again.\n' + err);
        [btn, pendBtn].forEach(function(b) {
            if (!b) return;
            b.disabled = false;
            b.innerHTML = '<i class="bi bi-shield-check"></i>';
        });
    });
}
</script>
</body>
