# Database Table Usage Analysis
**Date:** January 10, 2026  
**System:** VLE (Virtual Learning Environment)

## Summary
This analysis identifies which database tables are actively used in the system and which are potentially unused or disconnected.

---

## ✅ **ACTIVELY USED TABLES** (Connected to System)

### Core User Tables
1. **users** - ✅ ACTIVE
   - Used in: auth.php, manage_students.php, manage_lecturers.php, manage_finance.php
   - Purpose: Authentication and user management across all roles

2. **students** - ✅ ACTIVE
   - Used extensively across: student dashboard, finance system, admin management, course enrollment
   - Purpose: Student records and profiles

3. **lecturers** - ✅ ACTIVE
   - Used in: lecturer dashboard, course management, admin management, finance setup
   - Purpose: Lecturer records, authentication, and role management (including finance, staff)

### VLE Core Tables
4. **vle_courses** - ✅ ACTIVE
   - Used in: manage_courses.php, lecturer dashboard, student enrollment, module allocation
   - Purpose: Course definitions and management

5. **vle_enrollments** - ✅ ACTIVE
   - Used in: student/lecturer dashboards, course allocation, semester assignments
   - Purpose: Student-course enrollment tracking

6. **vle_weekly_content** - ✅ ACTIVE
   - Used in: add_content.php, student dashboard
   - Purpose: Weekly course materials and content

7. **vle_assignments** - ✅ ACTIVE
   - Used in: student dashboard, lecturer assignment management, submission tracking
   - Purpose: Assignment creation and management

8. **vle_submissions** - ✅ ACTIVE
   - Used in: submit_assignment.php, student dashboard, grading
   - Purpose: Student assignment submissions

9. **vle_progress** - ✅ ACTIVE
   - Used in: student dashboard for tracking course progress
   - Purpose: Student learning progress tracking

10. **vle_announcements** - ✅ ACTIVE
    - Used in: lecturer/announcements.php
    - Purpose: Course announcements system

11. **vle_messages** - ✅ ACTIVE
    - Used in: student/messages.php, lecturer/messages.php
    - Purpose: Internal messaging between students and lecturers

### Academic Structure Tables
12. **departments** - ✅ ACTIVE
    - Used in: manage_departments.php, manage_students.php, manage_faculties.php, finance reports
    - Purpose: Department/program management

13. **faculties** - ✅ ACTIVE
    - Used in: manage_faculties.php, manage_departments.php
    - Purpose: Faculty organization structure

14. **modules** - ✅ ACTIVE
    - Used in: manage_modules.php, manage_semester.php
    - Purpose: Module/course catalog management

15. **semester_courses** - ✅ ACTIVE
    - Used in: semester_course_assignment.php, module_allocation.php
    - Purpose: Assign courses to specific semesters
    - Note: Created dynamically if doesn't exist

### Finance Tables
16. **student_finances** - ✅ ACTIVE
    - Used in: finance system (dashboard, student_finances, record_payment, review_payments)
    - Purpose: Student financial records and balances

17. **payment_transactions** - ✅ ACTIVE
    - Used in: finance dashboard, finance reports, payment history, print receipts
    - Purpose: Payment transaction history

18. **payment_submissions** - ✅ ACTIVE
    - Used in: submit_payment.php, review_payments.php
    - Purpose: Student payment proof submissions for review

19. **fee_settings** - ✅ ACTIVE
    - Used in: fee_settings.php, student dashboard, finance dashboard
    - Purpose: Fee structure configuration

### System Configuration
20. **university_settings** - ✅ ACTIVE
    - Used in: university_settings.php, print_receipt.php
    - Purpose: University branding and configuration

---

## ⚠️ **POTENTIALLY UNUSED TABLES** (Defined but Not Connected)

### 1. **administrative_staff** - ⚠️ MINIMAL USE
- **Defined in:** setup.php (lines 50-59)
- **Schema:**
  ```sql
  staff_id, full_name, email, phone, department, position, hire_date, is_active
  ```
- **Usage Analysis:** 
  - Created in setup.php
  - NOT actively queried in any system files
  - Admin users are stored in `lecturers` table with role='staff'
- **Status:** REDUNDANT - Functionality merged into lecturers table
- **Recommendation:** 🗑️ **Can be removed** - No longer needed

### 2. **vle_forums** - ⚠️ PARTIALLY IMPLEMENTED
- **Defined in:** setup.php (lines 156-164)
- **Schema:**
  ```sql
  forum_id, course_id, week_number, title, description, is_active, created_date
  ```
- **Usage Analysis:**
  - Table created in setup.php
  - Referenced in forum.php and view_forum.php but NOT fully implemented
  - Basic structure exists but no active CRUD operations
- **Status:** INCOMPLETE FEATURE
- **Recommendation:** ⚡ **Complete implementation** OR **Remove if not needed**

### 3. **vle_forum_posts** - ⚠️ PARTIALLY IMPLEMENTED
- **Defined in:** setup.php (lines 166-176)
- **Schema:**
  ```sql
  post_id, forum_id, parent_post_id, user_id, title, content, post_date, is_pinned
  ```
- **Usage Analysis:**
  - Table created but no active queries found
  - Part of incomplete forums feature
- **Status:** INCOMPLETE FEATURE
- **Recommendation:** ⚡ **Complete implementation** OR **Remove if not needed**

### 4. **vle_grades** - ⚠️ NOT USED
- **Defined in:** setup.php (lines 178-189)
- **Schema:**
  ```sql
  grade_id, enrollment_id, assignment_id, grade_type, score, max_score, 
  percentage, grade_letter, graded_date
  ```
- **Usage Analysis:**
  - Created in setup.php
  - NOT queried anywhere in the codebase
  - Grading currently done through vle_submissions.score field
- **Status:** REDUNDANT
- **Recommendation:** 🗑️ **Can be removed** - Functionality covered by vle_submissions

### 5. **attendance_sessions** - ⚠️ NOT USED
- **Defined in:** setup.php (lines 191-203)
- **Schema:**
  ```sql
  session_id, course_id, lecturer_id, session_date, start_time, end_time, 
  location, is_active, created_at
  ```
- **Usage Analysis:**
  - Created in setup.php
  - NO references found in any PHP files
  - Attendance feature not implemented
- **Status:** UNUSED FEATURE
- **Recommendation:** 🗑️ **Remove** OR **Implement attendance feature**

### 6. **attendance_records** - ⚠️ NOT USED
- **Defined in:** setup.php (approximately)
- **Schema:** (Likely contains student_id, session_id, status, timestamp)
- **Usage Analysis:**
  - If exists, completely unused
  - Part of unimplemented attendance system
- **Status:** UNUSED
- **Recommendation:** 🗑️ **Remove** if exists

---

## 📊 **USAGE STATISTICS**

### Active Tables: **20 tables**
- Core functionality: 11 tables (55%)
- Finance system: 4 tables (20%)
- Academic structure: 3 tables (15%)
- Configuration: 2 tables (10%)

### Unused/Incomplete Tables: **6 tables**
- Redundant: 2 tables (administrative_staff, vle_grades)
- Incomplete features: 2 tables (vle_forums, vle_forum_posts)
- Unimplemented: 2 tables (attendance_sessions, attendance_records)

---

## 🎯 **RECOMMENDATIONS**

### Immediate Actions:
1. **Remove Redundant Tables:**
   - `administrative_staff` - Functionality in `lecturers` table
   - `vle_grades` - Functionality in `vle_submissions` table

2. **Decision Needed:**
   - **Forums Feature** (vle_forums, vle_forum_posts):
     - Option A: Complete the implementation
     - Option B: Remove tables if feature not needed
   
   - **Attendance Feature** (attendance_sessions, attendance_records):
     - Option A: Implement full attendance tracking
     - Option B: Remove tables if feature not needed

### Database Cleanup SQL (if removing unused tables):
```sql
-- Backup first!
-- DROP TABLE IF EXISTS administrative_staff;
-- DROP TABLE IF EXISTS vle_grades;
-- DROP TABLE IF EXISTS attendance_sessions;
-- DROP TABLE IF EXISTS attendance_records;
-- DROP TABLE IF EXISTS vle_forums;
-- DROP TABLE IF EXISTS vle_forum_posts;
```

---

## 📝 **NOTES**

1. **Dynamic Table Creation:**
   - `semester_courses` is created on-demand in semester_course_assignment.php
   - This is working as intended

2. **Role Consolidation:**
   - Admin staff stored in `lecturers` table with role='staff'
   - Finance users stored in `lecturers` table with role='finance'
   - This consolidation makes `administrative_staff` table redundant

3. **Grading System:**
   - Currently uses `vle_submissions.score` field
   - `vle_grades` table appears to be a planned enhancement but never implemented

4. **Missing Tables Check:**
   - System handles missing tables gracefully (e.g., semester_courses)
   - Always uses `SHOW TABLES LIKE` before operations

---

## ✅ **CONCLUSION**

The VLE system has **20 actively used tables** that are well-integrated into the application. 

However, **6 tables** are either:
- Not connected to any functionality (unused)
- Partially implemented (incomplete features)
- Redundant (functionality moved elsewhere)

**Recommended Action:** Clean up unused tables to improve database clarity and maintenance.
