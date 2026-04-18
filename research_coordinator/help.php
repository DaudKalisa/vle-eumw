<?php
/**
 * Research Coordinator Portal - Help & User Manual
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
    <title>Help & User Manual - Research Coordinator Portal</title>
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
                <h2><i class="bi bi-journal-bookmark me-2"></i>Research Coordinator User Manual</h2>
                <p>Manage dissertations, supervisors, defenses, ethical clearances, and research workflows</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="help-search"><input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autofocus></div>
            </div>
        </div>
    </div>

    <div class="quick-nav">
        <a href="#dashboard" class="quick-nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        <a href="#dissertations" class="quick-nav-item"><i class="bi bi-journal-text"></i><span>Dissertations</span></a>
        <a href="#supervisors" class="quick-nav-item"><i class="bi bi-people"></i><span>Supervisors</span></a>
        <a href="#defenses" class="quick-nav-item"><i class="bi bi-shield-check"></i><span>Defenses</span></a>
        <a href="#ethics" class="quick-nav-item"><i class="bi bi-clipboard-check"></i><span>Ethical Forms</span></a>
        <a href="#similarity" class="quick-nav-item"><i class="bi bi-file-earmark-diff"></i><span>Similarity</span></a>
        <a href="#submissions" class="quick-nav-item"><i class="bi bi-upload"></i><span>Submissions</span></a>
        <a href="#marking" class="quick-nav-item"><i class="bi bi-pencil-square"></i><span>Marking</span></a>
        <a href="#references" class="quick-nav-item"><i class="bi bi-envelope-paper"></i><span>References</span></a>
        <a href="#graduation" class="quick-nav-item"><i class="bi bi-mortarboard"></i><span>Graduation</span></a>
    </div>

    <div class="row">
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc">
                <h6 class="text-muted mb-3" style="font-size:.75rem;text-transform:uppercase;letter-spacing:1px">Contents</h6>
                <a href="#getting-started">Getting Started</a>
                <a href="#dashboard">Dashboard</a>
                <a href="#dissertations">Manage Dissertations</a>
                <a href="#supervisors">Assign Supervisors</a>
                <a href="#defenses">Defense Management</a>
                <a href="#ethics">Ethical Forms</a>
                <a href="#similarity">Similarity Reports</a>
                <a href="#submissions">Review Submissions</a>
                <a href="#marking">Marking Sheets</a>
                <a href="#deadlines">Deadline Management</a>
                <a href="#references">Reference Letters</a>
                <a href="#graduation">Graduation Clearance</a>
                <a href="#messages">Messages</a>
                <a href="#faq">FAQ</a>
            </div>
        </div>
        <div class="col-lg-9">

            <!-- Getting Started -->
            <div class="help-section" id="getting-started">
                <h3><i class="bi bi-rocket-takeoff"></i>Getting Started</h3>
                <div class="help-card">
                    <h5>Research Coordinator Overview</h5>
                    <p>You oversee the entire research and dissertation process within the university. Your responsibilities include:</p>
                    <ul>
                        <li><strong>Dissertation Management</strong> — Track all active dissertations by stage</li>
                        <li><strong>Supervisor Assignment</strong> — Match students with appropriate supervisors</li>
                        <li><strong>Defense Scheduling</strong> — Organize defense panels, dates, and venues</li>
                        <li><strong>Ethical Clearance</strong> — Review and approve ethics forms</li>
                        <li><strong>Similarity Checking</strong> — Monitor plagiarism/similarity reports</li>
                        <li><strong>Graduation Clearance</strong> — Confirm research completion for graduating students</li>
                    </ul>
                </div>
            </div>

            <!-- Dashboard -->
            <div class="help-section" id="dashboard">
                <h3><i class="bi bi-speedometer2"></i>Dashboard</h3>
                <div class="help-card">
                    <h5>Dashboard Overview</h5>
                    <p>Your dashboard shows at-a-glance metrics:</p>
                    <ul>
                        <li><strong>Active Dissertations</strong> — Count of ongoing research projects</li>
                        <li><strong>Pending Submissions</strong> — Submissions awaiting your review</li>
                        <li><strong>Upcoming Defenses</strong> — Scheduled defense sessions</li>
                        <li><strong>Ethical Clearances</strong> — Pending approval requests</li>
                    </ul>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>Use the dashboard cards as quick shortcuts — click any card to jump to the relevant section.</div>
                </div>
            </div>

            <!-- Manage Dissertations -->
            <div class="help-section" id="dissertations">
                <h3><i class="bi bi-journal-text"></i>Manage Dissertations</h3>
                <div class="help-card">
                    <h5>Viewing & Tracking Dissertations</h5>
                    <p>The <strong>Manage Dissertations</strong> page lists all active dissertations with their current status:</p>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Navigate to <strong>Manage Dissertations</strong> from the sidebar.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Use filters to search by student name, program, department, or dissertation stage.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Click on a dissertation to view full details including title, abstract, supervisor, and submission history.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Update the dissertation status or add comments as needed.</p></div></div>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>Changing a dissertation's status sends notifications to the student and supervisor. Double-check before updating.</div>
                </div>
            </div>

            <!-- Assign Supervisors -->
            <div class="help-section" id="supervisors">
                <h3><i class="bi bi-people"></i>Assign Supervisors</h3>
                <div class="help-card">
                    <h5>Supervisor Assignment Process</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Assign Supervisors</strong> from the sidebar.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>You'll see students without supervisors listed at the top. Filter by department if needed.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Click <strong>Assign Supervisor</strong> for the student.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Select the <strong>Main Supervisor</strong> and optionally a <strong>Co-Supervisor</strong> from available lecturers.</p></div></div>
                    <div class="help-step"><div class="help-step-num">5</div><div class="help-step-content"><p>Click <strong>Save</strong>. Both the student and supervisor will be notified.</p></div></div>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>Check each supervisor's current load (number of students already assigned) to ensure balanced distribution.</div>
                </div>
            </div>

            <!-- Defense Management -->
            <div class="help-section" id="defenses">
                <h3><i class="bi bi-shield-check"></i>Defense Management</h3>
                <div class="help-card">
                    <h5>Scheduling & Managing Defenses</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Open <strong>Defense Management</strong> from the sidebar.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Click <strong>Schedule New Defense</strong> to create a new session.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Select the student, set the date, time, and venue.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Add <strong>Panel Members</strong> (examiners) including internal and external examiners.</p></div></div>
                    <div class="help-step"><div class="help-step-num">5</div><div class="help-step-content"><p>Submit the schedule. All parties will be notified.</p></div></div>
                    <p class="mt-2">You can also <strong>print the defense schedule as PDF</strong> from the defense detail page.</p>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>Ensure the student's ethical clearance and similarity report are approved before scheduling a defense.</div>
                </div>
            </div>

            <!-- Ethical Forms -->
            <div class="help-section" id="ethics">
                <h3><i class="bi bi-clipboard-check"></i>Ethical Forms</h3>
                <div class="help-card">
                    <h5>Reviewing Ethical Clearance</h5>
                    <p>Students submit ethical clearance forms before beginning data collection. Your role is to review and approve them:</p>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Ethical Forms</strong> — pending submissions appear first.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Click <strong>Review</strong> to open the submission details.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Examine the form content, methodology, and data collection methods.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Choose <strong>Approve</strong>, <strong>Request Revision</strong>, or <strong>Reject</strong>. Add comments to explain your decision.</p></div></div>
                    <div class="help-tip"><i class="bi bi-lightbulb"></i>If requesting revision, be specific about what needs changing so the student can address your concerns.</div>
                </div>
            </div>

            <!-- Similarity Reports -->
            <div class="help-section" id="similarity">
                <h3><i class="bi bi-file-earmark-diff"></i>Similarity Reports</h3>
                <div class="help-card">
                    <h5>Checking Similarity/Plagiarism</h5>
                    <p>Review similarity check results for submitted dissertations:</p>
                    <ul>
                        <li>View the overall similarity percentage</li>
                        <li>Identify highlighted matching sections</li>
                        <li>Determine if the similarity is acceptable (typically under a defined threshold)</li>
                    </ul>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>A high similarity score does not always indicate plagiarism — references, quotes, and common phrases may inflate the number. Review the details carefully.</div>
                </div>
            </div>

            <!-- Review Submissions -->
            <div class="help-section" id="submissions">
                <h3><i class="bi bi-upload"></i>Review Submissions</h3>
                <div class="help-card">
                    <h5>Reviewing Student Submissions</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Navigate to <strong>Review Submissions</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Pending submissions are listed with student name, submission date, and type.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Click to open and download the submitted document.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Provide feedback, mark as <strong>Approved</strong> or <strong>Needs Revision</strong>.</p></div></div>
                </div>
            </div>

            <!-- Marking Sheets -->
            <div class="help-section" id="marking">
                <h3><i class="bi bi-pencil-square"></i>Marking Sheets</h3>
                <div class="help-card">
                    <h5>Managing Dissertation Marks</h5>
                    <p>Use <strong>Marking Sheets</strong> to record and manage defense scores and dissertation grades:</p>
                    <ul>
                        <li>View marking criteria and rubrics</li>
                        <li>Enter marks from individual panel members</li>
                        <li>Calculate final aggregated scores</li>
                        <li>Record defense outcomes (Pass, Conditional Pass, Fail)</li>
                    </ul>
                </div>
            </div>

            <!-- Deadline Management -->
            <div class="help-section" id="deadlines">
                <h3><i class="bi bi-calendar-event"></i>Deadline Management</h3>
                <div class="help-card">
                    <h5>Setting Research Deadlines</h5>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Deadline Management</strong> from the sidebar.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Create or edit deadlines for proposal submission, ethical clearance, draft submission, and final submission.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Set the date and optional late penalty policy.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>Students and supervisors will see these deadlines in their portals.</p></div></div>
                </div>
            </div>

            <!-- Reference Letters -->
            <div class="help-section" id="references">
                <h3><i class="bi bi-envelope-paper"></i>Reference Letters</h3>
                <div class="help-card">
                    <h5>Managing Reference Letters</h5>
                    <p>Handle reference letter requests from students:</p>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Go to <strong>Reference Letters</strong> to see pending requests.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Click <strong>View</strong> to see the student's details and request reason.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>Use <strong>Print Reference Letter</strong> to generate a formatted PDF.</p></div></div>
                </div>
            </div>

            <!-- Graduation Clearance -->
            <div class="help-section" id="graduation">
                <h3><i class="bi bi-mortarboard"></i>Graduation Clearance</h3>
                <div class="help-card">
                    <h5>Research Clearance for Graduation</h5>
                    <p>Before students can graduate, they need research clearance confirming their dissertation is complete:</p>
                    <div class="help-step"><div class="help-step-num">1</div><div class="help-step-content"><p>Navigate to <strong>Graduation Clearance</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">2</div><div class="help-step-content"><p>Review the student's dissertation completion status, defense outcome, and final corrections.</p></div></div>
                    <div class="help-step"><div class="help-step-num">3</div><div class="help-step-content"><p>If everything is in order, click <strong>Grant Clearance</strong>.</p></div></div>
                    <div class="help-step"><div class="help-step-num">4</div><div class="help-step-content"><p>The clearance status will be shared with the Registrar for graduation processing.</p></div></div>
                    <div class="help-warning"><i class="bi bi-exclamation-triangle"></i>Only grant clearance once you have confirmed the final corrected dissertation has been submitted and accepted.</div>
                </div>
            </div>

            <!-- Messages -->
            <div class="help-section" id="messages">
                <h3><i class="bi bi-chat-dots"></i>Messages</h3>
                <div class="help-card">
                    <h5>Internal Messaging</h5>
                    <p>Use the <strong>Messages</strong> feature to communicate with students and supervisors:</p>
                    <ul>
                        <li>Send individual messages to students about their research progress</li>
                        <li>Communicate with supervisors about specific dissertations</li>
                        <li>View conversation history for each contact</li>
                    </ul>
                </div>
            </div>

            <!-- FAQ -->
            <div class="help-section" id="faq">
                <h3><i class="bi bi-question-circle"></i>Frequently Asked Questions</h3>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I reassign a supervisor?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Go to <strong>Assign Supervisors</strong>, find the student, and click <strong>Change</strong>. Select the new supervisor and save. Both old and new supervisors will be notified.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">What happens if a student's ethical clearance is rejected?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">The student will be notified and must resubmit. They cannot proceed to data collection until ethical clearance is approved.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Can I schedule multiple defenses on the same day?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">Yes, but ensure the times and venues do not conflict. The system will warn you of scheduling overlaps with the same panel members.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">How do I add an external examiner to a defense panel?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">When scheduling a defense, you can enter external examiner details manually (name, institution, email). They are not required to have a system account.</div></div>
                <div class="faq-item"><div class="faq-q" onclick="this.parentElement.classList.toggle('open')">Where can I see all completed dissertations?<i class="bi bi-chevron-down faq-chevron"></i></div><div class="faq-a">In <strong>Manage Dissertations</strong>, use the status filter to select "Completed" to see all finished dissertations.</div></div>
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
