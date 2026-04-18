<?php
// health.php - Lightweight health check endpoint

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed'
    ]);
    exit();
}

require_once __DIR__ . '/includes/config.php';

$response = [
    'status' => 'ok',
    'service' => 'vle',
    'timestamp' => gmdate('c'),
    'checks' => [
        'database' => 'ok'
    ]
];

try {
    $conn = getDbConnection();
    $result = $conn->query('SELECT 1 AS db_ok');

    if (!$result) {
        throw new Exception('Database probe query failed');
    }
} catch (Throwable $e) {
    error_log('Health check database error: ' . $e->getMessage());

    $response['status'] = 'degraded';
    $response['checks']['database'] = 'unreachable';

    http_response_code(503);
    echo json_encode($response);
    exit();
}

http_response_code(200);
echo json_encode($response);
