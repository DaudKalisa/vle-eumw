<?php
/**
 * Programmes Page — Exploits University Malawi VLE
 * Full programme catalogue: Undergraduate, Diploma, Postgraduate, Doctoral, Professional & Coming Soon
 */
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Explore all academic programmes offered by Exploits University Malawi — Undergraduate, Postgraduate, Doctoral, Professional Courses (ABMA, ABE, ICAM) and Coming Soon programmes.">
    <meta name="keywords" content="Exploits University Programmes, BBA, MBA, DBA, PhD, ABMA, ABE, ICAM, Community Development, Logistics, Supply Chain, IT, Human Resources, Malawi">
    <title>Programmes — Exploits University Malawi</title>
    <link rel="icon" type="image/png" href="pictures/Logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --eu-primary: #0d1b4a;
            --eu-secondary: #1b3a7b;
            --eu-accent: #e8a317;
            --eu-accent-hover: #c88b0f;
            --eu-light: #f0f4ff;
            --eu-white: #ffffff;
            --eu-text: #1f2937;
            --eu-text-muted: #6b7280;
            --eu-radius: 16px;
            --eu-shadow: 0 8px 32px rgba(0,0,0,.12);
            --eu-transition: all .35s cubic-bezier(.4,0,.2,1);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; overflow-x: hidden; color: var(--eu-text); }

        /* ─── Top Bar ──────────────────────────── */
        .eu-topbar { background: var(--eu-primary); color: rgba(255,255,255,.8); font-size: .8rem; padding: 6px 0; }
        .eu-topbar a { color: var(--eu-accent); text-decoration: none; }
        .eu-topbar a:hover { text-decoration: underline; }

        /* ─── Navbar ───────────────────────────── */
        .eu-navbar { background: rgba(255,255,255,.97); backdrop-filter: blur(12px); box-shadow: 0 2px 20px rgba(0,0,0,.06); padding: .6rem 0; position: sticky; top: 0; z-index: 1050; transition: var(--eu-transition); }
        .eu-navbar.scrolled { box-shadow: 0 4px 24px rgba(0,0,0,.12); }
        .eu-navbar .nav-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .eu-navbar .nav-brand img { height: 50px; width: auto; }
        .eu-navbar .nav-brand-text { line-height: 1.2; }
        .eu-navbar .nav-brand-text .uni-name { font-weight: 800; font-size: 1.05rem; color: var(--eu-primary); }
        .eu-navbar .nav-brand-text .vle-label { font-size: .72rem; color: var(--eu-accent); font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; }
        .eu-navbar .nav-link-custom { font-weight: 500; color: var(--eu-text); padding: .5rem 1rem !important; border-radius: 8px; transition: var(--eu-transition); }
        .eu-navbar .nav-link-custom:hover { background: var(--eu-light); color: var(--eu-secondary); }
        .eu-navbar .nav-link-custom.active-link { background: var(--eu-light); color: var(--eu-secondary); font-weight: 700; }
        .eu-navbar .btn-login { background: var(--eu-primary); color: #fff; border: none; padding: .55rem 1.8rem; border-radius: 50px; font-weight: 600; transition: var(--eu-transition); }
        .eu-navbar .btn-login:hover { background: var(--eu-secondary); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(13,27,74,.3); }

        /* ─── Page Hero ────────────────────────── */
        .page-hero {
            background: linear-gradient(135deg, var(--eu-primary) 0%, var(--eu-secondary) 60%, #234ea1 100%);
            color: #fff;
            padding: 4.5rem 0 3.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .page-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url('pictures/Slider-3.jpg') center/cover no-repeat;
            opacity: .12;
        }
        .page-hero .container { position: relative; z-index: 2; }
        .page-hero h1 { font-weight: 900; font-size: clamp(2rem, 4.5vw, 3rem); margin-bottom: .5rem; }
        .page-hero p { max-width: 650px; margin: 0 auto; opacity: .85; font-size: 1.1rem; }
        .page-hero .breadcrumb-nav { font-size: .85rem; margin-bottom: 1rem; }
        .page-hero .breadcrumb-nav a { color: var(--eu-accent); text-decoration: none; }
        .page-hero .breadcrumb-nav a:hover { text-decoration: underline; }
        .page-hero .breadcrumb-nav span { opacity: .7; }

        /* ─── Quick Jump Tabs ──────────────────── */
        .prog-tabs {
            background: #fff;
            border-bottom: 1px solid rgba(0,0,0,.06);
            position: sticky;
            top: 62px;
            z-index: 1040;
            box-shadow: 0 2px 10px rgba(0,0,0,.04);
        }
        .prog-tabs .nav-link {
            font-weight: 600;
            color: var(--eu-text-muted);
            padding: .85rem 1.4rem;
            border: none;
            border-bottom: 3px solid transparent;
            border-radius: 0;
            transition: var(--eu-transition);
            white-space: nowrap;
        }
        .prog-tabs .nav-link:hover { color: var(--eu-secondary); background: var(--eu-light); }
        .prog-tabs .nav-link.active { color: var(--eu-primary); border-bottom-color: var(--eu-accent); background: transparent; }

        /* ─── Section Shared ───────────────────── */
        .prog-section { padding: 4.5rem 0; }
        .prog-section:nth-child(even) { background: var(--eu-light); }
        .prog-section-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--eu-primary);
            margin-bottom: .5rem;
        }
        .eu-accent-line { width: 60px; height: 4px; background: var(--eu-accent); border-radius: 2px; margin: .8rem auto 1.2rem; }
        .eu-accent-line-left { margin: .8rem 0 1.2rem; }
        .prog-section-sub { color: var(--eu-text-muted); max-width: 700px; margin: 0 auto 3rem; }

        /* ─── Programme Cards ──────────────────── */
        .prog-card {
            background: #fff;
            border-radius: var(--eu-radius);
            padding: 0;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,.06);
            transition: var(--eu-transition);
            border: 1px solid rgba(0,0,0,.04);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .prog-card:hover { transform: translateY(-6px); box-shadow: 0 12px 40px rgba(0,0,0,.12); }
        .prog-card-header {
            padding: 1.5rem 1.5rem 1rem;
            position: relative;
        }
        .prog-card-header .prog-icon {
            width: 56px; height: 56px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; color: #fff;
            margin-bottom: 1rem;
        }
        .prog-card-header .prog-level {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 50px;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .5px;
            text-transform: uppercase;
            margin-bottom: .5rem;
        }
        .prog-card-header h5 { font-weight: 700; color: var(--eu-primary); margin-bottom: .3rem; font-size: 1.05rem; }
        .prog-card-header .prog-abbr { font-size: .8rem; color: var(--eu-accent); font-weight: 600; }
        .prog-card-body {
            padding: 0 1.5rem 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .prog-card-body p { font-size: .88rem; color: var(--eu-text-muted); flex: 1; }
        .prog-card-body .prog-details { list-style: none; padding: 0; margin: 0 0 1rem; }
        .prog-card-body .prog-details li {
            font-size: .82rem;
            color: var(--eu-text);
            padding: .3rem 0;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .prog-card-body .prog-details li i { color: var(--eu-accent); font-size: .75rem; }
        .prog-card-footer {
            padding: .8rem 1.5rem;
            border-top: 1px solid rgba(0,0,0,.05);
            background: rgba(0,0,0,.01);
        }
        .prog-card-footer .btn-apply-sm {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border: none;
            padding: .45rem 1.2rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: .82rem;
            transition: var(--eu-transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: .4rem;
        }
        .prog-card-footer .btn-apply-sm:hover { background: linear-gradient(135deg, #059669, #047857); color: #fff; transform: translateY(-1px); }

        /* Level badges */
        .badge-undergrad { background: rgba(102,126,234,.12); color: #667eea; }
        .badge-diploma { background: rgba(250,112,154,.12); color: #f5576c; }
        .badge-postgrad { background: rgba(17,153,142,.12); color: #11998e; }
        .badge-doctoral { background: rgba(232,163,23,.12); color: #c88b0f; }
        .badge-professional { background: rgba(99,102,241,.12); color: #6366f1; }
        .badge-coming { background: rgba(156,163,175,.15); color: #6b7280; }

        /* Icon gradients */
        .icon-undergrad { background: linear-gradient(135deg, #667eea, #764ba2); }
        .icon-diploma { background: linear-gradient(135deg, #fa709a, #f5576c); }
        .icon-postgrad { background: linear-gradient(135deg, #11998e, #38ef7d); }
        .icon-doctoral { background: linear-gradient(135deg, var(--eu-accent), #c88b0f); }
        .icon-professional { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .icon-coming { background: linear-gradient(135deg, #9ca3af, #6b7280); }

        /* ─── Professional Courses Section ─────── */
        .prof-provider {
            background: #fff;
            border-radius: var(--eu-radius);
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,.06);
            border: 1px solid rgba(0,0,0,.04);
            transition: var(--eu-transition);
            height: 100%;
        }
        .prof-provider:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,.1); }
        .prof-provider h4 { font-weight: 800; color: var(--eu-primary); font-size: 1.15rem; margin-bottom: .3rem; }
        .prof-provider .provider-full { font-size: .82rem; color: var(--eu-accent); font-weight: 600; margin-bottom: .8rem; }
        .prof-provider p { font-size: .9rem; color: var(--eu-text-muted); }
        .prof-provider .course-list { list-style: none; padding: 0; margin: 1rem 0; }
        .prof-provider .course-list li {
            padding: .45rem 0;
            font-size: .88rem;
            color: var(--eu-text);
            display: flex;
            align-items: flex-start;
            gap: .6rem;
            border-bottom: 1px solid rgba(0,0,0,.04);
        }
        .prof-provider .course-list li:last-child { border-bottom: none; }
        .prof-provider .course-list li i { color: var(--eu-accent); margin-top: 3px; font-size: .8rem; }
        .prof-provider .provider-link {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            font-size: .85rem;
            font-weight: 600;
            color: var(--eu-secondary);
            text-decoration: none;
            transition: var(--eu-transition);
        }
        .prof-provider .provider-link:hover { color: var(--eu-accent); }

        /* ─── Coming Soon ──────────────────────── */
        .coming-card {
            background: #fff;
            border: 2px dashed rgba(13,27,74,.15);
            border-radius: var(--eu-radius);
            padding: 1.8rem;
            text-align: center;
            transition: var(--eu-transition);
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        .coming-card::before {
            content: 'COMING SOON';
            position: absolute;
            top: 18px; right: -35px;
            background: var(--eu-accent);
            color: var(--eu-primary);
            font-size: .6rem;
            font-weight: 800;
            letter-spacing: 1px;
            padding: 4px 40px;
            transform: rotate(45deg);
        }
        .coming-card:hover { border-color: var(--eu-accent); transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,.08); }
        .coming-card h5 { font-weight: 700; color: var(--eu-primary); font-size: 1rem; margin-top: 1rem; }
        .coming-card p { font-size: .85rem; color: var(--eu-text-muted); margin: 0; }

        /* ─── CTA ──────────────────────────────── */
        .eu-cta {
            background: linear-gradient(135deg, var(--eu-accent) 0%, #d4940f 100%);
            color: var(--eu-primary);
            padding: 4rem 0;
            text-align: center;
        }
        .eu-cta h2 { font-weight: 800; font-size: 2rem; }
        .eu-cta .btn-cta { background: var(--eu-primary); color: #fff; padding: .9rem 2.8rem; border-radius: 50px; font-weight: 700; border: none; font-size: 1.05rem; transition: var(--eu-transition); }
        .eu-cta .btn-cta:hover { background: var(--eu-secondary); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,.2); }

        /* ─── Footer ───────────────────────────── */
        .eu-footer { background: var(--eu-primary); color: rgba(255,255,255,.7); padding: 3.5rem 0 1.5rem; }
        .eu-footer h6 { color: #fff; font-weight: 700; font-size: .85rem; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 1rem; }
        .eu-footer ul { list-style: none; padding: 0; }
        .eu-footer ul li { margin-bottom: .4rem; }
        .eu-footer ul li a { color: rgba(255,255,255,.6); text-decoration: none; font-size: .88rem; transition: .2s; }
        .eu-footer ul li a:hover { color: var(--eu-accent); padding-left: 4px; }
        .eu-footer-bottom { border-top: 1px solid rgba(255,255,255,.1); padding-top: 1.2rem; margin-top: 2rem; font-size: .8rem; }
        .eu-footer-bottom a { color: var(--eu-accent); text-decoration: none; }

        /* ─── Responsive ───────────────────────── */
        @media (max-width: 991px) {
            .prog-tabs .nav { flex-wrap: nowrap; overflow-x: auto; }
            .page-hero { padding: 3.5rem 0 2.5rem; }
        }
        @media (max-width: 767px) {
            .prog-section { padding: 3rem 0; }
            .eu-navbar .nav-brand img { height: 40px; }
            .eu-navbar .nav-brand-text .uni-name { font-size: .9rem; }
            .page-hero h1 { font-size: 1.6rem; }
        }
    </style>
</head>
<body>

    <!-- Top Bar -->
    <div class="eu-topbar d-none d-md-block">
        <div class="container d-flex justify-content-between align-items-center">
            <div><i class="bi bi-envelope me-1"></i> <a href="mailto:info@exploitsonline.com">info@exploitsonline.com</a> <span class="mx-2">|</span> <i class="bi bi-telephone me-1"></i> +265 999 000 000</div>
            <div><a href="https://exploitsmw.com" target="_blank"><i class="bi bi-globe me-1"></i> exploitsmw.com</a> <span class="mx-2">|</span> <a href="https://vle.exploitsonline.com"><i class="bi bi-mortarboard me-1"></i> VLE Portal</a></div>
        </div>
    </div>

    <!-- Navbar -->
    <nav class="eu-navbar" id="mainNav">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="index.php" class="nav-brand">
                <img src="pictures/Logo.png" alt="Exploits University Logo">
                <div class="nav-brand-text">
                    <div class="uni-name">Exploits University Malawi</div>
                    <div class="vle-label">Virtual Learning Environment</div>
                </div>
            </a>
            <div class="d-none d-lg-flex align-items-center gap-1">
                <a href="index.php" class="nav-link-custom">Home</a>
                <a href="programmes.php" class="nav-link-custom active-link">Programmes</a>
                <a href="campus.php" class="nav-link-custom">Campus</a>
                <a href="https://exploitsmw.com" target="_blank" class="nav-link-custom">Main Website</a>
                <a href="https://apply.exploitsonline.com" target="_blank" class="btn btn-login ms-2" style="background:linear-gradient(135deg,#10b981,#059669);"><i class="bi bi-pencil-square me-1"></i> Apply Now</a>
                <a href="login.php" class="btn btn-login ms-1"><i class="bi bi-box-arrow-in-right me-1"></i> Login</a>
            </div>
            <button class="btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" style="font-size:1.5rem"><i class="bi bi-list"></i></button>
        </div>
    </nav>

    <!-- Mobile Nav Offcanvas -->
    <div class="offcanvas offcanvas-end" id="mobileNav">
        <div class="offcanvas-header">
            <h5><img src="pictures/Logo.png" alt="Logo" style="height:35px" class="me-2"> EU Malawi</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <a href="index.php" class="d-block py-2 text-decoration-none text-dark fw-500"><i class="bi bi-house me-2"></i>Home</a>
            <a href="programmes.php" class="d-block py-2 text-decoration-none fw-500" style="color:var(--eu-secondary);"><i class="bi bi-book me-2"></i>Programmes</a>
            <a href="campus.php" class="d-block py-2 text-decoration-none text-dark fw-500"><i class="bi bi-building me-2"></i>Campus</a>
            <a href="https://exploitsmw.com" target="_blank" class="d-block py-2 text-decoration-none text-dark fw-500"><i class="bi bi-globe me-2"></i>Main Website</a>
            <hr>
            <a href="https://apply.exploitsonline.com" target="_blank" class="btn w-100 mb-2" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;border-radius:50px;font-weight:600;padding:.55rem 1.8rem;"><i class="bi bi-pencil-square me-1"></i> Apply Now</a>
            <a href="login.php" class="btn btn-login w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Login to VLE</a>
        </div>
    </div>

    <!-- Page Hero -->
    <section class="page-hero">
        <div class="container">
            <div class="breadcrumb-nav">
                <a href="index.php">Home</a> <span class="mx-2">/</span> <span>Programmes</span>
            </div>
            <h1><i class="bi bi-mortarboard me-2"></i>Our Programmes</h1>
            <p>Explore NCHE-accredited Undergraduate, Postgraduate, Doctoral and Professional programmes designed for working professionals and school leavers through Open, Distance and e-Learning.</p>
        </div>
    </section>

    <!-- Quick Jump Tabs -->
    <div class="prog-tabs">
        <div class="container">
            <ul class="nav justify-content-center" id="progNav">
                <li class="nav-item"><a class="nav-link active" href="#undergraduate" data-section="undergraduate">Undergraduate</a></li>
                <li class="nav-item"><a class="nav-link" href="#diploma" data-section="diploma">Diploma</a></li>
                <li class="nav-item"><a class="nav-link" href="#postgraduate" data-section="postgraduate">Postgraduate</a></li>
                <li class="nav-item"><a class="nav-link" href="#doctoral" data-section="doctoral">Doctoral</a></li>
                <li class="nav-item"><a class="nav-link" href="#professional" data-section="professional">Professional</a></li>
                <li class="nav-item"><a class="nav-link" href="#coming-soon" data-section="coming-soon">Coming Soon</a></li>
            </ul>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         UNDERGRADUATE PROGRAMMES
         ════════════════════════════════════════════════════════════════ -->
    <section class="prog-section" id="undergraduate">
        <div class="container">
            <div class="text-center">
                <h2 class="prog-section-title"><i class="bi bi-journal-bookmark me-2"></i>Undergraduate Programmes</h2>
                <div class="eu-accent-line"></div>
                <p class="prog-section-sub">Our Bachelor's degree programmes are NCHE-accredited and delivered through flexible Open, Distance and e-Learning, allowing you to study while you work.</p>
            </div>
            <div class="row g-4">

                <!-- BBA -->
                <div class="col-lg-4 col-md-6">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-undergrad"><i class="bi bi-briefcase"></i></div>
                            <span class="prog-level badge-undergrad">Bachelor's Degree</span>
                            <h5>Bachelor of Business Administration</h5>
                            <div class="prog-abbr">BBA</div>
                        </div>
                        <div class="prog-card-body">
                            <p>Develop comprehensive knowledge in business management, strategic planning, entrepreneurship, marketing and organisational leadership to thrive in today's competitive business environment.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 4 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Online)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

                <!-- Community Development -->
                <div class="col-lg-4 col-md-6">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-undergrad"><i class="bi bi-people"></i></div>
                            <span class="prog-level badge-undergrad">Bachelor's Degree</span>
                            <h5>Bachelor of Community Development</h5>
                            <div class="prog-abbr">BCD</div>
                        </div>
                        <div class="prog-card-body">
                            <p>Equip yourself with practical skills in community mobilisation, project management, social research, rural development and policy analysis to drive sustainable community transformation across Malawi and beyond.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 4 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Online)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

                <!-- Logistics & Supply Chain -->
                <div class="col-lg-4 col-md-6">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-undergrad"><i class="bi bi-truck"></i></div>
                            <span class="prog-level badge-undergrad">Bachelor's Degree</span>
                            <h5>Bachelor of Logistics &amp; Supply Chain Management</h5>
                            <div class="prog-abbr">BLSCM</div>
                        </div>
                        <div class="prog-card-body">
                            <p>Master the complexities of procurement, warehousing, transportation, inventory management and global supply chain operations across public and private sectors.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 4 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Online)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

                <!-- Information Technology -->
                <div class="col-lg-4 col-md-6">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-undergrad"><i class="bi bi-laptop"></i></div>
                            <span class="prog-level badge-undergrad">Bachelor's Degree</span>
                            <h5>Bachelor of Information Technology</h5>
                            <div class="prog-abbr">BIT</div>
                        </div>
                        <div class="prog-card-body">
                            <p>Gain cutting-edge skills in software development, database management, cybersecurity, networking, web technologies and IT project management for the digital economy.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 4 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Online)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

                <!-- Human Resources Management -->
                <div class="col-lg-4 col-md-6">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-undergrad"><i class="bi bi-person-badge"></i></div>
                            <span class="prog-level badge-undergrad">Bachelor's Degree</span>
                            <h5>Bachelor of Human Resources Management</h5>
                            <div class="prog-abbr">BHRM</div>
                        </div>
                        <div class="prog-card-body">
                            <p>Develop expertise in talent acquisition, employee relations, performance management, labour law, organisational behaviour and strategic HR planning in modern organisations.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 4 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Online)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

                <!-- Health Systems Management -->
                <div class="col-lg-4 col-md-6">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-undergrad"><i class="bi bi-heart-pulse"></i></div>
                            <span class="prog-level badge-undergrad">Bachelor's Degree</span>
                            <h5>Bachelor of Health Systems Management</h5>
                            <div class="prog-abbr">BHSM</div>
                        </div>
                        <div class="prog-card-body">
                            <p>Acquire essential competencies in health policy, hospital administration, public health planning, health economics, quality assurance and health information systems to lead and manage healthcare organisations effectively.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 4 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Online)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ════════════════════════════════════════════════════════════════
         DIPLOMA PROGRAMME
         ════════════════════════════════════════════════════════════════ -->
    <section class="prog-section" id="diploma">
        <div class="container">
            <div class="text-center">
                <h2 class="prog-section-title"><i class="bi bi-award me-2"></i>Diploma Programme</h2>
                <div class="eu-accent-line"></div>
                <p class="prog-section-sub">A practical, career-focused qualification that provides a strong foundation for employment or progression to a Bachelor's degree.</p>
            </div>
            <div class="row g-4 justify-content-center">

                <!-- Diploma in Business Management -->
                <div class="col-lg-5 col-md-8">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-diploma"><i class="bi bi-graph-up-arrow"></i></div>
                            <span class="prog-level badge-diploma">Diploma</span>
                            <h5>Diploma in Business Management</h5>
                            <div class="prog-abbr">DBM</div>
                        </div>
                        <div class="prog-card-body">
                            <p>Build essential business skills in accounting, marketing, management principles, economics and entrepreneurship. This programme prepares you for entry-level management roles and serves as a pathway to the Bachelor of Business Administration.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 2 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Online)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                                <li><i class="bi bi-arrow-up-circle"></i> Pathway to BBA</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ════════════════════════════════════════════════════════════════
         POSTGRADUATE PROGRAMMES
         ════════════════════════════════════════════════════════════════ -->
    <section class="prog-section" id="postgraduate">
        <div class="container">
            <div class="text-center">
                <h2 class="prog-section-title"><i class="bi bi-trophy me-2"></i>Postgraduate Programmes</h2>
                <div class="eu-accent-line"></div>
                <p class="prog-section-sub">Advance your career with our Master's degree programmes, designed for experienced professionals seeking leadership roles and specialised expertise.</p>
            </div>
            <div class="row g-4 justify-content-center">

                <!-- MBA -->
                <div class="col-lg-5 col-md-6">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-postgrad"><i class="bi bi-bar-chart-line"></i></div>
                            <span class="prog-level badge-postgrad">Master's Degree</span>
                            <h5>Master of Business Administration</h5>
                            <div class="prog-abbr">MBA</div>
                        </div>
                        <div class="prog-card-body">
                            <p>Our flagship postgraduate programme equips you with advanced knowledge in strategic management, financial analysis, leadership, global business strategy and innovation management to lead organisations at the highest levels.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 2 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Online)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                                <li><i class="bi bi-check2-circle"></i> Entry: Bachelor's Degree + Experience</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

                <!-- Master of HRM -->
                <div class="col-lg-5 col-md-6">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-postgrad"><i class="bi bi-person-gear"></i></div>
                            <span class="prog-level badge-postgrad">Master's Degree</span>
                            <h5>Master of Human Resource Management</h5>
                            <div class="prog-abbr">MHRM</div>
                        </div>
                        <div class="prog-card-body">
                            <p>Specialise in advanced HR practices including strategic workforce planning, organisational development, change management, international HRM, labour relations and HR analytics for data-driven decision-making.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 2 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Online)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                                <li><i class="bi bi-check2-circle"></i> Entry: Bachelor's Degree + Experience</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ════════════════════════════════════════════════════════════════
         DOCTORAL PROGRAMMES
         ════════════════════════════════════════════════════════════════ -->
    <section class="prog-section" id="doctoral">
        <div class="container">
            <div class="text-center">
                <h2 class="prog-section-title"><i class="bi bi-star me-2"></i>Doctoral Programmes</h2>
                <div class="eu-accent-line"></div>
                <p class="prog-section-sub">Our highest-level research-intensive programmes for scholars and senior professionals seeking to make original contributions to knowledge in business and management.</p>
            </div>
            <div class="row g-4 justify-content-center">

                <!-- DBA -->
                <div class="col-lg-5 col-md-6">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-doctoral"><i class="bi bi-lightbulb"></i></div>
                            <span class="prog-level badge-doctoral">Doctoral Degree</span>
                            <h5>Doctor of Business Administration</h5>
                            <div class="prog-abbr">DBA</div>
                        </div>
                        <div class="prog-card-body">
                            <p>A professional doctorate designed for senior managers and executives who wish to apply rigorous research methodologies to real-world business challenges, combining advanced theory with practical application in organisational settings.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 3–5 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Blended)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                                <li><i class="bi bi-check2-circle"></i> Entry: Master's Degree</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

                <!-- PhD in BA -->
                <div class="col-lg-5 col-md-6">
                    <div class="prog-card">
                        <div class="prog-card-header">
                            <div class="prog-icon icon-doctoral"><i class="bi bi-journal-richtext"></i></div>
                            <span class="prog-level badge-doctoral">Doctoral Degree</span>
                            <h5>Doctor of Philosophy in Business Administration</h5>
                            <div class="prog-abbr">PhD</div>
                        </div>
                        <div class="prog-card-body">
                            <p>An academic doctorate focused on original scholarly research, advancing theoretical frontiers in business administration, management science, finance, marketing or organisational studies. Ideal for aspiring academics, researchers and policy advisors.</p>
                            <ul class="prog-details">
                                <li><i class="bi bi-clock"></i> Duration: 3–5 Years</li>
                                <li><i class="bi bi-mortarboard"></i> Mode: ODeL (Blended)</li>
                                <li><i class="bi bi-bookmark-star"></i> NCHE Accredited</li>
                                <li><i class="bi bi-check2-circle"></i> Entry: Master's Degree</li>
                            </ul>
                        </div>
                        <div class="prog-card-footer d-flex justify-content-between align-items-center">
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-apply-sm"><i class="bi bi-pencil-square"></i> Apply Now</a>
                            <small class="text-muted">Intake Open</small>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ════════════════════════════════════════════════════════════════
         PROFESSIONAL COURSES (ABMA, ABE, ICAM)
         ════════════════════════════════════════════════════════════════ -->
    <section class="prog-section" id="professional">
        <div class="container">
            <div class="text-center">
                <h2 class="prog-section-title"><i class="bi bi-patch-check me-2"></i>Professional Courses</h2>
                <div class="eu-accent-line"></div>
                <p class="prog-section-sub">Internationally recognised professional qualifications offered in partnership with leading UK and Malawian awarding bodies. Enhance your employability with industry-standard certifications.</p>
            </div>
            <div class="row g-4">

                <!-- ABMA -->
                <div class="col-lg-4 col-md-6">
                    <div class="prof-provider">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="prog-icon icon-professional" style="width:48px;height:48px;min-width:48px;font-size:1.1rem;border-radius:12px;"><i class="bi bi-building"></i></div>
                            <div>
                                <h4>ABMA</h4>
                                <div class="provider-full">Association of Business Managers and Administrators</div>
                            </div>
                        </div>
                        <p>ABMA Education is a UK-based Ofqual-regulated awarding organisation offering globally recognised qualifications in business, computing, and management. Their programmes are designed to provide learners with practical skills aligned to industry needs.</p>
                        <ul class="course-list">
                            <li><i class="bi bi-check2"></i> <div><strong>Level 4 Diploma</strong> — Business Management, Computing &amp; Information Systems</div></li>
                            <li><i class="bi bi-check2"></i> <div><strong>Level 5 Diploma</strong> — Business Management, Human Resource Management, Computing &amp; Information Systems</div></li>
                            <li><i class="bi bi-check2"></i> <div><strong>Level 6 Diploma</strong> — Business Management, Human Resource Management, Strategic Management</div></li>
                            <li><i class="bi bi-check2"></i> <div><strong>Specialisations</strong> — Hospitality &amp; Tourism Management, Health &amp; Social Care</div></li>
                        </ul>
                        <a href="https://www.abma.uk.com" target="_blank" class="provider-link"><i class="bi bi-box-arrow-up-right"></i> Visit ABMA Official Website</a>
                    </div>
                </div>

                <!-- ABE -->
                <div class="col-lg-4 col-md-6">
                    <div class="prof-provider">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="prog-icon icon-professional" style="width:48px;height:48px;min-width:48px;font-size:1.1rem;border-radius:12px;"><i class="bi bi-briefcase"></i></div>
                            <div>
                                <h4>ABE</h4>
                                <div class="provider-full">Association of Business Executives</div>
                            </div>
                        </div>
                        <p>ABE is a UK-based professional membership body and Ofqual-regulated awarding organisation that has been developing business leaders since 1973. Their qualifications provide flexible, affordable routes into higher education and management careers worldwide.</p>
                        <ul class="course-list">
                            <li><i class="bi bi-check2"></i> <div><strong>Level 4 Diploma</strong> — Business Management, Human Resource Management, Travel, Tourism &amp; Hospitality</div></li>
                            <li><i class="bi bi-check2"></i> <div><strong>Level 5 Diploma</strong> — Business Management, Marketing Management, Human Resource Management</div></li>
                            <li><i class="bi bi-check2"></i> <div><strong>Level 6 Diploma</strong> — Business Management, Marketing Management, Entrepreneurship &amp; Innovation</div></li>
                            <li><i class="bi bi-check2"></i> <div><strong>Short Courses</strong> — Introduction to Business, Digital Marketing Fundamentals</div></li>
                        </ul>
                        <a href="https://www.abeuk.com" target="_blank" class="provider-link"><i class="bi bi-box-arrow-up-right"></i> Visit ABE Official Website</a>
                    </div>
                </div>

                <!-- ICAM -->
                <div class="col-lg-4 col-md-6">
                    <div class="prof-provider">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="prog-icon icon-professional" style="width:48px;height:48px;min-width:48px;font-size:1.1rem;border-radius:12px;"><i class="bi bi-calculator"></i></div>
                            <div>
                                <h4>ICAM</h4>
                                <div class="provider-full">Institute of Chartered Accountants in Malawi</div>
                            </div>
                        </div>
                        <p>ICAM is the national professional accountancy body of Malawi, responsible for regulating the accountancy profession. Their qualification pathway produces Chartered Accountants equipped with financial, auditing and advisory expertise to serve both public and private sectors.</p>
                        <ul class="course-list">
                            <li><i class="bi bi-check2"></i> <div><strong>Certificate Level</strong> — Financial Accounting, Business Mathematics &amp; Statistics, Economics, Commercial Law</div></li>
                            <li><i class="bi bi-check2"></i> <div><strong>Knowledge Level</strong> — Management Accounting, Taxation, Auditing Principles, Public Sector Accounting</div></li>
                            <li><i class="bi bi-check2"></i> <div><strong>Application Level</strong> — Financial Reporting, Advanced Taxation, Advanced Audit &amp; Assurance, Management Information Systems</div></li>
                            <li><i class="bi bi-check2"></i> <div><strong>Professional Level</strong> — Advanced Financial Reporting, Strategic Management &amp; Leadership, Case Study (Capstone)</div></li>
                        </ul>
                        <a href="https://www.icam.mw" target="_blank" class="provider-link"><i class="bi bi-box-arrow-up-right"></i> Visit ICAM Official Website</a>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ════════════════════════════════════════════════════════════════
         COMING SOON PROGRAMMES
         ════════════════════════════════════════════════════════════════ -->
    <section class="prog-section" id="coming-soon">
        <div class="container">
            <div class="text-center">
                <h2 class="prog-section-title"><i class="bi bi-rocket-takeoff me-2"></i>Coming Soon</h2>
                <div class="eu-accent-line"></div>
                <p class="prog-section-sub">Exciting new programmes currently in development. Register your interest to be notified when applications open.</p>
            </div>
            <div class="row g-4">

                <!-- Master of Developmental Studies -->
                <div class="col-lg-4 col-md-6">
                    <div class="coming-card">
                        <div class="prog-icon icon-coming mx-auto" style="width:56px;height:56px;border-radius:14px;"><i class="bi bi-globe-americas" style="font-size:1.4rem;"></i></div>
                        <h5>Master of Developmental Studies</h5>
                        <p class="mb-2">Majoring in all Undergraduate programme fields:</p>
                        <div class="text-start d-inline-block">
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Business Administration</small>
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Community Development</small>
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Logistics &amp; Supply Chain Management</small>
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Information Technology</small>
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Human Resources Management</small>
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Health Systems Management</small>
                        </div>
                    </div>
                </div>

                <!-- Master of International Relations and Diplomacy -->
                <div class="col-lg-4 col-md-6">
                    <div class="coming-card">
                        <div class="prog-icon icon-coming mx-auto" style="width:56px;height:56px;border-radius:14px;"><i class="bi bi-flag" style="font-size:1.4rem;"></i></div>
                        <h5>Master of International Relations &amp; Diplomacy</h5>
                        <p>Specialising in diplomatic studies, international law, foreign policy analysis, conflict resolution, global governance and multilateral negotiations in the African and international context.</p>
                    </div>
                </div>

                <!-- Doctor of Developmental Studies -->
                <div class="col-lg-4 col-md-6">
                    <div class="coming-card">
                        <div class="prog-icon icon-coming mx-auto" style="width:56px;height:56px;border-radius:14px;"><i class="bi bi-journal-medical" style="font-size:1.4rem;"></i></div>
                        <h5>Doctor of Developmental Studies</h5>
                        <p class="mb-2">Majoring in all Undergraduate programme fields:</p>
                        <div class="text-start d-inline-block">
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Business Administration</small>
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Community Development</small>
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Logistics &amp; Supply Chain Management</small>
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Information Technology</small>
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Human Resources Management</small>
                            <small class="d-block text-muted"><i class="bi bi-dot"></i> Health Systems Management</small>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Register Interest -->
            <div class="text-center mt-5">
                <div class="p-4 rounded-4" style="background:rgba(13,27,74,.04); border:1px solid rgba(13,27,74,.08); display:inline-block; max-width:600px;">
                    <i class="bi bi-bell fs-3 d-block mb-2" style="color:var(--eu-accent);"></i>
                    <h5 class="fw-bold" style="color:var(--eu-primary);">Interested in These Programmes?</h5>
                    <p class="text-muted mb-3" style="font-size:.9rem;">Contact us at <a href="mailto:info@exploitsonline.com" style="color:var(--eu-secondary);font-weight:600;">info@exploitsonline.com</a> to register your interest and be notified when applications open.</p>
                    <a href="mailto:info@exploitsonline.com?subject=Interest%20in%20Coming%20Soon%20Programmes" class="btn btn-login"><i class="bi bi-envelope me-1"></i> Register Interest</a>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="eu-cta">
        <div class="container">
            <h2>Ready to Start Your Academic Journey?</h2>
            <p class="mb-4" style="max-width:550px;margin:0 auto;">Apply now to join Exploits University Malawi and access quality NCHE-accredited programmes through our Virtual Learning Environment.</p>
            <div class="d-flex gap-3 justify-content-center flex-wrap">
                <a href="https://apply.exploitsonline.com" target="_blank" class="btn" style="background:#fff;color:#059669;padding:.9rem 2.8rem;border-radius:50px;font-weight:700;border:none;font-size:1.05rem;"><i class="bi bi-pencil-square me-2"></i>Apply Now</a>
                <a href="login.php" class="btn btn-cta"><i class="bi bi-box-arrow-in-right me-2"></i>Student Login</a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="eu-footer">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4 col-md-6">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <img src="pictures/Logo.png" alt="Logo" style="height:40px; filter:brightness(0) invert(1);">
                        <div>
                            <div class="fw-bold text-white">Exploits University Malawi</div>
                            <div style="font-size:.72rem;color:var(--eu-accent);letter-spacing:1px;">VIRTUAL LEARNING ENVIRONMENT</div>
                        </div>
                    </div>
                    <p style="font-size:.88rem;">Transforming higher education through innovative Open, Distance and e-Learning across Malawi. NCHE accredited and committed to academic excellence.</p>
                    <div class="d-flex gap-2 mt-2">
                        <a href="https://exploitsmw.com" target="_blank" class="btn btn-sm" style="background:rgba(255,255,255,.1);color:#fff;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-globe"></i></a>
                        <a href="mailto:info@exploitsonline.com" class="btn btn-sm" style="background:rgba(255,255,255,.1);color:#fff;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-envelope"></i></a>
                    </div>
                </div>
                <div class="col-lg-2 col-md-6">
                    <h6>Study</h6>
                    <ul>
                        <li><a href="#undergraduate">Undergraduate</a></li>
                        <li><a href="#postgraduate">Postgraduate</a></li>
                        <li><a href="#doctoral">Doctoral</a></li>
                        <li><a href="#professional">Professional</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-md-6">
                    <h6>Quick Links</h6>
                    <ul>
                        <li><a href="login.php">VLE Login</a></li>
                        <li><a href="https://apply.exploitsonline.com" target="_blank">Apply Now</a></li>
                        <li><a href="index.php">Home</a></li>
                        <li><a href="https://exploitsmw.com" target="_blank">Main Website</a></li>
                        <li><a href="forgot_password.php">Reset Password</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-md-6">
                    <h6>Contact Us</h6>
                    <ul>
                        <li><i class="bi bi-envelope me-2" style="color:var(--eu-accent)"></i> info@exploitsonline.com</li>
                        <li><i class="bi bi-globe me-2" style="color:var(--eu-accent)"></i> <a href="https://exploitsmw.com" target="_blank">exploitsmw.com</a></li>
                        <li><i class="bi bi-geo-alt me-2" style="color:var(--eu-accent)"></i> Lilongwe, Malawi</li>
                        <li><i class="bi bi-mortarboard me-2" style="color:var(--eu-accent)"></i> <a href="https://vle.exploitsonline.com">vle.exploitsonline.com</a></li>
                    </ul>
                </div>
            </div>
            <div class="eu-footer-bottom d-flex flex-wrap justify-content-between align-items-center">
                <div>&copy; <?php echo date('Y'); ?> Exploits University Malawi. All rights reserved.</div>
                <div>VLE v16.0.1 &bull; Powered by <a href="https://exploitsmw.com" target="_blank">Exploits University</a></div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', () => {
            document.getElementById('mainNav').classList.toggle('scrolled', window.scrollY > 50);
        });

        // Tab-section scroll spy & smooth scroll
        const sections = document.querySelectorAll('.prog-section[id]');
        const tabLinks = document.querySelectorAll('#progNav .nav-link');

        tabLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    const offset = 130; // account for sticky nav + tabs
                    const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                    window.scrollTo({ top, behavior: 'smooth' });
                }
            });
        });

        // Scroll spy: highlight active tab
        function updateActiveTab() {
            let current = '';
            sections.forEach(sec => {
                const top = sec.offsetTop - 160;
                if (window.pageYOffset >= top) current = sec.getAttribute('id');
            });
            tabLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('data-section') === current) link.classList.add('active');
            });
        }
        window.addEventListener('scroll', updateActiveTab);
        updateActiveTab();
    </script>
</body>
</html>
