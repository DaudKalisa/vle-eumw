-- Add missing columns to lecturer_finance_requests for finance request form compatibility
ALTER TABLE lecturer_finance_requests
ADD COLUMN month INT(2) AFTER lecturer_id,
ADD COLUMN year INT(4) AFTER month,
ADD COLUMN courses_data TEXT AFTER year,
ADD COLUMN total_students INT AFTER courses_data,
ADD COLUMN total_modules INT AFTER total_students,
ADD COLUMN total_assignments_marked INT AFTER total_modules,
ADD COLUMN total_content_uploaded INT AFTER total_assignments_marked,
ADD COLUMN total_hours DECIMAL(6,2) AFTER total_content_uploaded,
ADD COLUMN hourly_rate DECIMAL(10,2) AFTER total_hours,
ADD COLUMN total_amount DECIMAL(12,2) AFTER hourly_rate,
ADD COLUMN signature_path VARCHAR(255) AFTER total_amount,
ADD COLUMN additional_notes TEXT AFTER signature_path,
ADD COLUMN submission_date DATETIME AFTER additional_notes;