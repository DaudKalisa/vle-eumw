# Dean Portal Documentation

## Overview
The Dean Portal provides faculty-level academic management capabilities for deans to oversee lecturers, courses, students, exam results, and financial claims within their faculty.

## Color Theme
- **Primary Color**: Green (`#1a472a` to `#2d5a3e` gradient)
- Distinguishes Dean Portal from other portals (ODL: purple, Admin: blue, Finance: gold)

## Setup

### 1. Run Setup Script
Navigate to: `http://your-domain/setup_dean_portal.php`

This creates:
- `dean_announcements` table
- `dean_claims_approval` log table
- Dean approval columns in `lecturer_finance_requests`
- Dean approval columns in exam tables

### 2. Create Dean User
```sql
INSERT INTO users (username, email, password, role, full_name) 
VALUES ('dean_faculty', 'dean@university.edu', 'hashed_password', 'dean', 'Faculty Dean');
```

### 3. Assign Dean to Faculty
Update the `faculties` table:
```sql
UPDATE faculties SET head_of_faculty = <user_id> WHERE faculty_id = <faculty_id>;
```

## Features

### Dashboard (`dashboard.php`)
- Faculty statistics (lecturers, courses, students, programs)
- Pending claims overview
- Recent claims list
- Quick action buttons

### Claims Approval (`claims_approval.php`)
- View claims forwarded from ODL Coordinator
- Actions: Approve, Reject, Return, Forward to Finance
- Add remarks for each decision
- Filter by status and search

### Reports (`reports.php`)
- Generate faculty reports:
  - **Overview**: Summary statistics
  - **Lecturers**: Performance metrics
  - **Students**: Enrollment stats
  - **Claims**: Financial claims history
  - **Exams**: Results summary
- CSV export functionality
- Visual charts

### Lecturers Management (`lecturers.php`)
- View all faculty lecturers
- Filter by department
- See course assignments and claims
- View detailed lecturer profile

### Courses Overview (`courses.php`)
- All courses in faculty
- Enrollment counts
- Lecturer assignments
- Filter by program

### Students Overview (`students.php`)
- View enrolled students
- Filter by program and year
- Pagination (50 per page)
- Student statistics by year

### Exam Results (`exams.php`)
- Approve exam results before publication
- View results by course
- Dean approval workflow

### Performance Dashboard (`performance.php`)
- Key metrics cards
- Monthly claims trends chart
- Grading completion rate
- Top lecturers by claims

### Departments (`departments.php`)
- View faculty departments
- Program and lecturer counts

### Programs (`programs.php`)
- View academic programs
- Filter by type (Degree, Masters, etc.)

### Announcements (`announcements.php`)
- Create faculty announcements
- Target: All, Lecturers, or Students
- View and delete announcements

### Activity Logs (`activity_logs.php`)
- Claims approval history
- Announcement creation log

### Profile (`profile.php`)
- View/edit dean profile
- Faculty information

## Workflow: Claims Approval

```
[Lecturer Submits Claim]
        ↓
[ODL Coordinator Reviews]
        ↓
[Forwards to Dean] → odl_approval_status = 'forwarded_to_dean'
        ↓
[Dean Reviews Claim]
        ↓
[Dean Actions:]
  ├→ Approve → dean_approval_status = 'approved' → Forward to Finance
  ├→ Reject → dean_approval_status = 'rejected' → Back to Lecturer
  └→ Return → dean_approval_status = 'returned' → Back to ODL
```

## Files Structure
```
dean/
├── activity_logs.php      # Claims approval and activity logs
├── announcements.php      # Manage faculty announcements
├── claims_approval.php    # Review and approve claims
├── courses.php            # Courses overview
├── dashboard.php          # Main dean dashboard
├── departments.php        # Department management
├── exams.php              # Exam results approval
├── get_claim_details.php  # AJAX: Claim details modal
├── get_lecturer_details.php # AJAX: Lecturer details modal
├── header_nav.php         # Navigation component
├── lecturers.php          # Lecturers management
├── performance.php        # Performance metrics dashboard
├── print_claim.php        # Printable claim form
├── profile.php            # Dean profile page
├── programs.php           # Academic programs view
├── reports.php            # Generate reports & export
└── students.php           # Students overview
```

## Database Tables Used

### Created by Dean Portal
- `dean_announcements` - Faculty announcements
- `dean_claims_approval` - Approval audit log

### Columns Added
- `lecturer_finance_requests.dean_approval_status`
- `lecturer_finance_requests.dean_approved_by`
- `lecturer_finance_requests.dean_approved_at`
- `lecturer_finance_requests.dean_remarks`
- `exams.dean_approved` / `vle_exam_results.dean_approved`

### Existing Tables Used
- `users` - Dean authentication
- `faculties` - Faculty information
- `departments` - Department data
- `programs` - Academic programs
- `lecturers` - Lecturer data
- `students` - Student records
- `vle_courses` - Course information
- `lecturer_finance_requests` - Claims data
- `exams` / `vle_exams` - Exam data

## Access Control
- Required role: `dean` or `admin`
- Dean users are redirected to `dean/dashboard.php` on login
- Each page checks for proper authentication and role

## Dependencies
- Bootstrap 5.3 (CSS framework)
- Bootstrap Icons
- Chart.js (for performance charts)
- PHP 8.0+
- MySQL 5.7+ / MariaDB

## Troubleshooting

### "Access Denied" on Dean Portal
1. Verify user has `role = 'dean'` in users table
2. Run `setup_dean_portal.php` to create tables

### No Faculty Data Showing
1. Ensure dean is linked to a faculty (`faculties.head_of_faculty`)
2. Verify faculty has departments and programs

### Claims Not Appearing
1. Check ODL coordinator has forwarded claims
2. Verify `odl_approval_status = 'forwarded_to_dean'`

### Charts Not Rendering
1. Check browser console for errors
2. Ensure Chart.js CDN is accessible
