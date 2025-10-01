<?php
// Start output buffering
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to safely output JSON
function outputJSON($data) {
    $output = ob_get_clean();
    if (!empty($output)) {
        error_log("Unexpected output before JSON: " . $output);
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only process GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    outputJSON(['success' => false, 'message' => 'Method Not Allowed']);
}

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get user ID from query parameter
    if (!isset($_GET['user_id'])) {
        throw new Exception('User ID not provided');
    }
    $userId = (int)$_GET['user_id'];

    // Fetch basic user information
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.username,
            u.email,
            u.type,
            u.points,
            u.rank,
            u.is_verified,
            u.created_at,
            u.bio,
            u.avatar
        FROM 
            users u
        WHERE 
            u.id = :user_id
    ");

    $stmt->execute([':user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found for ID: ' . $userId);
    }

    // Get tournament registrations count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as tournaments_participated
        FROM tournament_registrations 
        WHERE user_id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    $tournamentStats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get recent activity
    $stmt = $pdo->prepare("
        SELECT 
            action,
            timestamp,
            details,
            ip_address
        FROM 
            activity_log
        WHERE 
            user_id = :user_id
        ORDER BY timestamp DESC
        LIMIT 10
    ");
    $stmt->execute([':user_id' => $userId]);
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare response data
    $responseData = [
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'avatar' => $user['avatar']
        ],
      
    ];

    outputJSON([
        'success' => true,
        'data' => $responseData
    ]);

} catch (Exception $e) {
    http_response_code(400);
    outputJSON([
        'success' => false, 
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>