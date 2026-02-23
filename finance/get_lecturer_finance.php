<?php
// get_lecturer_finance.php - Fetch lecturer finance requests
require_once '../includes/auth.php';
requireLogin();
requireRole(['lecturer', 'admin', 'finance']);

header('Content-Type: application/json');

$conn = getDbConnection();
$response = ['success' => false, 'data' => [], 'message' => ''];

try {
    $action = $_GET['action'] ?? 'list';
    $lecturer_id = $_SESSION['vle_related_id'] ?? null;
    $user_role = $_SESSION['vle_user_type'] ?? '';

    switch ($action) {
        case 'list':
            // Get finance requests list
            $query = "
                SELECT 
                    lfr.*,
                    l.full_name as lecturer_name,
                    l.email as lecturer_email,
                    l.department,
                    l.position
                FROM lecturer_finance_requests lfr
                LEFT JOIN lecturers l ON lfr.lecturer_id = l.lecturer_id
            ";
            
            // Filter by lecturer if not admin/finance
            if ($user_role === 'lecturer') {
                $query .= " WHERE lfr.lecturer_id = ?";
                $stmt = $conn->prepare($query . " ORDER BY lfr.submission_date DESC");
                $stmt->bind_param("s", $lecturer_id);
            } else {
                // Admin/Finance can see all requests
                $status_filter = $_GET['status'] ?? '';
                $month_filter = $_GET['month'] ?? '';
                $year_filter = $_GET['year'] ?? '';
                
                $where_conditions = [];
                $params = [];
                $types = '';
                
                if (!empty($status_filter)) {
                    $where_conditions[] = "lfr.status = ?";
                    $params[] = $status_filter;
                    $types .= 's';
                }
                
                if (!empty($month_filter)) {
                    $where_conditions[] = "lfr.month = ?";
                    $params[] = $month_filter;
                    $types .= 'i';
                }
                
                if (!empty($year_filter)) {
                    $where_conditions[] = "lfr.year = ?";
                    $params[] = $year_filter;
                    $types .= 'i';
                }
                
                if (!empty($where_conditions)) {
                    $query .= " WHERE " . implode(" AND ", $where_conditions);
                }
                
                $query .= " ORDER BY lfr.submission_date DESC";
                $stmt = $conn->prepare($query);
                
                if (!empty($params)) {
                    $stmt->bind_param($types, ...$params);
                }
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $requests = [];
            
            while ($row = $result->fetch_assoc()) {
                // Decode courses data
                $row['courses'] = json_decode($row['courses_data'] ?? '[]', true);
                $requests[] = $row;
            }
            
            $response['success'] = true;
            $response['data'] = $requests;
            break;

        case 'details':
            // Get single request details
            $request_id = $_GET['request_id'] ?? 0;
            
            $query = "
                SELECT 
                    lfr.*,
                    l.full_name as lecturer_name,
                    l.email as lecturer_email,
                    l.phone as lecturer_phone,
                    l.department,
                    l.position,
                    l.qualification,
                    l.nrc
                FROM lecturer_finance_requests lfr
                LEFT JOIN lecturers l ON lfr.lecturer_id = l.lecturer_id
                WHERE lfr.request_id = ?
            ";
            
            // Lecturers can only view their own requests
            if ($user_role === 'lecturer') {
                $query .= " AND lfr.lecturer_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("is", $request_id, $lecturer_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $request_id);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $row['courses'] = json_decode($row['courses_data'] ?? '[]', true);
                $response['success'] = true;
                $response['data'] = $row;
            } else {
                $response['message'] = 'Request not found or access denied';
            }
            break;

        case 'stats':
            // Get statistics
            $query = "
                SELECT 
                    COUNT(*) as total_requests,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                    SUM(total_amount) as total_amount,
                    SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END) as approved_amount,
                    SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as paid_amount
                FROM lecturer_finance_requests
            ";
            
            if ($user_role === 'lecturer') {
                $query .= " WHERE lecturer_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $lecturer_id);
            } else {
                $stmt = $conn->prepare($query);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $stats = $result->fetch_assoc();
            
            $response['success'] = true;
            $response['data'] = $stats;
            break;

        case 'update_status':
            // Update request status (Admin/Finance only)
            if (!in_array($user_role, ['admin', 'finance'])) {
                $response['message'] = 'Unauthorized access';
                break;
            }
            
            $request_id = $_POST['request_id'] ?? 0;
            $new_status = $_POST['status'] ?? '';
            $admin_notes = $_POST['admin_notes'] ?? '';
            
            if (!in_array($new_status, ['pending', 'approved', 'rejected', 'paid'])) {
                $response['message'] = 'Invalid status';
                break;
            }
            
            $stmt = $conn->prepare("
                UPDATE lecturer_finance_requests 
                SET status = ?, admin_notes = ?, reviewed_date = NOW()
                WHERE request_id = ?
            ");
            $stmt->bind_param("ssi", $new_status, $admin_notes, $request_id);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Status updated successfully';
            } else {
                $response['message'] = 'Failed to update status';
            }
            break;

        case 'monthly_summary':
            // Get monthly summary
            $month = $_GET['month'] ?? date('n');
            $year = $_GET['year'] ?? date('Y');
            
            $query = "
                SELECT 
                    l.full_name,
                    l.department,
                    lfr.*
                FROM lecturer_finance_requests lfr
                LEFT JOIN lecturers l ON lfr.lecturer_id = l.lecturer_id
                WHERE lfr.month = ? AND lfr.year = ?
            ";
            
            if ($user_role === 'lecturer') {
                $query .= " AND lfr.lecturer_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("iis", $month, $year, $lecturer_id);
            } else {
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ii", $month, $year);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $summary = [];
            $total_amount = 0;
            
            while ($row = $result->fetch_assoc()) {
                $row['courses'] = json_decode($row['courses_data'] ?? '[]', true);
                $summary[] = $row;
                $total_amount += $row['total_amount'];
            }
            
            $response['success'] = true;
            $response['data'] = [
                'requests' => $summary,
                'total_amount' => $total_amount,
                'total_count' => count($summary),
                'month' => $month,
                'year' => $year
            ];
            break;

        default:
            $response['message'] = 'Invalid action';
    }

} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

$conn->close();
echo json_encode($response);
?>