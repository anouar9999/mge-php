<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Prevent PHP from outputting HTML errors
ini_set('display_errors', 0);
error_reporting(0);

try {
    // Validate tournament_id parameter
    if (!isset($_GET['tournament_id']) || !is_numeric($_GET['tournament_id'])) {
        throw new Exception('Invalid or missing tournament ID');
    }
    
    $tournament_id = intval($_GET['tournament_id']);
    
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
    
    // Build query to get game information based on tournament ID
    $query = "
        SELECT 
            g.id AS game_id,
            g.name AS game_name,
            g.slug AS game_slug,
            g.image AS game_image,
            g.publisher AS game_publisher,
            g.is_active AS game_is_active
        FROM $tableName t
        JOIN games g ON t.game_id = g.id
        WHERE t.id = :tournament_id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':tournament_id', $tournament_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$game) {
        throw new Exception('Game not found for the specified tournament ID');
    }
    
    // Process image path if exists
    if (isset($game['game_image']) && $game['game_image']) {
        // Make sure image URL is absolute if it's a relative path
        if (strpos($game['game_image'], 'http') !== 0 && strpos($game['game_image'], '//') !== 0) {
            // Only prepend the base URL if the image path doesn't already have it
            $baseUrl = rtrim(getenv('BASE_URL') ?: '', '/');
            $game['game_image'] = $baseUrl . '/' . ltrim($game['game_image'], '/');
        }
    }

    echo json_encode([
        'success' => true, 
        'game' => $game
    ]);

} catch (Exception $e) {
    // Log error to server log but don't expose details
    error_log('Game fetch error: ' . $e->getMessage());
    
    // Return a clean error response
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch game information',
        'error' => $e->getMessage()
    ]);
}
?>