<?php
/**
 * Admin Portal - Help & User Manual
 * Comprehensive guide for all administration features
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
    <title>Help & User Manual - Admin Portal</title>
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
                <h2><i class="bi bi-shield-lock me-2"></i>Admin Portal User Manual</h2>
                <p>Complete guide to VLE system administration and management</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="help-search"><input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autofocus></div>
            </div>
        </div>
    </div>

    <div class="quick-nav">
        <a href="#users" class="quick-nav-item"><i class="bi bi-people"></i><span>User Management</span></a>
        <a href="#courses" class="quick-nav-item"><i class="bi bi-book"></i><span>Courses</span></a>
        <a href="#registrations" class="quick-nav-item"><i class="bi bi-person-plus"></i><span>Registrations</span></a>
        <a href="#fees" class="quick-nav-item"><i class="bi bi-cash-coin"></i><span>Fee Settings</span></a>
        <a href="#departments" class="quick-nav-item"><i class="bi bi-building"></i><span>Departments</span></a>
        <a href="#database" class="quick-nav-item"><i class="bi bi-database"></i><span>Database</span></a>
        <a href="#reports" class="quick-nav-item"><i class="bi bi-graph-up"></i><span>Reports</span></a>
        <a href="#settings" class="quick-nav-item"><i class="bi bi-gear"></i><span>Settings</span></a>
    </div>

    <div class="row">
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc">
                <h6 class="text-muted mb-3" style="font-size:.75rem;text-transform:uppercase;letter-spacing:1px">Contents</h6>
                <a href="#getting-started">Getting Started</a>
                <a href="#dashboard">Dashboard</a>
                <a href="#users">User Management</a>
                <a href="#registrations">Student Registration</a>
                <a href="#courses">Course Management</a>
                <a href="#allocations">Course Allocations</a>
                <a href="#departments">Departments & Programs</a>
                <a href="#fees">Fee Settings</a>
                <a href="#semester">Semester Management</a>
                <a href="#database">Database Manager</a>
                <a href="#reports">Reports & Export</a>
                <a href="#settings">System Settings</a>
                <a href="#graduation">Graduation</a>
                <a href="#dissertation">Dissertation Links</a>
                <a href="#messages">Messages & Announcements</a>
                <a href="#faq">FAQ</a>
            </div>
        </div>

        <div class="col-lg-9">

            <!-- Getting Started -->
            <div class="help-section" id="getting-started">
                <h3><i class="bi bi-rocket-takeoff"></i>Getting Started</h3>
                <div class="help-card">
                    <h5>Admin Portal Overview</h5>
                    <p>As an administrator, you have full control over the VLE system. Your primary responsibilities include:</p>
                    <ul>
                        <li>Managing student and lecturer accounts</li>
                        <li>Configuring courses, programs, and departments</li>
                        <li>Setting fee structures and managing finances</li>
                        <li>Approving student registrations</li>
                        <li>Maintaining system settings (SMTP, Zoom, university info)</li>
                        <li>Database management and backups</li>
                    </ul>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Important:</strong> Admin actions affect all users. Always double-check before making bulk changes or deleting records.
                    </div>
                </div>
            </div>

            <!-- Dashboard -->
            <div class="help-section" id="dashboard">
                <h3><i class="bi bi-speedometer2"></i>Dashboard</h3>
                <div class="help-card">
                    <h5>Admin Dashboard</h5>
                    <p>The admin dashboard provides a system-wide overview:</p>
                    <ul>
                        <li><strong>User Statistics:</strong> Total students, lecturers, and other staff counts.</li>
                        <li><strong>Pending Approvals:</strong> Student registration requests awaiting approval.</li>
                        <li><strong>Course Stats:</strong> Active courses, enrollments, and content uploads.</li>
                        <li><strong>Finance Summary:</strong> Revenue overview, outstanding balances, and payment statistics.</li>
                        <li><strong>System Health:</strong> Storage usage, recent activities, and system alerts.</li>
                    </ul>
                </div>
            </div>

            <!-- User Management -->
            <div class="help-section" id="users">
                <h3><i class="bi bi-people"></i>User Management</h3>
                <div class="help-card">
                    <h5>Managing Students</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Manage Students</strong> to view all registered students.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Use the search and filters to find specific students by name, ID, program, or campus.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Click a student row to <strong>view details</strong>, <strong>edit information</strong>, or <strong>manage enrollment</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Use the <strong>status</strong> toggle to activate or deactivate student accounts.</p></div>
                    </div>

                    <h5 class="mt-4">Managing Lecturers</h5>
                    <p>Go to <strong>Manage Lecturers</strong> to view, add, edit, or deactivate lecturer accounts. Assign lecturers to departments and manage their course allocations.</p>

                    <h5 class="mt-4">Managing All Users</h5>
                    <p>The <strong>Manage Users</strong> page provides a unified view of all user accounts (students, lecturers, finance officers, deans, HODs, etc.) with bulk actions available.</p>

                    <h5 class="mt-4">Resetting Passwords</h5>
                    <p>Use <strong>Reset Passwords</strong> to bulk-reset passwords for multiple users at once, or reset individually from the user details page.</p>
                </div>
            </div>

            <!-- Student Registration -->
            <div class="help-section" id="registrations">
                <h3><i class="bi bi-person-plus"></i>Student Registration Approval</h3>
                <div class="help-card">
                    <h5>Approving New Registrations</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Approve Student Accounts</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Review pending registration requests — check student details, program, campus, and year of study.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Click <strong>Approve</strong> to activate the account, or <strong>Reject</strong> with a reason.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Approved students automatically receive login credentials and are enrolled in their program courses.</p></div>
                    </div>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> You can edit a student's campus or program before approving if they selected the wrong option during registration.
                    </div>
                </div>
            </div>

            <!-- Course Management -->
            <div class="help-section" id="courses">
                <h3><i class="bi bi-book"></i>Course Management</h3>
                <div class="help-card">
                    <h5>Managing Courses</h5>
                    <p>Go to <strong>Manage Courses</strong> to:</p>
                    <ul>
                        <li>View all courses in the system with codes, names, credits, and department.</li>
                        <li>Add new courses with all required details.</li>
                        <li>Edit existing course information.</li>
                        <li>Activate or deactivate courses per semester.</li>
                    </ul>
                </div>
            </div>

            <!-- Course Allocations -->
            <div class="help-section" id="allocations">
                <h3><i class="bi bi-diagram-3"></i>Course Allocations</h3>
                <div class="help-card">
                    <h5>Assigning Lecturers to Courses</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Course Allocations</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Select a course and assign a <strong>lecturer</strong> from the dropdown.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Set the <strong>semester</strong> and <strong>academic year</strong> for the allocation.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Save. The lecturer will see the course on their dashboard.</p></div>
                    </div>
                    <p class="mt-3">Use <strong>Module Allocation</strong> for bulk or template-based allocations across multiple courses.</p>
                </div>
            </div>

            <!-- Departments & Programs -->
            <div class="help-section" id="departments">
                <h3><i class="bi bi-building"></i>Departments, Programs & Faculties</h3>
                <div class="help-card">
                    <h5>Organizational Structure</h5>
                    <ul>
                        <li><strong>Manage Faculties:</strong> Add or edit faculties (e.g., Faculty of Science, Faculty of Education).</li>
                        <li><strong>Manage Departments:</strong> Create departments under faculties. Each department has a Head (HOD).</li>
                        <li><strong>Manage Programs:</strong> Set up academic programs (e.g., BSc Computer Science) under departments with duration, type (full-time/part-time/ODL).</li>
                    </ul>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Course and student assignments depend on the correct department/program hierarchy. Set this up first.
                    </div>
                </div>
            </div>

            <!-- Fee Settings -->
            <div class="help-section" id="fees">
                <h3><i class="bi bi-cash-coin"></i>Fee Settings</h3>
                <div class="help-card">
                    <h5>Configuring Fees</h5>
                    <p>The fee system controls student billing and content access:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Fee Settings</strong> to configure fee structures.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Set fees per <strong>program</strong>, <strong>year of study</strong>, and <strong>semester</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Configure the <strong>payment threshold</strong> — the minimum percentage students must pay to access course content.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Set <strong>payment deadlines</strong> and late payment policies.</p></div>
                    </div>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Important:</strong> Changes to fee settings affect all students in the affected program/year. Review carefully before saving.
                    </div>
                </div>
            </div>

            <!-- Semester Management -->
            <div class="help-section" id="semester">
                <h3><i class="bi bi-calendar3"></i>Semester Management</h3>
                <div class="help-card">
                    <h5>Managing Academic Periods</h5>
                    <ul>
                        <li><strong>Manage Semester:</strong> Set the current semester, start/end dates, and academic year.</li>
                        <li><strong>Semester Shift:</strong> Transition students from one semester to the next, updating enrollments and year of study.</li>
                    </ul>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Important:</strong> Semester shift is a major operation. Back up the database first and verify all grade entries are complete before shifting.
                    </div>
                </div>
            </div>

            <!-- Database Manager -->
            <div class="help-section" id="database">
                <h3><i class="bi bi-database"></i>Database Manager</h3>
                <div class="help-card">
                    <h5>Database Operations</h5>
                    <p>The Database Manager allows you to perform maintenance tasks:</p>
                    <ul>
                        <li><strong>View Tables:</strong> Browse all database tables and their record counts.</li>
                        <li><strong>Data Update:</strong> Search for and update specific records across tables.</li>
                        <li><strong>Backup:</strong> Create database backups before making major changes.</li>
                        <li><strong>Export:</strong> Export table data for analysis or reporting.</li>
                    </ul>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Warning:</strong> Direct database operations can cause data loss if used incorrectly. Always create a backup before modifying data.
                    </div>
                </div>
            </div>

            <!-- Reports -->
            <div class="help-section" id="reports">
                <h3><i class="bi bi-graph-up"></i>Reports & Data Export</h3>
                <div class="help-card">
                    <h5>Generating Reports</h5>
                    <ul>
                        <li><strong>Student Reports:</strong> Enrollment statistics, demographics, and academic performance.</li>
                        <li><strong>Course Reports:</strong> Course enrollment numbers, completion rates, and content statistics.</li>
                        <li><strong>Export Data:</strong> Download reports as Excel/CSV for external analysis.</li>
                    </ul>
                </div>
            </div>

            <!-- System Settings -->
            <div class="help-section" id="settings">
                <h3><i class="bi bi-gear"></i>System Settings</h3>
                <div class="help-card">
                    <h5>Configuring the VLE</h5>
                    <ul>
                        <li><strong>SMTP Settings:</strong> Configure email server for sending notifications, password resets, and announcements.</li>
                        <li><strong>Zoom Settings:</strong> Set up Zoom API integration for live classroom sessions.</li>
                        <li><strong>University Settings:</strong> Update university name, logo, contact info displayed across the VLE.</li>
                        <li><strong>File Manager:</strong> Browse and manage uploaded files on the server.</li>
                    </ul>
                </div>
            </div>

            <!-- Graduation -->
            <div class="help-section" id="graduation">
                <h3><i class="bi bi-mortarboard"></i>Graduation</h3>
                <div class="help-card">
                    <h5>Graduation Management</h5>
                    <ul>
                        <li><strong>Graduation Clearance:</strong> Review and clear students for graduation based on academic and financial requirements.</li>
                        <li><strong>Graduation Lists:</strong> Generate and export lists of graduating students.</li>
                    </ul>
                </div>
            </div>

            <!-- Dissertation Links -->
            <div class="help-section" id="dissertation">
                <h3><i class="bi bi-journal-text"></i>Dissertation Links</h3>
                <div class="help-card">
                    <h5>Dissertation Administration</h5>
                    <p>Manage dissertation-related settings and assignments:</p>
                    <ul>
                        <li>Link students to dissertation supervisors.</li>
                        <li>Configure dissertation timelines and milestones.</li>
                        <li>Manage dissertation fee requirements.</li>
                    </ul>
                </div>
            </div>

            <!-- Messages & Announcements -->
            <div class="help-section" id="messages">
                <h3><i class="bi bi-megaphone"></i>Messages & Announcements</h3>
                <div class="help-card">
                    <h5>Communication Tools</h5>
                    <ul>
                        <li><strong>Messages:</strong> Send and receive internal messages to/from any user.</li>
                        <li><strong>Announcements:</strong> Post system-wide announcements visible to all students and lecturers.</li>
                    </ul>
                </div>
            </div>

            <!-- FAQ -->
            <div class="help-section" id="faq">
                <h3><i class="bi bi-question-circle"></i>Frequently Asked Questions</h3>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I reset a student's password? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Go to <strong>Reset Passwords</strong>, search for the student, and click Reset. You can also use bulk reset for multiple students. The new password will be their student ID by default.</div>
                </div>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I bulk-enroll students in courses? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Use the <strong>Course Allocations</strong> page to assign courses to programs. Students enrolled in a program are automatically linked to its courses.</div>
                </div>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I back up the database? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Go to <strong>Database Manager</strong> and click <strong>Backup</strong>. You can also use the export functionality to download specific tables.</div>
                </div>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I create a new user role? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Roles are predefined in the system (Student, Lecturer, Admin, Finance, Dean, HOD, etc.). To assign a role, go to <strong>Manage Users</strong>, find the user, and change their role from the edit page.</div>
                </div>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">A student registered with the wrong campus. How do I fix it? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Go to <strong>Approve Student Accounts</strong> or <strong>Manage Students</strong> and edit the student's campus field. You can change it using the campus dropdown before or after approval.</div>
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
