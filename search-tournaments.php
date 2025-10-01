<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db_config = require 'db_config.php';

try {
    if (!isset($_GET['query'])) {
        throw new Exception('Search query is required');
    }

    $query = trim($_GET['query']);
    if (empty($query)) {
        throw new Exception('Search query cannot be empty');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Search in relevant fields with FULLTEXT search
    $sql = "
        SELECT 
            t.*,
            MATCH(
                nom_des_qualifications,
                description_des_qualifications,
                type_de_jeu
            ) AGAINST (:query IN BOOLEAN MODE) as relevance
        FROM tournaments t
        WHERE MATCH(
            nom_des_qualifications,
            description_des_qualifications,
            type_de_jeu
        ) AGAINST (:query IN BOOLEAN MODE)
        ORDER BY relevance DESC
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['query' => $query]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'tournaments' => $results,
        'count' => count($results)
    ]);

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to search tournaments',
        'debug_message' => $e->getMessage()
    ]);
}