<?php
/**
 * Theme Settings Page
 * Allows users to select their preferred color theme
 */

require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();
$current_theme = $_SESSION['vle_theme'] ?? 'navy';

// Handle theme change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    $theme = $_POST['theme'];
    $available_themes = ['navy', 'emerald', 'purple', 'orange'];
    
    if (in_array($theme, $available_themes)) {
        $_SESSION['vle_theme'] = $theme;
        
        // Save to database
        $conn = getDbConnection();
        $result = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_preference'");
        if ($result->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE users SET theme_preference = ? WHERE user_id = ?");
            $stmt->bind_param("si", $theme, $_SESSION['vle_user_id']);
            $stmt->execute();
        }
                
        $current_theme = $theme;
        $success = "Theme updated successfully!";
    }
}

// Determine back URL based on user role
$back_url = 'index.php';
switch ($user['role']) {
    case 'student':
        $back_url = 'student/dashboard.php';
        break;
    case 'lecturer':
        $back_url = 'lecturer/dashboard.php';
        break;
    case 'finance':
        $back_url = 'finance/dashboard.php';
        break;
    case 'staff':
    case 'admin':
        $back_url = 'admin/dashboard.php';
        break;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($current_theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme Settings - VLE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/global-theme.css" rel="stylesheet">
    <style>
        .theme-card {
            border: 3px solid transparent;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            overflow: hidden;
            height: 100%;
        }
        .theme-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        .theme-card.active {
            border-color: var(--vle-success);
        }
        .theme-card.active::after {
            content: '\F26E';
            font-family: 'bootstrap-icons';
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--vle-success);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        .theme-preview {
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .theme-preview-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .theme-navy .theme-preview {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        }
        .theme-emerald .theme-preview {
            background: linear-gradient(135deg, #047857 0%, #059669 100%);
        }
        .theme-purple .theme-preview {
            background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%);
        }
        .theme-orange .theme-preview {
            background: linear-gradient(135deg, #c2410c 0%, #ea580c 100%);
        }
        .theme-info {
            padding: 20px;
            background: var(--vle-card-bg);
        }
        .theme-name {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        .theme-desc {
            color: var(--vle-text-muted);
            font-size: 0.9rem;
        }
        .page-header {
            background: var(--vle-gradient-primary);
            color: white;
            padding: 40px 0;
            margin-bottom: 40px;
        }
        .page-header h1 {
            font-weight: 700;
            margin-bottom: 10px;
        }
        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h1><i class="bi bi-palette me-2"></i>Theme Settings</h1>
                    <p>Personalize your VLE experience with your preferred color theme</p>
                </div>
                <a href="<?php echo $back_url; ?>" class="btn btn-light">
                    <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Navy Blue Theme -->
            <div class="col-md-6 col-lg-3">
                <form method="POST" class="h-100">
                    <input type="hidden" name="theme" value="navy">
                    <button type="submit" class="theme-card card position-relative w-100 border-0 text-start theme-navy <?php echo $current_theme === 'navy' ? 'active' : ''; ?>">
                        <div class="theme-preview">
                            <div class="theme-preview-circle" style="background: linear-gradient(135deg, #1e3c72, #2a5298);"></div>
                        </div>
                        <div class="theme-info">
                            <div class="theme-name">Navy Blue</div>
                            <div class="theme-desc">Default professional theme</div>
                        </div>
                    </button>
                </form>
            </div>

            <!-- Emerald Green Theme -->
            <div class="col-md-6 col-lg-3">
                <form method="POST" class="h-100">
                    <input type="hidden" name="theme" value="emerald">
                    <button type="submit" class="theme-card card position-relative w-100 border-0 text-start theme-emerald <?php echo $current_theme === 'emerald' ? 'active' : ''; ?>">
                        <div class="theme-preview">
                            <div class="theme-preview-circle" style="background: linear-gradient(135deg, #047857, #059669);"></div>
                        </div>
                        <div class="theme-info">
                            <div class="theme-name">Emerald Green</div>
                            <div class="theme-desc">Fresh and natural feel</div>
                        </div>
                    </button>
                </form>
            </div>

            <!-- Royal Purple Theme -->
            <div class="col-md-6 col-lg-3">
                <form method="POST" class="h-100">
                    <input type="hidden" name="theme" value="purple">
                    <button type="submit" class="theme-card card position-relative w-100 border-0 text-start theme-purple <?php echo $current_theme === 'purple' ? 'active' : ''; ?>">
                        <div class="theme-preview">
                            <div class="theme-preview-circle" style="background: linear-gradient(135deg, #5b21b6, #7c3aed);"></div>
                        </div>
                        <div class="theme-info">
                            <div class="theme-name">Royal Purple</div>
                            <div class="theme-desc">Elegant and creative</div>
                        </div>
                    </button>
                </form>
            </div>

            <!-- Sunset Orange Theme -->
            <div class="col-md-6 col-lg-3">
                <form method="POST" class="h-100">
                    <input type="hidden" name="theme" value="orange">
                    <button type="submit" class="theme-card card position-relative w-100 border-0 text-start theme-orange <?php echo $current_theme === 'orange' ? 'active' : ''; ?>">
                        <div class="theme-preview">
                            <div class="theme-preview-circle" style="background: linear-gradient(135deg, #c2410c, #ea580c);"></div>
                        </div>
                        <div class="theme-info">
                            <div class="theme-name">Sunset Orange</div>
                            <div class="theme-desc">Warm and energetic</div>
                        </div>
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-5">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-info-circle me-2"></i>About Themes</h5>
                <p class="card-text text-muted mb-0">
                    Your selected theme will be applied across all pages in the VLE system. 
                    The theme preference is saved to your account and will be remembered when you log in from any device.
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update localStorage when theme is changed
        document.querySelectorAll('.theme-card').forEach(card => {
            card.addEventListener('click', function() {
                const theme = this.closest('form').querySelector('input[name="theme"]').value;
                localStorage.setItem('vle-theme', theme);
            });
        });
    </script>
</body>
</html>
