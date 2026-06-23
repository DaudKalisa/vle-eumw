<?php
/**
 * Admin – Manage Registration Links
 * Add, edit, toggle, and delete links shown on the public apply.php page.
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin', 'super_admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$success = '';
$error = '';

// Categories config
$categories = [
    'student_registration'  => ['title' => 'Student Registration',  'icon' => 'bi-mortarboard',      'color' => '#6366f1'],
    'exam_clearance'        => ['title' => 'Exam Clearance',        'icon' => 'bi-clipboard-check',   'color' => '#10b981'],
    'finance_clearance'     => ['title' => 'Finance Clearance',     'icon' => 'bi-cash-coin',         'color' => '#f59e0b'],
    'graduation_clearance'  => ['title' => 'Graduation Clearance',  'icon' => 'bi-award',             'color' => '#f43f5e'],
    'dissertation'          => ['title' => 'Dissertation Registration', 'icon' => 'bi-journal-text',  'color' => '#14b8a6'],
];

// ── Handle POST actions ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add new link
    if ($action === 'add') {
        $category     = trim($_POST['category'] ?? '');
        $label        = trim($_POST['label'] ?? '');
        $url          = trim($_POST['url'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $program_type = trim($_POST['program_type'] ?? '');
        $icon         = trim($_POST['icon'] ?? 'bi-link-45deg');
        $btn_class    = trim($_POST['btn_class'] ?? 'btn-green');
        $btn_style    = trim($_POST['btn_style'] ?? '');
        $sort_order   = (int)($_POST['sort_order'] ?? 0);

        if (!$category || !$label || !$url) {
            $error = 'Category, Label, and URL are required.';
        } elseif (!array_key_exists($category, $categories)) {
            $error = 'Invalid category.';
        } else {
            $stmt = $conn->prepare("INSERT INTO registration_links (category, label, url, description, program_type, icon, btn_class, btn_style, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $uid = (int)$user['user_id'];
            $desc_val  = $description ?: null;
            $ptype_val = $program_type ?: null;
            $style_val = $btn_style ?: null;
            $stmt->bind_param("ssssssssis", $category, $label, $url, $desc_val, $ptype_val, $icon, $btn_class, $style_val, $sort_order, $uid);
            if ($stmt->execute()) {
                $success = 'Link added successfully!';
            } else {
                $error = 'Failed to add link: ' . $conn->error;
            }
        }
    }

    // Edit link
    if ($action === 'edit') {
        $link_id      = (int)($_POST['link_id'] ?? 0);
        $label        = trim($_POST['label'] ?? '');
        $url          = trim($_POST['url'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $program_type = trim($_POST['program_type'] ?? '');
        $icon         = trim($_POST['icon'] ?? 'bi-link-45deg');
        $btn_class    = trim($_POST['btn_class'] ?? 'btn-green');
        $btn_style    = trim($_POST['btn_style'] ?? '');
        $sort_order   = (int)($_POST['sort_order'] ?? 0);
        $category     = trim($_POST['category'] ?? '');

        if (!$link_id || !$label || !$url) {
            $error = 'Label and URL are required.';
        } else {
            $desc_val  = $description ?: null;
            $ptype_val = $program_type ?: null;
            $style_val = $btn_style ?: null;
            $stmt = $conn->prepare("UPDATE registration_links SET category=?, label=?, url=?, description=?, program_type=?, icon=?, btn_class=?, btn_style=?, sort_order=? WHERE link_id=?");
            $stmt->bind_param("ssssssssii", $category, $label, $url, $desc_val, $ptype_val, $icon, $btn_class, $style_val, $sort_order, $link_id);
            if ($stmt->execute()) {
                $success = 'Link updated successfully!';
            } else {
                $error = 'Failed to update link: ' . $conn->error;
            }
        }
    }

    // Toggle active
    if ($action === 'toggle') {
        $link_id = (int)($_POST['link_id'] ?? 0);
        if ($link_id) {
            $conn->query("UPDATE registration_links SET is_active = NOT is_active WHERE link_id = $link_id");
            $success = 'Link status toggled.';
        }
    }

    // Delete
    if ($action === 'delete') {
        $link_id = (int)($_POST['link_id'] ?? 0);
        if ($link_id) {
            $stmt = $conn->prepare("DELETE FROM registration_links WHERE link_id = ?");
            $stmt->bind_param("i", $link_id);
            $stmt->execute();
            $success = 'Link deleted.';
        }
    }
}

// ── Fetch all links ─────────────────────────────────────────────────────────
$links = [];
$rs = $conn->query("SELECT * FROM registration_links ORDER BY category, sort_order, link_id");
if ($rs) while ($r = $rs->fetch_assoc()) $links[] = $r;

// Group by category
$grouped = [];
foreach ($links as $l) {
    $grouped[$l['category']][] = $l;
}

$page_title = 'Manage Registration Links';
$breadcrumbs = [['title' => 'Manage Registration Links']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> – VLE Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#f0f4f8;}
        .page-header{background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;}
        .card-custom{background:#fff;border-radius:12px;border:none;box-shadow:0 2px 12px rgba(0,0,0,.06);margin-bottom:1.5rem;}
        .link-item{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.25rem;margin-bottom:.6rem;transition:box-shadow .2s;}
        .link-item:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
        .link-item.inactive{opacity:.55;border-left:4px solid #ef4444;}
        .link-item.active-link{border-left:4px solid #10b981;}
        .cat-badge{display:inline-block;padding:3px 10px;border-radius:50px;font-size:.75rem;font-weight:700;color:#fff;}
        .url-display{font-size:.78rem;color:#6b7280;word-break:break-all;}
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:1200px;">
    <!-- Header -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h4 class="mb-1"><i class="bi bi-link-45deg me-2"></i><?= $page_title ?></h4>
            <p class="mb-0 opacity-75">Manage links displayed on the public Registration Portal (apply.php)</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-white text-primary fs-6"><?= count($links) ?> links</span>
            <a href="../apply.php" target="_blank" class="btn btn-light btn-sm"><i class="bi bi-eye me-1"></i>View Public Page</a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <!-- Add New Link -->
    <div class="card-custom">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="mb-0"><i class="bi bi-plus-circle me-2 text-primary"></i>Add New Registration Link</h5>
        </div>
        <div class="card-body pt-0">
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-600">Category <span class="text-danger">*</span></label>
                        <select name="category" class="form-select" required>
                            <?php foreach ($categories as $key => $cat): ?>
                            <option value="<?= $key ?>"><?= $cat['title'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-600">Button Label <span class="text-danger">*</span></label>
                        <input type="text" name="label" class="form-control" placeholder="e.g. Degree Programme" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-600">URL <span class="text-danger">*</span></label>
                        <input type="text" name="url" class="form-control" placeholder="https://... or relative path" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Description <small class="text-muted">(shown on card)</small></label>
                        <input type="text" name="description" class="form-control" placeholder="Brief description">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Programme Type</label>
                        <select name="program_type" class="form-select">
                            <option value="">None</option>
                            <option value="degree">Degree</option>
                            <option value="masters">Masters</option>
                            <option value="doctorate">Doctorate</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Icon</label>
                        <input type="text" name="icon" class="form-control" value="bi-link-45deg" placeholder="bi-icon-name">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Button Color</label>
                        <select name="btn_class" class="form-select">
                            <option value="btn-green">Green</option>
                            <option value="btn-purple">Purple</option>
                            <option value="btn-orange">Orange</option>
                            <option value="btn-rose">Rose/Red</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0" min="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Custom CSS Style</label>
                        <input type="text" name="btn_style" class="form-control" placeholder="Optional inline CSS">
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Link</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Links by Category -->
    <?php foreach ($categories as $cat_key => $cat_info): ?>
    <div class="card-custom">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="<?= $cat_info['icon'] ?> me-2" style="color:<?= $cat_info['color'] ?>"></i><?= $cat_info['title'] ?>
                <span class="badge bg-secondary ms-2"><?= count($grouped[$cat_key] ?? []) ?></span>
            </h5>
        </div>
        <div class="card-body pt-0">
            <?php if (empty($grouped[$cat_key])): ?>
                <p class="text-muted text-center py-3"><i class="bi bi-inbox fs-4 d-block mb-1"></i>No links in this category.</p>
            <?php else: ?>
                <?php foreach ($grouped[$cat_key] as $lnk): ?>
                <div class="link-item <?= $lnk['is_active'] ? 'active-link' : 'inactive' ?>">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div style="flex:1;min-width:250px;">
                            <div class="fw-bold mb-1">
                                <i class="<?= htmlspecialchars($lnk['icon']) ?> me-1" style="color:<?= $cat_info['color'] ?>"></i>
                                <?= htmlspecialchars($lnk['label']) ?>
                                <?php if ($lnk['program_type']): ?>
                                    <span class="badge bg-info ms-1"><?= ucfirst($lnk['program_type']) ?></span>
                                <?php endif; ?>
                                <?php if ($lnk['is_active']): ?>
                                    <span class="badge bg-success ms-1">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger ms-1">Hidden</span>
                                <?php endif; ?>
                                <small class="text-muted ms-2">Order: <?= $lnk['sort_order'] ?></small>
                            </div>
                            <div class="url-display mb-1"><?= htmlspecialchars($lnk['url']) ?></div>
                            <?php if ($lnk['description']): ?>
                                <small class="text-muted"><?= htmlspecialchars(mb_strimwidth($lnk['description'], 0, 120, '...')) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1 flex-shrink-0">
                            <!-- Edit Button -->
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $lnk['link_id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
                            <!-- Toggle -->
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="link_id" value="<?= $lnk['link_id'] ?>">
                                <?php if ($lnk['is_active']): ?>
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Hide"><i class="bi bi-eye-slash"></i></button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Show"><i class="bi bi-eye"></i></button>
                                <?php endif; ?>
                            </form>
                            <!-- Delete -->
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this link?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="link_id" value="<?= $lnk['link_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Modal -->
                <div class="modal fade" id="editModal<?= $lnk['link_id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="post">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="link_id" value="<?= $lnk['link_id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Link</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Category</label>
                                            <select name="category" class="form-select">
                                                <?php foreach ($categories as $k => $c): ?>
                                                <option value="<?= $k ?>" <?= $lnk['category'] === $k ? 'selected' : '' ?>><?= $c['title'] ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Button Label <span class="text-danger">*</span></label>
                                            <input type="text" name="label" class="form-control" value="<?= htmlspecialchars($lnk['label']) ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Programme Type</label>
                                            <select name="program_type" class="form-select">
                                                <option value="">None</option>
                                                <option value="degree" <?= ($lnk['program_type'] ?? '') === 'degree' ? 'selected' : '' ?>>Degree</option>
                                                <option value="masters" <?= ($lnk['program_type'] ?? '') === 'masters' ? 'selected' : '' ?>>Masters</option>
                                                <option value="doctorate" <?= ($lnk['program_type'] ?? '') === 'doctorate' ? 'selected' : '' ?>>Doctorate</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">URL <span class="text-danger">*</span></label>
                                            <input type="text" name="url" class="form-control" value="<?= htmlspecialchars($lnk['url']) ?>" required>
                                        </div>
                                        <div class="col-md-12">
                                            <label class="form-label">Description</label>
                                            <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($lnk['description'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Icon</label>
                                            <input type="text" name="icon" class="form-control" value="<?= htmlspecialchars($lnk['icon']) ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Button Color</label>
                                            <select name="btn_class" class="form-select">
                                                <option value="btn-green" <?= $lnk['btn_class'] === 'btn-green' ? 'selected' : '' ?>>Green</option>
                                                <option value="btn-purple" <?= $lnk['btn_class'] === 'btn-purple' ? 'selected' : '' ?>>Purple</option>
                                                <option value="btn-orange" <?= $lnk['btn_class'] === 'btn-orange' ? 'selected' : '' ?>>Orange</option>
                                                <option value="btn-rose" <?= $lnk['btn_class'] === 'btn-rose' ? 'selected' : '' ?>>Rose/Red</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Order</label>
                                            <input type="number" name="sort_order" class="form-control" value="<?= $lnk['sort_order'] ?>" min="0">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Custom CSS</label>
                                            <input type="text" name="btn_style" class="form-control" value="<?= htmlspecialchars($lnk['btn_style'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
