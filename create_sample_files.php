<?php
/**
 * Create Sample Media Files
 * Creates placeholder files for course content (images, PDFs, presentations, etc.)
 * 
 * Run: http://localhost/vle-eumw/create_sample_files.php
 */

require_once 'includes/config.php';

set_time_limit(300);

// Directory structure
$directories = [
    'uploads',
    'uploads/presentations',
    'uploads/documents', 
    'uploads/videos',
    'uploads/audio',
    'uploads/images',
    'uploads/assignments',
    'uploads/marked_assignments',
    'uploads/course_materials',
];

echo '<!DOCTYPE html><html><head><title>Create Sample Files</title>';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">';
echo '</head><body class="p-4"><div class="container">';
echo '<h1 class="mb-4">📁 Create Sample Media Files</h1>';

// Create directories
echo '<h4>Creating directories...</h4>';
foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "<p class='text-success'>✓ Created: $dir</p>";
        } else {
            echo "<p class='text-danger'>✗ Failed to create: $dir</p>";
        }
    } else {
        echo "<p class='text-muted'>• Already exists: $dir</p>";
    }
}

// Create placeholder text files to represent different media types
echo '<h4 class="mt-4">Creating sample placeholder files...</h4>';

// Sample presentation placeholder
$pptx_content = "This is a placeholder for a PowerPoint presentation.\n\nIn production, this would be an actual .pptx file with:\n- Lecture slides\n- Images and diagrams\n- Course content\n\nTo add real presentations, upload them through the Lecturer Dashboard.";

$presentations = [
    'Week_1_Introduction.pptx.txt' => $pptx_content,
    'Week_2_Fundamentals.pptx.txt' => $pptx_content,
    'Week_3_Core_Concepts.pptx.txt' => $pptx_content,
    'Week_4_Advanced_Topics.pptx.txt' => $pptx_content,
    'Course_Overview.pptx.txt' => $pptx_content,
];

foreach ($presentations as $file => $content) {
    $path = "uploads/presentations/$file";
    if (file_put_contents($path, $content)) {
        echo "<p class='text-success'>✓ Created: $path</p>";
    }
}

// Sample document placeholder  
$pdf_content = "This is a placeholder for a PDF document.\n\nIn production, this would be an actual .pdf file with:\n- Reading materials\n- Study guides\n- Course notes\n- Reference documents\n\nTo add real documents, upload them through the Lecturer Dashboard.";

$documents = [
    'Study_Guide_Week1.pdf.txt' => $pdf_content,
    'Reading_Material_Chapter1.pdf.txt' => $pdf_content,
    'Course_Syllabus.pdf.txt' => $pdf_content,
    'Assignment_Guidelines.pdf.txt' => $pdf_content,
    'Reference_Notes.pdf.txt' => $pdf_content,
];

foreach ($documents as $file => $content) {
    $path = "uploads/documents/$file";
    if (file_put_contents($path, $content)) {
        echo "<p class='text-success'>✓ Created: $path</p>";
    }
}

// Sample video placeholder
$video_content = "This is a placeholder for a video file.\n\nIn production, this would be:\n- An actual .mp4 video file, OR\n- A YouTube/Vimeo embed URL\n\nFor the VLE system, videos can be:\n1. Uploaded as files (.mp4, .webm)\n2. Embedded via URL (YouTube, Vimeo)\n3. Recorded live sessions\n\nTo add real videos, upload them through the Lecturer Dashboard or provide external URLs.";

$videos = [
    'Lecture_1_Introduction.mp4.txt' => $video_content,
    'Tutorial_Week_2.mp4.txt' => $video_content,
    'Demo_Practical.mp4.txt' => $video_content,
];

foreach ($videos as $file => $content) {
    $path = "uploads/videos/$file";
    if (file_put_contents($path, $content)) {
        echo "<p class='text-success'>✓ Created: $path</p>";
    }
}

// Sample audio placeholder
$audio_content = "This is a placeholder for an audio file.\n\nIn production, this would be an actual .mp3 or .wav file with:\n- Lecture recordings\n- Podcast episodes\n- Discussion recordings\n\nTo add real audio files, upload them through the Lecturer Dashboard.";

$audios = [
    'Lecture_Recording_Week1.mp3.txt' => $audio_content,
    'Podcast_Episode_1.mp3.txt' => $audio_content,
    'Discussion_Recording.mp3.txt' => $audio_content,
];

foreach ($audios as $file => $content) {
    $path = "uploads/audio/$file";
    if (file_put_contents($path, $content)) {
        echo "<p class='text-success'>✓ Created: $path</p>";
    }
}

// Create a sample image file (1x1 pixel transparent PNG as base64)
$png_data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

$images = [
    'sample_diagram_1.png',
    'sample_diagram_2.png',
    'course_banner.png',
    'lecture_image.png',
];

foreach ($images as $file) {
    $path = "uploads/images/$file";
    if (file_put_contents($path, $png_data)) {
        echo "<p class='text-success'>✓ Created placeholder image: $path</p>";
    }
}

// Create sample HTML content files
echo '<h4 class="mt-4">Creating sample HTML content pages...</h4>';

$html_template = '<!DOCTYPE html>
<html>
<head>
    <title>Sample Content: %s</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        h1 { color: #002147; }
        .content { background: #f5f5f5; padding: 20px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>%s</h1>
    <div class="content">
        <p>This is sample course content for demonstration purposes.</p>
        <h3>Learning Objectives</h3>
        <ul>
            <li>Understand key concepts and terminology</li>
            <li>Apply theoretical knowledge to practical scenarios</li>
            <li>Analyze case studies and examples</li>
            <li>Evaluate different approaches and solutions</li>
        </ul>
        <h3>Key Topics</h3>
        <ol>
            <li>Introduction to the subject</li>
            <li>Fundamental principles</li>
            <li>Core theories and models</li>
            <li>Practical applications</li>
            <li>Case studies and examples</li>
        </ol>
        <p><em>Note: This is placeholder content. Real course materials would include detailed explanations, diagrams, examples, and interactive elements.</em></p>
    </div>
</body>
</html>';

$html_pages = [
    'Week_1_Introduction' => 'Week 1: Introduction to the Course',
    'Week_2_Fundamentals' => 'Week 2: Fundamental Concepts',
    'Week_3_Advanced' => 'Week 3: Advanced Topics',
    'Study_Guide' => 'Course Study Guide',
];

foreach ($html_pages as $filename => $title) {
    $content = sprintf($html_template, $title, $title);
    $path = "uploads/course_materials/{$filename}.html";
    if (file_put_contents($path, $content)) {
        echo "<p class='text-success'>✓ Created: $path</p>";
    }
}

echo '<hr>';
echo '<h4 class="text-success">✓ Sample files created successfully!</h4>';
echo '<p>These are placeholder files. To add real course content:</p>';
echo '<ol>';
echo '<li>Login as a Lecturer</li>';
echo '<li>Go to a course in your dashboard</li>';
echo '<li>Click "Add Content" for any week</li>';
echo '<li>Upload actual presentations, documents, videos, or audio files</li>';
echo '</ol>';

echo '<div class="mt-4">';
echo '<a href="setup_sample_content.php" class="btn btn-primary">Run Sample Content Setup</a> ';
echo '<a href="lecturer/dashboard.php" class="btn btn-success">Go to Lecturer Dashboard</a>';
echo '</div>';

echo '</div></body></html>';
?>
