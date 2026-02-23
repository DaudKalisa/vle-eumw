-- Add response_date column if missing
ALTER TABLE lecturer_finance_requests ADD COLUMN response_date DATETIME;
