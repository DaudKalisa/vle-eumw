<?php
require 'includes/config.php';
require 'includes/auth.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Error: You must be logged in to view this page.");
}

echo "<h2>Finance Claims Workflow Test</h2>\n";
echo "<p>Testing with actual submitted claims from the database...</p>\n";

// Get sample claims
$query = "SELECT request_id, lecturer_name, position_title, status, odl_approval_status, dean_approval_status FROM lecturer_finance_requests LIMIT 10";
$result = mysqli_query($conn, $query);

if (!$result) {
    die("Database error: " . mysqli_error($conn));
}

if (mysqli_num_rows($result) == 0) {
    echo "<p style='color: orange;'>No claims found in the system yet.</p>";
} else {
    echo "<table border='1' cellpadding='10' style='margin-top: 20px;'>";
    echo "<tr><th>Request ID</th><th>Lecturer Name</th><th>Status</th><th>ODL Approval</th><th>Dean Approval</th><th>Test Link</th></tr>";
    
    while ($row = mysqli_fetch_assoc($result)) {
        $request_id = $row['request_id'];
        echo "<tr>";
        echo "<td>" . htmlspecialchars($request_id) . "</td>";
        echo "<td>" . htmlspecialchars($row['lecturer_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . htmlspecialchars($row['odl_approval_status']) . "</td>";
        echo "<td>" . htmlspecialchars($row['dean_approval_status']) . "</td>";
        echo "<td>";
        
        // Show test links based on user role
        $user_role = $_SESSION['role'] ?? 'unknown';
        
        if ($user_role == 'odl_coordinator') {
            echo "<a href='odl_coordinator/print_claim.php?id=" . $request_id . "' target='_blank'>View & Approve (ODL)</a>";
        } elseif ($user_role == 'dean') {
            echo "<a href='dean/print_claim.php?id=" . $request_id . "' target='_blank'>View & Approve (Dean)</a>";
        } else {
            echo "<a href='finance/print_claim.php?id=" . $request_id . "' target='_blank'>View & Approve (Finance)</a>";
        }
        
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
}

echo "<hr>";
echo "<h3>Workflow Status Summary</h3>";
echo "<p><strong>Current User Role:</strong> " . htmlspecialchars($_SESSION['role'] ?? 'unknown') . "</p>";
echo "<p><strong>User ID:</strong> " . htmlspecialchars($_SESSION['user_id'] ?? 'unknown') . "</p>";

// Check signature status
$signatures_dir = 'uploads/signatures';
if (is_dir($signatures_dir)) {
    $files = scandir($signatures_dir);
    $sig_count = count($files) - 2; // Exclude . and ..
    echo "<p><strong>Stored Signatures:</strong> " . $sig_count . " file(s)</p>";
}

?>
