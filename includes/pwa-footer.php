<?php
/**
 * PWA Service Worker Registration + Install Prompt
 * Include this just before </body> on any page.
 * Usage: <?php include_once __DIR__ . '/../includes/pwa-footer.php'; ?> (from subfolder)
 *        <?php include_once __DIR__ . '/includes/pwa-footer.php'; ?>   (from root)
 */

// Determine relative path to root
$_pwaf_script = $_SERVER['PHP_SELF'] ?? '';
$_pwaf_depth = substr_count(trim(dirname($_pwaf_script), '/'), '/');
$_pwaf_root = $_pwaf_depth > 0 ? str_repeat('../', $_pwaf_depth) : './';
?>
<!-- PWA Install Banner -->
<div id="pwaInstallBanner" style="display:none; position:fixed; bottom:0; left:0; right:0; z-index:99999;
    background:linear-gradient(135deg, #0d1b4a 0%, #1b3a7b 100%); color:#fff; padding:16px 20px;
    box-shadow:0 -4px 20px rgba(0,0,0,.25); font-family:'Inter',sans-serif;">
    <div style="max-width:600px; margin:0 auto; display:flex; align-items:center; gap:14px;">
        <img src="<?= $_pwaf_root ?>assets/icons/icon-96.png" alt="VLE" 
             style="width:48px; height:48px; border-radius:12px; flex-shrink:0;">
        <div style="flex:1; min-width:0;">
            <div style="font-weight:700; font-size:1rem; margin-bottom:2px;">Install EUMW VLE</div>
            <div style="font-size:.82rem; opacity:.85;">Add to your home screen for quick access</div>
        </div>
        <div style="display:flex; gap:8px; flex-shrink:0;">
            <button id="pwaInstallBtn" onclick="pwaInstallAccept()" 
                style="background:#e8a317; color:#0d1b4a; border:none; padding:8px 18px; border-radius:8px;
                       font-weight:700; font-size:.88rem; cursor:pointer; white-space:nowrap;">
                Install
            </button>
            <button onclick="pwaInstallDismiss()" 
                style="background:rgba(255,255,255,.15); color:#fff; border:none; padding:8px 12px;
                       border-radius:8px; font-size:.88rem; cursor:pointer;">
                <span style="font-size:1.1rem;">&times;</span>
            </button>
        </div>
    </div>
</div>

<!-- iOS Install Hint (shown for Safari on iOS) -->
<div id="pwaIOSHint" style="display:none; position:fixed; bottom:0; left:0; right:0; z-index:99999;
    background:linear-gradient(135deg, #0d1b4a 0%, #1b3a7b 100%); color:#fff; padding:18px 20px;
    box-shadow:0 -4px 20px rgba(0,0,0,.25); font-family:'Inter',sans-serif;">
    <div style="max-width:600px; margin:0 auto; display:flex; align-items:center; gap:14px;">
        <img src="<?= $_pwaf_root ?>assets/icons/icon-96.png" alt="VLE" 
             style="width:48px; height:48px; border-radius:12px; flex-shrink:0;">
        <div style="flex:1; min-width:0;">
            <div style="font-weight:700; font-size:1rem; margin-bottom:4px;">Install EUMW VLE</div>
            <div style="font-size:.82rem; opacity:.85;">
                Tap <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle; margin:0 2px;">
                    <path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/>
                    <line x1="12" y1="2" x2="12" y2="15"/></svg> 
                then <b>&ldquo;Add to Home Screen&rdquo;</b>
            </div>
        </div>
        <button onclick="pwaIOSDismiss()" 
            style="background:rgba(255,255,255,.15); color:#fff; border:none; padding:8px 12px;
                   border-radius:8px; cursor:pointer;">
            <span style="font-size:1.1rem;">&times;</span>
        </button>
    </div>
</div>

<script>
// ── Service Worker Registration ──
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        // Register from root-relative path to ensure correct scope
        var swPath = '<?= $_pwaf_root ?>service-worker.js';
        navigator.serviceWorker.register(swPath)
            .then(function(reg) {
                console.log('SW registered, scope:', reg.scope);
                // Check for updates every 60 minutes
                setInterval(function() { reg.update(); }, 60 * 60 * 1000);
            })
            .catch(function(err) {
                console.warn('SW registration failed:', err);
            });
    });
}

// ── PWA Install Prompt ──
let pwaInstallEvent = null;

window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    pwaInstallEvent = e;
    // Show install banner if not dismissed recently
    if (!localStorage.getItem('pwa_dismissed') || 
        Date.now() - parseInt(localStorage.getItem('pwa_dismissed')) > 7 * 24 * 60 * 60 * 1000) {
        document.getElementById('pwaInstallBanner').style.display = 'block';
    }
});

function pwaInstallAccept() {
    if (pwaInstallEvent) {
        pwaInstallEvent.prompt();
        pwaInstallEvent.userChoice.then(function(result) {
            if (result.outcome === 'accepted') {
                console.log('PWA installed');
            }
            pwaInstallEvent = null;
            document.getElementById('pwaInstallBanner').style.display = 'none';
        });
    }
}

function pwaInstallDismiss() {
    document.getElementById('pwaInstallBanner').style.display = 'none';
    localStorage.setItem('pwa_dismissed', Date.now().toString());
}

// ── iOS Install Hint ──
window.addEventListener('load', function() {
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    var isInStandalone = window.navigator.standalone === true;
    
    if (isIOS && !isInStandalone) {
        if (!localStorage.getItem('pwa_ios_dismissed') || 
            Date.now() - parseInt(localStorage.getItem('pwa_ios_dismissed')) > 14 * 24 * 60 * 60 * 1000) {
            // Only show on iOS Safari (not Chrome iOS which supports beforeinstallprompt)
            if (!pwaInstallEvent) {
                setTimeout(function() {
                    document.getElementById('pwaIOSHint').style.display = 'block';
                }, 3000);
            }
        }
    }
});

function pwaIOSDismiss() {
    document.getElementById('pwaIOSHint').style.display = 'none';
    localStorage.setItem('pwa_ios_dismissed', Date.now().toString());
}

// Hide banners if already installed
window.addEventListener('appinstalled', function() {
    document.getElementById('pwaInstallBanner').style.display = 'none';
    document.getElementById('pwaIOSHint').style.display = 'none';
    console.log('PWA installed successfully');
});
</script>
