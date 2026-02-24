<?php
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();
$lecturer_id = $_SESSION['vle_related_id'];

if (!isset($_GET['content_id'])) {
    die('No content selected.');
}
$content_id = (int)$_GET['content_id'];

// Fetch content details
$stmt = $conn->prepare("SELECT * FROM vle_weekly_content WHERE content_id = ?");
$stmt->bind_param("i", $content_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die('Content not found.');
}
$content = $result->fetch_assoc();

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $content_type = $_POST['content_type'];
    $stmt = $conn->prepare("UPDATE vle_weekly_content SET title=?, description=?, content_type=? WHERE content_id=?");
    $stmt->bind_param("sssi", $title, $description, $content_type, $content_id);
    if ($stmt->execute()) {
        $success = 'Content updated successfully.';
        // Refresh content
        $content['title'] = $title;
        $content['description'] = $description;
        $content['content_type'] = $content_type;
    } else {
        $error = 'Update failed.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Content</title>
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
    <h2>Edit Content</h2>
    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="mb-3">
            <label for="title" class="form-label">Title</label>
            <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($content['title']); ?>" required>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label">Description</label>
            <textarea class="form-control" id="description" name="description" rows="5" required><?php echo htmlspecialchars($content['description']); ?></textarea>
        </div>
        <div class="mb-3">
            <label for="content_type" class="form-label">Content Type</label>
            <select class="form-select" id="content_type" name="content_type">
                <option value="presentation" <?php if ($content['content_type'] == 'presentation') echo 'selected'; ?>>Presentation</option>
                <option value="video" <?php if ($content['content_type'] == 'video') echo 'selected'; ?>>Video</option>
                <option value="document" <?php if ($content['content_type'] == 'document') echo 'selected'; ?>>Document</option>
                <option value="link" <?php if ($content['content_type'] == 'link') echo 'selected'; ?>>Link</option>
                <option value="text" <?php if ($content['content_type'] == 'text') echo 'selected'; ?>>Text</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Update Content</button>
        <a href="dashboard.php?course_id=<?php echo $content['course_id']; ?>" class="btn btn-secondary">Back</a>
    </form>
</div>
</body>
</html>
