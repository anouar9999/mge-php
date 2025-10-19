<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

try {
    $db_config = require 'db_config.php';
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $tournament_id = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;

    if (!$tournament_id) {
        echo json_encode(['success' => false, 'message' => 'Tournament ID required']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, name, description FROM tournaments WHERE id = :id");
    $stmt->execute([':id' => $tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tournament) {
        echo json_encode([
            'success' => true,
            'tournament' => $tournament
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Tournament not found'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
