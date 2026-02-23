# Quick Start Guide: Fee Management System

## Initial Setup (One-Time)

### 1. Run Database Migration
Open your browser and go to:
```
http://localhost/vle_system/setup_fee_system.php
```

Expected output:
```
✅ Fee settings table created successfully
✅ Program type column added to students table
✅ Application fee columns added to student_finances table
✅ Expected amount columns added successfully
✅ Default fee settings inserted
```

### 2. Set Student Program Types
Each student needs a program type assigned. You can either:

**Option A: Update via SQL**
```sql
UPDATE students SET program_type = 'degree' WHERE student_id = 'STU001';
UPDATE students SET program_type = 'professional' WHERE student_id = 'STU002';
UPDATE students SET program_type = 'masters' WHERE student_id = 'STU003';
UPDATE students SET program_type = 'doctorate' WHERE student_id = 'STU004';
```

**Option B: Update via Admin Panel**
(Future enhancement - add program type field to student edit form)

## Daily Operations

### Recording a Payment (Finance Officer)

1. **Navigate:** Finance Dashboard → Record Payment
2. **Select Student:** Choose from dropdown (shows student ID, name, and program)
3. **View Status:** System automatically shows:
   - Application Fee: K paid / K5,500
   - Registration Fee: K paid / K39,500
   - Tuition Fee: K paid / K (varies by program)
   - Installment progress (4 installments)
   - Current access level and weeks
4. **Enter Details:**
   - Amount (K)
   - Payment Method
   - Reference Number (optional)
   - Payment Date (defaults to today)
   - Notes (optional)
5. **Submit:** Click "Record Payment"
6. **Print Receipt:** Choose 58mm or A4 format

**Payment Distribution Happens Automatically:**
- Application Fee gets paid first
- Then Registration Fee
- Then Installments 1-4 in order
- Access level updates automatically

### Viewing Student Finances

1. **Finance Dashboard → Student Finances**
2. **Search/Select Student**
3. **View Complete Breakdown:**
   - All fees paid and pending
   - Payment history
   - Access level
   - Outstanding balance

### Managing Fee Amounts (Administrator)

1. **Admin Dashboard → Fee Settings**
2. **Update Any Fee Amount:**
   - Application Fee (default: K5,500)
   - Registration Fee (default: K39,500)
   - Tuition - Degree (default: K500,000)
   - Tuition - Professional (default: K200,000)
   - Tuition - Masters (default: K1,100,000)
   - Tuition - Doctorate (default: K2,200,000)
   - Supplementary Exam Fee (default: K50,000)
   - Deferred Exam Fee (default: K50,000)
3. **View Summary:** See total fees for each program type
4. **Save Changes:** Updates apply immediately

## Understanding Program Types

### Degree Programs (K500,000 tuition)
- Undergraduate degree programs
- 4-year courses
- Each installment: K125,000

### Professional Courses (K200,000 tuition)
- Certificate programs
- Diploma courses
- Short professional courses
- Each installment: K50,000

### Masters Programs (K1,100,000 tuition)
- Postgraduate masters degrees
- Each installment: K275,000

### Doctorate Programs (K2,200,000 tuition)
- PhD programs
- Each installment: K550,000

## Payment Scenarios

### Scenario 1: New Student (Degree Program)
**Student Status:** No payments yet
**Expected Total:** K545,000

**Payment 1: K50,000**
Distribution:
- Application Fee: K5,500 ✅ PAID
- Registration: K39,500 ✅ PAID
- Installment 1: K5,000 (K120,000 remaining)
- Access Level: 0% (0 weeks)

**Payment 2: K120,000**
Distribution:
- Installment 1: K120,000 ✅ PAID (now K125,000 total)
- Access Level: 25% (4 weeks)

**Payment 3: K125,000**
Distribution:
- Installment 2: K125,000 ✅ PAID
- Access Level: 50% (8 weeks)

**Payment 4: K125,000**
Distribution:
- Installment 3: K125,000 ✅ PAID
- Access Level: 75% (12 weeks)

**Payment 5: K125,000**
Distribution:
- Installment 4: K125,000 ✅ PAID
- Access Level: 100% (16 weeks) - FULL ACCESS

### Scenario 2: Large Initial Payment
**Student:** Professional Course
**Expected Total:** K245,000

**Payment: K245,000** (pays everything at once)
Distribution:
- Application Fee: K5,500 ✅ PAID
- Registration: K39,500 ✅ PAID
- Installment 1: K50,000 ✅ PAID
- Installment 2: K50,000 ✅ PAID
- Installment 3: K50,000 ✅ PAID
- Installment 4: K50,000 ✅ PAID
- Access Level: 100% (16 weeks) - FULL ACCESS

### Scenario 3: Partial Payments
**Student:** Masters Program
**Expected Total:** K1,145,000

**Payment 1: K100,000**
Distribution:
- Application Fee: K5,500 ✅ PAID
- Registration: K39,500 ✅ PAID
- Installment 1: K55,000 (K220,000 remaining)
- Access Level: 0% (0 weeks) - needs to complete installment 1

**Payment 2: K220,000**
Distribution:
- Installment 1: K220,000 (completes it - now K275,000 total)
- Access Level: 25% (4 weeks)

## Access Level System

| Tuition Paid | Access Level | Content Access | VLE Features |
|--------------|--------------|----------------|--------------|
| 0% | 0% | 0 weeks | Limited - View only |
| 25% (1 inst) | 25% | 4 weeks | Basic access |
| 50% (2 inst) | 50% | 8 weeks | Standard access |
| 75% (3 inst) | 75% | 12 weeks | Extended access |
| 100% (4 inst) | 100% | 16 weeks | Full access |

**Note:** Access is based on TUITION paid, not total amount. Application and Registration fees don't count toward access level.

## Dashboard Statistics

**Finance Dashboard Shows:**
1. **Total Students:** Count of enrolled students
2. **Expected Revenue:** Total all students should pay
3. **Total Collected:** Actual payments received
4. **Outstanding Balance:** Amount still owed
5. **Application Fee:** K collected / K expected (%)
6. **Registration Fee:** K collected / K expected (%)
7. **Tuition Collected:** Total tuition payments

**Formula Examples:**
- Expected Revenue = Sum of (Application + Registration + Tuition) per student
- For 10 Degree students: 10 × K545,000 = K5,450,000
- For 5 Professional students: 5 × K245,000 = K1,225,000
- Total Expected: K6,675,000

## Troubleshooting

### Problem: Payment doesn't distribute correctly
**Solution:** Check that student has a `program_type` set
```sql
SELECT student_id, full_name, program_type FROM students WHERE student_id = 'STU001';
```

### Problem: Wrong tuition amount showing
**Solution:** Verify fee_settings table has correct values
```sql
SELECT * FROM fee_settings WHERE id = 1;
```

### Problem: Access level not updating
**Solution:** System calculates based on tuition paid vs total tuition
- Check `tuition_paid` value in `student_finances`
- Check `expected_tuition` matches program type
- Access updates automatically when payment is recorded

### Problem: Expected total is wrong
**Solution:** Run update query to recalculate
```sql
UPDATE student_finances sf
JOIN students s ON sf.student_id = s.student_id
JOIN fee_settings fs ON fs.id = 1
SET 
    sf.expected_tuition = CASE s.program_type
        WHEN 'degree' THEN fs.tuition_degree
        WHEN 'professional' THEN fs.tuition_professional
        WHEN 'masters' THEN fs.tuition_masters
        WHEN 'doctorate' THEN fs.tuition_doctorate
    END,
    sf.expected_total = fs.application_fee + fs.registration_fee + 
        CASE s.program_type
            WHEN 'degree' THEN fs.tuition_degree
            WHEN 'professional' THEN fs.tuition_professional
            WHEN 'masters' THEN fs.tuition_masters
            WHEN 'doctorate' THEN fs.tuition_doctorate
        END;
```

## Tips & Best Practices

✅ **Always run setup_fee_system.php first** before using the system
✅ **Set program_type for all students** before recording payments
✅ **Use the Finance Dashboard** to monitor overall revenue
✅ **Print receipts immediately** after recording payments
✅ **Update fee amounts at start of academic year** if needed
✅ **Check access levels** to ensure students have correct access
✅ **Keep reference numbers** for bank transactions
✅ **Add notes** to payments for important details

## Common Questions

**Q: Can I change fees mid-year?**
A: Yes, but changes only affect new calculations. Existing payment records stay the same.

**Q: What if a student changes program type?**
A: Update their `program_type` and run the expected_total update query above.

**Q: Can I have different registration fees per program?**
A: Currently no - same registration for all. This can be added if needed.

**Q: Do access levels expire?**
A: Currently no - once granted, access remains. Time limits can be added.

**Q: Can I give discounts?**
A: Not built-in yet. You can manually adjust by recording a payment with notes explaining the discount.

**Q: What about refunds?**
A: Not currently supported. Would need additional development.

---

**Need Help?** Check the full documentation in [FEE_SYSTEM_COMPLETE.md](FEE_SYSTEM_COMPLETE.md)
