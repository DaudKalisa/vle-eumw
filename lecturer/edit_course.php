<?php
// edit_course.php - Edit VLE course details
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

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
    $course_name = trim($_POST['course_name'] ?? '');
    $course_code = trim($_POST['course_code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $total_weeks = (int)($_POST['total_weeks'] ?? 12);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (!empty($course_name) && !empty($course_code)) {
        $stmt = $conn->prepare("UPDATE vle_courses SET course_name = ?, course_code = ?, description = ?, total_weeks = ?, is_active = ? WHERE course_id = ?");
        $stmt->bind_param("sssiii", $course_name, $course_code, $description, $total_weeks, $is_active, $course_id);

        if ($stmt->execute()) {
            header("Location: dashboard.php?course_id=$course_id&success=1");
            exit();
        } else {
            $error = "Failed to update course: " . $conn->error;
        }
    } else {
        $error = "Course name and code are required.";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Course - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h4>Edit Course</h4>
                        <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-secondary btn-sm">Back to Dashboard</a>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label for="course_name" class="form-label">Course Name *</label>
                                <input type="text" class="form-control" id="course_name" name="course_name" value="<?php echo htmlspecialchars($course['course_name']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="course_code" class="form-label">Course Code *</label>
                                <input type="text" class="form-control" id="course_code" name="course_code" value="<?php echo htmlspecialchars($course['course_code']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($course['description']); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="total_weeks" class="form-label">Total Weeks</label>
                                <input type="number" class="form-control" id="total_weeks" name="total_weeks" value="<?php echo $course['total_weeks']; ?>" min="1" max="52">
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" <?php echo $course['is_active'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Course is Active</label>
                            </div>

                            <button type="submit" class="btn btn-primary">Update Course</button>
                            <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-secondary">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
