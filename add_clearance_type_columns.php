<?php
/**
 * Database Migration: Add clearance_type, amount_paid, proof_request_type, required_amount
 * to exam_clearance_students and clearance_type to exam_clearance_invites
 * Run once via browser, then delete.
 */
require_once 'includes/config.php';
$conn = getDbConnection();

$changes = [];

// 1. Add clearance_type to exam_clearance_students
$col = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'clearance_type'");
if ($col && $col->num_rows === 0) {
    if ($conn->query("ALTER TABLE exam_clearance_students ADD COLUMN clearance_type ENUM('midsemester','endsemester') NOT NULL DEFAULT 'endsemester' AFTER status")) {
        $changes[] = "Added clearance_type to exam_clearance_students";
    } else {
        $changes[] = "FAILED clearance_type: " . $conn->error;
    }
} else {
    $changes[] = "clearance_type already exists in exam_clearance_students";
}

// 2. Add amount_paid to exam_clearance_students
$col = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'amount_paid'");
if ($col && $col->num_rows === 0) {
    if ($conn->query("ALTER TABLE exam_clearance_students ADD COLUMN amount_paid DECIMAL(12,2) DEFAULT 0.00 AFTER balance")) {
        $changes[] = "Added amount_paid to exam_clearance_students";
    } else {
        $changes[] = "FAILED amount_paid: " . $conn->error;
    }
} else {
    $changes[] = "amount_paid already exists in exam_clearance_students";
}

// 3. Add proof_request_type to exam_clearance_students
$col = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'proof_request_type'");
if ($col && $col->num_rows === 0) {
    if ($conn->query("ALTER TABLE exam_clearance_students ADD COLUMN proof_request_type ENUM('tuition','registration','both') DEFAULT NULL AFTER amount_paid")) {
        $changes[] = "Added proof_request_type to exam_clearance_students";
    } else {
        $changes[] = "FAILED proof_request_type: " . $conn->error;
    }
} else {
    $changes[] = "proof_request_type already exists in exam_clearance_students";
}

// 4. Add required_amount to exam_clearance_students
$col = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'required_amount'");
if ($col && $col->num_rows === 0) {
    if ($conn->query("ALTER TABLE exam_clearance_students ADD COLUMN required_amount DECIMAL(12,2) DEFAULT NULL AFTER proof_request_type")) {
        $changes[] = "Added required_amount to exam_clearance_students";
    } else {
        $changes[] = "FAILED required_amount: " . $conn->error;
    }
} else {
    $changes[] = "required_amount already exists in exam_clearance_students";
}

// 5. Add revenue_recorded to exam_clearance_students (flag to avoid double revenue insert)
$col = $conn->query("SHOW COLUMNS FROM exam_clearance_students LIKE 'revenue_recorded'");
if ($col && $col->num_rows === 0) {
    if ($conn->query("ALTER TABLE exam_clearance_students ADD COLUMN revenue_recorded TINYINT(1) DEFAULT 0 AFTER required_amount")) {
        $changes[] = "Added revenue_recorded to exam_clearance_students";
    } else {
        $changes[] = "FAILED revenue_recorded: " . $conn->error;
    }
} else {
    $changes[] = "revenue_recorded already exists in exam_clearance_students";
}

// 6. Add clearance_type to exam_clearance_invites
$col = $conn->query("SHOW COLUMNS FROM exam_clearance_invites LIKE 'clearance_type'");
if ($col && $col->num_rows === 0) {
    if ($conn->query("ALTER TABLE exam_clearance_invites ADD COLUMN clearance_type ENUM('midsemester','endsemester') NOT NULL DEFAULT 'endsemester' AFTER program_type")) {
        $changes[] = "Added clearance_type to exam_clearance_invites";
    } else {
        $changes[] = "FAILED clearance_type on invites: " . $conn->error;
    }
} else {
    $changes[] = "clearance_type already exists in exam_clearance_invites";
}

// 7. Add minimum_payment_percent to exam_clearance_invites
$col = $conn->query("SHOW COLUMNS FROM exam_clearance_invites LIKE 'minimum_payment_percent'");
if ($col && $col->num_rows === 0) {
    if ($conn->query("ALTER TABLE exam_clearance_invites ADD COLUMN minimum_payment_percent INT DEFAULT 100 AFTER clearance_type")) {
        $changes[] = "Added minimum_payment_percent to exam_clearance_invites";
    } else {
        $changes[] = "FAILED minimum_payment_percent: " . $conn->error;
    }
} else {
    $changes[] = "minimum_payment_percent already exists in exam_clearance_invites";
}

echo "<h2>Database Migration: Exam Clearance Type Columns</h2>";
echo "<ul>";
foreach ($changes as $c) {
    $color = strpos($c, 'FAILED') !== false ? 'red' : 'green';
    echo "<li style='color:$color'>$c</li>";
}
echo "</ul>";
echo "<p><strong>Migration complete.</strong> You can delete this file now.</p>";
$conn->close();
