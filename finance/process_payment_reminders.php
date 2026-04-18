<?php
// finance/process_payment_reminders.php - Process & Send Payment Deadline Reminders
// Can be run manually from finance portal or via cron job
// Cron example: php /path/to/process_payment_reminders.php --cron
require_once '../includes/auth.php';
require_once '../includes/notifications.php';

$is_cron = (php_sapi_name() === 'cli' && in_array('--cron', $argv ?? []));
$is_web = !$is_cron;

if ($is_web) {
    requireLogin();
    requireRole(['finance', 'staff']);
}

$conn = getDbConnection();

// Check tables exist
$table_check = $conn->query("SHOW TABLES LIKE 'payment_deadlines'");
if (!$table_check || $table_check->num_rows === 0) {
    if ($is_cron) {
        echo "payment_deadlines table not found.\n";
        exit(1);
    }
    header('Location: payment_deadlines.php');
    exit;
}

$today = date('Y-m-d');
$log = [];
$total_sent = 0;
$total_skipped = 0;
$errors = [];

// Reminder schedule: type => days relative to deadline (negative = before, positive = after)
$reminder_schedule = [
    '10_days'      => -10,
    '5_days'       => -5,
    '2_days'       => -2,
    'on_day'       => 0,
    '1_day_after'  => 1,
    '2_days_after' => 2,
];

$reminder_labels = [
    '10_days'      => '10 Days Before Deadline',
    '5_days'       => '5 Days Before Deadline',
    '2_days'       => '2 Days Before Deadline',
    'on_day'       => 'Payment Deadline Today',
    '1_day_after'  => '1 Day Past Deadline',
    '2_days_after' => '2 Days Past Deadline (Final Warning)',
];

// Get all active deadlines
$deadlines = $conn->query("SELECT * FROM payment_deadlines WHERE is_active = 1 ORDER BY deadline_date ASC");
if (!$deadlines || $deadlines->num_rows === 0) {
    $log[] = 'No active payment deadlines found.';
    outputResults($log, $total_sent, $total_skipped, $errors, $is_web);
    exit;
}

while ($deadline = $deadlines->fetch_assoc()) {
    $deadline_date = $deadline['deadline_date'];
    $deadline_id = $deadline['deadline_id'];
    $installment_type = $deadline['installment_type'];
    $installment_label = $deadline['installment_label'];
    $program_type = $deadline['program_type'];
    $custom_message = $deadline['reminder_message'] ?? '';
    $amount = $deadline['amount_expected'];

    foreach ($reminder_schedule as $reminder_type => $day_offset) {
        // Calculate what date this reminder should fire
        $reminder_date = date('Y-m-d', strtotime("$day_offset days", strtotime($deadline_date)));
        
        // Only process if reminder date is today
        if ($reminder_date !== $today) {
            continue;
        }

        $log[] = "Processing: {$installment_label} - {$reminder_labels[$reminder_type]} (Deadline: {$deadline_date})";

        // Get students who should receive this reminder
        // Filter by program_type if set, and only active students with outstanding balance for this installment
        $student_sql = "SELECT s.student_id, s.full_name, s.email, s.program_type as student_program_type,
                               u.user_id,
                               sf.total_paid, sf.expected_total, sf.balance,
                               sf.application_fee_paid, sf.registration_paid,
                               sf.installment_1, sf.installment_2, sf.installment_3, sf.installment_4
                        FROM students s
                        LEFT JOIN users u ON u.related_student_id = s.student_id
                        LEFT JOIN student_finances sf ON s.student_id = sf.student_id
                        WHERE s.is_active = TRUE";

        if ($program_type === 'weekday') {
            $student_sql .= " AND s.program_type = 'weekday'";
        } elseif ($program_type === 'weekend') {
            $student_sql .= " AND s.program_type = 'weekend'";
        }

        $students = $conn->query($student_sql);
        if (!$students) {
            $errors[] = "Query failed for deadline #{$deadline_id}: " . $conn->error;
            continue;
        }

        while ($student = $students->fetch_assoc()) {
            // Check if this student actually owes for this installment
            if (!studentOwesForInstallment($student, $installment_type, $deadline)) {
                continue;
            }

            // Check if reminder already sent
            $check = $conn->prepare("SELECT reminder_id FROM payment_deadline_reminders 
                                     WHERE deadline_id = ? AND student_id = ? AND reminder_type = ?");
            $check->bind_param("iss", $deadline_id, $student['student_id'], $reminder_type);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $total_skipped++;
                continue;
            }

            // Build the reminder message
            $message = buildReminderMessage($reminder_type, $deadline, $student, $custom_message, $reminder_labels);
            $title = buildReminderTitle($reminder_type, $installment_label, $reminder_labels);

            // Send in-app notification
            $notification_id = null;
            if (!empty($student['user_id'])) {
                $notification_id = createNotification(
                    (int)$student['user_id'],
                    'finance',
                    $title,
                    $message,
                    'student/payment_history.php',
                    (string)$deadline_id,
                    'payment_deadline',
                    true // send email too
                );
            }

            // Record the reminder
            $stmt = $conn->prepare("INSERT INTO payment_deadline_reminders 
                (deadline_id, student_id, reminder_type, reminder_message, notification_id, email_sent) 
                VALUES (?, ?, ?, ?, ?, ?)");
            $email_sent = $notification_id ? 1 : 0;
            $stmt->bind_param("isssii", $deadline_id, $student['student_id'], $reminder_type, $message, $notification_id, $email_sent);
            $stmt->execute();

            $total_sent++;
        }
    }
}

$log[] = "Processing complete.";
$conn->close();
outputResults($log, $total_sent, $total_skipped, $errors, $is_web);

// ==================== HELPER FUNCTIONS ====================

/**
 * Check if a student still owes for a specific installment type
 */
function studentOwesForInstallment($student, $installment_type, $deadline) {
    $fee_settings_result = getDbConnection()->query("SELECT * FROM fee_settings LIMIT 1");
    $fee_settings = $fee_settings_result ? $fee_settings_result->fetch_assoc() : null;
    
    $app_fee = $fee_settings['application_fee'] ?? 5500;
    $reg_fee = $fee_settings['registration_fee'] ?? 39500;

    switch ($installment_type) {
        case 'application_fee':
            return ($student['application_fee_paid'] ?? 0) < $app_fee;
        case 'registration_fee':
            return ($student['registration_paid'] ?? 0) < $reg_fee;
        case 'installment_1':
            return ($student['installment_1'] ?? 0) < ($deadline['amount_expected'] ?: 1);
        case 'installment_2':
            return ($student['installment_2'] ?? 0) < ($deadline['amount_expected'] ?: 1);
        case 'installment_3':
            return ($student['installment_3'] ?? 0) < ($deadline['amount_expected'] ?: 1);
        case 'installment_4':
            return ($student['installment_4'] ?? 0) < ($deadline['amount_expected'] ?: 1);
        case 'clearance':
            return ($student['balance'] ?? 0) > 0;
        default:
            return ($student['balance'] ?? 0) > 0;
    }
}

/**
 * Build reminder notification title
 */
function buildReminderTitle($reminder_type, $installment_label, $labels) {
    $prefix = '';
    if (strpos($reminder_type, 'after') !== false) {
        $prefix = 'OVERDUE: ';
    } elseif ($reminder_type === 'on_day') {
        $prefix = 'DUE TODAY: ';
    } else {
        $prefix = 'Upcoming: ';
    }
    return $prefix . $installment_label;
}

/**
 * Build the reminder message with placeholders replaced
 */
function buildReminderMessage($reminder_type, $deadline, $student, $custom_message, $labels) {
    $installment_label = $deadline['installment_label'];
    $deadline_date = date('d M Y', strtotime($deadline['deadline_date']));
    $amount = $deadline['amount_expected'] > 0 ? 'K' . number_format($deadline['amount_expected'], 2) : '';
    $student_name = $student['full_name'] ?? 'Student';
    $balance = 'K' . number_format(max(0, $student['balance'] ?? 0), 2);

    // Use custom message if provided, replacing placeholders
    if (!empty($custom_message)) {
        $msg = str_replace(
            ['{student_name}', '{amount}', '{deadline_date}', '{installment}', '{balance}'],
            [$student_name, $amount, $deadline_date, $installment_label, $balance],
            $custom_message
        );
        return $msg;
    }

    // Default messages based on reminder type
    $urgency = $labels[$reminder_type] ?? '';
    
    switch ($reminder_type) {
        case '10_days':
            return "Dear {$student_name}, this is a friendly reminder that the {$installment_label} deadline is approaching on {$deadline_date}." 
                . ($amount ? " The expected payment is {$amount}." : '')
                . " Your current outstanding balance is {$balance}. Please ensure timely payment to avoid any disruptions to your studies.";
        
        case '5_days':
            return "Dear {$student_name}, your {$installment_label} payment is due in 5 days ({$deadline_date})."
                . ($amount ? " Amount due: {$amount}." : '')
                . " Outstanding balance: {$balance}. Please make your payment promptly.";
        
        case '2_days':
            return "URGENT: Dear {$student_name}, your {$installment_label} payment deadline is in just 2 days ({$deadline_date})."
                . ($amount ? " Amount due: {$amount}." : '')
                . " Outstanding balance: {$balance}. Please arrange payment immediately to avoid penalties.";
        
        case 'on_day':
            return "IMPORTANT: Dear {$student_name}, TODAY is the deadline for {$installment_label} ({$deadline_date})."
                . ($amount ? " Amount due: {$amount}." : '')
                . " Outstanding balance: {$balance}. Please make your payment today to remain in good academic standing.";
        
        case '1_day_after':
            return "OVERDUE NOTICE: Dear {$student_name}, the {$installment_label} deadline was yesterday ({$deadline_date})."
                . ($amount ? " Amount due: {$amount}." : '')
                . " Outstanding balance: {$balance}. Please clear your payment immediately. Failure to pay may result in restricted access to academic services.";
        
        case '2_days_after':
            return "FINAL WARNING: Dear {$student_name}, your {$installment_label} payment is now 2 days overdue (deadline was {$deadline_date})."
                . ($amount ? " Amount due: {$amount}." : '')
                . " Outstanding balance: {$balance}. This is a final reminder. Immediate payment is required to avoid suspension of academic services and access to learning materials.";
        
        default:
            return "Dear {$student_name}, payment reminder for {$installment_label} - deadline: {$deadline_date}. Outstanding balance: {$balance}.";
    }
}

/**
 * Output results for web or CLI
 */
function outputResults($log, $total_sent, $total_skipped, $errors, $is_web) {
    if (!$is_web) {
        // CLI output
        foreach ($log as $line) echo $line . "\n";
        echo "\nSent: {$total_sent} | Skipped (already sent): {$total_skipped}\n";
        if ($errors) {
            echo "Errors:\n";
            foreach ($errors as $e) echo "  - {$e}\n";
        }
        return;
    }

    // Web output
    $user = getCurrentUser();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Payment Reminders - Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include 'header_nav.php'; ?>

    <div class="container-fluid px-3 px-lg-4 mt-3 mt-lg-4 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-send-check me-2"></i>Process Payment Reminders</h1>
                <p class="text-muted mb-0">Results of automatic reminder processing for <?php echo date('d M Y'); ?></p>
            </div>
            <div>
                <a href="payment_deadlines.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Deadlines</a>
                <a href="process_payment_reminders.php" class="btn btn-warning btn-sm"><i class="bi bi-arrow-clockwise me-1"></i> Run Again</a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h2 class="text-success mb-0"><?php echo $total_sent; ?></h2>
                        <small class="text-muted">Reminders Sent</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h2 class="text-secondary mb-0"><?php echo $total_skipped; ?></h2>
                        <small class="text-muted">Already Sent (Skipped)</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-<?php echo count($errors) ? 'danger' : 'info'; ?>">
                    <div class="card-body text-center">
                        <h2 class="text-<?php echo count($errors) ? 'danger' : 'info'; ?> mb-0"><?php echo count($errors); ?></h2>
                        <small class="text-muted">Errors</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Processing Log -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-journal-text me-1"></i> Processing Log</h5>
            </div>
            <div class="card-body">
                <?php if (empty($log) && $total_sent === 0): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-check-circle display-4 text-success"></i>
                        <p class="mt-2 text-muted">No reminders due for today (<?php echo date('d M Y'); ?>). All up to date!</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($log as $line): ?>
                        <div class="list-group-item py-2">
                            <small><i class="bi bi-arrow-right-short me-1"></i> <?php echo htmlspecialchars($line); ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($errors): ?>
                <div class="mt-3">
                    <h6 class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i> Errors</h6>
                    <?php foreach ($errors as $e): ?>
                    <div class="alert alert-danger py-1 small"><?php echo htmlspecialchars($e); ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Auto-reminder Setup Info -->
        <div class="card mt-4">
            <div class="card-body">
                <h6><i class="bi bi-info-circle me-1"></i> Automated Reminders Setup</h6>
                <p class="text-muted small mb-2">To automate daily reminder processing, set up a cron job (Linux) or Task Scheduler (Windows):</p>
                <div class="bg-dark text-white p-2 rounded small">
                    <code># Linux cron (runs daily at 7:00 AM):<br>
                    0 7 * * * php <?php echo realpath(__DIR__); ?>/process_payment_reminders.php --cron<br><br>
                    # Windows Task Scheduler:<br>
                    php.exe "<?php echo realpath(__DIR__); ?>\process_payment_reminders.php" --cron</code>
                </div>
                <p class="text-muted small mt-2 mb-0">Or include this auto-trigger on dashboard load (already integrated).</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php } ?>
