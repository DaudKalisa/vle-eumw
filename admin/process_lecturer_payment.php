<?php
// admin/process_lecturer_payment.php - Redirect to new location in finance/
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'admin']);

header('Location: ../finance/process_lecturer_payment.php');
exit;
