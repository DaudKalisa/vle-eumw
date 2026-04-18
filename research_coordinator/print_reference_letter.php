<?php
/**
 * Print Reference Letter
 * Generates a formal, printable reference/introduction letter
 * for student data collection, case study access, etc.
 */
session_start();
require_once '../includes/auth.php';
requireLogin();

$conn = getDbConnection();
$letter_id = (int)($_GET['letter_id'] ?? 0);

if (!$letter_id) {
    die('Invalid letter ID.');
}

// Fetch letter with all related data
$stmt = $conn->prepare("
    SELECT rl.*, 
           d.title AS dissertation_title, d.topic_area, d.program, d.program_type,
           d.academic_year,
           s.full_name AS student_name, s.email AS student_email,
           l.full_name AS supervisor_name
    FROM dissertation_reference_letters rl
    LEFT JOIN dissertations d ON rl.dissertation_id = d.dissertation_id
    LEFT JOIN students s ON rl.student_id = s.student_id
    LEFT JOIN lecturers l ON d.supervisor_id = l.lecturer_id
    WHERE rl.letter_id = ?
");
$stmt->bind_param("i", $letter_id);
$stmt->execute();
$letter = $stmt->get_result()->fetch_assoc();

if (!$letter) {
    die('Letter not found.');
}

// Only allow viewing if coordinator_approved or registrar_signed
if (!in_array($letter['status'], ['coordinator_approved', 'registrar_signed'])) {
    die('This letter has not been approved yet.');
}

// Get registrar signature
$signature = null;
if ($letter['status'] === 'registrar_signed' && $letter['registrar_signature_path']) {
    $sig_q = $conn->query("SELECT * FROM registrar_signatures WHERE signature_image_path = '" . $conn->real_escape_string($letter['registrar_signature_path']) . "' LIMIT 1");
    $signature = $sig_q ? $sig_q->fetch_assoc() : null;
}
// Fallback to active signature if not found
if (!$signature && $letter['status'] === 'registrar_signed') {
    $sig_q = $conn->query("SELECT * FROM registrar_signatures WHERE is_active = 1 LIMIT 1");
    $signature = $sig_q ? $sig_q->fetch_assoc() : null;
}

// Get coordinator name
$coordinator_name = '';
if ($letter['coordinator_approved_by']) {
    $cq = $conn->prepare("
        SELECT u.username, COALESCE(rc.full_name, as2.full_name, u.username) AS name 
        FROM users u 
        LEFT JOIN research_coordinators rc ON u.related_staff_id = rc.coordinator_id AND u.role = 'research_coordinator'
        LEFT JOIN administrative_staff as2 ON u.related_staff_id = as2.staff_id AND u.role = 'admin'
        WHERE u.user_id = ?
    ");
    $cq->bind_param("i", $letter['coordinator_approved_by']);
    $cq->execute();
    $cr = $cq->get_result()->fetch_assoc();
    $coordinator_name = $cr['name'] ?? '';
}

$date_str = $letter['coordinator_approved_at'] ? date('F j, Y', strtotime($letter['coordinator_approved_at'])) : date('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reference Letter - <?= htmlspecialchars($letter['letter_reference'] ?? '') ?></title>
    <style>
        @page { size: A4; margin: 20mm 25mm; }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.6; color: #000; background: #e8e8e8; }
        .page { width: 210mm; min-height: 297mm; margin: 10mm auto; background: #fff; padding: 20mm 25mm; box-shadow: 0 2px 10px rgba(0,0,0,.2); position: relative; }
        
        /* Letterhead */
        .letterhead { text-align: center; border-bottom: 2px solid #1a237e; padding-bottom: 15px; margin-bottom: 25px; }
        .letterhead img { height: 70px; margin-bottom: 8px; }
        .letterhead h1 { font-size: 18pt; color: #1a237e; letter-spacing: 2px; margin-bottom: 2px; }
        .letterhead h2 { font-size: 12pt; font-weight: normal; color: #333; margin-bottom: 2px; }
        .letterhead .contact { font-size: 9pt; color: #666; }
        
        /* Reference line */
        .ref-line { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 11pt; }
        .ref-line .ref { font-weight: bold; }
        
        /* Salutation & body */
        .addressee { margin-bottom: 20px; }
        .addressee p { margin-bottom: 2px; }
        .subject { text-align: center; font-weight: bold; text-decoration: underline; margin: 20px 0; font-size: 12pt; }
        .body p { text-align: justify; margin-bottom: 12px; }
        
        /* Signature block */
        .signature-block { margin-top: 40px; }
        .signature-block .sig-img { max-height: 60px; margin: 10px 0; }
        .signature-block .name { font-weight: bold; }
        .signature-block .title { font-style: italic; }
        
        .dual-signature { display: flex; justify-content: space-between; margin-top: 40px; }
        .dual-signature .sig-col { width: 45%; }
        .sig-line-print { border-bottom: 1px solid #000; height: 40px; margin-bottom: 4px; }
        
        /* Footer */
        .footer { position: absolute; bottom: 15mm; left: 25mm; right: 25mm; text-align: center; font-size: 8pt; color: #999; border-top: 1px solid #ddd; padding-top: 8px; }

        /* Print buttons */
        .print-bar { position: fixed; bottom: 20px; right: 20px; display: flex; gap: 10px; z-index: 100; }
        .print-bar button, .print-bar a { padding: 12px 28px; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; text-decoration: none; box-shadow: 0 4px 12px rgba(0,0,0,.2); color: #fff; }
        .btn-prn { background: #1a237e; }
        .btn-prn:hover { background: #283593; }
        .btn-bk { background: #666; }
        .btn-bk:hover { background: #555; color: #fff; }
    </style>
</head>
<body>

<div class="print-bar no-print">
    <a href="reference_letters.php" class="btn-bk">← Back</a>
    <button class="btn-prn" onclick="window.print()">🖨️ Print Letter</button>
</div>

<div class="page">
    <!-- Letterhead -->
    <div class="letterhead">
        <img src="../assets/img/Logo.png" alt="University Logo" onerror="this.style.display='none'">
        <h1>EXPLOITS UNIVERSITY OF MALAWI</h1>
        <h2>Office of the Registrar</h2>
        <div class="contact">
            P.O. Box 000, Blantyre, Malawi &nbsp;|&nbsp; Tel: +265 xxx xxx xxx &nbsp;|&nbsp; Email: registrar@eumw.ac.mw
        </div>
    </div>

    <!-- Reference & Date -->
    <div class="ref-line">
        <div class="ref">Ref: <?= htmlspecialchars($letter['letter_reference'] ?? '_______________') ?></div>
        <div><?= $date_str ?></div>
    </div>

    <!-- Addressee -->
    <div class="addressee">
        <?php if ($letter['addressed_to']): ?>
            <p><?= htmlspecialchars($letter['addressed_to']) ?></p>
        <?php else: ?>
            <p>To Whom It May Concern</p>
        <?php endif; ?>
        <?php if ($letter['organization']): ?>
            <p><?= htmlspecialchars($letter['organization']) ?></p>
        <?php endif; ?>
    </div>

    <!-- Subject -->
    <div class="subject">
        RE: LETTER OF INTRODUCTION FOR DATA COLLECTION
    </div>

    <!-- Body -->
    <div class="body">
        <p>Dear Sir/Madam,</p>

        <p>
            This letter serves to introduce <strong><?= htmlspecialchars($letter['student_name'] ?? '') ?></strong>
            (Student ID: <strong><?= htmlspecialchars($letter['student_id'] ?? '') ?></strong>), 
            a student at <strong>Exploits University of Malawi</strong> pursuing a 
            <strong><?= htmlspecialchars(ucfirst($letter['program_type'] ?? 'degree')) ?></strong> programme in
            <strong><?= htmlspecialchars($letter['program'] ?? '') ?></strong>.
        </p>

        <p>
            The above-named student is currently undertaking a research study titled:
            <em>"<?= htmlspecialchars($letter['dissertation_title'] ?? '') ?>"</em>
            <?php if ($letter['topic_area']): ?>
                in the area of <strong><?= htmlspecialchars($letter['topic_area']) ?></strong>
            <?php endif; ?>
            as part of the requirements for the award of their <?= htmlspecialchars(ucfirst($letter['program_type'] ?? 'degree')) ?>.
        </p>

        <?php if ($letter['purpose']): ?>
        <p><?= nl2br(htmlspecialchars($letter['purpose'])) ?></p>
        <?php else: ?>
        <p>
            The student requires access to your organisation for the purpose of data collection in line with the 
            objectives of their research study. The data collected will be used strictly for academic purposes 
            and will be treated with the utmost confidentiality in accordance with research ethics guidelines.
        </p>
        <?php endif; ?>

        <?php if ($letter['data_collection_period']): ?>
        <p>
            The data collection is expected to take place during the period: 
            <strong><?= htmlspecialchars($letter['data_collection_period']) ?></strong>.
        </p>
        <?php endif; ?>

        <p>
            The study has received ethical clearance from the University's Research Ethics Committee. 
            The student is under the supervision of 
            <strong><?= htmlspecialchars($letter['supervisor_name'] ?? 'their assigned supervisor') ?></strong>.
        </p>

        <p>
            We kindly request that you provide the necessary assistance and cooperation to enable the student 
            to successfully complete this important academic exercise. Any information provided will be used 
            solely for the purposes of this research and will remain confidential.
        </p>

        <p>
            Should you require any further information or verification, please do not hesitate to contact 
            the undersigned.
        </p>

        <p>Yours faithfully,</p>
    </div>

    <!-- Signatures -->
    <div class="dual-signature">
        <!-- Registrar Signature -->
        <div class="sig-col">
            <?php if ($letter['status'] === 'registrar_signed' && $signature): ?>
                <?php if ($signature['signature_image_path']): ?>
                    <img src="../<?= htmlspecialchars($signature['signature_image_path']) ?>" alt="Signature" class="sig-img">
                <?php else: ?>
                    <div class="sig-line-print"></div>
                <?php endif; ?>
                <p class="name"><?= htmlspecialchars($signature['signatory_name']) ?></p>
                <p class="title"><?= htmlspecialchars($signature['signatory_title'] ?? 'University Registrar') ?></p>
            <?php else: ?>
                <div class="sig-line-print"></div>
                <p class="name">_________________________</p>
                <p class="title">University Registrar</p>
            <?php endif; ?>
        </div>

        <!-- Research Coordinator -->
        <div class="sig-col">
            <div class="sig-line-print"></div>
            <p class="name"><?= htmlspecialchars($coordinator_name ?: '_________________________') ?></p>
            <p class="title">Research Coordinator</p>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        Exploits University of Malawi &nbsp;|&nbsp; This letter is computer-generated and is valid without physical stamp.
        <?php if ($letter['letter_reference']): ?>
            &nbsp;|&nbsp; Ref: <?= htmlspecialchars($letter['letter_reference']) ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
