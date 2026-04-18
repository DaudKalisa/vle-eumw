# Dissertation Fee Proof of Payment - Quick Start Guide

## For Students

### How to Submit a Proof of Payment

1. **Go to Student Portal** → Dissertation
2. **Scroll to "Dissertation Fee Payment" card**
3. **Fill the form**:
   - **Installment**: Select which installment you're paying (1st, 2nd, or 3rd)
   - **Amount Paid (MK)**: Enter the amount (in Malawi Kwacha)
   - **Bank Slip / Proof**: Upload a photo/screenshot or PDF of your bank receipt
   - **Payment Reference** (optional): Add transaction ID or notes if you want

4. **Click "Submit Proof of Payment"**
5. **You'll see**: "Proof of payment submitted successfully. Awaiting finance approval."

### What Happens Next?

- Finance staff will review your proof within 2-3 business days
- Once approved, the fee payment will be recorded and your access will be unlocked
- You'll see your payment reflected in the "Dissertation Fee" card

### What If My Access Is Locked?

You'll see a red alert: **"Dissertation Access Restricted"**

This means:
- Your 1st installment is due (after supervisor assignment)
- OR your 2nd installment is due (before ethics/defense)
- OR your 3rd installment is due (before final presentation)

**Solution**: Submit your proof of payment using the form on this same page.

### Checking Payment Status

- Look at the **"Dissertation Fee" card** (right side)
- See your total progress (e.g., "MK 83,333 / MK 250,000")
- Each installment shows:
  - **Paid** (green) = installment fully paid
  - **X%** (yellow) = partially paid
  - **Pending** (gray) = not yet paid
- See your remaining balance at the bottom

### Allowed File Formats

Upload as one of these:
- JPG (photo of bank slip)
- PNG (screenshot)
- PDF (scanned or digital receipt)

Max size: 5MB

---

## For Finance Staff

### How to Approve/Reject Payment Proofs

1. **Go to Finance Portal** → Dissertation Fees
2. **Scroll to "Pending Proofs of Payment for Approval"** (bottom of page)
3. **Review the proof**:
   - Click **"View"** button to download and check the bank slip
   - Verify amount and reference match submission

4. **Make a decision**:
   - **✓ Approve**: Click green "Approve" button
   - **✗ Reject**: Click red "Reject" button

5. **Result**:
   - If approved: Fee records update automatically, student access unlocked
   - If rejected: Student can submit another proof

### Approve vs Reject

**Click Approve When**:
- Bank slip is clear and valid
- Amount matches what student submitted
- Reference number is valid
- Payment date is recent (within reasonable timeframe)

**Click Reject When**:
- Bank slip is unclear or fake
- Amount doesn't match
- Student paid wrong account
- Payment reference is missing or wrong

### What Gets Updated on Approval?

When you click "Approve":

| Field | Changes |
|-------|---------|
| `payment_transactions.approval_status` | pending → approved |
| `dissertation_fees.installment_n_paid` | increases by amount |
| `dissertation_fees.total_paid` | increases by amount |
| `dissertation_fees.balance` | decreases by amount |
| `dissertation_fees.installment_n_date` | set to today's date |

**Student's fee lock automatically lifts** → They can proceed with next phase

### Dashboard Overview

At top of page, you see:

- **Invoiced**: How many students have fee records
- **Expected**: Total fee amount across all students
- **Collected**: Total amount paid so far
- **Outstanding**: Total still owed by all students
- **Fully Paid**: Number of students with 0 balance
- **Locked**: Number of students with access restricted

### Main Fee Records Table

Shows each student's:
- 1st Installment progress bar
- 2nd Installment progress bar  
- 3rd Installment progress bar
- Total paid (green)
- Balance owed (red if > 0)
- Which access locks are enabled

### Quick Actions

**Record Payment Manually** (without proof):
- Click 💰 button on student row
- Enter installment, amount, and reference
- This bypasses the proof submission

**Update Access Locks**:
- Click 🔒 button on student row
- Toggle which phases should require payment
- Save settings

**Lock/Unlock All Students**:
Use "Bulk Access Lock Controls" section:
- Lock all students for 1st/2nd/3rd installment
- Unlock all students for a specific installment

**Find Students**:
- Use filter dropdown (All, Outstanding, Fully Paid, Locked)
- Use search box to find by student ID or name

---

## FAQ

### Q: What if a student submits the wrong amount?
**A**: They can submit another proof with the correct amount. You approve the one that's correct.

### Q: What if a student pays in installments (e.g., 40,000 + 43,333)?
**A**: Each submission is separate. Student submits proof for 40,000, finance approves. Then student submits proof for 43,333 later, finance approves. Both add up in the system.

### Q: Can I approve partial payments?
**A**: Yes. If installment is 83,333 but student paid 50,000, approve 50,000. The system will show 50,000/83,333 (60%) until remaining 33,333 is posted.

### Q: What happens if I reject a proof by mistake?
**A**: Student resubmits and you can approve the same proof again. Or manually record the payment using the Record Payment button.

### Q: Can students see if their proof is approved/rejected?
**A**: Yes, they can see the payment status immediately. Once you approve, their lock lifts and payment shows as "Paid" in their dashboard.

### Q: Where are the proof files stored?
**A**: All uploaded proof files are saved in: `/uploads/dissertations/fee_proofs/`
Filename format: `feeproof_{student_id}_d{dissertation_id}_i{installment}_[timestamp].jpg`

### Q: Can I download/export proof files?
**A**: Yes, click the "View" link to download any submitted proof.

### Q: What if a student's proof file is deleted?
**A**: The database record remains, but the file is gone. You can still see it was submitted, but won't be able to view the file. Keep regular backups of the uploads folder.

### Q: Is there an audit trail?
**A**: Yes. Every submission creates a record in `payment_transactions` with:
- Student ID
- Amount & reference
- Date submitted
- Finance decision (approved/rejected)
- Filename (for retrieval)

---

## Common Issues & Solutions

| Issue | Solution |
|-------|----------|
| "File size must not exceed 5MB" | Compress the image or PDF before uploading |
| "Please provide valid payment details" | Make sure installment is 1, 2, or 3, and amount > 0 |
| Proof doesn't appear in pending list | Refresh page; check if it was already approved |
| Student can't submit proof | Check if they're logged in and have an active dissertation |
| Balance not updating after approval | Refresh page to reload data from database |
| "Failed to save payment record" | Contact admin; likely a database issue |

---

## Support

For issues or questions:
- Student issues: Contact Finance Office (ext. XXXX)
- Technical issues: Contact IT Support
- System documentation: See `DISSERTATION_FEE_PROOF_TEST_FLOW.md` and `DISSERTATION_FEE_PROOF_TEST_SUMMARY.md`
