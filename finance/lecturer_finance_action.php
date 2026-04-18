<?php
/**
 * lecturer_finance_action.php - Handles lecturer finance request actions
 * Enforces proper approval workflow:
 * 1. Lecturer submits → status='pending', odl_approval_status='pending'
 * 2. ODL Coordinator approves → odl_approval_status='approved'/'rejected'/'forwarded_to_dean'
 * 3. Dean (if forwarded) approves → dean_approval_status='approved'/'rejected'/'returned'
 * 4. Finance reviews → Finance can only approve if:
 *    - odl_approval_status = 'approved' AND dean_approval_status = NULL, OR
 *    - dean_approval_status = 'approved'
 * 5. Finance marks paid → status='paid'
 */

require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

$conn = getDbConnection();
$user = getCurrentUser();
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['action'])) {
    $request_id = intval($_POST['request_id']);
    $action = $_POST['action'];
    $remarks = trim($_POST['remarks'] ?? '');
    $error_message = '';
    $success_message = '';
    
    // Get current request details for validation
    $check_stmt = $conn->prepare("
        SELECT request_id, status, odl_approval_status, dean_approval_status, lecturer_id
        FROM lecturer_finance_requests 
        WHERE request_id = ?
    ");
    $check_stmt->bind_param('i', $request_id);
    $check_stmt->execute();
    $req_result = $check_stmt->get_result();
    $request = $req_result->fetch_assoc();
    $check_stmt->close();
    
    if (!$request) {
        $error_message = 'Request not found.';
    } else {
        // Validate action based on approval workflow
        $can_approve = false;
        $can_reject = false;
        $can_mark_paid = false;
        
        // Check if request is ready for finance approval
        if ($action === 'approve') {
            // Finance can approve only if:
            // 1. ODL approved it and didn't forward to dean (dean_approval_status is NULL), OR
            // 2. ODL forwarded to dean and Dean approved it
            if (($request['odl_approval_status'] === 'approved' && empty($request['dean_approval_status'])) ||
                ($request['dean_approval_status'] === 'approved' && 
                 in_array($request['odl_approval_status'], ['approved', 'forwarded_to_dean']))) {
                $can_approve = true;
            } elseif ($request['odl_approval_status'] === 'pending') {
                $error_message = 'Request must be approved by ODL Coordinator first.';
            } elseif ($request['dean_approval_status'] === 'pending') {
                $error_message = 'Request is pending Dean approval.';
            } else {
                $error_message = 'Request cannot be approved in its current state.';
            }
        } elseif ($action === 'reject') {
            // Finance can reject at any stage if not already processed
            if ($request['status'] !== 'paid' && $request['status'] !== 'rejected') {
                $can_reject = true;
            } else {
                $error_message = 'Request cannot be rejected in its current state.';
            }
        } elseif ($action === 'revise_rates') {
            // Finance can revise rates at any stage except paid
            if ($request['status'] !== 'paid') {
                $revised_hourly_rate = $_POST['revised_hourly_rate'] ?? null;
                $revised_airtime_rate = $_POST['revised_airtime_rate'] ?? null;
                $rate_revision_reason = trim($_POST['rate_revision_reason'] ?? '');
                
                // At least one rate must be provided
                if (!$revised_hourly_rate && !$revised_airtime_rate) {
                    $error_message = 'Please provide at least one revised rate.';
                } else {
                    // Update rates
                    $user_id = $user['id'] ?? $user['user_id'] ?? null;
                    $stmt = $conn->prepare("
                        UPDATE lecturer_finance_requests 
                        SET revised_hourly_rate = ?,
                            revised_airtime_rate = ?,
                            rate_revision_reason = ?,
                            revised_by = ?,
                            revised_at = NOW()
                        WHERE request_id = ?
                    ");
                    $stmt->bind_param('ddsii', $revised_hourly_rate, $revised_airtime_rate, $rate_revision_reason, $user_id, $request_id);
                    if ($stmt->execute()) {
                        $stmt->close();
                        
                        // If hourly rate was revised, update total_amount
                        if ($revised_hourly_rate) {
                            $get_hours = $conn->prepare("SELECT total_hours FROM lecturer_finance_requests WHERE request_id = ?");
                            $get_hours->bind_param('i', $request_id);
                            $get_hours->execute();
                            $hours_result = $get_hours->get_result()->fetch_assoc();
                            $get_hours->close();
                            
                            if ($hours_result) {
                                $new_amount = $hours_result['total_hours'] * $revised_hourly_rate;
                                $update_amount = $conn->prepare("UPDATE lecturer_finance_requests SET total_amount = ? WHERE request_id = ?");
                                $update_amount->bind_param('di', $new_amount, $request_id);
                                $update_amount->execute();
                                $update_amount->close();
                            }
                        }
                        
                        $success_message = 'Rates revised successfully.';
                    } else {
                        $error_message = 'Database error: ' . $conn->error;
                        $stmt->close();
                    }
                }
            } else {
                $error_message = 'Cannot revise rates for paid requests.';
            }
        } elseif ($action === 'mark_paid') {
            // Finance can mark paid only if approved
            if ($request['status'] === 'approved') {
                $can_mark_paid = true;
            } else {
                $error_message = 'Request must be approved before marking as paid.';
            }
        } elseif ($action === 'delete') {
            // Finance can delete pending or rejected requests (not approved/paid)
            if (in_array($request['status'], ['pending', 'rejected'])) {
                $del_stmt = $conn->prepare("DELETE FROM lecturer_finance_requests WHERE request_id = ?");
                $del_stmt->bind_param('i', $request_id);
                if ($del_stmt->execute()) {
                    $del_stmt->close();
                    $success_message = 'Request deleted successfully.';
                } else {
                    $error_message = 'Database error: ' . $conn->error;
                    $del_stmt->close();
                }
            } else {
                $error_message = 'Only pending or rejected requests can be deleted.';
            }
        }
        
        // Execute action if validation passed
        if ($can_approve) {
            $status = 'approved';
            $stmt = $conn->prepare("
                UPDATE lecturer_finance_requests 
                SET status = ?, finance_approved_at = NOW()
                WHERE request_id = ?
            ");
            $stmt->bind_param('si', $status, $request_id);
            if ($stmt->execute()) {
                $stmt->close();
                $success_message = 'Request approved successfully.';
            } else {
                $error_message = 'Database error: ' . $conn->error;
                $stmt->close();
            }
        } elseif ($can_reject) {
            $status = 'rejected';
            $stmt = $conn->prepare("
                UPDATE lecturer_finance_requests 
                SET status = ?, finance_remarks = ?, finance_rejected_at = NOW()
                WHERE request_id = ?
            ");
            $stmt->bind_param('ssi', $status, $remarks, $request_id);
            if ($stmt->execute()) {
                $stmt->close();
                $success_message = 'Request rejected successfully.';
            } else {
                $error_message = 'Database error: ' . $conn->error;
                $stmt->close();
            }
        } elseif ($can_mark_paid) {
            $status = 'paid';
            $stmt = $conn->prepare("
                UPDATE lecturer_finance_requests 
                SET status = ?, finance_paid_at = NOW()
                WHERE request_id = ?
            ");
            $stmt->bind_param('si', $status, $request_id);
            if ($stmt->execute()) {
                $stmt->close();
                $success_message = 'Request marked as paid successfully.';
            } else {
                $error_message = 'Database error: ' . $conn->error;
                $stmt->close();
            }
        }
    }
    
    // Return response
    if ($isAjax) {
        header('Content-Type: application/json');
        if (!empty($error_message)) {
            echo json_encode(['success' => false, 'message' => $error_message]);
        } else {
            echo json_encode(['success' => true, 'message' => $success_message]);
        }
        exit;
    } else {
        if (!empty($error_message)) {
            $_SESSION['vle_error'] = $error_message;
        } else {
            $_SESSION['vle_success'] = $success_message;
        }
        header('Location: finance_manage_requests.php');
        exit;
    }
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
} else {
    header('Location: finance_manage_requests.php');
}
exit;