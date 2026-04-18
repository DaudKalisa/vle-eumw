<?php
/**
 * Examination Officer Portal - Help & User Manual
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
    <title>Help & User Manual - Examination Officer Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .help-hero{background:linear-gradient(135deg,#1e3c72 0%,#2a5298 50%,#667eea 100%);color:#fff;border-radius:16px;padding:40px;margin-bottom:32px}.help-hero h2{font-weight:700;margin-bottom:8px}.help-hero p{opacity:.9;margin-bottom:0}.help-search{max-width:500px}.help-search .form-control{border-radius:50px;padding:12px 20px;border:none;font-size:.95rem}.help-search .form-control:focus{box-shadow:0 0 0 3px rgba(102,126,234,.3)}.help-toc{position:sticky;top:80px}.help-toc a{display:block;padding:8px 16px;color:#475569;text-decoration:none;font-size:.85rem;border-left:3px solid transparent;transition:all .2s}.help-toc a:hover,.help-toc a.active{color:#1e3c72;border-left-color:#667eea;background:#f1f5f9;font-weight:600}.help-section{scroll-margin-top:80px;margin-bottom:48px}.help-section h3{font-weight:700;color:#1e293b;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #e2e8f0}.help-section h3 i{color:#667eea;margin-right:8px}.help-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:16px}.help-card h5{font-weight:600;color:#1e293b;margin-bottom:12px}.help-step{display:flex;gap:16px;margin-bottom:16px;align-items:flex-start}.help-step-num{min-width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0}.help-step-content{flex:1}.help-step-content p{margin-bottom:4px;color:#475569;font-size:.9rem}.help-tip{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;font-size:.85rem;color:#1e40af;margin:12px 0}.help-tip i{margin-right:6px}.help-warning{background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;font-size:.85rem;color:#92400e;margin:12px 0}.help-warning i{margin-right:6px}.quick-nav{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:32px}.quick-nav-item{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center;text-decoration:none;color:#1e293b;transition:all .2s}.quick-nav-item:hover{border-color:#667eea;box-shadow:0 4px 12px rgba(102,126,234,.15);color:#1e293b;transform:translateY(-2px)}.quick-nav-item i{font-size:1.5rem;color:#667eea;display:block;margin-bottom:8px}.quick-nav-item span{font-size:.85rem;font-weight:600}.faq-item{border:1px solid #e2e8f0;border-radius:10px;margin-bottom:8px;overflow:hidden}.faq-item .faq-q{padding:14px 18px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:.9rem;color:#1e293b;background:#f8fafc}.faq-item .faq-q:hover{background:#f1f5f9}.faq-item .faq-a{padding:0 18px;max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s ease;font-size:.85rem;color:#475569}.faq-item.open .faq-a{max-height:500px;padding:14px 18px}.faq-item.open .faq-chevron{transform:rotate(180deg)}.faq-chevron{transition:transform .3s;color:#94a3b8}@media print{.help-toc,.help-search,.quick-nav,nav{display:none!important}.help-section{page-break-inside:avoid}}
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="vle-content">
    <div class="help-hero">
        <div class="row align-items-center">
            <div class="col-md-7">
                <h2><i class="bi bi-file-earmark-text me-2"></i>Examination Officer User Manual</h2>
                <p>Create exams, manage question banks, generate tokens, monitor sessions, and publish results</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="help-search"><input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autofocus></div>
            </div>
        </div>
    </div>

    <div class="quick-nav">
        <a href="#dashboard" class="quick-nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="#create-exams" class="quick-nav-item"><i class="bi bi-plus-circle"></i><span>Create Exams</span></a>
        <a href="#question-bank" class="quick-nav-item"><i class="bi bi-collection"></i><span>Question Bank</span></a>
        <a href="#tokens" class="quick-nav-item"><i class="bi bi-key"></i><span>Exam Tokens</span></a>
        <a href="#monitoring" class="quick-nav-item"><i class="bi bi-eye"></i><span>Monitoring</span></a>
        <a href="#results" class="quick-nav-item"><i class="bi bi-graph-up"></i><span>Results</span></a>
        <a href="#timetable" class="quick-nav-item"><i class="bi bi-calendar3"></i><span>Timetable</span></a>
        <a href="#reports" class="quick-nav-item"><i class="bi bi-file-earmark-bar-graph"></i><span>Reports</span></a>
    </div>

    <div class="row">
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc">
                <h6 class="text-muted mb-3" style="font-size:.75rem;text-transform:uppercase;letter-spacing:1px">Contents</h6>
                <a href="#getting-started">Getting Started</a>
                <a href="#dashboard">Dashboard</a>
                <a href="#create-exams">Create & Edit Exams</a>
                <a href="#question-bank">Question Bank</a>
                <a href="#tokens">Exam Tokens</a>
                <a href="#monitoring">Live Monitoring</a>
                <a href="#results">Results & Publishing</a>
                <a href="#timetable">Exam Timetable</a>
                <a href="#reports">Semester Reports</a>
                <a href="#faq">FAQ</a>
            </div>
        </div>
        <div class="col-lg-9">

            <!-- Getting Started -->
            <div class="help-section" id="getting-started">
                <h3><i class="bi bi-rocket-takeoff"></i>Getting Started</h3>
                <div class="help-card">
                    <h5>Examination Officer Overview</h5>
                    <p>As Examination Officer, you manage the complete examination lifecycle:</p>
                    <ul>
                        <li><strong>Exam Creation</strong> — Set up exams with questions, time limits, and rules</li>
                        <li><strong>Question Bank</strong> — Build and maintain a reusable library of questions</li>
                        <li><strong>Token Management</strong> — Generate secure access tokens for students</li>
                        <li><strong>Live Monitoring</strong> — Watch exam sessions in real time</li>
                        <li><strong>Results</strong> — Review, approve, and publish exam results</li>
                        <li><strong>Timetable</strong> — Create and manage the exam schedule</li>
                        <li><strong>Reports</strong> — Generate semester-wide performance reports</li>
                    </ul>
                </div>
            </div>

            <!-- Dashboard -->
            <div class="help-section" id="dashboard">
                <h3><i class="bi bi-speedometer2"></i>Dashboard</h3>
                <div class="help-card">
                    <h5>Dashboard Overview</h5>
                    <p>Your dashboard provides key exam metrics at a glance:</p>
                    <ul>
                        <li><strong>Upcoming Exams</strong> — Exams scheduled in the next 7 days</li>
                        <li><strong>Active Sessions</strong> — Exams currently being taken</li>
                        <li><strong>Pending Results</strong> — Results awaiting review/publishing</li>
                        <li><strong>Recent Activity</strong> — Latest actions and submissions</li>
                    </ul>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>Cards on the dashboard are clickable — tap any card to navigate to the relevant management page.</div>
                </div>
            </div>

            <!-- Create & Edit Exams -->
            <div class="help-section" id="create-exams">
                <h3><i class="bi bi-plus-circle"></i>Create & Edit Exams</h3>
                <div class="help-card">
                    <h5>Creating a New Exam</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Navigate to <strong>Manage Exams</strong> and click <strong>Create New Exam</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Fill in exam details: <strong>Title</strong>, <strong>Course</strong>, <strong>Semester</strong>, <strong>Duration</strong> (in minutes), and <strong>Total Marks</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Set the <strong>Start Date/Time</strong> and <strong>End Date/Time</strong> for the exam window.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Configure rules: number of attempts allowed, shuffled questions, show results immediately, etc.</p></div></div>
                    <div class="help-step"><div class="help-step-num">5</div><div class="help-step-content"><p>Click <strong>Save as Draft</strong> or <strong>Publish</strong> to make it available to students.</p></div></div>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>Once an exam is published and students have started, you cannot change questions. Only edit exams while they are in Draft status.</div>
                </div>
                <div class="help-card">
                    <h5>Adding Questions to an Exam</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>After creating the exam, click <strong>Add Questions</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Choose to <strong>Create New</strong> or <strong>Import from Question Bank</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>For each question: enter the question text, type (MCQ, True/False, Short Answer, Essay), marks, and options.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>For MCQ questions, mark the correct answer(s). For auto-graded types, set the answer key.</p></div></div>
                    <div class="help-step"><div class="help-step-num">5</div><div class="help-step-content"><p>Rearrange question order by drag-and-drop if desired.</p></div></div>
                </div>
                <div class="help-card">
                    <h5>Editing an Existing Exam</h5>
                    <p>From <strong>Manage Exams</strong>, click the <strong>Edit</strong> button on any draft exam. You can modify all fields, questions, and settings. Published exams can only have their window dates adjusted.</p>
                </div>
            </div>

            <!-- Question Bank -->
            <div class="help-section" id="question-bank">
                <h3><i class="bi bi-collection"></i>Question Bank</h3>
                <div class="help-card">
                    <h5>Building Your Question Library</h5>
                    <p>The Question Bank lets you store reusable questions organized by course and topic:</p>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Question Bank</strong> from the sidebar.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Click <strong>Add Question</strong> and select the course, topic, and difficulty level.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Enter the question, answer options, and correct answer.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Save the question. It can now be imported into any future exam for that course.</p></div></div>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>Tag questions with difficulty levels (Easy, Medium, Hard) to quickly build balanced exams by pulling random sets from each level.</div>
                </div>
            </div>

            <!-- Exam Tokens -->
            <div class="help-section" id="tokens">
                <h3><i class="bi bi-key"></i>Exam Tokens</h3>
                <div class="help-card">
                    <h5>Generating Access Tokens</h5>
                    <p>Exam tokens provide secure, one-time access codes for students to enter an exam:</p>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Exam Tokens</strong> and select the exam.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Click <strong>Generate Tokens</strong>. Choose whether to generate for all enrolled students or a specific group.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Tokens are generated as unique codes. You can <strong>Download as CSV</strong> or <strong>Print</strong> the token list.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Distribute tokens to students through your preferred secure channel (in-person, proctored environment, etc.).</p></div></div>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>Each token is single-use. Once used, it cannot be reused. If a student loses their token, you must generate a replacement.</div>
                </div>
            </div>

            <!-- Live Monitoring -->
            <div class="help-section" id="monitoring">
                <h3><i class="bi bi-eye"></i>Live Monitoring</h3>
                <div class="help-card">
                    <h5>Monitoring Active Exams</h5>
                    <p>During an active exam session, use <strong>Monitoring</strong> to track progress in real time:</p>
                    <ul>
                        <li><strong>Students Online</strong> — Number of students currently taking the exam</li>
                        <li><strong>Progress Bars</strong> — How far each student has progressed through questions</li>
                        <li><strong>Time Remaining</strong> — Individual countdown timers</li>
                        <li><strong>Suspicious Activity</strong> — Flag for tab switches, copy attempts, or multiple logins</li>
                    </ul>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>If you detect suspicious activity, you can suspend an individual student's session and investigate before allowing them to continue.</div>
                </div>
            </div>

            <!-- Results & Publishing -->
            <div class="help-section" id="results">
                <h3><i class="bi bi-graph-up"></i>Results & Publishing</h3>
                <div class="help-card">
                    <h5>Reviewing & Publishing Results</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>After the exam window closes, go to <strong>Exam Results</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Auto-graded questions (MCQ, True/False) are scored automatically. Essay/short answer questions require manual marking.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Review the results summary: average score, pass rate, and score distribution chart.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Click <strong>Publish Results</strong> to make scores visible to students.</p></div></div>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>Once results are published, students can see their scores immediately. Ensure all manual marking is complete before publishing.</div>
                </div>
            </div>

            <!-- Exam Timetable -->
            <div class="help-section" id="timetable">
                <h3><i class="bi bi-calendar3"></i>Exam Timetable</h3>
                <div class="help-card">
                    <h5>Managing the Exam Schedule</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Navigate to <strong>Manage Exam Timetable</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Select the semester and academic year.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Add exam slots: select the course, date, time, duration, and venue.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>The system will highlight any clashes (same student enrolled in overlapping exams).</p></div></div>
                    <div class="help-step"><div class="help-step-num">5</div><div class="help-step-content"><p>Once finalized, click <strong>Publish Timetable</strong>. Students and lecturers can then view it.</p></div></div>
                </div>
            </div>

            <!-- Semester Reports -->
            <div class="help-section" id="reports">
                <h3><i class="bi bi-file-earmark-bar-graph"></i>Semester Reports</h3>
                <div class="help-card">
                    <h5>Generating Exam Reports</h5>
                    <p>Generate comprehensive reports at the end of each semester:</p>
                    <ul>
                        <li><strong>Exam Summary</strong> — All exams with pass/fail rates and averages</li>
                        <li><strong>Student Performance</strong> — Individual student results across all exams</li>
                        <li><strong>Course Analysis</strong> — Performance breakdown by course</li>
                        <li><strong>Question Analysis</strong> — Which questions were most/least answered correctly</li>
                    </ul>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Semester Reports</strong> or <strong>Generate Exam Report</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Select the report type, semester, and filters.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Click <strong>Generate</strong>. Export as PDF or Excel for archiving.</p></div></div>
                </div>
            </div>

            <!-- FAQ -->
            <div class="help-section" id="faq">
                <h3><i class="bi bi-question-circle"></i>Frequently Asked Questions</h3>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Can I extend the exam time for a specific student?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Yes. From the Monitoring page, find the student and click <strong>Extend Time</strong>. Enter the additional minutes and confirm. This is commonly used for students with approved accommodations.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">A student says their token doesn't work — what do I do?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Check if the token has already been used (it's single-use). If it shows as unused and still doesn't work, generate a new replacement token for that student from the Exam Tokens page.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I import questions from a previous exam?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">When adding questions to a new exam, click <strong>Import from Question Bank</strong>. You can filter by course and topic, then select individual questions or bulk-import from a previous exam's question set.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Can I unpublish results after publishing?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Contact the System Administrator if you need to retract published results. This action requires elevated privileges and should only be done in exceptional circumstances.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I handle exam clashes in the timetable?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">The system automatically detects clashes when you add exam slots. Reschedule one of the conflicting exams or arrange a separate sitting for affected students.</div></div>
            </div>

            <!-- Print/Contact -->
            <div class="text-center py-4" style="border-top:2px solid #e2e8f0;">
                <button onclick="window.print()" class="btn btn-outline-primary me-2"><i class="bi bi-printer me-1"></i>Print Manual</button>
                <a href="dashboard.php" class="btn btn-primary"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>
                <p class="text-muted mt-3" style="font-size:.8rem;">EUMW Virtual Learning Environment &copy; <?= date('Y') ?></p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/session-timeout.js"></script>
<script>
document.getElementById('helpSearch').addEventListener('input',function(){var q=this.value.toLowerCase();document.querySelectorAll('.help-section').forEach(function(s){s.style.display=s.textContent.toLowerCase().includes(q)?'':'none'})});
var tocLinks=document.querySelectorAll('.help-toc a');
window.addEventListener('scroll',function(){var fromTop=window.scrollY+100;tocLinks.forEach(function(link){var sec=document.querySelector(link.getAttribute('href'));if(sec&&sec.offsetTop<=fromTop&&sec.offsetTop+sec.offsetHeight>fromTop){link.classList.add('active')}else{link.classList.remove('active')}})});
</script>
</body>
</html>
