<?php
$db_config = require 'db_config.php';

// api/auth/me.php - NEW FILE (Create this file)
$allowedOrigins = ["http://{$db_config['api']['host']}:3000", "http://{$db_config['api']['host']}:5173"];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

session_start();

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_data'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Not authenticated'
        ]);
        exit();
    }

    // Optional: Check session expiry (if you want sessions to expire after X hours)
    $sessionLifetime = 24 * 60 * 60; // 24 hours in seconds
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $sessionLifetime) {
        // Session expired
        session_destroy();
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Session expired'
        ]);
        exit();
    }

    // Return user data from session
    echo json_encode([
        'success' => true,
        'user' => $_SESSION['user_data'],
        'authenticated' => true,
        'session_time_remaining' => $sessionLifetime - (time() - $_SESSION['login_time'])
    ]);

} catch (Exception $e) {
    error_log("Auth check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
?>
