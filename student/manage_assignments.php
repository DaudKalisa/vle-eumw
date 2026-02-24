<?php
// manage_assignments.php - Student view assignments page
// Redirect to the main course listing which displays assignments
require_once '../includes/auth.php';

requireLogin();
requireRole(['student']);

header('Location: courses.php');
exit;
