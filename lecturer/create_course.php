<?php
// create_course.php - Create content for assigned VLE courses
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$user = getCurrentUser();
$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];

$message = '';
$content_created = false;

// Get assigned courses for this lecturer
$assigned_courses = [];
$course_query = "SELECT course_id, course_code, course_name, total_weeks 
                 FROM vle_courses 
                 WHERE lecturer_id = ? AND is_active = TRUE 
                 ORDER BY course_name";
$stmt = $conn->prepare($course_query);
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $assigned_courses[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_id = (int)($_POST['course_id'] ?? 0);
    $week_number = (int)($_POST['week_number'] ?? 1);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $content_type = $_POST['content_type'] ?? 'text';
    $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
    
    // Verify the course is assigned to this lecturer
    $verify_stmt = $conn->prepare("SELECT course_id FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
    $verify_stmt->bind_param("ii", $course_id, $lecturer_id);
    $verify_stmt->execute();
    
    if ($verify_stmt->get_result()->num_rows === 0) {
        $message = 'You are not assigned to this course.';
    } elseif (empty($title)) {
        $message = 'Title is required.';
    } else {
        $file_path = null;
        $file_name = null;
        
        // Handle file upload
        if (isset($_FILES['content_file']) && $_FILES['content_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_name = basename($_FILES['content_file']['name']);
            $file_path = time() . '_' . $file_name;
            $target_path = $upload_dir . $file_path;

            if (!move_uploaded_file($_FILES['content_file']['tmp_name'], $target_path)) {
                $message = "Failed to upload file.";
            }
        }
        
        if (empty($message)) {
            $stmt = $conn->prepare("INSERT INTO vle_weekly_content (course_id, week_number, title, description, content_type, file_path, file_name, is_mandatory) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iissssii", $course_id, $week_number, $title, $description, $content_type, $file_path, $file_name, $is_mandatory);

            if ($stmt->execute()) {
                $content_created = true;
                $message = 'Content created successfully!';
            } else {
                $message = 'Error creating content: ' . $conn->error;
            }
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Course - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-success">
        <div class="container">
            <a class="navbar-brand" href="#">VLE System - Lecturer</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <span class="navbar-text me-3">Welcome, <?php echo htmlspecialchars($user['display_name']); ?></span>
                <a class="nav-link" href="../logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4>Create Content for Assigned Course</h4>
                    </div>
                    <div class="card-body">
                        <?php if (empty($assigned_courses)): ?>
                            <div class="alert alert-warning">
                                <h5><i class="bi bi-exclamation-triangle"></i> No Courses Assigned</h5>
                                <p>You don't have any courses assigned to you yet. Please contact the administrator to assign courses to you.</p>
                                <a href="dashboard.php" class="btn btn-secondary">Go to Dashboard</a>
                            </div>
                        <?php else: ?>
                            <?php if ($message): ?>
                                <div class="alert alert-<?php echo $content_created ? 'success' : 'danger'; ?>">
                                    <?php echo htmlspecialchars($message); ?>
                                </div>
                                <?php if ($content_created): ?>
                                    <div class="text-center">
                                        <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                                        <button type="button" class="btn btn-success" onclick="window.location.reload()">Add More Content</button>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!$content_created): ?>
                                <form method="POST" action="" enctype="multipart/form-data">
                                    <div class="mb-3">
                                        <label for="course_id" class="form-label">Select Course *</label>
                                        <select class="form-select" id="course_id" name="course_id" required onchange="updateWeekOptions()">
                                            <option value="">-- Select a Course --</option>
                                            <?php foreach ($assigned_courses as $course): ?>
                                                <option value="<?php echo $course['course_id']; ?>" 
                                                        data-weeks="<?php echo $course['total_weeks']; ?>">
                                                    <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Select from your assigned courses</div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="week_number" class="form-label">Week Number *</label>
                                        <select class="form-select" id="week_number" name="week_number" required>
                                            <option value="">Select course first</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label for="title" class="form-label">Content Title *</label>
                                        <input type="text" class="form-control" id="title" name="title" required
                                               placeholder="e.g., Introduction to Programming" maxlength="200">
                                    </div>

                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="4"
                                                  placeholder="Content description and learning objectives..."></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label for="content_type" class="form-label">Content Type *</label>
                                        <select class="form-select" id="content_type" name="content_type" required onchange="toggleFileUpload()">
                                            <option value="text">Text/Notes</option>
                                            <option value="presentation">Presentation (PPT/PDF)</option>
                                            <option value="video">Video</option>
                                            <option value="document">Document</option>
                                            <option value="link">External Link</option>
                                            <option value="file">Other File</option>
                                        </select>
                                    </div>

                                    <div class="mb-3" id="file_upload_section">
                                        <label for="content_file" class="form-label">Upload File</label>
                                        <input type="file" class="form-control" id="content_file" name="content_file">
                                        <div class="form-text">Upload relevant file for this content</div>
                                    </div>

                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="is_mandatory" name="is_mandatory">
                                            <label class="form-check-label" for="is_mandatory">
                                                Mark as mandatory content
                                            </label>
                                        </div>
                                    </div>

                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <a href="dashboard.php" class="btn btn-secondary me-md-2">Cancel</a>
                                        <button type="submit" class="btn btn-primary">Create Content</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateWeekOptions() {
            const courseSelect = document.getElementById('course_id');
            const weekSelect = document.getElementById('week_number');
            const selectedOption = courseSelect.options[courseSelect.selectedIndex];
            const totalWeeks = selectedOption.getAttribute('data-weeks') || 0;
            
            weekSelect.innerHTML = '<option value="">-- Select Week --</option>';
            
            for (let i = 1; i <= totalWeeks; i++) {
                const option = document.createElement('option');
                option.value = i;
                option.textContent = 'Week ' + i;
                weekSelect.appendChild(option);
            }
        }
        
        function toggleFileUpload() {
            const contentType = document.getElementById('content_type').value;
            const fileSection = document.getElementById('file_upload_section');
            
            if (contentType === 'text' || contentType === 'link') {
                fileSection.style.display = 'none';
            } else {
                fileSection.style.display = 'block';
            }
        }
        
        // Initialize on page load
        toggleFileUpload();
    </script>
</body>
</html>