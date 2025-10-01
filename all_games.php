<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Prevent PHP from outputting HTML errors
ini_set('display_errors', 0);
error_reporting(0);

try {
    $db_config = require 'db_config.php';

    // Connect to the database
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4" . 
        (isset($db_config['port']) ? ";port={$db_config['port']}" : ""),
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Determine which tournament table to use - first check tournaments_old table
    $stmt = $pdo->query("SHOW TABLES LIKE 'tournaments_old'");
    $useOldTable = $stmt->rowCount() > 0;
    
    $tableName = $useOldTable ? 'tournaments_old' : 'tournaments';
    
    // Build query based on available tables - now including game information
    $query = "
        SELECT 
            *
        FROM games g
        
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

   

    echo json_encode([
        
        'success' => true, 
        'games' => $tournaments,
    ]);

} catch (Exception $e) {
    // Log error to server log but don't expose details
    error_log('Tournament fetch error: ' . $e->getMessage());
    
    // Return a clean error response
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch tournaments',
        'error' => $e->getMessage()
    ]);
}
?>