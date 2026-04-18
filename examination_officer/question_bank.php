<?php
/**
 * Question Bank - Examination Officer
 * Manage exam sections, questions and sub-questions with TinyMCE rich text editor
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['examination_manager', 'examination_officer']);

$conn = getDbConnection();
$user = getCurrentUser();
$success_message = '';
$error_message = '';

$exam_id = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);

// Auto-setup: ensure required tables and columns exist
$conn->query("CREATE TABLE IF NOT EXISTS exam_sections (
    section_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    section_label VARCHAR(10) DEFAULT 'A',
    section_title VARCHAR(255) DEFAULT '',
    description TEXT,
    instructions TEXT,
    total_marks INT DEFAULT 0,
    section_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$col = $conn->query("SHOW COLUMNS FROM exam_questions LIKE 'section_id'");
if ($col && $col->num_rows === 0) $conn->query("ALTER TABLE exam_questions ADD COLUMN section_id INT NULL AFTER exam_id");
$col = $conn->query("SHOW COLUMNS FROM exam_questions LIKE 'parent_question_id'");
if ($col && $col->num_rows === 0) $conn->query("ALTER TABLE exam_questions ADD COLUMN parent_question_id INT NULL AFTER section_id");
$col = $conn->query("SHOW COLUMNS FROM exam_questions LIKE 'sub_label'");
if ($col && $col->num_rows === 0) $conn->query("ALTER TABLE exam_questions ADD COLUMN sub_label VARCHAR(10) NULL AFTER parent_question_id");
$col = $conn->query("SHOW COLUMNS FROM exam_questions LIKE 'explanation'");
if ($col && $col->num_rows === 0) $conn->query("ALTER TABLE exam_questions ADD COLUMN explanation TEXT NULL");
$col = $conn->query("SHOW COLUMNS FROM exams LIKE 'exam_code'");
if ($col && $col->num_rows === 0) $conn->query("ALTER TABLE exams ADD COLUMN exam_code VARCHAR(50) DEFAULT '' AFTER exam_id");

// Get exam info
$exam = null;
if ($exam_id) {
    $stmt = $conn->prepare("SELECT e.*, c.course_name, c.course_code FROM exams e LEFT JOIN vle_courses c ON e.course_id = c.course_id WHERE e.exam_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $exam_id);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
    }
}

// ─── Handle Section Actions ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_section'])) {
    $label = trim($_POST['section_label'] ?? 'A');
    $title = trim($_POST['section_title'] ?? '');
    $description = trim($_POST['section_description'] ?? '');
    $instructions = trim($_POST['section_instructions'] ?? '');
    $total_marks = (int)($_POST['section_marks'] ?? 0);
    $order_r = $conn->query("SELECT MAX(section_order) as mx FROM exam_sections WHERE exam_id = $exam_id");
    $next = ($order_r->fetch_assoc()['mx'] ?? 0) + 1;
    $stmt = $conn->prepare("INSERT INTO exam_sections (exam_id, section_label, section_title, description, instructions, total_marks, section_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssii", $exam_id, $label, $title, $description, $instructions, $total_marks, $next);
    $stmt->execute() ? $success_message = "Section added." : $error_message = $conn->error;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_section'])) {
    $sid = (int)$_POST['section_id'];
    $stmt = $conn->prepare("UPDATE exam_sections SET section_label=?, section_title=?, description=?, instructions=?, total_marks=? WHERE section_id=? AND exam_id=?");
    $stmt->bind_param("ssssiis", $_POST['section_label'], $_POST['section_title'], $_POST['section_description'] ?? '', $_POST['section_instructions'] ?? '', (int)($_POST['section_marks'] ?? 0), $sid, $exam_id);
    $stmt->execute() ? $success_message = "Section updated." : $error_message = $conn->error;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_section'])) {
    $sid = (int)$_POST['section_id'];
    $conn->query("UPDATE exam_questions SET section_id = NULL WHERE section_id = $sid");
    $conn->query("DELETE FROM exam_sections WHERE section_id = $sid AND exam_id = $exam_id");
    $success_message = "Section removed.";
}

// ─── Handle Question Actions ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    $question_text = $_POST['question_text'] ?? '';
    $question_type = $_POST['question_type'];
    $marks = (int)$_POST['marks'];
    $explanation = $_POST['explanation'] ?? '';
    $section_id = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
    $parent_id = !empty($_POST['parent_question_id']) ? (int)$_POST['parent_question_id'] : null;
    $sub_label = trim($_POST['sub_label'] ?? '');
    
    $options = null; $correct_answer = '';
    if (in_array($question_type, ['multiple_choice', 'multiple_answer'])) {
        $opts = [];
        for ($i = 0; $i < 6; $i++) { $o = trim($_POST['option_'.$i] ?? ''); if ($o) $opts[] = $o; }
        $options = json_encode($opts);
        $correct_answer = $question_type === 'multiple_choice' ? ($_POST['correct_option'] ?? '') : implode(',', $_POST['correct_options'] ?? []);
    } elseif ($question_type === 'true_false') {
        $options = json_encode(['True','False']); $correct_answer = $_POST['correct_tf'] ?? 'True';
    } else { $correct_answer = trim($_POST['correct_answer'] ?? ''); }
    
    $ord = $conn->query("SELECT MAX(question_order) as mx FROM exam_questions WHERE exam_id = $exam_id");
    $next_order = ($ord->fetch_assoc()['mx'] ?? 0) + 1;
    $qnum = $conn->query("SELECT MAX(question_number) as mx FROM exam_questions WHERE exam_id = $exam_id");
    $next_qnum = ($qnum->fetch_assoc()['mx'] ?? 0) + 1;
    $stmt = $conn->prepare("INSERT INTO exam_questions (exam_id, section_id, parent_question_id, sub_label, question_text, question_type, options, correct_answer, marks, question_order, question_number, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiisssssiiis", $exam_id, $section_id, $parent_id, $sub_label, $question_text, $question_type, $options, $correct_answer, $marks, $next_order, $next_qnum, $explanation);
    $stmt->execute() ? $success_message = "Question added!" : $error_message = "Error: " . $conn->error;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_question'])) {
    $qid = (int)$_POST['question_id'];
    $question_text = $_POST['question_text'] ?? '';
    $question_type = $_POST['question_type'];
    $marks = (int)$_POST['marks'];
    $explanation = $_POST['explanation'] ?? '';
    $section_id = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
    $parent_id = !empty($_POST['parent_question_id']) ? (int)$_POST['parent_question_id'] : null;
    $sub_label = trim($_POST['sub_label'] ?? '');
    $options = null; $correct_answer = '';
    if (in_array($question_type, ['multiple_choice','multiple_answer'])) {
        $opts = []; for ($i=0;$i<6;$i++){$o=trim($_POST['option_'.$i]??'');if($o)$opts[]=$o;} $options=json_encode($opts);
        $correct_answer=$question_type==='multiple_choice'?($_POST['correct_option']??''):implode(',', $_POST['correct_options']??[]);
    } elseif ($question_type==='true_false'){$options=json_encode(['True','False']);$correct_answer=$_POST['correct_tf']??'True';
    } else {$correct_answer=trim($_POST['correct_answer']??'');}
    $stmt=$conn->prepare("UPDATE exam_questions SET section_id=?,parent_question_id=?,sub_label=?,question_text=?,question_type=?,options=?,correct_answer=?,marks=?,explanation=? WHERE question_id=?");
    $stmt->bind_param("iisssssiis",$section_id,$parent_id,$sub_label,$question_text,$question_type,$options,$correct_answer,$marks,$explanation,$qid);
    $stmt->execute()?$success_message="Question updated.":$error_message="Error: ".$conn->error;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    $qid = (int)$_POST['question_id'];
    $conn->query("DELETE FROM exam_answers WHERE question_id = $qid");
    $conn->query("DELETE FROM exam_answers WHERE question_id IN (SELECT question_id FROM exam_questions WHERE parent_question_id = $qid)");
    $conn->query("DELETE FROM exam_questions WHERE parent_question_id = $qid");
    $conn->prepare("DELETE FROM exam_questions WHERE question_id = ?")->bind_param("i", $qid);
    $conn->query("DELETE FROM exam_questions WHERE question_id = $qid");
    $success_message = "Question deleted.";
}

// ─── Load Data ─────────────────────────
$sections = []; $questions = [];
if ($exam_id) {
    $r = $conn->query("SELECT * FROM exam_sections WHERE exam_id = $exam_id ORDER BY section_order");
    if ($r) while ($row = $r->fetch_assoc()) $sections[] = $row;
    $r = $conn->query("SELECT * FROM exam_questions WHERE exam_id = $exam_id ORDER BY section_id ASC, question_order ASC, question_id ASC");
    if ($r) while ($row = $r->fetch_assoc()) $questions[] = $row;
}
$all_exams_r = $conn->query("SELECT exam_id, exam_code, exam_name FROM exams ORDER BY created_at DESC");
$all_exams = $all_exams_r ? $all_exams_r->fetch_all(MYSQLI_ASSOC) : [];
$marks_sum = array_sum(array_column($questions, 'marks'));
$top_questions = array_filter($questions, fn($q) => empty($q['parent_question_id']));

// Group by section
$unsectioned = array_filter($questions, fn($q) => empty($q['section_id']) && empty($q['parent_question_id']));
$by_section = [];
foreach ($sections as $sec) $by_section[$sec['section_id']] = array_filter($questions, fn($q) => $q['section_id'] == $sec['section_id'] && empty($q['parent_question_id']));
$sub_map = [];
foreach ($questions as $q) { if (!empty($q['parent_question_id'])) $sub_map[$q['parent_question_id']][] = $q; }

$type_labels = ['multiple_choice'=>'Multiple Choice','multiple_answer'=>'Multiple Answer','true_false'=>'True/False','short_answer'=>'Short Answer','essay'=>'Essay'];
$type_colors = ['multiple_choice'=>'primary','multiple_answer'=>'info','true_false'=>'success','short_answer'=>'warning','essay'=>'secondary'];
$type_icons = ['multiple_choice'=>'bi-ui-radios','multiple_answer'=>'bi-ui-checks','true_false'=>'bi-toggle-on','short_answer'=>'bi-input-cursor-text','essay'=>'bi-text-paragraph'];

$page_title = "Question Bank";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Bank - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <?php include '../includes/tinymce_head.php'; ?>
    <?php include '../includes/tinymce_init.php'; ?>
    <style>
        .section-banner{background:linear-gradient(135deg,#1e3a5f,#2d5a87);color:#fff;border-radius:10px;padding:15px 20px;margin-bottom:15px}
        .sub-question{border-left:3px solid #6366f1;margin-left:20px;padding-left:15px}
        .add-sub-btn{font-size:.75rem;padding:2px 8px}
    </style>
</head>
<body>
    <?php include 'header_nav.php'; ?>
    <div class="vle-content">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="vle-page-title"><i class="bi bi-collection me-2"></i>Question Bank</h2>
                <?php if ($exam): ?>
                <p class="text-muted mb-0"><?= htmlspecialchars($exam['exam_name']) ?> &mdash; <?= count($top_questions) ?> questions<?= count($sections) ? ', '.count($sections).' sections' : '' ?>, <?= $marks_sum ?>/<?= $exam['total_marks'] ?> marks</p>
                <?php if ($marks_sum != $exam['total_marks']): ?><span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Marks mismatch: <?= $marks_sum ?>/<?= $exam['total_marks'] ?></span><?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($exam_id): ?>
                <button class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#addSectionModal"><i class="bi bi-bookmark-plus me-1"></i>Add Section</button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuestionModal"><i class="bi bi-plus-circle me-1"></i>Add Question</button>
                <a href="exam_view.php?id=<?= $exam_id ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($success_message): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= $success_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($error_message): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= $error_message ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <?php if (!$exam_id): ?>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-8"><label class="form-label">Select Examination</label>
                    <select name="exam_id" class="form-select" required><option value="">-- Choose --</option>
                    <?php foreach ($all_exams as $e): ?><option value="<?= $e['exam_id'] ?>"><?= htmlspecialchars($e['exam_code'].' - '.$e['exam_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-arrow-right me-1"></i>Load</button></div>
            </form>
        </div></div>
        <?php endif; ?>

        <?php if ($exam_id): ?>
        <?php $global_num = 0; ?>

        <?php if (empty($sections) && empty($questions)): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-muted">
            <i class="bi bi-question-circle display-4 d-block mb-3"></i>
            <p>No sections or questions yet. Start by adding a <strong>Section</strong> then add questions.</p>
            <div class="d-flex gap-2 justify-content-center">
                <button class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#addSectionModal"><i class="bi bi-bookmark-plus me-1"></i>Add Section</button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuestionModal"><i class="bi bi-plus-circle me-1"></i>Add Question</button>
            </div>
        </div></div>
        <?php else: ?>

        <!-- Sections -->
        <?php foreach ($sections as $sec): ?>
        <div class="section-banner d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0 fw-bold">Section <?= htmlspecialchars($sec['section_label']) ?>: <?= htmlspecialchars($sec['section_title']) ?></h5>
                <?php if ($sec['instructions']): ?><small class="opacity-75"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($sec['instructions']) ?></small><?php endif; ?>
                <?php if ($sec['total_marks']): ?><span class="badge bg-light text-dark ms-2"><?= $sec['total_marks'] ?> marks</span><?php endif; ?>
            </div>
            <div class="d-flex gap-1">
                <button class="btn btn-sm btn-outline-light" onclick="editSection(<?= htmlspecialchars(json_encode($sec)) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Remove section?')"><input type="hidden" name="exam_id" value="<?= $exam_id ?>"><input type="hidden" name="section_id" value="<?= $sec['section_id'] ?>"><button type="submit" name="delete_section" class="btn btn-sm btn-outline-light"><i class="bi bi-trash"></i></button></form>
            </div>
        </div>
        <?php if (empty($by_section[$sec['section_id']])): ?><p class="text-muted text-center py-2"><small>No questions in this section.</small></p><?php endif; ?>
        <?php foreach (($by_section[$sec['section_id']] ?? []) as $q): $global_num++; ?>
        <!-- Question Card -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-dark me-1"><i class="<?= $type_icons[$q['question_type']] ?? 'bi-question' ?> me-1"></i>Q<?= $global_num ?></span>
                    <span class="badge bg-<?= $type_colors[$q['question_type']] ?? 'secondary' ?>"><?= $type_labels[$q['question_type']] ?? $q['question_type'] ?></span>
                    <span class="badge bg-light text-dark"><?= $q['marks'] ?> mk</span>
                    <?php if (!empty($sub_map[$q['question_id']])): ?><span class="badge bg-info text-white"><i class="bi bi-diagram-3 me-1"></i><?= count($sub_map[$q['question_id']]) ?> sub</span><?php endif; ?>
                </div>
                <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-outline-info add-sub-btn" onclick="addSubQuestion(<?= $q['question_id'] ?>, <?= $q['section_id'] ?: 'null' ?>)"><i class="bi bi-plus-circle"></i> Sub-Q</button>
                    <button class="btn btn-sm btn-outline-warning" onclick='editQuestion(<?= htmlspecialchars(json_encode($q), ENT_QUOTES) ?>)'><i class="bi bi-pencil"></i></button>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="exam_id" value="<?= $exam_id ?>"><input type="hidden" name="question_id" value="<?= $q['question_id'] ?>"><button type="submit" name="delete_question" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                </div>
            </div>
            <div class="card-body">
                <div class="mb-2" style="font-size:1.05rem;line-height:1.6"><?= $q['question_text'] ?: '<em class="text-muted">No text</em>' ?></div>
                <?php $opts = $q['options'] ? json_decode($q['options'], true) : []; ?>
                <?php if (!empty($opts)): ?><div class="row g-2">
                    <?php foreach ($opts as $oi => $opt):
                        $ic = ($q['question_type']==='multiple_choice' && $q['correct_answer']==$oi) || ($q['question_type']==='multiple_answer' && in_array($oi,explode(',',$q['correct_answer']))) || ($q['question_type']==='true_false' && $q['correct_answer']===$opt);
                    ?><div class="col-md-6"><div class="border rounded p-2 <?= $ic?'border-success bg-success bg-opacity-10':'' ?>"><?= $ic?'<i class="bi bi-check-circle-fill text-success me-1"></i>':'' ?><?= htmlspecialchars($opt) ?></div></div>
                    <?php endforeach; ?></div>
                <?php elseif ($q['question_type']==='short_answer' && $q['correct_answer']): ?>
                    <small class="text-muted"><strong>Expected:</strong> <?= htmlspecialchars($q['correct_answer']) ?></small>
                <?php endif; ?>
                <?php if ($q['explanation']): ?><div class="mt-2 p-2 bg-light rounded" style="font-size:.85rem"><i class="bi bi-lightbulb text-info me-1"></i><?= $q['explanation'] ?></div><?php endif; ?>
                
                <!-- Sub-questions -->
                <?php if (!empty($sub_map[$q['question_id']])): ?>
                <div class="mt-3"><h6 class="text-muted mb-2"><i class="bi bi-diagram-3 me-1"></i>Sub-questions:</h6>
                <?php $si=0; foreach ($sub_map[$q['question_id']] as $sq): $si++; $sl=$sq['sub_label']?:chr(96+$si); ?>
                <div class="sub-question mb-2 p-2 bg-light rounded">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><span class="badge bg-secondary me-1">(<?= htmlspecialchars($sl) ?>)</span><span class="badge bg-<?= $type_colors[$sq['question_type']]??'secondary' ?>" style="font-size:.65rem"><?= $type_labels[$sq['question_type']]??'' ?></span> <span class="badge bg-light text-dark" style="font-size:.65rem"><?= $sq['marks'] ?>mk</span></div>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-warning py-0 px-1" onclick='editQuestion(<?= htmlspecialchars(json_encode($sq),ENT_QUOTES) ?>)'><i class="bi bi-pencil" style="font-size:.7rem"></i></button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="exam_id" value="<?= $exam_id ?>"><input type="hidden" name="question_id" value="<?= $sq['question_id'] ?>"><button type="submit" name="delete_question" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash" style="font-size:.7rem"></i></button></form>
                        </div>
                    </div>
                    <div style="font-size:.9rem"><?= $sq['question_text'] ?: '<em class="text-muted">No text</em>' ?></div>
                    <?php $sqo=$sq['options']?json_decode($sq['options'],true):[]; foreach ($sqo as $soi=>$sopt): $sc=($sq['question_type']==='multiple_choice'&&$sq['correct_answer']==$soi)||($sq['question_type']==='true_false'&&$sq['correct_answer']===$sopt); ?>
                    <span class="badge <?= $sc?'bg-success':'bg-light text-dark border' ?> me-1 mt-1"><?= htmlspecialchars($sopt) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>

        <!-- Unsectioned -->
        <?php if (!empty($unsectioned)): ?>
        <?php if (!empty($sections)): ?><div class="section-banner" style="background:linear-gradient(135deg,#475569,#64748b)"><h5 class="mb-0 fw-bold">Unsectioned Questions</h5></div><?php endif; ?>
        <?php foreach ($unsectioned as $q): $global_num++; ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-dark me-1"><i class="<?= $type_icons[$q['question_type']]??'bi-question' ?> me-1"></i>Q<?= $global_num ?></span>
                    <span class="badge bg-<?= $type_colors[$q['question_type']]??'secondary' ?>"><?= $type_labels[$q['question_type']]??$q['question_type'] ?></span>
                    <span class="badge bg-light text-dark"><?= $q['marks'] ?> mk</span>
                </div>
                <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-outline-info add-sub-btn" onclick="addSubQuestion(<?= $q['question_id'] ?>, null)"><i class="bi bi-plus-circle"></i> Sub-Q</button>
                    <button class="btn btn-sm btn-outline-warning" onclick='editQuestion(<?= htmlspecialchars(json_encode($q),ENT_QUOTES) ?>)'><i class="bi bi-pencil"></i></button>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="exam_id" value="<?= $exam_id ?>"><input type="hidden" name="question_id" value="<?= $q['question_id'] ?>"><button type="submit" name="delete_question" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                </div>
            </div>
            <div class="card-body">
                <div class="mb-2" style="font-size:1.05rem;line-height:1.6"><?= $q['question_text']?:'<em class="text-muted">No text</em>' ?></div>
                <?php $opts=$q['options']?json_decode($q['options'],true):[]; if (!empty($opts)): ?><div class="row g-2">
                <?php foreach ($opts as $oi=>$opt): $ic=($q['question_type']==='multiple_choice'&&$q['correct_answer']==$oi)||($q['question_type']==='true_false'&&$q['correct_answer']===$opt); ?>
                <div class="col-md-6"><div class="border rounded p-2 <?= $ic?'border-success bg-success bg-opacity-10':'' ?>"><?= $ic?'<i class="bi bi-check-circle-fill text-success me-1"></i>':'' ?><?= htmlspecialchars($opt) ?></div></div>
                <?php endforeach; ?></div><?php endif; ?>
                <?php if ($q['explanation']): ?><div class="mt-2 p-2 bg-light rounded" style="font-size:.85rem"><i class="bi bi-lightbulb text-info me-1"></i><?= $q['explanation'] ?></div><?php endif; ?>
                <?php if (!empty($sub_map[$q['question_id']])): ?>
                <div class="mt-3"><h6 class="text-muted mb-2"><i class="bi bi-diagram-3 me-1"></i>Sub-questions:</h6>
                <?php $si=0; foreach ($sub_map[$q['question_id']] as $sq): $si++; $sl=$sq['sub_label']?:chr(96+$si); ?>
                <div class="sub-question mb-2 p-2 bg-light rounded">
                    <div class="d-flex justify-content-between"><div><span class="badge bg-secondary">(<?= htmlspecialchars($sl) ?>)</span> <span class="badge bg-<?= $type_colors[$sq['question_type']]??'secondary' ?>" style="font-size:.65rem"><?= $type_labels[$sq['question_type']]??'' ?></span> <span class="badge bg-light text-dark" style="font-size:.65rem"><?= $sq['marks'] ?>mk</span></div>
                    <div class="d-flex gap-1"><button class="btn btn-sm btn-outline-warning py-0 px-1" onclick='editQuestion(<?= htmlspecialchars(json_encode($sq),ENT_QUOTES) ?>)'><i class="bi bi-pencil" style="font-size:.7rem"></i></button>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="exam_id" value="<?= $exam_id ?>"><input type="hidden" name="question_id" value="<?= $sq['question_id'] ?>"><button type="submit" name="delete_question" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash" style="font-size:.7rem"></i></button></form></div></div>
                    <div style="font-size:.9rem"><?= $sq['question_text']?:'<em class="text-muted">No text</em>' ?></div>
                </div>
                <?php endforeach; ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ═══ ADD SECTION MODAL ═══ -->
    <div class="modal fade" id="addSectionModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content">
        <div class="modal-header bg-dark text-white"><h5 class="modal-title"><i class="bi bi-bookmark-plus me-2"></i>Add Section</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><input type="hidden" name="exam_id" value="<?= $exam_id ?>">
            <div class="row g-3">
                <div class="col-3"><label class="form-label">Label</label><input type="text" name="section_label" class="form-control text-center fw-bold" value="<?= chr(65+count($sections)) ?>" maxlength="5" required><div class="form-text text-center">A, B, C</div></div>
                <div class="col-9"><label class="form-label">Title</label><input type="text" name="section_title" class="form-control" required placeholder="e.g. Short Answer Questions"></div>
                <div class="col-12"><label class="form-label">Instructions</label><textarea name="section_instructions" class="form-control" rows="2" placeholder="e.g. Answer ALL questions..."></textarea></div>
                <div class="col-12"><label class="form-label">Description</label><textarea name="section_description" class="form-control" rows="2"></textarea></div>
                <div class="col-6"><label class="form-label">Total Marks</label><input type="number" name="section_marks" class="form-control" min="0" value="0"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="add_section" class="btn btn-dark"><i class="bi bi-bookmark-plus me-1"></i>Add Section</button></div>
    </form></div></div>

    <!-- ═══ EDIT SECTION MODAL ═══ -->
    <div class="modal fade" id="editSectionModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content">
        <div class="modal-header bg-warning"><h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Section</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><input type="hidden" name="exam_id" value="<?= $exam_id ?>"><input type="hidden" name="section_id" id="es_id">
            <div class="row g-3">
                <div class="col-3"><label class="form-label">Label</label><input type="text" name="section_label" id="es_label" class="form-control text-center fw-bold" maxlength="5" required></div>
                <div class="col-9"><label class="form-label">Title</label><input type="text" name="section_title" id="es_title" class="form-control" required></div>
                <div class="col-12"><label class="form-label">Instructions</label><textarea name="section_instructions" id="es_instr" class="form-control" rows="2"></textarea></div>
                <div class="col-12"><label class="form-label">Description</label><textarea name="section_description" id="es_desc" class="form-control" rows="2"></textarea></div>
                <div class="col-6"><label class="form-label">Marks</label><input type="number" name="section_marks" id="es_marks" class="form-control" min="0"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="update_section" class="btn btn-warning"><i class="bi bi-save me-1"></i>Update</button></div>
    </form></div></div>

    <!-- ═══ ADD QUESTION MODAL ═══ -->
    <div class="modal fade" id="addQuestionModal" tabindex="-1"><div class="modal-dialog modal-xl"><form method="POST" class="modal-content" id="addQForm">
        <div class="modal-header bg-dark text-white"><h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Question</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><input type="hidden" name="exam_id" value="<?= $exam_id ?>">
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Section</label><select name="section_id" class="form-select" id="addSecSel"><option value="">-- No Section --</option>
                    <?php foreach($sections as $s): ?><option value="<?= $s['section_id'] ?>">Sec <?= htmlspecialchars($s['section_label']) ?>: <?= htmlspecialchars($s['section_title']) ?></option><?php endforeach; ?>
                </select></div>
                <div class="col-md-3"><label class="form-label">Type</label><select name="question_type" class="form-select" id="addQType" onchange="toggleOptions('add')">
                    <option value="multiple_choice">Multiple Choice</option><option value="multiple_answer">Multiple Answer</option><option value="true_false">True/False</option><option value="short_answer">Short Answer</option><option value="essay">Essay</option>
                </select></div>
                <div class="col-md-2"><label class="form-label">Marks</label><input type="number" name="marks" class="form-control" min="1" value="1" required></div>
                <div class="col-md-2"><label class="form-label">Parent Q#</label><select name="parent_question_id" class="form-select"><option value="">-- None --</option>
                    <?php foreach($top_questions as $tq): ?><option value="<?= $tq['question_id'] ?>">Q<?= $tq['question_order'] ?></option><?php endforeach; ?>
                </select><div class="form-text">Sub-question of</div></div>
                <div class="col-md-2"><label class="form-label">Sub-label</label><input type="text" name="sub_label" class="form-control" placeholder="a, b" maxlength="10"></div>
                
                <div class="col-12"><label class="form-label fw-bold">Question Text <small class="text-muted">(Rich Text - use formatting, images, tables)</small></label>
                    <textarea name="question_text" id="add_q_text" class="form-control tinymce" rows="4"></textarea></div>
                
                <div id="addOptionsSection" class="col-12"><label class="form-label">Answer Options</label>
                    <?php for($i=0;$i<6;$i++): ?><div class="input-group mb-2"><span class="input-group-text"><?= chr(65+$i) ?></span><input type="text" name="option_<?= $i ?>" class="form-control" placeholder="Option <?= chr(65+$i) ?>"><div class="input-group-text"><input type="radio" name="correct_option" value="<?= $i ?>" class="add-mc-radio" <?= $i===0?'checked':'' ?>><input type="checkbox" name="correct_options[]" value="<?= $i ?>" class="add-ma-check d-none"></div></div><?php endfor; ?>
                </div>
                <div id="addTFSection" class="col-12 d-none"><label class="form-label">Correct</label><select name="correct_tf" class="form-select"><option value="True">True</option><option value="False">False</option></select></div>
                <div id="addShortSection" class="col-12 d-none"><label class="form-label">Expected Answer</label><input type="text" name="correct_answer" class="form-control" placeholder="Optional"></div>
                <div class="col-12"><label class="form-label">Explanation / Marking Guide</label><textarea name="explanation" id="add_expl" class="form-control tinymce" rows="2"></textarea></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="add_question" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Question</button></div>
    </form></div></div>

    <!-- ═══ EDIT QUESTION MODAL ═══ -->
    <div class="modal fade" id="editQuestionModal" tabindex="-1"><div class="modal-dialog modal-xl"><form method="POST" class="modal-content" id="editQForm">
        <div class="modal-header bg-warning"><h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Question</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><input type="hidden" name="exam_id" value="<?= $exam_id ?>"><input type="hidden" name="question_id" id="edit_qid">
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Section</label><select name="section_id" class="form-select" id="editSecSel"><option value="">-- None --</option>
                    <?php foreach($sections as $s): ?><option value="<?= $s['section_id'] ?>">Sec <?= htmlspecialchars($s['section_label']) ?>: <?= htmlspecialchars($s['section_title']) ?></option><?php endforeach; ?>
                </select></div>
                <div class="col-md-3"><label class="form-label">Type</label><select name="question_type" class="form-select" id="editQType" onchange="toggleOptions('edit')">
                    <option value="multiple_choice">Multiple Choice</option><option value="multiple_answer">Multiple Answer</option><option value="true_false">True/False</option><option value="short_answer">Short Answer</option><option value="essay">Essay</option>
                </select></div>
                <div class="col-md-2"><label class="form-label">Marks</label><input type="number" name="marks" class="form-control" id="edit_marks" min="1" required></div>
                <div class="col-md-2"><label class="form-label">Parent Q#</label><select name="parent_question_id" class="form-select" id="edit_parent"><option value="">-- None --</option>
                    <?php foreach($top_questions as $tq): ?><option value="<?= $tq['question_id'] ?>">Q<?= $tq['question_order'] ?></option><?php endforeach; ?>
                </select></div>
                <div class="col-md-2"><label class="form-label">Sub-label</label><input type="text" name="sub_label" id="edit_sub_label" class="form-control" maxlength="10"></div>
                
                <div class="col-12"><label class="form-label fw-bold">Question Text</label>
                    <textarea name="question_text" id="edit_q_text" class="form-control tinymce" rows="4"></textarea></div>
                
                <div id="editOptionsSection" class="col-12"><label class="form-label">Options</label>
                    <?php for($i=0;$i<6;$i++): ?><div class="input-group mb-2"><span class="input-group-text"><?= chr(65+$i) ?></span><input type="text" name="option_<?= $i ?>" class="form-control" id="edit_opt_<?= $i ?>"><div class="input-group-text"><input type="radio" name="correct_option" value="<?= $i ?>" class="edit-mc-radio"><input type="checkbox" name="correct_options[]" value="<?= $i ?>" class="edit-ma-check d-none"></div></div><?php endfor; ?>
                </div>
                <div id="editTFSection" class="col-12 d-none"><label class="form-label">Correct</label><select name="correct_tf" class="form-select" id="edit_tf"><option value="True">True</option><option value="False">False</option></select></div>
                <div id="editShortSection" class="col-12 d-none"><label class="form-label">Expected Answer</label><input type="text" name="correct_answer" class="form-control" id="edit_answer"></div>
                <div class="col-12"><label class="form-label">Explanation</label><textarea name="explanation" id="edit_expl" class="form-control tinymce" rows="2"></textarea></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="update_question" class="btn btn-warning"><i class="bi bi-save me-1"></i>Update</button></div>
    </form></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let addInit = false, editInit = false;
    
    document.getElementById('addQuestionModal')?.addEventListener('shown.bs.modal', function() {
        if (!addInit) {
            initTinyMCE('#add_q_text', { mode: 'exam_question', height: 300 });
            initTinyMCE('#add_expl', { mode: 'compact', height: 150 });
            addInit = true;
        }
    });
    document.getElementById('editQuestionModal')?.addEventListener('shown.bs.modal', function() {
        if (!editInit) {
            initTinyMCE('#edit_q_text', { mode: 'exam_question', height: 300 });
            initTinyMCE('#edit_expl', { mode: 'compact', height: 150 });
            editInit = true;
        }
    });

    // Sync TinyMCE before submit
    document.getElementById('addQForm')?.addEventListener('submit', function() {
        ['add_q_text','add_expl'].forEach(id => { const e = tinymce.get(id); if (e) document.getElementById(id).value = e.getContent(); });
    });
    document.getElementById('editQForm')?.addEventListener('submit', function() {
        ['edit_q_text','edit_expl'].forEach(id => { const e = tinymce.get(id); if (e) document.getElementById(id).value = e.getContent(); });
    });

    function toggleOptions(prefix) {
        const type = document.getElementById(prefix+'QType').value;
        ['OptionsSection','TFSection','ShortSection'].forEach(s => document.getElementById(prefix+s)?.classList.add('d-none'));
        if (type==='multiple_choice'||type==='multiple_answer') {
            document.getElementById(prefix+'OptionsSection').classList.remove('d-none');
            document.querySelectorAll('.'+prefix+'-mc-radio').forEach(r => r.classList.toggle('d-none', type!=='multiple_choice'));
            document.querySelectorAll('.'+prefix+'-ma-check').forEach(c => c.classList.toggle('d-none', type!=='multiple_answer'));
        } else if (type==='true_false') document.getElementById(prefix+'TFSection').classList.remove('d-none');
        else if (type==='short_answer'||type==='essay') document.getElementById(prefix+'ShortSection').classList.remove('d-none');
    }
    
    function editSection(s) {
        document.getElementById('es_id').value = s.section_id;
        document.getElementById('es_label').value = s.section_label;
        document.getElementById('es_title').value = s.section_title;
        document.getElementById('es_instr').value = s.instructions||'';
        document.getElementById('es_desc').value = s.description||'';
        document.getElementById('es_marks').value = s.total_marks||0;
        new bootstrap.Modal(document.getElementById('editSectionModal')).show();
    }
    
    function editQuestion(q) {
        document.getElementById('edit_qid').value = q.question_id;
        document.getElementById('edit_marks').value = q.marks;
        document.getElementById('editQType').value = q.question_type;
        document.getElementById('edit_sub_label').value = q.sub_label||'';
        document.getElementById('editSecSel').value = q.section_id||'';
        document.getElementById('edit_parent').value = q.parent_question_id||'';
        
        setTimeout(function() {
            const qe = tinymce.get('edit_q_text');
            if (qe) qe.setContent(q.question_text||''); else document.getElementById('edit_q_text').value = q.question_text||'';
            const ee = tinymce.get('edit_expl');
            if (ee) ee.setContent(q.explanation||''); else document.getElementById('edit_expl').value = q.explanation||'';
        }, 300);
        
        const opts = q.options ? JSON.parse(q.options) : [];
        for (let i=0;i<6;i++){const el=document.getElementById('edit_opt_'+i);if(el)el.value=opts[i]||'';}
        if (q.question_type==='multiple_choice') document.querySelectorAll('.edit-mc-radio').forEach((r,i)=>r.checked=(i==q.correct_answer));
        else if (q.question_type==='multiple_answer'){const c=(q.correct_answer||'').split(',');document.querySelectorAll('.edit-ma-check').forEach((cb,i)=>cb.checked=c.includes(String(i)));}
        else if (q.question_type==='true_false') document.getElementById('edit_tf').value=q.correct_answer;
        else document.getElementById('edit_answer').value=q.correct_answer||'';
        toggleOptions('edit');
        new bootstrap.Modal(document.getElementById('editQuestionModal')).show();
    }
    
    function addSubQuestion(parentId, sectionId) {
        const modal = document.getElementById('addQuestionModal');
        modal.querySelector('[name="parent_question_id"]').value = parentId;
        if (sectionId) modal.querySelector('[name="section_id"]').value = sectionId;
        new bootstrap.Modal(modal).show();
    }
    
    toggleOptions('add');
    </script>
</body>
</html>
