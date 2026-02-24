<?php
// setup_university_settings.php - Create university settings table
require_once 'includes/config.php';

$conn = getDbConnection();

// Create university_settings table
$sql = "CREATE TABLE IF NOT EXISTS university_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    university_name VARCHAR(255) NOT NULL DEFAULT 'Exploits University',
    address_po_box VARCHAR(100) DEFAULT 'P.O.Box 301752',
    address_area VARCHAR(100) DEFAULT 'Area 4',
    address_street VARCHAR(100) DEFAULT '',
    address_city VARCHAR(100) DEFAULT 'Lilongwe',
    address_country VARCHAR(100) DEFAULT 'Malawi',
    phone VARCHAR(50) DEFAULT '',
    email VARCHAR(100) DEFAULT '',
    website VARCHAR(100) DEFAULT '',
    logo_path VARCHAR(255) DEFAULT '',
    receipt_footer_text TEXT DEFAULT 'Thank you for your payment',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "✅ University settings table created successfully!<br>";
    
    // Insert default values
    $check = $conn->query("SELECT COUNT(*) as count FROM university_settings");
    $row = $check->fetch_assoc();
    
    if ($row['count'] == 0) {
        $insert = "INSERT INTO university_settings (
            university_name, 
            address_po_box, 
            address_area, 
            address_street, 
            address_city, 
            address_country
        ) VALUES (
            'Exploits University',
            'P.O.Box 301752',
            'Area 4',
            '',
            'Lilongwe',
            'Malawi'
        )";
        
        if ($conn->query($insert) === TRUE) {
            echo "✅ Default university settings inserted!<br>";
        } else {
            echo "❌ Error inserting default settings: " . $conn->error . "<br>";
        }
    } else {
        echo "ℹ️ University settings already exist.<br>";
    }
} else {
    echo "❌ Error creating table: " . $conn->error . "<br>";
}

echo "<br><a href='admin/university_settings.php'>Go to University Settings</a>";
?>
