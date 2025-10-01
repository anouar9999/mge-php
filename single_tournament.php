<?php
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $db_config = require 'db_config.php';

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if (!isset($_GET['slug'])) {
        throw new Exception('Slug parameter is required');
    }

    $slug = $_GET['slug'];

    // First get the tournament to ensure it exists and to get the game_id
    $tournamentQuery = "
        SELECT t.*, g.name as game_name, g.slug as game_slug, g.image as game_image, g.publisher as game_publisher
        FROM tournaments t 
        LEFT JOIN games g ON t.game_id = g.id
        WHERE t.slug = :slug
    ";

    $stmt = $pdo->prepare($tournamentQuery);
    $stmt->execute([':slug' => $slug]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        throw new Exception('Tournament not found');
    }

    // Extract game information
    $game = [
        'id' => $tournament['game_id'],
        'name' => $tournament['game_name'],
        'slug' => $tournament['game_slug'],
        'image' => $tournament['game_image'],
        'publisher' => $tournament['game_publisher']
    ];

    // Calculate tournament stats
    $tournament['max_spots'] = (int)$tournament['max_participants'];
    
    // Get registration count
    $registrationQuery = "
        SELECT COUNT(*) as count
        FROM tournament_registrations
        WHERE tournament_id = :tournament_id
        AND status IN ('pending', 'accepted')
    ";
    
    $stmt = $pdo->prepare($registrationQuery);
    $stmt->execute([':tournament_id' => $tournament['id']]);
    $registrationResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $registeredCount = (int)$registrationResult['count'];
    
    // Set spots remaining
    $tournament['registered_count'] = $registeredCount;
    $tournament['spots_remaining'] = max(0, $tournament['max_spots'] - $registeredCount);

    // Parse prize distribution if it exists
    if (!empty($tournament['prize_distribution'])) {
        $tournament['prize_distribution'] = json_decode($tournament['prize_distribution'], true);
    }

    echo json_encode([
        'success' => true,
        'tournament' => $tournament,
        'game' => $game
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>