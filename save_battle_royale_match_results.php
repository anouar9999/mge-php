<?php
// save_battle_royale_match_results.php

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

// Enforce POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Load database configuration
$db_config = require 'db_config.php';

// Get JSON data from request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Log the incoming data for debugging
error_log('Received data in save_battle_royale_match_results.php: ' . print_r($data, true));

// Validate required data
if (!$data || !isset($data['tournament_id']) || !isset($data['results']) || empty($data['results'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit();
}

$tournament_id = intval($data['tournament_id']);
$match_id = isset($data['match_id']) ? intval($data['match_id']) : 0;
$results = $data['results'];

try {
    // Database connection
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], $options);
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Verify tournament exists
    $tournamentSql = "SELECT id, bracket_type FROM tournaments WHERE id = ?";
    $tournamentStmt = $pdo->prepare($tournamentSql);
    $tournamentStmt->execute([$tournament_id]);
    $tournament = $tournamentStmt->fetch();
    
    if (!$tournament) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tournament not found']);
        exit();
    }
    
    // Update tournament bracket_type if needed
    if ($tournament['bracket_type'] !== 'Battle Royale') {
        $updateTournamentSql = "UPDATE tournaments SET bracket_type = 'Battle Royale' WHERE id = ?";
        $updateTournamentStmt = $pdo->prepare($updateTournamentSql);
        $updateTournamentStmt->execute([$tournament_id]);
    }
    
    // Create new match if match_id is not provided
    if ($match_id <= 0) {
        $createMatchSql = "
            INSERT INTO matches 
            (tournament_id, tournament_round_text, start_time, state, match_state) 
            VALUES (?, 'Battle Royale', NOW(), 'SCORE_DONE', 'completed')
        ";
        $createMatchStmt = $pdo->prepare($createMatchSql);
        $createMatchStmt->execute([$tournament_id]);
        $match_id = $pdo->lastInsertId();
    }
    
    // Get or create battle royale settings
    $settingsSql = "
        SELECT id, kill_points, placement_points_distribution, match_count
        FROM battle_royale_settings 
        WHERE tournament_id = ?
    ";
    $settingsStmt = $pdo->prepare($settingsSql);
    $settingsStmt->execute([$tournament_id]);
    $settings = $settingsStmt->fetch();
    
    if (!$settings) {
        // Default settings
        $defaultPointsDistribution = [
            "1" => 15, "2" => 12, "3" => 10, "4" => 8, "5" => 6,
            "6" => 4, "7" => 2, "8" => 1
        ];
        $kill_points = 1;
        $match_count = 1;
        
        // Insert default settings
        $createSettingsSql = "
            INSERT INTO battle_royale_settings 
            (tournament_id, kill_points, placement_points_distribution, match_count) 
            VALUES (?, ?, ?, ?)
        ";
        $createSettingsStmt = $pdo->prepare($createSettingsSql);
        $createSettingsStmt->execute([
            $tournament_id, 
            $kill_points, 
            json_encode($defaultPointsDistribution), 
            $match_count
        ]);
        
        $settings = [
            'id' => $pdo->lastInsertId(),
            'kill_points' => $kill_points,
            'placement_points_distribution' => $defaultPointsDistribution,
            'match_count' => $match_count
        ];
    } else {
        $settings['placement_points_distribution'] = json_decode($settings['placement_points_distribution'], true);
    }
    
    // Delete existing results for this match
    $deleteResultsSql = "DELETE FROM battle_royale_match_results WHERE match_id = ?";
    $deleteResultsStmt = $pdo->prepare($deleteResultsSql);
    $deleteResultsStmt->execute([$match_id]);
    
    // Insert new results
    $insertResultSql = "
        INSERT INTO battle_royale_match_results 
        (match_id, team_id, position, kills, placement_points) 
        VALUES (?, ?, ?, ?, ?)
    ";
    $insertResultStmt = $pdo->prepare($insertResultSql);
    
    foreach ($results as $result) {
        $teamId = intval($result['team_id']);
        $position = intval($result['position']);
        $kills = intval($result['kills']);
        
        // Skip if no position is set or team_id is invalid
        if ($position <= 0 || $teamId <= 0) {
            continue;
        }
        
        // Calculate placement points
        $placementPoints = 0;
        $positionStr = (string)$position;
        if (isset($settings['placement_points_distribution'][$positionStr])) {
            $placementPoints = $settings['placement_points_distribution'][$positionStr];
        }
        
        // Calculate kill points (kills * points per kill)
        $killPoints = $kills * $settings['kill_points'];
        
        // Insert the result
        $insertResultStmt->execute([
            $match_id,
            $teamId,
            $position,
            $killPoints, // Store calculated kill points
            $placementPoints
        ]);
    }
    
    // Update match count in settings
    $countMatchesSql = "
        SELECT COUNT(DISTINCT m.id) as match_count
        FROM matches m 
        WHERE m.tournament_id = ? AND m.state = 'SCORE_DONE'
    ";
    $countMatchesStmt = $pdo->prepare($countMatchesSql);
    $countMatchesStmt->execute([$tournament_id]);
    $matchCountResult = $countMatchesStmt->fetch();
    
    if ($matchCountResult) {
        $updateMatchCountSql = "
            UPDATE battle_royale_settings
            SET match_count = ?
            WHERE id = ?
        ";
        $updateMatchCountStmt = $pdo->prepare($updateMatchCountSql);
        $updateMatchCountStmt->execute([$matchCountResult['match_count'], $settings['id']]);
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Match results saved successfully',
        'match_id' => $match_id
    ]);

} catch (PDOException $e) {
    // Rollback transaction on error
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    
    // Log the error
    error_log('Database error in save_battle_royale_match_results.php: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    
    // Log the error
    error_log('General error in save_battle_royale_match_results.php: ' . $e->getMessage());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}