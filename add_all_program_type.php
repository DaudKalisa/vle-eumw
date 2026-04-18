<?php
/**
 * Migration: Add 'all' to exam_clearance_invites.program_type ENUM
 * This allows creating a single clearance window for all program types
 */
require_once 'includes/config.php';
$conn = getDbConnection();
$changes = [];

// 1. Alter ENUM to include 'all'
$result = $conn->query("ALTER TABLE exam_clearance_invites MODIFY COLUMN program_type ENUM('all','degree','professional','masters','doctorate') NOT NULL DEFAULT 'degree'");
if ($result) {
    $changes[] = "Added 'all' to exam_clearance_invites.program_type ENUM";
} else {
    $changes[] = "Failed to alter ENUM: " . $conn->error;
}

echo "<h3>Migration: Add 'all' program type</h3><ul>";
foreach ($changes as $c) echo "<li>$c</li>";
echo "</ul><p>Done.</p>";
