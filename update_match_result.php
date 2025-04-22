// update_match_result.php - Handles updating round robin match results

<?php
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo safe_json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// Get the database configuration
$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate request data
    if (!isset($_GET['match_id']) || !is_numeric($_GET['match_id'])) {
        throw new Exception('Invalid match ID');
    }
    
    $match_id = (int)$_GET['match_id'];
    
    if (!isset($data['team1_score']) || !is_numeric($data['team1_score']) || 
        !isset($data['team2_score']) || !is_numeric($data['team2_score'])) {
        throw new Exception('Invalid scores. Both team scores must be provided and must be numeric.');
    }
    
    $team1_score = (int)$data['team1_score'];
    $team2_score = (int)$data['team2_score'];
    
    // Validate scores are non-negative
    if ($team1_score < 0 || $team2_score < 0) {
        throw new Exception('Scores cannot be negative');
    }
    
    // Start a transaction for data consistency
    $pdo->beginTransaction();
    
    // Get the match details
    $stmt = $pdo->prepare("
        SELECT m.*, t1.id as team1_id, t2.id as team2_id, 
               t1.name as team1_name, t2.name as team2_name,
               g.id as group_id, g.tournament_id
        FROM round_robin_matches m
        JOIN teams t1 ON m.team1_id = t1.id
        JOIN teams t2 ON m.team2_id = t2.id
        JOIN round_robin_groups g ON m.group_id = g.id
        WHERE m.id = ?
    ");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$match) {
        throw new Exception('Match not found');
    }
    
    // Check if match is already completed
    if ($match['status'] === 'completed') {
        throw new Exception('Match is already completed. Please reset the match first if you want to update the result.');
    }
    
    // Determine the winner
    $winner_id = null;
    if ($team1_score > $team2_score) {
        $winner_id = $match['team1_id'];
    } else if ($team2_score > $team1_score) {
        $winner_id = $match['team2_id'];
    }
    // If scores are equal, winner_id remains null (draw)
    
    // Update the match result
    $stmt = $pdo->prepare("
        UPDATE round_robin_matches
        SET team1_score = ?, team2_score = ?, winner_id = ?, status = 'completed', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$team1_score, $team2_score, $winner_id, $match_id]);
    
    // Update standings
    // Note: The database trigger after_round_robin_match_update will handle this automatically
    // if you have the trigger set up. If not, we'll need to update standings manually.
    
    // Check if trigger exists
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.triggers 
        WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = 'after_round_robin_match_update'
    ");
    $stmt->execute([$db_config['db']]);
    $trigger_exists = (bool)$stmt->fetchColumn();
    
    // If trigger doesn't exist, manually update standings
    if (!$trigger_exists) {
        updateStandings($pdo, $match, $team1_score, $team2_score, $winner_id);
    }
    
    // Get updated standings
    $stmt = $pdo->prepare("
        SELECT s.*, t.name as team_name, t.logo as team_logo
        FROM round_robin_standings s
        JOIN teams t ON s.team_id = t.id
        WHERE s.tournament_id = ? AND s.group_id = ?
        ORDER BY s.points DESC, (s.goals_for - s.goals_against) DESC, s.goals_for DESC
    ");
    $stmt->execute([$match['tournament_id'], $match['group_id']]);
    $standings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Update positions in standings based on the order
    for ($i = 0; $i < count($standings); $i++) {
        $stmt = $pdo->prepare("
            UPDATE round_robin_standings
            SET position = ?
            WHERE id = ?
        ");
        $stmt->execute([$i + 1, $standings[$i]['id']]);
        
        // Update the position in our result array too
        $standings[$i]['position'] = $i + 1;
    }
    
    // Commit the transaction
    $pdo->commit();
    
    // Return success response
    echo safe_json_encode([
        'success' => true,
        'message' => 'Match result updated successfully',
        'data' => [
            'match' => [
                'id' => $match_id,
                'team1_id' => $match['team1_id'],
                'team2_id' => $match['team2_id'],
                'team1_name' => $match['team1_name'],
                'team2_name' => $match['team2_name'],
                'team1_score' => $team1_score,
                'team2_score' => $team2_score,
                'winner_id' => $winner_id,
                'status' => 'completed'
            ],
            'updated_standings' => $standings
        ]
    ]);

} catch (PDOException $e) {
    // Rollback transaction on database error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo safe_json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // Rollback transaction on application error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(400);
    echo safe_json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Manually update standings when trigger doesn't exist
 *
 * @param PDO $pdo Database connection
 * @param array $match Match data
 * @param int $team1_score Team 1 score
 * @param int $team2_score Team 2 score
 * @param int|null $winner_id Winner team ID (null for draw)
 */
function updateStandings($pdo, $match, $team1_score, $team2_score, $winner_id) {
    $tournament_id = $match['tournament_id'];
    $group_id = $match['group_id'];
    $team1_id = $match['team1_id'];
    $team2_id = $match['team2_id'];
    
    // Update Team 1 standings
    updateTeamStanding($pdo, $tournament_id, $group_id, $team1_id, $team1_score, $team2_score, $winner_id === $team1_id);
    
    // Update Team 2 standings
    updateTeamStanding($pdo, $tournament_id, $group_id, $team2_id, $team2_score, $team1_score, $winner_id === $team2_id);
}

/**
 * Update a team's standing
 *
 * @param PDO $pdo Database connection
 * @param int $tournament_id Tournament ID
 * @param int $group_id Group ID
 * @param int $team_id Team ID
 * @param int $goals_for Goals scored by this team
 * @param int $goals_against Goals conceded by this team
 * @param bool $is_winner Whether this team won the match
 */
function updateTeamStanding($pdo, $tournament_id, $group_id, $team_id, $goals_for, $goals_against, $is_winner) {
    // First check if the standing record exists
    $stmt = $pdo->prepare("
        SELECT id FROM round_robin_standings
        WHERE tournament_id = ? AND group_id = ? AND team_id = ?
    ");
    $stmt->execute([$tournament_id, $group_id, $team_id]);
    $standing_id = $stmt->fetchColumn();
    
    if (!$standing_id) {
        // Create new standing record if it doesn't exist
        $stmt = $pdo->prepare("
            INSERT INTO round_robin_standings (
                tournament_id, group_id, team_id, matches_played, wins, draws, 
                losses, goals_for, goals_against, points, created_at, updated_at
            )
            VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $wins = $is_winner ? 1 : 0;
        $draws = ($is_winner === null) ? 1 : 0;
        $losses = ($is_winner === false) ? 1 : 0;
        $points = $wins * 3 + $draws * 1; // 3 points for win, 1 for draw
        
        $stmt->execute([
            $tournament_id, $group_id, $team_id, $wins, $draws, 
            $losses, $goals_for, $goals_against, $points
        ]);
    } else {
        // Update existing standing record
        $stmt = $pdo->prepare("
            UPDATE round_robin_standings
            SET matches_played = matches_played + 1,
                wins = wins + ?,
                draws = draws + ?,
                losses = losses + ?,
                goals_for = goals_for + ?,
                goals_against = goals_against + ?,
                points = points + ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $wins_inc = $is_winner ? 1 : 0;
        $draws_inc = ($is_winner === null) ? 1 : 0;
        $losses_inc = ($is_winner === false) ? 1 : 0;
        $points_inc = $wins_inc * 3 + $draws_inc * 1; // 3 points for win, 1 for draw
        
        $stmt->execute([
            $wins_inc, $draws_inc, $losses_inc, 
            $goals_for, $goals_against, $points_inc,
            $standing_id
        ]);
    }
}