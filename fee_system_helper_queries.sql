-- Fee System Helper Queries
-- Use these queries for maintenance and updates after running setup_fee_system.php

-- ===========================================
-- 1. SET PROGRAM TYPES FOR EXISTING STUDENTS
-- ===========================================

-- Check current program types
SELECT student_id, full_name, program_type, department_code 
FROM students 
ORDER BY student_id;

-- Set all students to 'degree' (default)
UPDATE students SET program_type = 'degree';

-- Set specific students to different program types
-- UPDATE students SET program_type = 'professional' WHERE student_id IN ('STU001', 'STU002');
-- UPDATE students SET program_type = 'masters' WHERE student_id = 'STU003';
-- UPDATE students SET program_type = 'doctorate' WHERE student_id = 'STU004';

-- Set by department (example - adjust department codes as needed)
-- UPDATE students SET program_type = 'degree' WHERE department_code IN ('CS', 'ENG', 'BUS');
-- UPDATE students SET program_type = 'professional' WHERE department_code IN ('CERT', 'DIP');
-- UPDATE students SET program_type = 'masters' WHERE department_code IN ('MAST');
-- UPDATE students SET program_type = 'doctorate' WHERE department_code IN ('PHD');


-- ===========================================
-- 2. UPDATE EXPECTED TOTALS FOR ALL STUDENTS
-- ===========================================

-- This calculates expected_total and expected_tuition based on program type and current fees
UPDATE student_finances sf
JOIN students s ON sf.student_id = s.student_id
JOIN fee_settings fs ON fs.id = 1
SET 
    sf.expected_tuition = CASE s.program_type
        WHEN 'degree' THEN fs.tuition_degree
        WHEN 'professional' THEN fs.tuition_professional
        WHEN 'masters' THEN fs.tuition_masters
        WHEN 'doctorate' THEN fs.tuition_doctorate
        ELSE fs.tuition_degree  -- default to degree if NULL
    END,
    sf.expected_total = fs.application_fee + fs.registration_fee + 
        CASE s.program_type
            WHEN 'degree' THEN fs.tuition_degree
            WHEN 'professional' THEN fs.tuition_professional
            WHEN 'masters' THEN fs.tuition_masters
            WHEN 'doctorate' THEN fs.tuition_doctorate
            ELSE fs.tuition_degree
        END,
    sf.balance = (fs.application_fee + fs.registration_fee + 
        CASE s.program_type
            WHEN 'degree' THEN fs.tuition_degree
            WHEN 'professional' THEN fs.tuition_professional
            WHEN 'masters' THEN fs.tuition_masters
            WHEN 'doctorate' THEN fs.tuition_doctorate
            ELSE fs.tuition_degree
        END) - sf.total_paid;


-- ===========================================
-- 3. VIEW CURRENT FEE CONFIGURATION
-- ===========================================

SELECT 
    'Application Fee' AS fee_type, 
    CONCAT('K', FORMAT(application_fee, 0)) AS amount,
    'All Programs' AS applies_to
FROM fee_settings WHERE id = 1
UNION ALL
SELECT 
    'Registration Fee', 
    CONCAT('K', FORMAT(registration_fee, 0)),
    'All Programs'
FROM fee_settings WHERE id = 1
UNION ALL
SELECT 
    'Tuition - Degree', 
    CONCAT('K', FORMAT(tuition_degree, 0)),
    'Degree Programs'
FROM fee_settings WHERE id = 1
UNION ALL
SELECT 
    'Tuition - Professional', 
    CONCAT('K', FORMAT(tuition_professional, 0)),
    'Professional Courses'
FROM fee_settings WHERE id = 1
UNION ALL
SELECT 
    'Tuition - Masters', 
    CONCAT('K', FORMAT(tuition_masters, 0)),
    'Masters Programs'
FROM fee_settings WHERE id = 1
UNION ALL
SELECT 
    'Tuition - Doctorate', 
    CONCAT('K', FORMAT(tuition_doctorate, 0)),
    'Doctorate Programs'
FROM fee_settings WHERE id = 1
UNION ALL
SELECT 
    'Supplementary Exam', 
    CONCAT('K', FORMAT(supplementary_exam_fee, 0)),
    'All Programs'
FROM fee_settings WHERE id = 1
UNION ALL
SELECT 
    'Deferred Exam', 
    CONCAT('K', FORMAT(deferred_exam_fee, 0)),
    'All Programs'
FROM fee_settings WHERE id = 1;


-- ===========================================
-- 4. REVENUE REPORTS
-- ===========================================

-- Total Expected Revenue by Program Type
SELECT 
    s.program_type,
    COUNT(*) AS student_count,
    CONCAT('K', FORMAT(SUM(sf.expected_total), 0)) AS total_expected_revenue
FROM student_finances sf
JOIN students s ON sf.student_id = s.student_id
GROUP BY s.program_type
ORDER BY s.program_type;

-- Overall Revenue Summary
SELECT 
    COUNT(DISTINCT sf.student_id) AS total_students,
    CONCAT('K', FORMAT(SUM(sf.expected_total), 0)) AS expected_revenue,
    CONCAT('K', FORMAT(SUM(sf.total_paid), 0)) AS collected_revenue,
    CONCAT('K', FORMAT(SUM(sf.balance), 0)) AS outstanding_balance,
    CONCAT(ROUND((SUM(sf.total_paid) / SUM(sf.expected_total)) * 100, 1), '%') AS collection_rate
FROM student_finances sf;

-- Application Fee Status
SELECT 
    COUNT(*) AS total_students,
    SUM(CASE WHEN application_fee_paid >= 5500 THEN 1 ELSE 0 END) AS paid_app_fee,
    SUM(CASE WHEN application_fee_paid < 5500 THEN 1 ELSE 0 END) AS pending_app_fee,
    CONCAT('K', FORMAT(SUM(application_fee_paid), 0)) AS total_app_collected
FROM student_finances;

-- Registration Fee Status
SELECT 
    COUNT(*) AS total_students,
    SUM(CASE WHEN registration_paid >= 39500 THEN 1 ELSE 0 END) AS paid_reg_fee,
    SUM(CASE WHEN registration_paid < 39500 THEN 1 ELSE 0 END) AS pending_reg_fee,
    CONCAT('K', FORMAT(SUM(registration_paid), 0)) AS total_reg_collected
FROM student_finances;


-- ===========================================
-- 5. STUDENT PAYMENT STATUS
-- ===========================================

-- Students with outstanding balances
SELECT 
    s.student_id,
    s.full_name,
    s.program_type,
    CONCAT('K', FORMAT(sf.total_paid, 0)) AS paid,
    CONCAT('K', FORMAT(sf.expected_total, 0)) AS expected,
    CONCAT('K', FORMAT(sf.balance, 0)) AS balance,
    sf.payment_percentage AS access_level
FROM students s
JOIN student_finances sf ON s.student_id = sf.student_id
WHERE sf.balance > 0
ORDER BY sf.balance DESC;

-- Students who haven't paid application fee
SELECT 
    s.student_id,
    s.full_name,
    s.program_type,
    CONCAT('K', FORMAT(sf.application_fee_paid, 0)) AS app_fee_paid
FROM students s
JOIN student_finances sf ON s.student_id = sf.student_id
WHERE sf.application_fee_paid < 5500
ORDER BY s.student_id;

-- Payment progress by access level
SELECT 
    sf.payment_percentage AS access_level,
    COUNT(*) AS student_count,
    GROUP_CONCAT(s.student_id ORDER BY s.student_id SEPARATOR ', ') AS students
FROM students s
JOIN student_finances sf ON s.student_id = sf.student_id
GROUP BY sf.payment_percentage
ORDER BY sf.payment_percentage;


-- ===========================================
-- 6. FIX DATA ISSUES
-- ===========================================

-- Recalculate tuition_paid for all students
-- (Total paid minus application fee minus registration fee)
UPDATE student_finances sf
JOIN fee_settings fs ON fs.id = 1
SET sf.tuition_paid = GREATEST(0, sf.total_paid - fs.application_fee - fs.registration_fee);

-- Fix missing program types (set to degree)
UPDATE students 
SET program_type = 'degree' 
WHERE program_type IS NULL;

-- Recalculate balances
UPDATE student_finances sf
SET sf.balance = sf.expected_total - sf.total_paid;

-- Recalculate access levels based on tuition paid
UPDATE student_finances sf
SET 
    sf.payment_percentage = CASE
        WHEN sf.tuition_paid >= sf.expected_tuition THEN 100
        WHEN sf.tuition_paid >= (sf.expected_tuition * 0.75) THEN 75
        WHEN sf.tuition_paid >= (sf.expected_tuition * 0.50) THEN 50
        WHEN sf.tuition_paid >= (sf.expected_tuition * 0.25) THEN 25
        ELSE 0
    END,
    sf.content_access_weeks = CASE
        WHEN sf.tuition_paid >= sf.expected_tuition THEN 16
        WHEN sf.tuition_paid >= (sf.expected_tuition * 0.75) THEN 12
        WHEN sf.tuition_paid >= (sf.expected_tuition * 0.50) THEN 8
        WHEN sf.tuition_paid >= (sf.expected_tuition * 0.25) THEN 4
        ELSE 0
    END;


-- ===========================================
-- 7. TESTING QUERIES
-- ===========================================

-- View a specific student's complete finance record
SELECT 
    s.student_id,
    s.full_name,
    s.program_type,
    CONCAT('K', FORMAT(sf.application_fee_paid, 0)) AS app_fee,
    CONCAT('K', FORMAT(sf.registration_paid, 0)) AS reg_fee,
    CONCAT('K', FORMAT(sf.installment_1, 0)) AS inst_1,
    CONCAT('K', FORMAT(sf.installment_2, 0)) AS inst_2,
    CONCAT('K', FORMAT(sf.installment_3, 0)) AS inst_3,
    CONCAT('K', FORMAT(sf.installment_4, 0)) AS inst_4,
    CONCAT('K', FORMAT(sf.tuition_paid, 0)) AS total_tuition,
    CONCAT('K', FORMAT(sf.total_paid, 0)) AS total_paid,
    CONCAT('K', FORMAT(sf.expected_total, 0)) AS expected,
    CONCAT('K', FORMAT(sf.balance, 0)) AS balance,
    CONCAT(sf.payment_percentage, '%') AS access_level
FROM students s
JOIN student_finances sf ON s.student_id = sf.student_id
WHERE s.student_id = 'STU001';  -- Change student ID as needed

-- View all payment transactions for a student
SELECT 
    pt.transaction_id,
    pt.payment_date,
    pt.payment_type,
    CONCAT('K', FORMAT(pt.amount, 0)) AS amount,
    pt.payment_method,
    pt.reference_number,
    pt.notes
FROM payment_transactions pt
WHERE pt.student_id = 'STU001'  -- Change student ID as needed
ORDER BY pt.payment_date DESC, pt.transaction_id DESC;


-- ===========================================
-- 8. BACKUP QUERIES
-- ===========================================

-- Backup current fee settings before making changes
CREATE TABLE fee_settings_backup AS SELECT * FROM fee_settings;

-- Backup student finances before bulk updates
CREATE TABLE student_finances_backup AS SELECT * FROM student_finances;

-- Restore from backup if needed
-- DELETE FROM fee_settings WHERE id = 1;
-- INSERT INTO fee_settings SELECT * FROM fee_settings_backup WHERE id = 1;


-- ===========================================
-- 9. USEFUL CHECKS
-- ===========================================

-- Check for data inconsistencies
SELECT 
    s.student_id,
    s.full_name,
    'Missing program_type' AS issue
FROM students s
WHERE s.program_type IS NULL
UNION ALL
SELECT 
    s.student_id,
    s.full_name,
    'Total paid exceeds expected' AS issue
FROM students s
JOIN student_finances sf ON s.student_id = sf.student_id
WHERE sf.total_paid > sf.expected_total
UNION ALL
SELECT 
    s.student_id,
    s.full_name,
    'Negative balance' AS issue
FROM students s
JOIN student_finances sf ON s.student_id = sf.student_id
WHERE sf.balance < 0;

-- Check installment calculations
SELECT 
    s.student_id,
    s.program_type,
    sf.expected_tuition,
    ROUND(sf.expected_tuition / 4, 2) AS calculated_installment,
    sf.installment_1 + sf.installment_2 + sf.installment_3 + sf.installment_4 AS sum_installments,
    sf.tuition_paid
FROM students s
JOIN student_finances sf ON s.student_id = sf.student_id;


-- ===========================================
-- NOTES
-- ===========================================
/*
1. Always run setup_fee_system.php FIRST before using these queries
2. Backup your database before running UPDATE queries
3. Test queries on a single student first before running on all students
4. The fee_settings table should only have ONE row (id = 1)
5. All amounts are in Zambian Kwacha (K)
6. Program types: 'degree', 'professional', 'masters', 'doctorate'
*/
