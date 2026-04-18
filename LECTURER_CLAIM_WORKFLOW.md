# LECTURER FINANCE CLAIM WORKFLOW - COMPLETE DOCUMENTATION

**Last Updated:** March 19, 2026

## SYSTEM OVERVIEW

The VLE Lecturer Finance Claim System manages the complete lifecycle of lecturer finance requests, from submission through approval to payment and receipt generation. The system enforces a strict multi-stage approval workflow to ensure financial controls and accountability.

---

## COMPLETE WORKFLOW STAGES

### Stage 1: LECTURER SUBMITS CLAIM
- **Who:** Lecturer
- **Where:** `lecturer/request_finance.php`
- **What Happens:**
  - Lecturer selects courses they taught
  - Enters total hours worked
  - Calculates total amount based on hourly rate (varies by position)
  - Provides digital signature
  - Submits claim
- **Database Updates:**
  - `status` = `'pending'`
  - `odl_approval_status` = `'pending'`
  - `request_date` = current timestamp
  - `submission_date` = current timestamp
- **Next Step:** Goes to ODL Coordinator for review

---

### Stage 2: ODL COORDINATOR REVIEWS
- **Who:** ODL Coordinator
- **Where:** `odl_coordinator/claims_approval.php`
- **Possible Actions:**
  1. **APPROVE:** Marks as ready for Finance processing (no Dean needed)
  2. **REJECT:** Sends back to lecturer with rejection reason
  3. **FORWARD TO DEAN:** Escalates to Dean for additional review
  4. **REQUEST CLARIFICATION:** Returns for revisions
- **Database Updates:**
  - `odl_approval_status` = `'approved'` | `'rejected'` | `'forwarded_to_dean'` | `'returned'`
  - `odl_approved_by` = ODL Coordinator user ID
  - `odl_approved_at` = timestamp of decision
  - `odl_remarks` = notes/comments from coordinator
- **Next Step:** 
  - If **APPROVED**: Goes to Finance Department
  - If **FORWARDED TO DEAN**: Goes to Dean for review
  - If **REJECTED/RETURNED**: Sent back to Lecturer

---

### Stage 3: DEAN APPROVAL (OPTIONAL - Only if forwarded by ODL)
- **Who:** Dean
- **Where:** `dean/claims_approval.php`
- **Conditions:** Only appears if ODL marked as `'forwarded_to_dean'`
- **Possible Actions:**
  1. **APPROVE:** Authorizes Finance to process
  2. **REJECT:** Declines the claim
  3. **REQUEST CHANGES:** Returns to lecturer
- **Database Updates:**
  - `dean_approval_status` = `'approved'` | `'rejected'` | `'returned'`
  - `dean_approved_by` = Dean user ID
  - `dean_approved_at` = timestamp of decision
  - `dean_remarks` = notes/comments from dean
- **Next Step:**
  - If **APPROVED**: Goes to Finance Department
  - If **REJECTED/RETURNED**: Sent back to Lecturer

---

### Stage 4: FINANCE APPROVAL & VALIDATION
- **Who:** Finance Officer
- **Where:** `finance/finance_manage_requests.php`
- **Conditions:** Can only approve if:
  - `odl_approval_status` = `'approved'` AND no Dean review was required, OR
  - `dean_approval_status` = `'approved'`
- **Dashboard Displays:**
  - All claims with approval statuses
  - ODL Status badge
  - Dean Status badge (if applicable)
  - Finance Status badge
  - Workflow progress indicator
- **Finance Actions:**
  1. **APPROVE FOR PAYMENT:** Marks request as approved (ready to pay)
  2. **REJECT:** Declines with remarks
- **Database Updates:**
  - `status` = `'approved'`
  - `finance_approved_at` = timestamp
  - `finance_remarks` = (if rejected) reasons
- **Next Step:** Request moves to payment processing

---

### Stage 5: PAYMENT PROCESSING
- **Who:** Finance Officer
- **Where:** `finance/finance_manage_requests.php` → Pay & Print button
- **Process:**
  1. Clicks "Pay & Print" button on approved request
  2. Confirms payment action
  3. System marks claim as paid
  4. Automatically opens payment receipt in new tab for printing
- **Database Updates:**
  - `status` = `'paid'`
  - `finance_paid_at` = timestamp of payment
  - `response_date` = timestamp
- **Next Step:** Receipt is printed and distributed

---

### Stage 6: RECEIPT PRINTING & DISTRIBUTION
- **Who:** Finance Officer or Lecturer
- **Where:** `finance/print_lecturer_payment.php`
- **Receipt Contains:**
  - Receipt number (LPR-YYYY-[request_id])
  - University header and contact info
  - Lecturer details (name, ID, email, department)
  - Claim details (courses, hours, hourly rate)
  - Total amount paid (in MKW)
  - Payment date
  - Approval signatures/dates
  - Print and digital options
- **Can Be Accessed:**
  - Directly after payment (auto-opens)
  - Anytime from Finance dashboard if status = 'paid'
  - By lecturer from their request history

---

## APPROVAL DECISION TREE

```
┌─ Lecturer Submits Claim
│  ├─ status: pending
│  └─ odl_approval: pending
│
├─ ODL Coordinator Reviews
│  ├─ APPROVE
│  │  └─ odl_approval: approved
│  │     └─ → Finance Approval (next stage)
│  │
│  ├─ FORWARD TO DEAN
│  │  └─ odl_approval: forwarded_to_dean
│  │     └─ → Dean Reviews (next stage)
│  │
│  ├─ REJECT
│  │  └─ odl_approval: rejected
│  │     └─ → END (Lecturer notified)
│  │
│  └─ REQUEST CHANGES
│     └─ odl_approval: returned
│        └─ → Lecturer revises and resubmits
│
├─ (If forwarded to Dean) Dean Reviews
│  ├─ APPROVE
│  │  └─ dean_approval: approved
│  │     └─ → Finance Approval (next stage)
│  │
│  ├─ REJECT
│  │  └─ dean_approval: rejected
│  │     └─ → END (Lecturer notified)
│  │
│  └─ REQUEST CHANGES
│     └─ dean_approval: returned
│        └─ → Lecturer revises and resubmits
│
├─ Finance Approves (if proper approvals in place)
│  ├─ APPROVE FOR PAYMENT
│  │  └─ status: approved
│  │     └─ → Payment Processing (next stage)
│  │
│  └─ REJECT
│     └─ status: rejected
│        └─ → END
│
├─ Finance Marks Paid
│  └─ status: paid
│     └─ Receipt available for printing
│
└─ END OF WORKFLOW
```

---

## DATABASE SCHEMA

### lecturer_finance_requests Table

| Column | Type | Purpose |
|--------|------|---------|
| request_id | INT | Primary key |
| lecturer_id | VARCHAR(50) | Foreign key to lecturers |
| request_date | TIMESTAMP | When claim was submitted |
| submission_date | DATETIME | Submission timestamp |
| status | ENUM | Finance status: pending, approved, rejected, paid |
| odl_approval_status | ENUM | ODL Coordinator status: pending, approved, rejected, returned, forwarded_to_dean |
| odl_approved_by | INT | User ID of approving coordinator |
| odl_approved_at | TIMESTAMP | When ODL approval was made |
| odl_remarks | TEXT | Comments from ODL coordinator |
| dean_approval_status | ENUM | Dean status: pending, approved, rejected, returned |
| dean_approved_by | INT | User ID of dean |
| dean_approved_at | DATETIME | When dean approval was made |
| dean_remarks | TEXT | Comments from dean |
| finance_approved_at | DATETIME | When finance approved |
| finance_remarks | TEXT | Finance comments |
| finance_rejected_at | DATETIME | When finance rejected |
| finance_paid_at | DATETIME | When payment was processed |
| total_amount | DECIMAL | Amount to be paid (MKW) |
| total_hours | DECIMAL | Hours worked |
| hourly_rate | DECIMAL | Rate per hour (MKW) |
| courses_data | JSON | Courses worked on |
| ... | | (other tracking fields) |

---

## ROLE-BASED ACCESS CONTROL

### LECTURER
- **Can:** Submit claims, view own claim history, view own approval status
- **Cannot:** Approve claims, access other lecturers' claims, process payments
- **Key Pages:** `lecturer/request_finance.php`

### ODL COORDINATOR
- **Can:** View all pending claims, approve/reject claims, forward to dean, add remarks
- **Cannot:** Process payments, approve after dean has rejected
- **Key Pages:** `odl_coordinator/claims_approval.php`, `odl_coordinator/print_claim.php`

### DEAN (Optional - Context Dependent)
- **Can:** Review forwarded claims, approve/reject, request clarifications
- **Cannot:** Approve claims not forwarded by ODL, process payments
- **Key Pages:** `dean/claims_approval.php`, `dean/print_claim.php`

### FINANCE
- **Can:** View all approved claims, approve for payment, process payments, print receipts
- **Cannot:** Approve without ODL/Dean approval (enforced in code), override approvals
- **Key Pages:** `finance/finance_manage_requests.php`, `finance/pay_lecturer.php`, `finance/print_lecturer_payment.php`

---

## CRITICAL BUSINESS RULES

1. **Rule: Sequential Approval Required**
   - Finance can ONLY approve if ODL approved it (and dean if forwarded)
   - Bypass attempts are rejected with error message
   - Enforced in: `finance/lecturer_finance_action.php`

2. **Rule: Cannot Pay Unapproved Claims**
   - Payment requires `status = 'approved'`
   - System checks in: `finance/pay_lecturer.php`

3. **Rule: Dean Review Optional but Mandatory if Escalated**
   - ODL can approve directly (skip dean) OR forward to dean
   - If forwarded, dean MUST approve before finance can proceed
   - Enforced in: `dean/claims_approval.php`, `finance/lecturer_finance_action.php`

4. **Rule: One Rejection = End of Workflow**
   - If any level rejects, claim is marked as rejected
   - Lecturer must resubmit as new claim
   - No automatic reprocessing

5. **Rule: All Amounts in MKW**
   - Currency standardized across system
   - Conversion happens on user entry
   - Enforced in: All display pages use `number_format()` with "MKW" prefix

---

## ERROR HANDLING & VALIDATION

### Common Errors & Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| "Request must be approved by ODL first" | Finance tried to approve without ODL approval | Ensure ODL coordinator reviews first |
| "Request is pending Dean approval" | Finance tried to approve after ODL forwarded to dean | Wait for dean approval |
| "Only approved requests can be paid" | Finance tried to pay non-approved request | Approve request first |
| "Lecturer profile not found" | Lecturer making request without linked profile | Admin must create lecturer profile |
| "Status: NULL" in display | Old data from before schema updates | Migrate old claims or ignore |

---

## TESTING THE WORKFLOW

### Manual Test Procedures

1. **Test ODL → Finance (No Dean)**
   - Login as Lecturer: Submit claim
   - Login as ODL Coordinator: APPROVE (not forward to dean)
   - Login as Finance: Should see as ready for approval
   - Finance: APPROVE then PAY
   - Verify receipt prints correctly

2. **Test ODL → Dean → Finance**
   - Login as Lecturer: Submit claim
   - Login as ODL Coordinator: FORWARD TO DEAN
   - Login as Dean: APPROVE
   - Login as Finance: Should see as ready for approval
   - Finance: APPROVE then PAY
   - Verify receipt prints correctly

3. **Test Rejection at ODL Level**
   - Login as Lecturer: Submit claim
   - Login as ODL Coordinator: REJECT with remarks
   - Claim should be marked as rejected
   - Lecturer receives notification
   - Verify lecturer can submit new claim

4. **Test Finance Validation**
   - Attempt to access Finance dashboard without proper approvals
   - Try to pay directly without ODL approval (should fail)
   - Verify error messages are clear

---

## INTEGRATION POINTS

- **Email Notifications:** Sent when status changes (not yet fully implemented)
- **API Endpoints:** `finance/get_lecturer_finance.php` supports AJAX requests
- **PDF Generation:** `finance_request_pdf.php` generates printable claim details
- **Audit Logging:** Finance actions tracked for compliance

---

## MAINTENANCE & MONITORING

- Check orphaned claims (lecturers deleted) quarterly
- Monitor for claims stuck in "pending" for > 30 days
- Review rejected claims for patterns
- Audit finance approvals for policy compliance

---

END OF DOCUMENTATION
