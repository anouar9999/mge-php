<?php
// edit_team.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, OPTIONS'); // Added POST
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        // Debug logging
        error_log("Received data: " . print_r($data, true));
        
        if (!isset($data['id'])) { // Changed from team_id to id
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Team ID is required']);
            exit();
        }

        $allowedFields = [
            'name',
            'team_game',
            'description',
            'privacy_level',
            'division',
            'mmr',
            'win_rate',
            'regional_rank',
            'average_rank'
        ];

        $updates = array_filter($data, function($key) use ($allowedFields) {
            return in_array($key, $allowedFields);
        }, ARRAY_FILTER_USE_KEY);

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No valid fields to update']);
            exit();
        }

        $setClauses = array_map(function($field) {
            return "{$field} = ?";
        }, array_keys($updates));

        $query = "UPDATE teams SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $params = array_merge(array_values($updates), [$data['id']]); // Changed from team_id to id

        error_log("SQL Query: " . $query);
        error_log("Parameters: " . print_r($params, true));

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        echo json_encode([
            'success' => true, 
            'message' => 'Team updated successfully',
            'data' => array_merge($updates, ['id' => $data['id']])
        ]);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>