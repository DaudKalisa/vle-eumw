# Dissertation Fee Proof of Payment - Test Flow

## Overview
This document outlines the complete flow for submitting, approving, and managing dissertation fee payment proofs.

## System Components

### 1. Student Side (`student/dissertation.php`)
**Location**: Card titled "Dissertation Fee Payment" (appears when `$diss_fee` exists)

**User Actions**:
- Student selects installment (1st, 2nd, or 3rd)
- Enters amount paid (MK)
- Uploads proof file (JPG, PNG, PDF, max 5MB)
- Enters payment reference/notes (optional)
- Submits form

**Backend Processing** (`action === 'submit_fee_proof'`):
1. Validates dissertation_id, installment_num (1-3), amount > 0
2. Validates proof file exists and size ≤ 5MB
3. Creates upload directory if missing: `uploads/dissertations/fee_proofs/`
4. Saves file with naming: `feeproof_{student_id}_d{dissertation_id}_i{installment_num}_{timestamp}.{ext}`
5. Inserts record into `payment_transactions` table with:
   - `proof_file`: path to uploaded file
   - `approval_status`: 'pending' (default)
   - `installment_no`: installment number
   - `fee_id`: link to dissertation_fees record
6. Returns success message: "Proof of payment submitted successfully. Awaiting finance approval."

**Database Schema** (`payment_transactions` table):
New columns added:
```sql
ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS proof_file VARCHAR(255) DEFAULT NULL;
ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS approval_status ENUM('pending','approved','rejected') DEFAULT 'pending';
ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS installment_no INT DEFAULT NULL;
ALTER TABLE payment_transactions ADD COLUMN IF NOT EXISTS fee_id INT DEFAULT NULL;
```

### 2. Finance Side (`finance/dissertation_fees.php`)
**Finance Staff UI**:
- Dashboard displays stats (invoiced, expected, collected, outstanding, fully paid, locked count)
- Main table shows all dissertation fee records with installment progress bars
- Separate section: "Pending Proofs of Payment for Approval"

**Pending Proofs Table** (appears when `$pending_proofs->num_rows > 0`):
Shows:
- Student name & ID
- Installment number + amount required
- Amount submitted
- Proof file (clickable "View" link)
- Payment reference
- Submitted date
- Action buttons: "Approve" (green) and "Reject" (red)

**Backend Processing** (POST with `proof_action`):
1. Validates: proof_id, decision (approve/reject), installment_no (1-3), fee_id
2. Updates `payment_transactions.approval_status` to 'approved' or 'rejected'
3. **If approved**:
   - Fetches amount from payment_transactions
   - Fetches current installment values from dissertation_fees
   - Calculates new values:
     - `new_inst = installment_{n}_paid + amount`
     - `new_total = total_paid + amount`
     - `new_balance = fee_amount - new_total` (minimum 0)
   - Updates dissertation_fees with new values
   - Sets `installment_{n}_date` to CURDATE()
4. Returns success message for approved/rejected

### 3. Access Control (`student/dissertation.php` ~ lines 570-600)
**Fee Lock Logic**:
Determines if student is blocked from proceeding:

```php
if ($diss_fee) {
    $supervisor_assigned = !empty($dissertation['supervisor_id']);
    
    // Lock 1: After supervisor assigned, 1st installment required
    if ($fee_locks['supervisor'] && $supervisor_assigned && !in_array($current_phase, ['topic'])) {
        $fee_lock_active = true;
        $fee_lock_message = 'Your 1st dissertation fee installment (MK X) is required...';
        $can_submit = false;
    }
    
    // Lock 2: Before ethics, proposal defense phases
    if ($fee_locks['ethics'] && in_array($current_phase, ['ethics', 'defense', 'proposal'])) {
        $fee_lock_active = true;
        $fee_lock_message = 'Your 2nd dissertation fee installment (MK X) is required...';
        $can_submit = false;
    }
    
    // Lock 3: Before final submission/presentation
    if ($fee_locks['final'] && in_array($current_phase, ['final_draft', 'final_submission'])) {
        $fee_lock_active = true;
        $fee_lock_message = 'Your 3rd dissertation fee installment (MK X) is required...';
        $can_submit = false;
    }
}
```

**Lock Status** (set in `fee_locks` array):
- `lock_after_supervisor`: 1 if finance enabled AND installment_1_paid < installment_amount
- `lock_before_ethics`: 1 if finance enabled AND installment_2_paid < installment_amount
- `lock_before_final`: 1 if finance enabled AND installment_3_paid < installment_amount

## Test Flow with Examples

### Test Case 1: Successful Student Submission → Finance Approval

#### Step 1: Finance Invoices Student
1. Go to: `finance/dissertation_fees.php`
2. Click "Invoice All" or manually invoice student
3. Student now has `dissertation_fees` record with:
   - `fee_amount`: 250,000 MK
   - `installment_amount`: 83,333.33 MK
   - `lock_after_supervisor`: 1 (enabled)

#### Step 2: Student Submits Proof
1. Go to: `student/dissertation.php`
2. Scroll to "Dissertation Fee Payment" card
3. Fill form:
   - Installment: 1st Installment (After Supervisor)
   - Amount: 83333
   - Proof file: Upload bank slip image
   - Reference: "ACC12345" (optional)
4. Click "Submit Proof of Payment"
5. **Backend result**:
   - File saved to: `uploads/dissertations/fee_proofs/feeproof_{id}_d{did}_i1_{timestamp}.jpg`
   - Record inserted into `payment_transactions`:
     ```sql
     payment_type: 'dissertation_installment_1'
     approval_status: 'pending'
     proof_file: 'uploads/dissertations/fee_proofs/...'
     installment_no: 1
     fee_id: <fee_record_id>
     ```
   - Message: "Proof of payment submitted successfully. Awaiting finance approval."

#### Step 3: Finance Reviews Proof
1. Go to: `finance/dissertation_fees.php`
2. Scroll to "Pending Proofs of Payment for Approval" (visible if any pending)
3. Row shows:
   - Student: "John Doe (STD001234)"
   - Installment: "1 / MK 83,333.33"
   - Amount: "MK 83,333.00"
   - Proof File: [View] link
4. Finance staff clicks "View" to verify bank slip
5. Finance staff clicks "Approve" button
6. **Backend result**:
   - `payment_transactions.approval_status` → 'approved'
   - `dissertation_fees.installment_1_paid` → 83,333.00 (new total)
   - `dissertation_fees.total_paid` → 83,333.00 (new total)
   - `dissertation_fees.balance` → 166,666.67 (new balance)
   - `dissertation_fees.installment_1_date` → TODAY
   - Message: "Proof approved and installment marked as paid."

##### Now Student Access is Unlocked!
1. Student returns to `student/dissertation.php`
2. `$fee_locks['supervisor']` → false (because installment_1_paid >= installment_amount)
3. `$can_submit` → true
4. Student can now submit chapters after supervisor assignment

### Test Case 2: Finance Rejects Proof

#### Step 1-2: Same as above (student submits proof)

#### Step 3: Finance Rejects Proof
1. Finance staff clicks "Reject" button on pending proof
2. **Backend result**:
   - `payment_transactions.approval_status` → 'rejected'
   - `dissertation_fees` values unchanged (no automatic updates)
3. Proof disappears from "Pending Proofs" table

#### Step 4: Student Resubmits
1. Student can submit another proof (previous one didn't update installment)
2. Process repeats from Test Case 1, Step 2

## Database Schema Summary

### payment_transactions Table (New Columns)
```sql
Column             | Type                                      | Default
proof_file         | VARCHAR(255)                              | NULL
approval_status    | ENUM('pending','approved','rejected')    | 'pending'
installment_no     | INT                                       | NULL
fee_id             | INT                                       | NULL
```

### dissertation_fees Table (Existing Columns Used)
```sql
Column                      | Type       | Purpose
installment_1_paid          | DECIMAL    | Amount paid for 1st installment
installment_1_date          | DATE       | Date 1st installment was approved
installment_2_paid          | DECIMAL    | Amount paid for 2nd installment
installment_2_date          | DATE       | Date 2nd installment was approved
installment_3_paid          | DECIMAL    | Amount paid for 3rd installment
installment_3_date          | DATE       | Date 3rd installment was approved
total_paid                  | DECIMAL    | Total amount paid across all installments
balance                     | DECIMAL    | Remaining balance (fee_amount - total_paid)
lock_after_supervisor       | TINYINT    | Finance-controlled lock for 1st installment
lock_before_ethics         | TINYINT    | Finance-controlled lock for 2nd installment
lock_before_final          | TINYINT    | Finance-controlled lock for 3rd installment
```

## Key Features

✅ **Student-Friendly**: 
- Simple form to upload proof
- Clear messaging about approval status
- Can see which installments are paid/pending

✅ **Finance-Controlled**:
- View all pending proofs in one place
- Can approve/reject with one click
- Automatic updates to installment tracking
- Lock/unlock controls for each phase

✅ **Automatic Access Control**:
- Submission locks are automatically lifted when installment is approved
- Students see clear messages about payment requirements
- No manual intervention needed after approval

✅ **Audit Trail**:
- All proofs stored with student/dissertation/installment info
- Proof files kept in dedicated directory
- Approval status tracked in database
- Date of approval recorded

## Testing Checklist

- [ ] Student can submit proof form (all fields required except reference)
- [ ] File upload validates size (max 5MB) and extensions
- [ ] Proof appears in finance "Pending Proofs" table
- [ ] Finance can view uploaded proof file
- [ ] Finance can approve proof (updates installment_paid values)
- [ ] Finance can reject proof (status changes, no fee update)
- [ ] Student lock status updates after approval
- [ ] Student can submit new chapters after lock is lifted
- [ ] All payment confirmations are recorded in database
- [ ] Installment dates are set to approval date

## File Locations

- Student Form: [student/dissertation.php](student/dissertation.php#L848)
- Finance UI: [finance/dissertation_fees.php](finance/dissertation_fees.php#L608-L640)
- Upload Directory: `uploads/dissertations/fee_proofs/`
- Database Table: `payment_transactions` (with new columns)
