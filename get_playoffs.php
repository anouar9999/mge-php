<?php
/**
 * get_playoffs.php - Fetches playoff bracket data for a tournament
 * 
 * This script retrieves all playoff matches for a tournament and 
 * structures them into a format suitable for client-side bracket visualization.
 */

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
    
    // Check if playoffs exist for this tournament
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM matches 
        WHERE tournament_id = ? AND bracket_type = 'winners'
    ");
    $stmt->execute([$tournament_id]);
    $playoffsExist = (bool)$stmt->fetchColumn();
    
    if (!$playoffsExist) {
        echo safe_json_encode([
            'success' => true,
            'data' => [
                'tournament' => $tournament,
                'has_playoffs' => false
            ]
        ]);
        exit();
    }
    
    // Get all playoff matches grouped by round
    $stmt = $pdo->prepare("
        SELECT 
            m.*,
            CASE 
                WHEN m.tournament_round_text = 'Final' THEN 0
                WHEN m.tournament_round_text = 'Semi-finals' THEN 1
                WHEN m.tournament_round_text = 'Quarter-finals' THEN 2
                WHEN m.tournament_round_text = 'Round of 16' THEN 3
                WHEN m.tournament_round_text = 'Round of 32' THEN 4
                ELSE 5
            END as round_sort_order
        FROM matches m
        WHERE m.tournament_id = ? AND m.bracket_type = 'winners'
        ORDER BY round_sort_order DESC, m.position
    ");
    $stmt->execute([$tournament_id]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get participants for each match
    $matchParticipants = [];
    $stmt = $pdo->prepare("
        SELECT * FROM match_participants 
        WHERE match_id = ?
    ");
    
    foreach ($matches as $match) {
        $stmt->execute([$match['id']]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get team details for each participant
        foreach ($participants as $key => $participant) {
            $teamStmt = $pdo->prepare("
                SELECT t.* FROM teams t
                WHERE t.id = ?
            ");
            $teamStmt->execute([$participant['participant_id']]);
            $team = $teamStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($team) {
                $participants[$key]['team_data'] = $team;
            }
        }
        
        $matchParticipants[$match['id']] = $participants;
    }
    
    // Group matches by round for bracket structure
    $rounds = [];
    $roundsMap = [];
    
    foreach ($matches as $match) {
        $roundName = $match['tournament_round_text'];
        if (!isset($roundsMap[$roundName])) {
            $roundsMap[$roundName] = count($rounds);
            $rounds[] = [];
        }
        
        $roundIndex = $roundsMap[$roundName];
        
        // Format match for response
        $formattedMatch = [
            'id' => $match['id'],
            'name' => "Match " . ($match['position'] + 1),
            'nextMatchId' => $match['next_match_id'],
            'tournamentRoundText' => $roundName,
            'startTime' => $match['start_time'],
            'state' => $match['state'],
            'score1' => $match['score1'],
            'score2' => $match['score2'],
            'participants' => []
        ];
        
        // Add participants to match
        if (isset($matchParticipants[$match['id']])) {
            foreach ($matchParticipants[$match['id']] as $index => $participant) {
                $isWinner = $match['winner_id'] === $participant['participant_id'];
                
                $formattedMatch['participants'][] = [
                    'id' => $participant['participant_id'],
                    'name' => $participant['name'],
                    'picture' => $participant['picture'],
                    'result_text' => $participant['result_text'],
                    'is_winner' => $isWinner,
                    'status' => $participant['status']
                ];
            }
        }
        
        $rounds[$roundIndex][] = $formattedMatch;
    }
    
    // Sort rounds in reverse order (Final first, then Semi-finals, etc.)
    // This is a typical visualization preference for tournament brackets
    $rounds = array_reverse($rounds);
    
    // Return the complete data
    echo safe_json_encode([
        'success' => true,
        'data' => [
            'tournament' => $tournament,
            'has_playoffs' => true,
            'bracket' => [
                'rounds' => $rounds
            ]
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