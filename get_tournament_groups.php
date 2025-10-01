<?php
// get_tournament_groups.php - Fetches all round robin groups and matches for a tournament

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to safely encode JSON
function safe_json_encode($data)
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo safe_json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// Get tournament_id from query parameter
$tournament_id = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : null;

// Get the database configuration
$db_config = require 'db_config.php';

try {
    if ($tournament_id === null) {
        throw new Exception('tournament_id query parameter is required');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // First get the tournament details
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    // Get all groups for this tournament
    $stmt = $pdo->prepare("SELECT * FROM round_robin_groups WHERE tournament_id = ? ORDER BY name");
    $stmt->execute([$tournament_id]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($groups) === 0) {
        // No groups exist yet
        echo safe_json_encode([
            'success' => true,
            'data' => [
                'tournament' => $tournament,
                'groups' => []
            ]
        ]);
        exit();
    }
    
    // Prepare the response data structure
    $response_data = [
        'tournament' => $tournament,
        'groups' => []
    ];
    
    // For each group, get teams, matches, and standings
    foreach ($groups as $group) {
        $group_data = [
            'id' => $group['id'],
            'name' => $group['name'],
            'is_primary' => (bool)$group['is_primary'],
            'teams' => [],
            'matches' => [],
            'standings' => []
        ];
        
        // Get teams in this group
        $stmt = $pdo->prepare("
            SELECT t.* 
            FROM teams t
            JOIN round_robin_group_teams gt ON t.id = gt.team_id
            WHERE gt.group_id = ?
            ORDER BY t.name
        ");
        $stmt->execute([$group['id']]);
        $group_data['teams'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all matches for this group, grouped by round
        $stmt = $pdo->prepare("
            SELECT 
                m.*,
                t1.name as team1_name,
                t1.logo as team1_logo,
                t2.name as team2_name,
                t2.logo as team2_logo
            FROM round_robin_matches m
            JOIN teams t1 ON m.team1_id = t1.id
            JOIN teams t2 ON m.team2_id = t2.id
            WHERE m.group_id = ?
            ORDER BY m.round_number, m.id
        ");
        $stmt->execute([$group['id']]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group matches by round
        $rounds = [];
        foreach ($matches as $match) {
            $round_number = $match['round_number'];
            if (!isset($rounds[$round_number])) {
                $rounds[$round_number] = [];
            }
            $rounds[$round_number][] = $match;
        }
        $group_data['matches'] = $rounds;
        
        // Get standings for this group
        $stmt = $pdo->prepare("
            SELECT 
                s.*,
                t.name as team_name,
                t.logo as team_logo
            FROM round_robin_standings s
            JOIN teams t ON s.team_id = t.id
            WHERE s.group_id = ?
            ORDER BY s.position ASC, s.points DESC, (s.goals_for - s.goals_against) DESC, s.goals_for DESC
        ");
        $stmt->execute([$group['id']]);
        $group_data['standings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response_data['groups'][] = $group_data;
    }
    
    // Return the complete data
    echo safe_json_encode([
        'success' => true,
        'data' => $response_data
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo safe_json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo safe_json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}