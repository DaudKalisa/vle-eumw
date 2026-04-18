<?php
/**
 * Dean Portal - Profile Page
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// Get faculty info
$faculty_name = '';
if (!empty($user['related_dean_id'])) {
    $result = $conn->query("SELECT faculty_name FROM faculties WHERE faculty_id = " . (int)$user['related_dean_id']);
    if ($result && $row = $result->fetch_assoc()) {
        $faculty_name = $row['faculty_name'];
    }
}

$page_title = "My Profile";
$breadcrumbs = [['title' => 'Profile']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Dean Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php include 'header_nav.php'; ?>
    
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-person-circle me-2"></i>My Profile</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center mb-3" style="width: 100px; height: 100px; font-size: 2.5rem; font-weight: 700;">
                                <?= strtoupper(substr($user['display_name'] ?? 'D', 0, 1)) ?>
                            </div>
                            <h4><?= htmlspecialchars($user['display_name'] ?? 'Dean') ?></h4>
                            <p class="text-muted mb-0">Dean<?= $faculty_name ? ' of ' . htmlspecialchars($faculty_name) : '' ?></p>
                        </div>
                        
                        <hr>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted">Username</label>
                                <p class="mb-0"><strong><?= htmlspecialchars($user['username'] ?? 'N/A') ?></strong></p>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted">Email</label>
                                <p class="mb-0"><strong><?= htmlspecialchars($user['email'] ?? 'N/A') ?></strong></p>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted">Role</label>
                                <p class="mb-0"><span class="badge bg-success">Dean</span></p>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted">Faculty</label>
                                <p class="mb-0"><strong><?= htmlspecialchars($faculty_name ?: 'All Faculties') ?></strong></p>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="form-label text-muted"><i class="bi bi-pen me-2"></i>Digital Signature</label>
                                <small class="d-block text-muted mb-2">Upload your signature for approval documents (PNG or JPG, max 2MB)</small>
                                <div class="d-flex align-items-center gap-3">
                                    <?php
                                    $sig_path = '../uploads/signatures/dean_' . $user['user_id'] . '.png';
                                    if (file_exists($sig_path)):
                                    ?>
                                    <div>
                                        <img src="<?= htmlspecialchars($sig_path) ?>" alt="Your Signature" style="max-height: 80px; border: 1px solid #ddd; padding: 5px; border-radius: 3px;">
                                    </div>
                                    <div>
                                        <small class="text-success">✓ Signature on file</small>
                                        <button type="button" class="btn btn-sm btn-warning mt-2" data-bs-toggle="modal" data-bs-target="#updateSignatureModal">
                                            Update Signature
                                        </button>
                                    </div>
                                    <?php else: ?>
                                    <div>
                                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateSignatureModal">
                                            <i class="bi bi-upload me-2"></i>Upload Signature
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                            <a href="../change_password.php" class="btn btn-outline-primary">
                                <i class="bi bi-key me-1"></i> Change Password
                            </a>
                            <a href="../theme_settings.php" class="btn btn-outline-secondary">
                                <i class="bi bi-palette me-1"></i> Theme Settings
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Signature Upload Modal -->
    <div class="modal fade" id="updateSignatureModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pen me-2"></i>Upload Digital Signature</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="signatureForm" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="signatureFile" class="form-label">Select Signature Image</label>
                            <input type="file" class="form-control" id="signatureFile" accept="image/png,image/jpeg" required>
                            <small class="form-text text-muted">PNG or JPG format, max 2MB</small>
                        </div>
                        <div id="filePreview" style="display: none; margin-bottom: 15px;">
                            <label class="form-label">Preview:</label>
                            <img id="previewImg" alt="Preview" style="max-width: 100%; max-height: 200px; border: 1px solid #ddd; border-radius: 3px; padding: 5px;">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="uploadSignature()">Upload Signature</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // File preview
        document.getElementById('signatureFile').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            // Validate file size
            if (file.size > 2 * 1024 * 1024) {
                alert('File size must be less than 2MB');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(event) {
                document.getElementById('previewImg').src = event.target.result;
                document.getElementById('filePreview').style.display = 'block';
            };
            reader.readAsDataURL(file);
        });
        
        function uploadSignature() {
            const fileInput = document.getElementById('signatureFile');
            const file = fileInput.files[0];
            
            if (!file) {
                alert('Please select a file');
                return;
            }
            
            const formData = new FormData();
            formData.append('signature', file);
            formData.append('type', 'dean');
            
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading...';
            
            fetch('../upload_signature.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Signature uploaded successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Failed to upload'));
                    btn.disabled = false;
                    btn.innerHTML = 'Upload Signature';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
                btn.disabled = false;
                btn.innerHTML = 'Upload Signature';
            });
        }
    </script>
