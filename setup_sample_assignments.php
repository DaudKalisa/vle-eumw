<?php
/**
 * Setup Sample Assignments for All Modules
 * Creates assignments for all 6 modules across all weeks
 */

require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Setting Up Sample Assignments for All Modules</h2>";

// Get all active courses
$courses = [];
$result = $conn->query("SELECT course_id, course_name, course_code, total_weeks FROM vle_courses WHERE is_active = 1 ORDER BY course_id");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

echo "<h3>Found " . count($courses) . " Active Courses</h3>";

if (count($courses) == 0) {
    echo "<p style='color: red;'>No active courses found. Please create courses first.</p>";
    exit;
}

// Display courses
echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Code</th><th>Name</th><th>Weeks</th></tr>";
foreach ($courses as $c) {
    echo "<tr><td>{$c['course_id']}</td><td>{$c['course_code']}</td><td>" . htmlspecialchars($c['course_name']) . "</td><td>{$c['total_weeks']}</td></tr>";
}
echo "</table>";

// Define assignment templates for each week type
$assignment_templates = [
    // Week 1-3: Formative assignments
    'formative' => [
        ['title' => 'Weekly Quiz', 'description' => 'Complete the quiz covering this week\'s lecture material. Multiple choice and short answer questions.', 'type' => 'formative', 'max_score' => 100],
        ['title' => 'Reading Reflection', 'description' => 'Write a 300-word reflection on the assigned readings for this week.', 'type' => 'formative', 'max_score' => 100],
    ],
    // Week 4: Summative Assignment 1
    'summative1' => [
        ['title' => 'Summative Assignment 1', 'description' => 'First comprehensive assessment covering Weeks 1-4 content. Submit a well-structured essay or report demonstrating your understanding of the core concepts.', 'type' => 'summative', 'max_score' => 100],
    ],
    // Week 5-7: Formative
    'formative_mid' => [
        ['title' => 'Weekly Practice Exercise', 'description' => 'Complete the practice exercises to reinforce your understanding of the week\'s material.', 'type' => 'formative', 'max_score' => 100],
    ],
    // Week 8: Mid-semester Exam
    'midsem' => [
        ['title' => 'Mid-Semester Examination', 'description' => 'Mid-semester examination covering all content from Weeks 1-8. This is a timed assessment worth 20% of your final grade.', 'type' => 'mid_sem', 'max_score' => 100],
    ],
    // Week 9-11: Formative
    'formative_late' => [
        ['title' => 'Case Study Analysis', 'description' => 'Analyze the provided case study and answer the guiding questions.', 'type' => 'formative', 'max_score' => 100],
    ],
    // Week 12: Summative Assignment 2
    'summative2' => [
        ['title' => 'Summative Assignment 2', 'description' => 'Second comprehensive assessment covering Weeks 5-12 content. This assignment tests your ability to apply advanced concepts and critical thinking skills.', 'type' => 'summative', 'max_score' => 100],
    ],
    // Week 13-15: Formative
    'formative_final' => [
        ['title' => 'Exam Preparation Exercise', 'description' => 'Complete the revision exercises in preparation for the final examination.', 'type' => 'formative', 'max_score' => 100],
    ],
    // Week 16: Final Exam
    'final' => [
        ['title' => 'Final End-of-Semester Examination', 'description' => 'Comprehensive final examination covering all course content from Weeks 1-16. This examination is worth 60% of your final grade.', 'type' => 'final_exam', 'max_score' => 100],
    ],
];

// Week-specific topics for different types of courses
$course_specific_content = [
    // Generic template that can be customized per course
    'default' => [
        1 => 'Introduction and Fundamentals',
        2 => 'Core Concepts and Principles',
        3 => 'Application of Basic Theories',
        4 => 'Review and Assessment 1',
        5 => 'Intermediate Concepts',
        6 => 'Advanced Applications',
        7 => 'Analysis Techniques',
        8 => 'Mid-Semester Review',
        9 => 'Specialized Topics Part 1',
        10 => 'Specialized Topics Part 2',
        11 => 'Integration of Concepts',
        12 => 'Review and Assessment 2',
        13 => 'Advanced Analysis',
        14 => 'Contemporary Issues',
        15 => 'Course Synthesis',
        16 => 'Final Examination',
    ]
];

// Prepare insert statement
$stmt = $conn->prepare("
    INSERT INTO vle_assignments 
    (course_id, week_number, title, description, assignment_type, max_score, passing_score, due_date, is_active) 
    VALUES (?, ?, ?, ?, ?, ?, 50, ?, TRUE)
    ON DUPLICATE KEY UPDATE 
    title = VALUES(title),
    description = VALUES(description),
    assignment_type = VALUES(assignment_type),
    max_score = VALUES(max_score)
");

if (!$stmt) {
    echo "<p style='color: red;'>Error preparing statement: " . $conn->error . "</p>";
    exit;
}

$total_added = 0;
$base_date = new DateTime('2026-03-01');

foreach ($courses as $course) {
    $course_id = $course['course_id'];
    $course_name = $course['course_name'];
    $total_weeks = min($course['total_weeks'], 16); // Cap at 16 weeks
    
    echo "<h4>Processing: " . htmlspecialchars($course_name) . " (ID: $course_id)</h4>";
    echo "<ul>";
    
    for ($week = 1; $week <= $total_weeks; $week++) {
        // Determine which assignment template to use based on week
        $template_key = 'formative';
        if ($week == 4) {
            $template_key = 'summative1';
        } elseif ($week == 8) {
            $template_key = 'midsem';
        } elseif ($week == 12) {
            $template_key = 'summative2';
        } elseif ($week == 16) {
            $template_key = 'final';
        } elseif ($week > 12) {
            $template_key = 'formative_final';
        } elseif ($week > 8) {
            $template_key = 'formative_late';
        } elseif ($week > 4) {
            $template_key = 'formative_mid';
        }
        
        $assignments = $assignment_templates[$template_key];
        $week_topic = $course_specific_content['default'][$week] ?? "Week $week Content";
        
        foreach ($assignments as $assignment) {
            // Customize title and description with course name and week topic
            $title = "Week $week: " . $assignment['title'];
            $description = $assignment['description'] . "\n\nTopic Focus: $week_topic\nCourse: " . $course_name;
            
            // Calculate due date (2 weeks from start of each week)
            $due_date = clone $base_date;
            $due_date->modify("+$week weeks");
            $due_date->modify("+6 days"); // Due at end of week
            $due_str = $due_date->format('Y-m-d 23:59:59');
            
            $type = $assignment['type'];
            $max_score = $assignment['max_score'];
            $stmt->bind_param(
                "iisssis",
                $course_id,
                $week,
                $title,
                $description,
                $type,
                $max_score,
                $due_str
            );
            
            if ($stmt->execute()) {
                $total_added++;
                echo "<li style='color: green;'>✓ Week $week: " . htmlspecialchars($assignment['title']) . "</li>";
            } else {
                echo "<li style='color: red;'>✗ Week $week: Failed - " . $stmt->error . "</li>";
            }
        }
    }
    echo "</ul>";
}

$stmt->close();

// Summary
echo "<hr>";
echo "<h3>Summary</h3>";
echo "<p style='color: green;'><strong>Total assignments created/updated: $total_added</strong></p>";

// Show assignment counts per course
echo "<h4>Assignments per Course:</h4>";
echo "<table border='1' cellpadding='5'><tr><th>Course</th><th>Assignment Count</th></tr>";
$result = $conn->query("
    SELECT vc.course_name, COUNT(va.assignment_id) as cnt 
    FROM vle_courses vc 
    LEFT JOIN vle_assignments va ON vc.course_id = va.course_id 
    WHERE vc.is_active = 1
    GROUP BY vc.course_id
    ORDER BY vc.course_id
");
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>" . htmlspecialchars($row['course_name']) . "</td><td>{$row['cnt']}</td></tr>";
}
echo "</table>";

echo "<hr>";
echo "<p><a href='student/course_content.php?course_id=100'>View Course 100 Content</a></p>";
echo "<p><a href='index.php'>Return to Home</a></p>";

$conn->close();
?>
