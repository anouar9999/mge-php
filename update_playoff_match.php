<?php
/**
 * update_playoff_match.php - Updates the result of a playoff match
 * 
 * This script updates match scores and advances winners to the next round
 * in a playoff bracket.
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

// Get match_id from query parameters
$match_id = isset($_GET['match_id']) ? $_GET['match_id'] : null;

// Get the database configuration
$db_config = require 'db_config.php';

try {
    if ($match_id === null) {
        throw new Exception('match_id is required as a query parameter');
    }

    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['team1_score']) || !isset($data['team2_score'])) {
        throw new Exception('Both team1_score and team2_score must be provided');
    }
    
    $team1_score = (int)$data['team1_score'];
    $team2_score = (int)$data['team2_score'];
    
    // Validate scores are non-negative
    if ($team1_score < 0 || $team2_score < 0) {
        throw new Exception('Scores cannot be negative');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Start a transaction for data consistency
    $pdo->beginTransaction();

    // Get the match details
    $stmt = $pdo->prepare("
        SELECT * FROM matches 
        WHERE id = ?
    ");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$match) {
        throw new Exception('Match not found');
    }
    
    // Get the match participants
    $stmt = $pdo->prepare("
        SELECT * FROM match_participants 
        WHERE match_id = ? 
        ORDER BY id
    ");
    $stmt->execute([$match_id]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($participants) < 2) {
        throw new Exception('Match does not have two participants');
    }
    
    // Determine the winner
    $winner_id = null;
    if ($team1_score > $team2_score) {
        $winner_id = $participants[0]['participant_id'];
    } elseif ($team2_score > $team1_score) {
        $winner_id = $participants[1]['participant_id'];
    }
    // If scores are equal, winner_id remains null (draw/tie)
    
    // Update the match result
    $stmt = $pdo->prepare("
        UPDATE matches
        SET score1 = ?, score2 = ?, winner_id = ?, state = 'SCORE_DONE', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$team1_score, $team2_score, $winner_id, $match_id]);
    
    // Update participant status and result text
    foreach ($participants as $index => $participant) {
        $is_winner = $participant['participant_id'] === $winner_id;
        $score = $index === 0 ? $team1_score : $team2_score;
        $opponent_score = $index === 0 ? $team2_score : $team1_score;
        
        $result_text = "{$score}-{$opponent_score}";
        $status = $is_winner ? 'PLAYED' : 'PLAYED';
        
        $stmt = $pdo->prepare("
            UPDATE match_participants
            SET is_winner = ?, result_text = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$is_winner ? 1 : 0, $result_text, $status, $participant['id']]);
    }
    
    // If there's a winner and a next match, advance the winner to the next match
    if ($winner_id && $match['next_match_id']) {
        // Get the winning team's details
        $stmt = $pdo->prepare("
            SELECT p.*, t.name as team_name, t.logo as team_logo FROM match_participants p
            JOIN teams t ON p.participant_id = t.id
            WHERE p.match_id = ? AND p.participant_id = ?
        ");
        $stmt->execute([$match_id, $winner_id]);
        $winner = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($winner) {
            // Check if the next match already has this participant
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM match_participants
                WHERE match_id = ? AND participant_id = ?
            ");
            $stmt->execute([$match['next_match_id'], $winner_id]);
            $participantExists = (bool)$stmt->fetchColumn();
            
            if (!$participantExists) {
                // Determine the slot (1 or 2) for this participant in the next match
                // This requires knowledge of bracket structure - for simple brackets,
                // even-numbered matches feed into slot 1, odd-numbered into slot 2
                $matchNumber = extractMatchNumber($match_id);
                $slot = ($matchNumber % 2 === 0) ? 1 : 2;
                
                // Check if there's already a participant in this slot
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM match_participants
                    WHERE match_id = ? 
                    ORDER BY id
                    LIMIT 1 OFFSET ?
                ");
                $offset = $slot - 1;
                $stmt->execute([$match['next_match_id'], $offset]);
                $slotFilled = (bool)$stmt->fetchColumn();
                
                if ($slotFilled) {
                    // Update existing participant in this slot
                    $stmt = $pdo->prepare("
                        UPDATE match_participants
                        SET participant_id = ?, name = ?, picture = ?, status = 'NOT_PLAYED', is_winner = 0, result_text = NULL
                        WHERE match_id = ?
                        ORDER BY id
                        LIMIT 1 OFFSET ?
                    ");
                    $stmt->execute([
                        $winner_id, 
                        $winner['team_name'], 
                        $winner['team_logo'], 
                        $match['next_match_id'],
                        $offset
                    ]);
                } else {
                    // Create new participant in this slot
                    $stmt = $pdo->prepare("
                        INSERT INTO match_participants (
                            match_id, participant_id, name, picture, status, created_at, updated_at
                        )
                        VALUES (?, ?, ?, ?, 'NOT_PLAYED', NOW(), NOW())
                    ");
                    $stmt->execute([
                        $match['next_match_id'],
                        $winner_id,
                        $winner['team_name'],
                        $winner['team_logo']
                    ]);
                }
            }
        }
    }
    
    // Commit the transaction
    $pdo->commit();
    
    // Return success response
    echo safe_json_encode([
        'success' => true,
        'message' => 'Match result updated successfully',
        'data' => [
            'match_id' => $match_id,
            'team1_score' => $team1_score,
            'team2_score' => $team2_score,
            'winner_id' => $winner_id,
            'next_match_id' => $match['next_match_id']
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
 * Extract the match number from a match ID
 *
 * @param string $matchId Match ID (format like 'tournament-playoffs-r1-m2')
 * @return int Match number
 */
function extractMatchNumber($matchId) {
    // Extract match number from ID like "tournament-playoffs-r1-m2"
    if (preg_match('/m(\d+)$/', $matchId, $matches)) {
        return (int)$matches[1];
    }
    
    // If matchId format is different or extraction fails, return 0
    // This will default to placing the team in slot 1
    return 0;
}