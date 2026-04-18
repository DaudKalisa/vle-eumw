<?php
require_once 'includes/config.php';
$conn = getDbConnection();

// Show table structure
echo "Users table columns:\n";
$cols = $conn->query("DESCRIBE users");
$columns = [];
while ($col = $cols->fetch_assoc()) {
    echo "  - {$col['Field']}\n";
    $columns[] = $col['Field'];
}

// Find correct column names
$pwd_col = in_array('password_hash', $columns) ? 'password_hash' : 'password';
$name_col = in_array('full_name', $columns) ? 'full_name' : (in_array('name', $columns) ? 'name' : null);

echo "\nPassword column: $pwd_col\n";
echo "Name column: $name_col\n\n";

// Create dean user
$password = password_hash('dean123', PASSWORD_DEFAULT);
$dean_name = 'Dr. James Mwangi';

// Check if user exists
$check = $conn->query("SELECT user_id FROM users WHERE username = 'dean_faculty'");
if ($check && $check->num_rows > 0) {
    $existing = $check->fetch_assoc();
    $user_id = $existing['user_id'];
    $update_sql = "UPDATE users SET role = 'dean', $pwd_col = '$password'";
    if ($name_col) $update_sql .= ", $name_col = '$dean_name'";
    $update_sql .= " WHERE user_id = $user_id";
    $conn->query($update_sql);
    echo "Dean user updated. User ID: $user_id\n";
} else {
    $insert_cols = "username, email, $pwd_col, role";
    $insert_vals = "'dean_faculty', 'dean@university.edu', '$password', 'dean'";
    if ($name_col) {
        $insert_cols .= ", $name_col";
        $insert_vals .= ", '$dean_name'";
    }
    if (in_array('created_at', $columns)) {
        $insert_cols .= ", created_at";
        $insert_vals .= ", NOW()";
    }
    
    $sql = "INSERT INTO users ($insert_cols) VALUES ($insert_vals)";
    if ($conn->query($sql)) {
        $user_id = $conn->insert_id;
        echo "Dean user created. User ID: $user_id\n";
    } else {
        die("Error creating user: " . $conn->error);
    }
}

// Link to a faculty if faculties table exists
$check = $conn->query("SHOW TABLES LIKE 'faculties'");
if ($check && $check->num_rows > 0) {
    $faculty = $conn->query("SELECT faculty_id, faculty_name FROM faculties LIMIT 1");
    if ($faculty && $faculty->num_rows > 0) {
        $f = $faculty->fetch_assoc();
        $conn->query("UPDATE faculties SET head_of_faculty = $user_id WHERE faculty_id = {$f['faculty_id']}");
        echo "Linked to faculty: {$f['faculty_name']}\n";
    } else {
        echo "No faculties found. Create a faculty first.\n";
    }
}

echo "\n=== Login Credentials ===\n";
echo "Username: dean_faculty\n";
echo "Password: dean123\n";
echo "URL: /vle-eumw/login.php\n";
