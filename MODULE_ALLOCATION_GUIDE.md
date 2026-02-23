# Module/Course Allocation to Students - Complete Guide

## Overview
The VLE system provides **THREE methods** for allocating modules/courses to students:

1. **Student Self-Service Registration** - Students request courses, admin approves (NEW!)
2. **Individual Student Allocation** - Admin allocates courses one student at a time
3. **Bulk Student Allocation** - Admin allocates a course to multiple students at once

All methods require courses to be assigned to semesters first.

---

## 🆕 NEW FEATURE: Student Self-Service Registration with Admin Approval

Students can now browse available courses and submit registration requests. Administrators review and approve/reject these requests.

### For Students:

#### How to Register for Courses:
1. Login as **Student**
2. Navigate to **Dashboard**
3. Click on **"Register Courses"** in the navigation menu
4. Browse available courses for your program and year
5. Click **"Request Registration"** on desired courses
6. Wait for administrator approval

#### Student Interface Features:
- **My Registration Requests Table**:
  - View all your submitted requests
  - See request status: Pending (⏳), Approved (✅), Rejected (❌)
  - View admin notes if request was rejected
  - Track request dates

- **Available Courses Display**:
  - Grouped by semester
  - Shows only courses matching your program and year
  - Color-coded status:
    - 🔵 **Blue border** = Available to request
    - 🟢 **Green border** = Already enrolled
    - 🟡 **Yellow border** = Request pending approval
  - Course details: Code, Name, Lecturer, Credits

- **Automatic Validation**:
  - ✓ Can only request courses for your program
  - ✓ Can only request courses for your year level
  - ✓ Cannot request same course twice
  - ✓ Maximum 7 courses per semester (including pending)
  - ✓ Cannot request if already enrolled

#### Student Workflow:
1. **Browse** → View available semester courses
2. **Request** → Click "Request Registration" button
3. **Wait** → Request shows as "Pending"
4. **Notification** → Check status (Approved/Rejected)
5. **Access** → If approved, access course content

---

### For Administrators:

#### How to Approve/Reject Registrations:
1. Login as **Administrator/Staff**
2. Navigate to **Admin Dashboard**
3. Click on **"Registration Approvals"** in navigation
4. Review pending requests with full student and course details
5. Approve or reject individual requests (or use bulk approval)

#### Admin Interface Features:

**Statistics Dashboard**:
- Total Pending Requests (Yellow)
- Total Approved Requests (Green)  
- Total Rejected Requests (Red)

**Filters**:
- Status: All / Pending / Approved / Rejected
- Program: Filter by student program
- Semester: Filter by requested semester
- Apply filters to narrow down requests

**Request Cards Display**:
Each request shows:
- ✓ **Student Information**: Name, ID, Program, Year, Semester
- ✓ **Course Information**: Code, Name, Lecturer, Credits, Semester
- ✓ **Compatibility Check**:
  - 🟢 Green badge = Program & Year match (recommended)
  - 🟡 Yellow badge = Program or Year mismatch (warning)
- ✓ **Request Date**: When student submitted request
- ✓ **Current Status**: Pending/Approved/Rejected
- ✓ **Admin Notes**: Previous rejection reasons (if any)

**Individual Actions**:
- **Approve Button** (Green):
  - Instantly enrolls student in course
  - Updates request status to "Approved"
  - Adds to vle_enrollments table
  
- **Reject Button** (Red):
  - Opens modal to enter rejection reason
  - Updates request status to "Rejected"
  - Student can see admin notes

**Bulk Approval**:
- Select multiple pending requests using checkboxes
- Click "Select All" to select all visible requests
- Click "Deselect All" to clear selection
- Click "Approve Selected" to process all at once
- System automatically:
  - Skips already enrolled students
  - Validates 7-course limit
  - Checks program/year compatibility
  - Shows summary: "Approved X, Skipped Y"

#### Automatic Validations During Approval:
When admin approves a request, system checks:
1. ✓ Student not already enrolled
2. ✓ Student hasn't reached 7-course limit
3. ✓ Course still exists and is active
4. ✓ Transaction safety (rollback on error)

If validation fails:
- Request automatically rejected
- Admin notes added (e.g., "Already enrolled", "7-course limit reached")
- Student notified via request status

#### Admin Workflow:
1. **Review** → Check pending requests
2. **Filter** → Use filters to find specific requests
3. **Validate** → Review compatibility badges
4. **Decide** → Approve or reject with optional notes
5. **Bulk Process** → Use bulk approval for multiple requests

---

## STEP 1: Assign Courses to Semesters (REQUIRED FIRST STEP)

Before you can allocate any courses to students, you must first assign courses to semesters.

### How to Access:
1. Login as **Administrator/Staff**
2. Navigate to **Admin Dashboard**
3. Click on **"Semester Course Assignment"** (or access directly via `admin/semester_course_assignment.php`)

### How to Assign Courses:
1. Click the **"Assign Courses to Semester"** button
2. In the modal dialog:
   - **Select Semester**: Choose "Semester 1", "Semester 2", or "Summer Session"
   - **Select Academic Year**: Choose year (e.g., 2024, 2025, 2026)
   - **Filter Courses** (optional): Use filters for:
     - Program of Study
     - Year of Study
     - Search by course name/code
   - **Select Courses**: Check the boxes for courses you want to assign
   - Click **"Select All"** to select all filtered courses
   - Click **"Deselect All"** to clear selection
3. Click **"Assign Selected Courses"** button
4. Courses will now appear in the "Current Semester Course Assignments" table

### Managing Semester Assignments:
- **View All Assignments**: See all courses assigned to different semesters
- **Deactivate**: Click "Deactivate" to temporarily remove course from semester (keeps record)
- **Reactivate**: Click "Reactivate" to restore a deactivated course assignment
- **Each course** shows:
  - Course Code
  - Course Name
  - Lecturer assigned
  - Semester
  - Academic Year
  - Status (Active/Inactive)

**IMPORTANT**: Only courses assigned to semesters will appear in the allocation interfaces!

---

## METHOD 1: Student Self-Service Registration (Recommended)

**Best for**: Allowing students to choose their own courses within their program

### Student Side:
Navigate to: **Student Dashboard** → **Register Courses**

#### Process:
1. View available courses for your program and year
2. Click "Request Registration" on desired courses
3. Submit up to 7 course requests per semester
4. Monitor request status in "My Registration Requests" table
5. Once approved, access course content from dashboard

### Admin Side:
Navigate to: **Admin Dashboard** → **Registration Approvals**

#### Process:
1. View all pending registration requests
2. Review student-course compatibility
3. Approve individual requests OR use bulk approval
4. Add rejection notes if declining a request
5. System automatically enrolls students upon approval

---

## METHOD 2: Individual Student Allocation

Use this method when you want to allocate courses to one student at a time.

### How to Access:
1. Login as **Administrator/Staff**
2. Navigate to **Admin Dashboard**
3. Click on **"Module Allocation"** (or access directly via `admin/module_allocation.php`)

### Step-by-Step Process:

#### 1. Select Student
- Use the **"Select Student"** dropdown menu
- Students are grouped by program and year
- Format: `[Program] Year X - Student Name (ID)`
- Example: `BIT Year 1 - John Doe (20230001)`

#### 2. Click "Allocate Course to Student" Button
- This opens the course selection modal

#### 3. Search and Filter Courses
The modal shows only semester-assigned courses. You can filter by:
- **Search Box**: Search by course code, name, or lecturer name
- **Semester Filter**: Filter by specific semester
- **Table shows**:
  - Course Code
  - Course Name
  - Lecturer Name
  - Program
  - Year of Study
  - Semester (colored badge)

#### 4. Select Course
- Click on a course row to select it (radio button)
- The row will be highlighted
- Only one course can be selected at a time

#### 5. Confirm Allocation
- Click **"Allocate Selected Course"** button
- System validates:
  - ✓ Course program matches student's program
  - ✓ Course year matches student's year of study
  - ✓ Student hasn't exceeded 7-course limit per semester
  - ✓ Student isn't already enrolled in this course

#### 6. View Results
- Success message shows: Student name and course allocated
- Error message shows if validation fails
- The enrollment appears in the "Current Module Allocations" table

### Current Allocations Table:
- View all student enrollments
- Shows: Student Name, ID, Course Name, Code, Enrollment Date, Actions
- **Delete** button to remove allocation if needed

---

## METHOD 3: Bulk Student Allocation

Use this method when you want to allocate ONE course to MULTIPLE students at once.

### How to Access:
1. Login as **Administrator/Staff**
2. Navigate to **"Manage Courses"** (access via `admin/manage_courses.php`)

### Step-by-Step Process:

#### 1. Find the Course
- Scroll through the courses list OR
- Use the search filters (program, year, semester)
- Each course card shows full details

#### 2. Click "Allocate" Button
- Located in the course card actions (blue button with user icon)
- Opens the **Bulk Allocation Modal**

#### 3. Filter Students
In the modal, you can filter students by:
- **Program of Study**: Dropdown (BIT, BA, BSc, etc.)
- **Year of Study**: Dropdown (Year 1, 2, 3, 4)
- **Search**: Text search for student names/IDs
- Filters work in combination

#### 4. Select Students
The student list shows:
- **Color-coded status**:
  - 🟢 **Green** = Matching program & year (recommended)
  - 🟡 **Yellow** = Already enrolled in this course
  - ⚪ **Gray** = Available but different program/year
- **Student Details**: Name, Program, Year, Semester
- **Enrollment Status**: Shows if already enrolled

Selection options:
- **Individual**: Click checkbox next to each student
- **Select All**: Click "Select All" button (selects all visible/filtered)
- **Deselect All**: Click "Deselect All" button (clears all selections)

#### 5. Allocate to Selected Students
- Click **"Allocate to Selected Students"** button
- System processes each student:
  - Skips if already enrolled
  - Validates program/year compatibility
  - Checks 7-course limit
- Shows summary: "Successfully enrolled X students"
- Page refreshes automatically

### Best Practices:
- **Filter by matching program** for better success rate
- **Review yellow (already enrolled)** students before selecting
- **Use Select All** when enrolling a whole class
- **Check course details** at top of modal before allocating

---

## VALIDATION RULES

The system enforces these rules automatically:

### 1. Course-Semester Assignment
- ✓ Course MUST be assigned to a semester first
- ✓ Only active semester assignments appear for allocation

### 2. Program Compatibility
- ✓ Course program must match student's program
- ⚠️ Warning shown if mismatch (bulk allocation may skip)

### 3. Year of Study Match
- ✓ Course year must match student's year of study
- ⚠️ Prevents Year 1 students from enrolling in Year 3 courses

### 4. Course Limit
- ✓ Maximum 7 courses per student per semester
- ✓ System counts existing enrollments before allowing new ones

### 5. Duplicate Prevention
- ✓ Student cannot enroll in same course twice
- ✓ Automatically skipped in bulk allocation

---

## TROUBLESHOOTING

### Problem: No courses appear in allocation modal
**Solution**: 
- Ensure courses are assigned to semesters first
- Go to "Semester Course Assignment" and assign courses
- Check that semester assignments are "Active"

### Problem: Cannot allocate course to student
**Possible Causes**:
1. **Program mismatch** - Course is for different program than student
2. **Year mismatch** - Course year doesn't match student's year
3. **7-course limit reached** - Student already has 7 courses for that semester
4. **Already enrolled** - Student already has this course

**Solution**: Check error message for specific reason

### Problem: Student not appearing in bulk allocation list
**Possible Causes**:
1. Student is inactive
2. Filters applied (check program/year filters)
3. Student already enrolled (shown in yellow)

**Solution**: Clear filters or check student status in "Manage Students"

### Problem: Bulk allocation skips some students
**This is normal!** System skips students who:
- Are already enrolled
- Have reached 7-course limit
- Have program/year mismatch
- Check success message for count of successfully enrolled students

---

## WORKFLOW RECOMMENDATIONS

### Which Method to Use?

**Use Student Self-Service Registration when:**
- ✓ Students should choose their own elective courses
- ✓ You want to reduce administrative workload
- ✓ Students need flexibility in course selection
- ✓ You want automated validation before enrollment
- ✓ You need audit trail of registration requests

**Use Individual Admin Allocation when:**
- ✓ Student needs special permission course
- ✓ Program/year mismatch requires override
- ✓ Late enrollment after registration period
- ✓ Individual case-by-case decisions needed

**Use Bulk Admin Allocation when:**
- ✓ Enrolling entire class in required courses
- ✓ Fast enrollment of many students needed
- ✓ Pre-registration for incoming students
- ✓ Administrative batch processing

### Start of Semester Workflow:
1. **Update semester courses** in "Semester Course Assignment"
   - Assign all courses for the upcoming semester
   - Set correct academic year

2. **Choose enrollment strategy**:
   - **Option A (Student-Driven)**: 
     - Announce registration period to students
     - Students submit course requests
     - Admin reviews and approves requests daily
     - Bulk approve matching requests
   
   - **Option B (Admin-Driven)**:
     - Use bulk allocation for required courses
     - Filter by program and year
     - Select all matching students
   
   - **Option C (Hybrid)**:
     - Bulk allocate required courses
     - Open self-service registration for electives
     - Admin approves elective requests

3. **Monitor and adjust**
   - Check registration approval dashboard daily
   - Process pending requests promptly
   - Use individual allocation for special cases

### Mid-Semester Adjustments:
- Use "Module Allocation" for individual changes
- Delete allocations from "Current Module Allocations" if needed
- Add new students using bulk or individual method

### End of Semester:
- Deactivate semester course assignments when term ends
- Keep records (don't delete) for historical tracking
- Prepare next semester's assignments

---

## QUICK REFERENCE

| Task | Navigate To | Action |
|------|-------------|--------|
| **SEMESTER SETUP** |
| Assign courses to semester | Semester Course Assignment | Click "Assign Courses to Semester" button |
| **STUDENT ACTIONS** |
| Browse available courses | Student → Register Courses | View courses for your program |
| Request course registration | Student → Register Courses | Click "Request Registration" |
| Check request status | Student → Register Courses | View "My Registration Requests" table |
| **ADMIN ACTIONS** |
| Review registration requests | Admin → Registration Approvals | View pending requests |
| Approve single request | Admin → Registration Approvals | Click "Approve" on request card |
| Reject request with notes | Admin → Registration Approvals | Click "Reject", enter reason |
| Bulk approve requests | Admin → Registration Approvals | Select multiple, click "Approve Selected" |
| Filter requests | Admin → Registration Approvals | Use status/program/semester filters |
| **TRADITIONAL ALLOCATION** |
| Allocate course to 1 student | Admin → Module Allocation | Select student, select course, allocate |
| Allocate 1 course to many | Admin → Manage Courses | Find course, "Allocate", select students |
| View all allocations | Admin → Module Allocation | See "Current Module Allocations" table |
| Remove allocation | Admin → Module Allocation | Click "Delete" in allocations table |
| **MANAGEMENT** |
| Manage semester assignments | Semester Course Assignment | View table, activate/deactivate |

---

## IMPORTANT NOTES

1. **Always assign courses to semesters FIRST** before any allocation method
2. **Student self-service reduces admin workload** - students handle initial selection
3. **Admin approval provides oversight** - validates all enrollments
4. **Bulk operations save time** - both for registration approval and allocation
5. **System enforces 7-course limit** - cannot be overridden
6. **Validation is automatic** - helps prevent enrollment errors
7. **Color coding helps** identify best candidates and compatibility
8. **Filters are your friend** - use them to narrow down selections
9. **Success messages confirm** how many students were enrolled/approved
10. **Rejection notes** help students understand why request was denied
11. **Transaction safety** - approvals rollback on error, preventing partial enrollments
12. **Audit trail** - all registration requests are tracked with dates and reviewers

---

## TROUBLESHOOTING

### Student Issues:

**Problem: Cannot see any courses to register**
**Solution**: 
- Courses must be assigned to semesters by admin first
- Check with administrator about semester course availability
- Verify you're viewing correct semester

**Problem: "Already enrolled" error when requesting**
**Solution**: 
- You're already enrolled in this course
- Check your dashboard enrolled courses
- Contact admin if enrollment is incorrect

**Problem: "7-course limit reached" error**
**Solution**:
- You have 7 courses + pending requests for the semester
- Wait for pending requests to be processed
- Drop a course before requesting new one
- Contact admin for special permission

**Problem: Request was rejected**
**Solution**:
- Read admin notes for rejection reason
- Contact administrator to discuss
- Verify program/year compatibility
- Check prerequisite requirements

### Admin Issues:

**Problem: No requests appearing in approval dashboard**
**Solution**:
- Students haven't submitted any requests yet
- Check filters (status, program, semester)
- Verify course_registration_requests table exists
- Run setup_registration_requests.php if needed

**Problem: Cannot approve request - already enrolled error**
**Solution**:
- Student was already enrolled manually
- Request will auto-reject with note
- Check vle_enrollments table for duplicates

**Problem: Bulk approval skips many students**
**This is normal!** System automatically skips:
- Students already enrolled
- Students at 7-course limit
- Students with program/year mismatch
- Check success message for approved count

**Problem: Student registration requests not saving**
**Solution**:
- Run setup_registration_requests.php to create table
- Check database permissions
- Verify semester_courses table exists
- Check error logs

---

## SUPPORT

### Setup Steps:
1. **First-time setup**: Run `setup_registration_requests.php` to create the database table
2. **Assign courses**: Use Semester Course Assignment to make courses available
3. **Test workflow**: Create a test student account and submit a registration request
4. **Review process**: Login as admin and practice approving requests

### Getting Help:
If you encounter issues not covered in this guide:
1. Check that you have **Administrator/Staff** role (for admin features)
2. Check that you are logged in as **Student** (for student features)
3. Verify database connection is working
4. Check that courses have lecturers assigned
5. Ensure semester_courses table exists (auto-created on first use)
6. Ensure course_registration_requests table exists (run setup file or auto-creates)
7. Review error messages carefully - they indicate the specific issue
8. Contact system administrator for database or permission issues

### Best Practices:
- Process registration requests daily during registration period
- Add clear rejection notes to help students understand
- Use filters to manage large numbers of requests efficiently
- Bulk approve compatible requests to save time
- Keep semester course assignments up to date
- Communicate registration deadlines clearly to students

---

**Last Updated**: January 10, 2026  
**System Version**: VLE 1.0  
**New Features**: Student Self-Service Registration with Admin Approval
**Modules**: semester_course_assignment.php, module_allocation.php, manage_courses.php, register_courses.php (student), approve_registrations.php (admin)
