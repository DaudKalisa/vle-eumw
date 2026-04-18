<?php
/**
 * Lecturer Dissertation Guidelines Overview
 * All dissertation guidelines for lecturers/supervisors to reference
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer', 'admin', 'staff', 'research_coordinator']);

$user = getCurrentUser();
$conn = getDbConnection();

// Get all active guidelines grouped by phase
$all_guidelines = [];
$r = $conn->query("SELECT * FROM dissertation_guidelines WHERE is_active = 1 ORDER BY phase, section_order");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $all_guidelines[$row['phase']][] = $row;
    }
}

// Phase display config
$phase_display = [
    'general' => ['label' => 'General Requirements', 'icon' => 'bi-info-circle', 'color' => '#6366f1'],
    'formatting' => ['label' => 'Formatting & Structure', 'icon' => 'bi-file-earmark-ruled', 'color' => '#8b5cf6'],
    'topic' => ['label' => 'Topic Selection', 'icon' => 'bi-lightbulb', 'color' => '#a855f7'],
    'concept_note' => ['label' => 'Concept Note', 'icon' => 'bi-file-text', 'color' => '#d946ef'],
    'chapter1' => ['label' => 'Chapter 1 - Introduction', 'icon' => 'bi-1-circle', 'color' => '#0ea5e9'],
    'chapter2' => ['label' => 'Chapter 2 - Literature Review', 'icon' => 'bi-2-circle', 'color' => '#06b6d4'],
    'chapter3' => ['label' => 'Chapter 3 - Methodology', 'icon' => 'bi-3-circle', 'color' => '#14b8a6'],
    'chapter4' => ['label' => 'Chapter 4 - Results & Discussion', 'icon' => 'bi-4-circle', 'color' => '#f97316'],
    'chapter5' => ['label' => 'Chapter 5 - Conclusions', 'icon' => 'bi-5-circle', 'color' => '#ef4444'],
    'proposal' => ['label' => 'Full Proposal', 'icon' => 'bi-file-earmark-check', 'color' => '#10b981'],
    'ethics' => ['label' => 'Ethics Clearance', 'icon' => 'bi-shield-check', 'color' => '#22c55e'],
    'defense' => ['label' => 'Proposal Defense', 'icon' => 'bi-mortarboard', 'color' => '#eab308'],
    'presentation' => ['label' => 'Final Result Presentation', 'icon' => 'bi-easel', 'color' => '#7c3aed'],
    'references' => ['label' => 'References', 'icon' => 'bi-bookmark-star', 'color' => '#f97316'],
    'appendices' => ['label' => 'Appendices', 'icon' => 'bi-paperclip', 'color' => '#64748b'],
];

$phase_order = ['general', 'formatting', 'topic', 'concept_note', 'chapter1', 'chapter2', 'chapter3', 'proposal', 'ethics', 'defense', 'chapter4', 'chapter5', 'presentation', 'references', 'appendices'];

$page_title = 'Dissertation Guidelines';
$breadcrumbs = [
    ['title' => 'Dissertation Supervision', 'url' => 'dissertation_supervision.php'],
    ['title' => 'Guidelines']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dissertation Guidelines - Lecturer Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .phase-section { border-left: 4px solid; border-radius: 8px; margin-bottom: 1.5rem; }
        .phase-header { padding: 1rem 1.25rem; cursor: pointer; border-radius: 8px 8px 0 0; transition: background 0.2s; }
        .phase-header:hover { filter: brightness(0.97); }
        .guideline-item { padding: 1rem 1.25rem; border-top: 1px solid #f1f5f9; }
        .guideline-title { font-weight: 600; font-size: 1rem; color: #1e293b; }
        .guideline-content { font-size: 0.9rem; line-height: 1.7; color: #475569; white-space: pre-line; }
        .word-count-badge { font-size: 0.7rem; background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 12px; }
        .toc-link { color: #475569; text-decoration: none; padding: 4px 8px; display: block; border-radius: 4px; font-size: 0.85rem; transition: all 0.15s; }
        .toc-link:hover { background: #f1f5f9; color: #1e293b; }
        .toc-link.active { background: #eff6ff; color: #2563eb; font-weight: 600; border-left: 3px solid #2563eb; }
        .sticky-toc { position: sticky; top: 80px; }
        @media print { .no-print { display: none !important; } .phase-section { break-inside: avoid; } }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>

<div class="container-fluid py-4">
    <div class="row">
        <!-- Left: Table of Contents -->
        <div class="col-lg-3 no-print">
            <div class="sticky-toc">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-2">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-list-nested me-1"></i>Table of Contents</h6>
                    </div>
                    <div class="card-body p-2" style="max-height: calc(100vh - 200px); overflow-y: auto;">
                        <?php foreach ($phase_order as $phase_key): ?>
                            <?php if (isset($all_guidelines[$phase_key])): ?>
                                <?php $pd = $phase_display[$phase_key] ?? ['label' => ucfirst($phase_key), 'icon' => 'bi-circle', 'color' => '#6b7280']; ?>
                                <a href="#section-<?= $phase_key ?>" class="toc-link">
                                    <i class="bi <?= $pd['icon'] ?> me-1" style="color:<?= $pd['color'] ?>"></i>
                                    <?= $pd['label'] ?>
                                    <span class="badge bg-light text-muted ms-1"><?= count($all_guidelines[$phase_key]) ?></span>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <hr class="my-2">
                        <div class="d-grid gap-1 px-1">
                            <a href="guidelinebook.php" class="btn btn-sm btn-primary">
                                <i class="bi bi-journal-richtext me-1"></i>Full Guideline Book
                            </a>
                            <a href="dissertation_supervision.php" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-arrow-left me-1"></i>My Students
                            </a>
                            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-printer me-1"></i>Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Guidelines Content -->
        <div class="col-lg-9">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold mb-1"><i class="bi bi-book me-2 text-primary"></i>Dissertation Guidelines</h3>
                    <p class="text-muted mb-0">Reference guide for supervisors — standards and expectations for each dissertation phase.</p>
                </div>
                <div class="d-flex gap-2 no-print">
                    <a href="guidelinebook.php" class="btn btn-primary">
                        <i class="bi bi-journal-richtext me-1"></i>Read Full Guideline Book
                    </a>
                    <a href="dissertation_supervision.php" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left me-1"></i>My Students
                    </a>
                </div>
            </div>

            <?php if (empty($all_guidelines)): ?>
                <div class="alert alert-info text-center py-5">
                    <i class="bi bi-info-circle" style="font-size: 2rem;"></i>
                    <p class="mt-2 mb-0">No guidelines have been published yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($phase_order as $phase_key): ?>
                    <?php if (!isset($all_guidelines[$phase_key])) continue; ?>
                    <?php $pd = $phase_display[$phase_key] ?? ['label' => ucfirst($phase_key), 'icon' => 'bi-circle', 'color' => '#6b7280']; ?>
                    
                    <div class="phase-section bg-white shadow-sm" id="section-<?= $phase_key ?>" style="border-color: <?= $pd['color'] ?>;">
                        <div class="phase-header d-flex align-items-center justify-content-between" 
                             data-bs-toggle="collapse" data-bs-target="#content-<?= $phase_key ?>" 
                             aria-expanded="true">
                            <div>
                                <i class="bi <?= $pd['icon'] ?> me-2" style="color:<?= $pd['color'] ?>; font-size: 1.2rem;"></i>
                                <span class="fw-bold" style="font-size: 1.1rem;"><?= $pd['label'] ?></span>
                                <span class="badge bg-light text-muted ms-2"><?= count($all_guidelines[$phase_key]) ?> section<?= count($all_guidelines[$phase_key]) > 1 ? 's' : '' ?></span>
                            </div>
                            <i class="bi bi-chevron-down text-muted"></i>
                        </div>

                        <div class="collapse show" id="content-<?= $phase_key ?>">
                            <?php foreach ($all_guidelines[$phase_key] as $g): ?>
                            <div class="guideline-item">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="guideline-title mb-0">
                                        <i class="bi bi-bookmark-fill me-1" style="color:<?= $pd['color'] ?>; font-size: 0.8rem;"></i>
                                        <?= htmlspecialchars($g['section_title']) ?>
                                    </h6>
                                    <div class="d-flex gap-2">
                                        <?php if ($g['min_word_count'] || $g['max_word_count']): ?>
                                            <span class="word-count-badge">
                                                <i class="bi bi-text-paragraph me-1"></i>
                                                <?php if ($g['min_word_count'] && $g['max_word_count']): ?>
                                                    <?= number_format($g['min_word_count']) ?>–<?= number_format($g['max_word_count']) ?> words
                                                <?php elseif ($g['min_word_count']): ?>
                                                    Min <?= number_format($g['min_word_count']) ?> words
                                                <?php else: ?>
                                                    Max <?= number_format($g['max_word_count']) ?> words
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($g['min_pages'] || $g['max_pages']): ?>
                                            <span class="word-count-badge">
                                                <i class="bi bi-file-earmark me-1"></i>
                                                <?php if ($g['min_pages'] && $g['max_pages']): ?>
                                                    <?= $g['min_pages'] ?>–<?= $g['max_pages'] ?> pages
                                                <?php elseif ($g['min_pages']): ?>
                                                    Min <?= $g['min_pages'] ?> pages
                                                <?php else: ?>
                                                    Max <?= $g['max_pages'] ?> pages
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="guideline-content"><?= nl2br(htmlspecialchars($g['content'])) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('scroll', function() {
    const sections = document.querySelectorAll('.phase-section');
    const links = document.querySelectorAll('.toc-link');
    let current = '';
    sections.forEach(s => {
        if (s.getBoundingClientRect().top <= 120) current = s.id;
    });
    links.forEach(l => {
        l.classList.remove('active');
        if (current && l.getAttribute('href') === '#' + current) l.classList.add('active');
    });
});
</script>
</body>
</html>
