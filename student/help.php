<?php
/**
 * Student Portal - Help & User Manual
 * Comprehensive guide for all student features
 */
require_once '../includes/auth.php';
requireLogin();
requireRole(['student', 'dissertation_student']);
$user = getCurrentUser();
$conn = getDbConnection();
$breadcrumbs = [['title' => 'Help & User Manual']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help & User Manual - Student Portal</title>
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
        .help-img-placeholder { background: #f1f5f9; border: 2px dashed #cbd5e1; border-radius: 10px; padding: 30px; text-align: center; color: #94a3b8; margin: 12px 0; font-size: 0.85rem; }
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
        .kbd { background: #f1f5f9; border: 1px solid #d1d5db; border-radius: 4px; padding: 2px 6px; font-size: 0.8rem; font-family: monospace; }
        @media print { .help-toc, .help-search, .quick-nav, nav, .vle-page-header { display: none !important; } .help-section { page-break-inside: avoid; } }
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="vle-content">
    <!-- Hero Section -->
    <div class="help-hero">
        <div class="row align-items-center">
            <div class="col-md-7">
                <h2><i class="bi bi-mortarboard me-2"></i>Student Portal User Manual</h2>
                <p>Everything you need to know about using the Virtual Learning Environment</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="help-search">
                    <input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autofocus>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Navigation -->
    <div class="quick-nav">
        <a href="#dashboard" class="quick-nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="#courses" class="quick-nav-item"><i class="bi bi-book"></i><span>My Courses</span></a>
        <a href="#assignments" class="quick-nav-item"><i class="bi bi-file-earmark-text"></i><span>Assignments</span></a>
        <a href="#exams" class="quick-nav-item"><i class="bi bi-pencil-square"></i><span>Exams</span></a>
        <a href="#payments" class="quick-nav-item"><i class="bi bi-credit-card"></i><span>Payments</span></a>
        <a href="#dissertation" class="quick-nav-item"><i class="bi bi-journal-text"></i><span>Dissertation</span></a>
        <a href="#messages" class="quick-nav-item"><i class="bi bi-chat-dots"></i><span>Messages</span></a>
        <a href="#profile" class="quick-nav-item"><i class="bi bi-person-circle"></i><span>Profile</span></a>
    </div>

    <div class="row">
        <!-- Table of Contents (Sidebar) -->
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc">
                <h6 class="text-muted mb-3" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:1px;">Contents</h6>
                <a href="#getting-started">Getting Started</a>
                <a href="#dashboard">Dashboard</a>
                <a href="#courses">My Courses</a>
                <a href="#course-content">Course Content</a>
                <a href="#register-courses">Register Courses</a>
                <a href="#assignments">Assignments</a>
                <a href="#exams">Exams</a>
                <a href="#attendance">Attendance</a>
                <a href="#payments">Payments & Finance</a>
                <a href="#dissertation">Dissertation</a>
                <a href="#messages">Messages</a>
                <a href="#forums">Forums</a>
                <a href="#announcements">Announcements</a>
                <a href="#live-classes">Live Classes</a>
                <a href="#reports">Semester Reports</a>
                <a href="#profile">Profile & Settings</a>
                <a href="#faq">FAQ</a>
                <a href="#support">Support</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-9">

            <!-- Getting Started -->
            <div class="help-section" id="getting-started">
                <h3><i class="bi bi-rocket-takeoff"></i>Getting Started</h3>
                <div class="help-card">
                    <h5>Welcome to the VLE Student Portal</h5>
                    <p>The Virtual Learning Environment (VLE) is your central hub for all academic activities. Here's how to get started:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content">
                            <p><strong>Login:</strong> Use the username and password provided in your welcome email. Visit the login page and enter your credentials.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content">
                            <p><strong>Change Your Password:</strong> On first login, you should change your temporary password. Go to <strong>Profile → Change Password</strong>.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content">
                            <p><strong>Explore Your Dashboard:</strong> The dashboard shows your enrolled courses, upcoming assignments, announcements, and payment status at a glance.</p>
                        </div>
                    </div>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Bookmark the VLE login page for quick access. Your session will expire after inactivity, so save your work regularly.
                    </div>
                </div>
            </div>

            <!-- Dashboard -->
            <div class="help-section" id="dashboard">
                <h3><i class="bi bi-speedometer2"></i>Dashboard</h3>
                <div class="help-card">
                    <h5>Your Dashboard Overview</h5>
                    <p>The dashboard is the first page you see after logging in. It provides a summary of your academic activities:</p>
                    <ul>
                        <li><strong>Enrolled Courses:</strong> Cards showing your currently enrolled courses with quick links to content and assignments.</li>
                        <li><strong>Upcoming Deadlines:</strong> Assignment due dates and exam schedules displayed prominently.</li>
                        <li><strong>Announcements:</strong> Latest announcements from your lecturers and the university administration.</li>
                        <li><strong>Payment Status:</strong> Overview of your financial standing — fees paid vs. outstanding balance.</li>
                        <li><strong>Attendance Summary:</strong> Your attendance percentage across courses.</li>
                    </ul>
                    <div class="help-img-placeholder">
                        <i class="bi bi-image" style="font-size:2rem;"></i><br>
                        Dashboard view showing course cards, announcements, and status widgets
                    </div>
                </div>
            </div>

            <!-- My Courses -->
            <div class="help-section" id="courses">
                <h3><i class="bi bi-book"></i>My Courses</h3>
                <div class="help-card">
                    <h5>Viewing Your Courses</h5>
                    <p>Navigate to <strong>My Courses</strong> from the sidebar menu to see all courses you are enrolled in for the current semester.</p>
                    <ul>
                        <li>Each course card shows the <strong>course code</strong>, <strong>course name</strong>, <strong>lecturer</strong>, and <strong>your progress</strong>.</li>
                        <li>Click on a course card to access its <strong>content, assignments, and forum</strong>.</li>
                        <li>Courses are organized by <strong>year of study</strong> and <strong>semester</strong>.</li>
                    </ul>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> If you don't see a course you should be enrolled in, contact your department or the admin office.
                    </div>
                </div>
            </div>

            <!-- Course Content -->
            <div class="help-section" id="course-content">
                <h3><i class="bi bi-folder2-open"></i>Course Content</h3>
                <div class="help-card">
                    <h5>Accessing Learning Materials</h5>
                    <p>Each course has a dedicated content section where lecturers upload learning materials:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content">
                            <p>Go to <strong>My Courses</strong> and click on a course.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content">
                            <p>Select the <strong>Course Content</strong> tab to view uploaded files, notes, and resources.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content">
                            <p>Click the <strong>download</strong> icon next to a file to download it. Some files may require a download request approval.</p>
                        </div>
                    </div>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Note:</strong> Access to course content may be restricted if you have outstanding fee balances. Please ensure your payments are up to date.
                    </div>
                </div>
            </div>

            <!-- Register Courses -->
            <div class="help-section" id="register-courses">
                <h3><i class="bi bi-plus-circle"></i>Register Courses</h3>
                <div class="help-card">
                    <h5>Course Registration</h5>
                    <p>At the beginning of each semester, you may need to register for courses:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content">
                            <p>Navigate to <strong>Register Courses</strong> from the menu.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content">
                            <p>Browse available courses for your program, year, and semester.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content">
                            <p>Select courses and click <strong>Register</strong>. Maximum of 7 courses per semester.</p>
                        </div>
                    </div>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Consult your academic advisor or HOD before registering for elective courses.
                    </div>
                </div>
            </div>

            <!-- Assignments -->
            <div class="help-section" id="assignments">
                <h3><i class="bi bi-file-earmark-text"></i>Assignments</h3>
                <div class="help-card">
                    <h5>Viewing & Submitting Assignments</h5>
                    <p>Lecturers create assignments that you must complete and submit online.</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content">
                            <p>Go to <strong>My Assignments</strong> or click on an assignment from a course page.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content">
                            <p>Read the assignment instructions carefully, including the <strong>due date</strong> and <strong>marks allocation</strong>.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content">
                            <p>Upload your work as a file (PDF, DOCX, etc.) or answer questions directly on the page.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content">
                            <p>Click <strong>Submit</strong>. You will receive a confirmation once your submission is recorded.</p>
                        </div>
                    </div>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Important:</strong> Late submissions may not be accepted. Always submit before the deadline. You can view your grades once the lecturer marks your work.
                    </div>
                </div>
            </div>

            <!-- Exams -->
            <div class="help-section" id="exams">
                <h3><i class="bi bi-pencil-square"></i>Exams</h3>
                <div class="help-card">
                    <h5>Taking Online Exams</h5>
                    <p>Online exams are conducted through the VLE with time limits and access tokens.</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content">
                            <p>Go to <strong>Take Exam</strong> from the menu when an exam is scheduled.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content">
                            <p>Enter the <strong>exam access token</strong> provided by your examination officer or invigilator.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content">
                            <p>Once the exam starts, a <strong>countdown timer</strong> will appear. Answer all questions within the allocated time.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content">
                            <p>Click <strong>Submit Exam</strong> when finished. The exam auto-submits when time expires.</p>
                        </div>
                    </div>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Important:</strong> Do not close your browser or navigate away during an exam — your progress may be lost. Ensure a stable internet connection beforehand.
                    </div>
                </div>
            </div>

            <!-- Attendance -->
            <div class="help-section" id="attendance">
                <h3><i class="bi bi-calendar-check"></i>Attendance</h3>
                <div class="help-card">
                    <h5>Registering Attendance</h5>
                    <p>Lecturers open attendance sessions that you must confirm during class:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content">
                            <p>When your lecturer starts an attendance session, go to <strong>Attendance Register</strong>.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content">
                            <p>Find the active session for your course and click <strong>Confirm Attendance</strong>.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content">
                            <p>Your attendance is recorded with a timestamp. You can view your attendance history per course.</p>
                        </div>
                    </div>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Attendance sessions have a limited window. Confirm as soon as the session is opened to avoid missing it.
                    </div>
                </div>
            </div>

            <!-- Payments & Finance -->
            <div class="help-section" id="payments">
                <h3><i class="bi bi-credit-card"></i>Payments & Finance</h3>
                <div class="help-card">
                    <h5>Viewing Your Financial Status</h5>
                    <p>Go to <strong>Payment History</strong> to see:</p>
                    <ul>
                        <li><strong>Total Fees:</strong> The amount you owe for the current semester or year.</li>
                        <li><strong>Payments Made:</strong> All recorded payments with dates and receipt numbers.</li>
                        <li><strong>Outstanding Balance:</strong> The remaining amount due.</li>
                    </ul>
                    <h5 class="mt-4">Submitting a Payment Proof</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content">
                            <p>Go to <strong>Submit Payment</strong> from the menu.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content">
                            <p>Enter the <strong>payment amount</strong>, <strong>payment method</strong> (bank transfer, mobile money, etc.), and <strong>reference number</strong>.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content">
                            <p>Upload a <strong>proof of payment</strong> screenshot or receipt image.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content">
                            <p>Click <strong>Submit</strong>. The finance office will review and approve your payment.</p>
                        </div>
                    </div>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Note:</strong> Access to some course content and exams may be restricted until your fees are sufficiently paid.
                    </div>
                </div>
            </div>

            <!-- Dissertation -->
            <div class="help-section" id="dissertation">
                <h3><i class="bi bi-journal-text"></i>Dissertation</h3>
                <div class="help-card">
                    <h5>Managing Your Dissertation</h5>
                    <p>For final-year and postgraduate students working on a dissertation:</p>
                    <ul>
                        <li><strong>View Supervisor:</strong> See your assigned dissertation supervisor and their contact details.</li>
                        <li><strong>Submit Chapters:</strong> Upload dissertation chapters for review by your supervisor.</li>
                        <li><strong>Ethics Form:</strong> Complete and submit the online ethics approval form.</li>
                        <li><strong>Guidelines:</strong> Access dissertation formatting guidelines and the guideline book.</li>
                        <li><strong>Defense Schedule:</strong> Check the defense schedule once announced.</li>
                    </ul>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Keep in regular contact with your supervisor through the Messages feature. Submit chapters well before the deadline for feedback.
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <div class="help-section" id="messages">
                <h3><i class="bi bi-chat-dots"></i>Messages</h3>
                <div class="help-card">
                    <h5>Using the Messaging System</h5>
                    <p>The VLE has a built-in messaging system for communication with lecturers and administrators:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content">
                            <p>Go to <strong>Messages</strong> from the menu.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content">
                            <p>Click <strong>New Message</strong> to compose. Select the recipient and type your message.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content">
                            <p>View received messages in your inbox. Click on a message to read and reply.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Forums -->
            <div class="help-section" id="forums">
                <h3><i class="bi bi-chat-square-text"></i>Forums</h3>
                <div class="help-card">
                    <h5>Course Discussion Forums</h5>
                    <p>Each course may have discussion forums for collaborative learning:</p>
                    <ul>
                        <li>Access forums from the course page or <strong>Forums</strong> menu.</li>
                        <li>Read existing discussions and contribute by posting replies.</li>
                        <li>Create new discussion topics when you have questions.</li>
                        <li>Be respectful and academic in your forum contributions.</li>
                    </ul>
                </div>
            </div>

            <!-- Announcements -->
            <div class="help-section" id="announcements">
                <h3><i class="bi bi-megaphone"></i>Announcements</h3>
                <div class="help-card">
                    <h5>Staying Updated</h5>
                    <p>Announcements are posted by lecturers and administrators. Check the <strong>Announcements</strong> page regularly for:</p>
                    <ul>
                        <li>Class schedule changes</li>
                        <li>Assignment deadline extensions</li>
                        <li>Exam timetable updates</li>
                        <li>University-wide notices</li>
                        <li>Payment deadline reminders</li>
                    </ul>
                </div>
            </div>

            <!-- Live Classes -->
            <div class="help-section" id="live-classes">
                <h3><i class="bi bi-camera-video"></i>Live Classes</h3>
                <div class="help-card">
                    <h5>Joining Live Sessions</h5>
                    <p>Lecturers may schedule live online classes:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content">
                            <p>Go to <strong>Live Invites</strong> to see scheduled and active sessions.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content">
                            <p>Click <strong>Join</strong> to enter the live classroom when the session starts.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content">
                            <p>Ensure your microphone and camera are working. Use the chat panel for questions.</p>
                        </div>
                    </div>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Test your internet connection before joining. Use a quiet environment and headphones for the best experience.
                    </div>
                </div>
            </div>

            <!-- Semester Reports -->
            <div class="help-section" id="reports">
                <h3><i class="bi bi-file-bar-graph"></i>Semester Reports</h3>
                <div class="help-card">
                    <h5>Viewing Academic Reports</h5>
                    <p>At the end of each semester, you can view your academic report:</p>
                    <ul>
                        <li><strong>Semester Report:</strong> View your grades for all courses in a semester.</li>
                        <li><strong>Mid-Semester Report:</strong> Check your progress during the semester (if available).</li>
                        <li>Reports can be <strong>downloaded as PDF</strong> for your records.</li>
                    </ul>
                </div>
            </div>

            <!-- Profile & Settings -->
            <div class="help-section" id="profile">
                <h3><i class="bi bi-person-circle"></i>Profile & Settings</h3>
                <div class="help-card">
                    <h5>Managing Your Profile</h5>
                    <p>Keep your profile information up to date:</p>
                    <ul>
                        <li><strong>My Profile:</strong> View your student details — name, student ID, program, department, campus.</li>
                        <li><strong>Change Password:</strong> Update your password regularly for security. Use the profile dropdown → Change Password.</li>
                        <li><strong>Theme Settings:</strong> Customize the appearance of the VLE to your preference (light/dark mode, colors).</li>
                    </ul>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> If any of your personal details are incorrect (name, program, etc.), contact the admin office to have them updated.
                    </div>
                </div>
            </div>

            <!-- FAQ -->
            <div class="help-section" id="faq">
                <h3><i class="bi bi-question-circle"></i>Frequently Asked Questions</h3>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">I forgot my password. How do I reset it? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Click <strong>"Forgot Password"</strong> on the login page and enter your registered email. You will receive a password reset link. Alternatively, contact the admin office to request a password reset.</div>
                </div>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">I can't access course content. What should I do? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Course content access may be restricted due to outstanding fees. Check your <strong>Payment History</strong> and ensure your balance is within the allowed threshold. If payments are up to date, contact the finance office or your lecturer.</div>
                </div>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I know when assignments are due? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Assignment deadlines are shown on your <strong>Dashboard</strong> and on the <strong>My Assignments</strong> page. You may also receive announcements from your lecturers about upcoming deadlines.</div>
                </div>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">My exam token is not working. What do I do? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Exam tokens are single-use and time-limited. Ensure you are entering it correctly (check for spaces). If the token is expired or invalid, contact your <strong>Examination Officer</strong> immediately.</div>
                </div>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">Can I access the VLE on my phone? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Yes, the VLE is fully responsive and works on smartphones and tablets. Simply open your browser and go to the VLE URL. For the best experience during exams, use a laptop or desktop computer.</div>
                </div>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I submit proof of payment? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Go to <strong>Submit Payment</strong>, fill in the payment details (amount, method, reference number), upload a screenshot or photo of your receipt, and click Submit. The finance team will verify and record your payment.</div>
                </div>
                
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">My session keeps expiring. Why? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">For security, the VLE automatically logs you out after a period of inactivity. Stay active on the page or save your work frequently. You will see a warning before the session expires.</div>
                </div>
            </div>

            <!-- Support -->
            <div class="help-section" id="support">
                <h3><i class="bi bi-headset"></i>Support & Contact</h3>
                <div class="help-card">
                    <h5>Need More Help?</h5>
                    <p>If you cannot find the answer to your question above, reach out to the support team:</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                                <h6><i class="bi bi-envelope me-2 text-primary"></i>Email Support</h6>
                                <p class="mb-0" style="font-size:0.85rem;">Contact your institution's IT helpdesk or admin office via email for technical issues.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                                <h6><i class="bi bi-chat-left-text me-2 text-primary"></i>VLE Messages</h6>
                                <p class="mb-0" style="font-size:0.85rem;">Use the Messages feature to contact your lecturers or department for academic queries.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                                <h6><i class="bi bi-cash-coin me-2 text-primary"></i>Finance Office</h6>
                                <p class="mb-0" style="font-size:0.85rem;">For payment and fee-related queries, contact the finance department directly.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="background:#f1f5f9;border-radius:10px;padding:16px;">
                                <h6><i class="bi bi-building me-2 text-primary"></i>Department Office</h6>
                                <p class="mb-0" style="font-size:0.85rem;">For academic issues like course registration, grades, or enrollment, contact your HOD.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Print Button -->
            <div class="text-center mb-4">
                <button class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i>Print This Manual
                </button>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/session-timeout.js"></script>
<script>
// Search functionality
document.getElementById('helpSearch').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('.help-section').forEach(function(sec) {
        var text = sec.textContent.toLowerCase();
        sec.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
    document.querySelectorAll('.quick-nav-item').forEach(function(item) {
        var text = item.textContent.toLowerCase();
        item.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
});

// Active TOC highlighting on scroll
var tocLinks = document.querySelectorAll('.help-toc a');
var sections = document.querySelectorAll('.help-section');
window.addEventListener('scroll', function() {
    var scrollPos = window.scrollY + 120;
    sections.forEach(function(sec, i) {
        if (sec.offsetTop <= scrollPos && sec.offsetTop + sec.offsetHeight > scrollPos) {
            tocLinks.forEach(function(l) { l.classList.remove('active'); });
            var match = document.querySelector('.help-toc a[href="#' + sec.id + '"]');
            if (match) match.classList.add('active');
        }
    });
});
</script>
</body>
</html>
