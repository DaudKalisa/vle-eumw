<?php
// open_eresources.php - Open E-Resources page
require_once '../includes/auth.php';
requireLogin();
requireRole(['student']);
$resources = [
    [
        'name' => 'RESEARCH FOR LIFE',
        'url' => 'https://login.research4life.org/tacsgr1portal_research4life_org/',
        'icon' => 'https://www.research4life.org/wp-content/uploads/2019/07/cropped-r4l-favicon-192x192.png'
    ],
    [
        'name' => 'DIRECTORY OF OPEN ACCESS JOURNALS & ARTICLES',
        'url' => 'https://daj.org',
        'icon' => 'https://doaj.org/favicon.ico'
    ],
    [
        'name' => 'MALICO',
        'url' => 'https://app.myloft.xyz/user/login?institute=cl46ybmv25hzz0960vote403i',
        'icon' => 'https://app.myloft.xyz/favicon.ico'
    ],
    [
        'name' => 'CONCHRANE',
        'url' => 'https://www.cochrane.org/',
        'icon' => 'https://www.cochrane.org/sites/all/themes/cochrane/favicon.ico'
    ],
    [
        'name' => 'BIOLINE OPEN ACCESS RESEARCH ARTICLES',
        'url' => 'https://www.bioline.org.br/',
        'icon' => 'https://www.bioline.org.br/favicon.ico'
    ],
    [
        'name' => 'NATIONAL ACADEMIES PRESS',
        'url' => 'https://nap.nationalacademies.org/',
        'icon' => 'https://nap.nationalacademies.org/favicon.ico'
    ],
    [
        'name' => 'FREE MEDICAL JOURNALS',
        'url' => 'http://www.freemedicaljournals.com/',
        'icon' => 'http://www.freemedicaljournals.com/favicon.ico'
    ],
    [
        'name' => 'MEDSCAPE',
        'url' => 'https://www.medscape.com/',
        'icon' => 'https://img.medscape.com/favicon.ico'
    ],
    [
        'name' => 'OPENMD',
        'url' => 'https://openmd.com/',
        'icon' => 'https://openmd.com/favicon.ico'
    ],
    [
        'name' => 'QUICK GUIDE TO HARVARD REFERENCING',
        'url' => 'https://www.scribbr.co.uk/referencing/harvard-style/',
        'icon' => 'https://www.scribbr.com/favicon.ico'
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open E-Resources</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php include 'header_nav.php'; ?>
    <div class="container py-5">
        <div class="row justify-content-center mb-4">
            <div class="col-lg-8 text-center">
                <h3 class="mb-4">Open E-Resources</h3>
                <p class="lead">Access a wide range of academic and research resources below.</p>
            </div>
        </div>
        <div class="row row-cols-1 row-cols-md-3 g-4">
            <?php foreach ($resources as $res): ?>
                <div class="col">
                    <a href="<?php echo $res['url']; ?>" target="_blank" rel="noopener" class="btn btn-outline-primary w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 mb-2">
                        <img src="<?php echo $res['icon']; ?>" alt="<?php echo htmlspecialchars($res['name']); ?> icon" style="width:40px;height:40px;object-fit:contain;margin-bottom:10px;">
                        <span class="fw-bold text-center" style="font-size:1rem;"><?php echo htmlspecialchars($res['name']); ?></span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="row mt-4">
            <div class="col text-center">
                <a href="exploits_resources.php" class="btn btn-outline-secondary">Back to Resources</a>
            </div>
        </div>
    </div>
</body>
</html>
