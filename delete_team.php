<?php
// delete_team.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
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

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $data = json_decode(file_get_contents('php://input'), true);
        $teamId = $data['team_id'] ?? null;

        if (!$teamId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Team ID is required']);
            exit();
        }

        $pdo->beginTransaction();
        
        try {
            // Delete team members
            $stmt = $pdo->prepare("DELETE FROM team_members WHERE team_id = ?");
            $stmt->execute([$teamId]);
            
            // Delete join requests
            $stmt = $pdo->prepare("DELETE FROM team_join_requests WHERE team_id = ?");
            $stmt->execute([$teamId]);
            
            // Delete team
            $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ?");
            $stmt->execute([$teamId]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Team deleted successfully']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>