<?php
/**
 * Setup Week Summaries Table
 * Creates and populates the vle_week_summaries table for storing
 * weekly topic summaries and learning objectives
 */

require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Setting Up Week Summaries Table</h2>";

// Create the vle_week_summaries table
$sql = "CREATE TABLE IF NOT EXISTS vle_week_summaries (
    summary_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    week_number INT NOT NULL,
    main_topics TEXT,
    learning_objectives TEXT,
    key_concepts TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES vle_courses(course_id) ON DELETE CASCADE,
    UNIQUE KEY unique_course_week (course_id, week_number),
    INDEX idx_course (course_id),
    INDEX idx_week (week_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "<p style='color: green;'>✓ vle_week_summaries table created successfully</p>";
} else {
    echo "<p style='color: red;'>✗ Error creating table: " . $conn->error . "</p>";
}

// Check if course 100 exists
$course_check = $conn->query("SELECT course_id, course_name FROM vle_courses WHERE course_id = 100");
if ($course_check && $course_check->num_rows > 0) {
    $course = $course_check->fetch_assoc();
    echo "<p>Found course: " . htmlspecialchars($course['course_name']) . " (ID: 100)</p>";
    
    // Insert sample week summaries for course 100
    $week_summaries = [
        [
            'week' => 1,
            'topics' => '<ul>
                <li>Introduction to Course Fundamentals</li>
                <li>Overview of Key Concepts and Terminology</li>
                <li>Understanding the Course Structure</li>
                <li>Getting Started with Basic Principles</li>
            </ul>',
            'objectives' => '<ul>
                <li>Understand the fundamental concepts and principles of the subject</li>
                <li>Identify key terminology and definitions used throughout the course</li>
                <li>Navigate the course structure and assessment requirements</li>
                <li>Apply introductory principles to basic scenarios</li>
                <li>Prepare for more advanced topics in subsequent weeks</li>
            </ul>',
            'concepts' => 'Foundation concepts, Core principles, Basic terminology, Course navigation'
        ],
        [
            'week' => 2,
            'topics' => '<ul>
                <li>Building on Week 1 Foundations</li>
                <li>Intermediate Concepts and Applications</li>
                <li>Practical Examples and Case Studies</li>
            </ul>',
            'objectives' => '<ul>
                <li>Build upon foundational knowledge from Week 1</li>
                <li>Analyze intermediate-level concepts</li>
                <li>Apply theories to practical examples</li>
            </ul>',
            'concepts' => 'Intermediate concepts, Practical applications, Case study analysis'
        ],
        [
            'week' => 3,
            'topics' => '<ul>
                <li>Advanced Theory Development</li>
                <li>Critical Analysis Methods</li>
                <li>Research and Investigation Techniques</li>
            </ul>',
            'objectives' => '<ul>
                <li>Develop advanced theoretical understanding</li>
                <li>Apply critical analysis to complex problems</li>
                <li>Conduct basic research investigations</li>
            </ul>',
            'concepts' => 'Advanced theory, Critical analysis, Research methods'
        ],
        [
            'week' => 4,
            'topics' => '<ul>
                <li>Summative Assignment Preparation</li>
                <li>Review of Weeks 1-3 Material</li>
                <li>Assessment Criteria and Guidelines</li>
            </ul>',
            'objectives' => '<ul>
                <li>Consolidate learning from weeks 1-3</li>
                <li>Demonstrate understanding through summative assessment</li>
                <li>Apply knowledge to assignment requirements</li>
            </ul>',
            'concepts' => 'Assessment preparation, Knowledge consolidation, Academic writing'
        ]
    ];
    
    $stmt = $conn->prepare("INSERT INTO vle_week_summaries (course_id, week_number, main_topics, learning_objectives, key_concepts) 
                            VALUES (?, ?, ?, ?, ?) 
                            ON DUPLICATE KEY UPDATE 
                            main_topics = VALUES(main_topics), 
                            learning_objectives = VALUES(learning_objectives),
                            key_concepts = VALUES(key_concepts)");
    
    foreach ($week_summaries as $summary) {
        $course_id = 100;
        $stmt->bind_param("iisss", $course_id, $summary['week'], $summary['topics'], $summary['objectives'], $summary['concepts']);
        if ($stmt->execute()) {
            echo "<p style='color: green;'>✓ Week {$summary['week']} summary added/updated for course 100</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding Week {$summary['week']} summary: " . $stmt->error . "</p>";
        }
    }
    $stmt->close();
} else {
    echo "<p style='color: orange;'>⚠ Course ID 100 not found. Please create the course first or adjust the course_id.</p>";
    
    // List available courses
    $courses = $conn->query("SELECT course_id, course_name FROM vle_courses ORDER BY course_id LIMIT 10");
    if ($courses && $courses->num_rows > 0) {
        echo "<p>Available courses:</p><ul>";
        while ($c = $courses->fetch_assoc()) {
            echo "<li>ID: {$c['course_id']} - " . htmlspecialchars($c['course_name']) . "</li>";
        }
        echo "</ul>";
        echo "<p>You can run this script again with a valid course_id or create course 100 first.</p>";
    }
}

echo "<hr>";
echo "<h3>Setup Complete</h3>";
echo "<p><a href='student/course_content.php?course_id=100'>View Course Content for Course 100</a></p>";
echo "<p><a href='index.php'>Return to Home</a></p>";

$conn->close();
?>
