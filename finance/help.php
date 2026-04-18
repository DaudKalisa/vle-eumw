<?php
/**
 * Finance Portal - Help & User Manual
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
    <title>Help & User Manual - Finance Portal</title>
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
                <h2><i class="bi bi-cash-stack me-2"></i>Finance Portal User Manual</h2>
                <p>Complete guide to student fee management, payments, and lecturer claims</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <div class="help-search"><input type="text" id="helpSearch" class="form-control" placeholder="Search help topics..." autofocus></div>
            </div>
        </div>
    </div>

    <div class="quick-nav">
        <a href="#student-finances" class="quick-nav-item"><i class="bi bi-people"></i><span>Student Finances</span></a>
        <a href="#payments" class="quick-nav-item"><i class="bi bi-credit-card"></i><span>Record Payments</span></a>
        <a href="#review" class="quick-nav-item"><i class="bi bi-check-circle"></i><span>Review Payments</span></a>
        <a href="#lecturer-claims" class="quick-nav-item"><i class="bi bi-receipt"></i><span>Lecturer Claims</span></a>
        <a href="#fee-settings" class="quick-nav-item"><i class="bi bi-sliders"></i><span>Fee Settings</span></a>
        <a href="#reports" class="quick-nav-item"><i class="bi bi-graph-up"></i><span>Reports</span></a>
    </div>

    <div class="row">
        <div class="col-lg-3 d-none d-lg-block">
            <div class="help-toc">
                <h6 class="text-muted mb-3" style="font-size:.75rem;text-transform:uppercase;letter-spacing:1px">Contents</h6>
                <a href="#getting-started">Getting Started</a>
                <a href="#dashboard">Dashboard</a>
                <a href="#student-finances">Student Finances</a>
                <a href="#payments">Record Payments</a>
                <a href="#review">Review Payments</a>
                <a href="#outstanding">Outstanding Balances</a>
                <a href="#lecturer-claims">Lecturer Claims</a>
                <a href="#fee-settings">Fee Settings</a>
                <a href="#deadlines">Payment Deadlines</a>
                <a href="#dissertation-fees">Dissertation Fees</a>
                <a href="#reports">Finance Reports</a>
                <a href="#graduation">Graduation Clearance</a>
                <a href="#faq">FAQ</a>
            </div>
        </div>

        <div class="col-lg-9">

            <div class="help-section" id="getting-started">
                <h3><i class="bi bi-rocket-takeoff"></i>Getting Started</h3>
                <div class="help-card">
                    <h5>Finance Portal Overview</h5>
                    <p>The Finance Portal is your central hub for all financial operations within the VLE:</p>
                    <ul>
                        <li>Recording and verifying student fee payments</li>
                        <li>Managing student financial accounts and outstanding balances</li>
                        <li>Processing lecturer finance/travel claims</li>
                        <li>Configuring fee structures and payment deadlines</li>
                        <li>Generating financial reports</li>
                        <li>Managing graduation clearance (financial)</li>
                    </ul>
                </div>
            </div>

            <div class="help-section" id="dashboard">
                <h3><i class="bi bi-speedometer2"></i>Dashboard</h3>
                <div class="help-card">
                    <h5>Finance Dashboard</h5>
                    <ul>
                        <li><strong>Revenue Summary:</strong> Total collected fees, pending payments, and current period revenue.</li>
                        <li><strong>Pending Reviews:</strong> Number of payment submissions awaiting verification.</li>
                        <li><strong>Outstanding Balances:</strong> Total outstanding student fees.</li>
                        <li><strong>Lecturer Claims:</strong> Pending claims requiring processing.</li>
                        <li><strong>Recent Activity:</strong> Latest payment records and approvals.</li>
                    </ul>
                </div>
            </div>

            <div class="help-section" id="student-finances">
                <h3><i class="bi bi-people"></i>Student Finances</h3>
                <div class="help-card">
                    <h5>Viewing Student Financial Records</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Student Finances</strong> to view all students and their financial status.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Use filters to search by <strong>student name/ID</strong>, <strong>program</strong>, <strong>campus</strong>, or <strong>payment status</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Click on a student to see their detailed financial profile: total fees, payments made, outstanding balance, and payment history.</p></div>
                    </div>
                </div>
            </div>

            <div class="help-section" id="payments">
                <h3><i class="bi bi-credit-card"></i>Recording Payments</h3>
                <div class="help-card">
                    <h5>Recording a Student Payment</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Record Payment</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Search for the student by name or ID.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Enter the <strong>amount</strong>, <strong>payment date</strong>, <strong>payment method</strong>, and <strong>receipt/reference number</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Click <strong>Save Payment</strong>. The student's balance is updated automatically.</p></div>
                    </div>
                    <div class="help-tip">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tip:</strong> Always verify the receipt or bank reference before recording a payment.
                    </div>
                </div>
            </div>

            <div class="help-section" id="review">
                <h3><i class="bi bi-check-circle"></i>Reviewing Payment Submissions</h3>
                <div class="help-card">
                    <h5>Verifying Student-Submitted Payments</h5>
                    <p>Students can submit payment proofs via their portal. You need to review and verify them:</p>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Review Payments</strong> to see all pending submissions.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Click on a submission to view the details and the uploaded proof document/image.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Cross-check the amount and reference with your bank records.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Click <strong>Approve</strong> to record the payment, or <strong>Reject</strong> with a reason if the proof is invalid.</p></div>
                    </div>
                    <div class="help-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Important:</strong> Always verify payments against actual bank statements before approving. Fraudulent submissions should be rejected and reported.
                    </div>
                </div>
            </div>

            <div class="help-section" id="outstanding">
                <h3><i class="bi bi-exclamation-diamond"></i>Outstanding Balances</h3>
                <div class="help-card">
                    <h5>Managing Outstanding Fees</h5>
                    <p>Go to <strong>Outstanding Balances</strong> to see all students with unpaid fees:</p>
                    <ul>
                        <li>Filter by program, campus, or balance range.</li>
                        <li>Export the list for follow-up communications.</li>
                        <li>Students below the payment threshold may have restricted content access.</li>
                    </ul>
                </div>
            </div>

            <div class="help-section" id="lecturer-claims">
                <h3><i class="bi bi-receipt"></i>Lecturer Finance Claims</h3>
                <div class="help-card">
                    <h5>Processing Lecturer Claims</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Lecturer Finance Requests</strong> to see submitted claims.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Review claim details: type, amount, supporting documents, and approval chain status.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Claims that have been approved by HOD and Dean can be <strong>processed for payment</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Mark as <strong>Processed</strong> once payment has been made to the lecturer.</p></div>
                    </div>
                </div>
            </div>

            <div class="help-section" id="fee-settings">
                <h3><i class="bi bi-sliders"></i>Fee Settings</h3>
                <div class="help-card">
                    <h5>Configuring Fee Structures</h5>
                    <ul>
                        <li>Set tuition fees per program, year of study, and semester.</li>
                        <li>Configure the minimum payment threshold percentage for content access.</li>
                        <li>Define fee categories (tuition, lab fees, library fees, etc.).</li>
                        <li>Rate revision management for adjusting fees across periods.</li>
                    </ul>
                </div>
            </div>

            <div class="help-section" id="deadlines">
                <h3><i class="bi bi-clock-history"></i>Payment Deadlines</h3>
                <div class="help-card">
                    <h5>Setting Payment Deadlines</h5>
                    <p>Configure payment deadlines per semester to encourage timely payments:</p>
                    <ul>
                        <li>Set early-bird deadlines with discounts (if applicable).</li>
                        <li>Set final payment deadlines with consequences for non-payment.</li>
                        <li>Students see deadline reminders on their dashboard.</li>
                    </ul>
                </div>
            </div>

            <div class="help-section" id="dissertation-fees">
                <h3><i class="bi bi-journal-text"></i>Dissertation Fees</h3>
                <div class="help-card">
                    <h5>Managing Dissertation Fees</h5>
                    <p>Separate fee tracking for dissertation students:</p>
                    <ul>
                        <li>View and manage dissertation-specific fees.</li>
                        <li>Track dissertation fee payments independently from tuition.</li>
                        <li>Verify dissertation fee proof submissions.</li>
                    </ul>
                </div>
            </div>

            <div class="help-section" id="reports">
                <h3><i class="bi bi-graph-up"></i>Finance Reports</h3>
                <div class="help-card">
                    <h5>Generating Financial Reports</h5>
                    <ul>
                        <li><strong>Revenue Reports:</strong> Total fees collected per period, program, or campus.</li>
                        <li><strong>Outstanding Reports:</strong> Students with unpaid balances.</li>
                        <li><strong>Payment Breakdown:</strong> Analysis by payment method, date range.</li>
                        <li><strong>Lecturer Claims Report:</strong> Summary of processed and pending claims.</li>
                        <li>All reports can be <strong>exported as Excel/PDF</strong>.</li>
                    </ul>
                </div>
            </div>

            <div class="help-section" id="graduation">
                <h3><i class="bi bi-mortarboard"></i>Graduation Clearance</h3>
                <div class="help-card">
                    <h5>Financial Clearance for Graduation</h5>
                    <div class="help-step">
                        <div class="help-step-num">1</div>
                        <div class="help-step-content"><p>Go to <strong>Graduation Clearance</strong>.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">2</div>
                        <div class="help-step-content"><p>Review graduating students' financial status — all fees must be fully paid.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">3</div>
                        <div class="help-step-content"><p>Grant <strong>Financial Clearance</strong> for students with zero outstanding balance.</p></div>
                    </div>
                    <div class="help-step">
                        <div class="help-step-num">4</div>
                        <div class="help-step-content"><p>Flag students with outstanding balances for follow-up.</p></div>
                    </div>
                </div>
            </div>

            <div class="help-section" id="faq">
                <h3><i class="bi bi-question-circle"></i>Frequently Asked Questions</h3>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">A student's payment proof looks suspicious. What should I do? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Reject the submission with a clear reason. Cross-check with bank records. If you suspect fraud, report it to the administration immediately.</div>
                </div>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I correct a wrongly recorded payment? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Go to the student's financial record, find the incorrect payment entry, and edit or void it. Add a note explaining the correction for audit purposes.</div>
                </div>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How does the payment threshold affect students? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Students who have paid less than the configured threshold percentage of their total fees may be restricted from accessing course content and taking exams.</div>
                </div>
                <div class="faq-item" onclick="this.classList.toggle('open')">
                    <div class="faq-q">How do I process a lecturer claim? <i class="bi bi-chevron-down faq-chevron"></i></div>
                    <div class="faq-a">Claims must be approved by the lecturer's HOD and Dean before you can process them. Once approved, go to <strong>Lecturer Finance Requests</strong>, review the claim, and mark it as Processed after payment is made.</div>
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
