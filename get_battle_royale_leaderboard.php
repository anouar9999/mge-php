<?php
// get_battle_royale_leaderboard.php

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

// Get tournament ID from request parameters
$tournament_id = isset($_GET['tournament_id']) ? intval($_GET['tournament_id']) : 0;

if ($tournament_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid tournament ID']);
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
    
    // Verify tournament exists
    $tournamentSql = "SELECT id, name, bracket_type, game_id FROM tournaments WHERE id = ?";
    $tournamentStmt = $pdo->prepare($tournamentSql);
    $tournamentStmt->execute([$tournament_id]);
    $tournament = $tournamentStmt->fetch();
    
    if (!$tournament) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tournament not found']);
        exit();
    }
    
    // Get tournament teams (registered and accepted)
    $teamsSql = "
        SELECT 
            t.id, 
            t.name, 
            t.tag,
            t.logo, 
            t.banner,
            t.tier
        FROM teams t
        JOIN tournament_registrations tr ON t.id = tr.team_id
        WHERE tr.tournament_id = ? AND tr.status = 'accepted'
        ORDER BY t.name
    ";
    $teamsStmt = $pdo->prepare($teamsSql);
    $teamsStmt->execute([$tournament_id]);
    $teamsData = $teamsStmt->fetchAll();
    
    // Create team lookup array
    $teams = [];
    foreach ($teamsData as $team) {
        $teams[$team['id']] = [
            'team_id' => $team['id'],
            'team_name' => $team['name'],
            'team_tag' => $team['tag'],
            'team_image' => $team['logo'],
            'team_banner' => $team['banner'],
            'team_tier' => $team['tier'],
            'total_kills' => 0,
            'total_placement_points' => 0,
            'total_points' => 0,
            'matches_played' => 0,
            'highest_position' => null,
            'avg_position' => 0,
            'positions' => []
        ];
    }
    
    // Check if tournament has any battle royale matches
    $matchesSql = "
        SELECT m.id 
        FROM matches m
        WHERE m.tournament_id = ?
    ";
    $matchesStmt = $pdo->prepare($matchesSql);
    $matchesStmt->execute([$tournament_id]);
    $matches = $matchesStmt->fetchAll();
    
    if (count($matches) > 0) {
        // Get match IDs
        $matchIds = array_column($matches, 'id');
        
        // Get leaderboard data from battle_royale_match_results
        $leaderboardSql = "
            SELECT 
                br.team_id,
                br.match_id,
                br.position,
                br.kills,
                br.placement_points,
                (br.kills + br.placement_points) as match_points
            FROM battle_royale_match_results br
            WHERE br.match_id IN (" . implode(',', array_fill(0, count($matchIds), '?')) . ")
        ";
        $leaderboardStmt = $pdo->prepare($leaderboardSql);
        $leaderboardStmt->execute($matchIds);
        $results = $leaderboardStmt->fetchAll();
        
        // Process results
        foreach ($results as $result) {
            $teamId = $result['team_id'];
            
            // Skip if team not in our lookup (could be deleted or not registered anymore)
            if (!isset($teams[$teamId])) {
                continue;
            }
            
            // Update team stats
            $teams[$teamId]['total_kills'] += (int)$result['kills'];
            $teams[$teamId]['total_placement_points'] += (int)$result['placement_points'];
            $teams[$teamId]['total_points'] += (int)$result['match_points'];
            $teams[$teamId]['matches_played']++;
            $teams[$teamId]['positions'][] = (int)$result['position'];
            
            // Update highest position (lowest number is better)
            if ($teams[$teamId]['highest_position'] === null || (int)$result['position'] < $teams[$teamId]['highest_position']) {
                $teams[$teamId]['highest_position'] = (int)$result['position'];
            }
        }
        
        // Calculate average positions and clean up
        foreach ($teams as $teamId => $team) {
            if (count($team['positions']) > 0) {
                $teams[$teamId]['avg_position'] = array_sum($team['positions']) / count($team['positions']);
                $teams[$teamId]['avg_position'] = round($teams[$teamId]['avg_position'], 1);
            } else {
                $teams[$teamId]['avg_position'] = 0;
            }
        }
    }
    
    // Convert teams to indexed array and sort by total points
    $teamsArray = array_values($teams);
    usort($teamsArray, function($a, $b) {
        // Sort by total points (descending)
        if ($a['total_points'] != $b['total_points']) {
            return $b['total_points'] - $a['total_points'];
        }
        // If points tied, sort by kills (descending)
        if ($a['total_kills'] != $b['total_kills']) {
            return $b['total_kills'] - $a['total_kills'];
        }
        // If kills tied, sort by highest position (ascending)
        if ($a['highest_position'] != $b['highest_position']) {
            return $a['highest_position'] - $b['highest_position'];
        }
        // Lastly, sort by name
        return strcmp($a['team_name'], $b['team_name']);
    });
    
    // Get tournament settings
    $settingsSql = "
        SELECT kill_points, placement_points_distribution, match_count
        FROM battle_royale_settings 
        WHERE tournament_id = ?
    ";
    $settingsStmt = $pdo->prepare($settingsSql);
    $settingsStmt->execute([$tournament_id]);
    $settings = $settingsStmt->fetch();
    
    if (!$settings) {
        // Default settings if not found
        $settings = [
            'kill_points' => 1,
            'placement_points_distribution' => json_encode([
                "1" => 15, "2" => 12, "3" => 10, "4" => 8, "5" => 6,
                "6" => 4, "7" => 2, "8" => 1
            ]),
            'match_count' => count($matches)
        ];
    } else {
        $settings['placement_points_distribution'] = json_decode($settings['placement_points_distribution'], true);
    }
    
    // Return success response with data
    echo json_encode([
        'success' => true,
        'tournament' => [
            'id' => $tournament['id'],
            'name' => $tournament['name'],
            'bracket_type' => $tournament['bracket_type'],
            'game_id' => $tournament['game_id']
        ],
        'settings' => $settings,
        'matches_played' => count($matches),
        'teams_count' => count($teamsArray),
        'data' => $teamsArray
    ]);

} catch (PDOException $e) {
    // Log the error
    error_log('Database error in get_battle_royale_leaderboard.php: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    // Log the error
    error_log('General error in get_battle_royale_leaderboard.php: ' . $e->getMessage());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}