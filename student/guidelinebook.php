<?php
/**
 * Dissertation Guideline Book - Full Version
 * Renders the complete dissertation guideline document as a web book
 * Non-downloadable, view-only for students and lecturers
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
requireRole(['student', 'admin', 'lecturer', 'staff', 'research_coordinator']);

$user = getCurrentUser();
$role = $_SESSION['vle_role'] ?? 'student';

// Determine back URL based on role
$back_url = 'dissertation.php';
$back_label = 'My Dissertation';
if (in_array($role, ['lecturer', 'staff'])) {
    $back_url = '../lecturer/dissertation_supervision.php';
    $back_label = 'Dissertation Supervision';
} elseif ($role === 'research_coordinator') {
    $back_url = '../research_coordinator/manage_dissertations.php';
    $back_label = 'Manage Dissertations';
} elseif ($role === 'admin') {
    $back_url = 'dissertation_guidelines.php';
    $back_label = 'Guidelines Overview';
}

// Load parsed guideline content
$json_path = __DIR__ . '/../_guideline_parsed.json';
$content_items = [];
if (file_exists($json_path)) {
    $content_items = json_decode(file_get_contents($json_path), true) ?: [];
}

// Build table of contents from H1 headings
$toc = [];
$chapter_idx = 0;
foreach ($content_items as $idx => $item) {
    if ($item['type'] === 'h1') {
        $chapter_idx++;
        $toc[] = [
            'id' => 'section-' . $chapter_idx,
            'title' => $item['text'],
            'index' => $idx
        ];
    }
}

$page_title = 'Dissertation Guideline Book';
$breadcrumbs = [
    ['title' => 'Dissertation', 'url' => $back_url],
    ['title' => 'Guideline Book']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dissertation Guideline Book - Exploits University</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        /* Prevent downloading/copying */
        .book-content {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }
        .book-content img {
            pointer-events: none;
            -webkit-user-drag: none;
        }

        /* Book styling */
        .book-container {
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            overflow: hidden;
        }
        .book-cover {
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 40%, #1e3a5f 100%);
            color: #fff;
            padding: 4rem 3rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .book-cover::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 60%);
            animation: coverShimmer 8s ease-in-out infinite;
        }
        @keyframes coverShimmer {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(10%, 10%); }
        }
        .book-cover .university-logo {
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            border: 3px solid rgba(255,255,255,0.3);
        }
        .book-cover h1 {
            font-size: 1.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 1rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .book-cover .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 300;
            margin-bottom: 2rem;
        }
        .book-cover .edition {
            display: inline-block;
            padding: 6px 20px;
            border: 2px solid rgba(255,255,255,0.4);
            border-radius: 25px;
            font-size: 0.85rem;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .book-body {
            padding: 2rem 3rem;
            font-family: 'Georgia', 'Times New Roman', serif;
            font-size: 1.05rem;
            line-height: 1.8;
            color: #2d3748;
        }

        /* TOC Sidebar */
        .toc-sidebar {
            position: sticky;
            top: 80px;
            max-height: calc(100vh - 100px);
            overflow-y: auto;
        }
        .toc-sidebar::-webkit-scrollbar { width: 4px; }
        .toc-sidebar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .toc-item {
            display: block;
            padding: 6px 12px;
            color: #64748b;
            text-decoration: none;
            font-size: 0.8rem;
            border-left: 3px solid transparent;
            transition: all 0.2s;
            line-height: 1.4;
            margin-bottom: 2px;
        }
        .toc-item:hover { color: #1e3a5f; background: #f8fafc; border-left-color: #94a3b8; }
        .toc-item.active { color: #1e3a5f; background: #eff6ff; border-left-color: #2563eb; font-weight: 600; }

        /* Content headings */
        .book-body .ch-h1 {
            font-family: 'Inter', -apple-system, sans-serif;
            font-size: 1.8rem;
            font-weight: 800;
            color: #1e3a5f;
            text-transform: uppercase;
            border-bottom: 3px solid #1e3a5f;
            padding-bottom: 0.75rem;
            margin: 3rem 0 1.5rem;
            letter-spacing: 1px;
            page-break-before: always;
        }
        .book-body .ch-h1:first-child { margin-top: 0; }
        .book-body .ch-h2 {
            font-family: 'Inter', -apple-system, sans-serif;
            font-size: 1.35rem;
            font-weight: 700;
            color: #2d5a87;
            margin: 2rem 0 1rem;
            padding-left: 0;
            border-left: none;
        }
        .book-body .ch-h3 {
            font-family: 'Inter', -apple-system, sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            color: #374151;
            margin: 1.5rem 0 0.75rem;
        }
        .book-body .ch-h4 {
            font-family: 'Inter', -apple-system, sans-serif;
            font-size: 1rem;
            font-weight: 600;
            color: #4b5563;
            margin: 1.25rem 0 0.5rem;
            font-style: italic;
        }
        .book-body .ch-p {
            margin-bottom: 1rem;
            text-align: justify;
        }
        .book-body .ch-li {
            margin-bottom: 0.4rem;
            padding-left: 0;
        }
        .book-body .list-group-0 { margin-left: 0; }
        .book-body .list-group-1 { margin-left: 0; }
        .book-body .list-group-2 { margin-left: 0; }
        .book-body .list-group-3 { margin-left: 0; }

        .book-body .ch-img {
            display: block;
            max-width: 100%;
            margin: 1.5rem auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        /* Example boxes */
        .example-box {
            background: #f0f9ff;
            border-left: 4px solid #3b82f6;
            padding: 1rem 1.25rem;
            margin: 1rem 0;
            border-radius: 0 8px 8px 0;
            font-size: 0.95rem;
        }
        .tip-box {
            background: #f0fdf4;
            border-left: 4px solid #22c55e;
            padding: 0.75rem 1rem;
            margin: 0.75rem 0;
            border-radius: 0 8px 8px 0;
            font-size: 0.9rem;
        }

        /* Reading progress bar */
        .reading-progress {
            position: fixed;
            top: 0;
            left: 0;
            width: 0%;
            height: 3px;
            background: linear-gradient(90deg, #2563eb, #7c3aed);
            z-index: 9999;
            transition: width 0.1s;
        }

        /* Navigation */
        .book-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 0;
        }

        /* Print prevention */
        @media print {
            body { display: none !important; }
        }

        /* Responsive */
        @media (max-width: 991px) {
            .book-body { padding: 1.5rem; }
            .book-cover { padding: 2.5rem 1.5rem; }
            .book-cover h1 { font-size: 1.3rem; }
        }
    </style>
</head>
<body oncontextmenu="return false;">
<div class="reading-progress" id="readingProgress"></div>

<?php include 'header_nav.php'; ?>

<!-- Top Navigation Bar -->
<div class="book-nav">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <a href="<?= $back_url ?>" class="btn btn-sm btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left me-1"></i><?= $back_label ?>
                </a>
                <a href="dissertation_guidelines.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-grid me-1"></i>Phase Overview
                </a>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-sm btn-outline-secondary" onclick="changeFontSize(-1)" title="Decrease font">
                    <i class="bi bi-type" style="font-size:0.7rem;"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="changeFontSize(1)" title="Increase font">
                    <i class="bi bi-type" style="font-size:1.1rem;"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="toggleToc" title="Toggle contents">
                    <i class="bi bi-list"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid py-4">
    <div class="row">
        <!-- TOC Sidebar -->
        <div class="col-lg-3 d-none d-lg-block" id="tocCol">
            <div class="toc-sidebar">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-2">
                        <h6 class="mb-0 fw-bold" style="font-family: sans-serif;">
                            <i class="bi bi-book me-1 text-primary"></i>Contents
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        <?php foreach ($toc as $t): ?>
                        <a href="#<?= $t['id'] ?>" class="toc-item">
                            <?= htmlspecialchars($t['title']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Book Content -->
        <div class="col-lg-9" id="bookCol">
            <div class="book-container">
                <!-- Cover Page -->
                <div class="book-cover">
                    <div class="university-logo">
                        <i class="bi bi-mortarboard-fill"></i>
                    </div>
                    <h1>Dissertation Module Guideline</h1>
                    <p class="subtitle">For Exploits University</p>
                    <p class="subtitle" style="font-size:0.95rem; opacity:0.75;">
                        A comprehensive guide for the preparation, formatting, and submission of dissertations
                    </p>
                    <span class="edition">Official Edition</span>
                </div>

                <!-- Book Body -->
                <div class="book-body book-content" id="bookBody">
                    <?php
                    $chapter_counter = 0;
                    $in_list = false;
                    $prev_list_level = -1;

                    foreach ($content_items as $idx => $item):
                        $text = htmlspecialchars($item['text']);
                        $type = $item['type'];
                        $image = $item['image'] ?? null;
                        $is_list = $item['is_list'] ?? false;
                        $list_level = $item['list_level'] ?? 0;

                        // Close list if we were in one and now we're not
                        if ($in_list && !$is_list && !str_starts_with($type, 'li')) {
                            echo "</ul>\n";
                            $in_list = false;
                            $prev_list_level = -1;
                        }

                        // Check if heading text contains "Example" or "Tip"
                        $is_example = (stripos($item['text'], 'example') !== false && in_array($type, ['h3', 'h4']));
                        $is_tip = (stripos($item['text'], 'tip') !== false && in_array($type, ['h3', 'h4']));

                        switch ($type):
                            case 'title':
                            case 'h1':
                                $chapter_counter++;
                                echo "<h2 class='ch-h1' id='section-{$chapter_counter}'>{$text}</h2>\n";
                                break;

                            case 'h2':
                                echo "<h3 class='ch-h2'>{$text}</h3>\n";
                                break;

                            case 'h3':
                                if ($is_example) {
                                    echo "<h4 class='ch-h3'><i class='bi bi-lightbulb me-1 text-primary'></i>{$text}</h4>\n";
                                } elseif ($is_tip) {
                                    echo "<h4 class='ch-h3'><i class='bi bi-check-circle me-1 text-success'></i>{$text}</h4>\n";
                                } else {
                                    echo "<h4 class='ch-h3'>{$text}</h4>\n";
                                }
                                break;

                            case 'h4':
                                echo "<h5 class='ch-h4'>{$text}</h5>\n";
                                break;

                            case 'toc':
                                // Skip TOC entries — we have our own sidebar TOC
                                break;

                            case 'li0':
                            case 'li1':
                            case 'li2':
                            case 'li3':
                                if (!$in_list) {
                                    echo "<ul class='list-group-{$list_level}' style='list-style-type:" . ($list_level === 0 ? 'disc' : ($list_level === 1 ? 'circle' : 'square')) . ";padding-left:1.2rem;margin-left:0;'>\n";
                                    $in_list = true;
                                } elseif ($list_level > $prev_list_level) {
                                    echo "<ul class='list-group-{$list_level}' style='list-style-type:" . ($list_level === 0 ? 'disc' : ($list_level === 1 ? 'circle' : 'square')) . ";padding-left:1.2rem;margin-left:0;'>\n";
                                } elseif ($list_level < $prev_list_level) {
                                    for ($l = $prev_list_level; $l > $list_level; $l--) {
                                        echo "</ul>\n";
                                    }
                                }
                                echo "<li class='ch-li'>{$text}</li>\n";
                                $prev_list_level = $list_level;
                                break;

                            default: // paragraph
                                if (!empty($text)) {
                                    echo "<p class='ch-p'>{$text}</p>\n";
                                }
                                break;
                        endswitch;

                        // Render image if present
                        if ($image):
                            $img_path = "../assets/images/guideline_book/" . htmlspecialchars($image);
                            ?>
                            <figure class="text-center my-3">
                                <img src="<?= $img_path ?>" class="ch-img" alt="Guideline illustration" 
                                     draggable="false" oncontextmenu="return false;"
                                     style="max-width:100%; height:auto;">
                            </figure>
                            <?php
                        endif;

                    endforeach;

                    // Close any remaining list
                    if ($in_list) echo "</ul>\n";
                    ?>
                </div>

                <!-- Book Footer -->
                <div class="text-center py-4" style="background:#f8fafc; border-top:1px solid #e2e8f0;">
                    <small class="text-muted">
                        <i class="bi bi-mortarboard me-1"></i>
                        Exploits University &mdash; Dissertation Module Guideline
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Reading progress bar
window.addEventListener('scroll', function() {
    const h = document.documentElement;
    const pct = (h.scrollTop / (h.scrollHeight - h.clientHeight)) * 100;
    document.getElementById('readingProgress').style.width = pct + '%';
});

// Active TOC highlighting
document.addEventListener('scroll', function() {
    const sections = document.querySelectorAll('.ch-h1');
    const links = document.querySelectorAll('.toc-item');
    let current = '';
    sections.forEach(s => {
        if (s.getBoundingClientRect().top <= 150) current = s.id;
    });
    links.forEach(l => {
        l.classList.remove('active');
        if (current && l.getAttribute('href') === '#' + current) l.classList.add('active');
    });
});

// Font size controls
let currentSize = 1.05;
function changeFontSize(delta) {
    currentSize = Math.max(0.8, Math.min(1.5, currentSize + delta * 0.1));
    document.getElementById('bookBody').style.fontSize = currentSize + 'rem';
}

// TOC toggle
document.getElementById('toggleToc').addEventListener('click', function() {
    const col = document.getElementById('tocCol');
    const book = document.getElementById('bookCol');
    if (col.classList.contains('d-lg-block')) {
        col.classList.remove('d-lg-block');
        col.classList.add('d-none');
        book.classList.remove('col-lg-9');
        book.classList.add('col-lg-12');
    } else {
        col.classList.add('d-lg-block');
        col.classList.remove('d-none');
        book.classList.add('col-lg-9');
        book.classList.remove('col-lg-12');
    }
});

// Prevent keyboard shortcuts for copying/saving
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'p')) {
        e.preventDefault();
        return false;
    }
});
</script>
</body>
</html>
