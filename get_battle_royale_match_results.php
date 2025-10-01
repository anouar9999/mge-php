<?php
// get_battle_royale_match_results.php

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enforce GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Load database configuration
$db_config = require 'db_config.php';

// Get match_id from request parameters
$match_id = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;

if ($match_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid match ID']);
    exit();
}

try {
    // Database connection
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], $options);
    
    // Get match information
    $matchSql = "
        SELECT 
            m.id,
            m.tournament_id,
            m.start_time,
            m.state,
            t.name AS tournament_name
        FROM matches m
        JOIN tournaments t ON m.tournament_id = t.id
        WHERE m.id = ?
    ";
    $matchStmt = $pdo->prepare($matchSql);
    $matchStmt->execute([$match_id]);
    $match = $matchStmt->fetch();
    
    if (!$match) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Match not found']);
        exit();
    }
    
    // Get battle royale settings for this tournament
    $settingsSql = "
        SELECT kill_points, placement_points_distribution
        FROM battle_royale_settings 
        WHERE tournament_id = ?
    ";
    $settingsStmt = $pdo->prepare($settingsSql);
    $settingsStmt->execute([$match['tournament_id']]);
    $settings = $settingsStmt->fetch();
    
    $kill_points = 1; // Default if not found
    if ($settings) {
        $kill_points = (int)$settings['kill_points'];
    }
    
    // Get match results with team information
    $resultsSql = "
        SELECT 
            br.id,
            br.team_id,
            t.name AS team_name,
            t.logo AS team_logo,
            t.tag AS team_tag,
            br.position,
            br.kills,
            br.placement_points,
            (br.kills + br.placement_points) AS total_points
        FROM battle_royale_match_results br
        JOIN teams t ON br.team_id = t.id
        WHERE br.match_id = ?
        ORDER BY br.position, total_points DESC
    ";
    $resultsStmt = $pdo->prepare($resultsSql);
    $resultsStmt->execute([$match_id]);
    $results = $resultsStmt->fetchAll();
    
    // Convert stored kill points back to raw kill count for editing
    // If kill_points is > 1, we divide to get the original count
    if ($kill_points > 1) {
        foreach ($results as &$result) {
            $result['raw_kills'] = round($result['kills'] / $kill_points);
            // Keep the stored kill points as well
            $result['kill_points'] = $result['kills'];
        }
    } else {
        // If kill_points is 1, raw_kills equals kills
        foreach ($results as &$result) {
            $result['raw_kills'] = $result['kills'];
            $result['kill_points'] = $result['kills'];
        }
    }
    
    // Return success response with data
    echo json_encode([
        'success' => true,
        'match' => $match,
        'settings' => [
            'kill_points' => $kill_points
        ],
        'results' => $results
    ]);

} catch (PDOException $e) {
    // Log the error
    error_log('Database error in get_battle_royale_match_results.php: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    // Log the error
    error_log('General error in get_battle_royale_match_results.php: ' . $e->getMessage());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}