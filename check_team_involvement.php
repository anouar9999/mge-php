<?php
function handleError($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit;
}
set_error_handler('handleError');

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db_config = require 'db_config.php';
    if (!$db_config) {
        throw new Exception('Database configuration not found');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get and validate input data
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['team_id']) || !isset($data['user_id'])) {
        throw new Exception('Missing required parameters');
    }

    $team_id = filter_var($data['team_id'], FILTER_VALIDATE_INT);
    $user_id = filter_var($data['user_id'], FILTER_VALIDATE_INT);

    if ($team_id === false || $user_id === false) {
        throw new Exception('Invalid parameters');
    }

    // Check team ownership
    $stmt = $pdo->prepare("
        SELECT owner_id 
        FROM teams 
        WHERE id = :team_id
    ");
    $stmt->execute(['team_id' => $team_id]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        throw new Exception('Team not found');
    }

    if ((int)$team['owner_id'] === $user_id) {
        echo json_encode([
            'success' => true,
            'is_involved' => true,
            'role' => 'owner'
        ]);
        exit;
    }

    // Check team membership
    $stmt = $pdo->prepare("
        SELECT tm.id, tm.role
        FROM team_members tm
        JOIN users u ON tm.name = u.username
        WHERE tm.team_id = :team_id 
        AND u.id = :user_id
        AND tm.is_active = 1
    ");
    $stmt->execute([
        'team_id' => $team_id,
        'user_id' => $user_id
    ]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($membership) {
        echo json_encode([
            'success' => true,
            'is_involved' => true,
            'role' => 'member'
        ]);
        exit;
    }

    // Check pending join requests
    $stmt = $pdo->prepare("
        SELECT id, status
        FROM team_join_requests
        WHERE team_id = :team_id
        AND name = (SELECT username FROM users WHERE id = :user_id)
        AND status = 'pending'
    ");
    $stmt->execute([
        'team_id' => $team_id,
        'user_id' => $user_id
    ]);
    $pendingRequest = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'is_involved' => $pendingRequest ? true : false,
        'role' => $pendingRequest ? 'pending' : null,
        'has_pending_request' => $pendingRequest ? true : false
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}