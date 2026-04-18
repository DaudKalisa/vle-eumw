<?php
/**
 * HOD Portal - Help & User Manual
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
    <title>Help & User Manual - HOD Portal</title>
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
                <h2><i class="bi bi-diagram-3 me-2"></i>HOD Portal User Manual</h2>
                <p>Head of Department — departmental management, course allocations, and academic oversight</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="help-search"><input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autofocus></div>
            </div>
        </div>
    </div>

    <div class="quick-nav">
        <a href="#dashboard" class="quick-nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="#course-alloc" class="quick-nav-item"><i class="bi bi-arrows-angle-expand"></i><span>Course Allocations</span></a>
        <a href="#courses" class="quick-nav-item"><i class="bi bi-book"></i><span>Courses</span></a>
        <a href="#lecturers" class="quick-nav-item"><i class="bi bi-person-badge"></i><span>Lecturers</span></a>
        <a href="#students" class="quick-nav-item"><i class="bi bi-mortarboard"></i><span>Students</span></a>
        <a href="#reports" class="quick-nav-item"><i class="bi bi-graph-up"></i><span>Reports</span></a>
    </div>

    <div class="row">
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc">
                <h6 class="text-muted mb-3" style="font-size:.75rem;text-transform:uppercase;letter-spacing:1px">Contents</h6>
                <a href="#getting-started">Getting Started</a>
                <a href="#dashboard">Dashboard</a>
                <a href="#course-alloc">Course Allocations</a>
                <a href="#courses">Courses</a>
                <a href="#lecturers">Lecturers</a>
                <a href="#students">Students</a>
                <a href="#reports">Reports</a>
                <a href="#faq">FAQ</a>
            </div>
        </div>
        <div class="col-lg-9">

            <!-- Getting Started -->
            <div class="help-section" id="getting-started">
                <h3><i class="bi bi-rocket-takeoff"></i>Getting Started</h3>
                <div class="help-card">
                    <h5>HOD Portal Overview</h5>
                    <p>As Head of Department, you manage the academic operations of your department. Your primary responsibilities include:</p>
                    <ul>
                        <li><strong>Course Allocations</strong> — Assign lecturers to courses each semester</li>
                        <li><strong>Course Management</strong> — Monitor departmental courses and content</li>
                        <li><strong>Lecturer Oversight</strong> — Track lecturer workloads and performance</li>
                        <li><strong>Student Monitoring</strong> — View student enrolments and academic progress</li>
                        <li><strong>Departmental Reports</strong> — Generate analytics on enrolment, performance, and workload</li>
                    </ul>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>Your portal only shows data for your own department — you will not see other departments' information.</div>
                </div>
                <div class="help-card">
                    <h5>First-Time Login</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p><strong>Log in</strong> with your username and temporary password provided by admin.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p><strong>Change your password</strong> immediately via <em>Profile → Change Password</em>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p><strong>Review your dashboard</strong> to see departmental statistics and pending tasks.</p></div></div>
                </div>
            </div>

            <!-- Dashboard -->
            <div class="help-section" id="dashboard">
                <h3><i class="bi bi-speedometer2"></i>Dashboard</h3>
                <div class="help-card">
                    <h5>Dashboard Overview</h5>
                    <p>The dashboard provides a snapshot of your department:</p>
                    <ul>
                        <li><strong>Statistics Cards</strong> — Total courses, lecturers, students, and active enrolments</li>
                        <li><strong>Course Allocation Status</strong> — How many courses have lecturers assigned vs unassigned</li>
                        <li><strong>Recent Activity</strong> — Latest actions in your department</li>
                    </ul>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>Click any statistic card to navigate directly to the relevant management page.</div>
                </div>
            </div>

            <!-- Course Allocations -->
            <div class="help-section" id="course-alloc">
                <h3><i class="bi bi-arrows-angle-expand"></i>Course Allocations</h3>
                <div class="help-card">
                    <h5>Assigning Lecturers to Courses</h5>
                    <p>This is one of your most important tasks each semester. Navigate to <strong>Course Allocations</strong> from the sidebar.</p>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>The page shows all courses in your department for the current semester.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Each course row displays the current allocated lecturer (or "Unassigned").</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Click the <strong>Assign</strong> or <strong>Change</strong> button next to a course.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Select a lecturer from the dropdown and confirm the allocation.</p></div></div>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>Check lecturer workloads before assigning — avoid overloading a single lecturer with too many courses.</div>
                </div>
            </div>

            <!-- Courses -->
            <div class="help-section" id="courses">
                <h3><i class="bi bi-book"></i>Courses</h3>
                <div class="help-card">
                    <h5>Viewing Departmental Courses</h5>
                    <p>The <strong>Courses</strong> page shows all courses offered by your department:</p>
                    <ul>
                        <li>Course code, name, and credit hours</li>
                        <li>Year of study and semester</li>
                        <li>Allocated lecturer</li>
                        <li>Number of enrolled students</li>
                    </ul>
                    <p>Use the filters at the top to narrow results by year, semester, or program.</p>
                </div>
            </div>

            <!-- Lecturers -->
            <div class="help-section" id="lecturers">
                <h3><i class="bi bi-person-badge"></i>Lecturers</h3>
                <div class="help-card">
                    <h5>Managing Department Lecturers</h5>
                    <p>View all lecturers assigned to your department:</p>
                    <ul>
                        <li><strong>Contact Information</strong> — Email, phone</li>
                        <li><strong>Qualifications</strong> — Academic credentials</li>
                        <li><strong>Course Load</strong> — Number of courses currently assigned</li>
                        <li><strong>Status</strong> — Active/inactive</li>
                    </ul>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>If a lecturer needs to be added or removed from your department, contact the Admin office.</div>
                </div>
            </div>

            <!-- Students -->
            <div class="help-section" id="students">
                <h3><i class="bi bi-mortarboard"></i>Students</h3>
                <div class="help-card">
                    <h5>Viewing Department Students</h5>
                    <p>See all students enrolled in your department's programs:</p>
                    <ul>
                        <li>Student ID, name, and contact details</li>
                        <li>Program the student is enrolled in</li>
                        <li>Year of study and semester</li>
                        <li>Enrolment status (active, deferred, etc.)</li>
                    </ul>
                    <p>Use the search bar to quickly find a specific student by name or student ID.</p>
                </div>
            </div>

            <!-- Reports -->
            <div class="help-section" id="reports">
                <h3><i class="bi bi-graph-up"></i>Reports</h3>
                <div class="help-card">
                    <h5>Departmental Reports</h5>
                    <p>Generate analytics and reports for your department:</p>
                    <ul>
                        <li><strong>Enrolment Report</strong> — Students count per program, year, and semester</li>
                        <li><strong>Workload Report</strong> — Course load distribution among lecturers</li>
                        <li><strong>Performance Summary</strong> — Pass/fail rates by course</li>
                    </ul>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Reports</strong> from the sidebar.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Select the report type and filter by semester or academic year.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Click <strong>Generate</strong> to view the report. Use <strong>Export</strong> to download as PDF/Excel.</p></div></div>
                </div>
            </div>

            <!-- FAQ -->
            <div class="help-section" id="faq">
                <h3><i class="bi bi-question-circle"></i>Frequently Asked Questions</h3>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I allocate a lecturer to a course?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Go to <strong>Course Allocations</strong>, find the course, click <strong>Assign</strong>, select the lecturer from the dropdown, and confirm.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Can I see courses from other departments?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">No. The HOD portal is scoped to your department only. For cross-department data, contact the Dean or Admin.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">A lecturer is not appearing in my allocation dropdown?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">The lecturer may not be assigned to your department yet. Contact Admin to update the lecturer's department assignment.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I request a new course to be added?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Course creation is managed by Admin. Submit a request to the Admin office with the course code, name, credits, and target program details.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Who do I contact for technical issues?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Contact the ICT department or System Administrator. You can also email <strong>ict@eumw.edu</strong>.</div></div>
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
// TOC active highlight
var tocLinks=document.querySelectorAll('.help-toc a');
window.addEventListener('scroll',function(){var fromTop=window.scrollY+100;tocLinks.forEach(function(link){var sec=document.querySelector(link.getAttribute('href'));if(sec&&sec.offsetTop<=fromTop&&sec.offsetTop+sec.offsetHeight>fromTop){link.classList.add('active')}else{link.classList.remove('active')}})});
</script>
</body>
</html>
