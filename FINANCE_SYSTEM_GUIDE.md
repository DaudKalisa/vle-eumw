# Finance System Setup Guide

## Overview
Complete university financial management system with payment tracking, installment management, and content access control based on payment status.

## Financial Structure

### Total Fees per Student: K539,500
- **Registration Fee:** K39,500 (One-time)
- **Tuition Fee:** K500,000 (Divided into 4 equal installments)
  - 1st Installment: K125,000
  - 2nd Installment: K125,000
  - 3rd Installment: K125,000
  - 4th Installment: K125,000

### Content Access Control
Students get access to course materials based on payment percentage:
- **0% Paid:** No access to course materials
- **25% Paid (K125,000):** Access to Weeks 1-4
- **50% Paid (K250,000):** Access to Weeks 1-8
- **75% Paid (K375,000):** Access to Weeks 1-12
- **100% Paid (K500,000):** Full access to all 16 weeks

## Student ID Format
Auto-generated format: `PROGRAM/CAMPUS/YEAR/ENTRY_TYPE/SEQUENCE`

**Example:** `CS/MZ/2026/NE/0001`

### Components:
- **PROGRAM:** CS (Computer Science), IT (Information Technology), BBA (Business), EDU (Education), etc.
- **CAMPUS:** MZ (Main Campus), LL (Lilongwe), BT (Blantyre), etc.
- **YEAR:** Year of registration (e.g., 2026)
- **ENTRY_TYPE:**
  - ME: Mature Entry
  - NE: Normal Entry
  - ODL: Open Distance Learning
  - PC: Professional Course
- **SEQUENCE:** 4-digit sequential number (0001, 0002, etc.)

## Installation Steps

### 1. Run Setup Script
Navigate to: `http://localhost/vle_system/setup_finance_system.php`

This will:
- Add new fields to students table (entry_type, year_of_registration)
- Create student_finances table
- Create payment_transactions table
- Create finance user account
- Generate 20 sample students with varying payment statuses

### 2. Database Tables Created

#### student_finances
Tracks all financial records for each student:
- Registration fee payment and date
- 4 installments with amounts and dates
- Total paid and balance
- Payment percentage
- Content access weeks

#### payment_transactions
Logs all payment activities:
- Transaction ID (auto-increment)
- Student ID
- Payment type (registration/installment_1/installment_2/etc.)
- Amount
- Payment method (cash/bank_transfer/mobile_money/cheque/card)
- Reference number
- Payment date
- Recorded by (finance officer ID)
- Notes

### 3. Finance User Account

**Login Credentials:**
- Email: `finance@university.edu`
- Password: `finance123`
- Role: finance

## Finance Dashboard Features

### 1. Main Dashboard (`finance/dashboard.php`)
- Total students count
- Expected revenue
- Total collected
- Outstanding balance
- Payment distribution chart
- Recent transactions
- Quick action buttons

### 2. Student Financial Accounts (`finance/student_finances.php`)
- View all student accounts
- Filter by payment status
- Search by ID, name, or email
- See payment percentage and access weeks
- Quick actions (view details, record payment, view history)

### 3. Record Payment (`finance/record_payment.php`)
- Select student from dropdown
- Choose payment type
- Enter amount
- Select payment method
- Add reference number
- Add notes
- Real-time balance updates
- Auto-calculate content access weeks

### 4. View Student Details (`finance/view_student_finance.php`)
- Complete student information
- Financial summary
- Detailed payment breakdown
- Full payment history
- Print statement option

### 5. Finance Reports (`finance/finance_reports.php`)
- Date range filtering
- Revenue summary
- Revenue by payment type
- Defaulters list (0% payment)
- Transaction history
- Print reports

## Sample Data

The setup script creates 20 sample students with:
- Various programs (CS, IT, BBA, EDU)
- Different campuses (MZ, LL, BT)
- Different entry types (NE, ME, ODL, PC)
- Different payment statuses:
  - 4 students: 0% paid (No access)
  - 4 students: 25% paid (4 weeks access)
  - 4 students: 50% paid (8 weeks access)
  - 4 students: 75% paid (12 weeks access)
  - 4 students: 100% paid (Full access)

## Payment Workflow

### Step 1: Student Registration
- Admin/Staff creates student account
- System auto-generates Student ID
- Finance record created with K539,500 balance

### Step 2: Record Payment
- Finance officer logs in
- Selects student
- Records payment (registration or installment)
- System automatically:
  - Updates student_finances table
  - Calculates payment percentage
  - Updates content access weeks
  - Logs transaction in payment_transactions

### Step 3: Content Access Control
- Student logs into their dashboard
- System checks payment_percentage
- Shows/hides course materials based on access weeks
- Displays payment status and locked content

### Step 4: Reports & Analytics
- Finance officer generates reports
- Views defaulters
- Tracks revenue by date/type
- Exports for accounting

## Access Control Implementation

In student dashboard, check payment before showing content:

```php
// Get student's finance record
$finance_query = "SELECT content_access_weeks, payment_percentage 
                  FROM student_finances 
                  WHERE student_id = ?";
// Compare with content week number
if ($content_week <= $student_access_weeks) {
    // Show content
} else {
    // Show locked message with payment prompt
}
```

## Permissions

### Finance Officer Can:
- View all student financial accounts
- Record payments
- View payment history
- Generate reports
- View defaulters list
- Export financial data

### Finance Officer Cannot:
- Edit student academic records
- Modify courses
- Access lecturer features
- Delete payment transactions (audit trail)

## Security Features

1. **Transaction Logging:** All payments logged with timestamp and recorded_by
2. **No Deletion:** Payments cannot be deleted, only new transactions added
3. **Role-Based Access:** Only finance and staff roles can access finance features
4. **Audit Trail:** Complete history of who recorded what payment when
5. **Reference Numbers:** Track external transaction references

## Reporting Features

### Available Reports:
1. Revenue by date range
2. Revenue by payment type
3. Defaulters list (0% payment)
4. Transaction history
5. Student account statements
6. Payment collection trends

### Export Options:
- Print reports
- Filter by date range
- Search specific students
- Filter by payment status

## Testing the System

1. **Login as Finance Officer:**
   - Email: finance@university.edu
   - Password: finance123

2. **View Sample Students:**
   - Go to "View All Student Accounts"
   - Filter by payment status
   - See students with different access levels

3. **Record a Payment:**
   - Click "Record New Payment"
   - Select a student with 0% or partial payment
   - Record installment payment
   - Verify balance update
   - Check access weeks changed

4. **View Reports:**
   - Go to "View Reports"
   - Set date range
   - See revenue summary
   - Check defaulters list

## Troubleshooting

### Issue: Finance user cannot login
**Solution:** Ensure setup_finance_system.php was run successfully

### Issue: Students table missing columns
**Solution:** Run setup script which adds entry_type and year_of_registration

### Issue: Payment not updating
**Solution:** Check student_finances table exists and has correct student_id

### Issue: Wrong access weeks calculated
**Solution:** Check payment_percentage calculation in record_payment.php

## Future Enhancements

1. **Email Notifications:**
   - Payment receipts
   - Payment reminders
   - Balance alerts

2. **Payment Plans:**
   - Custom installment schedules
   - Early payment discounts
   - Late payment penalties

3. **Bulk Operations:**
   - Import payments from CSV
   - Bulk payment recording
   - Mass email to defaulters

4. **Advanced Reports:**
   - Revenue projections
   - Payment trends analysis
   - Program-wise revenue
   - Campus-wise collection

5. **Mobile Integration:**
   - Mobile money API integration
   - SMS payment confirmations
   - USSD payment recording

## Database Schema

### student_finances Table
```sql
finance_id (PK, AUTO_INCREMENT)
student_id (FK → students.student_id)
registration_fee (DECIMAL: 39500.00)
registration_paid (DECIMAL: 0.00)
registration_paid_date (DATE NULL)
tuition_fee (DECIMAL: 500000.00)
tuition_paid (DECIMAL: 0.00)
installment_1 (DECIMAL: 0.00)
installment_1_date (DATE NULL)
installment_2 (DECIMAL: 0.00)
installment_2_date (DATE NULL)
installment_3 (DECIMAL: 0.00)
installment_3_date (DATE NULL)
installment_4 (DECIMAL: 0.00)
installment_4_date (DATE NULL)
total_paid (DECIMAL: 0.00)
balance (DECIMAL: 539500.00)
payment_percentage (INT: 0-100)
content_access_weeks (INT: 0/4/8/12/16)
created_at (TIMESTAMP)
updated_at (TIMESTAMP)
```

### payment_transactions Table
```sql
transaction_id (PK, AUTO_INCREMENT)
student_id (FK → students.student_id)
payment_type (ENUM: registration/installment_1/2/3/4)
amount (DECIMAL)
payment_method (ENUM: cash/bank_transfer/mobile_money/cheque/card)
reference_number (VARCHAR NULL)
payment_date (DATE)
recorded_by (VARCHAR)
notes (TEXT NULL)
created_at (TIMESTAMP)
```

## Support

For issues or questions:
1. Check this documentation
2. Review setup_finance_system.php for database structure
3. Check finance/*.php files for implementation
4. Verify database tables created correctly
5. Check user permissions and roles

---

**System Version:** 1.0  
**Last Updated:** <?php echo date('F d, Y'); ?>  
**Developed for:** University VLE System
