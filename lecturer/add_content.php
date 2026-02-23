<?php
// add_content.php - Add weekly content to VLE course
require_once '../includes/auth.php';
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
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
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