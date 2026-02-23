-- Add NRC column to lecturers table if missing
ALTER TABLE lecturers ADD COLUMN nrc VARCHAR(100) NULL;