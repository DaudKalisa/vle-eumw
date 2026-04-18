<?php
/**
 * ODL Coordinator Portal - Help & User Manual
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
    <title>Help & User Manual - ODL Coordinator Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <style>
        .help-hero{background:linear-gradient(135deg,#1e3c72 0%,#2a5298 50%,#667eea 100%);color:#fff;border-radius:16px;padding:40px;margin-bottom:32px}.help-hero h2{font-weight:700;margin-bottom:8px}.help-hero p{opacity:.9;margin-bottom:0}.help-search{max-width:500px}.help-search .form-control{border-radius:50px;padding:12px 20px;border:none;font-size:.95rem}.help-search .form-control:focus{box-shadow:0 0 0 3px rgba(102,126,234,.3)}.help-toc{position:sticky;top:80px}.help-toc a{display:block;padding:8px 16px;color:#475569;text-decoration:none;font-size:.85rem;border-left:3px solid transparent;transition:all .2s}.help-toc a:hover,.help-toc a.active{color:#1e3c72;border-left-color:#667eea;background:#f1f5f9;font-weight:600}.help-section{scroll-margin-top:80px;margin-bottom:48px}.help-section h3{font-weight:700;color:#1e293b;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #e2e8f0}.help-section h3 i{color:#667eea;margin-right:8px}.help-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:16px}.help-card h5{font-weight:600;color:#1e293b;margin-bottom:12px}.help-step{display:flex;gap:16px;margin-bottom:16px;align-items:flex-start}.help-step-num{min-width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0}.help-step-content{flex:1}.help-step-content p{margin-bottom:4px;color:#475569;font-size:.9rem}.help-tip{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;font-size:.85rem;color:#1e40af;margin:12px 0}.help-tip i{margin-right:6px}.help-warning{background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;font-size:.85rem;color:#92400e;margin:12px 0}.help-warning i{margin-right:6px}.quick-nav{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:32px}.quick-nav-item{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center;text-decoration:none;color:#1e293b;transition:all .2s}.quick-nav-item:hover{border-color:#667eea;box-shadow:0 4px 12px rgba(102,126,234,.15);color:#1e293b;transform:translateY(-2px)}.quick-nav-item i{font-size:1.5rem;color:#667eea;display:block;margin-bottom:8px}.quick-nav-item span{font-size:.85rem;font-weight:600}.faq-item{border:1px solid #e2e8f0;border-radius:10px;margin-bottom:8px;overflow:hidden}.faq-item .faq-q{padding:14px 18px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:.9rem;color:#1e293b;background:#f8fafc}.faq-item .faq-q:hover{background:#f1f5f9}.faq-item .faq-a{padding:0 18px;max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s ease;font-size:.85rem;color:#475569}.faq-item.open .faq-a{max-height:500px;padding:14px 18px}.faq-item.open .faq-chevron{transform:rotate(180deg)}.faq-chevron{transition:transform .3s;color:#94a3b8}@media print{.help-toc,.help-search,.quick-nav,nav{display:none!important}.help-section{page-break-inside:avoid}}
    </style>
</head>
<body>
<?php include 'header_nav.php'; ?>
<div class="vle-content">
    <div class="help-hero">
        <div class="row align-items-center">
            <div class="col-md-7">
                <h2><i class="bi bi-globe me-2"></i>ODL Coordinator User Manual</h2>
                <p>Open & Distance Learning — courses, enrolments, timetables, claims, exams, and student progress</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="help-search"><input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autofocus></div>
            </div>
        </div>
    </div>

    <div class="quick-nav">
        <a href="#dashboard" class="quick-nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="#courses" class="quick-nav-item"><i class="bi bi-book"></i><span>Courses</span></a>
        <a href="#students" class="quick-nav-item"><i class="bi bi-people"></i><span>Students</span></a>
        <a href="#timetable" class="quick-nav-item"><i class="bi bi-calendar3"></i><span>Timetable</span></a>
        <a href="#claims" class="quick-nav-item"><i class="bi bi-receipt"></i><span>Claims</span></a>
        <a href="#exams" class="quick-nav-item"><i class="bi bi-file-earmark-text"></i><span>Exams</span></a>
        <a href="#reports" class="quick-nav-item"><i class="bi bi-graph-up"></i><span>Reports</span></a>
    </div>

    <div class="row">
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc">
                <h6 class="text-muted mb-3" style="font-size:.75rem;text-transform:uppercase;letter-spacing:1px">Contents</h6>
                <a href="#getting-started">Getting Started</a>
                <a href="#dashboard">Dashboard</a>
                <a href="#courses">Course Management</a>
                <a href="#course-alloc">Course Allocation</a>
                <a href="#course-monitor">Course Monitoring</a>
                <a href="#students">Student Management</a>
                <a href="#student-progress">Student Progress</a>
                <a href="#timetable">Timetable</a>
                <a href="#claims">Claims Approval</a>
                <a href="#exams">Exam Management</a>
                <a href="#reports">Reports & Logs</a>
                <a href="#faq">FAQ</a>
            </div>
        </div>
        <div class="col-lg-9">

            <!-- Getting Started -->
            <div class="help-section" id="getting-started">
                <h3><i class="bi bi-rocket-takeoff"></i>Getting Started</h3>
                <div class="help-card">
                    <h5>ODL Coordinator Overview</h5>
                    <p>As ODL Coordinator, you manage all aspects of the Open and Distance Learning programme:</p>
                    <ul>
                        <li><strong>Course Management</strong> — Create, allocate, and monitor ODL courses</li>
                        <li><strong>Student Enrolment</strong> — Enrol, verify, and track ODL students</li>
                        <li><strong>Timetabling</strong> — Schedule residential sessions and online classes</li>
                        <li><strong>Claims Approval</strong> — Review and approve lecturer payment claims</li>
                        <li><strong>Exam Management</strong> — Coordinate ODL examinations</li>
                        <li><strong>Reporting</strong> — Generate progress, enrolment, and activity reports</li>
                    </ul>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>ODL operates differently from conventional programmes — residential sessions, online tutorials, and flexible deadlines are key components.</div>
                </div>
            </div>

            <!-- Dashboard -->
            <div class="help-section" id="dashboard">
                <h3><i class="bi bi-speedometer2"></i>Dashboard</h3>
                <div class="help-card">
                    <h5>Dashboard Overview</h5>
                    <p>Your dashboard shows ODL programme metrics:</p>
                    <ul>
                        <li><strong>Active Students</strong> — Current ODL enrolment count</li>
                        <li><strong>Courses Running</strong> — Courses active this semester</li>
                        <li><strong>Pending Claims</strong> — Lecturer claims awaiting your approval</li>
                        <li><strong>Upcoming Sessions</strong> — Next scheduled residential or online sessions</li>
                        <li><strong>Verification Requests</strong> — Students pending identity/document verification</li>
                    </ul>
                </div>
            </div>

            <!-- Course Management -->
            <div class="help-section" id="courses">
                <h3><i class="bi bi-book"></i>Course Management</h3>
                <div class="help-card">
                    <h5>Managing ODL Courses</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Navigate to <strong>Manage Courses</strong> from the sidebar.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>View all ODL courses with their status, allocated lecturers, and student counts.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Click <strong>Add Course</strong> to register a new ODL course, or <strong>Edit</strong> to modify existing ones.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Set course details: code, name, credits, delivery mode (online, blended, residential).</p></div></div>
                </div>
            </div>

            <!-- Course Allocation -->
            <div class="help-section" id="course-alloc">
                <h3><i class="bi bi-arrows-angle-expand"></i>Course Allocation</h3>
                <div class="help-card">
                    <h5>Allocating Lecturers to ODL Courses</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Course Allocation</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Select the course and choose from available lecturers.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Confirm the allocation. The lecturer will be notified and gain access to the course materials.</p></div></div>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>ODL lecturers may be part-time or external. Verify their contract status before allocation.</div>
                </div>
            </div>

            <!-- Course Monitoring -->
            <div class="help-section" id="course-monitor">
                <h3><i class="bi bi-binoculars"></i>Course Monitoring</h3>
                <div class="help-card">
                    <h5>Monitoring Course Activity</h5>
                    <p>Track how ODL courses are progressing:</p>
                    <ul>
                        <li><strong>Content Uploads</strong> — Whether lecturers have uploaded materials</li>
                        <li><strong>Student Engagement</strong> — Login frequency, content access, assignment submissions</li>
                        <li><strong>Completion Rates</strong> — Percentage of students completing modules on time</li>
                    </ul>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>Use course monitoring data to identify courses that may need additional support or intervention.</div>
                </div>
            </div>

            <!-- Student Management -->
            <div class="help-section" id="students">
                <h3><i class="bi bi-people"></i>Student Management</h3>
                <div class="help-card">
                    <h5>Enrolment & Verification</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p><strong>Student Enrolment</strong> — View and manage student enrolments in ODL programmes. Enrol students manually or process batch enrolments.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p><strong>Student Verification</strong> — Verify student identities and submitted documents. Mark as Verified, Pending, or Rejected.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p><strong>Student Details</strong> — Click any student to view full profile including contact, programme, and payment status.</p></div></div>
                </div>
            </div>

            <!-- Student Progress -->
            <div class="help-section" id="student-progress">
                <h3><i class="bi bi-bar-chart-line"></i>Student Progress</h3>
                <div class="help-card">
                    <h5>Tracking Student Progress</h5>
                    <p>The <strong>Student Progress</strong> page shows academic status for each ODL student:</p>
                    <ul>
                        <li>Courses enrolled vs. completed</li>
                        <li>Assignment submission rates</li>
                        <li>Exam results and overall GPA</li>
                        <li>Attendance at residential sessions</li>
                    </ul>
                    <p>Use filters to view by programme, year, or individual student.</p>
                </div>
            </div>

            <!-- Timetable -->
            <div class="help-section" id="timetable">
                <h3><i class="bi bi-calendar3"></i>Timetable</h3>
                <div class="help-card">
                    <h5>Managing the ODL Timetable</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Manage Timetable</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Create sessions: select course, lecturer, date, time, and mode (online/physical).</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>For physical residential sessions, specify the venue and campus.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Once finalized, click <strong>Publish</strong>. Students can view and print the timetable.</p></div></div>
                    <p class="mt-2">Use <strong>Print Timetable</strong> to generate a printable PDF version.</p>
                </div>
            </div>

            <!-- Claims Approval -->
            <div class="help-section" id="claims">
                <h3><i class="bi bi-receipt"></i>Claims Approval</h3>
                <div class="help-card">
                    <h5>Reviewing Lecturer Claims</h5>
                    <p>ODL lecturers submit payment claims for teaching hours. As coordinator, you review and approve these:</p>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Claims Approval</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Pending claims appear with lecturer name, course, hours claimed, and amount.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Click <strong>View Details</strong> to review supporting documentation.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Choose <strong>Approve</strong>, <strong>Request Revision</strong>, or <strong>Reject</strong>. Add comments if applicable.</p></div></div>
                    <div class="help-step"><div class="help-step-num">5</div><div class="help-step-content"><p>Approved claims are forwarded to Finance for processing.</p></div></div>
                    <p class="mt-2">Use <strong>Print Claim</strong> to generate a hard-copy record.</p>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>Verify the claimed hours against timetable records and attendance data before approving.</div>
                </div>
            </div>

            <!-- Exam Management -->
            <div class="help-section" id="exams">
                <h3><i class="bi bi-file-earmark-text"></i>Exam Management</h3>
                <div class="help-card">
                    <h5>Coordinating ODL Exams</h5>
                    <p>Manage examinations for ODL students:</p>
                    <ul>
                        <li><strong>Schedule ODL exams</strong> — Coordinate with exam centres at different campuses</li>
                        <li><strong>Monitor exam sessions</strong> — Track exam progress across locations</li>
                        <li><strong>Special arrangements</strong> — Handle deferred exams and supplementary sittings for ODL students</li>
                    </ul>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>ODL students may take exams at different campuses. Coordinate with the Examination Officer to ensure materials and tokens are distributed to all centres.</div>
                </div>
            </div>

            <!-- Reports & Logs -->
            <div class="help-section" id="reports">
                <h3><i class="bi bi-graph-up"></i>Reports & Activity Logs</h3>
                <div class="help-card">
                    <h5>Generating Reports</h5>
                    <p>Navigate to <strong>Reports</strong> for comprehensive ODL analytics:</p>
                    <ul>
                        <li><strong>Enrolment Report</strong> — ODL student numbers by programme, campus, and year</li>
                        <li><strong>Progress Report</strong> — Student completion and performance metrics</li>
                        <li><strong>Claims Report</strong> — Summary of approved/pending/rejected claims</li>
                        <li><strong>Course Activity</strong> — Engagement metrics per course</li>
                    </ul>
                </div>
                <div class="help-card">
                    <h5>Activity Logs</h5>
                    <p>The <strong>Activity Logs</strong> page shows a chronological record of all actions taken in the ODL portal — enrolments, approvals, modifications, and system events. Use this for audit purposes and troubleshooting.</p>
                </div>
            </div>

            <!-- FAQ -->
            <div class="help-section" id="faq">
                <h3><i class="bi bi-question-circle"></i>Frequently Asked Questions</h3>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I enrol a student in an ODL programme?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Go to <strong>Student Enrolment</strong>, click <strong>Add Student</strong>, enter their details or search by student ID, select the programme, and confirm enrolment.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Can ODL students access the same courses as conventional students?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">ODL courses are managed separately. They may have the same content but different schedules and delivery modes. Contact Admin if a course needs to be shared across modes.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I manage residential session attendance?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Lecturers mark attendance during residential sessions via the VLE. You can view attendance records in <strong>Course Monitoring</strong> or <strong>Student Progress</strong>.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">A lecturer's claim has incorrect hours — what should I do?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Click <strong>Request Revision</strong> on the claim and add a comment explaining the discrepancy. The lecturer will be notified and can resubmit with corrected hours.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I print the timetable for a specific campus?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">In <strong>Manage Timetable</strong>, filter by campus, then click <strong>Print Timetable</strong>. The PDF will include only sessions for the selected campus.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Who do I contact for system issues?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Contact the ICT department or System Administrator at <strong>ict@eumw.edu</strong>.</div></div>
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
