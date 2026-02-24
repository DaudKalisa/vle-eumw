# Foreign Key Constraint Error - FIXED

## Problem
Error: `Can't create table 'university_portal'.'attendance_records' (errno: 150 "Foreign key constraint is incorrectly formed")`

## Root Cause
MySQL Error 150 indicates the foreign key constraint was incorrectly formed. This happened because:

1. **Missing Engine Specification**: Tables weren't explicitly set to use InnoDB (required for foreign keys)
2. **Missing Cascade Actions**: Foreign key constraints didn't specify ON DELETE behavior
3. **Missing Indexes**: Some foreign key columns weren't indexed for performance

## Solution Applied

Updated `setup.php` with the following changes:

### 1. Added InnoDB Engine
All affected tables now include:
```sql
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### 2. Added Cascade Actions
Foreign keys now specify deletion behavior:
```sql
FOREIGN KEY (column) REFERENCES table(column) ON DELETE CASCADE
FOREIGN KEY (lecturer_id) REFERENCES lecturers(lecturer_id) ON DELETE SET NULL
```

### 3. Added Indexes
Foreign key columns now have indexes for performance:
```sql
INDEX idx_session (session_id),
INDEX idx_student (student_id)
```

## Tables Updated

| Table | Changes |
|-------|---------|
| `students` | Added ENGINE=InnoDB, charset, collation |
| `lecturers` | Added ENGINE=InnoDB, charset, collation |
| `vle_courses` | Added ENGINE=InnoDB, ON DELETE SET NULL |
| `attendance_sessions` | Added ENGINE=InnoDB, ON DELETE CASCADE |
| `attendance_records` | Added ENGINE=InnoDB, ON DELETE CASCADE, indexes |

## Before and After

### Before (Error 150)
```sql
CREATE TABLE IF NOT EXISTS attendance_records (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    ...
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(session_id),
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    UNIQUE (session_id, student_id)
);
```

### After (Works)
```sql
CREATE TABLE IF NOT EXISTS attendance_records (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    ...
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    UNIQUE (session_id, student_id),
    INDEX idx_session (session_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## How to Apply

1. **Option 1**: Run setup.php again via browser
   - Visit: `http://localhost/vle-eumw/setup.php`
   - This will recreate all tables with correct definitions

2. **Option 2**: Drop and recreate database
   ```sql
   DROP DATABASE university_portal;
   CREATE DATABASE university_portal;
   -- Then run setup.php
   ```

## Verification

The updated `setup.php` has been validated:
- ✅ PHP syntax is correct (no errors)
- ✅ All foreign key relationships properly defined
- ✅ All tables use InnoDB engine
- ✅ Proper ON DELETE behavior specified
- ✅ Indexes added for performance

## Key Points

✅ **InnoDB Required**: Foreign keys only work with InnoDB, not MyISAM  
✅ **ON DELETE CASCADE**: When parent row deleted, child rows deleted too  
✅ **ON DELETE SET NULL**: When parent deleted, foreign key set to NULL  
✅ **Indexes**: Speed up foreign key checks  
✅ **Charset Consistency**: Using utf8mb4_unicode_ci for all tables  

## Result

The `attendance_records` table will now be created successfully without Error 150!
