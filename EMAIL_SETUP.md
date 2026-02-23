# Email Configuration Guide for VLE System

## Setup Instructions

### 1. Install PHPMailer

**Option A: Using Composer (Recommended)**

Open PowerShell **as Administrator** in the VLE system directory and run:

```powershell
cd c:\xampp\htdocs\vle_system
composer install
```

If you don't have Composer, download it from: https://getcomposer.org/download/

**Option B: Manual Installation**

1. Download PHPMailer from: https://github.com/PHPMailer/PHPMailer/archive/refs/heads/master.zip
2. Extract the ZIP file
3. Copy the `src` folder to `c:\xampp\htdocs\vle_system\vendor\phpmailer\phpmailer\`
4. Ensure the path exists: `c:\xampp\htdocs\vle_system\vendor\phpmailer\phpmailer\src\PHPMailer.php`

### 2. Create Required Directory Structure

```powershell
New-Item -Path "c:\xampp\htdocs\vle_system\vendor\phpmailer\phpmailer\src" -ItemType Directory -Force
```

### 3. Configure Email Settings

Edit the file: `includes/email.php`

Update the following constants with your email provider settings:

```php
define('SMTP_HOST', 'smtp.gmail.com'); // Your SMTP server
define('SMTP_PORT', 587); // Usually 587 for TLS, 465 for SSL
define('SMTP_USERNAME', 'your-email@gmail.com'); // Your email
define('SMTP_PASSWORD', 'your-app-password'); // Your app password
define('SMTP_FROM_EMAIL', 'your-email@gmail.com'); // From email
define('SMTP_FROM_NAME', 'VLE System'); // System name
define('SMTP_ENCRYPTION', 'tls'); // 'tls' or 'ssl'
define('SYSTEM_URL', 'http://localhost/vle_system'); // Your VLE URL
```

### 3. Gmail Setup (If using Gmail)

1. Enable 2-Factor Authentication on your Google account
2. Generate an App Password:
   - Go to: https://myaccount.google.com/apppasswords
   - Select "Mail" and "Windows Computer"
   - Copy the generated 16-character password
   - Use this password in `SMTP_PASSWORD`

### 4. Create Announcements Table

Run this command in PowerShell:

```powershell
cd c:\xampp\htdocs\vle_system
php create_announcements_table.php
```

### 5. Update Database with Email Fields (if needed)

Make sure your students and lecturers tables have an `email` column:

```sql
ALTER TABLE students ADD COLUMN IF NOT EXISTS email VARCHAR(255);
ALTER TABLE lecturers ADD COLUMN IF NOT EXISTS email VARCHAR(255);
```

## Features Implemented

### 1. Assignment Submission Emails
- Student submits assignment → Lecturer receives email (with CC to student)
- Email includes direct link to grade the submission

### 2. Grade Notification Emails
- Lecturer grades assignment → Student receives email (with CC to lecturer)
- Email shows: Grade letter, score, GPA, pass/fail status, feedback
- Email includes link to view full grades

### 3. Message Notification Emails
- User sends message → Recipient receives email (with CC to sender)
- Email includes message preview and link to view/reply

### 4. Course Announcements
- Lecturer posts announcement → All enrolled students receive email
- Accessed via: lecturer/announcements.php?course_id=X

## Email Templates

All emails use professional HTML templates with:
- Responsive design
- Color-coded headers
- Clear call-to-action buttons
- Mobile-friendly layout

## Testing Email Functionality

1. Make sure XAMPP Apache and MySQL are running
2. Configure email settings in `includes/email.php`
3. Add email addresses to student and lecturer records
4. Test each feature:
   - Submit an assignment
   - Grade an assignment
   - Send a message
   - Post an announcement

## Troubleshooting

### Email not sending?
- Check SMTP credentials are correct
- Verify firewall isn't blocking SMTP ports
- Check PHP error logs: `c:\xampp\php\logs\php_error_log`
- Enable error reporting in email.php temporarily

### Gmail "Less secure apps" error?
- Use App Password instead of regular password
- Enable 2-Factor Authentication first

### Wrong sender email?
- Update `SMTP_FROM_EMAIL` in email.php

### Links in emails not working?
- Update `SYSTEM_URL` constant to match your actual URL
