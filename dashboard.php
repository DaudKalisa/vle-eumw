<?php
// dashboard.php - Main dashboard for VLE System
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();

// Check for session error messages and display them
if (!empty($_SESSION['vle_error'])) {
    $error_msg = $_SESSION['vle_error'];
    unset($_SESSION['vle_error']);
    // Show error page instead of redirecting
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Account Issue - VLE</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card shadow border-0">
                        <div class="card-body text-center p-5">
                            <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i>
                            <h3 class="mt-3 mb-3">Account Configuration Issue</h3>
                            <div class="alert alert-warning">
                                <?php echo htmlspecialchars($error_msg); ?>
                            </div>
                            <p class="text-muted">Your account role may not be properly linked to a profile record. Please contact your system administrator.</p>
                            <div class="mt-4">
                                <a href="dashboard.php" class="btn btn-primary me-2"><i class="bi bi-arrow-clockwise me-1"></i>Try Again</a>
                                <a href="logout.php" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Graduation students redirect (additional_role check before primary role switch)
if (function_exists('hasRole') && hasRole('graduation_student')) {
    header('Location: graduation_student/dashboard.php');
    exit();
}

// Exam clearance students redirect
if (function_exists('hasRole') && hasRole('exam_clearance_student')) {
    header('Location: student/exam_clearance.php');
    exit();
}

// Redirect based on role
switch ($user['role']) {
    case 'student':
        header('Location: student/dashboard.php');
        break;
    case 'lecturer':
        header('Location: lecturer/dashboard.php');
        break;
    case 'admin':
    case 'staff':
        header('Location: admin/dashboard.php');
        break;
    case 'finance':
        header('Location: finance/dashboard.php');
        break;
    case 'hod':
        header('Location: admin/dashboard.php');
        break;
    case 'dean':
        header('Location: dean/dashboard.php');
        break;
    case 'odl_coordinator':
        header('Location: odl_coordinator/dashboard.php');
        break;
    case 'examination_manager':
        header('Location: examination_manager/dashboard.php');
        break;
    case 'examination_officer':
        header('Location: examination_officer/dashboard.php');
        break;
    case 'exam_clearance_student':
        header('Location: student/exam_clearance.php');
        break;
    case 'graduation_student':
        header('Location: graduation_student/dashboard.php');
        break;
    case 'dissertation_student':
        header('Location: student/dissertation.php');
        break;
    default:
        header('Location: access_denied.php');
        break;
}
exit();
?>