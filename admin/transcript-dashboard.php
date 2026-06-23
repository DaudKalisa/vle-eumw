<?php
/**
 * Admin – Transcript Management Dashboard
 * Central hub for all transcript-related operations.
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = [];

// transcript_uploads table may not exist yet — check first
$tbl_check = $conn->query("SHOW TABLES LIKE 'transcript_uploads'");
if ($tbl_check && $tbl_check->num_rows > 0) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM transcript_uploads");
    $stats['total_uploads'] = (int)($r ? $r->fetch_assoc()['c'] : 0);

    $r = $conn->query("SELECT COUNT(*) AS c FROM transcript_uploads WHERE status='confirmed'");
    $stats['confirmed'] = (int)($r ? $r->fetch_assoc()['c'] : 0);

    $r = $conn->query("SELECT COUNT(*) AS c FROM transcript_uploads WHERE status='draft'");
    $stats['drafts'] = (int)($r ? $r->fetch_assoc()['c'] : 0);

    $r = $conn->query("SELECT COUNT(*) AS c FROM transcript_uploads WHERE application_id IS NOT NULL");
    $stats['linked'] = (int)($r ? $r->fetch_assoc()['c'] : 0);

    // Recent 5
    $recent_rs = $conn->query(
        "SELECT u.*, (SELECT COUNT(*) FROM transcript_upload_modules WHERE upload_id=u.upload_id) AS module_count
         FROM transcript_uploads u ORDER BY u.uploaded_at DESC LIMIT 5"
    );
    $recent_uploads = [];
    if ($recent_rs) while ($row = $recent_rs->fetch_assoc()) $recent_uploads[] = $row;
} else {
    $stats = ['total_uploads' => 0, 'confirmed' => 0, 'drafts' => 0, 'linked' => 0];
    $recent_uploads = [];
}

// Graduation transcript (ICT) stats
$r2 = $conn->query("SELECT COUNT(DISTINCT application_id) AS c FROM graduation_ict_modules");
$stats['grad_with_grades'] = (int)($r2 ? $r2->fetch_assoc()['c'] : 0);

$r3 = $conn->query("SELECT COUNT(*) AS c FROM graduation_applications WHERE status NOT IN ('rejected','pending')");
$stats['active_grad'] = (int)($r3 ? $r3->fetch_assoc()['c'] : 0);

$page_title = 'Transcript Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $page_title ?> – VLE Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
    body{background:#f0f4f8;font-family:'Segoe UI',sans-serif;}
    .top-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
    .page-header{background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;border-radius:16px;padding:1.75rem 2rem;margin-bottom:1.5rem;}
    .stat-card{background:#fff;border-radius:14px;padding:1.4rem 1.2rem;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,.06);border:1px solid #e2e8f0;}
    .stat-card .stat-num{font-size:2.1rem;font-weight:800;line-height:1;}
    .stat-card .stat-label{font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-top:.35rem;}
    .qa-card{border-radius:14px;padding:1.4rem 1.3rem;color:#fff;cursor:pointer;text-decoration:none;display:block;transition:transform .15s,box-shadow .15s;}
    .qa-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.18);color:#fff;}
    .qa-card .qa-icon{font-size:2.2rem;display:block;margin-bottom:.6rem;}
    .qa-card .qa-title{font-size:1rem;font-weight:700;}
    .qa-card .qa-desc{font-size:.78rem;opacity:.85;margin-top:.2rem;}
    .qa-primary{background:linear-gradient(135deg,#7c3aed,#6d28d9);}
    .qa-success{background:linear-gradient(135deg,#059669,#047857);}
    .qa-info{background:linear-gradient(135deg,#0284c7,#075985);}
    .qa-warning{background:linear-gradient(135deg,#d97706,#b45309);}
    .qa-secondary{background:linear-gradient(135deg,#475569,#334155);}
    .section-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.05);}
    .badge-confirmed{background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:50px;font-size:.72rem;font-weight:600;}
    .badge-draft{background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:50px;font-size:.72rem;font-weight:600;}
    .upload-hint-box{background:#faf5ff;border:1px solid #e9d5ff;border-radius:10px;padding:1rem 1.25rem;}
</style>
</head>
<body>

<div class="top-bar">
    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Admin Dashboard
    </a>
    <span class="fw-bold" style="color:#7c3aed;"><i class="bi bi-file-earmark-text me-1"></i><?= $page_title ?></span>
    <a href="graduation_students.php" class="btn btn-sm btn-outline-success">
        <i class="bi bi-mortarboard me-1"></i>Graduation Students
    </a>
</div>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:1300px;">

    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div>
                <h4 class="mb-1 fw-bold"><i class="bi bi-journals me-2"></i><?= $page_title ?></h4>
                <p class="mb-0 opacity-75">Manage academic transcripts — upload old transcripts, link grades, and generate official documents.</p>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3 col-xl-2">
            <div class="stat-card">
                <div class="stat-num text-purple" style="color:#7c3aed;"><?= $stats['total_uploads'] ?></div>
                <div class="stat-label">Uploaded Transcripts</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="stat-card">
                <div class="stat-num text-success"><?= $stats['confirmed'] ?></div>
                <div class="stat-label">Confirmed</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="stat-card">
                <div class="stat-num text-warning"><?= $stats['drafts'] ?></div>
                <div class="stat-label">Drafts</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="stat-card">
                <div class="stat-num text-info"><?= $stats['linked'] ?></div>
                <div class="stat-label">Linked to App</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="stat-card">
                <div class="stat-num text-primary"><?= $stats['grad_with_grades'] ?></div>
                <div class="stat-label">Grad. w/ Grades</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <div class="stat-card">
                <div class="stat-num text-secondary"><?= $stats['active_grad'] ?></div>
                <div class="stat-label">Active Grad. Apps</div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="section-card mb-4">
        <h6 class="fw-bold mb-3"><i class="bi bi-lightning-charge-fill me-2 text-warning"></i>Quick Actions</h6>
        <div class="row g-3">

            <!-- PRIMARY: Upload Old Transcript -->
            <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                <a href="transcript-grades.php" class="qa-card qa-primary">
                    <i class="bi bi-cloud-upload qa-icon"></i>
                    <div class="qa-title">Upload Old Transcript</div>
                    <div class="qa-desc">Convert a paper or PDF transcript into digital grade records using the Excel template.</div>
                </a>
            </div>

            <!-- Download Template -->
            <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                <a href="transcript-grades.php?action=download_template" class="qa-card qa-success">
                    <i class="bi bi-file-earmark-arrow-down qa-icon"></i>
                    <div class="qa-title">Download Grade Template</div>
                    <div class="qa-desc">Get the Excel template to fill in module grades from a student's PDF transcript.</div>
                </a>
            </div>

            <!-- View All Uploads -->
            <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                <a href="transcript-grades.php" class="qa-card qa-info">
                    <i class="bi bi-table qa-icon"></i>
                    <div class="qa-title">View All Uploads</div>
                    <div class="qa-desc">Browse, search, and manage all previously uploaded transcript records.</div>
                </a>
            </div>

            <!-- ICT Clearance (enter grades inline) -->
            <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                <a href="graduation_ict_clearance.php" class="qa-card qa-warning">
                    <i class="bi bi-pencil-square qa-icon"></i>
                    <div class="qa-title">Enter Grades (ICT Step)</div>
                    <div class="qa-desc">Manually enter graduation clearance grades for a specific application at the ICT step.</div>
                </a>
            </div>

            <!-- Generate Transcript PDF -->
            <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                <a href="graduation_students.php" class="qa-card qa-secondary">
                    <i class="bi bi-file-earmark-pdf qa-icon"></i>
                    <div class="qa-title">Generate Transcript PDF</div>
                    <div class="qa-desc">Generate and download the official academic transcript PDF for a graduation applicant.</div>
                </a>
            </div>

            <!-- Graduation Students -->
            <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                <a href="graduation_students.php" class="qa-card" style="background:linear-gradient(135deg,#0e7490,#155e75);">
                    <i class="bi bi-mortarboard qa-icon"></i>
                    <div class="qa-title">Graduation Students</div>
                    <div class="qa-desc">Manage all graduation clearance applications and track each student's clearance progress.</div>
                </a>
            </div>

        </div>
    </div>

    <!-- How-to Guide -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="section-card h-100">
                <h6 class="fw-bold mb-3"><i class="bi bi-info-circle-fill me-2 text-purple" style="color:#7c3aed;"></i>How to Upload an Old Transcript (PDF → Grades)</h6>
                <ol class="mb-0 ps-3">
                    <li class="mb-2">
                        <strong>Download the Excel template</strong> using the Quick Action above (or from the upload page).
                    </li>
                    <li class="mb-2">
                        <strong>Open the student's PDF transcript</strong> (e.g. Alice Manyunya's transcript in Downloads).
                    </li>
                    <li class="mb-2">
                        <strong>Fill in the template:</strong> enter Student Name, ID, Program in the yellow rows (1–6), then add one module per row from row 9 onward with Year, Semester, Code, Name, and Marks.
                    </li>
                    <li class="mb-2">
                        <strong>Leave Grade &amp; Grade Point blank</strong> — they are auto-calculated from the Marks on upload.
                    </li>
                    <li class="mb-2">
                        Click <strong>"Upload Old Transcript"</strong> and select your filled Excel file.
                    </li>
                    <li class="mb-2">
                        After upload, click <strong>"View"</strong> on the record, optionally link it to an existing Graduation Application, then click <strong>"Confirm &amp; Push Grades to System"</strong> to copy the grades into the graduation module records.
                    </li>
                </ol>
            </div>
        </div>

        <div class="col-lg-6">
            <!-- Recent Uploads -->
            <div class="section-card h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-2" style="color:#7c3aed;"></i>Recent Uploads</h6>
                    <a href="transcript-grades.php" class="btn btn-xs btn-outline-primary btn-sm">View All</a>
                </div>
                <?php if (empty($recent_uploads)): ?>
                <div class="upload-hint-box text-center py-4">
                    <i class="bi bi-inbox display-6 text-muted d-block mb-2"></i>
                    <p class="mb-2 text-muted">No transcripts uploaded yet.</p>
                    <a href="transcript-grades.php" class="btn btn-sm btn-primary">
                        <i class="bi bi-upload me-1"></i>Upload First Transcript
                    </a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Student</th>
                            <th class="text-center">Modules</th>
                            <th class="text-center">GPA</th>
                            <th class="text-center">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_uploads as $u): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($u['student_name']) ?></strong>
                            <div class="text-muted" style="font-size:.75rem;"><?= htmlspecialchars($u['student_id_number'] ?? '') ?></div>
                        </td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $u['module_count'] ?></span></td>
                        <td class="text-center fw-bold" style="color:#7c3aed;">
                            <?= $u['overall_gpa'] ? number_format($u['overall_gpa'], 2) : '—' ?>
                        </td>
                        <td class="text-center">
                            <span class="<?= $u['status'] === 'confirmed' ? 'badge-confirmed' : 'badge-draft' ?>">
                                <?= $u['status'] === 'confirmed' ? '✓ Confirmed' : '✏ Draft' ?>
                            </span>
                        </td>
                        <td>
                            <a href="transcript-grades.php?view=<?= $u['upload_id'] ?>" class="btn btn-xs btn-outline-primary btn-sm py-0 px-2">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
