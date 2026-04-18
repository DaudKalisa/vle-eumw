<?php
/**
 * Fix Course Content Files
 * Creates actual sample files for course 100 content
 */

require_once 'includes/config.php';

$conn = getDbConnection();

echo "<h2>Fixing Course 100 Content Files</h2>";

$uploads_dir = __DIR__ . '/uploads/';

// Get all content records with file paths for course 100
$result = $conn->query("
    SELECT content_id, course_id, week_number, title, file_path, file_name, content_type 
    FROM vle_weekly_content 
    WHERE course_id = 100 AND file_path IS NOT NULL AND file_path != ''
    ORDER BY week_number
");

$created = 0;
$exists = 0;
$failed = 0;

if ($result && $result->num_rows > 0) {
    echo "<h4>Processing " . $result->num_rows . " content items</h4>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Week</th><th>Title</th><th>Type</th><th>File</th><th>Status</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        $file_path = $uploads_dir . $row['file_path'];
        $ext = strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION));
        
        if (file_exists($file_path)) {
            echo "<tr><td>{$row['week_number']}</td><td>" . htmlspecialchars($row['title']) . "</td><td>{$row['content_type']}</td><td>{$row['file_path']}</td><td style='color:blue;'>Exists</td></tr>";
            $exists++;
            continue;
        }
        
        // Create appropriate sample content based on file type
        $content = generateSampleContent($row, $ext);
        
        // Write the file
        if (file_put_contents($file_path, $content) !== false) {
            echo "<tr><td>{$row['week_number']}</td><td>" . htmlspecialchars($row['title']) . "</td><td>{$row['content_type']}</td><td>{$row['file_path']}</td><td style='color:green;'>Created</td></tr>";
            $created++;
        } else {
            echo "<tr><td>{$row['week_number']}</td><td>" . htmlspecialchars($row['title']) . "</td><td>{$row['content_type']}</td><td>{$row['file_path']}</td><td style='color:red;'>Failed</td></tr>";
            $failed++;
        }
    }
    echo "</table>";
}

function generateSampleContent($row, $ext) {
    $title = $row['title'];
    $week = $row['week_number'];
    $type = $row['content_type'];
    
    // For PDF files - create a simple PDF-like text document
    if ($ext === 'pdf') {
        return generateSamplePDF($title, $week);
    }
    
    // For PowerPoint - create a readable text summary
    if (in_array($ext, ['ppt', 'pptx'])) {
        return generateSamplePPT($title, $week);
    }
    
    // For Word docs
    if (in_array($ext, ['doc', 'docx'])) {
        return generateSampleDoc($title, $week);
    }
    
    // For video/audio - create info text file
    if (in_array($ext, ['mp4', 'webm', 'mp3', 'wav'])) {
        return generateSampleMedia($title, $week, $type);
    }
    
    // Default text content
    return "Sample content for: $title\nWeek: $week\nType: $type\n";
}

function generateSamplePDF($title, $week) {
    // Create a more realistic text representation
    $content = "=" . str_repeat("=", 70) . "\n";
    $content .= "  BUSINESS MATHEMATICS I - Week $week\n";
    $content .= "  $title\n";
    $content .= "=" . str_repeat("=", 70) . "\n\n";
    
    $content .= "COURSE: Business Mathematics I (BBA 1103)\n";
    $content .= "WEEK: $week of 16\n";
    $content .= "DATE: " . date('F Y') . "\n\n";
    
    $content .= str_repeat("-", 72) . "\n";
    $content .= "LEARNING OBJECTIVES\n";
    $content .= str_repeat("-", 72) . "\n";
    $content .= "By the end of this week, students should be able to:\n\n";
    $content .= "1. Understand fundamental mathematical concepts for business\n";
    $content .= "2. Apply quantitative methods to business problems\n";
    $content .= "3. Analyze data using statistical techniques\n";
    $content .= "4. Interpret mathematical results in business context\n\n";
    
    $content .= str_repeat("-", 72) . "\n";
    $content .= "KEY TOPICS\n";
    $content .= str_repeat("-", 72) . "\n";
    $content .= "- Introduction to business mathematics principles\n";
    $content .= "- Mathematical notation and terminology\n";
    $content .= "- Problem-solving strategies\n";
    $content .= "- Practical applications in business\n\n";
    
    $content .= str_repeat("-", 72) . "\n";
    $content .= "STUDY MATERIALS\n";
    $content .= str_repeat("-", 72) . "\n";
    $content .= "Required Reading:\n";
    $content .= "  - Course Textbook: Chapters relevant to Week $week\n";
    $content .= "  - Lecture notes and slides\n";
    $content .= "  - Practice worksheets\n\n";
    
    $content .= "Additional Resources:\n";
    $content .= "  - Online tutorials via the VLE\n";
    $content .= "  - Discussion forums\n";
    $content .= "  - Office hours with your lecturer\n\n";
    
    $content .= str_repeat("=", 72) . "\n";
    $content .= "This document is sample content for the VLE system.\n";
    $content .= "Lecturers should upload actual course materials.\n";
    $content .= str_repeat("=", 72) . "\n";
    
    return $content;
}

function generateSamplePPT($title, $week) {
    $content = "================================================================================\n";
    $content .= "PRESENTATION: $title\n";
    $content .= "Week $week - Business Mathematics I\n";
    $content .= "================================================================================\n\n";
    
    $content .= "SLIDE 1: Title Slide\n";
    $content .= "-------------------\n";
    $content .= "  $title\n";
    $content .= "  Business Mathematics I\n";
    $content .= "  Week $week\n\n";
    
    $content .= "SLIDE 2: Learning Objectives\n";
    $content .= "----------------------------\n";
    $content .= "  - Objective 1: Understand core concepts\n";
    $content .= "  - Objective 2: Apply mathematical techniques\n";
    $content .= "  - Objective 3: Solve practical problems\n\n";
    
    $content .= "SLIDE 3: Key Concepts\n";
    $content .= "---------------------\n";
    $content .= "  - Concept A: Definition and examples\n";
    $content .= "  - Concept B: Applications\n";
    $content .= "  - Concept C: Practice exercises\n\n";
    
    $content .= "SLIDE 4: Summary\n";
    $content .= "----------------\n";
    $content .= "  - Review key points\n";
    $content .= "  - Prepare for next week\n";
    $content .= "  - Complete assigned exercises\n\n";
    
    $content .= "================================================================================\n";
    $content .= "NOTE: This is a sample presentation placeholder.\n";
    $content .= "Upload actual PowerPoint files through the Lecturer Dashboard.\n";
    $content .= "================================================================================\n";
    
    return $content;
}

function generateSampleDoc($title, $week) {
    $content = "DOCUMENT: $title\n";
    $content .= "Week $week\n";
    $content .= str_repeat("=", 50) . "\n\n";
    
    $content .= "CONTENT OVERVIEW\n";
    $content .= str_repeat("-", 50) . "\n\n";
    $content .= "This document provides reading materials for Week $week.\n\n";
    
    $content .= "SECTIONS:\n";
    $content .= "1. Introduction\n";
    $content .= "2. Main Content\n";
    $content .= "3. Summary\n";
    $content .= "4. References\n\n";
    
    $content .= str_repeat("=", 50) . "\n";
    $content .= "Sample document - Replace with actual content.\n";
    $content .= str_repeat("=", 50) . "\n";
    
    return $content;
}

function generateSampleMedia($title, $week, $type) {
    $content = "================================================================================\n";
    $content .= strtoupper($type) . " FILE: $title\n";
    $content .= "Week $week\n";
    $content .= "================================================================================\n\n";
    
    if ($type === 'video') {
        $content .= "VIDEO LECTURE INFORMATION\n";
        $content .= str_repeat("-", 50) . "\n";
        $content .= "Duration: Approximately 30-45 minutes\n";
        $content .= "Topics Covered:\n";
        $content .= "  - Week $week main concepts\n";
        $content .= "  - Practical demonstrations\n";
        $content .= "  - Problem-solving examples\n\n";
    } else {
        $content .= "AUDIO LECTURE INFORMATION\n";
        $content .= str_repeat("-", 50) . "\n";
        $content .= "Duration: Approximately 20-30 minutes\n";
        $content .= "Content:\n";
        $content .= "  - Discussion of Week $week topics\n";
        $content .= "  - Key points and explanations\n\n";
    }
    
    $content .= str_repeat("=", 50) . "\n";
    $content .= "This is a placeholder file.\n";
    $content .= "Upload actual media files through the Lecturer Dashboard.\n";
    $content .= str_repeat("=", 50) . "\n";
    
    return $content;
}

// Summary
echo "<hr>";
echo "<h3>Summary</h3>";
echo "<p><strong>Files created:</strong> $created</p>";
echo "<p><strong>Files already exist:</strong> $exists</p>";
echo "<p><strong>Failed:</strong> $failed</p>";

// Test link
echo "<hr>";
echo "<h3>Test Links</h3>";
echo "<p><a href='student/course_content.php?course_id=100' class='btn btn-primary'>View Course 100 Content (Student View)</a></p>";
echo "<p><a href='lecturer/manage_content.php?course_id=100' class='btn btn-success'>Manage Course 100 Content (Lecturer View)</a></p>";

$conn->close();
?>
