<?php
/**
 * Handle Claim Approval Submission with Signature
 * Accepts JSON post from print_claim.php approval modal
 */

require_once '../includes/auth.php';
requireLogin();

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
$role = $input['role'] ?? '';
$signature = $input['signature'] ?? '';
$remarks = trim($input['remarks'] ?? '');

// Validate inputs
if (!$request_id || !in_array($role, ['odl', 'dean', 'finance'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request parameters']);
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

// Check authorization based on role
$user_role = $user['role'] ?? '';
$authorized = ($user_role === 'admin') || 
              ($role === 'odl' && $user_role === 'odl_coordinator') ||
              ($role === 'dean' && $user_role === 'dean') ||
              ($role === 'finance' && $user_role === 'finance');

if (!$authorized) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized to approve this claim']);
    exit;
}

// Check approval status based on role
if ($role === 'odl' && $claim['odl_approval_status'] === 'approved') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'This claim has already been approved by ODL Coordinator']);
    exit;
}

if ($role === 'dean' && $claim['dean_approval_status'] === 'approved') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'This claim has already been approved by Dean']);
    exit;
}

if ($role === 'finance' && ($claim['status'] === 'approved' || $claim['status'] === 'paid')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'This claim has already been approved by Finance']);
    exit;
}

// Process signature
$signature_filename = null;
if ($signature) {
    // Check if it's a data URL from canvas or already processed
    if (strpos($signature, 'data:image') === 0) {
        // Extract base64 data
        $image_data = explode(',', $signature);
        if (count($image_data) === 2) {
            $decoded = base64_decode($image_data[1]);
            if ($decoded === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid signature data']);
                exit;
            }
            
            // Create signatures directory if it doesn't exist
            $upload_dir = '../uploads/signatures';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate unique filename
            $signature_filename = 'sig_' . $request_id . '_' . $role . '_' . time() . '.png';
            $filepath = $upload_dir . '/' . $signature_filename;
            
            // Save the image
            if (file_put_contents($filepath, $decoded) === false) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to save signature']);
                exit;
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid signature format']);
            exit;
        }
    } else {
        $signature_filename = $signature; // Already a filename
    }
}

try {
    // Update claim based on role
    if ($role === 'odl') {
        $stmt = $conn->prepare("
            UPDATE lecturer_finance_requests 
            SET odl_approval_status = 'approved',
                odl_approved_by = ?,
                odl_approved_at = NOW(),
                odl_remarks = ?,
                odl_signature_path = ?
            WHERE request_id = ?
        ");
        $stmt->bind_param("issi", $user['user_id'], $remarks, $signature_filename, $request_id);
    } 
    elseif ($role === 'dean') {
        $stmt = $conn->prepare("
            UPDATE lecturer_finance_requests 
            SET dean_approval_status = 'approved',
                dean_approved_by = ?,
                dean_approved_at = NOW(),
                dean_remarks = ?,
                dean_signature_path = ?
            WHERE request_id = ?
        ");
        $stmt->bind_param("issi", $user['user_id'], $remarks, $signature_filename, $request_id);
    } 
    elseif ($role === 'finance') {
        $stmt = $conn->prepare("
            UPDATE lecturer_finance_requests 
            SET status = 'approved',
                finance_approved_by = ?,
                finance_signed_at = NOW(),
                finance_remarks = ?,
                finance_signature_path = ?
            WHERE request_id = ?
        ");
        $stmt->bind_param("issi", $user['user_id'], $remarks, $signature_filename, $request_id);
    }
    
    if (!$stmt->execute()) {
        throw new Exception('Database update failed: ' . $conn->error);
    }
    
    // Log the approval action (get approver name)
    $approver_name = $user['full_name'] ?? $user['email'] ?? 'System';
    
    echo json_encode([
        'success' => true,
        'message' => 'Approval submitted successfully',
        'signature_path' => $signature_filename
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>
