<?php
// finance/get_student_finance.php - AJAX endpoint to get student finance details
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff']);

header('Content-Type: application/json');

$conn = getDbConnection();
$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : '';

if (empty($student_id)) {
    echo json_encode(['error' => 'Student ID required']);
    exit;
}

// Get fee settings
$fee_query = "SELECT * FROM fee_settings WHERE id = 1";
$fee_result = $conn->query($fee_query);
$fee_settings = $fee_result->fetch_assoc();

// Get student finance data
$query = "SELECT sf.*, s.program_type FROM student_finances sf 
          LEFT JOIN students s ON sf.student_id = s.student_id 
          WHERE sf.student_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(['error' => 'Student finance record not found']);
    exit;
}

$data = $result->fetch_assoc();

// Add fee settings to response
$data['application_fee'] = $fee_settings['application_fee'];
$data['registration_fee'] = $fee_settings['registration_fee'];

// Get tuition based on program type
$program_type = $data['program_type'] ?? 'degree';
$tuition_key = 'tuition_' . $program_type;
$data['expected_tuition'] = $fee_settings[$tuition_key];
$data['expected_total'] = $fee_settings['application_fee'] + $fee_settings['registration_fee'] + $fee_settings[$tuition_key];

// Rename fields for JavaScript compatibility
$data['installment_1_paid'] = $data['installment_1'];
$data['installment_2_paid'] = $data['installment_2'];
$data['installment_3_paid'] = $data['installment_3'];
$data['installment_4_paid'] = $data['installment_4'];

echo json_encode($data);

$stmt->close();
?>
