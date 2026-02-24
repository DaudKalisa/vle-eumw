<?php
/**
 * Finance Permissions Helper
 * Handles permission checks for finance-related operations
 * 
 * Exploits University Malawi VLE v16.0.1
 */

if (!defined('FINANCE_PERMISSIONS_LOADED')) {
    define('FINANCE_PERMISSIONS_LOADED', true);
}

/**
 * Check if user has finance role access
 * @param array|null $user Current user array
 * @return bool
 */
function hasFinanceAccess($user = null) {
    if (!$user && function_exists('getCurrentUser')) {
        $user = getCurrentUser();
    }
    if (!$user) return false;
    
    $finance_roles = ['finance', 'admin', 'administrator'];
    return in_array(strtolower($user['role'] ?? ''), $finance_roles);
}

/**
 * Check if a student has met minimum payment threshold for content access
 * @param mysqli $conn Database connection
 * @param int $student_id Student ID
 * @param float $threshold Minimum payment percentage (default 50%)
 * @return array ['has_access' => bool, 'percentage' => float, 'total_paid' => float, 'expected_total' => float]
 */
function checkPaymentAccess($conn, $student_id, $threshold = 50.0) {
    $result = [
        'has_access' => false,
        'percentage' => 0,
        'total_paid' => 0,
        'expected_total' => 0,
        'balance_due' => 0
    ];
    
    // Get expected total fees
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as expected_total FROM student_fees WHERE student_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $result['expected_total'] = floatval($row['expected_total'] ?? 0);
        $stmt->close();
    }
    
    // Get total payments
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM payments WHERE student_id = ? AND status = 'approved'");
    if ($stmt) {
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $result['total_paid'] = floatval($row['total_paid'] ?? 0);
        $stmt->close();
    }
    
    // Calculate percentage and access
    if ($result['expected_total'] > 0) {
        $result['percentage'] = round(($result['total_paid'] / $result['expected_total']) * 100, 1);
    }
    $result['balance_due'] = max(0, $result['expected_total'] - $result['total_paid']);
    $result['has_access'] = $result['percentage'] >= $threshold;
    
    return $result;
}
?>