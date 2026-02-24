# Copilot Instructions for VLE System

## Project Overview
- This is a PHP-based Virtual Learning Environment (VLE) for university course, student, lecturer, and finance management.
- The system is standalone but shares a database with the main university portal.
- Major modules: Student, Lecturer, Admin, Finance, API endpoints.

## Architecture & Key Patterns
- **Directory structure:**
  - `includes/`: Core PHP logic (auth, config, helpers)
  - `admin/`, `student/`, `lecturer/`, `finance/`: Role-specific dashboards and features
  - `api/`: REST-like endpoints for AJAX and integrations
  - `assets/`: Static files (CSS, JS, images)
  - `uploads/`: User-uploaded files
- **Database:**
  - Centralized, with tables for users, students, lecturers, courses, enrollments, assignments, finances, etc.
  - See `DATABASE_TABLE_ANALYSIS.md` for table usage and mapping to code files.
- **Authentication:**
  - Handled in `includes/auth.php` and checked in most dashboard/feature files.
- **Finance system:**
  - Dynamic fee management, payment tracking, and content access control based on payment status (see `FEE_SYSTEM_COMPLETE.md`, `FINANCE_SYSTEM_GUIDE.md`).

## Developer Workflows
- **Setup:**
  - Run `setup.php` and `setup_finance_system.php` via browser to initialize DB tables.
  - Configure DB credentials in `includes/config.php` (or `config.production.php` for production).
- **Deployment:**
  - See `DEPLOYMENT_GUIDE.md` and `CLIENT_DEPLOYMENT_FIXES.md` for hosting, permissions, and .htaccess troubleshooting.
- **Error logs:**
  - PHP errors: `logs/php_errors.log`
  - Server errors: Apache error log
- **File permissions:**
  - `uploads/` must be writable; see deployment guides for chmod examples.

## Conventions & Integration
- **Role-based access:**
  - Each user type has a dedicated folder and dashboard.
- **AJAX/API:**
  - Use `api/` endpoints for dynamic UI updates and integrations.
- **External dependencies:**
  - Uses Composer for PHP packages (e.g., PHPMailer, PhpSpreadsheet, mPDF). Run `composer install` after cloning.
- **Customizations:**
  - Fee and finance logic is highly configurable via admin UI and DB tables.

## Key References
- `README.md`: High-level overview and directory map
- `DATABASE_TABLE_ANALYSIS.md`: Table-to-feature mapping
- `FEE_SYSTEM_COMPLETE.md`, `FINANCE_SYSTEM_GUIDE.md`: Finance logic and payment flows
- `DEPLOYMENT_GUIDE.md`, `CLIENT_DEPLOYMENT_FIXES.md`: Hosting and troubleshooting

## Example: Adding a New Feature
- Place new student features in `student/`, lecturer features in `lecturer/`, etc.
- Use `includes/auth.php` for access control.
- For DB changes, update setup scripts and document in the relevant `.md` files.

---
For more details, see the referenced markdown files in the project root.
