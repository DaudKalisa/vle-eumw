    <div class="container mt-2 mb-2">
        <button class="btn btn-outline-secondary mb-2" onclick="window.history.back();"><i class="bi bi-arrow-left"></i> Back</button>
    </div>
<?php
// edit_assignment.php - Edit Assignment
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];
$assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;

// Get assignment details
$stmt = $conn->prepare("SELECT * FROM vle_assignments WHERE assignment_id = ?");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment) {
    echo '<div class="alert alert-danger">Assignment not found.</div>';
    exit();
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $max_score = (int)($_POST['max_score'] ?? 100);
    $due_date = $_POST['due_date'] ?? null;
    $assignment_type = $_POST['assignment_type'] ?? $assignment['assignment_type'];

    $stmt = $conn->prepare("UPDATE vle_assignments SET title = ?, description = ?, max_score = ?, due_date = ?, assignment_type = ? WHERE assignment_id = ?");
    $stmt->bind_param("ssissi", $title, $description, $max_score, $due_date, $assignment_type, $assignment_id);
    if ($stmt->execute()) {
        $success = "Assignment updated successfully!";
        // Refresh assignment
        $stmt = $conn->prepare("SELECT * FROM vle_assignments WHERE assignment_id = ?");
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
    } else {
        $error = "Failed to update assignment: " . $conn->error;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Assignment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header_nav.php'; ?>
<div class="container-fluid px-3 px-lg-4 mt-3 mt-lg-4">
    <div class="mb-3">
        <button class="btn btn-outline-secondary" onclick="window.history.back();"><i class="bi bi-arrow-left"></i> Back</button>
    </div>
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4>Edit Assignment</h4>
                </div>
                <div class="card-body">
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label for="title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($assignment['title']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="5" required><?php echo htmlspecialchars($assignment['description']); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="max_score" class="form-label">Max Score</label>
                            <input type="number" class="form-control" id="max_score" name="max_score" value="<?php echo $assignment['max_score']; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="due_date" class="form-label">Due Date</label>
                            <input type="datetime-local" class="form-control" id="due_date" name="due_date" value="<?php echo $assignment['due_date'] ? date('Y-m-d\TH:i', strtotime($assignment['due_date'])) : ''; ?>">
                        </div>
                        <div class="mb-3">
                            <label for="assignment_type" class="form-label">Assignment Type</label>
                            <select class="form-select" id="assignment_type" name="assignment_type">
                                <option value="regular" <?php echo $assignment['assignment_type'] == 'regular' ? 'selected' : ''; ?>>Regular</option>
                                <option value="summative" <?php echo $assignment['assignment_type'] == 'summative' ? 'selected' : ''; ?>>Summative</option>
                                <option value="midsemester" <?php echo $assignment['assignment_type'] == 'midsemester' ? 'selected' : ''; ?>>Midsemester</option>
                                <option value="final" <?php echo $assignment['assignment_type'] == 'final' ? 'selected' : ''; ?>>Final</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Assignment</button>
                        <a href="dashboard.php?course_id=<?php echo $assignment['course_id']; ?>" class="btn btn-secondary">Back</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
