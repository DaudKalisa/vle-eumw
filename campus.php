<?php
/**
 * Campus Page — Exploits University Malawi VLE
 * All campus locations, contact details & bank information
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
    <meta name="description" content="Exploits University Malawi campuses in Blantyre, Lilongwe, Mzuzu, ODeL and Postgraduate — contact details, bank accounts and location information.">
    <meta name="keywords" content="Exploits University Campus, Blantyre, Lilongwe, Mzuzu, ODeL, Postgraduate, Malawi University">
    <title>Our Campuses — Exploits University Malawi</title>
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
            background: url('pictures/Slider-4.jpg') center/cover no-repeat;
            opacity: .12;
        }
        .page-hero .container { position: relative; z-index: 2; }
        .page-hero h1 { font-weight: 900; font-size: clamp(2rem, 4.5vw, 3rem); margin-bottom: .5rem; }
        .page-hero p { max-width: 650px; margin: 0 auto; opacity: .85; font-size: 1.1rem; }
        .page-hero .breadcrumb-nav { font-size: .85rem; margin-bottom: 1rem; }
        .page-hero .breadcrumb-nav a { color: var(--eu-accent); text-decoration: none; }
        .page-hero .breadcrumb-nav a:hover { text-decoration: underline; }
        .page-hero .breadcrumb-nav span { opacity: .7; }

        /* ─── Section Shared ───────────────────── */
        .campus-section { padding: 5rem 0; }
        .campus-section.alt-bg { background: var(--eu-light); }
        .eu-accent-line { width: 60px; height: 4px; background: var(--eu-accent); border-radius: 2px; margin: .8rem auto 1.2rem; }

        /* ─── Campus Cards ─────────────────────── */
        .campus-card {
            background: #fff;
            border-radius: var(--eu-radius);
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,.07);
            transition: var(--eu-transition);
            border: 1px solid rgba(0,0,0,.04);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .campus-card:hover { transform: translateY(-8px); box-shadow: 0 16px 48px rgba(0,0,0,.14); }
        .campus-card-img {
            position: relative;
            overflow: hidden;
            height: 220px;
        }
        .campus-card-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--eu-transition);
        }
        .campus-card:hover .campus-card-img img { transform: scale(1.06); }
        .campus-card-img .campus-badge {
            position: absolute;
            top: 16px;
            left: 16px;
            background: var(--eu-primary);
            color: #fff;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .5px;
            text-transform: uppercase;
            padding: 5px 14px;
            border-radius: 50px;
            backdrop-filter: blur(8px);
        }
        .campus-card-img .campus-badge.flagship { background: var(--eu-accent); color: var(--eu-primary); }
        .campus-card-body {
            padding: 1.8rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .campus-card-body h3 {
            font-weight: 800;
            font-size: 1.25rem;
            color: var(--eu-primary);
            margin-bottom: .3rem;
        }
        .campus-card-body .campus-subtitle {
            font-size: .88rem;
            color: var(--eu-accent);
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .campus-card-body .campus-desc {
            font-size: .9rem;
            color: var(--eu-text-muted);
            margin-bottom: 1.2rem;
            flex: 1;
        }

        /* Contact info rows */
        .campus-info { list-style: none; padding: 0; margin: 0 0 1.2rem; }
        .campus-info li {
            display: flex;
            align-items: flex-start;
            gap: .7rem;
            padding: .55rem 0;
            font-size: .88rem;
            border-bottom: 1px solid rgba(0,0,0,.04);
        }
        .campus-info li:last-child { border-bottom: none; }
        .campus-info li .info-icon {
            min-width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
            color: #fff;
            margin-top: 1px;
        }
        .campus-info li .info-label {
            font-size: .72rem;
            font-weight: 600;
            color: var(--eu-text-muted);
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .campus-info li .info-value {
            font-weight: 600;
            color: var(--eu-text);
        }
        .campus-info li a {
            color: var(--eu-secondary);
            text-decoration: none;
            font-weight: 600;
        }
        .campus-info li a:hover { color: var(--eu-accent); }

        /* Bank details box */
        .bank-box {
            background: linear-gradient(135deg, rgba(13,27,74,.03), rgba(27,58,123,.06));
            border: 1px solid rgba(13,27,74,.08);
            border-radius: 12px;
            padding: 1rem 1.2rem;
            margin-top: auto;
        }
        .bank-box .bank-title {
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--eu-text-muted);
            margin-bottom: .5rem;
            display: flex;
            align-items: center;
            gap: .4rem;
        }
        .bank-box .bank-name {
            font-weight: 800;
            color: var(--eu-primary);
            font-size: 1rem;
            margin-bottom: .2rem;
        }
        .bank-box .bank-acc {
            font-size: .92rem;
            color: var(--eu-text);
            font-family: 'Courier New', monospace;
            font-weight: 600;
            letter-spacing: 1px;
            background: rgba(232,163,23,.1);
            display: inline-block;
            padding: 3px 10px;
            border-radius: 6px;
        }

        /* Campus actions */
        .campus-actions {
            padding: 1rem 1.8rem;
            border-top: 1px solid rgba(0,0,0,.05);
            background: rgba(0,0,0,.01);
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
        }
        .campus-actions .btn-campus-call {
            background: var(--eu-primary);
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
        .campus-actions .btn-campus-call:hover { background: var(--eu-secondary); color: #fff; transform: translateY(-1px); }
        .campus-actions .btn-campus-apply {
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
        .campus-actions .btn-campus-apply:hover { background: linear-gradient(135deg, #059669, #047857); color: #fff; transform: translateY(-1px); }

        /* ─── Map / Summary section ────────────── */
        .campus-summary {
            background: linear-gradient(135deg, var(--eu-primary) 0%, var(--eu-secondary) 100%);
            color: #fff;
            padding: 4rem 0;
        }
        .campus-summary .summary-stat {
            text-align: center;
            padding: 1.5rem;
        }
        .campus-summary .summary-stat .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background: rgba(255,255,255,.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: var(--eu-accent);
        }
        .campus-summary .summary-stat h3 {
            font-weight: 800;
            font-size: 2rem;
            margin-bottom: .2rem;
        }
        .campus-summary .summary-stat p {
            opacity: .7;
            font-size: .9rem;
            margin: 0;
        }

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
            .page-hero { padding: 3.5rem 0 2.5rem; }
        }
        @media (max-width: 767px) {
            .campus-section { padding: 3rem 0; }
            .eu-navbar .nav-brand img { height: 40px; }
            .eu-navbar .nav-brand-text .uni-name { font-size: .9rem; }
            .page-hero h1 { font-size: 1.6rem; }
            .campus-card-img { height: 180px; }
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
                <a href="programmes.php" class="nav-link-custom">Programmes</a>
                <a href="campus.php" class="nav-link-custom active-link">Campus</a>
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
            <a href="programmes.php" class="d-block py-2 text-decoration-none text-dark fw-500"><i class="bi bi-book me-2"></i>Programmes</a>
            <a href="campus.php" class="d-block py-2 text-decoration-none fw-500" style="color:var(--eu-secondary);"><i class="bi bi-building me-2"></i>Campus</a>
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
                <a href="index.php">Home</a> <span class="mx-2">/</span> <span>Our Campuses</span>
            </div>
            <h1><i class="bi bi-building me-2"></i>Our Campuses</h1>
            <p>Study at any of our campuses across Malawi. Each campus offers a full learning experience with dedicated support, accessible facilities and flexible study options.</p>
        </div>
    </section>

    <!-- ════════════════════════════════════════════════════════════════
         CAMPUS CARDS
         ════════════════════════════════════════════════════════════════ -->
    <section class="campus-section">
        <div class="container">
            <div class="row g-4">

                <!-- ─── Blantyre Campus ──────────────── -->
                <div class="col-lg-6" id="blantyre">
                    <div class="campus-card">
                        <div class="campus-card-img">
                            <img src="pictures/Slider-4.jpg" alt="Blantyre Campus">
                            <span class="campus-badge flagship"><i class="bi bi-star-fill me-1"></i> Flagship</span>
                        </div>
                        <div class="campus-card-body">
                            <h3>Blantyre Campus</h3>
                            <div class="campus-subtitle"><i class="bi bi-geo-alt me-1"></i> Blantyre, Malawi</div>
                            <p class="campus-desc">Our flagship campus located in Malawi's commercial city. A vibrant hub for undergraduate and professional studies with modern learning facilities, student support services and a thriving academic community.</p>
                            <ul class="campus-info">
                                <li>
                                    <div class="info-icon" style="background:linear-gradient(135deg,#667eea,#764ba2);"><i class="bi bi-telephone"></i></div>
                                    <div>
                                        <div class="info-label">Phone</div>
                                        <div class="info-value"><a href="tel:+265883699446">+265 883 699 446</a> / <a href="tel:+265995811776">0995 811 776</a></div>
                                    </div>
                                </li>
                            </ul>
                            <div class="bank-box">
                                <div class="bank-title"><i class="bi bi-bank"></i> Bank Details</div>
                                <div class="bank-name">FDH Bank</div>
                                <div class="bank-acc">1400000214517</div>
                            </div>
                        </div>
                        <div class="campus-actions">
                            <a href="tel:+265883699446" class="btn-campus-call"><i class="bi bi-telephone"></i> Call Campus</a>
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-campus-apply"><i class="bi bi-pencil-square"></i> Apply Now</a>
                        </div>
                    </div>
                </div>

                <!-- ─── Lilongwe Campus ──────────────── -->
                <div class="col-lg-6" id="lilongwe">
                    <div class="campus-card">
                        <div class="campus-card-img">
                            <img src="pictures/Slider-1.jpg" alt="Lilongwe Campus">
                            <span class="campus-badge"><i class="bi bi-geo-alt-fill me-1"></i> Capital City</span>
                        </div>
                        <div class="campus-card-body">
                            <h3>Lilongwe Campus</h3>
                            <div class="campus-subtitle"><i class="bi bi-geo-alt me-1"></i> Lilongwe, Malawi</div>
                            <p class="campus-desc">Located in Malawi's capital city, the Lilongwe Campus provides convenient access to higher education for students in the central region. Offering a full range of undergraduate programmes with dedicated academic and administrative support.</p>
                            <ul class="campus-info">
                                <li>
                                    <div class="info-icon" style="background:linear-gradient(135deg,#11998e,#38ef7d);"><i class="bi bi-telephone"></i></div>
                                    <div>
                                        <div class="info-label">Phone</div>
                                        <div class="info-value"><a href="tel:+265998955220">0998 955 220</a></div>
                                    </div>
                                </li>
                            </ul>
                            <div class="bank-box">
                                <div class="bank-title"><i class="bi bi-bank"></i> Bank Details</div>
                                <div class="bank-name">National Bank</div>
                                <div class="bank-acc">278181</div>
                            </div>
                        </div>
                        <div class="campus-actions">
                            <a href="tel:+265998955220" class="btn-campus-call"><i class="bi bi-telephone"></i> Call Campus</a>
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-campus-apply"><i class="bi bi-pencil-square"></i> Apply Now</a>
                        </div>
                    </div>
                </div>

                <!-- ─── Mzuzu Campus ─────────────────── -->
                <div class="col-lg-6" id="mzuzu">
                    <div class="campus-card">
                        <div class="campus-card-img">
                            <img src="pictures/Slider-3.jpg" alt="Mzuzu Campus">
                            <span class="campus-badge"><i class="bi bi-compass me-1"></i> Northern Region</span>
                        </div>
                        <div class="campus-card-body">
                            <h3>Mzuzu Campus</h3>
                            <div class="campus-subtitle"><i class="bi bi-geo-alt me-1"></i> Mzuzu, Malawi</div>
                            <p class="campus-desc">Serving students in the northern region, the Mzuzu Campus brings quality higher education closer to communities in the north. Full academic programmes delivered with the same standards of excellence as our other campuses.</p>
                            <ul class="campus-info">
                                <li>
                                    <div class="info-icon" style="background:linear-gradient(135deg,#f093fb,#f5576c);"><i class="bi bi-telephone"></i></div>
                                    <div>
                                        <div class="info-label">Phone</div>
                                        <div class="info-value"><a href="tel:+265999594869">+265 999 594 869</a></div>
                                    </div>
                                </li>
                            </ul>
                            <div class="bank-box">
                                <div class="bank-title"><i class="bi bi-bank"></i> Bank Details</div>
                                <div class="bank-name">FDH Bank</div>
                                <div class="bank-acc">1400100308365</div>
                            </div>
                        </div>
                        <div class="campus-actions">
                            <a href="tel:+265999594869" class="btn-campus-call"><i class="bi bi-telephone"></i> Call Campus</a>
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-campus-apply"><i class="bi bi-pencil-square"></i> Apply Now</a>
                        </div>
                    </div>
                </div>

                <!-- ─── ODeL Campus ──────────────────── -->
                <div class="col-lg-6" id="odel">
                    <div class="campus-card">
                        <div class="campus-card-img">
                            <img src="pictures/Slider-2.png" alt="ODeL Campus">
                            <span class="campus-badge" style="background:linear-gradient(135deg,#10b981,#059669);"><i class="bi bi-wifi me-1"></i> Online</span>
                        </div>
                        <div class="campus-card-body">
                            <h3>Open Distance e-Learning Campus</h3>
                            <div class="campus-subtitle"><i class="bi bi-laptop me-1"></i> Flexible Online &amp; Distance Learning</div>
                            <p class="campus-desc">Study from anywhere in Malawi and beyond through our ODeL platform. Access lectures, assignments, examinations and academic resources online at your own pace — designed for working professionals and students who prefer flexible scheduling.</p>
                            <ul class="campus-info">
                                <li>
                                    <div class="info-icon" style="background:linear-gradient(135deg,#fa709a,#fee140);"><i class="bi bi-telephone"></i></div>
                                    <div>
                                        <div class="info-label">Phone</div>
                                        <div class="info-value"><a href="tel:+265998955220">+265 998 955 220</a></div>
                                    </div>
                                </li>
                            </ul>
                            <div class="bank-box">
                                <div class="bank-title"><i class="bi bi-bank"></i> Bank Details</div>
                                <div class="bank-name">Standard Bank</div>
                                <div class="bank-acc">9100001084809</div>
                            </div>
                        </div>
                        <div class="campus-actions">
                            <a href="tel:+265998955220" class="btn-campus-call"><i class="bi bi-telephone"></i> Call Now</a>
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-campus-apply"><i class="bi bi-pencil-square"></i> Apply Now</a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ════════════════════════════════════════════════════════════════
         POSTGRADUATE CAMPUS (Full Width)
         ════════════════════════════════════════════════════════════════ -->
    <section class="campus-section alt-bg" id="postgraduate">
        <div class="container">
            <div class="text-center mb-4">
                <h2 style="font-weight:800; font-size:1.8rem; color:var(--eu-primary);"><i class="bi bi-trophy me-2"></i>Postgraduate Campus</h2>
                <div class="eu-accent-line"></div>
            </div>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="campus-card">
                        <div class="campus-card-img" style="height:250px;">
                            <img src="pictures/Slider-5.png" alt="Postgraduate Campus">
                            <span class="campus-badge flagship"><i class="bi bi-mortarboard-fill me-1"></i> Postgraduate</span>
                        </div>
                        <div class="campus-card-body">
                            <h3>Postgraduate Campus</h3>
                            <div class="campus-subtitle"><i class="bi bi-geo-alt me-1"></i> All Campuses — Blantyre, Lilongwe, Mzuzu &amp; Online</div>
                            <p class="campus-desc">Our postgraduate programmes — MBA, Master's degrees, DBA and PhD — are offered across all campus locations and online. Postgraduate students benefit from dedicated research supervision, advanced seminars, flexible scheduling and access to all campus facilities nationwide.</p>
                            <ul class="campus-info">
                                <li>
                                    <div class="info-icon" style="background:linear-gradient(135deg,var(--eu-accent),#c88b0f);"><i class="bi bi-telephone"></i></div>
                                    <div>
                                        <div class="info-label">Phone</div>
                                        <div class="info-value"><a href="tel:+265998995220">+265 998 995 220</a> / <a href="tel:+265883699446">883 699 446</a></div>
                                    </div>
                                </li>
                            </ul>
                            <div class="bank-box">
                                <div class="bank-title"><i class="bi bi-bank"></i> Bank Details</div>
                                <div class="bank-name">Standard Bank</div>
                                <div class="bank-acc">9100001084809</div>
                            </div>
                        </div>
                        <div class="campus-actions">
                            <a href="tel:+265998995220" class="btn-campus-call"><i class="bi bi-telephone"></i> Call Now</a>
                            <a href="https://apply.exploitsonline.com" target="_blank" class="btn-campus-apply"><i class="bi bi-pencil-square"></i> Apply for Postgrad</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ════════════════════════════════════════════════════════════════
         CAMPUS SUMMARY STATS
         ════════════════════════════════════════════════════════════════ -->
    <section class="campus-summary">
        <div class="container">
            <div class="row g-4">
                <div class="col-6 col-md-3">
                    <div class="summary-stat">
                        <div class="stat-icon"><i class="bi bi-building"></i></div>
                        <h3>5</h3>
                        <p>Campus Locations</p>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="summary-stat">
                        <div class="stat-icon"><i class="bi bi-geo-alt"></i></div>
                        <h3>3</h3>
                        <p>Cities in Malawi</p>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="summary-stat">
                        <div class="stat-icon"><i class="bi bi-wifi"></i></div>
                        <h3>100%</h3>
                        <p>Online Access</p>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="summary-stat">
                        <div class="stat-icon"><i class="bi bi-people"></i></div>
                        <h3>13,500+</h3>
                        <p>Students &amp; Alumni</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="eu-cta">
        <div class="container">
            <h2>Ready to Join a Campus Near You?</h2>
            <p class="mb-4" style="max-width:550px;margin:0 auto;">Apply now to study at any Exploits University campus or join our flexible ODeL programme from anywhere.</p>
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
                    <h6>Our Campuses</h6>
                    <ul>
                        <li><a href="#blantyre">Blantyre</a></li>
                        <li><a href="#lilongwe">Lilongwe</a></li>
                        <li><a href="#mzuzu">Mzuzu</a></li>
                        <li><a href="#odel">ODeL Online</a></li>
                        <li><a href="#postgraduate">Postgraduate</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-md-6">
                    <h6>Quick Links</h6>
                    <ul>
                        <li><a href="login.php">VLE Login</a></li>
                        <li><a href="https://apply.exploitsonline.com" target="_blank">Apply Now</a></li>
                        <li><a href="programmes.php">Programmes</a></li>
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
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(a => {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                const t = document.querySelector(this.getAttribute('href'));
                if (t) {
                    const offset = 80;
                    const top = t.getBoundingClientRect().top + window.pageYOffset - offset;
                    window.scrollTo({ top, behavior: 'smooth' });
                }
            });
        });
    </script>
</body>
</html>
