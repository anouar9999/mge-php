<?php
$db_config = require 'db_config.php';

header("Access-Control-Allow-Origin: http://{$db_config['api']['host']}:3000");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $data = json_decode(file_get_contents("php://input"));
    if (!isset($data->match_id) || !isset($data->score1) || !isset($data->score2)) {
        throw new Exception('Required fields missing');
    }

    // Start transaction
    $pdo->beginTransaction();

    // Get match details
    $match = getMatchDetails($pdo, $data->match_id);
    if (!$match) {
        throw new Exception('Match not found');
    }

    // Get participants
    $participants = getMatchParticipants($pdo, $data->match_id);
    if (count($participants) !== 2) {
        throw new Exception('Invalid match participants');
    }

    // Update match scores and winner
    updateMatchScore($pdo, $data->match_id, $data->score1, $data->score2);

    // Update participant results
    updateParticipantResults($pdo, $participants, $data->score1, $data->score2);

    // Find or create next round match
    $nextMatch = findOrCreateNextMatch($pdo, $match);
    if ($nextMatch) {
        // Progress winner to next match
        progressWinner($pdo, $match, $nextMatch, $data->score1, $data->score2);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function getMatchDetails($pdo, $matchId) {
    $query = "
        SELECT m.*, t.participation_type, t.id as tournament_id,
               CAST(m.tournament_round_text AS SIGNED) as round_number
        FROM matches m
        JOIN tournaments t ON m.tournament_id = t.id
        WHERE m.id = ?
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$matchId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function findOrCreateNextMatch($pdo, $currentMatch) {
    // Calculate next round number
    $nextRound = $currentMatch['round_number'] + 1;
    
    // Get all matches from current round
    $query = "
        SELECT id, position 
        FROM matches 
        WHERE tournament_id = ? 
        AND tournament_round_text = ? 
        ORDER BY position ASC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$currentMatch['tournament_id'], $currentMatch['round_number']]);
    $currentRoundMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Find current match position
    $currentPosition = 0;
    foreach ($currentRoundMatches as $index => $match) {
        if ($match['id'] == $currentMatch['id']) {
            $currentPosition = $match['position'];
            break;
        }
    }
    
    // Calculate next round position
    $nextPosition = floor($currentPosition / 2);
    
    // Try to find existing next round match with correct position
    $query = "
        SELECT *
        FROM matches
        WHERE tournament_id = ? 
        AND tournament_round_text = ?
        AND position = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$currentMatch['tournament_id'], $nextRound, $nextPosition]);
    $nextMatch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$nextMatch) {
        // Create new match for next round with correct position
        $query = "
            INSERT INTO matches (
                tournament_id,
                tournament_round_text,
                position,
                start_time,
                state
            ) VALUES (?, ?, ?, CURDATE(), 'SCHEDULED')
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $currentMatch['tournament_id'],
            $nextRound,
            $nextPosition
        ]);
        
        // Get the created match
        $query = "SELECT * FROM matches WHERE id = LAST_INSERT_ID()";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $nextMatch = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $nextMatch;
}


function getMatchParticipants($pdo, $matchId) {
    $query = "SELECT * FROM match_participants WHERE match_id = ? ORDER BY id";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$matchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateMatchScore($pdo, $matchId, $score1, $score2) {
    $query = "
        UPDATE matches 
        SET state = 'SCORE_DONE',
            score1 = ?,
            score2 = ?
        WHERE id = ?
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$score1, $score2, $matchId]);
}

function updateParticipantResults($pdo, $participants, $score1, $score2) {
    $query = "
        UPDATE match_participants 
        SET result_text = ?,
            is_winner = ?,
            status = 'PLAYED'
        WHERE id = ?
    ";
    
    $stmt = $pdo->prepare($query);
    
    // Participant 1
    $stmt->execute([
        $score1,
        $score1 > $score2 ? 1 : 0,
        $participants[0]['id']
    ]);
    
    // Participant 2
    $stmt->execute([
        $score2,
        $score2 > $score1 ? 1 : 0,
        $participants[1]['id']
    ]);
}

function progressWinner($pdo, $currentMatch, $nextMatch, $score1, $score2) {
    // Get winner based on scores
    $stmt = $pdo->prepare("SELECT * FROM match_participants WHERE match_id = ? ORDER BY id");
    $stmt->execute([$currentMatch['id']]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($participants)) return;
    
    // For matches with one participant, auto-advance that participant
    if (count($participants) === 1) {
        $winner = $participants[0];
    } 
    // For matches with two participants, use scores to determine winner
    else if (count($participants) === 2) {
        $winner = $score1 > $score2 ? $participants[0] : $participants[1];
    }

    if (isset($winner)) {
        // Insert winner into next match
        $stmt = $pdo->prepare("
            INSERT INTO match_participants (match_id, participant_id, name, picture, status) 
            VALUES (?, ?, ?, ?, 'NOT_PLAYED')
        ");
        $stmt->execute([
            $nextMatch['id'],
            $winner['participant_id'], 
            $winner['name'],
            $winner['picture']
        ]);
        
        // Update current match status
        $stmt = $pdo->prepare("UPDATE matches SET state = 'SCORE_DONE' WHERE id = ?");
        $stmt->execute([$currentMatch['id']]);
    }
}
function updateMatchStatus($pdo, $matchId, $status) {
    $query = "
        UPDATE matches 
        SET state = ?
        WHERE id = ?
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$status, $matchId]);
}
