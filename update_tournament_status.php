<?php
// update_tournament_status.php

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// Include database configuration
$db_config = require 'db_config.php';

try {
    // Connect to the database
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get JSON data from the request body
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['tournament_id']) || !isset($data['new_status'])) {
        throw new Exception('Missing tournament_id or new_status');
    }

    $tournament_id = $data['tournament_id'];
    $new_status = $data['new_status'];

    // Validate status
    $valid_statuses = ['Ouvert aux inscriptions', 'En cours', 'Terminé', 'Annulé'];
    if (!in_array($new_status, $valid_statuses)) {
        throw new Exception('Invalid status');
    }

    // Update the tournament status
    $stmt = $pdo->prepare("UPDATE tournaments SET status = :status WHERE id = :id");
    $result = $stmt->execute([
        ':status' => $new_status,
        ':id' => $tournament_id
    ]);

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Tournament status updated successfully'
        ]);
    } else {
        throw new Exception('Failed to update tournament status');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>