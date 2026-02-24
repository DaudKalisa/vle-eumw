<?php
// add_content.php - Add weekly content to VLE course
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();

// Get course_id and week from URL
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$week = isset($_GET['week']) ? (int)$_GET['week'] : 0;

// Verify lecturer owns this course
$user = getCurrentUser();
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
$stmt->bind_param("ii", $course_id, $user['related_lecturer_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$course = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $content_type = $_POST['content_type'] ?? 'text';
    $content_url = trim($_POST['content_url'] ?? '');
    $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
    $file_path = null;
    $file_name = null;
    
    // If URL is provided for video/audio/link types, store it in description
    if (in_array($content_type, ['video', 'audio', 'link']) && !empty($content_url)) {
        $description = $content_url;
    }

    // Handle file upload if content type requires it
    if (in_array($content_type, ['presentation', 'video', 'document', 'file']) && isset($_FILES['content_file']) && $_FILES['content_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_name = basename($_FILES['content_file']['name']);
        $file_path = time() . '_' . $file_name;
        $target_path = $upload_dir . $file_path;

        if (move_uploaded_file($_FILES['content_file']['tmp_name'], $target_path)) {
            // File uploaded successfully
        } else {
            $error = "Failed to upload file.";
        }
    }

    if (empty($error) && !empty($title)) {
        $stmt = $conn->prepare("INSERT INTO vle_weekly_content (course_id, week_number, title, description, content_type, file_path, file_name, is_mandatory) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissssii", $course_id, $week, $title, $description, $content_type, $file_path, $file_name, $is_mandatory);

        if ($stmt->execute()) {
            // Send notification to enrolled students
            if (isEmailEnabled()) {
                // Get enrolled students
                $students_stmt = $conn->prepare("
                    SELECT s.full_name, s.email 
                    FROM students s 
                    INNER JOIN vle_enrollments ve ON s.student_id = ve.student_id 
                    WHERE ve.course_id = ?
                ");
                $students_stmt->bind_param("i", $course_id);
                $students_stmt->execute();
                $students_result = $students_stmt->get_result();
                
                // Get lecturer name
                $lecturer_name = $user['display_name'] ?? 'Your Instructor';
                
                while ($student = $students_result->fetch_assoc()) {
                    sendDocumentUploadedEmail(
                        $student['email'],
                        $student['full_name'],
                        $lecturer_name,
                        $course['course_name'],
                        $title,
                        $content_type,
                        $description,
                        $course_id
                    );
                }
            }
            
            header("Location: dashboard.php?course_id=$course_id");
            exit();
        } else {
            $error = "Failed to add content: " . $conn->error;
        }
    } elseif (empty($title)) {
        $error = "Title is required.";
    }
}

$user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
        <style>
            .navbar.sticky-Top, .navbar.fixed-top {
                position: sticky;
                top: 0;
                z-index: 9999;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                background: #198754 !important;
            }
            .navbar-brand img {
                height: 48px;
                width: auto;
                margin-right: 10px;
            }
        </style>
        <!-- Modern Header -->
        <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #002147; position: sticky; top: 0; z-index: 1050; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <div class="container-fluid">
                <a class="navbar-brand d-flex align-items-center fw-bold text-white me-4" href="dashboard.php">
                    <img src="../assets/img/Logo.png" alt="Logo" style="height:38px;width:auto;margin-right:10px;">
                    <span>VLE-EUMW</span>
                </a>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="gradebook.php">Gradebook</a></li>
                    <li class="nav-item"><a class="nav-link" href="messages.php">Messages</a></li>
                    <li class="nav-item"><a class="nav-link" href="announcements.php">Announcements</a></li>
                    <li class="nav-item"><a class="nav-link" href="forum.php">Forums</a></li>
                </ul>
                <ul class="navbar-nav align-items-center mb-2 mb-lg-0 ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span><?php echo htmlspecialchars($user['display_name']); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
                            <li><a class="dropdown-item" href="change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </nav>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Content - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h4>Add Content to <?php echo htmlspecialchars($course['course_name']); ?> - Week <?php echo $week; ?></h4>
                        <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-secondary btn-sm">Back to Dashboard</a>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="title" class="form-label">Title *</label>
                                <input type="text" class="form-control" id="title" name="title" required>
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="content_type" class="form-label">Content Type</label>
                                <select class="form-select" id="content_type" name="content_type">
                                    <option value="text">Text</option>
                                    <option value="presentation">Presentation</option>
                                    <option value="video">Video</option>
                                    <option value="document">Document</option>
                                    <option value="file">File</option>
                                    <option value="audio">Audio</option>
                                    <option value="link">Supplementary Resource</option>
                                </select>
                            </div>

                            <div class="mb-3" id="url_input" style="display: none;">
                                <label for="content_url" class="form-label">URL</label>
                                <input type="url" class="form-control" id="content_url" name="content_url" placeholder="https://example.com">
                                <small class="form-text text-muted">For videos: YouTube, Vimeo, etc. | For audio: SoundCloud, podcast URLs, etc.</small>
                            </div>

                            <div class="mb-3" id="file_upload" style="display: none;">
                                <label for="content_file" class="form-label">Upload File</label>
                                <input type="file" class="form-control" id="content_file" name="content_file">
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_mandatory" name="is_mandatory" checked>
                                <label class="form-check-label" for="is_mandatory">Mandatory content</label>
                            </div>

                            <button type="submit" class="btn btn-primary">Add Content</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Summernote rich text editor
            $('#description').summernote({
                height: 300,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture', 'video']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ]
            });
        });

        // Show/hide file upload and URL input based on content type
        document.getElementById('content_type').addEventListener('change', function() {
            const fileUpload = document.getElementById('file_upload');
            const urlInput = document.getElementById('url_input');
            const contentType = this.value;
            
            if (['presentation', 'document', 'file'].includes(contentType)) {
                fileUpload.style.display = 'block';
                urlInput.style.display = 'none';
            } else if (['video', 'audio', 'link'].includes(contentType)) {
                fileUpload.style.display = 'none';
                urlInput.style.display = 'block';
            } else {
                fileUpload.style.display = 'none';
                urlInput.style.display = 'none';
            }
        });
    </script>
</body>
</html>