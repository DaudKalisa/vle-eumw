<?php
/**
 * Setup Sample Content for VLE
 * Creates sample course materials, assignments, and exams for Year 1 Semester 1 courses
 * 
 * Run: http://localhost/vle-eumw/setup_sample_content.php
 */

require_once 'includes/config.php';

set_time_limit(300);
ini_set('memory_limit', '256M');

$conn = getDbConnection();

// Check if running from browser or CLI
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    echo '<!DOCTYPE html><html><head><title>Setup Sample Content</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<style>.log-success { color: green; } .log-info { color: blue; } .log-warning { color: orange; }</style>';
    echo '</head><body class="p-4"><div class="container">';
    echo '<h1 class="mb-4">🎓 Setup Sample Content for Year 1 Semester 1</h1>';
}

function logMsg($msg, $type = 'info') {
    global $is_cli;
    if ($is_cli) {
        echo ($type === 'success' ? '✓ ' : ($type === 'warning' ? '! ' : '  ')) . $msg . "\n";
    } else {
        echo "<p class='log-$type'>$msg</p>";
    }
    flush();
}

// Sample content templates
$content_templates = [
    'presentation' => [
        ['title' => 'Week %d Lecture Slides', 'desc' => 'Comprehensive lecture slides covering key concepts for week %d'],
        ['title' => 'Week %d Summary Presentation', 'desc' => 'Summary of main topics and learning objectives for week %d'],
    ],
    'video' => [
        ['title' => 'Week %d Video Lecture', 'desc' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        ['title' => 'Week %d Tutorial Video', 'desc' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
    ],
    'document' => [
        ['title' => 'Week %d Reading Material', 'desc' => 'Essential reading material and notes for week %d'],
        ['title' => 'Week %d Study Guide', 'desc' => 'Comprehensive study guide with examples and exercises'],
    ],
    'link' => [
        ['title' => 'Week %d Online Resources', 'desc' => 'https://www.khanacademy.org/'],
        ['title' => 'Week %d Reference Materials', 'desc' => 'https://www.coursera.org/'],
    ],
    'text' => [
        ['title' => 'Week %d Learning Objectives', 'desc' => 'By the end of this week, you should be able to: 1. Understand key concepts 2. Apply theoretical knowledge 3. Analyze case studies 4. Evaluate different approaches'],
        ['title' => 'Week %d Key Terms', 'desc' => 'Important terms and definitions you need to know for this week\'s content'],
    ],
];

// Assignment templates
$assignment_templates = [
    'formative' => [
        ['title' => 'Week %d Quiz', 'desc' => 'Quick assessment quiz to test your understanding of week %d concepts. Multiple choice and short answer questions.', 'max_score' => 20, 'passing_score' => 10],
        ['title' => 'Week %d Practice Exercise', 'desc' => 'Hands-on practice exercise to reinforce learning. Submit your work for feedback.', 'max_score' => 15, 'passing_score' => 8],
    ],
    'summative' => [
        ['title' => 'Week %d Assignment', 'desc' => 'Summative assessment covering topics from week %d. Please follow the rubric provided.', 'max_score' => 30, 'passing_score' => 15],
    ],
    'mid_sem' => [
        ['title' => 'Mid-Semester Examination', 'desc' => 'Comprehensive mid-semester examination covering weeks 1-8. Duration: 2 hours. Open book allowed.', 'max_score' => 100, 'passing_score' => 50],
    ],
    'final_exam' => [
        ['title' => 'End of Semester Examination', 'desc' => 'Final examination covering all course content. Duration: 3 hours. Closed book.', 'max_score' => 100, 'passing_score' => 50],
    ],
];

// Exam question templates
$question_templates = [
    'multiple_choice' => [
        ['text' => 'Which of the following best describes %s?', 'options' => ['Option A - Correct answer', 'Option B - Incorrect', 'Option C - Incorrect', 'Option D - Incorrect'], 'correct' => 'A'],
        ['text' => 'What is the primary purpose of %s in this context?', 'options' => ['To achieve objective A', 'To achieve objective B', 'To achieve objective C', 'To achieve objective D'], 'correct' => 'A'],
        ['text' => 'According to the course material, %s is characterized by:', 'options' => ['Characteristic A', 'Characteristic B', 'Characteristic C', 'Characteristic D'], 'correct' => 'A'],
        ['text' => 'Which statement about %s is TRUE?', 'options' => ['Statement A is true', 'Statement B is false', 'Statement C is false', 'Statement D is false'], 'correct' => 'A'],
        ['text' => 'The main advantage of %s is:', 'options' => ['Advantage A', 'Advantage B', 'Advantage C', 'Advantage D'], 'correct' => 'A'],
    ],
    'true_false' => [
        ['text' => '%s is an essential component of this subject area.', 'correct' => 'True'],
        ['text' => 'The concepts taught in this course are not applicable in real-world scenarios.', 'correct' => 'False'],
        ['text' => 'Critical thinking is required to understand %s.', 'correct' => 'True'],
    ],
    'short_answer' => [
        ['text' => 'Briefly explain the concept of %s in your own words.', 'correct' => 'Student should demonstrate understanding of the key concept and provide relevant examples.'],
        ['text' => 'List three key characteristics of %s.', 'correct' => '1. First characteristic 2. Second characteristic 3. Third characteristic'],
        ['text' => 'Define %s and provide one example.', 'correct' => 'Definition followed by relevant example from course material.'],
    ],
    'essay' => [
        ['text' => 'Critically analyze the role of %s in modern applications. Support your answer with examples from the course material.', 'correct' => 'Comprehensive analysis expected covering: introduction, main arguments, examples, and conclusion.'],
        ['text' => 'Compare and contrast two approaches to %s discussed in this course. Which approach do you think is more effective and why?', 'correct' => 'Comparison should cover: similarities, differences, evaluation, and justified conclusion.'],
    ],
];

// Sample file names for uploaded content
$sample_files = [
    'presentation' => ['Week_Lecture_Slides.pptx', 'Course_Overview.pptx', 'Summary_Presentation.pptx'],
    'video' => ['Lecture_Recording.mp4', 'Tutorial_Video.mp4', 'Demo_Video.mp4'],
    'document' => ['Reading_Material.pdf', 'Study_Guide.pdf', 'Course_Notes.pdf', 'Reference_Document.docx'],
    'audio' => ['Lecture_Audio.mp3', 'Discussion_Recording.mp3', 'Podcast_Episode.mp3'],
];

try {
    // Get Year 1 Semester 1 courses
    logMsg("Fetching Year 1 Semester 1 courses...", 'info');
    
    $courses_result = $conn->query("
        SELECT course_id, course_code, course_name, lecturer_id, total_weeks 
        FROM vle_courses 
        WHERE year_of_study = 1 AND semester = 'One' AND is_active = 1
        ORDER BY course_code
    ");
    
    if (!$courses_result || $courses_result->num_rows === 0) {
        logMsg("No Year 1 Semester 1 courses found. Creating some sample courses first...", 'warning');
        
        // Get a lecturer ID
        $lecturer = $conn->query("SELECT lecturer_id FROM lecturers LIMIT 1")->fetch_assoc();
        if (!$lecturer) {
            logMsg("No lecturers found. Please add lecturers first.", 'warning');
            exit;
        }
        $lecturer_id = $lecturer['lecturer_id'];
        
        // Get programs
        $programs = $conn->query("SELECT program_code, program_name FROM programs LIMIT 5")->fetch_all(MYSQLI_ASSOC);
        if (empty($programs)) {
            $programs = [
                ['program_code' => 'BBA', 'program_name' => 'Bachelor of Business Administration'],
                ['program_code' => 'BCOM', 'program_name' => 'Bachelor of Commerce'],
                ['program_code' => 'BIT', 'program_name' => 'Bachelor of Information Technology'],
            ];
        }
        
        // Sample Year 1 Semester 1 courses
        $sample_courses = [
            ['code' => 'COM101', 'name' => 'Communication Skills', 'program' => 'General'],
            ['code' => 'ICT101', 'name' => 'Introduction to Information Technology', 'program' => 'General'],
            ['code' => 'MGT101', 'name' => 'Principles of Management', 'program' => 'BBA'],
            ['code' => 'ACC101', 'name' => 'Financial Accounting I', 'program' => 'BCOM'],
            ['code' => 'ECO101', 'name' => 'Microeconomics', 'program' => 'General'],
            ['code' => 'MAT101', 'name' => 'Business Mathematics', 'program' => 'General'],
            ['code' => 'LAW101', 'name' => 'Business Law', 'program' => 'BBA'],
            ['code' => 'MKT101', 'name' => 'Principles of Marketing', 'program' => 'BBA'],
        ];
        
        foreach ($sample_courses as $course) {
            $stmt = $conn->prepare("
                INSERT IGNORE INTO vle_courses 
                (course_code, course_name, lecturer_id, year_of_study, semester, program_of_study, total_weeks, is_active)
                VALUES (?, ?, ?, 1, 'One', ?, 16, 1)
            ");
            $stmt->bind_param("ssis", $course['code'], $course['name'], $lecturer_id, $course['program']);
            $stmt->execute();
        }
        
        // Fetch again
        $courses_result = $conn->query("
            SELECT course_id, course_code, course_name, lecturer_id, total_weeks 
            FROM vle_courses 
            WHERE year_of_study = 1 AND semester = 'One' AND is_active = 1
            ORDER BY course_code
        ");
    }
    
    $courses = [];
    while ($row = $courses_result->fetch_assoc()) {
        $courses[] = $row;
    }
    
    logMsg("Found " . count($courses) . " courses to populate", 'success');
    
    // Create uploads directory if not exists
    $upload_dirs = ['uploads', 'uploads/presentations', 'uploads/documents', 'uploads/videos', 'uploads/audio', 'uploads/assignments'];
    foreach ($upload_dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    $total_content = 0;
    $total_assignments = 0;
    $total_exams = 0;
    
    foreach ($courses as $course) {
        logMsg("", 'info');
        logMsg("=== Processing: {$course['course_code']} - {$course['course_name']} ===", 'info');
        
        $course_id = $course['course_id'];
        $total_weeks = $course['total_weeks'] ?: 16;
        
        // Create weekly content for each week
        for ($week = 1; $week <= $total_weeks; $week++) {
            // Check if content already exists for this week
            $existing = $conn->query("SELECT COUNT(*) as cnt FROM vle_weekly_content WHERE course_id = $course_id AND week_number = $week")->fetch_assoc();
            
            if ($existing['cnt'] > 0) {
                continue; // Skip if content exists
            }
            
            // Add varied content types for each week
            $content_types_for_week = ['presentation', 'video', 'document', 'text'];
            if ($week % 2 == 0) {
                $content_types_for_week[] = 'link';
            }
            
            foreach ($content_types_for_week as $type) {
                if (!isset($content_templates[$type])) continue;
                
                // Pick a random template
                $template = $content_templates[$type][array_rand($content_templates[$type])];
                $title = sprintf($template['title'], $week);
                $desc = sprintf($template['desc'], $week);
                
                // Generate sample file path
                $file_path = null;
                $file_name = null;
                if (in_array($type, ['presentation', 'video', 'document']) && isset($sample_files[$type])) {
                    $file_name = $sample_files[$type][array_rand($sample_files[$type])];
                    $file_path = time() . '_' . $week . '_' . $file_name;
                }
                
                $is_mandatory = ($type === 'presentation' || $type === 'video') ? 1 : 0;
                
                $stmt = $conn->prepare("
                    INSERT INTO vle_weekly_content 
                    (course_id, week_number, title, description, content_type, file_path, file_name, is_mandatory, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $sort_order = array_search($type, ['presentation', 'video', 'document', 'link', 'text']);
                $stmt->bind_param("iisssssii", $course_id, $week, $title, $desc, $type, $file_path, $file_name, $is_mandatory, $sort_order);
                $stmt->execute();
                $total_content++;
            }
        }
        
        logMsg("  Added weekly content for $total_weeks weeks", 'success');
        
        // Create assignments
        // Check if assignments exist
        $existing_assignments = $conn->query("SELECT COUNT(*) as cnt FROM vle_assignments WHERE course_id = $course_id")->fetch_assoc();
        
        if ($existing_assignments['cnt'] == 0) {
            // Add formative assessments (quizzes) for weeks 2, 4, 6, 8, 10, 12, 14
            $quiz_weeks = [2, 4, 6, 10, 12, 14];
            foreach ($quiz_weeks as $week) {
                $template = $assignment_templates['formative'][0];
                $title = sprintf($template['title'], $week);
                $desc = sprintf($template['desc'], $week);
                $due_date = date('Y-m-d H:i:s', strtotime("+$week weeks"));
                
                $stmt = $conn->prepare("
                    INSERT INTO vle_assignments 
                    (course_id, week_number, title, description, assignment_type, max_score, passing_score, due_date, is_active)
                    VALUES (?, ?, ?, ?, 'formative', ?, ?, ?, 1)
                ");
                $stmt->bind_param("iisssis", $course_id, $week, $title, $desc, $template['max_score'], $template['passing_score'], $due_date);
                $stmt->execute();
                $total_assignments++;
            }
            
            // Add summative assignments for weeks 3, 7, 11
            $assignment_weeks = [3, 7, 11];
            foreach ($assignment_weeks as $week) {
                $template = $assignment_templates['summative'][0];
                $title = sprintf($template['title'], $week);
                $desc = sprintf($template['desc'], $week);
                $due_date = date('Y-m-d H:i:s', strtotime("+$week weeks"));
                
                $stmt = $conn->prepare("
                    INSERT INTO vle_assignments 
                    (course_id, week_number, title, description, assignment_type, max_score, passing_score, due_date, is_active)
                    VALUES (?, ?, ?, ?, 'summative', ?, ?, ?, 1)
                ");
                $stmt->bind_param("iisssis", $course_id, $week, $title, $desc, $template['max_score'], $template['passing_score'], $due_date);
                $stmt->execute();
                $total_assignments++;
            }
            
            // Add mid-semester exam (week 8)
            $template = $assignment_templates['mid_sem'][0];
            $due_date = date('Y-m-d H:i:s', strtotime("+8 weeks"));
            $stmt = $conn->prepare("
                INSERT INTO vle_assignments 
                (course_id, week_number, title, description, assignment_type, max_score, passing_score, due_date, is_active)
                VALUES (?, 8, ?, ?, 'mid_sem', ?, ?, ?, 1)
            ");
            $stmt->bind_param("isssis", $course_id, $template['title'], $template['desc'], $template['max_score'], $template['passing_score'], $due_date);
            $stmt->execute();
            $total_assignments++;
            
            // Add end-semester exam (week 16)
            $template = $assignment_templates['final_exam'][0];
            $due_date = date('Y-m-d H:i:s', strtotime("+16 weeks"));
            $stmt = $conn->prepare("
                INSERT INTO vle_assignments 
                (course_id, week_number, title, description, assignment_type, max_score, passing_score, due_date, is_active)
                VALUES (?, 16, ?, ?, 'final_exam', ?, ?, ?, 1)
            ");
            $stmt->bind_param("isssis", $course_id, $template['title'], $template['desc'], $template['max_score'], $template['passing_score'], $due_date);
            $stmt->execute();
            $total_assignments++;
            
            logMsg("  Added assignments (quizzes, assignments, mid-sem, final exam)", 'success');
        }
        
        // Create exams in examination system
        $existing_exams = $conn->query("SELECT COUNT(*) as cnt FROM exams WHERE course_id = $course_id")->fetch_assoc();
        
        if ($existing_exams['cnt'] == 0) {
            // Mid-semester exam
            $exam_code = $course['course_code'] . '-MID-' . date('Y');
            $exam_name = $course['course_name'] . ' - Mid-Semester Examination';
            $start_time = date('Y-m-d 09:00:00', strtotime('+8 weeks'));
            $end_time = date('Y-m-d 11:00:00', strtotime('+8 weeks'));
            $duration = 120; // 2 hours
            
            $stmt = $conn->prepare("
                INSERT INTO exams 
                (exam_code, exam_name, course_id, exam_type, description, total_questions, total_marks, passing_marks, duration_minutes, start_time, end_time, instructions, is_active)
                VALUES (?, ?, ?, 'mid_term', ?, 25, 100, 50, ?, ?, ?, ?, 1)
            ");
            $desc = "Mid-semester examination for {$course['course_name']}. Covers content from weeks 1-8.";
            $instructions = "1. Read all questions carefully before answering.\n2. Manage your time wisely.\n3. Answer all questions.\n4. Show all your work where applicable.\n5. Academic integrity rules apply.";
            $stmt->bind_param("ssisisss", $exam_code, $exam_name, $course_id, $desc, $duration, $start_time, $end_time, $instructions);
            
            if ($stmt->execute()) {
                $mid_exam_id = $stmt->insert_id;
                // Add questions
                addExamQuestions($conn, $mid_exam_id, $course['course_name'], 25);
                $total_exams++;
                logMsg("  Created mid-semester exam with 25 questions", 'success');
            }
            
            // End-semester exam
            $exam_code = $course['course_code'] . '-FIN-' . date('Y');
            $exam_name = $course['course_name'] . ' - End of Semester Examination';
            $start_time = date('Y-m-d 09:00:00', strtotime('+16 weeks'));
            $end_time = date('Y-m-d 12:00:00', strtotime('+16 weeks'));
            $duration = 180; // 3 hours
            
            $stmt = $conn->prepare("
                INSERT INTO exams 
                (exam_code, exam_name, course_id, exam_type, description, total_questions, total_marks, passing_marks, duration_minutes, start_time, end_time, instructions, is_active)
                VALUES (?, ?, ?, 'final', ?, 40, 100, 50, ?, ?, ?, ?, 1)
            ");
            $desc = "Final examination for {$course['course_name']}. Comprehensive exam covering all course content.";
            $instructions = "1. This is a closed-book examination.\n2. Read all questions carefully.\n3. Allocate your time appropriately.\n4. Answer all questions in the booklet provided.\n5. No electronic devices allowed.\n6. Academic integrity rules strictly enforced.";
            $stmt->bind_param("ssisisss", $exam_code, $exam_name, $course_id, $desc, $duration, $start_time, $end_time, $instructions);
            
            if ($stmt->execute()) {
                $final_exam_id = $stmt->insert_id;
                // Add questions
                addExamQuestions($conn, $final_exam_id, $course['course_name'], 40);
                $total_exams++;
                logMsg("  Created end-semester exam with 40 questions", 'success');
            }
        }
    }
    
    logMsg("", 'info');
    logMsg("========================================", 'success');
    logMsg("Sample Content Setup Complete!", 'success');
    logMsg("========================================", 'success');
    logMsg("Total weekly content items created: $total_content", 'info');
    logMsg("Total assignments created: $total_assignments", 'info');
    logMsg("Total exams created: $total_exams", 'info');
    
} catch (Exception $e) {
    logMsg("Error: " . $e->getMessage(), 'warning');
}

/**
 * Add exam questions to an exam
 */
function addExamQuestions($conn, $exam_id, $course_name, $num_questions) {
    global $question_templates;
    
    $question_num = 1;
    $marks_per_question = floor(100 / $num_questions);
    $remaining_marks = 100 - ($marks_per_question * $num_questions);
    
    // Distribution: 60% MCQ, 15% True/False, 15% Short Answer, 10% Essay
    $mcq_count = ceil($num_questions * 0.6);
    $tf_count = ceil($num_questions * 0.15);
    $sa_count = ceil($num_questions * 0.15);
    $essay_count = max(1, $num_questions - $mcq_count - $tf_count - $sa_count);
    
    // Add Multiple Choice Questions
    for ($i = 0; $i < $mcq_count && $question_num <= $num_questions; $i++) {
        $template = $question_templates['multiple_choice'][$i % count($question_templates['multiple_choice'])];
        $question_text = sprintf($template['text'], $course_name);
        $options = json_encode($template['options']);
        $marks = ($question_num == $num_questions) ? $marks_per_question + $remaining_marks : $marks_per_question;
        
        $stmt = $conn->prepare("
            INSERT INTO exam_questions 
            (exam_id, question_number, question_text, question_type, options, correct_answer, marks)
            VALUES (?, ?, ?, 'multiple_choice', ?, ?, ?)
        ");
        $stmt->bind_param("iisssi", $exam_id, $question_num, $question_text, $options, $template['correct'], $marks);
        $stmt->execute();
        $question_num++;
    }
    
    // Add True/False Questions
    for ($i = 0; $i < $tf_count && $question_num <= $num_questions; $i++) {
        $template = $question_templates['true_false'][$i % count($question_templates['true_false'])];
        $question_text = sprintf($template['text'], $course_name);
        $options = json_encode(['True', 'False']);
        $marks = ($question_num == $num_questions) ? $marks_per_question + $remaining_marks : $marks_per_question;
        
        $stmt = $conn->prepare("
            INSERT INTO exam_questions 
            (exam_id, question_number, question_text, question_type, options, correct_answer, marks)
            VALUES (?, ?, ?, 'true_false', ?, ?, ?)
        ");
        $stmt->bind_param("iisssi", $exam_id, $question_num, $question_text, $options, $template['correct'], $marks);
        $stmt->execute();
        $question_num++;
    }
    
    // Add Short Answer Questions
    for ($i = 0; $i < $sa_count && $question_num <= $num_questions; $i++) {
        $template = $question_templates['short_answer'][$i % count($question_templates['short_answer'])];
        $question_text = sprintf($template['text'], $course_name);
        $marks = ($question_num == $num_questions) ? $marks_per_question + $remaining_marks : $marks_per_question;
        
        $stmt = $conn->prepare("
            INSERT INTO exam_questions 
            (exam_id, question_number, question_text, question_type, options, correct_answer, marks)
            VALUES (?, ?, ?, 'short_answer', NULL, ?, ?)
        ");
        $stmt->bind_param("iissi", $exam_id, $question_num, $question_text, $template['correct'], $marks);
        $stmt->execute();
        $question_num++;
    }
    
    // Add Essay Questions
    for ($i = 0; $i < $essay_count && $question_num <= $num_questions; $i++) {
        $template = $question_templates['essay'][$i % count($question_templates['essay'])];
        $question_text = sprintf($template['text'], $course_name);
        // Essay questions get more marks
        $marks = $marks_per_question * 2;
        if ($question_num == $num_questions) {
            $marks += $remaining_marks;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO exam_questions 
            (exam_id, question_number, question_text, question_type, options, correct_answer, marks)
            VALUES (?, ?, ?, 'essay', NULL, ?, ?)
        ");
        $stmt->bind_param("iissi", $exam_id, $question_num, $question_text, $template['correct'], $marks);
        $stmt->execute();
        $question_num++;
    }
    
    // Update total_questions in exam
    $actual_questions = $question_num - 1;
    $conn->query("UPDATE exams SET total_questions = $actual_questions WHERE exam_id = $exam_id");
}

if (!$is_cli) {
    echo '<div class="mt-4">';
    echo '<a href="admin/dashboard.php" class="btn btn-primary">Go to Admin Dashboard</a> ';
    echo '<a href="lecturer/dashboard.php" class="btn btn-success">Go to Lecturer Dashboard</a> ';
    echo '<a href="student/dashboard.php" class="btn btn-info">Go to Student Dashboard</a>';
    echo '</div>';
    echo '</div></body></html>';
}
?>
