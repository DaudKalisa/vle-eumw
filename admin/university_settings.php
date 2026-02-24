<?php
// admin/university_settings.php - Manage university details
require_once '../includes/auth.php';
requireLogin();
requireRole(['staff', 'admin']);

$conn = getDbConnection();

// Handle form submission
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $university_name = trim($_POST['university_name']);
    $po_box = trim($_POST['po_box']);
    $area = trim($_POST['area']);
    $street = trim($_POST['street']);
    $city = trim($_POST['city']);
    $country = trim($_POST['country']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $website = trim($_POST['website']);
    $footer_text = trim($_POST['footer_text']);
    
    // Handle logo upload
    $logo_path = '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/university/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $new_filename = 'logo_' . time() . '.' . $file_extension;
            $logo_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $logo_path)) {
                // Delete old logo if exists
                $old_logo = $conn->query("SELECT logo_path FROM university_settings LIMIT 1");
                if ($old_logo && $row = $old_logo->fetch_assoc()) {
                    if ($row['logo_path'] && file_exists($row['logo_path'])) {
                        unlink($row['logo_path']);
                    }
                }
            } else {
                $error = "Failed to upload logo.";
            }
        } else {
            $error = "Invalid file type. Only JPG, PNG, and GIF allowed.";
        }
    }
    
    if (!$error) {
        // Check if settings exist
        $check = $conn->query("SELECT COUNT(*) as count FROM university_settings");
        $row = $check->fetch_assoc();
        
        if ($row['count'] > 0) {
            // Update existing
            $sql = "UPDATE university_settings SET 
                    university_name = ?, 
                    address_po_box = ?, 
                    address_area = ?, 
                    address_street = ?, 
                    address_city = ?, 
                    address_country = ?,
                    phone = ?,
                    email = ?,
                    website = ?,
                    receipt_footer_text = ?";
            
            $params = [$university_name, $po_box, $area, $street, $city, $country, $phone, $email, $website, $footer_text];
            
            if ($logo_path) {
                $sql .= ", logo_path = ?";
                $params[] = $logo_path;
            }
            
            $sql .= " WHERE id = 1";
            
            $stmt = $conn->prepare($sql);
            if ($logo_path) {
                $stmt->bind_param("sssssssssss", ...$params);
            } else {
                $stmt->bind_param("ssssssssss", ...$params);
            }
            
            if ($stmt->execute()) {
                $success = "University settings updated successfully!";
            } else {
                $error = "Failed to update settings: " . $stmt->error;
            }
        } else {
            // Insert new
            $sql = "INSERT INTO university_settings (university_name, address_po_box, address_area, address_street, address_city, address_country, phone, email, website, receipt_footer_text, logo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssssssss", $university_name, $po_box, $area, $street, $city, $country, $phone, $email, $website, $footer_text, $logo_path);
            
            if ($stmt->execute()) {
                $success = "University settings created successfully!";
            } else {
                $error = "Failed to create settings: " . $stmt->error;
            }
        }
    }
}

// Get current settings
$settings = $conn->query("SELECT * FROM university_settings LIMIT 1")->fetch_assoc();
if (!$settings) {
    $settings = [
        'university_name' => 'Exploits University',
        'address_po_box' => 'P.O.Box 301752',
        'address_area' => 'Area 4',
        'address_street' => '',
        'address_city' => 'Lilongwe',
        'address_country' => 'Malawi',
        'phone' => '',
        'email' => '',
        'website' => '',
        'logo_path' => '',
        'receipt_footer_text' => 'Thank you for your payment'
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>University Settings - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body>
    <?php 
    $currentPage = 'university_settings';
    $pageTitle = 'University Settings';
    $breadcrumbs = [['title' => 'Settings']];
    include 'header_nav.php'; 
    ?>

    <div class="vle-content">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card vle-card shadow">
                    <div class="card-header bg-vle-primary text-white">
                        <h4 class="mb-0"><i class="bi bi-gear-fill"></i> University Settings</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($success): ?>
                            <div class="alert vle-alert-success alert-dismissible fade show">
                                <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert vle-alert-error alert-dismissible fade show">
                                <i class="bi bi-exclamation-circle"></i> <?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <!-- Logo Section -->
                            <div class="mb-4">
                                <h5 class="border-bottom pb-2"><i class="bi bi-image"></i> University Logo</h5>
                                <?php if ($settings['logo_path'] && file_exists($settings['logo_path'])): ?>
                                    <div class="mb-3 text-center">
                                        <img src="<?php echo htmlspecialchars($settings['logo_path']); ?>" alt="Current Logo" style="max-height: 150px;" class="img-thumbnail">
                                        <p class="text-muted mt-2">Current Logo</p>
                                    </div>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label class="form-label">Upload New Logo</label>
                                    <input type="file" class="form-control" name="logo" accept="image/*">
                                    <small class="text-muted">Accepted formats: JPG, PNG, GIF. Recommended size: 300x300px</small>
                                </div>
                            </div>

                            <!-- Basic Information -->
                            <div class="mb-4">
                                <h5 class="border-bottom pb-2"><i class="bi bi-info-circle"></i> Basic Information</h5>
                                <div class="mb-3">
                                    <label class="form-label">University Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="university_name" value="<?php echo htmlspecialchars($settings['university_name']); ?>" required>
                                </div>
                            </div>

                            <!-- Address Information -->
                            <div class="mb-4">
                                <h5 class="border-bottom pb-2"><i class="bi bi-geo-alt"></i> Address Information</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">P.O. Box</label>
                                        <input type="text" class="form-control" name="po_box" value="<?php echo htmlspecialchars($settings['address_po_box']); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Area</label>
                                        <input type="text" class="form-control" name="area" value="<?php echo htmlspecialchars($settings['address_area']); ?>">
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Street Name</label>
                                        <input type="text" class="form-control" name="street" value="<?php echo htmlspecialchars($settings['address_street']); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">City</label>
                                        <input type="text" class="form-control" name="city" value="<?php echo htmlspecialchars($settings['address_city']); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Country</label>
                                        <input type="text" class="form-control" name="country" value="<?php echo htmlspecialchars($settings['address_country']); ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- Contact Information -->
                            <div class="mb-4">
                                <h5 class="border-bottom pb-2"><i class="bi bi-telephone"></i> Contact Information</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Phone</label>
                                        <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($settings['phone']); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($settings['email']); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Website</label>
                                        <input type="text" class="form-control" name="website" value="<?php echo htmlspecialchars($settings['website']); ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- Receipt Settings -->
                            <div class="mb-4">
                                <h5 class="border-bottom pb-2"><i class="bi bi-receipt"></i> Receipt Settings</h5>
                                <div class="mb-3">
                                    <label class="form-label">Receipt Footer Text</label>
                                    <textarea class="form-control" name="footer_text" rows="2"><?php echo htmlspecialchars($settings['receipt_footer_text']); ?></textarea>
                                    <small class="text-muted">This text will appear at the bottom of all receipts</small>
                                </div>
                            </div>

                            <div class="text-end">
                                <button type="submit" name="update_settings" class="btn btn-primary btn-lg">
                                    <i class="bi bi-save"></i> Save Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
