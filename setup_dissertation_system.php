<?php
/**
 * Dissertation Management System - Database Setup
 * 
 * Creates all tables needed for the dissertation workflow:
 * - dissertations: Main dissertation records
 * - dissertation_phases: Phase/chapter submissions
 * - dissertation_supervisors: Supervisor assignments
 * - dissertation_feedback: Supervisor/coordinator feedback
 * - dissertation_ethics: Ethics form submissions
 * - dissertation_defense: Defense scheduling & grading
 * - dissertation_similarity_checks: Plagiarism/AI detection results
 * - dissertation_guidelines: Chapter guidelines from the university doc
 * - research_coordinators: Research coordinator staff records
 * 
 * Also updates the users role ENUM to include 'research_coordinator'
 */

require_once 'includes/config.php';

$conn = getDbConnection();
$results = [];

// Helper function
function runSetupQuery($conn, $sql, $description) {
    try {
        if ($conn->query($sql)) {
            return ['success' => true, 'message' => $description . ' - OK'];
        } else {
            return ['success' => false, 'message' => $description . ' - ERROR: ' . $conn->error];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => $description . ' - EXCEPTION: ' . $e->getMessage()];
    }
}

// ============================================
// 1. Add 'research_coordinator' to users role ENUM
// ============================================
$results[] = runSetupQuery($conn, "
    ALTER TABLE users MODIFY COLUMN role ENUM(
        'student','lecturer','staff','hod','dean','finance','admin',
        'odl_coordinator','examination_manager','examination_officer',
        'research_coordinator'
    ) NOT NULL DEFAULT 'student'
", "Add 'research_coordinator' to users.role ENUM");

// ============================================
// 2. Research Coordinators table
// ============================================
$results[] = runSetupQuery($conn, "
    CREATE TABLE IF NOT EXISTS research_coordinators (
        coordinator_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        department VARCHAR(50) DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Create research_coordinators table");

// ============================================
// 3. Dissertations - Main record for each student's dissertation
// ============================================
$results[] = runSetupQuery($conn, "
    CREATE TABLE IF NOT EXISTS dissertations (
        dissertation_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(20) NOT NULL,
        user_id INT DEFAULT NULL,
        title VARCHAR(500) DEFAULT NULL,
        topic_area VARCHAR(255) DEFAULT NULL,
        program VARCHAR(100) DEFAULT NULL,
        program_type ENUM('degree','professional','masters','doctorate') DEFAULT 'degree',
        academic_year VARCHAR(20) DEFAULT NULL,
        semester ENUM('One','Two') DEFAULT NULL,
        year_of_study INT DEFAULT NULL,
        
        -- Overall status
        status ENUM(
            'topic_submission','topic_review','topic_approved','topic_rejected',
            'concept_note','concept_review','concept_approved','concept_rejected',
            'supervisor_assigned',
            'chapter1_writing','chapter1_submitted','chapter1_review','chapter1_approved','chapter1_revision',
            'chapter2_writing','chapter2_submitted','chapter2_review','chapter2_approved','chapter2_revision',
            'chapter3_writing','chapter3_submitted','chapter3_review','chapter3_approved','chapter3_revision',
            'proposal_submitted','proposal_review','proposal_approved','proposal_revision',
            'ethics_submitted','ethics_review','ethics_approved','ethics_revision',
            'defense_listed','defense_scheduled','defense_passed','defense_failed',
            'chapter4_writing','chapter4_submitted','chapter4_review','chapter4_approved','chapter4_revision',
            'chapter5_writing','chapter5_submitted','chapter5_review','chapter5_approved','chapter5_revision',
            'final_draft_submitted','final_draft_review','final_draft_approved','final_draft_revision',
            'presentation_submitted','presentation_review','presentation_approved','presentation_revision',
            'final_submitted','completed','archived'
        ) DEFAULT 'topic_submission',
        
        -- Current phase tracking
        current_phase ENUM(
            'topic','concept_note','chapter1','chapter2','chapter3',
            'proposal','ethics','defense','chapter4','chapter5',
            'final_draft','presentation','final_submission'
        ) DEFAULT 'topic',
        
        -- Supervisor info
        supervisor_id INT DEFAULT NULL COMMENT 'lecturers.lecturer_id',
        co_supervisor_id INT DEFAULT NULL COMMENT 'lecturers.lecturer_id',
        coordinator_id INT DEFAULT NULL COMMENT 'research_coordinators.coordinator_id',
        
        -- Concept note
        concept_note_text TEXT DEFAULT NULL,
        concept_note_file VARCHAR(255) DEFAULT NULL,
        
        -- Key dates
        topic_submitted_at DATETIME DEFAULT NULL,
        supervisor_assigned_at DATETIME DEFAULT NULL,
        proposal_approved_at DATETIME DEFAULT NULL,
        ethics_approved_at DATETIME DEFAULT NULL,
        defense_date DATETIME DEFAULT NULL,
        defense_grade DECIMAL(5,2) DEFAULT NULL,
        defense_result ENUM('pass','fail','conditional_pass') DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        
        -- Word count tracking
        total_word_count INT DEFAULT 0,
        
        -- Metadata
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_student (student_id),
        INDEX idx_supervisor (supervisor_id),
        INDEX idx_coordinator (coordinator_id),
        INDEX idx_status (status),
        INDEX idx_phase (current_phase)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Create dissertations table");

// ============================================
// 4. Dissertation Submissions - Per-chapter/phase file submissions
// ============================================
$results[] = runSetupQuery($conn, "
    CREATE TABLE IF NOT EXISTS dissertation_submissions (
        submission_id INT AUTO_INCREMENT PRIMARY KEY,
        dissertation_id INT NOT NULL,
        phase ENUM(
            'topic','concept_note','chapter1','chapter2','chapter3',
            'proposal','ethics','defense','chapter4','chapter5',
            'final_draft','presentation','final_submission'
        ) NOT NULL,
        version INT DEFAULT 1,
        
        -- File info
        file_path VARCHAR(500) DEFAULT NULL,
        file_name VARCHAR(255) DEFAULT NULL,
        file_size INT DEFAULT 0,
        file_type VARCHAR(50) DEFAULT NULL,
        
        -- Content
        submission_text TEXT DEFAULT NULL COMMENT 'For concept notes or inline text',
        word_count INT DEFAULT 0,
        
        -- Status
        status ENUM('draft','submitted','under_review','approved','revision_requested','rejected') DEFAULT 'draft',
        submitted_at DATETIME DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        reviewed_by INT DEFAULT NULL COMMENT 'user_id of reviewer',
        
        -- Formatting check results (JSON)
        formatting_check JSON DEFAULT NULL,
        formatting_score DECIMAL(5,2) DEFAULT NULL,
        
        -- Similarity check link
        similarity_check_id INT DEFAULT NULL,
        
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_dissertation (dissertation_id),
        INDEX idx_phase (phase),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Create dissertation_submissions table");

// ============================================
// 5. Dissertation Feedback - Comments from supervisors/coordinators
// ============================================
$results[] = runSetupQuery($conn, "
    CREATE TABLE IF NOT EXISTS dissertation_feedback (
        feedback_id INT AUTO_INCREMENT PRIMARY KEY,
        dissertation_id INT NOT NULL,
        submission_id INT DEFAULT NULL,
        phase ENUM(
            'topic','concept_note','chapter1','chapter2','chapter3',
            'proposal','ethics','defense','chapter4','chapter5',
            'final_draft','final_submission'
        ) NOT NULL,
        
        -- Who gave feedback
        user_id INT NOT NULL COMMENT 'user_id of reviewer',
        reviewer_role ENUM('supervisor','co_supervisor','coordinator','examiner') DEFAULT 'supervisor',
        
        -- Feedback content
        feedback_text TEXT NOT NULL,
        feedback_type ENUM('comment','approval','revision_request','rejection') DEFAULT 'comment',
        
        -- Specific sections flagged (JSON array)
        flagged_sections JSON DEFAULT NULL,
        
        -- Attachments
        attachment_path VARCHAR(500) DEFAULT NULL,
        
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        INDEX idx_dissertation (dissertation_id),
        INDEX idx_submission (submission_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Create dissertation_feedback table");

// ============================================
// 6. Dissertation Ethics - Ethics form tracking
// ============================================
$results[] = runSetupQuery($conn, "
    CREATE TABLE IF NOT EXISTS dissertation_ethics (
        ethics_id INT AUTO_INCREMENT PRIMARY KEY,
        dissertation_id INT NOT NULL,
        
        -- Ethics form details
        ethics_form_path VARCHAR(500) DEFAULT NULL,
        consent_form_path VARCHAR(500) DEFAULT NULL,
        irb_reference VARCHAR(100) DEFAULT NULL,
        
        -- Ethics review
        status ENUM('pending','submitted','under_review','approved','revision_required','rejected') DEFAULT 'pending',
        submitted_at DATETIME DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        reviewed_by INT DEFAULT NULL,
        reviewer_comments TEXT DEFAULT NULL,
        approval_letter_path VARCHAR(500) DEFAULT NULL,
        
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_dissertation (dissertation_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Create dissertation_ethics table");

// ============================================
// 7. Dissertation Defense - Defense scheduling and grading
// ============================================
$results[] = runSetupQuery($conn, "
    CREATE TABLE IF NOT EXISTS dissertation_defense (
        defense_id INT AUTO_INCREMENT PRIMARY KEY,
        dissertation_id INT NOT NULL,
        defense_type ENUM('proposal','final') DEFAULT 'proposal',
        
        -- Schedule
        defense_date DATETIME DEFAULT NULL,
        venue VARCHAR(255) DEFAULT NULL,
        is_virtual TINYINT(1) DEFAULT 0,
        meeting_link VARCHAR(500) DEFAULT NULL,
        
        -- Panel
        panel_members JSON DEFAULT NULL COMMENT 'Array of {user_id, name, role}',
        chairperson_id INT DEFAULT NULL,
        
        -- Results
        status ENUM('scheduled','in_progress','completed','postponed','cancelled') DEFAULT 'scheduled',
        grade DECIMAL(5,2) DEFAULT NULL,
        result ENUM('pass','fail','conditional_pass','major_revision') DEFAULT NULL,
        
        -- Comments and feedback
        panel_comments TEXT DEFAULT NULL,
        conditions TEXT DEFAULT NULL COMMENT 'Conditions for conditional pass',
        
        -- Documents
        presentation_file VARCHAR(500) DEFAULT NULL,
        defense_report_path VARCHAR(500) DEFAULT NULL,
        
        conducted_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_dissertation (dissertation_id),
        INDEX idx_status (status),
        INDEX idx_date (defense_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Create dissertation_defense table");

// ============================================
// 8. Similarity Checks - Plagiarism and AI detection
// ============================================
$results[] = runSetupQuery($conn, "
    CREATE TABLE IF NOT EXISTS dissertation_similarity_checks (
        check_id INT AUTO_INCREMENT PRIMARY KEY,
        dissertation_id INT NOT NULL,
        submission_id INT DEFAULT NULL,
        phase ENUM(
            'topic','concept_note','chapter1','chapter2','chapter3',
            'proposal','ethics','chapter4','chapter5',
            'final_draft','final_submission'
        ) NOT NULL,
        
        -- Overall scores
        similarity_score DECIMAL(5,2) DEFAULT 0 COMMENT 'Percentage 0-100',
        ai_detection_score DECIMAL(5,2) DEFAULT 0 COMMENT 'Percentage 0-100',
        
        -- Detailed results (JSON)
        similarity_details JSON DEFAULT NULL COMMENT 'Sources matched, passages flagged',
        ai_detection_details JSON DEFAULT NULL COMMENT 'AI patterns detected',
        
        -- Cross-student comparison
        cross_student_matches JSON DEFAULT NULL COMMENT 'Other dissertations with matches',
        
        -- Word-level analysis
        total_words_checked INT DEFAULT 0,
        flagged_words INT DEFAULT 0,
        
        -- Status
        status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
        checked_at DATETIME DEFAULT NULL,
        
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        INDEX idx_dissertation (dissertation_id),
        INDEX idx_submission (submission_id),
        INDEX idx_scores (similarity_score, ai_detection_score)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Create dissertation_similarity_checks table");

// ============================================
// 9. Dissertation Guidelines - Chapter writing guidelines
// ============================================
$results[] = runSetupQuery($conn, "
    CREATE TABLE IF NOT EXISTS dissertation_guidelines (
        guideline_id INT AUTO_INCREMENT PRIMARY KEY,
        phase ENUM(
            'general','formatting','topic','concept_note',
            'chapter1','chapter2','chapter3','chapter4','chapter5',
            'proposal','ethics','defense','references','appendices'
        ) NOT NULL,
        section_title VARCHAR(255) NOT NULL,
        section_order INT DEFAULT 0,
        content TEXT NOT NULL,
        min_word_count INT DEFAULT NULL,
        max_word_count INT DEFAULT NULL,
        min_pages INT DEFAULT NULL,
        max_pages INT DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_phase (phase),
        INDEX idx_order (section_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Create dissertation_guidelines table");

// ============================================
// 10. Dissertation Notifications
// ============================================
$results[] = runSetupQuery($conn, "
    CREATE TABLE IF NOT EXISTS dissertation_notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        dissertation_id INT NOT NULL,
        user_id INT NOT NULL,
        type ENUM(
            'submission','feedback','approval','rejection','revision_request',
            'supervisor_assigned','defense_scheduled','defense_result',
            'similarity_report','ethics_update','phase_complete','reminder'
        ) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT DEFAULT NULL,
        link VARCHAR(500) DEFAULT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        INDEX idx_user (user_id),
        INDEX idx_dissertation (dissertation_id),
        INDEX idx_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Create dissertation_notifications table");

// ============================================
// 11. Insert default guidelines from the university document
// ============================================
$guidelines_data = [
    // General formatting
    ['general', 'Document Formatting Requirements', 1, 
     'Font: Times New Roman, Size 12 for body text, Size 14 for chapter numbers/titles. Line Spacing: 1.5. Margins: Top 2.5cm, Bottom 2.5cm, Right 2.5cm, Left 3cm (for binding). Text Alignment: Full justified. Minimum word count: 7,500 ± 10% for undergraduate, 15,000 ± 10% for postgraduate.', NULL, NULL, NULL, NULL],
    
    ['formatting', 'Cover Page Requirements', 1,
     'The cover page must include: University logo (centered), Full dissertation title (uppercase, bold), Student full name, Student registration number, Supervisor name, Faculty and Department, Degree being pursued, Submission date (Month Year format).', NULL, NULL, NULL, NULL],
    
    ['formatting', 'Declaration', 2,
     'A signed statement declaring: the work is original, it has not been submitted elsewhere, all references are properly cited, and the student accepts responsibility for any issues found.', NULL, NULL, NULL, NULL],
    
    ['formatting', 'Certificate of Approval', 3,
     'Includes: dissertation title, student name, examining committee signatures (supervisor, committee member), date of approval, and university stamp.', NULL, NULL, NULL, NULL],
    
    ['formatting', 'Abstract', 4,
     'A concise summary (200-300 words) covering: research problem, objectives, methodology, key findings, and conclusions. Should be self-contained and give a complete picture of the study.', 200, 300, NULL, NULL],
    
    ['formatting', 'Table of Contents', 5,
     'Auto-generated using MS Word heading styles. Must include all chapters, sections, and sub-sections with correct page numbers. Separate lists for tables, figures, and abbreviations should follow.', NULL, NULL, NULL, NULL],
    
    // Topic & Concept Note
    ['topic', 'Topic Formulation Guidelines', 1,
     'Research Topic Selection: Choose a topic that is specific, researchable, and relevant to your field. The topic should address a clear gap in existing knowledge. Use the FINER criteria: Feasible (can be completed with available resources), Interesting (contributes to knowledge), Novel (adds something new), Ethical (respects research ethics), Relevant (important to the field and practice).', NULL, NULL, NULL, NULL],
    
    ['concept_note', 'Concept Note Requirements', 1,
     'The concept note should include: 1) Working title of the dissertation, 2) Brief background and context (200-300 words), 3) Statement of the problem (100-150 words), 4) Research objectives (1 main + 3 specific), 5) Proposed methodology overview (100-150 words), 6) Expected outcomes and significance, 7) Preliminary references (5-10 sources).', NULL, NULL, 2, 3],
    
    // Chapter 1
    ['chapter1', 'Chapter 1: Introduction', 1,
     'Chapter 1 introduces the study and sets the foundation. Required sections: 1.1 Introduction (1-2 pages) - background context; 1.2 Background of the Study (1-3 pages) - detailed context; 1.3 Statement of the Problem (3-4 paragraphs, 200-300 words) - the gap being addressed; 1.4 Research Objectives - 1 main objective + 3 specific objectives (SMART criteria); 1.5 Research Questions or Hypotheses - aligned with objectives; 1.6 Significance/Justification (100-120 words); 1.7 Scope and Limitations (100-120 words); 1.8 Structure of the Dissertation; 1.9 Chapter Summary (60-100 words).', NULL, NULL, 6, 10],
    
    ['chapter1', 'Research Objectives (SMART)', 2,
     'Objectives must follow SMART criteria: Specific (clear about what, who, where), Measurable (can be quantified or observed), Achievable (realistic within scope), Relevant (directly addresses the problem), Time-bound (achievable within study period). Main objective starts with "To examine/investigate/assess..." Specific objectives start with action verbs like "To determine...", "To assess...", "To examine...".', NULL, NULL, NULL, NULL],
    
    ['chapter1', 'Research Questions & Hypotheses', 3,
     'Research Questions: Each specific objective should have a corresponding research question. Hypotheses (if applicable): Each has a null hypothesis (H0) and alternative hypothesis (H1/Ha). Hypotheses are testable predictions about relationships between variables.', NULL, NULL, NULL, NULL],
    
    // Chapter 2
    ['chapter2', 'Chapter 2: Literature Review', 1,
     'Chapter 2 reviews existing literature and theoretical foundations. Sections: 2.1 Introduction; 2.2 Definitions of Key Concepts (max 3 pages) - define 5-8 key terms with 3-5 scholarly definitions each; 2.3 Theoretical Framework (100-150 words per theory, include 2-3 theories); 2.4 Empirical Literature Review (9-12 pages, organized by research objectives, 3-4 pages per objective); 2.5 Conceptual Framework (diagram + narrative); 2.6 Chapter Summary.', NULL, NULL, 15, 25],
    
    ['chapter2', 'Theoretical Framework Guide', 2,
     'For each theory include: 1) Name and primary developer with citation, 2) Historical development, 3) Key principles and assumptions (2-3 core), 4) Relevance to your study and how it connects to your variables, 5) Limitations or critiques (1-2). Connect multiple theories to show a comprehensive framework.', NULL, NULL, NULL, NULL],
    
    ['chapter2', 'Empirical Literature Review', 3,
     'Organize by research objectives (3-4 pages per objective). For each study: identify author/year, explain purpose, describe methodology (sample size, location, design, data collection), summarize key findings, highlight limitations. Use synthesis: compare/contrast findings, identify agreements and contradictions, evaluate methods, identify research gaps your study addresses.', NULL, NULL, 9, 12],
    
    ['chapter2', 'Conceptual Framework', 4,
     'Create a diagram showing: Independent variables (left), Dependent variable (right), arrows showing relationships, any moderating/mediating variables. Include a narrative explanation (1-2 paragraphs) justifying the proposed relationships using theory or literature. Can be original or adapted from existing literature with proper citation.', NULL, NULL, NULL, NULL],
    
    // Chapter 3
    ['chapter3', 'Chapter 3: Methodology', 1,
     'Chapter 3 details the research methodology. Sections: 3.1 Introduction (½ page); 3.2 Participants and Location (2 paragraphs); 3.4 Research Design (1-2 pages) covering Research Philosophy, Research Approach, Methodological Choice, Research Strategy, Time Horizon; 3.4.6 Sampling (1-2 pages); 3.5 Data Collection Tools (1-2 pages); 3.6 Data Collection (1 page); 3.7 Data Analysis (1-2 pages); 3.8 Ethical Considerations (½-1 page); 3.9 Limitations and Delimitations (½ page); 3.10 Chapter Summary (½ page).', NULL, NULL, 8, 15],
    
    ['chapter3', 'Research Design Components', 2,
     'Research Philosophy (100 words): positivism, interpretivism, or pragmatism. Research Approach (100 words): deductive, inductive, or abductive. Methodological Choice (100-150 words): qualitative, quantitative, or mixed methods. Research Strategy (100 words): survey, case study, experiment, etc. Time Horizon: cross-sectional or longitudinal.', NULL, NULL, NULL, NULL],
    
    ['chapter3', 'Sampling Guidelines', 3,
     'Sample Size: For quantitative studies use Yamane formula n = N/(1 + Ne²). Minimum 30 responses for statistical validity. For qualitative, plan 10-15 interviews. Sampling Technique: random, stratified, purposive, or convenience. Justify technique choice and describe recruitment process.', NULL, NULL, NULL, NULL],
    
    ['chapter3', 'Data Collection & Analysis', 4,
     'Tools: questionnaires, interview guides, observation sheets. Pretest with 5-10 participants. Validate using Cronbach alpha (aim for α ≥ 0.7). Analysis: Quantitative - use SPSS (descriptive stats, regression, t-tests). Qualitative - thematic analysis with NVivo. Explain techniques per objective.', NULL, NULL, NULL, NULL],
    
    // Chapter 4
    ['chapter4', 'Chapter 4: Results and Discussion', 1,
     'Chapter 4 presents findings and discusses implications. Sections: 4.1 Introduction (1-2 paragraphs); 4.2 Restatement of Problem and Objectives; 4.3 Demographics (tables + interpretation + literature links); 4.4 Reliability Tests (Cronbach alpha); 4.5 Descriptive Statistics (means, SD); 4.6 Inferential Statistics by Objective (regression, correlations, etc.); 4.7 Hypothesis Tests (if applicable); 4.8 Chapter Summary. Tables: captions above. Figures: captions below.', NULL, NULL, 15, 30],
    
    ['chapter4', 'Results Presentation Guidelines', 2,
     'For each research objective: 1) Restate the objective, 2) Present statistical test results with tables, 3) Interpret results in non-technical language, 4) Connect to literature from Chapter 2, 5) Relate to theoretical framework, 6) Discuss practical implications. Use past tense for findings, present tense for implications.', NULL, NULL, NULL, NULL],
    
    // Chapter 5
    ['chapter5', 'Chapter 5: Conclusion and Recommendations', 1,
     'Chapter 5 concludes the study. Sections: 5.1 Introduction (1-2 paragraphs, 150-200 words); 5.2 Research Conclusions (organized by objective, 150-250 words each, with data + theory + literature links); 5.3 Research Recommendations (3-6 actionable, cost-aware, evidence-based recommendations, 300-500 words total); 5.4 Recommendations for Further Studies (2-4 specific areas); 5.5 Chapter Summary (100-150 words).', NULL, NULL, 5, 10],
    
    ['chapter5', 'Recommendations Guide', 2,
     'Each recommendation must: specify what action to take, who should do it, how to implement it, estimate costs and benefits, be directly linked to a specific finding. Maximum 3-6 recommendations. Also include 2-4 areas for future research addressing limitations identified in your study.', NULL, NULL, NULL, NULL],
    
    // References
    ['references', 'Reference Requirements', 1,
     'Harvard citation style. Arranged alphabetically by first author surname. All sources must be within last 10 years. Journal articles within last 5 years. At least 60% must be empirical journal articles. No predatory journals. Use Mendeley or Zotero for management. Every in-text citation must have a reference entry and vice versa.', NULL, NULL, NULL, NULL],
    
    // Ethics
    ['ethics', 'Ethics Form Requirements', 1,
     'Required documents: 1) Informed consent form, 2) IRB approval application, 3) Data protection plan, 4) Participant information sheet. Must address: confidentiality, anonymity (unique codes), data storage security, voluntary participation, right to withdraw, and how sensitive information is handled.', NULL, NULL, NULL, NULL],
    
    // Defense
    ['defense', 'Defense Preparation Guide', 1,
     'Prepare a presentation (15-20 minutes) covering: Introduction and problem, objectives, methodology, key findings, conclusions and recommendations. Expect questions on: methodology justification, findings interpretation, limitations, contribution to knowledge. Dress professionally. Bring printed copies of the dissertation for panel members.', NULL, NULL, NULL, NULL],
];

// Check if guidelines already exist
$check = $conn->query("SELECT COUNT(*) as cnt FROM dissertation_guidelines");
$count = $check ? $check->fetch_assoc()['cnt'] : 0;

if ($count == 0) {
    $stmt = $conn->prepare("INSERT INTO dissertation_guidelines (phase, section_title, section_order, content, min_word_count, max_word_count, min_pages, max_pages) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $inserted = 0;
    foreach ($guidelines_data as $g) {
        $stmt->bind_param("ssissiii", $g[0], $g[1], $g[2], $g[3], $g[4], $g[5], $g[6], $g[7]);
        if ($stmt->execute()) {
            $inserted++;
        }
    }
    $results[] = ['success' => true, 'message' => "Inserted $inserted dissertation guidelines"];
} else {
    $results[] = ['success' => true, 'message' => "Guidelines already exist ($count records) - skipped"];
}

// ============================================
// 12. Create uploads directory for dissertations
// ============================================
$upload_dir = __DIR__ . '/uploads/dissertations';
if (!is_dir($upload_dir)) {
    if (mkdir($upload_dir, 0755, true)) {
        $results[] = ['success' => true, 'message' => "Created uploads/dissertations directory"];
    } else {
        $results[] = ['success' => false, 'message' => "Failed to create uploads/dissertations directory"];
    }
} else {
    $results[] = ['success' => true, 'message' => "uploads/dissertations directory already exists"];
}

// Sub-directories for organized uploads
$sub_dirs = ['topics', 'concept_notes', 'chapters', 'proposals', 'ethics', 'defense', 'final', 'similarity_reports'];
foreach ($sub_dirs as $dir) {
    $path = $upload_dir . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}
$results[] = ['success' => true, 'message' => "Created dissertation upload sub-directories"];

// Output results
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dissertation System Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="mb-4"><i class="bi bi-journal-bookmark-fill me-2"></i>Dissertation System Setup</h1>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-3">Setup Results</h5>
            <?php foreach ($results as $r): ?>
                <div class="alert alert-<?= $r['success'] ? 'success' : 'danger' ?> py-2 mb-2">
                    <i class="bi bi-<?= $r['success'] ? 'check-circle' : 'x-circle' ?> me-2"></i>
                    <?= htmlspecialchars($r['message']) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="card mt-4">
        <div class="card-body">
            <h5 class="card-title">Next Steps</h5>
            <ol>
                <li>Create a Research Coordinator user account via Admin panel</li>
                <li>Assign the <code>research_coordinator</code> role to the user</li>
                <li>Students in Year 3 Sem 2 and Year 4 will see the Dissertation module</li>
                <li>Access the Research Coordinator portal at <code>/research_coordinator/dashboard.php</code></li>
            </ol>
        </div>
    </div>
</div>
</body>
</html>
