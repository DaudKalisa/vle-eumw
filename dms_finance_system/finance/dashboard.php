<?php
require_once __DIR__ . '/../includes/ui.php';
dmsRequireRole(['finance_officer', 'admin']);

$conn = dmsGetDbConnection();
$user = dmsCurrentUser();
$uid = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'record_payment') {
        $feeId = (int)($_POST['fee_id'] ?? 0);
        $installmentNo = (int)($_POST['installment_no'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $reference = trim($_POST['payment_reference'] ?? '');

        if ($feeId <= 0 || $installmentNo < 1 || $installmentNo > 3 || $amount <= 0) {
            $_SESSION['dms_flash_error'] = 'Provide valid payment details.';
        } else {
            $get = $conn->prepare('SELECT * FROM dissertation_fees WHERE fee_id = ? LIMIT 1');
            $get->bind_param('i', $feeId);
            $get->execute();
            $fee = $get->get_result()->fetch_assoc();

            if (!$fee) {
                $_SESSION['dms_flash_error'] = 'Fee record not found.';
            } else {
                $col = 'installment_' . $installmentNo . '_paid';
                $newInstallment = (float)$fee[$col] + $amount;
                $newTotal = (float)$fee['total_paid'] + $amount;
                $newBalance = (float)$fee['total_fee'] - $newTotal;
                if ($newBalance < 0) {
                    $newBalance = 0;
                }

                $upd = $conn->prepare("UPDATE dissertation_fees SET {$col} = ?, total_paid = ?, balance = ? WHERE fee_id = ?");
                $upd->bind_param('dddi', $newInstallment, $newTotal, $newBalance, $feeId);
                if ($upd->execute()) {
                    $tx = $conn->prepare('INSERT INTO payment_transactions (fee_id, student_user_id, installment_no, amount, payment_reference, payment_date, recorded_by, notes) VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?)');
                    $studentUid = (int)$fee['student_user_id'];
                    $notes = 'Dissertation installment ' . $installmentNo;
                    $tx->bind_param('iiidsis', $feeId, $studentUid, $installmentNo, $amount, $reference, $uid, $notes);
                    $tx->execute();
                    $_SESSION['dms_flash_success'] = 'Payment recorded successfully.';
                } else {
                    $_SESSION['dms_flash_error'] = 'Failed to update fee record.';
                }
            }
        }
    }

    if ($action === 'toggle_locks') {
        $feeId = (int)($_POST['fee_id'] ?? 0);
        $l1 = isset($_POST['lock_before_proposal']) ? 1 : 0;
        $l2 = isset($_POST['lock_before_ethics']) ? 1 : 0;
        $l3 = isset($_POST['lock_before_final']) ? 1 : 0;
        $upd = $conn->prepare('UPDATE dissertation_fees SET lock_before_proposal = ?, lock_before_ethics = ?, lock_before_final = ? WHERE fee_id = ?');
        $upd->bind_param('iiii', $l1, $l2, $l3, $feeId);
        if ($upd->execute()) {
            $_SESSION['dms_flash_success'] = 'Lock settings updated.';
        } else {
            $_SESSION['dms_flash_error'] = 'Failed to update lock settings.';
        }
    }

    header('Location: ' . dmsBaseUrl() . '/finance/dashboard.php');
    exit;
}

$stats = $conn->query('SELECT COUNT(*) records, COALESCE(SUM(total_fee),0) expected, COALESCE(SUM(total_paid),0) paid, COALESCE(SUM(balance),0) outstanding FROM dissertation_fees')->fetch_assoc();

$sql = "SELECT f.*, u.full_name student_name, d.title dissertation_title
        FROM dissertation_fees f
        JOIN users u ON f.student_user_id = u.user_id
        JOIN dissertations d ON f.dissertation_id = d.dissertation_id
        ORDER BY f.updated_at DESC";
$rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

dmsRenderPageStart('Finance Dashboard', $user);
dmsFlashMessage();
?>
<div class="grid">
    <div class="card"><div class="muted">Fee Records</div><div class="stat"><?= (int)$stats['records'] ?></div></div>
    <div class="card"><div class="muted">Expected</div><div class="stat">MKW <?= number_format((float)$stats['expected'], 2) ?></div></div>
    <div class="card"><div class="muted">Collected</div><div class="stat">MKW <?= number_format((float)$stats['paid'], 2) ?></div></div>
    <div class="card"><div class="muted">Outstanding</div><div class="stat">MKW <?= number_format((float)$stats['outstanding'], 2) ?></div></div>
</div>

<div class="card">
    <h3>Dissertation Finance Records</h3>
    <table>
        <tr><th>Student</th><th>Dissertation</th><th>Totals</th><th>Installments</th><th>Locks</th></tr>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['student_name']) ?></td>
                <td><?= htmlspecialchars($r['dissertation_title']) ?></td>
                <td>
                    Total: MKW <?= number_format((float)$r['total_fee'], 2) ?><br>
                    Paid: MKW <?= number_format((float)$r['total_paid'], 2) ?><br>
                    Balance: MKW <?= number_format((float)$r['balance'], 2) ?>
                </td>
                <td>
                    <form method="POST">
                        <input type="hidden" name="action" value="record_payment">
                        <input type="hidden" name="fee_id" value="<?= (int)$r['fee_id'] ?>">
                        <select name="installment_no" required>
                            <option value="1">Installment 1</option>
                            <option value="2">Installment 2</option>
                            <option value="3">Installment 3</option>
                        </select>
                        <input type="number" name="amount" min="0.01" step="0.01" placeholder="Amount" required>
                        <input type="text" name="payment_reference" placeholder="Reference number">
                        <button class="btn" type="submit">Record Payment</button>
                    </form>
                </td>
                <td>
                    <form method="POST">
                        <input type="hidden" name="action" value="toggle_locks">
                        <input type="hidden" name="fee_id" value="<?= (int)$r['fee_id'] ?>">
                        <label><input type="checkbox" name="lock_before_proposal" <?= ((int)$r['lock_before_proposal'] === 1) ? 'checked' : '' ?>> proposal lock</label><br>
                        <label><input type="checkbox" name="lock_before_ethics" <?= ((int)$r['lock_before_ethics'] === 1) ? 'checked' : '' ?>> ethics lock</label><br>
                        <label><input type="checkbox" name="lock_before_final" <?= ((int)$r['lock_before_final'] === 1) ? 'checked' : '' ?>> final lock</label><br>
                        <button class="btn secondary" type="submit">Save Locks</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h3>Recent Transactions</h3>
    <?php
    $tx = $conn->query("SELECT t.*, u.full_name student_name FROM payment_transactions t JOIN users u ON t.student_user_id = u.user_id ORDER BY t.created_at DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
    ?>
    <table>
        <tr><th>Date</th><th>Student</th><th>Installment</th><th>Amount</th><th>Reference</th></tr>
        <?php foreach ($tx as $t): ?>
            <tr>
                <td><?= htmlspecialchars($t['payment_date']) ?></td>
                <td><?= htmlspecialchars($t['student_name']) ?></td>
                <td><?= (int)$t['installment_no'] ?></td>
                <td>MKW <?= number_format((float)$t['amount'], 2) ?></td>
                <td><?= htmlspecialchars((string)$t['payment_reference']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php dmsRenderPageEnd(); ?>
