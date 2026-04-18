<?php
// Standalone Dissertation + Finance Management System configuration

define('DMS_DB_HOST', 'localhost');
define('DMS_DB_USER', 'root');
define('DMS_DB_PASS', '');
define('DMS_DB_NAME', 'dms_finance_db');
define('DMS_DB_CHARSET', 'utf8mb4');
define('DMS_SESSION_TIMEOUT', 1800);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', DMS_SESSION_TIMEOUT);
    session_start();
}

function dmsGetDbConnection(bool $createDatabase = true): mysqli {
    static $conn = null;

    if ($conn instanceof mysqli && @$conn->ping()) {
        return $conn;
    }

    $conn = new mysqli(DMS_DB_HOST, DMS_DB_USER, DMS_DB_PASS);
    if ($conn->connect_error) {
        die('Database connection failed: ' . $conn->connect_error);
    }

    if ($createDatabase) {
        $sql = 'CREATE DATABASE IF NOT EXISTS ' . DMS_DB_NAME . ' CHARACTER SET ' . DMS_DB_CHARSET;
        if (!$conn->query($sql)) {
            die('Failed to create database: ' . $conn->error);
        }
    }

    if (!$conn->select_db(DMS_DB_NAME)) {
        die('Failed to select database: ' . $conn->error);
    }

    if (!$conn->set_charset(DMS_DB_CHARSET)) {
        die('Failed to set charset: ' . $conn->error);
    }

    return $conn;
}

function dmsBaseUrl(): string {
    if (!isset($_SERVER['HTTP_HOST'])) {
        return 'http://localhost/vle-eumw/dms_finance_system';
    }

    $is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $protocol = $is_https ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'] . '/vle-eumw/dms_finance_system';
}
