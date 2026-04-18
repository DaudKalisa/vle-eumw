<?php
/**
 * Student Dissertation Portal
 * Multi-phase dissertation workflow for eligible students
 * Available for Year 3 Sem 2, Year 4 Sem 1 & 2
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['student', 'admin', 'dissertation_student']);

$user = getCurrentUser();
$conn = getDbConnection();
$student_id = $_SESSION['vle_related_id'] ?? '';

$message = '';
$error = '';
$action = $_POST['action'] ?? '';

// Get student info
$student = null;
if ($student_id) {
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
}

// Fallback: look up student via users table if session related_id was not set (e.g. dissertation_student role)
if (!$student && !empty($_SESSION['vle_user_id'])) {
    $fb = $conn->prepare("SELECT s.* FROM students s INNER JOIN users u ON u.related_student_id = s.student_id WHERE u.user_id = ? LIMIT 1");
    $fb->bind_param("i", $_SESSION['vle_user_id']);
    $fb->execute();
    $student = $fb->get_result()->fetch_assoc();
    if ($student) {
        $student_id = $student['student_id'];
        $_SESSION['vle_related_id'] = $student_id;
    }
}

if (!$student) {
    if (hasRole('dissertation_student')) {
        // Show an error — do NOT redirect as that causes a loop for dissertation-only users
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title>'
           . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
           . '<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;background:#f8fafc;">'
           . '<div class="text-center p-5">'
           . '<i class="bi bi-exclamation-triangle" style="font-size:3rem;color:#f59e0b;"></i>'
           . '<h4 class="mt-3">Student Record Not Found</h4>'
           . '<p class="text-muted">Your student profile could not be loaded. Please contact the administrator.</p>'
           . '</div></body></html>';
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

// Check eligibility: Year 3 Semester 2 or above
$yos = (int)($student['year_of_study'] ?? 0);
$sem = $student['semester'] ?? '';
$is_dissertation_role = hasRole('dissertation_student');
if (!$is_dissertation_role && !($yos > 3 || ($yos === 3 && $sem === 'Two'))) {
    $_SESSION['dissertation_error'] = 'Dissertation is only available for students in Year 3 Semester 2 and above. You are currently in Year ' . $yos . ' Semester ' . htmlspecialchars($sem) . '.';
    header('Location: dashboard.php');
    exit;
}

// Phase labels and descriptions
$phase_config = [
    'topic' => ['label' => 'Topic Submission', 'icon' => 'bi-lightbulb', 'color' => '#6366f1'],
    'concept_note' => ['label' => 'Concept Note', 'icon' => 'bi-file-text', 'color' => '#8b5cf6'],
    'chapter1' => ['label' => 'Chapter 1 - Introduction', 'icon' => 'bi-1-circle', 'color' => '#0ea5e9'],
    'chapter2' => ['label' => 'Chapter 2 - Literature Review', 'icon' => 'bi-2-circle', 'color' => '#06b6d4'],
    'chapter3' => ['label' => 'Chapter 3 - Research Methodology', 'icon' => 'bi-3-circle', 'color' => '#14b8a6'],
    'proposal' => ['label' => 'Full Proposal', 'icon' => 'bi-file-earmark-check', 'color' => '#10b981'],
    'ethics' => ['label' => 'Ethics Clearance', 'icon' => 'bi-shield-check', 'color' => '#22c55e'],
    'defense' => ['label' => 'Proposal Defense', 'icon' => 'bi-mortarboard', 'color' => '#eab308'],
    'chapter4' => ['label' => 'Chapter 4 - Results & Discussion', 'icon' => 'bi-4-circle', 'color' => '#f97316'],
    'chapter5' => ['label' => 'Chapter 5 - Conclusions', 'icon' => 'bi-5-circle', 'color' => '#ef4444'],
    'final_draft' => ['label' => 'Final Draft', 'icon' => 'bi-file-earmark-pdf', 'color' => '#dc2626'],
    'presentation' => ['label' => 'Final Result Presentation', 'icon' => 'bi-easel', 'color' => '#7c3aed'],
    'final_submission' => ['label' => 'Final Submission', 'icon' => 'bi-check-circle', 'color' => '#059669']
];
$phase_order = array_keys($phase_config);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_fee_proof') {
        $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
        $installment_num = (int)($_POST['installment_num'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_ref = trim($_POST['payment_ref'] ?? '');
        $student_id = $_SESSION['vle_related_id'] ?? '';
        $proof_file = null;
        if ($dissertation_id && $installment_num >= 1 && $installment_num <= 3 && $amount > 0 && isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/dissertations/fee_proofs/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION));
            $safe_id = preg_replace('/[^A-Za-z0-9_-]/', '_', $student_id);
            $filename = 'feeproof_' . $safe_id . '_d' . $dissertation_id . '_i' . $installment_num . '_' . time() . '.' . $ext;
            $target_path = $upload_dir . $filename;
            if ($_FILES['proof_file']['size'] > 5 * 1024 * 1024) {
                $error = 'File size must not exceed 5MB.';
            } elseif (move_uploaded_file($_FILES['proof_file']['tmp_name'], $target_path)) {
                $proof_file = 'uploads/dissertations/fee_proofs/' . $filename;
                // Insert payment_transactions record
                $fee_row = $conn->query("SELECT id FROM dissertation_fees WHERE student_id = '$student_id' AND dissertation_id = $dissertation_id")->fetch_assoc();
                $fee_id = $fee_row['id'] ?? 0;
                $recorder = $_SESSION['vle_user_id'] ?? 0;
                $stmt = $conn->prepare("INSERT INTO payment_transactions (student_id, payment_type, amount, payment_method, reference_number, payment_date, recorded_by, notes, proof_file, approval_status, fee_id) VALUES (?, ?, ?, 'bank_transfer', ?, CURDATE(), ?, ?, ?, 'pending', ?)");
                $ptype = "dissertation_installment_$installment_num";
                $notes = "Dissertation fee installment $installment_num";
                $stmt->bind_param("ssdssssi", $student_id, $ptype, $amount, $payment_ref, $recorder, $notes, $proof_file, $fee_id);
                if ($stmt->execute()) {
                    $message = 'Proof of payment submitted successfully. Awaiting finance approval.';
                } else {
                    $error = 'Failed to save payment record.';
                }
            } else {
                $error = 'Failed to upload file. Please try again.';
            }
        } else {
            $error = 'Please provide valid payment details and upload a file.';
        }
    } elseif ($action === 'submit_topic') {
        $title = trim($_POST['title'] ?? '');
        $topic_area = trim($_POST['topic_area'] ?? '');
        $concept_text = trim($_POST['concept_note_text'] ?? '');
        
        if ($title && $topic_area) {
            // Check existing
            $check = $conn->query("SELECT dissertation_id FROM dissertations WHERE student_id = '$student_id' AND is_active = 1");
            if ($check && $check->num_rows > 0) {
                $error = 'You already have an active dissertation. Please wait for feedback on your current submission.';
            } else {
                $program = $student['program'] ?? '';
                $program_type = $student['program_type'] ?? '';
                $year = date('Y');
                // Map semester to valid ENUM('One','Two') values
                $raw_semester = $student['semester'] ?? 'One';
                $semester_map = ['1' => 'One', '2' => 'Two', 'one' => 'One', 'two' => 'Two', 
                    'first' => 'One', 'second' => 'Two', 'semester 1' => 'One', 'semester 2' => 'Two',
                    'sem 1' => 'One', 'sem 2' => 'Two', 'i' => 'One', 'ii' => 'Two'];
                $semester = $semester_map[strtolower(trim($raw_semester))] ?? (in_array($raw_semester, ['One', 'Two']) ? $raw_semester : 'One');
                $year_of_study = $student['year_of_study'] ?? 3;
                
                // Handle concept note file upload
                $concept_file = null;
                if (isset($_FILES['concept_file']) && $_FILES['concept_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = '../uploads/dissertations/concept_notes/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $ext = strtolower(pathinfo($_FILES['concept_file']['name'], PATHINFO_EXTENSION));
                    $safe_id = preg_replace('/[^A-Za-z0-9_-]/', '_', $student_id);
                    $filename = 'concept_' . $safe_id . '_' . time() . '.' . $ext;
                    $target_path = $upload_dir . $filename;
                    $target_dir = dirname($target_path);
                    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                    if (move_uploaded_file($_FILES['concept_file']['tmp_name'], $target_path)) {
                        $concept_file = 'uploads/dissertations/concept_notes/' . $filename;
                    }
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO dissertations (student_id, user_id, title, topic_area, program, program_type, 
                        academic_year, semester, year_of_study, status, current_phase,
                        concept_note_text, concept_note_file, topic_submitted_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'topic_submission', 'topic', ?, ?, NOW())
                ");
                $uid = $_SESSION['vle_user_id'] ?? 0;
                $stmt->bind_param("sissssssiss", $student_id, $uid, $title, $topic_area, $program, 
                    $program_type, $year, $semester, $year_of_study, $concept_text, $concept_file);
                
                if ($stmt->execute()) {
                    $message = 'Your dissertation topic has been submitted successfully. Please wait for the Research Coordinator to review it.';
                } else {
                    $error = 'Failed to submit topic. Please try again.';
                }
            }
        } else {
            $error = 'Please provide a title and topic area.';
        }
    } elseif ($action === 'submit_chapter') {
        $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
        $phase = $_POST['phase'] ?? '';
        $submission_text = trim($_POST['submission_text'] ?? '');

        // Whitelist phase to prevent SQL injection
        $valid_phases = ['concept_note','chapter1','chapter2','chapter3','proposal','ethics','defense','chapter4','chapter5','final_draft','presentation','final_submission'];
        if (!in_array($phase, $valid_phases, true)) {
            $error = 'Invalid submission phase.';
        } else {
        // Verify ownership
        $own_check = $conn->query("SELECT * FROM dissertations WHERE dissertation_id = $dissertation_id AND student_id = '$student_id' AND is_active = 1");
        $dissertation = $own_check ? $own_check->fetch_assoc() : null;
        
        if (!$dissertation) {
            $error = 'Invalid dissertation.';
        } else {
            // Get version number
            $ver = $conn->query("SELECT MAX(version) as max_v FROM dissertation_submissions WHERE dissertation_id = $dissertation_id AND phase = '$phase'");
            $version = ($ver && $v = $ver->fetch_assoc()) ? (int)$v['max_v'] + 1 : 1;
            
            $file_path = null;
            $file_name = null;
            $file_size = 0;
            $file_type = null;
            
            if (isset($_FILES['chapter_file']) && $_FILES['chapter_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/dissertations/chapters/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $ext = strtolower(pathinfo($_FILES['chapter_file']['name'], PATHINFO_EXTENSION));
                $presentation_phases = ['presentation', 'defense'];
                $allowed_chapter_ext = ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt'];
                if (in_array($phase, $presentation_phases, true)) {
                    $allowed_chapter_ext = array_merge($allowed_chapter_ext, ['ppt', 'pptx']);
                }
                if (!in_array($ext, $allowed_chapter_ext, true)) {
                    $error = in_array($phase, $presentation_phases, true)
                        ? 'Invalid file type. Allowed formats: PDF, DOC, DOCX, ODT, RTF, TXT, PPT, PPTX.'
                        : 'Invalid file type. Allowed formats: PDF, DOC, DOCX, ODT, RTF, TXT.';
                } elseif ($_FILES['chapter_file']['size'] > 20 * 1024 * 1024) {
                    $error = 'File size must not exceed 20MB.';
                } else {
                // Build filename: LastFirst_StudentID_Chapter_YYMMDD_vN
                // Name: spaces→underscore; ID: special chars→dash (BBA/24/LL/ME/002 → BBA-24-LL-ME-002)
                $safe_name = preg_replace('/_+/', '_', trim(preg_replace('/[^A-Za-z0-9]/', '_', $student['full_name'] ?? 'Student'), '_'));
                $safe_id   = preg_replace('/-+/', '-', trim(preg_replace('/[^A-Za-z0-9]/', '-', $student_id), '-'));
                $chapter_label = $phase_config[$phase]['label'] ?? $phase;
                $safe_chapter  = preg_replace('/_+/', '_', trim(preg_replace('/[^A-Za-z0-9]/', '_', $chapter_label), '_'));
                $date_str = date('ymd');
                $ver_str  = 'v' . $version;
                $filename = $safe_name . '_' . $safe_id . '_' . $safe_chapter . '_' . $date_str . '_' . $ver_str . '.' . $ext;
                $target_path = $upload_dir . $filename;
                $target_dir = dirname($target_path);
                if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                if (move_uploaded_file($_FILES['chapter_file']['tmp_name'], $target_path)) {
                    $file_path = 'uploads/dissertations/chapters/' . $filename;
                    $file_name = $filename;
                    $file_size = $_FILES['chapter_file']['size'];
                    $file_type = $_FILES['chapter_file']['type'];
                }
                } // end extension/size check else
            }
            
            if (!$file_path && empty($submission_text)) {
                $error = 'Please upload a file or enter text for your submission.';
            } else {
                $word_count = str_word_count($submission_text);
                
                $stmt = $conn->prepare("
                    INSERT INTO dissertation_submissions 
                    (dissertation_id, phase, version, file_path, file_name, file_size, file_type,
                     submission_text, word_count, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted')
                ");
                $stmt->bind_param("isisisssi", $dissertation_id, $phase, $version, $file_path, $file_name,
                    $file_size, $file_type, $submission_text, $word_count);
                
                if ($stmt->execute()) {
                    // Update dissertation status
                    $status_map = [
                        'concept_note' => 'concept_note_submitted',
                        'chapter1' => 'chapter1_submitted', 'chapter2' => 'chapter2_submitted',
                        'chapter3' => 'chapter3_submitted', 'proposal' => 'proposal_submitted',
                        'ethics' => 'ethics_submitted', 'chapter4' => 'chapter4_submitted',
                        'chapter5' => 'chapter5_submitted', 'final_draft' => 'final_draft_submitted',
                        'presentation' => 'presentation_submitted', 'final_submission' => 'final_submitted'
                    ];
                    $new_status = $status_map[$phase] ?? $dissertation['status'];
                    $conn->query("UPDATE dissertations SET status = '$new_status', updated_at = NOW() WHERE dissertation_id = $dissertation_id");
                    
                    $message = 'Your ' . ($phase_config[$phase]['label'] ?? $phase) . ' has been submitted (Version ' . $version . '). Your supervisor will review it.';
                } else {
                    $error = 'Failed to submit. Please try again.';
                }
            }
        }
        } // end valid phase whitelist check
    } elseif ($action === 'submit_ethics') {
        $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
        $ethics_text = trim($_POST['ethics_summary'] ?? '');
        
        $own_check = $conn->prepare("SELECT * FROM dissertations WHERE dissertation_id = ? AND student_id = ? AND is_active = 1");
        $own_check->bind_param("is", $dissertation_id, $student_id);
        $own_check->execute();
        if ($own_check->get_result()->num_rows > 0) {
            $ethics_file = null;
            if (isset($_FILES['ethics_file']) && $_FILES['ethics_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/dissertations/ethics/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $ext = strtolower(pathinfo($_FILES['ethics_file']['name'], PATHINFO_EXTENSION));
                $allowed_ethics_ext = ['pdf', 'doc', 'docx', 'odt'];
                if (!in_array($ext, $allowed_ethics_ext, true)) {
                    $error = 'Invalid file type. Allowed formats: PDF, DOC, DOCX, ODT.';
                } else {
                $safe_id = str_replace('/', '_', $student_id);
                $filename = 'ethics_' . $safe_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['ethics_file']['tmp_name'], $upload_dir . $filename)) {
                    $ethics_file = 'uploads/dissertations/ethics/' . $filename;
                }
                } // end extension check
            }
            
            // Ensure columns exist
            $conn->query("ALTER TABLE dissertation_ethics ADD COLUMN IF NOT EXISTS submitted_by INT DEFAULT NULL");
            $conn->query("ALTER TABLE dissertation_ethics ADD COLUMN IF NOT EXISTS research_summary TEXT DEFAULT NULL");
            
            $stmt = $conn->prepare("
                INSERT INTO dissertation_ethics (dissertation_id, submitted_by, ethics_form_path, research_summary, status, submitted_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ");
            $uid = $_SESSION['vle_user_id'] ?? 0;
            $stmt->bind_param("iiss", $dissertation_id, $uid, $ethics_file, $ethics_text);
            
            if ($stmt->execute()) {
                $conn->query("UPDATE dissertations SET status = 'ethics_submitted', updated_at = NOW() WHERE dissertation_id = " . (int)$dissertation_id);
                $message = 'Ethics clearance request submitted successfully.';
            }
        }
    } elseif ($action === 'upload_ethical_form') {
        $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
        $own_check = $conn->prepare("SELECT * FROM dissertations WHERE dissertation_id = ? AND student_id = ? AND is_active = 1");
        $own_check->bind_param("is", $dissertation_id, $student_id);
        $own_check->execute();
        if ($own_check->get_result()->num_rows > 0) {
            if (isset($_FILES['ethical_form_file']) && $_FILES['ethical_form_file']['error'] === UPLOAD_ERR_OK) {
                $allowed_ext = ['pdf', 'doc', 'docx'];
                $ext = strtolower(pathinfo($_FILES['ethical_form_file']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext)) {
                    $error = 'Only PDF, DOC, and DOCX files are allowed.';
                } elseif ($_FILES['ethical_form_file']['size'] > 10 * 1024 * 1024) {
                    $error = 'File size must not exceed 10MB.';
                } else {
                    $upload_dir = '../uploads/dissertations/ethics/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $safe_id = preg_replace('/[^A-Za-z0-9_-]/', '_', $student_id);
                    $filename = 'ethical_form_' . $safe_id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['ethical_form_file']['tmp_name'], $upload_dir . $filename)) {
                        $ethics_path = 'uploads/dissertations/ethics/' . $filename;
                        $ethics_summary = trim($_POST['ethics_notes'] ?? '');
                        $uid = $_SESSION['vle_user_id'] ?? 0;
                        // Ensure table and columns exist
                        $conn->query("CREATE TABLE IF NOT EXISTS dissertation_ethics (
                            ethics_id INT AUTO_INCREMENT PRIMARY KEY,
                            dissertation_id INT NOT NULL,
                            submitted_by INT DEFAULT NULL,
                            ethics_form_path VARCHAR(500) DEFAULT NULL,
                            research_summary TEXT DEFAULT NULL,
                            status VARCHAR(30) DEFAULT 'pending',
                            reviewer_notes TEXT DEFAULT NULL,
                            reviewed_by INT DEFAULT NULL,
                            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            reviewed_at TIMESTAMP NULL DEFAULT NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                        $conn->query("ALTER TABLE dissertation_ethics ADD COLUMN IF NOT EXISTS submitted_by INT DEFAULT NULL");
                        $conn->query("ALTER TABLE dissertation_ethics ADD COLUMN IF NOT EXISTS research_summary TEXT DEFAULT NULL");
                        $stmt = $conn->prepare("INSERT INTO dissertation_ethics (dissertation_id, submitted_by, ethics_form_path, research_summary, status, submitted_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
                        $stmt->bind_param("iiss", $dissertation_id, $uid, $ethics_path, $ethics_summary);
                        if ($stmt->execute()) {
                            $message = 'Ethical form uploaded successfully and submitted for review.';
                        } else {
                            $error = 'Failed to save ethical form record.';
                        }
                    } else {
                        $error = 'Failed to upload file. Please try again.';
                    }
                }
            } else {
                $error = 'Please select an ethical form file to upload.';
            }
        } else {
            $error = 'Invalid dissertation.';
        }
    } elseif ($action === 'request_reference_letter') {
        $dissertation_id = (int)($_POST['dissertation_id'] ?? 0);
        $own_check = $conn->prepare("SELECT * FROM dissertations WHERE dissertation_id = ? AND student_id = ? AND is_active = 1");
        $own_check->bind_param("is", $dissertation_id, $student_id);
        $own_check->execute();
        if ($own_check->get_result()->num_rows > 0) {
            $letter_type = in_array($_POST['letter_type'] ?? '', ['data_collection', 'case_study', 'institutional_access', 'other']) ? $_POST['letter_type'] : 'data_collection';
            $addressed_to = trim($_POST['addressed_to'] ?? '');
            $organization = trim($_POST['organization'] ?? '');
            $purpose = trim($_POST['purpose'] ?? '');
            $period = trim($_POST['data_collection_period'] ?? '');
            if ($addressed_to && $organization && $purpose) {
                $stmt = $conn->prepare("INSERT INTO dissertation_reference_letters (dissertation_id, student_id, letter_type, addressed_to, organization, purpose, study_description, data_collection_period, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $study_desc = '';
                $stmt->bind_param("isssssss", $dissertation_id, $student_id, $letter_type, $addressed_to, $organization, $purpose, $study_desc, $period);
                if ($stmt->execute()) {
                    $message = 'Reference letter request submitted. You will be notified when it is approved.';
                } else {
                    $error = 'Failed to submit request. Please try again.';
                }
            } else {
                $error = 'Please fill in all required fields for the reference letter.';
            }
        }
    }
}

// Get student's dissertation
$dissertation = null;
$r = $conn->query("
    SELECT d.*, l.full_name as supervisor_name, l.email as supervisor_email,
           l2.full_name as co_supervisor_name
    FROM dissertations d
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    LEFT JOIN lecturers l2 ON d.co_supervisor_id = l2.lecturer_id
    WHERE d.student_id = '$student_id' AND d.is_active = 1
    ORDER BY d.created_at DESC LIMIT 1
");
if ($r) $dissertation = $r->fetch_assoc();

// Get submissions if dissertation exists
$submissions = [];
$feedback = [];
$guidelines_data = [];
if ($dissertation) {
    $did = $dissertation['dissertation_id'];
    
    // Submissions
    $r = $conn->query("SELECT * FROM dissertation_submissions WHERE dissertation_id = $did ORDER BY phase, version DESC");
    if ($r) while ($row = $r->fetch_assoc()) $submissions[] = $row;
    
    // Feedback
    $r = $conn->query("
        SELECT df.*, COALESCE(l.full_name, u.username) as reviewer_name
        FROM dissertation_feedback df
        LEFT JOIN users u ON df.user_id = u.user_id
        LEFT JOIN lecturers l ON u.related_staff_id = l.lecturer_id AND u.role IN ('lecturer','staff')
        ORDER BY df.created_at DESC
    ");
    if ($r) while ($row = $r->fetch_assoc()) {
        if (!isset($row['dissertation_id']) || $row['dissertation_id'] == $did) {
            $feedback[] = $row;
        }
    }
    
    // All feedback for this dissertation
    $feedback = [];
    $r = $conn->query("
        SELECT df.*, 
               COALESCE(l.full_name, u.username) as reviewer_name
        FROM dissertation_feedback df
        LEFT JOIN users u ON df.user_id = u.user_id
        LEFT JOIN lecturers l ON u.related_staff_id = l.lecturer_id AND u.role IN ('lecturer','staff')
        WHERE df.dissertation_id = $did
        ORDER BY df.created_at DESC
    ");
    if ($r) while ($row = $r->fetch_assoc()) $feedback[] = $row;
    
    // Guidelines for current phase
    $cur_phase = $dissertation['current_phase'] ?? 'topic';
    $r = $conn->query("SELECT * FROM dissertation_guidelines WHERE phase = '$cur_phase' AND is_active = 1 ORDER BY section_order");
    if ($r) while ($row = $r->fetch_assoc()) $guidelines_data[] = $row;
    
    // Also get formatting guidelines
    $r2 = $conn->query("SELECT * FROM dissertation_guidelines WHERE phase = 'formatting' AND is_active = 1 ORDER BY section_order");
    if ($r2) while ($row = $r2->fetch_assoc()) $guidelines_data[] = $row;
}

// Get defense info if applicable
$defense_info = null;
if ($dissertation) {
    $did = $dissertation['dissertation_id'];
    $r = $conn->query("SELECT * FROM dissertation_defense WHERE dissertation_id = $did ORDER BY defense_date DESC LIMIT 1");
    if ($r) $defense_info = $r->fetch_assoc();
}

// Get dissertation fee info
$diss_fee = null;
$fee_locks = ['supervisor' => false, 'ethics' => false, 'final' => false];
if ($dissertation) {
    $dft = $conn->query("SHOW TABLES LIKE 'dissertation_fees'");
    if ($dft && $dft->num_rows > 0) {
        $df_stmt = $conn->prepare("SELECT * FROM dissertation_fees WHERE student_id = ? AND dissertation_id = ? LIMIT 1");
        if ($df_stmt) {
            $df_stmt->bind_param("si", $student_id, $dissertation['dissertation_id']);
            $df_stmt->execute();
            $diss_fee = $df_stmt->get_result()->fetch_assoc();
        }
    }
    if ($diss_fee) {
        $inst_amt = (float)$diss_fee['installment_amount'];
        // Determine active locks: lock is active only when finance enabled it AND installment not paid
        $fee_locks['supervisor'] = $diss_fee['lock_after_supervisor'] && (float)$diss_fee['installment_1_paid'] < $inst_amt;
        $fee_locks['ethics'] = $diss_fee['lock_before_ethics'] && (float)$diss_fee['installment_2_paid'] < $inst_amt;
        $fee_locks['final'] = $diss_fee['lock_before_final'] && (float)$diss_fee['installment_3_paid'] < $inst_amt;
    }
}

$page_title = 'Dissertation';
$breadcrumbs = [['title' => 'Dissertation']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dissertation - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <?php include '../includes/tinymce_head.php'; ?>
    <style>
        .phase-timeline { position:relative; padding-left:40px; }
        .phase-timeline::before { content:''; position:absolute; left:15px; top:0; bottom:0; width:3px; background:#e5e7eb; }
        .phase-item { position:relative; margin-bottom:1.5rem; }
        .phase-dot { position:absolute; left:-33px; top:4px; width:26px; height:26px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.65rem; z-index:1; border:3px solid #fff; box-shadow:0 0 0 2px #e5e7eb; }
        .phase-dot.completed { background:#10b981; color:#fff; box-shadow:0 0 0 2px #10b981; }
        .phase-dot.active { background:#3b82f6; color:#fff; box-shadow:0 0 0 2px #3b82f6; animation:pulse 2s infinite; }
        .phase-dot.locked { background:#d1d5db; color:#9ca3af; }
        @keyframes pulse { 0%,100%{box-shadow:0 0 0 2px #3b82f6;} 50%{box-shadow:0 0 0 6px rgba(59,130,246,0.3);} }
        .upload-zone { border:2px dashed #cbd5e1; border-radius:12px; padding:2rem; text-align:center; transition:all 0.2s; cursor:pointer; }
        .upload-zone:hover { border-color:#3b82f6; background:#eff6ff; }
        .guideline-card { border-left:4px solid #6366f1; background:#f8fafc; }
        .feedback-item { border-left:4px solid #f59e0b; }
        .score-circle { width:60px; height:60px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; }
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

    <?php if (!$dissertation): ?>
    <!-- NO DISSERTATION YET — Show Topic Submission Form -->
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary bg-opacity-10 py-3">
                    <h4 class="mb-1"><i class="bi bi-mortarboard me-2 text-primary"></i>Start Your Dissertation</h4>
                    <p class="text-muted mb-0">Submit your proposed research topic and concept note to begin the dissertation process.</p>
                </div>
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="submit_topic">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Proposed Dissertation Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required placeholder="Enter your proposed title..." maxlength="500">
                            <div class="form-text">Be descriptive. You can refine it later with your supervisor.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Topic Area / Research Field <span class="text-danger">*</span></label>
                            <input type="text" name="topic_area" class="form-control" required placeholder="e.g., Educational Technology, Public Health, Computer Science...">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Concept Note</label>
                            <textarea name="concept_note_text" id="concept_note_editor" class="form-control" rows="8" placeholder="Describe your research idea, motivation, objectives, and expected outcomes..."></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">Concept Note Document (Optional)</label>
                            <input type="file" name="concept_file" class="form-control" accept=".doc,.docx,.pdf">
                            <div class="form-text">Upload a detailed concept note document (DOC, DOCX, or PDF).</div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>What happens next?</strong> The Research Coordinator will review your topic. 
                            Once approved, you will be assigned a supervisor and can begin writing Chapter 1.
                        </div>

                        <div class="mb-4">
                            <a href="dissertation_guidelines.php" class="btn btn-outline-primary w-100">
                                <i class="bi bi-book me-2"></i>Read Full Dissertation Guidelines
                            </a>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-send me-2"></i>Submit Dissertation Topic
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- HAS DISSERTATION — Show Progress Dashboard -->
    <?php
        $current_phase = $dissertation['current_phase'] ?? 'topic';
        $current_idx = array_search($current_phase, $phase_order);
        if ($current_idx === false) $current_idx = 0;
        $status = $dissertation['status'] ?? '';
        
        // Determine if student can submit for current phase
        $can_submit = false;
        $waiting_reason = '';
        
        // Check status to determine if submission is allowed
        if (strpos($status, '_approved') !== false || $status === 'supervisor_assigned' || 
            strpos($status, '_writing') !== false || $status === 'defense_passed') {
            $can_submit = true;
        } elseif (strpos($status, '_submitted') !== false || $status === 'under_review') {
            $waiting_reason = 'Your submission is being reviewed by your supervisor.';
        } elseif (strpos($status, '_revision') !== false) {
            $can_submit = true; // Can resubmit after revision request
            $waiting_reason = '';
        } elseif ($status === 'topic_submission') {
            $waiting_reason = 'Waiting for the Research Coordinator to review your topic.';
        } elseif ($status === 'concept_approved') {
            $waiting_reason = 'Your topic is approved. Waiting for supervisor assignment.';
        } elseif ($status === 'defense_scheduled') {
            $waiting_reason = 'Your defense has been scheduled. Please prepare for it.';
        } elseif ($status === 'defense_listed') {
            $waiting_reason = 'You have been listed for defense. Schedule details will follow.';
        } elseif ($status === 'completed') {
            $waiting_reason = 'Congratulations! Your dissertation is complete.';
        }
        
        // Gate: final_submission requires a passing final presentation defense grade
        if ($current_phase === 'final_submission' && $can_submit) {
            $def_gate = $conn->prepare("SELECT result FROM dissertation_defense WHERE dissertation_id = ? AND defense_type = 'final' AND result IN ('pass','conditional_pass') AND status = 'completed' LIMIT 1");
            $def_gate->bind_param("i", $dissertation['dissertation_id']);
            $def_gate->execute();
            if (!$def_gate->get_result()->fetch_assoc()) {
                $can_submit = false;
                $waiting_reason = 'You must complete your final presentation defense with a passing grade before submitting the final dissertation.';
            }
            $def_gate->close();
        }
        
        // Check dissertation fee locks (finance officer controlled)
        $fee_lock_active = false;
        $fee_lock_message = '';
        if ($diss_fee) {
            $supervisor_assigned = !empty($dissertation['supervisor_id']);
            // Lock 1: After supervisor assigned, 1st installment required
            if ($fee_locks['supervisor'] && $supervisor_assigned && !in_array($current_phase, ['topic'])) {
                $fee_lock_active = true;
                $fee_lock_message = 'Your 1st dissertation fee installment (MKW ' . number_format((float)$diss_fee['installment_amount']) . ') is required after supervisor assignment to continue. Please contact the finance office.';
                $can_submit = false;
            }
            // Lock 2: Before ethics, proposal defense phases
            if ($fee_locks['ethics'] && in_array($current_phase, ['ethics', 'defense', 'proposal'])) {
                $fee_lock_active = true;
                $fee_lock_message = 'Your 2nd dissertation fee installment (MKW ' . number_format((float)$diss_fee['installment_amount']) . ') is required before ethics submission and proposal defense. Please contact the finance office.';
                $can_submit = false;
            }
            // Lock 3: Before final submission/presentation
            if ($fee_locks['final'] && in_array($current_phase, ['final_draft', 'presentation', 'final_submission'])) {
                $fee_lock_active = true;
                $fee_lock_message = 'Your 3rd dissertation fee installment (MKW ' . number_format((float)$diss_fee['installment_amount']) . ') is required before final dissertation presentation. Please contact the finance office.';
                $can_submit = false;
            }
        }
    ?>
    <div class="row">
        <!-- Left: Phase Timeline & Guidelines -->
        <div class="col-lg-3">
            <!-- Progress Overview -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-bar-chart-steps me-1"></i>Progress</h6>
                </div>
                <div class="card-body">
                    <div class="phase-timeline">
                        <?php foreach ($phase_order as $idx => $p_key): ?>
                        <?php
                            $p = $phase_config[$p_key];
                            $is_completed = $idx < $current_idx;
                            $is_active = $idx === $current_idx;
                            $dot_class = $is_completed ? 'completed' : ($is_active ? 'active' : 'locked');
                        ?>
                        <div class="phase-item">
                            <div class="phase-dot <?= $dot_class ?>">
                                <?php if ($is_completed): ?>
                                    <i class="bi bi-check"></i>
                                <?php else: ?>
                                    <small><?= $idx + 1 ?></small>
                                <?php endif; ?>
                            </div>
                            <div>
                                <small class="fw-bold <?= $is_active ? 'text-primary' : ($is_completed ? 'text-success' : 'text-muted') ?>"><?= $p['label'] ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Guidelines for Current Phase -->
            <?php if (!empty($guidelines_data)): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-book me-1"></i>Guidelines</h6>
                    <a href="dissertation_guidelines.php" class="btn btn-sm btn-outline-primary" title="View all guidelines"><i class="bi bi-box-arrow-up-right"></i></a>
                </div>
                <div class="card-body" style="max-height:400px;overflow-y:auto;">
                    <?php foreach ($guidelines_data as $g): ?>
                    <div class="guideline-card p-2 mb-2 rounded">
                        <small class="fw-bold"><?= htmlspecialchars($g['section_title']) ?></small>
                        <?php if ($g['content']): ?>
                        <p class="mb-0 mt-1" style="font-size:0.75rem; color:#475569;"><?= nl2br(htmlspecialchars(mb_strimwidth($g['content'], 0, 300, '...'))) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Center: Main Content -->
        <div class="col-lg-6">
            <!-- Dissertation Header -->
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h4 class="fw-bold mb-1"><?= htmlspecialchars($dissertation['title'] ?? 'Untitled') ?></h4>
                    <p class="text-muted mb-2"><small>Topic Area: <?= htmlspecialchars($dissertation['topic_area'] ?? '') ?></small></p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-primary"><?= $phase_config[$current_phase]['label'] ?? ucfirst($current_phase) ?></span>
                        <span class="badge bg-<?= (strpos($status, 'approved') !== false || $status === 'completed') ? 'success' : ((strpos($status, 'revision') !== false || strpos($status, 'rejected') !== false) ? 'danger' : 'warning') ?>">
                            <?= ucfirst(str_replace('_', ' ', $status)) ?>
                        </span>
                        <?php if ($dissertation['supervisor_name']): ?>
                        <span class="badge bg-info"><i class="bi bi-person me-1"></i>Supervisor: <?= htmlspecialchars($dissertation['supervisor_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Waiting Message -->
            <?php if ($waiting_reason && !$can_submit && !$fee_lock_active): ?>
            <div class="alert alert-info d-flex align-items-center">
                <div class="spinner-border spinner-border-sm me-2 text-primary" role="status" style="width:16px;height:16px;"></div>
                <?= htmlspecialchars($waiting_reason) ?>
            </div>
            <?php endif; ?>

            <!-- Fee Lock Alert -->
            <?php if ($fee_lock_active): ?>
            <div class="alert alert-danger d-flex align-items-start">
                <i class="bi bi-lock-fill me-2 fs-5 mt-1"></i>
                <div>
                    <strong>Dissertation Access Restricted</strong>
                    <p class="mb-1"><?= htmlspecialchars($fee_lock_message) ?></p>
                    <a href="payment_history.php" class="btn btn-sm btn-outline-danger mt-1"><i class="bi bi-wallet2 me-1"></i>View Payment Status</a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Defense Info -->
            <?php if ($defense_info): ?>
            <div class="card shadow-sm mb-3 border-warning">
                <div class="card-body">
                    <h5 class="fw-bold"><i class="bi bi-mortarboard me-2 text-warning"></i>Defense</h5>
                    <div class="row">
                        <div class="col-6">
                            <small class="text-muted">Type</small>
                            <p class="mb-1 fw-bold"><?= ucfirst($defense_info['defense_type']) ?> Defense</p>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">Date</small>
                            <p class="mb-1 fw-bold"><?= $defense_info['defense_date'] ? date('M j, Y H:i', strtotime($defense_info['defense_date'])) : 'TBD' ?></p>
                        </div>
                        <?php if ($defense_info['venue']): ?>
                        <div class="col-6">
                            <small class="text-muted">Venue</small>
                            <p class="mb-1"><?= htmlspecialchars($defense_info['venue']) ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ($defense_info['result']): ?>
                        <div class="col-6">
                            <small class="text-muted">Result</small>
                            <p class="mb-1"><span class="badge bg-<?= $defense_info['result'] === 'pass' ? 'success' : ($defense_info['result'] === 'fail' ? 'danger' : 'warning') ?>"><?= ucfirst(str_replace('_',' ',$defense_info['result'])) ?></span>
                            <?php if ($defense_info['grade']): ?> — <?= number_format($defense_info['grade'], 1) ?>%<?php endif; ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Submission Form (if can submit & not topic phase) -->
            <?php if ($can_submit && !in_array($current_phase, ['topic'])): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="<?= $phase_config[$current_phase]['icon'] ?? 'bi-file-earmark' ?> me-2"></i>Submit: <?= $phase_config[$current_phase]['label'] ?? ucfirst($current_phase) ?></h5>
                </div>
                <div class="card-body">
                    <?php if ($current_phase === 'ethics'): ?>
                    <!-- Ethics Form -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Step 1:</strong> Download the pre-filled ethics form, complete all sections, then upload the filled form below.
                    </div>
                    <div class="d-flex gap-2 mb-3">
                        <a href="../api/generate_ethics_form.php?dissertation_id=<?= $dissertation['dissertation_id'] ?>" class="btn btn-outline-primary">
                            <i class="bi bi-download me-1"></i>Download Ethics Form (PDF)
                        </a>
                        <a href="ethics_form_online.php?dissertation_id=<?= $dissertation['dissertation_id'] ?>" class="btn btn-outline-success">
                            <i class="bi bi-pencil-square me-1"></i>Fill Online &amp; Submit
                        </a>
                    </div>
                    <hr>
                    <p class="fw-bold mb-2"><strong>Step 2:</strong> Upload completed ethics form</p>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="submit_ethics">
                        <input type="hidden" name="dissertation_id" value="<?= $dissertation['dissertation_id'] ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Research Summary for Ethics Review</label>
                            <textarea name="ethics_summary" id="ethics_summary_editor" class="form-control" rows="5" required placeholder="Provide a summary of your research methodology, participants, data collection methods, and ethical considerations..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Completed Ethics Form</label>
                            <input type="file" name="ethics_file" class="form-control" accept=".doc,.docx,.pdf" required>
                            <div class="form-text">Upload the completed and signed ethics clearance form (PDF or Word).</div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check me-2"></i>Submit Ethics Application</button>
                    </form>
                    <?php elseif ($current_phase === 'presentation'): ?>
                    <!-- Final Result Presentation Upload -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Final Result Presentation:</strong> Upload your PowerPoint presentation slides for your final dissertation results presentation.
                        You may also upload the final dissertation document alongside.
                    </div>

                    <?php
                    // Fetch available final defense templates
                    $final_templates = [];
                    $tpl_r2 = $conn->query("SELECT * FROM defense_templates WHERE template_type = 'final' AND is_active = 1 ORDER BY created_at DESC");
                    if ($tpl_r2) while ($tpl_row2 = $tpl_r2->fetch_assoc()) $final_templates[] = $tpl_row2;
                    ?>
                    <?php if (!empty($final_templates)): ?>
                    <div class="card border-success mb-3">
                        <div class="card-header bg-success bg-opacity-10">
                            <h6 class="mb-0"><i class="bi bi-download me-2 text-success"></i>Download Presentation Template</h6>
                        </div>
                        <div class="card-body py-2">
                            <p class="text-muted small mb-2">Use these official templates to prepare your final results presentation:</p>
                            <?php foreach ($final_templates as $tpl2): ?>
                            <div class="d-flex align-items-center justify-content-between border-bottom py-2">
                                <div>
                                    <i class="bi bi-file-earmark-slides text-warning me-2"></i>
                                    <strong><?= htmlspecialchars($tpl2['template_name']) ?></strong>
                                    <?php if (!empty($tpl2['description'])): ?>
                                        <br><small class="text-muted ms-4"><?= htmlspecialchars($tpl2['description']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <a href="../<?= htmlspecialchars($tpl2['file_path']) ?>" download class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-download me-1"></i>Download Template
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="submit_chapter">
                        <input type="hidden" name="dissertation_id" value="<?= $dissertation['dissertation_id'] ?>">
                        <input type="hidden" name="phase" value="presentation">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Upload Presentation Slides <span class="text-danger">*</span></label>
                            <input type="file" name="chapter_file" class="form-control" accept=".ppt,.pptx,.pdf,.doc,.docx" id="chapterFile" required>
                            <div class="form-text">
                                <i class="bi bi-file-earmark-slides me-1 text-primary"></i>
                                Upload your <strong>PowerPoint presentation (PPT/PPTX)</strong> or PDF slides.  
                                Ensure your slides cover: research objectives, methodology, key findings, analysis, conclusions &amp; recommendations.
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Presentation Notes (Optional)</label>
                            <textarea name="submission_text" id="submission_notes_editor" class="form-control" rows="4" placeholder="Add any notes about your presentation, key talking points, or comments for your supervisor..."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-easel me-2"></i>Submit Final Result Presentation
                        </button>
                    </form>

                    <?php elseif ($current_phase === 'defense'): ?>
                    <!-- Proposal Defense Submission -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Proposal Defense:</strong> Upload your proposal defense presentation slides (PowerPoint) and/or supporting documents.
                    </div>

                    <?php
                    // Fetch available proposal defense templates
                    $defense_templates = [];
                    $tpl_r = $conn->query("SELECT * FROM defense_templates WHERE template_type = 'proposal' AND is_active = 1 ORDER BY created_at DESC");
                    if ($tpl_r) while ($tpl_row = $tpl_r->fetch_assoc()) $defense_templates[] = $tpl_row;
                    ?>
                    <?php if (!empty($defense_templates)): ?>
                    <div class="card border-primary mb-3">
                        <div class="card-header bg-primary bg-opacity-10">
                            <h6 class="mb-0"><i class="bi bi-download me-2 text-primary"></i>Download Presentation Template</h6>
                        </div>
                        <div class="card-body py-2">
                            <p class="text-muted small mb-2">Use these official templates provided by the Research Coordinator to prepare your proposal defense slides:</p>
                            <?php foreach ($defense_templates as $tpl): ?>
                            <div class="d-flex align-items-center justify-content-between border-bottom py-2">
                                <div>
                                    <i class="bi bi-file-earmark-slides text-warning me-2"></i>
                                    <strong><?= htmlspecialchars($tpl['template_name']) ?></strong>
                                    <?php if (!empty($tpl['description'])): ?>
                                        <br><small class="text-muted ms-4"><?= htmlspecialchars($tpl['description']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <a href="../<?= htmlspecialchars($tpl['file_path']) ?>" download class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-download me-1"></i>Download Template
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="submit_chapter">
                        <input type="hidden" name="dissertation_id" value="<?= $dissertation['dissertation_id'] ?>">
                        <input type="hidden" name="phase" value="defense">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Upload Proposal Presentation <span class="text-danger">*</span></label>
                            <input type="file" name="chapter_file" class="form-control" accept=".ppt,.pptx,.pdf,.doc,.docx" id="chapterFile" required>
                            <div class="form-text">
                                <i class="bi bi-file-earmark-slides me-1 text-primary"></i>
                                Upload your <strong>PowerPoint presentation (PPT/PPTX)</strong> or PDF for the proposal defense.  
                                Your slides should cover: problem statement, objectives, literature review, methodology, and expected outcomes.
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Defense Notes (Optional)</label>
                            <textarea name="submission_text" id="submission_notes_editor" class="form-control" rows="4" placeholder="Add any notes or comments for your supervisor about this defense submission..."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-warning btn-lg w-100">
                            <i class="bi bi-mortarboard me-2"></i>Submit Proposal Defense Presentation
                        </button>
                    </form>

                    <?php else: ?>
                    <!-- Chapter/Proposal Submission -->
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="submit_chapter">
                        <input type="hidden" name="dissertation_id" value="<?= $dissertation['dissertation_id'] ?>">
                        <input type="hidden" name="phase" value="<?= $current_phase ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Upload Document <span class="text-danger">*</span></label>
                            <input type="file" name="chapter_file" class="form-control" accept=".doc,.docx,.pdf" id="chapterFile">
                            <div class="form-text">
                                Required format: <strong>Times New Roman, 12pt</strong>, 1.5 line spacing, 
                                margins 2.5cm (top/right/bottom) and 3cm (left). Upload DOC, DOCX, or PDF.
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Submission Notes (Optional)</label>
                            <textarea name="submission_text" id="submission_notes_editor" class="form-control" rows="4" placeholder="Add any notes or comments for your supervisor about this submission..."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-cloud-upload me-2"></i>Submit <?= $phase_config[$current_phase]['label'] ?? ucfirst($current_phase) ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Previous Submissions -->
            <?php if (!empty($submissions)): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-files me-2"></i>My Submissions</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Phase</th>
                                    <th>Version</th>
                                    <th>File</th>
                                    <th>Status</th>
                                    <th>Integrity Check</th>
                                    <th>Submitted</th>
                                    <th>Approved</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($submissions as $sub): ?>
                                <?php
                                    $sc = 'secondary';
                                    if ($sub['status'] === 'approved') $sc = 'success';
                                    elseif ($sub['status'] === 'revision_requested') $sc = 'warning';
                                    elseif ($sub['status'] === 'rejected') $sc = 'danger';
                                    elseif ($sub['status'] === 'submitted') $sc = 'primary';

                                    // Fetch latest integrity check for this submission
                                    $sub_sim = null;
                                    if (!empty($sub['similarity_check_id'])) {
                                        $sc_q = $conn->query("SELECT similarity_score, ai_detection_score, checked_at FROM dissertation_similarity_checks WHERE check_id = " . (int)$sub['similarity_check_id']);
                                        if ($sc_q) $sub_sim = $sc_q->fetch_assoc();
                                    }
                                ?>
                                <tr>
                                    <td><span class="badge bg-secondary"><?= ucfirst(str_replace('_',' ',$sub['phase'])) ?></span></td>
                                    <td>v<?= $sub['version'] ?></td>
                                    <td>
                                        <?php if ($sub['file_path']): ?>
                                            <?php
                                            $stu_ext = strtolower(pathinfo($sub['file_name'] ?? '', PATHINFO_EXTENSION));
                                            if (!$stu_ext) $stu_ext = strtolower(pathinfo($sub['file_path'] ?? '', PATHINFO_EXTENSION));
                                            $stu_display = ($sub['file_name'] && $sub['file_name'] !== '0') ? $sub['file_name'] : basename($sub['file_path']);
                                            ?>
                                            <a href="../<?= htmlspecialchars($sub['file_path']) ?>" download class="text-decoration-none me-1" title="Download">
                                                <i class="bi bi-download me-1"></i><?= htmlspecialchars(mb_strimwidth($stu_display, 0, 25, '...')) ?>
                                            </a>
                                            <button class="btn btn-sm btn-outline-success py-0 px-1" onclick="openDocEditor('<?= htmlspecialchars($sub['file_path']) ?>', '<?= htmlspecialchars($stu_display, ENT_QUOTES) ?>')" title="View & Edit">
                                                <i class="bi bi-pencil-square" style="font-size:0.7rem;"></i>
                                            </button>
                                        <?php else: ?>
                                            <small class="text-muted">Text only</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-<?= $sc ?>"><?= ucfirst(str_replace('_',' ',$sub['status'])) ?></span></td>
                                    <td>
                                        <?php if ($sub_sim): ?>
                                            <?php
                                            $ss = (float)$sub_sim['similarity_score'];
                                            $as = (float)$sub_sim['ai_detection_score'];
                                            $sb = $ss >= 25 ? 'danger' : ($ss >= 15 ? 'warning' : 'success');
                                            $ab = $as >= 40 ? 'danger' : ($as >= 20 ? 'warning' : 'success');
                                            ?>
                                            <span class="badge bg-<?= $sb ?>" title="Similarity (plagiarism check)" style="font-size:0.65rem;">
                                                <i class="bi bi-shield-check me-1"></i><?= $ss ?>%
                                            </span>
                                            <span class="badge bg-<?= $ab ?>" title="AI-generated content likelihood" style="font-size:0.65rem;">
                                                <i class="bi bi-robot me-1"></i><?= $as ?>%
                                            </span>
                                            <br><small class="text-muted" style="font-size:0.65rem;"><?= !empty($sub_sim['checked_at']) ? date('M j, Y', strtotime($sub_sim['checked_at'])) : '' ?></small>
                                        <?php else: ?>
                                            <small class="text-muted" style="font-size:0.7rem;">Not checked yet</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= $sub['submitted_at'] ? date('M j, Y \a\t H:i', strtotime($sub['submitted_at'])) : '-' ?></small></td>
                                    <td><small><?= ($sub['status'] === 'approved' && !empty($sub['reviewed_at'])) ? date('M j, Y \a\t H:i', strtotime($sub['reviewed_at'])) : '-' ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Right: Feedback & Similarity -->
        <div class="col-lg-3">
            <!-- Supervisor Info -->
            <?php if ($dissertation['supervisor_name']): ?>
                    <!-- Proof of Payment Upload for Dissertation Fee -->
                    <?php if ($diss_fee): ?>
                    <div class="card mb-3 border-success">
                        <div class="card-header bg-success bg-opacity-10 py-2">
                            <h6 class="mb-0"><i class="bi bi-cash me-1"></i>Dissertation Fee Payment</h6>
                            <small class="text-muted">Upload proof of payment for each installment below. Finance will review and approve.</small>
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="submit_fee_proof">
                                <input type="hidden" name="dissertation_id" value="<?= (int)$dissertation['dissertation_id'] ?>">
                                <div class="mb-3">
                                    <label class="form-label">Installment</label>
                                    <select name="installment_num" class="form-select" required>
                                        <option value="1">1st Installment (After Supervisor)</option>
                                        <option value="2">2nd Installment (Before Ethics/Defense)</option>
                                        <option value="3">3rd Installment (Before Final Presentation)</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Amount Paid (MKW)</label>
                                    <input type="number" name="amount" class="form-control" min="1000" step="100" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Bank Slip / Proof of Payment <span class="text-danger">*</span></label>
                                    <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                                    <div class="form-text">Upload a clear bank slip, screenshot, or PDF proof (Max 5MB).</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Payment Reference / Notes</label>
                                    <input type="text" name="payment_ref" class="form-control" maxlength="120" placeholder="Bank reference, transaction ID, or notes">
                                </div>
                                <button type="submit" class="btn btn-success"><i class="bi bi-upload me-1"></i>Submit Proof of Payment</button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
            <div class="card shadow-sm mb-3">
                <div class="card-body text-center">
                    <div style="width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;margin:0 auto 0.5rem;color:#fff;font-size:1.2rem;font-weight:700;">
                        <?= strtoupper(substr($dissertation['supervisor_name'], 0, 1)) ?>
                    </div>
                    <h6 class="fw-bold mb-0"><?= htmlspecialchars($dissertation['supervisor_name']) ?></h6>
                    <small class="text-muted">Supervisor</small>
                    <?php if ($dissertation['co_supervisor_name']): ?>
                    <hr class="my-2">
                    <small class="text-muted">Co-Supervisor</small><br>
                    <small class="fw-bold"><?= htmlspecialchars($dissertation['co_supervisor_name']) ?></small>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Dissertation Fee Status -->
            <?php if ($diss_fee): ?>
            <div class="card shadow-sm mb-3" style="border-left: 3px solid #8b5cf6;">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-wallet2 me-1"></i>Dissertation Fee</h6>
                </div>
                <div class="card-body py-2">
                    <?php
                    $df_pct = $diss_fee['fee_amount'] > 0 ? round(($diss_fee['total_paid'] / $diss_fee['fee_amount']) * 100) : 0;
                    $df_inst = (float)$diss_fee['installment_amount'];
                    ?>
                    <div class="text-center mb-2">
                        <small class="text-muted">MKW <?= number_format($diss_fee['total_paid']) ?> / <?= number_format($diss_fee['fee_amount']) ?></small>
                        <div class="progress mt-1" style="height:6px;">
                            <div class="progress-bar" style="width:<?= $df_pct ?>%;background:linear-gradient(135deg,#8b5cf6,#7c3aed)"></div>
                        </div>
                        <small class="text-muted"><?= $df_pct ?>% paid</small>
                    </div>
                    <?php
                    $inst_info = [
                        1 => ['label' => '1st', 'desc' => 'After Supervisor'],
                        2 => ['label' => '2nd', 'desc' => 'Before Ethics'],
                        3 => ['label' => '3rd', 'desc' => 'Before Final']
                    ];
                    for ($i = 1; $i <= 3; $i++):
                        $ip = (float)$diss_fee["installment_{$i}_paid"];
                        $ipct = $df_inst > 0 ? min(100, round(($ip / $df_inst) * 100)) : 0;
                    ?>
                    <div class="d-flex justify-content-between align-items-center py-1 <?= $i < 3 ? 'border-bottom' : '' ?>" style="font-size:0.78rem;">
                        <div>
                            <strong><?= $inst_info[$i]['label'] ?></strong>
                            <span class="text-muted">(<?= $inst_info[$i]['desc'] ?>)</span>
                        </div>
                        <?php if ($ip >= $df_inst): ?>
                            <span class="badge bg-success" style="font-size:0.65rem">Paid</span>
                        <?php elseif ($ip > 0): ?>
                            <span class="badge bg-warning" style="font-size:0.65rem"><?= $ipct ?>%</span>
                        <?php else: ?>
                            <span class="badge bg-secondary" style="font-size:0.65rem">Pending</span>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                    <?php if ($diss_fee['balance'] > 0): ?>
                    <div class="mt-2 text-center">
                        <small class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Balance: MKW <?= number_format($diss_fee['balance']) ?></small>
                    </div>
                    <?php endif; ?>
                    <a href="payment_history.php" class="btn btn-sm btn-outline-secondary w-100 mt-2"><i class="bi bi-receipt me-1"></i>View Details</a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Exam Clearance -->
            <div class="card shadow-sm mb-3" style="border-left: 3px solid #10b981;">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-shield-check me-1"></i>Exam Clearance</h6>
                </div>
                <div class="card-body py-2 text-center">
                    <p class="text-muted small mb-2">Apply for examination clearance to sit for your exams.</p>
                    <a href="exam_clearance.php" class="btn btn-sm btn-success w-100"><i class="bi bi-shield-check me-1"></i>Apply for Exam Clearance</a>
                </div>
            </div>

            <!-- Feedback -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-chat-left-quote me-1"></i>Supervisor Feedback</h6>
                </div>
                <div class="card-body" style="max-height:500px;overflow-y:auto;">
                    <?php if (empty($feedback)): ?>
                        <p class="text-muted text-center small">No feedback yet.</p>
                    <?php else: ?>
                        <?php 
                        // Show unread revision requests prominently
                        $revision_fbs = array_filter($feedback, fn($f) => ($f['feedback_type'] ?? '') === 'revision_request');
                        if (!empty($revision_fbs)):
                            $latest_rev = reset($revision_fbs);
                        ?>
                        <div class="alert alert-warning py-2 mb-3" style="font-size:0.82rem;">
                            <i class="bi bi-exclamation-triangle me-1"></i><strong>Revision Requested</strong> — Please address the feedback below and resubmit.
                        </div>
                        <?php endif; ?>
                        
                        <?php foreach ($feedback as $fb): ?>
                        <?php 
                        $ft = $fb['feedback_type'] ?? '';
                        $border = $ft === 'approval' ? '#10b981' : ($ft === 'revision_request' ? '#f59e0b' : ($ft === 'comment' ? '#3b82f6' : '#818cf8'));
                        $bg = $ft === 'approval' ? '#f0fdf4' : ($ft === 'revision_request' ? '#fffbeb' : ($ft === 'comment' ? '#eff6ff' : '#f8fafc'));
                        $badge_color = $ft === 'approval' ? 'success' : ($ft === 'revision_request' ? 'warning' : 'info');
                        ?>
                        <div class="p-2 mb-2 rounded" style="border-left:4px solid <?= $border ?>; background:<?= $bg ?>;">
                            <div class="d-flex justify-content-between">
                                <small class="fw-bold"><?= htmlspecialchars($fb['reviewer_name'] ?? 'Supervisor') ?></small>
                                <span class="badge bg-<?= $badge_color ?>" style="font-size:0.6rem;">
                                    <?= ucfirst(str_replace('_', ' ', $ft ?: 'Comment')) ?>
                                </span>
                            </div>
                            <small class="text-muted"><?= ucfirst(str_replace('_',' ',$fb['phase'] ?? '')) ?></small>
                            <p class="mb-0 mt-1" style="font-size:0.8rem;"><?= nl2br(htmlspecialchars($fb['feedback_text'] ?? '')) ?></p>
                            <?php if (!empty($fb['attachment_path'])): ?>
                            <div class="mt-1">
                                <a href="../<?= htmlspecialchars($fb['attachment_path']) ?>" download class="text-decoration-none" style="font-size:0.78rem;">
                                    <i class="bi bi-download me-1 text-primary"></i><?= htmlspecialchars(basename($fb['attachment_path'])) ?>
                                </a>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($fb['flagged_sections'])): ?>
                            <div class="mt-1 p-1 rounded" style="background:rgba(239,68,68,0.08); font-size:0.75rem;">
                                <i class="bi bi-flag text-danger me-1"></i><strong class="text-danger">Sections to fix:</strong><br>
                                <?php
                                    $fs_decoded = json_decode($fb['flagged_sections'], true);
                                    if (is_array($fs_decoded)) {
                                        foreach ($fs_decoded as $fs_item) {
                                            echo '<span class="badge bg-danger bg-opacity-10 text-danger me-1 mb-1" style="font-size:0.7rem;">' . htmlspecialchars($fs_item) . '</span>';
                                        }
                                    } else {
                                        echo nl2br(htmlspecialchars($fb['flagged_sections']));
                                    }
                                ?>
                            </div>
                            <?php endif; ?>
                            <small class="text-muted"><?= $fb['created_at'] ? date('M j, Y H:i', strtotime($fb['created_at'])) : '' ?></small>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Similarity Check Results (latest) -->
            <?php
            $latest_sim = null;
            if ($dissertation) {
                $did = $dissertation['dissertation_id'];
                $r = $conn->query("SELECT * FROM dissertation_similarity_checks WHERE dissertation_id = $did ORDER BY checked_at DESC LIMIT 1");
                if ($r) $latest_sim = $r->fetch_assoc();
            }
            ?>
            <?php if ($latest_sim): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-shield-check me-1"></i>Similarity Check</h6>
                </div>
                <div class="card-body text-center">
                    <div class="d-flex justify-content-center gap-3">
                        <?php
                            $sim_s = $latest_sim['similarity_score'];
                            $ai_s = $latest_sim['ai_detection_score'];
                            $sim_c = $sim_s >= 25 ? '#dc2626' : ($sim_s >= 15 ? '#f59e0b' : '#10b981');
                            $ai_c = $ai_s >= 40 ? '#dc2626' : ($ai_s >= 20 ? '#f59e0b' : '#10b981');
                        ?>
                        <div>
                            <div class="score-circle mx-auto" style="background:<?= $sim_c ?>20; color:<?= $sim_c ?>; font-size:0.9rem;"><?= $sim_s ?>%</div>
                            <small class="text-muted d-block mt-1">Similarity</small>
                        </div>
                        <div>
                            <div class="score-circle mx-auto" style="background:<?= $ai_c ?>20; color:<?= $ai_c ?>; font-size:0.9rem;"><?= $ai_s ?>%</div>
                            <small class="text-muted d-block mt-1">AI Score</small>
                        </div>
                    </div>
                    <small class="text-muted mt-2 d-block">Last checked: <?= !empty($latest_sim['checked_at']) ? date('M j, Y', strtotime($latest_sim['checked_at'])) : 'N/A' ?></small>
                </div>
            </div>
            <?php endif; ?>

            <!-- Ethical Forms Download -->
            <?php
            $ethical_forms = [];
            $ef_r = $conn->query("SELECT * FROM dissertation_ethical_forms WHERE is_active = 1 ORDER BY form_type ASC");
            if ($ef_r) while ($ef_row = $ef_r->fetch_assoc()) $ethical_forms[] = $ef_row;
            ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-medical me-1"></i>Ethics Forms</h6>
                </div>
                <div class="card-body">
                    <?php if ($dissertation): ?>
                    <a href="../api/generate_ethics_form.php?dissertation_id=<?= $dissertation['dissertation_id'] ?>" class="btn btn-sm btn-primary w-100 mb-2">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Generate Ethics Form (PDF)
                    </a>
                    <a href="ethics_form_online.php?dissertation_id=<?= $dissertation['dissertation_id'] ?>" class="btn btn-sm btn-outline-success w-100 mb-2">
                        <i class="bi bi-pencil-square me-1"></i>Fill Ethics Form Online
                    </a>
                    <?php if (!empty($ethical_forms)): ?>
                    <hr class="my-2">
                    <small class="text-muted d-block mb-1">Additional Templates:</small>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php foreach ($ethical_forms as $ef): ?>
                    <div class="d-flex align-items-center justify-content-between py-2 <?= ($ef !== end($ethical_forms)) ? 'border-bottom' : '' ?>">
                        <div>
                            <small class="fw-bold d-block"><?= htmlspecialchars($ef['form_name']) ?></small>
                            <span class="badge bg-<?= $ef['is_required'] ? 'danger' : 'secondary' ?>" style="font-size:0.65rem"><?= $ef['is_required'] ? 'Required' : 'Optional' ?></span>
                        </div>
                        <a href="../<?= htmlspecialchars($ef['file_path']) ?>" download class="btn btn-sm btn-outline-primary" title="Download">
                            <i class="bi bi-download"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($dissertation): ?>
                    <!-- Upload Completed Ethical Form -->
                    <hr class="my-2">
                    <?php
                    // Check for existing ethical form uploads
                    $existing_ethics = null;
                    $ee_check = $conn->query("SHOW TABLES LIKE 'dissertation_ethics'");
                    if ($ee_check && $ee_check->num_rows > 0) {
                        $ee_stmt = $conn->prepare("SELECT * FROM dissertation_ethics WHERE dissertation_id = ? ORDER BY submitted_at DESC LIMIT 1");
                        if ($ee_stmt) {
                            $ee_stmt->bind_param("i", $dissertation['dissertation_id']);
                            $ee_stmt->execute();
                            $existing_ethics = $ee_stmt->get_result()->fetch_assoc();
                        }
                    }
                    ?>
                    <?php if ($existing_ethics): ?>
                    <div class="mb-2">
                        <small class="fw-bold d-block mb-1"><i class="bi bi-upload me-1"></i>Your Submitted Form:</small>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-<?= $existing_ethics['status'] === 'approved' ? 'success' : ($existing_ethics['status'] === 'rejected' ? 'danger' : 'warning') ?>">
                                <?= ucfirst(htmlspecialchars($existing_ethics['status'])) ?>
                            </span>
                            <?php if (!empty($existing_ethics['ethics_form_path'])): ?>
                            <a href="../<?= htmlspecialchars($existing_ethics['ethics_form_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($existing_ethics['reviewer_notes'])): ?>
                        <small class="text-muted d-block mt-1"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($existing_ethics['reviewer_notes']) ?></small>
                        <?php endif; ?>
                        <small class="text-muted d-block">Submitted: <?= !empty($existing_ethics['submitted_at']) ? date('M j, Y', strtotime($existing_ethics['submitted_at'])) : 'N/A' ?></small>
                    </div>
                    <?php endif; ?>

                    <?php if (!$existing_ethics || $existing_ethics['status'] === 'rejected'): ?>
                    <a class="btn btn-sm btn-outline-warning w-100" data-bs-toggle="collapse" href="#uploadEthicalForm" role="button">
                        <i class="bi bi-cloud-upload me-1"></i><?= $existing_ethics ? 'Re-upload' : 'Upload Completed' ?> Ethical Form
                    </a>
                    <div class="collapse mt-2" id="uploadEthicalForm">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_ethical_form">
                            <input type="hidden" name="dissertation_id" value="<?= $dissertation['dissertation_id'] ?>">
                            <div class="mb-2">
                                <input type="file" name="ethical_form_file" class="form-control form-control-sm" accept=".pdf,.doc,.docx" required>
                                <small class="text-muted">PDF, DOC, or DOCX (max 10MB)</small>
                            </div>
                            <div class="mb-2">
                                <textarea name="ethics_notes" class="form-control form-control-sm" rows="2" placeholder="Notes (optional)..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-sm btn-warning w-100">
                                <i class="bi bi-cloud-upload me-1"></i>Upload & Submit
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Request Reference Letter -->
            <?php if ($dissertation && in_array($dissertation['current_phase'], ['ethics', 'chapter3', 'chapter4', 'chapter5', 'defense', 'final_draft', 'presentation', 'final_submission'])): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-envelope-paper me-1"></i>Reference Letter</h6>
                </div>
                <div class="card-body">
                    <?php
                    $existing_letter = null;
                    $el_stmt = $conn->prepare("SELECT * FROM dissertation_reference_letters WHERE dissertation_id = ? ORDER BY created_at DESC LIMIT 1");
                    if ($el_stmt) {
                        $el_stmt->bind_param("i", $dissertation['dissertation_id']);
                        $el_stmt->execute();
                        $existing_letter = $el_stmt->get_result()->fetch_assoc();
                    }
                    ?>
                    <?php if ($existing_letter): ?>
                        <?php
                        $lc = 'secondary';
                        if ($existing_letter['status'] === 'pending') $lc = 'warning';
                        elseif ($existing_letter['status'] === 'coordinator_approved') $lc = 'primary';
                        elseif ($existing_letter['status'] === 'registrar_signed') $lc = 'success';
                        elseif ($existing_letter['status'] === 'rejected') $lc = 'danger';
                        ?>
                        <div class="text-center mb-2">
                            <span class="badge bg-<?= $lc ?>"><?= ucfirst(str_replace('_', ' ', $existing_letter['status'])) ?></span>
                        </div>
                        <?php if ($existing_letter['status'] === 'registrar_signed'): ?>
                            <a href="../research_coordinator/print_reference_letter.php?letter_id=<?= $existing_letter['letter_id'] ?>" target="_blank" class="btn btn-sm btn-success w-100">
                                <i class="bi bi-download me-1"></i>Download Letter
                            </a>
                        <?php elseif ($existing_letter['status'] === 'rejected'): ?>
                            <small class="text-danger d-block mb-2"><?= htmlspecialchars($existing_letter['rejection_reason'] ?? '') ?></small>
                            <button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#requestLetterModal">
                                <i class="bi bi-arrow-repeat me-1"></i>Request Again
                            </button>
                        <?php else: ?>
                            <small class="text-muted d-block">Your request is being processed.</small>
                            <?php if ($existing_letter['letter_reference']): ?>
                                <small class="d-block mt-1">Ref: <code><?= htmlspecialchars($existing_letter['letter_reference']) ?></code></small>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="small text-muted mb-2">Request a reference letter for data collection from your organization.</p>
                        <button class="btn btn-sm btn-primary w-100" data-bs-toggle="modal" data-bs-target="#requestLetterModal">
                            <i class="bi bi-envelope-plus me-1"></i>Request Letter
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Deadlines -->
            <?php
            $deadlines = [];
            $dl_r = $conn->query("SELECT * FROM dissertation_deadlines WHERE is_active = 1 AND deadline_date >= NOW() ORDER BY deadline_date ASC LIMIT 5");
            if ($dl_r) while ($dl_row = $dl_r->fetch_assoc()) $deadlines[] = $dl_row;
            ?>
            <?php if (!empty($deadlines)): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-calendar-event me-1"></i>Upcoming Deadlines</h6>
                </div>
                <div class="card-body p-2">
                    <?php foreach ($deadlines as $dl):
                        $diff_days = (int)((strtotime($dl['deadline_date']) - time()) / 86400);
                        $urgency = $diff_days <= 3 ? 'danger' : ($diff_days <= 7 ? 'warning' : 'info');
                    ?>
                    <div class="d-flex align-items-start gap-2 py-2 border-bottom">
                        <span class="badge bg-<?= $urgency ?> mt-1" style="font-size:0.65rem"><?= $diff_days ?>d</span>
                        <div>
                            <small class="fw-bold d-block"><?= htmlspecialchars($dl['title']) ?></small>
                            <small class="text-muted"><?= date('M j, Y', strtotime($dl['deadline_date'])) ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Request Reference Letter Modal -->
<?php if ($dissertation): ?>
<div class="modal fade" id="requestLetterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="request_reference_letter">
                <input type="hidden" name="dissertation_id" value="<?= $dissertation['dissertation_id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-envelope-paper me-2"></i>Request Reference Letter</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Letter Type <span class="text-danger">*</span></label>
                        <select name="letter_type" class="form-select" required>
                            <option value="data_collection">Data Collection</option>
                            <option value="case_study">Case Study</option>
                            <option value="institutional_access">Institutional Access</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Addressed To <span class="text-danger">*</span></label>
                        <input type="text" name="addressed_to" class="form-control" placeholder="e.g. The Director, Human Resources" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Organization <span class="text-danger">*</span></label>
                        <input type="text" name="organization" class="form-control" placeholder="e.g. National Bank of Malawi" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Purpose / Research Description <span class="text-danger">*</span></label>
                        <textarea name="purpose" class="form-control" rows="3" placeholder="Brief description of your research and why you need access..." required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Data Collection Period</label>
                        <input type="text" name="data_collection_period" class="form-control" placeholder="e.g. January 2025 - March 2025">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Document Editor Modal -->
<div class="modal fade" id="docEditorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2 bg-dark text-white">
                <div class="d-flex align-items-center">
                    <i class="bi bi-file-earmark-richtext me-2"></i>
                    <h6 class="modal-title mb-0" id="docEditorTitle">Document</h6>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
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
        .then(function(r) {
            return r.text().then(function(text) {
                try {
                    var data = JSON.parse(text);
                    return {ok: r.ok, data: data};
                } catch(e) {
                    throw new Error('Server returned an invalid response. Please try again.');
                }
            });
        })
        .then(function(res) {
            document.getElementById('docLoading').classList.add('d-none');
            if (!res.ok) {
                throw new Error(res.data.error || 'Conversion failed');
            }
            if (res.data.format === 'pdf') {
                docState.isPdf = true;
                document.getElementById('pdfFrame').src = '../' + res.data.url;
                document.getElementById('pdfFrame').classList.remove('d-none');
                document.getElementById('modeToggleGroup').classList.add('d-none');
            } else {
                docState.html = res.data.html;
                document.getElementById('docReadView').innerHTML = res.data.html;
                document.getElementById('docReadView').classList.remove('d-none');
            }
        })
        .catch(function(err) {
            document.getElementById('docLoading').classList.add('d-none');
            document.getElementById('docErrorMsg').textContent = err.message;
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
        // Wait for TinyMCE to initialize then set content
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

// Initialize TinyMCE on dissertation writing textareas
if (typeof initTinyMCE === 'function') {
    if (document.getElementById('concept_note_editor')) {
        initTinyMCE('#concept_note_editor', { mode: 'dissertation', height: 400 });
    }
    if (document.getElementById('ethics_summary_editor')) {
        initTinyMCE('#ethics_summary_editor', { mode: 'dissertation', height: 300 });
    }
    if (document.getElementById('submission_notes_editor')) {
        initTinyMCE('#submission_notes_editor', { mode: 'compact', height: 200 });
    }
}
</script>
</body>
</html>
