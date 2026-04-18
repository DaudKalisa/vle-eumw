<?php
/**
 * Dean Portal - Help & User Manual
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
    <title>Help & User Manual - Dean Portal</title>
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
                <h2><i class="bi bi-award me-2"></i>Dean Portal User Manual</h2>
                <p>Faculty management, claims approval, and academic oversight</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="help-search"><input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autofocus></div>
            </div>
        </div>
    </div>

    <div class="quick-nav">
        <a href="#claims" class="quick-nav-item"><i class="bi bi-check2-square"></i><span>Claims Approval</span></a>
        <a href="#departments" class="quick-nav-item"><i class="bi bi-building"></i><span>Departments</span></a>
        <a href="#lecturers" class="quick-nav-item"><i class="bi bi-person-badge"></i><span>Lecturers</span></a>
        <a href="#students" class="quick-nav-item"><i class="bi bi-mortarboard"></i><span>Students</span></a>
        <a href="#courses" class="quick-nav-item"><i class="bi bi-book"></i><span>Courses</span></a>
        <a href="#exams" class="quick-nav-item"><i class="bi bi-pencil-square"></i><span>Exams</span></a>
    </div>

    <div class="row">
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc">
                <h6 class="text-muted mb-3" style="font-size:.75rem;text-transform:uppercase;letter-spacing:1px">Contents</h6>
                <a href="#getting-started">Getting Started</a>
                <a href="#dashboard">Dashboard</a>
                <a href="#claims">Claims Approval</a>
                <a href="#departments">Departments</a>
                <a href="#lecturers">Lecturers</a>
                <a href="#students">Students</a>
                <a href="#courses">Courses & Programs</a>
                <a href="#exams">Exams</a>
                <a href="#performance">Performance</a>
                <a href="#timetable">Timetable</a>
                <a href="#reports">Reports</a>
                <a href="#graduation">Graduation</a>
                <a href="#announcements">Announcements</a>
                <a href="#faq">FAQ</a>
            </div>
        </div>

        <div class="col-lg-9">

            <div class="help-section" id="getting-started">
                <h3><i class="bi bi-rocket-takeoff"></i>Getting Started</h3>
                <div class="help-card">
                    <h5>Dean Portal Overview</h5>
                    <p>As Dean of Faculty, you oversee all departments within your faculty. Key responsibilities:</p>
                    <ul>
                        <li>Approving lecturer finance/travel claims (second-level approval after HOD)</li>
                        <li>Overseeing departments, lecturers, students, and courses in your faculty</li>
                        <li>Reviewing academic performance and exam results</li>
                        <li>Managing graduation clearance</li>
                        <li>Monitoring faculty-wide reports and statistics</li>
                    </ul>
                </div>
            </div>

            <div class="help-section" id="dashboard">
                <h3><i class="bi bi-speedometer2"></i>Dashboard</h3>
                <div class="help-card">
                    <h5>Faculty Overview</h5>
                    <ul>
                        <li><strong>Department Stats:</strong> Number of departments, lecturers, and students in your faculty.</li>
                        <li><strong>Pending Claims:</strong> Finance claims awaiting your approval.</li>
                        <li><strong>Course Delivery:</strong> Status of courses across departments.</li>
                        <li><strong>Announcements:</strong> Faculty-relevant notices.</li>
                    </ul>
                </div>
            </div>

            <div class="help-section" id="claims">
                <h3><i class="bi bi-check2-square"></i>Claims Approval</h3>
                <div class="help-card">
                    <h5>Approving Lecturer Finance Claims</h5>
                    <p>Lecturer claims follow an approval chain: <strong>Lecturer → HOD → Dean → Finance</strong>.</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Claims Approval</strong> to see claims approved by HODs and forwarded to you.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Review each claim: type, amount, supporting documents, and HOD comments.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Click <strong>Approve</strong> to forward to Finance, or <strong>Reject</strong> with a reason.</p></div>
                    </div>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Important:</strong> Verify that claims comply with university policy before approving. Check amounts and supporting documentation.
                    </div>
                </div>
            </div>

            <div class="help-section" id="departments">
                <h3><i class="bi bi-building"></i>Departments</h3>
                <div class="help-card">
                    <h5>Department Oversight</h5>
                    <p>View all departments under your faculty with details on HODs, lecturer count, student count, and program offerings. Monitor departmental activities and performance.</p>
                </div>
            </div>

            <div class="help-section" id="lecturers">
                <h3><i class="bi bi-person-badge"></i>Lecturers</h3>
                <div class="help-card">
                    <h5>Lecturer Management</h5>
                    <p>View all lecturers in your faculty, their course assignments, and teaching performance. Track workload distribution across departments.</p>
                </div>
            </div>

            <div class="help-section" id="students">
                <h3><i class="bi bi-mortarboard"></i>Students</h3>
                <div class="help-card">
                    <h5>Student Oversight</h5>
                    <p>View student enrollment statistics, academic performance, and program-level reports for your faculty. Identify at-risk students and monitor progression.</p>
                </div>
            </div>

            <div class="help-section" id="courses">
                <h3><i class="bi bi-book"></i>Courses & Programs</h3>
                <div class="help-card">
                    <h5>Academic Programs</h5>
                    <p>Review all courses and programs offered under your faculty. Monitor course delivery status, content uploads, and student enrollment per course.</p>
                </div>
            </div>

            <div class="help-section" id="exams">
                <h3><i class="bi bi-pencil-square"></i>Exams</h3>
                <div class="help-card">
                    <h5>Exam Oversight</h5>
                    <p>View exam schedules, results, and pass rates across your faculty. Review and endorse exam results before publication.</p>
                </div>
            </div>

            <div class="help-section" id="performance">
                <h3><i class="bi bi-graph-up"></i>Performance</h3>
                <div class="help-card">
                    <h5>Academic Performance Analytics</h5>
                    <p>View performance analytics across departments: pass rates, grade distributions, attendance trends, and course completion rates.</p>
                </div>
            </div>

            <div class="help-section" id="timetable">
                <h3><i class="bi bi-calendar-week"></i>Timetable</h3>
                <div class="help-card">
                    <h5>Timetable Management</h5>
                    <p>View and manage class and exam timetables for your faculty. Coordinate scheduling across departments to avoid conflicts.</p>
                </div>
            </div>

            <div class="help-section" id="reports">
                <h3><i class="bi bi-file-bar-graph"></i>Reports</h3>
                <div class="help-card">
                    <h5>Faculty Reports</h5>
                    <ul>
                        <li>Student enrollment reports by department and program</li>
                        <li>Lecturer workload and performance reports</li>
                        <li>Exam results summaries</li>
                        <li>All reports exportable as Excel/PDF</li>
                    </ul>
                </div>
            </div>

            <div class="help-section" id="graduation">
                <h3><i class="bi bi-mortarboard"></i>Graduation Clearance</h3>
                <div class="help-card">
                    <h5>Academic Clearance</h5>
                    <p>Review and grant academic clearance for graduating students in your faculty. Verify that all program requirements, credits, and assessments are met.</p>
                </div>
            </div>

            <div class="help-section" id="announcements">
                <h3><i class="bi bi-megaphone"></i>Announcements</h3>
                <div class="help-card">
                    <h5>Faculty Announcements</h5>
                    <p>Post announcements targeting all students and lecturers in your faculty. View system-wide announcements from administration.</p>
                </div>
            </div>

            <div class="help-section" id="faq">
                <h3><i class="bi bi-question-circle"></i>Frequently Asked Questions</h3>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I know which claims need my approval? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">The dashboard shows a count of pending claims. Go to <strong>Claims Approval</strong> to see all claims that have been approved by HODs and are waiting for your review.</div>
                </div>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">Can I view individual student records? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Yes, go to <strong>Students</strong> and search for specific students. You can view their enrollment, grades, and attendance records.</div>
                </div>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I grant graduation clearance? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Go to <strong>Graduation Clearance</strong>, review the list of eligible students, verify their academic requirements, and click <strong>Clear</strong> for each qualified student.</div>
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
document.getElementById('helpSearch').addEventListener('input',function(){var q=this.value.toLowerCase();document.querySelectorAll('.help-section').forEach(function(s){s.style.display=(!q||s.textContent.toLowerCase().includes(q))?'':'none'});document.querySelectorAll('.quick-nav-item').forEach(function(i){i.style.display=(!q||i.textContent.toLowerCase().includes(q))?'':'none'})});
var tocLinks=document.querySelectorAll('.help-toc a'),sections=document.querySelectorAll('.help-section');window.addEventListener('scroll',function(){var sp=window.scrollY+120;sections.forEach(function(s){if(s.offsetTop<=sp&&s.offsetTop+s.offsetHeight>sp){tocLinks.forEach(function(l){l.classList.remove('active')});var m=document.querySelector('.help-toc a[href="#'+s.id+'"]');if(m)m.classList.add('active')}})});
</script>
</body>
</html>
