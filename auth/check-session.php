<?php
// api/auth/check-session.php - NEW FILE (OPTIONAL - Quick session check)
$db_config = require 'db_config.php';

$allowedOrigins = ["http://{$db_config['api']['host']}:3000", "http://{$db_config['api']['host']}:5173"];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

// Simple boolean check if user is authenticated
echo json_encode([
    'authenticated' => isset($_SESSION['user_id']) && isset($_SESSION['user_data']),
    'user_id' => $_SESSION['user_id'] ?? null
]);