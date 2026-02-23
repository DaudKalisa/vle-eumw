-- lecturer_finance_requests table for lecturer payment/airtime requests
CREATE TABLE IF NOT EXISTS lecturer_finance_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT(11) NOT NULL,
    request_type ENUM('monthly_payment','airtime') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    details TEXT,
    request_date DATETIME NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    response TEXT,
    response_date DATETIME,
    INDEX (lecturer_id),
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(lecturer_id) ON DELETE CASCADE
);