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
    $userId = $_GET['user_id'];

    // Fetch user statistics
    $stmt = $pdo->prepare("
        SELECT 
            u.username,
            u.email,
            u.points,
            u.rank,
            (SELECT COUNT(*) FROM tournament_registrations WHERE user_id = u.id) as tournaments_participated,
            (SELECT COUNT(*) FROM tournament_registrations tr
             JOIN matches m ON (tr.tournament_id = m.tournament_id AND (m.participant1_id = tr.user_id OR m.participant2_id = tr.user_id))
             WHERE tr.user_id = u.id AND m.winner_id = u.id) as tournaments_won
        FROM 
            users u
        WHERE 
            u.id = :user_id
    ");

    $stmt->execute([':user_id' => $userId]);
    $userStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userStats) {
        throw new Exception('User stats not found for user ID: ' . $userId);
    }

    outputJSON([
        'success' => true,
        'data' => [
            'username' => $userStats['username'],
            'email' => $userStats['email'],
            'points' => (int)$userStats['points'],
            'rank' => (int)$userStats['rank'],
            'tournamentsParticipated' => (int)$userStats['tournaments_participated'],
            'tournamentsWon' => (int)$userStats['tournaments_won']
        ]
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