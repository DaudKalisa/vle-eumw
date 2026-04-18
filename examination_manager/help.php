<?php
/**
 * Examination Manager Portal - Help & User Manual
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
    <title>Help & User Manual - Examination Manager Portal</title>
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
                <h2><i class="bi bi-shield-lock me-2"></i>Examination Manager User Manual</h2>
                <p>Oversee exam creation, token generation, security monitoring, and semester reporting</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="help-search"><input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autofocus></div>
            </div>
        </div>
    </div>

    <div class="quick-nav">
        <a href="#dashboard" class="quick-nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="#create-exam" class="quick-nav-item"><i class="bi bi-plus-circle"></i><span>Create Exam</span></a>
        <a href="#tokens" class="quick-nav-item"><i class="bi bi-key"></i><span>Generate Tokens</span></a>
        <a href="#security" class="quick-nav-item"><i class="bi bi-shield-check"></i><span>Security</span></a>
        <a href="#reports" class="quick-nav-item"><i class="bi bi-file-earmark-bar-graph"></i><span>Reports</span></a>
    </div>

    <div class="row">
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc">
                <h6 class="text-muted mb-3" style="font-size:.75rem;text-transform:uppercase;letter-spacing:1px">Contents</h6>
                <a href="#getting-started">Getting Started</a>
                <a href="#dashboard">Dashboard</a>
                <a href="#create-exam">Create Exam</a>
                <a href="#tokens">Generate Tokens</a>
                <a href="#security">Security Monitoring</a>
                <a href="#reports">Semester Reports</a>
                <a href="#faq">FAQ</a>
            </div>
        </div>
        <div class="col-lg-9">

            <!-- Getting Started -->
            <div class="help-section" id="getting-started">
                <h3><i class="bi bi-rocket-takeoff"></i>Getting Started</h3>
                <div class="help-card">
                    <h5>Examination Manager Overview</h5>
                    <p>As Examination Manager, you exercise supervisory control over the examination process. Your role focuses on:</p>
                    <ul>
                        <li><strong>Exam Creation</strong> — Create and configure exams at a managerial level</li>
                        <li><strong>Token Generation</strong> — Issue secure access tokens for exam sessions</li>
                        <li><strong>Security Monitoring</strong> — Oversee exam integrity and detect violations</li>
                        <li><strong>Semester Reports</strong> — Generate high-level examination reports</li>
                    </ul>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>The Examination Manager role complements the Examination Officer role. Officers handle day-to-day exam operations while you oversee security and reporting.</div>
                </div>
            </div>

            <!-- Dashboard -->
            <div class="help-section" id="dashboard">
                <h3><i class="bi bi-speedometer2"></i>Dashboard</h3>
                <div class="help-card">
                    <h5>Dashboard Overview</h5>
                    <p>Your dashboard provides a managerial overview:</p>
                    <ul>
                        <li><strong>Total Exams</strong> — Count of exams this semester</li>
                        <li><strong>Active Sessions</strong> — Exams currently in progress</li>
                        <li><strong>Security Alerts</strong> — Flagged incidents requiring attention</li>
                        <li><strong>Pending Reports</strong> — Reports awaiting finalization</li>
                    </ul>
                </div>
            </div>

            <!-- Create Exam -->
            <div class="help-section" id="create-exam">
                <h3><i class="bi bi-plus-circle"></i>Create Exam</h3>
                <div class="help-card">
                    <h5>Creating an Exam</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Click <strong>Create Exam</strong> from the sidebar or dashboard.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Select the <strong>Course</strong> and <strong>Semester</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Enter the exam <strong>Title</strong>, <strong>Duration</strong>, and <strong>Total Marks</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Set the exam window (start and end dates/times).</p></div></div>
                    <div class="help-step"><div class="help-step-num">5</div><div class="help-step-content"><p>Configure security settings: IP restrictions, browser lockdown, single-attempt policy.</p></div></div>
                    <div class="help-step"><div class="help-step-num">6</div><div class="help-step-content"><p>Save or publish the exam.</p></div></div>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>Ensure the exam timetable has been coordinated before publishing to avoid scheduling conflicts.</div>
                </div>
            </div>

            <!-- Generate Tokens -->
            <div class="help-section" id="tokens">
                <h3><i class="bi bi-key"></i>Generate Tokens</h3>
                <div class="help-card">
                    <h5>Token Generation Process</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Generate Tokens</strong> and select the exam.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Review the list of eligible students. The system auto-populates based on course enrolment.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Click <strong>Generate</strong> to create unique tokens for each student.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Download token list as CSV or print for distribution.</p></div></div>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>Tokens are single-use. Replacement tokens can be generated for individual students if needed — just click the student's name and select <strong>Regenerate Token</strong>.</div>
                </div>
                <div class="help-card">
                    <h5>Understanding Session Details</h5>
                    <p>Click <strong>View Session Details</strong> for any exam to see:</p>
                    <ul>
                        <li>Total tokens generated vs. used</li>
                        <li>Students who have started vs. completed</li>
                        <li>Average completion time</li>
                        <li>Any flagged irregularities</li>
                    </ul>
                </div>
            </div>

            <!-- Security Monitoring -->
            <div class="help-section" id="security">
                <h3><i class="bi bi-shield-check"></i>Security Monitoring</h3>
                <div class="help-card">
                    <h5>Monitoring Exam Integrity</h5>
                    <p>The <strong>Security Monitoring</strong> page is your command centre for exam integrity:</p>
                    <ul>
                        <li><strong>Real-time Alerts</strong> — Notifications for suspicious activities (tab switches, copy/paste, multiple logins)</li>
                        <li><strong>IP Tracking</strong> — Verify students are taking exams from approved locations</li>
                        <li><strong>Session Logs</strong> — Detailed audit trail for each student's exam session</li>
                        <li><strong>Incident Management</strong> — Mark incidents as investigated, escalated, or resolved</li>
                    </ul>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Navigate to <strong>Security Monitoring</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Active alerts appear at the top in red/amber. Click to investigate.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Review the session log, then mark as <strong>False Alarm</strong>, <strong>Warning Issued</strong>, or <strong>Escalated</strong>.</p></div></div>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>Escalated incidents are forwarded to the Dean and academic integrity committee. Only escalate when you have strong evidence of a violation.</div>
                </div>
            </div>

            <!-- Semester Reports -->
            <div class="help-section" id="reports">
                <h3><i class="bi bi-file-earmark-bar-graph"></i>Semester Reports</h3>
                <div class="help-card">
                    <h5>Generating Semester Reports</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Semester Reports</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Select the <strong>Academic Year</strong> and <strong>Semester</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Choose report type: <strong>Exam Summary</strong>, <strong>Security Incidents</strong>, or <strong>Performance Analysis</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Click <strong>Generate</strong>. Reports include charts, tables, and summary statistics.</p></div></div>
                    <div class="help-step"><div class="help-step-num">5</div><div class="help-step-content"><p>Export as PDF or Excel for presentation to academic committees.</p></div></div>
                </div>
            </div>

            <!-- FAQ -->
            <div class="help-section" id="faq">
                <h3><i class="bi bi-question-circle"></i>Frequently Asked Questions</h3>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">What's the difference between Examination Manager and Examination Officer?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">The Examination Officer handles day-to-day operations: creating exams, adding questions, managing the question bank, and publishing results. The Examination Manager focuses on oversight: security monitoring, high-level reporting, and policy enforcement.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Can I override exam results?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Result modifications require coordinating with the Examination Officer. Managerial overrides are logged in the audit trail for accountability.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I handle a security breach during an exam?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">From Security Monitoring, you can: (1) Suspend the affected student's session, (2) Flag the incident, (3) Gather evidence from session logs, and (4) Escalate if warranted. In severe cases, you can pause the entire exam session.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Can I see reports from previous semesters?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Yes. In Semester Reports, use the semester/year filter to select any past period. All historical data is preserved.</div></div>
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
