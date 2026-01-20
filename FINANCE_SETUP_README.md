# 🎓 VLE Finance System - Quick Setup

## 🚀 Quick Start (3 Steps)

### Step 1: Run Setup Script
Open your browser and navigate to:
```
http://localhost/vle_system/setup_finance_system.php
```
✅ This creates all database tables and sample data

### Step 2: Login as Finance Officer
```
Email: finance@university.edu
Password: finance123
```
🔐 You'll be redirected to the finance dashboard automatically

### Step 3: Explore Features
- **Dashboard:** Overview of all finances
- **Student Accounts:** View all student payment records
- **Record Payment:** Add new payment transactions
- **Reports:** Generate financial reports

---

## 💰 Financial Structure

| Fee Type | Amount | Details |
|----------|--------|---------|
| Registration | K39,500 | One-time fee |
| Tuition (Total) | K500,000 | Paid in 4 installments |
| - 1st Installment | K125,000 | 25% of tuition |
| - 2nd Installment | K125,000 | 50% of tuition |
| - 3rd Installment | K125,000 | 75% of tuition |
| - 4th Installment | K125,000 | 100% of tuition |
| **TOTAL** | **K539,500** | Per student/semester |

## 🔓 Content Access Levels

| Payment % | Amount Paid | Access Granted |
|-----------|-------------|----------------|
| 0% | K0 | ❌ No access |
| 25% | K125,000 | ✅ Weeks 1-4 |
| 50% | K250,000 | ✅ Weeks 1-8 |
| 75% | K375,000 | ✅ Weeks 1-12 |
| 100% | K500,000 | ✅ All 16 weeks |

## 🆔 Student ID Format

**Format:** `PROGRAM/CAMPUS/YEAR/ENTRY/####`

**Example:** `CS/MZ/2026/NE/0001`

- **PROGRAM:** CS, IT, BBA, EDU, etc.
- **CAMPUS:** MZ, LL, BT, etc.
- **YEAR:** 2026, 2027, etc.
- **ENTRY:** NE (Normal), ME (Mature), ODL (Distance), PC (Professional)
- **####:** Sequential number (0001, 0002, etc.)

## 📊 Finance Dashboard Features

### 1️⃣ Main Dashboard
- 📈 Total revenue statistics
- 📊 Payment distribution chart
- ⏰ Recent transactions
- 🚀 Quick action buttons

### 2️⃣ Student Accounts
- 👥 View all student finances
- 🔍 Search and filter options
- 💳 Payment status indicators
- 🔓 Access level display

### 3️⃣ Record Payment
- ➕ Add new payments
- 💰 Multiple payment types
- 📝 Transaction notes
- ✅ Auto-calculate access

### 4️⃣ Finance Reports
- 📅 Date range filtering
- 💵 Revenue summaries
- ⚠️ Defaulters list
- 🖨️ Print reports

## 📦 Sample Data

Setup creates **20 sample students** with:
- ✅ 4 fully paid (100%)
- ⏳ 4 at 75% paid
- ⏳ 4 at 50% paid
- ⏳ 4 at 25% paid
- ❌ 4 not paid (0%)

## 🗂️ Files Created

```
finance/
├── dashboard.php              # Main finance dashboard
├── student_finances.php       # View all student accounts
├── record_payment.php         # Record new payments
├── view_student_finance.php   # Individual student details
├── finance_reports.php        # Reports and analytics
└── get_student_finance.php    # AJAX endpoint

setup_finance_system.php       # Database setup script
FINANCE_SYSTEM_GUIDE.md        # Complete documentation
```

## 🗄️ Database Tables Created

1. **student_finances** - Stores all financial records
2. **payment_transactions** - Logs all payment activities
3. **students** - Updated with entry_type and year_of_registration

## ✅ Testing Checklist

- [ ] Run setup script successfully
- [ ] Login as finance@university.edu
- [ ] View student accounts
- [ ] Filter by payment status
- [ ] Record a test payment
- [ ] View payment history
- [ ] Generate finance report
- [ ] Check defaulters list

## 🔐 User Roles

| Role | Access |
|------|--------|
| **finance** | Full finance system access |
| **staff** | Full finance system access |
| **student** | View own payment status only |
| **lecturer** | No finance access |

## 🎯 Workflow Example

1. **Admin** creates new student → Student ID auto-generated
2. **System** creates finance record (K539,500 balance)
3. **Student** tries to access course materials → Blocked (0% paid)
4. **Student** pays registration → K39,500
5. **Finance** records payment → Still 0% (registration not counted in tuition %)
6. **Student** pays 1st installment → K125,000
7. **Finance** records payment → 25% paid, 4 weeks access
8. **Student** can now view Weeks 1-4 materials
9. **Process repeats** for remaining installments

## 📞 Quick Help

### Can't login as finance?
→ Make sure setup script ran successfully

### Students missing entry_type field?
→ Run setup script again (safe to re-run)

### Payment not updating balance?
→ Check student_finances table exists

### Access weeks not calculating?
→ Verify payment type is correct (use installment_1, not registration for tuition)

## 📚 Full Documentation

See **FINANCE_SYSTEM_GUIDE.md** for complete documentation including:
- Detailed database schema
- Security features
- API endpoints
- Troubleshooting guide
- Future enhancements

---

**Ready to use!** 🎉

Login at: `http://localhost/vle_system/login.php`
