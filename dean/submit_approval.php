<?php
/**
 * Handle Dean Claim Approval Submission
 * Accepts JSON post from print_claim.php approval modal
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['dean', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();

// Set response header
header('Content-Type: application/json');

// Get JSON payload
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$request_id = (int)($input['request_id'] ?? 0);
$remarks = trim($input['remarks'] ?? '');
$action = $input['action'] ?? 'approve'; // approve, reject, delete

// Validate inputs
if (!$request_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid claim ID']);
    exit;
}

if (!in_array($action, ['approve', 'reject', 'return', 'delete'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

// Get the claim
$stmt = $conn->prepare("SELECT * FROM lecturer_finance_requests WHERE request_id = ?");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$result = $stmt->get_result();
$claim = $result->fetch_assoc();

if (!$claim) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Claim not found']);
    exit;
}

// Ensure claim is in a state the dean can act on
if (!in_array($claim['odl_approval_status'], ['approved', 'forwarded_to_dean'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'This claim has not been forwarded to the Dean']);
    exit;
}

// Ensure dean hasn't already processed this claim (unless deleting)
if ($action !== 'delete' && in_array($claim['dean_approval_status'], ['approved', 'rejected'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'This claim has already been ' . $claim['dean_approval_status'] . ' by the Dean']);
    exit;
}

// For delete, only allow if not yet paid
if ($action === 'delete' && $claim['status'] === 'paid') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Cannot delete a paid claim']);
    exit;
}

try {
    // Ensure log table exists
    $conn->query("CREATE TABLE IF NOT EXISTS dean_claims_approval (
        approval_id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        dean_id INT NOT NULL,
        status VARCHAR(50) NOT NULL,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    if ($action === 'approve') {
        // Process signature from base64 POST data
        $signature = $input['signature'] ?? '';
        $signature_filename = null;

        if (!empty($signature) && strpos($signature, 'data:image') === 0) {
            $image_parts = explode(',', $signature);
            if (count($image_parts) === 2) {
                $decoded = base64_decode($image_parts[1]);
                if ($decoded) {
                    $upload_dir = '../uploads/signatures';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $signature_filename = 'sig_' . $request_id . '_dean_' . time() . '.png';
                    file_put_contents($upload_dir . '/' . $signature_filename, $decoded);
                }
            }
        }

        $stmt = $conn->prepare("
            UPDATE lecturer_finance_requests 
            SET dean_approval_status = 'approved',
                dean_approved_by = ?,
                dean_approved_at = NOW(),
                dean_remarks = ?,
                dean_signature_path = ?
            WHERE request_id = ?
        ");
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $stmt->bind_param("issi", $user['user_id'], $remarks, $signature_filename, $request_id);
        if (!$stmt->execute()) throw new Exception('Execute failed: ' . $conn->error);

        $log_status = 'approved';
        $response_msg = 'Claim approved successfully';

    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("
            UPDATE lecturer_finance_requests 
            SET dean_approval_status = 'rejected',
                dean_approved_by = ?,
                dean_approved_at = NOW(),
                dean_remarks = ?
            WHERE request_id = ?
        ");
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $stmt->bind_param("isi", $user['user_id'], $remarks, $request_id);
        if (!$stmt->execute()) throw new Exception('Execute failed: ' . $conn->error);

        $log_status = 'rejected';
        $response_msg = 'Claim rejected successfully';

    } elseif ($action === 'return') {
        $stmt = $conn->prepare("
            UPDATE lecturer_finance_requests 
            SET dean_approval_status = 'returned',
                dean_approved_by = ?,
                dean_approved_at = NOW(),
                dean_remarks = ?
            WHERE request_id = ?
        ");
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $stmt->bind_param("isi", $user['user_id'], $remarks, $request_id);
        if (!$stmt->execute()) throw new Exception('Execute failed: ' . $conn->error);

        $log_status = 'returned';
        $response_msg = 'Claim returned to ODL Coordinator';

    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM lecturer_finance_requests WHERE request_id = ?");
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $stmt->bind_param("i", $request_id);
        if (!$stmt->execute()) throw new Exception('Execute failed: ' . $conn->error);

        $log_status = 'deleted';
        $response_msg = 'Claim deleted successfully';
    }

    // Log the action
    $log_stmt = $conn->prepare("
        INSERT INTO dean_claims_approval (request_id, dean_id, status, remarks, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    if ($log_stmt) {
        $log_stmt->bind_param("iiss", $request_id, $user['user_id'], $log_status, $remarks);
        $log_stmt->execute();
    }

    echo json_encode([
        'success' => true,
        'message' => $response_msg,
        'claim_id' => $request_id
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>
