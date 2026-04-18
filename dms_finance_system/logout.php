<?php
require_once __DIR__ . '/includes/auth.php';

dmsLogout();
header('Location: ' . dmsBaseUrl() . '/login.php');
exit;
