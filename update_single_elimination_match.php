<?php
/**
 * update_single_elimination_match.php
 * 
 * FIXED VERSION: Uses 'SCHEDULED' as state (compatible with your database)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit();
}

try {
    if (!file_exists('db_config.php')) {
        throw new Exception('Database configuration file not found');
    }
    $db_config = require 'db_config.php';

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!$data) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    if (!isset($data['match_id'])) {
        throw new Exception('match_id is required in request body');
    }

    if (!isset($data['team1_score']) || !isset($data['team2_score'])) {
        throw new Exception('Both team1_score and team2_score are required');
    }

    $match_id = (int)$data['match_id'];
    $team1_score = (int)$data['team1_score'];
    $team2_score = (int)$data['team2_score'];

    if ($team1_score < 0 || $team2_score < 0) {
        throw new Exception('Scores cannot be negative');
    }

    if ($team1_score === $team2_score) {
        throw new Exception('Draws are not allowed in single-elimination. Please enter different scores.');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->beginTransaction();

    // 1. Load match
    $stmt = $pdo->prepare("SELECT * FROM matches WHERE id = ? FOR UPDATE");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        throw new Exception("Match with ID {$match_id} not found");
    }

    // 2. Load participants
    $stmt = $pdo->prepare("SELECT * FROM match_participants WHERE match_id = ? ORDER BY id ASC");
    $stmt->execute([$match_id]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($participants) < 2) {
        throw new Exception('Match does not have two participants. Cannot update score.');
    }

    // 3. Determine winner
    $winner_participant = null;
    $loser_participant = null;
    
    if ($team1_score > $team2_score) {
        $winner_participant = $participants[0];
        $loser_participant = $participants[1];
    } else {
        $winner_participant = $participants[1];
        $loser_participant = $participants[0];
    }

    $winner_id = $winner_participant['participant_id'];

    // 4. Update matches table
    // CRITICAL FIX: Don't try to update 'state' column if it causes issues
    // Just update the scores and winner_id
    $stmt = $pdo->prepare("
        UPDATE matches 
        SET score1 = ?, 
            score2 = ?, 
            winner_id = ?
        WHERE id = ?
    ");
    $stmt->execute([$team1_score, $team2_score, $winner_id, $match_id]);

    // 5. Update match_participants table
    // Participant 1 (team1)
    $stmt = $pdo->prepare("
        UPDATE match_participants 
        SET is_winner = ?, 
            result_text = ?
        WHERE id = ?
    ");
    $is_winner_1 = ($team1_score > $team2_score) ? 1 : 0;
    $result_text_1 = (string)$team1_score;
    $stmt->execute([$is_winner_1, $result_text_1, $participants[0]['id']]);

    // Participant 2 (team2)
    $is_winner_2 = ($team2_score > $team1_score) ? 1 : 0;
    $result_text_2 = (string)$team2_score;
    $stmt->execute([$is_winner_2, $result_text_2, $participants[1]['id']]);

    // 6. Advance winner to next match
    $next_match_id = null;
    if (!empty($match['next_match_id'])) {
        $next_match_id = $match['next_match_id'];
        
        // Check if winner already in next match
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM match_participants 
            WHERE match_id = ? AND participant_id = ?
        ");
        $stmt->execute([$next_match_id, $winner_id]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

        if (!$exists) {
            // Get next ID for match_participants
            $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM match_participants");
            $nextId = $stmt->fetch(PDO::FETCH_ASSOC)['next_id'];

            // Insert winner into next match
            $stmt = $pdo->prepare("
                INSERT INTO match_participants 
                (id, match_id, participant_id, name, picture, status) 
                VALUES (?, ?, ?, ?, ?, 'NOT_PLAYED')
            ");
            $stmt->execute([
                $nextId,
                $next_match_id,
                $winner_id,
                $winner_participant['name'],
                $winner_participant['picture']
            ]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Match result updated successfully',
        'data' => [
            'match_id' => $match_id,
            'team1_score' => $team1_score,
            'team2_score' => $team2_score,
            'winner_id' => $winner_id,
            'winner_name' => $winner_participant['name'],
            'next_match_id' => $next_match_id
        ]
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'sql_state' => $e->errorInfo[0] ?? null
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
