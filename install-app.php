<?php
/**
 * PWA Install Instructions
 * Guides users through installing the VLE as a mobile/desktop app
 */
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install EUMW VLE App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include_once __DIR__ . '/includes/pwa-head.php'; ?>
    <style>
        :root {
            --eu-primary: #0d1b4a;
            --eu-secondary: #1b3a7b;
            --eu-accent: #e8a317;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f0f4ff 0%, #e8ecf4 100%);
            min-height: 100vh;
            margin: 0;
        }
        .install-hero {
            background: linear-gradient(135deg, var(--eu-primary) 0%, var(--eu-secondary) 100%);
            color: #fff;
            padding: 60px 20px 50px;
            text-align: center;
        }
        .install-hero img {
            width: 80px; height: 80px; border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,.3);
            margin-bottom: 20px;
        }
        .install-hero h1 {
            font-size: 1.8rem; font-weight: 800; margin-bottom: 10px;
        }
        .install-hero p {
            font-size: 1rem; opacity: .85; max-width: 500px; margin: 0 auto;
        }
        .install-section {
            max-width: 720px; margin: -30px auto 40px; padding: 0 16px;
        }
        .platform-card {
            background: #fff; border-radius: 16px; padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,.08);
            margin-bottom: 24px;
        }
        .platform-card h3 {
            font-size: 1.25rem; font-weight: 700; color: var(--eu-primary);
            margin-bottom: 6px; display: flex; align-items: center; gap: 10px;
        }
        .platform-card .subtitle {
            font-size: .85rem; color: #64748b; margin-bottom: 20px;
        }
        .step {
            display: flex; gap: 16px; margin-bottom: 18px;
            align-items: flex-start;
        }
        .step-num {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, var(--eu-primary), var(--eu-secondary));
            color: #fff; font-weight: 700; font-size: .95rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .step-content h5 {
            font-size: 1rem; font-weight: 600; color: #1e293b; margin-bottom: 4px;
        }
        .step-content p {
            font-size: .88rem; color: #475569; margin-bottom: 0; line-height: 1.5;
        }
        .step-content code {
            background: #f1f5f9; padding: 2px 8px; border-radius: 6px;
            font-size: .82rem; color: var(--eu-primary);
        }
        .feature-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px; margin-top: 20px;
        }
        .feature-item {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px; background: #f8fafc; border-radius: 10px;
        }
        .feature-item i {
            font-size: 1.3rem; color: var(--eu-accent);
        }
        .feature-item span {
            font-size: .88rem; font-weight: 500; color: #334155;
        }
        .btn-install-now {
            background: linear-gradient(135deg, var(--eu-accent), #c88b0f);
            color: var(--eu-primary); border: none; padding: 14px 32px;
            border-radius: 12px; font-weight: 700; font-size: 1rem;
            cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
            transition: all .3s;
        }
        .btn-install-now:hover {
            transform: translateY(-2px); box-shadow: 0 8px 20px rgba(232,163,23,.35);
            color: var(--eu-primary);
        }
        .btn-back {
            color: rgba(255,255,255,.8); text-decoration: none;
            font-size: .9rem; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-back:hover { color: #fff; }
        .detect-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,.15); padding: 6px 14px;
            border-radius: 20px; font-size: .82rem; margin-top: 16px;
        }
        @media (max-width: 576px) {
            .install-hero { padding: 40px 16px 40px; }
            .install-hero h1 { font-size: 1.5rem; }
            .platform-card { padding: 20px; }
        }
    </style>
</head>
<body>
    <!-- Hero -->
    <div class="install-hero">
        <a href="login.php" class="btn-back mb-3 d-inline-flex">
            <i class="bi bi-arrow-left"></i> Back to Login
        </a>
        <div>
            <img src="assets/icons/icon-192.png" alt="EUMW VLE">
            <h1>Install EUMW VLE</h1>
            <p>Get the full app experience on your phone, tablet, or computer — no app store required!</p>
            
            <div class="detect-badge" id="deviceDetect">
                <i class="bi bi-phone"></i>
                <span>Detecting your device...</span>
            </div>
        </div>

        <div class="mt-4">
            <button class="btn-install-now" id="heroInstallBtn" style="display:none;" onclick="pwaInstallAccept()">
                <i class="bi bi-download"></i> Install Now
            </button>
        </div>
    </div>

    <!-- Instructions -->
    <div class="install-section">

        <!-- Android Chrome -->
        <div class="platform-card" id="androidSection">
            <h3><i class="bi bi-android2" style="color:#3ddc84;"></i> Android (Chrome)</h3>
            <p class="subtitle">Works on Samsung, Xiaomi, Huawei, Tecno, Infinix, and all Android phones</p>
            
            <div class="step">
                <div class="step-num">1</div>
                <div class="step-content">
                    <h5>Open in Chrome</h5>
                    <p>Visit <code><?php echo (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']; ?></code> in Google Chrome browser</p>
                </div>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <div class="step-content">
                    <h5>Tap the Menu</h5>
                    <p>Tap the <b>three dots</b> <i class="bi bi-three-dots-vertical"></i> in the top-right corner of Chrome</p>
                </div>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <div class="step-content">
                    <h5>Select "Install app" or "Add to Home screen"</h5>
                    <p>Look for <b>"Install app"</b> or <b>"Add to Home screen"</b> in the menu and tap it</p>
                </div>
            </div>
            <div class="step">
                <div class="step-num">4</div>
                <div class="step-content">
                    <h5>Confirm Installation</h5>
                    <p>Tap <b>"Install"</b> on the confirmation dialog. The VLE icon will appear on your home screen!</p>
                </div>
            </div>
        </div>

        <!-- iOS Safari -->
        <div class="platform-card" id="iosSection">
            <h3><i class="bi bi-apple" style="color:#333;"></i> iPhone / iPad (Safari)</h3>
            <p class="subtitle">Works on all iPhones and iPads running iOS 11.3+</p>
            
            <div class="step">
                <div class="step-num">1</div>
                <div class="step-content">
                    <h5>Open in Safari</h5>
                    <p>Visit <code><?php echo (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']; ?></code> in <b>Safari</b> (not Chrome on iOS)</p>
                </div>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <div class="step-content">
                    <h5>Tap the Share Button</h5>
                    <p>Tap the <b>Share</b> button <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg> at the bottom of the screen (or top on iPad)</p>
                </div>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <div class="step-content">
                    <h5>Scroll down & tap "Add to Home Screen"</h5>
                    <p>Scroll through the share options and tap <b>"Add to Home Screen"</b> <i class="bi bi-plus-square"></i></p>
                </div>
            </div>
            <div class="step">
                <div class="step-num">4</div>
                <div class="step-content">
                    <h5>Tap "Add"</h5>
                    <p>Confirm the name and tap <b>"Add"</b>. The VLE icon will appear on your home screen!</p>
                </div>
            </div>
        </div>

        <!-- Desktop -->
        <div class="platform-card" id="desktopSection">
            <h3><i class="bi bi-laptop" style="color:#1b3a7b;"></i> Desktop (Chrome / Edge)</h3>
            <p class="subtitle">Works on Windows, macOS, Linux, and Chromebooks</p>
            
            <div class="step">
                <div class="step-num">1</div>
                <div class="step-content">
                    <h5>Open in Chrome or Edge</h5>
                    <p>Visit the VLE site in Google Chrome or Microsoft Edge</p>
                </div>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <div class="step-content">
                    <h5>Look for the Install Icon</h5>
                    <p>Click the <b>install icon</b> <i class="bi bi-box-arrow-in-down"></i> in the address bar (right side), or open the menu and select <b>"Install EUMW VLE"</b></p>
                </div>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <div class="step-content">
                    <h5>Click "Install"</h5>
                    <p>Confirm the installation. The VLE will open in its own window like a native app!</p>
                </div>
            </div>
        </div>

        <!-- Features -->
        <div class="platform-card">
            <h3><i class="bi bi-stars" style="color:var(--eu-accent);"></i> What You Get</h3>
            <p class="subtitle">The installed app gives you these benefits</p>
            
            <div class="feature-grid">
                <div class="feature-item">
                    <i class="bi bi-lightning-charge-fill"></i>
                    <span>Faster Loading</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-phone-fill"></i>
                    <span>Home Screen Icon</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-fullscreen"></i>
                    <span>Full Screen Mode</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-wifi-off"></i>
                    <span>Works Offline</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-arrow-repeat"></i>
                    <span>Always Up to Date</span>
                </div>
                <div class="feature-item">
                    <i class="bi bi-shield-check"></i>
                    <span>Secure & Private</span>
                </div>
            </div>
        </div>

        <!-- Back to Login -->
        <div class="text-center mt-3 mb-5">
            <a href="login.php" class="btn btn-lg px-5" 
               style="background:var(--eu-primary); color:#fff; border-radius:12px; font-weight:600;">
                <i class="bi bi-box-arrow-in-right me-2"></i> Go to Login
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Detect device and highlight relevant section
    (function() {
        var ua = navigator.userAgent;
        var badge = document.getElementById('deviceDetect');
        var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
        var isAndroid = /Android/.test(ua);
        
        if (isIOS) {
            badge.innerHTML = '<i class="bi bi-apple"></i><span>iPhone / iPad detected</span>';
            document.getElementById('iosSection').style.border = '2px solid var(--eu-accent)';
            document.getElementById('iosSection').style.order = '-1';
        } else if (isAndroid) {
            badge.innerHTML = '<i class="bi bi-android2"></i><span>Android device detected</span>';
            document.getElementById('androidSection').style.border = '2px solid var(--eu-accent)';
        } else {
            badge.innerHTML = '<i class="bi bi-laptop"></i><span>Desktop browser detected</span>';
            document.getElementById('desktopSection').style.border = '2px solid var(--eu-accent)';
        }
    })();

    // Show native install button if available
    let pwaInstallEvent = null;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        pwaInstallEvent = e;
        document.getElementById('heroInstallBtn').style.display = 'inline-flex';
    });

    function pwaInstallAccept() {
        if (pwaInstallEvent) {
            pwaInstallEvent.prompt();
            pwaInstallEvent.userChoice.then(function(r) {
                if (r.outcome === 'accepted') {
                    document.getElementById('heroInstallBtn').innerHTML = 
                        '<i class="bi bi-check-circle"></i> Installed!';
                }
                pwaInstallEvent = null;
            });
        }
    }
    </script>
    
    <?php include_once __DIR__ . '/includes/pwa-footer.php'; ?>
</body>
</html>
