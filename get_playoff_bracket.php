<?php
// get_playoff_bracket.php - Fetches playoff bracket data for a tournament

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

// Parse URL parameters
$url_parts = parse_url($_SERVER['REQUEST_URI']);
$path = $url_parts['path'];
$path_segments = explode('/', $path);

// Extract tournament_id from URL path
// Expected format: /api/tournaments/{tournament_id}/playoffs
$tournament_id = null;
foreach ($path_segments as $i => $segment) {
    if ($segment === 'tournaments' && isset($path_segments[$i+1]) && is_numeric($path_segments[$i+1])) {
        $tournament_id = (int)$path_segments[$i+1];
        break;
    }
}

// Get the database configuration
$db_config = require 'db_config.php';

try {
    if ($tournament_id === null) {
        throw new Exception('Tournament ID is required in the URL path (/api/tournaments/{tournament_id}/playoffs)');
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
    
    // Check if playoff matches exist for this tournament
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM matches 
        WHERE tournament_id = ? AND tournament_round_text LIKE 'Playoff%'
    ");
    $stmt->execute([$tournament_id]);
    $playoff_match_count = (int)$stmt->fetchColumn();
    
    if ($playoff_match_count === 0) {
        // No playoff matches exist yet
        echo safe_json_encode([
            'success' => true,
            'data' => [
                'tournament' => $tournament,
                'has_playoffs' => false,
                'message' => 'No playoff bracket has been created for this tournament yet'
            ]
        ]);
        exit();
    }
    
    // Get all playoff matches
    $stmt = $pdo->prepare("
        SELECT 
            m.*,
            SUBSTRING_INDEX(tournament_round_text, ' - ', -1) as round_name
        FROM matches m
        WHERE m.tournament_id = ? AND m.tournament_round_text LIKE 'Playoff%'
        ORDER BY m.tournament_round_text, m.position
    ");
    $stmt->execute([$tournament_id]);
    $playoff_matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get match participants
    $match_participants = [];
    
    // Get all match IDs
    $match_ids = array_column($playoff_matches, 'id');
    
    if (!empty($match_ids)) {
        $placeholders = str_repeat('?,', count($match_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT 
                mp.*,
                t.logo as team_logo
            FROM match_participants mp
            LEFT JOIN teams t ON mp.participant_id = t.id
            WHERE mp.match_id IN ($placeholders)
        ");
        $stmt->execute($match_ids);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group participants by match_id
        foreach ($participants as $participant) {
            $match_id = $participant['match_id'];
            if (!isset($match_participants[$match_id])) {
                $match_participants[$match_id] = [];
            }
            $match_participants[$match_id][] = $participant;
        }
    }
    
    // Get qualified teams from group stage
    $qualified_teams = [];
    $stmt = $pdo->prepare("
        SELECT 
            rrs.*, 
            t.name as team_name,
            t.logo as team_logo,
            rrg.name as group_name
        FROM round_robin_standings rrs
        JOIN teams t ON rrs.team_id = t.id
        JOIN round_robin_groups rrg ON rrs.group_id = rrg.id
        WHERE rrs.tournament_id = ? AND rrs.position <= 2
        ORDER BY rrg.name, rrs.position
    ");
    $stmt->execute([$tournament_id]);
    $qualified_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize matches by rounds
    $bracket_data = [];
    foreach ($playoff_matches as $match) {
        $round_name = $match['round_name'];
        
        if (!isset($bracket_data[$round_name])) {
            $bracket_data[$round_name] = [];
        }
        
        // Add participants to match data
        $match['participants'] = isset($match_participants[$match['id']]) ? $match_participants[$match['id']] : [];
        
        $bracket_data[$round_name][] = $match;
    }
    
    // Return the complete bracket data
    echo safe_json_encode([
        'success' => true,
        'data' => [
            'tournament' => $tournament,
            'has_playoffs' => true,
            'bracket' => $bracket_data,
            'qualified_teams' => $qualified_teams
        ]
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