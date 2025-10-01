<?php
// delete_tournament.php

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Access-Control-Allow-Headers, Content-Type, Access-Control-Allow-Methods, Authorization, X-Requested-With');

// Include database configuration
$db_config = require_once 'db_config.php';

if (!isset($db_config['host']) || !isset($db_config['db']) || !isset($db_config['user']) || !isset($db_config['pass'])) {
    echo json_encode(['success' => false, 'message' => 'Database configuration is incomplete']);
    exit;
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->tournament_id)) {
    echo json_encode(['success' => false, 'message' => 'Tournament ID is required']);
    exit;
}

$tournament_id = intval($data->tournament_id);

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Start a transaction
    $pdo->beginTransaction();

    // Delete related records in tournament_registrations table
    $stmt = $pdo->prepare("DELETE FROM tournament_registrations WHERE tournament_id = :tournament_id");
    $stmt->execute(['tournament_id' => $tournament_id]);



    // Delete the tournament
    $stmt = $pdo->prepare("DELETE FROM tournaments WHERE id = :tournament_id");
    $stmt->execute(['tournament_id' => $tournament_id]);

    // Commit the transaction
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Tournament deleted successfully']);
} catch (PDOException $e) {
    // Rollback the transaction if an error occurred
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
