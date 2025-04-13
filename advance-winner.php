<?php
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->match_id)) {
        throw new Exception('Match ID is required');
    }

    $pdo->beginTransaction();

    // Get current match details with tournament info
    $stmt = $pdo->prepare("
        SELECT m.*, t.nombre_maximum 
        FROM matches m 
        JOIN tournaments t ON m.tournament_id = t.id 
        WHERE m.id = ?
    ");
    $stmt->execute([$data->match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        throw new Exception('Match not found');
    }

    // Get single participant
    $stmt = $pdo->prepare("
        SELECT * FROM match_participants 
        WHERE match_id = ? 
        ORDER BY id ASC
    ");
    $stmt->execute([$data->match_id]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($participants) !== 1) {
        throw new Exception('Invalid number of participants');
    }
    
    $participant = $participants[0];

    // Calculate total rounds
    $totalRounds = ceil(log($match['nombre_maximum'], 2));
    $currentRound = intval($match['tournament_round_text']);
    
    // Check if this is the final round
    if ($currentRound >= $totalRounds) {
        // This is the final round - declare winner
        $stmt = $pdo->prepare("
            UPDATE match_participants 
            SET is_winner = 1, 
                status = 'PLAYED',
                result_text = '1'
            WHERE match_id = ? AND id = ?
        ");
        $stmt->execute([$match['id'], $participant['id']]);

        // Update current match status and scores
        $stmt = $pdo->prepare("
            UPDATE matches 
            SET state = 'SCORE_DONE', 
                winner_id = ?,
                score1 = 1,
                score2 = 0
            WHERE id = ?
        ");
        $stmt->execute([$participant['participant_id'], $match['id']]);

        // Update tournament status
        $stmt = $pdo->prepare("
            UPDATE tournaments 
            SET status = 'Terminé' 
            WHERE id = ?
        ");
        $stmt->execute([$match['tournament_id']]);

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Tournament winner declared',
            'winner' => [
                'name' => $participant['name'],
                'id' => $participant['participant_id']
            ]
        ]);
        exit;
    }

    $nextRound = $currentRound + 1;
    $currentPosition = intval($match['position']);
    
    // Calculate next round position using paired progression
    $nextPosition = floor($currentPosition / 2);
    
    // Get next round match that corresponds to this position
    $stmt = $pdo->prepare("
        SELECT * FROM matches 
        WHERE tournament_id = ? 
        AND tournament_round_text = ? 
        AND position = ?
    ");
    $stmt->execute([$match['tournament_id'], $nextRound, $nextPosition]);
    $nextMatch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$nextMatch) {
        throw new Exception('Next match not found');
    }

    // Check if participant already exists
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM match_participants 
        WHERE match_id = ? AND participant_id = ?
    ");
    $stmt->execute([$nextMatch['id'], $participant['participant_id']]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

    if (!$exists) {
        // Add participant to next match
        $stmt = $pdo->prepare("
            INSERT INTO match_participants 
            (match_id, participant_id, name, picture, status, result_text)
            VALUES (?, ?, ?, ?, 'NOT_PLAYED', '0')
        ");
        $stmt->execute([
            $nextMatch['id'],
            $participant['participant_id'],
            $participant['name'],
            $participant['picture']
        ]);

        // Update the current match status
        $stmt = $pdo->prepare("
            UPDATE matches 
            SET state = 'SCORE_DONE',
                winner_id = ?,
                score1 = CASE 
                    WHEN position % 2 = 0 THEN 1 
                    ELSE 0 
                END,
                score2 = CASE 
                    WHEN position % 2 = 0 THEN 0 
                    ELSE 1 
                END
            WHERE id = ?
        ");
        $stmt->execute([$participant['participant_id'], $match['id']]);

        // Update participant status
        $stmt = $pdo->prepare("
            UPDATE match_participants 
            SET is_winner = 1,
                status = 'PLAYED',
                result_text = '1'
            WHERE match_id = ? AND id = ?
        ");
        $stmt->execute([$match['id'], $participant['id']]);
    }

        // Update current match status and scores
        $stmt = $pdo->prepare("
            UPDATE matches 
            SET state = 'SCORE_DONE',
                score1 = CASE 
                    WHEN position % 2 = 0 THEN 1 
                    ELSE 0 
                END,
                score2 = CASE 
                    WHEN position % 2 = 0 THEN 0 
                    ELSE 1 
                END,
                winner_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$participant['participant_id'], $match['id']]);

        // Update bracket position for next match if needed
        if ($currentPosition % 2 == 0) {
            $stmt = $pdo->prepare("
                UPDATE matches 
                SET bracket_position = ? 
                WHERE id = ?
            ");
            $stmt->execute([0, $nextMatch['id']]);
        }
    

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Participant advanced successfully',
        'next_match' => [
            'id' => $nextMatch['id'],
            'round' => $nextRound,
            'position' => $nextPosition
        ]
    ]);

} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>