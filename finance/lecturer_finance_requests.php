<?php
// finance/lecturer_finance_requests.php - Manage Lecturer Finance Requests (redirects to finance/finance_manage_requests.php)
require_once '../includes/auth.php';
requireLogin();
requireRole(['finance', 'staff', 'admin']);

// Redirect to the main management page
header('Location: finance_manage_requests.php');
exit();
