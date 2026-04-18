<?php
/**
 * Lecturer Portal - Help & User Manual
 * Comprehensive guide for all lecturer features
 */
require_once '../includes/auth.php';
requireLogin();
$user = getCurrentUser();
$conn = getDbConnection();
$breadcrumbs = [['title' => 'Help & User Manual']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help & User Manual - Lecturer Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .help-hero { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #667eea 100%); color: #fff; border-radius: 16px; padding: 40px; margin-bottom: 32px; }
        .help-hero h2 { font-weight: 700; margin-bottom: 8px; }
        .help-hero p { opacity: 0.9; margin-bottom: 0; }
        .help-search { max-width: 500px; }
        .help-search .form-control { border-radius: 50px; padding: 12px 20px; border: none; font-size: 0.95rem; }
        .help-search .form-control:focus { box-shadow: 0 0 0 3px rgba(102,126,234,0.3); }
        .help-toc { position: sticky; top: 80px; }
        .help-toc a { display: block; padding: 8px 16px; color: #475569; text-decoration: none; font-size: 0.85rem; border-left: 3px solid transparent; transition: all 0.2s; }
        .help-toc a:hover, .help-toc a.active { color: #1e3c72; border-left-color: #667eea; background: #f1f5f9; font-weight: 600; }
        .help-section { scroll-margin-top: 80px; margin-bottom: 48px; }
        .help-section h3 { font-weight: 700; color: #1e293b; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #e2e8f0; }
        .help-section h3 i { color: #667eea; margin-right: 8px; }
        .help-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .help-card h5 { font-weight: 600; color: #1e293b; margin-bottom: 12px; }
        .help-step { display: flex; gap: 16px; margin-bottom: 16px; align-items: flex-start; }
        .help-step-num { min-width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; flex-shrink: 0; }
        .help-step-content { flex: 1; }
        .help-step-content p { margin-bottom: 4px; color: #475569; font-size: 0.9rem; }
        .help-tip { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 14px 18px; font-size: 0.85rem; color: #1e40af; margin: 12px 0; }
        .help-tip i { margin-right: 6px; }
        .help-warning { background: #fef3c7; border: 1px solid #fde68a; border-radius: 10px; padding: 14px 18px; font-size: 0.85rem; color: #92400e; margin: 12px 0; }
        .help-warning i { margin-right: 6px; }
        .quick-nav { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 32px; }
        .quick-nav-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; text-align: center; text-decoration: none; color: #1e293b; transition: all 0.2s; }
        .quick-nav-item:hover { border-color: #667eea; box-shadow: 0 4px 12px rgba(102,126,234,0.15); color: #1e293b; transform: translateY(-2px); }
        .quick-nav-item i { font-size: 1.5rem; color: #667eea; display: block; margin-bottom: 8px; }
        .quick-nav-item span { font-size: 0.85rem; font-weight: 600; }
        .faq-item { border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 8px; overflow: hidden; }
        .faq-item .faq-q { padding: 14px 18px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 0.9rem; color: #1e293b; background: #f8fafc; }
        .faq-item .faq-q:hover { background: #f1f5f9; }
        .faq-item .faq-a { padding: 0 18px; max-height: 0; overflow: hidden; transition: max-height 0.3s ease, padding 0.3s ease; font-size: 0.85rem; color: #475569; }
        .faq-item.open .faq-a { max-height: 500px; padding: 14px 18px; }
        .faq-item.open .faq-chevron { transform: rotate(180deg); }
        .faq-chevron { transition: transform 0.3s; color: #94a3b8; }
        @media print { .help-toc, .help-search, .quick-nav, nav { display: none !important; } .help-section { page-break-inside: avoid; } }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="vle-content">
    <!-- Hero -->
    <div class="help-hero">
        <div class="row align-items-center">
            <div class="col-md-7">
                <h2><i class="bi bi-person-workspace me-2"></i>Lecturer Portal User Manual</h2>
                <p>Complete guide to teaching, assessment, and management tools</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="help-search">
                    <input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autofocus>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Nav -->
    <div class="quick-nav">
        <a href="#dashboard" class="quick-nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="#content" class="quick-nav-item"><i class="bi bi-folder2-open"></i><span>Course Content</span></a>
        <a href="#assignments" class="quick-nav-item"><i class="bi bi-file-earmark-text"></i><span>Assignments</span></a>
        <a href="#gradebook" class="quick-nav-item"><i class="bi bi-journal-check"></i><span>Gradebook</span></a>
        <a href="#exams" class="quick-nav-item"><i class="bi bi-pencil-square"></i><span>Exam Marking</span></a>
        <a href="#attendance" class="quick-nav-item"><i class="bi bi-calendar-check"></i><span>Attendance</span></a>
        <a href="#live" class="quick-nav-item"><i class="bi bi-camera-video"></i><span>Live Classroom</span></a>
        <a href="#finance" class="quick-nav-item"><i class="bi bi-cash-coin"></i><span>Finance Claims</span></a>
    </div>

    <div class="row">
        <!-- TOC -->
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc">
                <h6 class="text-muted mb-3" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:1px;">Contents</h6>
                <a href="#getting-started">Getting Started</a>
                <a href="#dashboard">Dashboard</a>
                <a href="#content">Course Content</a>
                <a href="#assignments">Assignments</a>
                <a href="#gradebook">Gradebook</a>
                <a href="#exams">Exam Marking</a>
                <a href="#attendance">Attendance</a>
                <a href="#live">Live Classroom</a>
                <a href="#dissertation">Dissertation Supervision</a>
                <a href="#finance">Finance Claims</a>
                <a href="#messages">Messages</a>
                <a href="#forums">Forums</a>
                <a href="#announcements">Announcements</a>
                <a href="#profile">Profile & Settings</a>
                <a href="#faq">FAQ</a>
                <a href="#support">Support</a>
            </div>
        </div>

        <!-- Main -->
        <div class="col-lg-9">

            <!-- Getting Started -->
            <div class="help-section" id="getting-started">
                <h3><i class="bi bi-rocket-takeoff"></i>Getting Started</h3>
                <div class="help-card">
                    <h5>Welcome to the Lecturer Portal</h5>
                    <p>The VLE Lecturer Portal provides everything you need to deliver courses, assess students, and manage your academic work:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p><strong>Login:</strong> Use your credentials to sign in. Change your default password immediately via Profile → Change Password.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p><strong>View Your Courses:</strong> The dashboard shows all courses allocated to you for the current semester.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p><strong>Upload Content:</strong> Start by uploading course materials (notes, slides, readings) for your students.</p></div>
                    </div>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Set up your course content and assignments at the start of the semester so students can plan their workload.
                    </div>
                </div>
            </div>

            <!-- Dashboard -->
            <div class="help-section" id="dashboard">
                <h3><i class="bi bi-speedometer2"></i>Dashboard</h3>
                <div class="help-card">
                    <h5>Your Dashboard Overview</h5>
                    <p>The dashboard gives you a quick summary of your teaching activities:</p>
                    <ul>
                        <li><strong>Assigned Courses:</strong> Cards for each course you teach with student count and quick links.</li>
                        <li><strong>Pending Submissions:</strong> Number of assignments and exams awaiting marking.</li>
                        <li><strong>Announcements:</strong> Recent announcements from administration.</li>
                        <li><strong>Finance Claims:</strong> Status of your submitted finance/travel claims.</li>
                        <li><strong>Attendance Stats:</strong> Overview of attendance records across your courses.</li>
                    </ul>
                </div>
            </div>

            <!-- Course Content -->
            <div class="help-section" id="content">
                <h3><i class="bi bi-folder2-open"></i>Course Content Management</h3>
                <div class="help-card">
                    <h5>Adding Course Content</h5>
                    <p>Upload learning materials for your students:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Add Content</strong> or click <strong>Manage Content</strong> for a specific course.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Select the <strong>course</strong>, give the content a <strong>title</strong> and optional description.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Upload the file (PDF, DOCX, PPTX, etc.) and set the <strong>visibility</strong> (published or draft).</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Click <strong>Save</strong>. Students enrolled in the course can now access the material.</p></div>
                    </div>
                    <h5 class="mt-4">Managing Existing Content</h5>
                    <p>Use <strong>Manage Content</strong> to:</p>
                    <ul>
                        <li>Edit content titles and descriptions</li>
                        <li>Replace uploaded files with updated versions</li>
                        <li>Delete outdated content</li>
                        <li>Reorder content for better organization</li>
                    </ul>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Organize content by week or topic for easy navigation by students.
                    </div>
                </div>
            </div>

            <!-- Assignments -->
            <div class="help-section" id="assignments">
                <h3><i class="bi bi-file-earmark-text"></i>Assignments</h3>
                <div class="help-card">
                    <h5>Creating Assignments</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Add Assignment</strong> from the menu.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Select the <strong>course</strong>, set the <strong>title</strong>, <strong>instructions</strong>, <strong>total marks</strong>, and <strong>due date</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Optionally upload a reference file or rubric.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Click <strong>Create Assignment</strong>. Students will see it in their assignments list.</p></div>
                    </div>
                    <h5 class="mt-4">Marking Assignments</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to the assignment and click <strong>View Submissions</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Download each student's submission to review.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Enter the <strong>grade</strong> and optional <strong>feedback</strong> for each student.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Click <strong>Save Grades</strong>. Students can see their results once published.</p></div>
                    </div>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Use <strong>Edit Assignment</strong> to extend deadlines or update instructions after creation.
                    </div>
                </div>
            </div>

            <!-- Gradebook -->
            <div class="help-section" id="gradebook">
                <h3><i class="bi bi-journal-check"></i>Gradebook</h3>
                <div class="help-card">
                    <h5>Managing Student Grades</h5>
                    <p>The Gradebook provides a comprehensive view of all student grades for your courses:</p>
                    <ul>
                        <li><strong>View all grades:</strong> See assignment grades, exam scores, and overall marks in one place.</li>
                        <li><strong>Enter grades:</strong> Manually enter or edit grades for individual students.</li>
                        <li><strong>Export:</strong> Download the gradebook as a spreadsheet for offline use or record-keeping.</li>
                        <li><strong>Filter:</strong> Filter by course, student, or assessment type.</li>
                    </ul>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Important:</strong> Double-check grades before publishing. Once students can see their grades, corrections should be communicated clearly.
                    </div>
                </div>
            </div>

            <!-- Exam Marking -->
            <div class="help-section" id="exams">
                <h3><i class="bi bi-pencil-square"></i>Exam Marking</h3>
                <div class="help-card">
                    <h5>Marking Online Exams</h5>
                    <p>After students complete online exams, you need to review and mark them:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Exam Marking</strong> from the menu.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Select the exam you want to mark. View the list of student submissions.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Click <strong>Mark</strong> next to each student to review their answers and assign marks per question.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Click <strong>Save</strong> after marking each submission. The system calculates total scores automatically.</p></div>
                    </div>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Multiple-choice and true/false questions are auto-graded. You only need to manually mark short-answer and essay questions.
                    </div>
                </div>
            </div>

            <!-- Attendance -->
            <div class="help-section" id="attendance">
                <h3><i class="bi bi-calendar-check"></i>Attendance</h3>
                <div class="help-card">
                    <h5>Managing Attendance</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Attendance Register</strong> and select your course.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Click <strong>Start Session</strong> to open a new attendance session for today's class.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Students confirm their attendance on their portals while the session is active.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Close the session when the confirmation window ends. You can view attendance reports any time.</p></div>
                    </div>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Note:</strong> Attendance sessions should be opened during class so students can confirm in real time.
                    </div>
                </div>
            </div>

            <!-- Live Classroom -->
            <div class="help-section" id="live">
                <h3><i class="bi bi-camera-video"></i>Live Classroom</h3>
                <div class="help-card">
                    <h5>Starting a Live Class</h5>
                    <p>Conduct live online classes with your students:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Live Classroom</strong> and click <strong>Create Session</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Select the course, set the <strong>date/time</strong>, and add a <strong>description/topic</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Students receive an invitation and can join from <strong>Live Invites</strong> on their portal.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Start the session at the scheduled time. Use screen sharing, whiteboard, and chat tools.</p></div>
                    </div>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Create sessions well in advance so students can plan. Remind them via announcements.
                    </div>
                </div>
            </div>

            <!-- Dissertation Supervision -->
            <div class="help-section" id="dissertation">
                <h3><i class="bi bi-journal-text"></i>Dissertation Supervision</h3>
                <div class="help-card">
                    <h5>Supervising Student Dissertations</h5>
                    <p>If you are assigned as a dissertation supervisor, you can:</p>
                    <ul>
                        <li><strong>View Assigned Students:</strong> See the list of students you are supervising.</li>
                        <li><strong>Review Submissions:</strong> Students upload dissertation chapters for your review.</li>
                        <li><strong>Provide Feedback:</strong> Comment on submissions and guide students through the writing process.</li>
                        <li><strong>Track Progress:</strong> Monitor each student's progress through the dissertation stages.</li>
                    </ul>
                </div>
            </div>

            <!-- Finance Claims -->
            <div class="help-section" id="finance">
                <h3><i class="bi bi-cash-coin"></i>Finance Claims</h3>
                <div class="help-card">
                    <h5>Submitting Finance/Travel Claims</h5>
                    <p>Submit claims for travel, teaching materials, or other expenses:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Request Finance</strong> from the menu.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Fill in the claim form: <strong>claim type</strong>, <strong>amount</strong>, <strong>description</strong>, and <strong>supporting documents</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Upload receipts or proof documents.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Submit the claim. It goes through an approval workflow (HOD → Dean → Finance).</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">5</div>
                        <div class="help-step-content"><p>Track the status of your claims on the dashboard or finance claims page.</p></div>
                    </div>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Note:</strong> Claims require approval from your HOD, Dean, and finance office before processing. Ensure all supporting documents are attached.
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <div class="help-section" id="messages">
                <h3><i class="bi bi-chat-dots"></i>Messages</h3>
                <div class="help-card">
                    <h5>Communication</h5>
                    <p>Use the built-in messaging system to communicate with students, other lecturers, and administrators:</p>
                    <ul>
                        <li>Go to <strong>Messages</strong> to view your inbox.</li>
                        <li>Click <strong>New Message</strong> to compose and send to any VLE user.</li>
                        <li>Reply directly from the message thread.</li>
                    </ul>
                </div>
            </div>

            <!-- Forums -->
            <div class="help-section" id="forums">
                <h3><i class="bi bi-chat-square-text"></i>Forums</h3>
                <div class="help-card">
                    <h5>Course Discussion Forums</h5>
                    <p>Create and moderate discussion forums for your courses:</p>
                    <ul>
                        <li>Create new discussion topics for students to engage with.</li>
                        <li>Respond to student questions and monitor discussions.</li>
                        <li>Moderate inappropriate posts if needed.</li>
                    </ul>
                </div>
            </div>

            <!-- Announcements -->
            <div class="help-section" id="announcements">
                <h3><i class="bi bi-megaphone"></i>Announcements</h3>
                <div class="help-card">
                    <h5>Posting Announcements</h5>
                    <p>Keep your students informed by posting announcements:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Announcements</strong> and click <strong>New Announcement</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Select the target audience (specific course or all students).</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Write the announcement and click <strong>Post</strong>.</p></div>
                    </div>
                </div>
            </div>

            <!-- Profile -->
            <div class="help-section" id="profile">
                <h3><i class="bi bi-person-circle"></i>Profile & Settings</h3>
                <div class="help-card">
                    <h5>Managing Your Account</h5>
                    <ul>
                        <li><strong>My Profile:</strong> View your details — name, employee ID, department, courses assigned.</li>
                        <li><strong>Change Password:</strong> Update your password from the profile dropdown.</li>
                        <li><strong>Theme Settings:</strong> Customize VLE appearance (light/dark mode).</li>
                        <li><strong>Guideline Book:</strong> Access institutional guidelines for teaching and assessment.</li>
                    </ul>
                </div>
            </div>

            <!-- FAQ -->
            <div class="help-section" id="faq">
                <h3><i class="bi bi-question-circle"></i>Frequently Asked Questions</h3>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I add a new course? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Use <strong>Create Course</strong> from the menu. Fill in the course code, name, credits, and other details. Note: Course allocation to you is typically managed by the admin or HOD.</div>
                </div>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">Students can't see my uploaded content. Why? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Check if the content is set to <strong>Published</strong> (not Draft). Also verify that students are enrolled in the course. Students with unpaid fees may have restricted access.</div>
                </div>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">Can I extend an assignment deadline? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Yes. Go to the assignment, click <strong>Edit Assignment</strong>, change the due date, and save.</div>
                </div>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I track my finance claim status? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Go to <strong>Request Finance</strong> to see all your submitted claims and their approval status (Pending, Approved by HOD, Approved by Dean, Processed).</div>
                </div>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I download all student submissions at once? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">On the assignment submissions page, look for the <strong>Download All</strong> button to download all submissions as a ZIP file.</div>
                </div>
            </div>

            <!-- Support -->
            <div class="help-section" id="support">
                <h3><i class="bi bi-headset"></i>Support & Contact</h3>
                <div class="help-card">
                    <h5>Need More Help?</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                                <h6><i class="bi bi-envelope me-2 text-primary"></i>IT Helpdesk</h6>
                                <p class="mb-0" style="font-size:0.85rem;">Contact IT support for technical issues with the VLE platform.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                                <h6><i class="bi bi-building me-2 text-primary"></i>HOD / Dean</h6>
                                <p class="mb-0" style="font-size:0.85rem;">For academic and administrative matters, contact your Head of Department or Dean.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mb-4">
                <button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-2"></i>Print This Manual</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/session-timeout.js"></script>
<script>
document.getElementById('helpSearch').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('.help-section').forEach(function(sec) { sec.style.display = (!q || sec.textContent.toLowerCase().includes(q)) ? '' : 'none'; });
    document.querySelectorAll('.quick-nav-item').forEach(function(item) { item.style.display = (!q || item.textContent.toLowerCase().includes(q)) ? '' : 'none'; });
});
var tocLinks = document.querySelectorAll('.help-toc a'), sections = document.querySelectorAll('.help-section');
window.addEventListener('scroll', function() {
    var sp = window.scrollY + 120;
    sections.forEach(function(sec) { if (sec.offsetTop <= sp && sec.offsetTop + sec.offsetHeight > sp) { tocLinks.forEach(function(l) { l.classList.remove('active'); }); var m = document.querySelector('.help-toc a[href="#' + sec.id + '"]'); if (m) m.classList.add('active'); } });
});
</script>
</body>
</html>
