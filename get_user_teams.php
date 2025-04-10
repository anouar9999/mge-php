<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$db_config = require 'db_config.php';

try {
    if (!isset($_GET['user_id'])) {
        throw new Exception('User ID is required');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $userId = filter_var($_GET['user_id'], FILTER_VALIDATE_INT);
    if ($userId === false) {
        throw new Exception('Invalid user ID format');
    }

    $stmt = $pdo->prepare("
        SELECT t.id, t.name, t.image,
        (SELECT COUNT(*) FROM team_members WHERE team_id = t.id) as member_count
        FROM teams t
        WHERE t.owner_id = :user_id
        ORDER BY t.name
    ");
    
    $stmt->execute([':user_id' => $userId]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'teams' => $teams
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>