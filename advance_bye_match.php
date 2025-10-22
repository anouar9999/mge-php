<?php
/**
 * advance_bye_match.php
 * 
 * Manually advance a specific match if it has a bye (1 participant)
 * Can be triggered by button click in UI
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if (!file_exists('db_config.php')) {
        throw new Exception('Database configuration file not found');
    }
    $db_config = require 'db_config.php';

    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);
    
    if (!$data || !isset($data['match_id'])) {
        throw new Exception('match_id is required');
    }

    $match_id = (int)$data['match_id'];

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->beginTransaction();

    // Load match
    $stmt = $pdo->prepare("SELECT * FROM matches WHERE id = ?");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        throw new Exception('Match not found');
    }

    // Check if already processed
    if ($match['winner_id'] !== null) {
        throw new Exception('Match already has a winner');
    }

    // Load participants
    $stmt = $pdo->prepare("SELECT * FROM match_participants WHERE match_id = ?");
    $stmt->execute([$match_id]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $participantCount = count($participants);

    // Validate this is a bye
    if ($participantCount !== 1) {
        throw new Exception("Cannot auto-advance: match has {$participantCount} participants (need exactly 1 for bye)");
    }

    $participant = $participants[0];
    $winner_id = $participant['participant_id'];

    // Process the bye
    $stmt = $pdo->prepare("UPDATE matches SET winner_id = ?, score1 = 0, score2 = 0 WHERE id = ?");
    $stmt->execute([$winner_id, $match_id]);

    $stmt = $pdo->prepare("UPDATE match_participants SET is_winner = 1, result_text = 'BYE' WHERE id = ?");
    $stmt->execute([$participant['id']]);

    // Advance to next match
    $next_match_id = null;
    if (!empty($match['next_match_id'])) {
        $next_match_id = $match['next_match_id'];
        
        // Check if not already in next match
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM match_participants WHERE match_id = ? AND participant_id = ?");
        $stmt->execute([$next_match_id, $winner_id]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

        if (!$exists) {
            $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM match_participants");
            $nextId = $stmt->fetch(PDO::FETCH_ASSOC)['next_id'];

            $stmt = $pdo->prepare("INSERT INTO match_participants (id, match_id, participant_id, name, picture, status) VALUES (?, ?, ?, ?, ?, 'NOT_PLAYED')");
            $stmt->execute([$nextId, $next_match_id, $winner_id, $participant['name'], $participant['picture']]);
        }
    }

    // Now process any new byes created
    $additionalByes = processAllByesInBracket($pdo, $match['tournament_id']);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Bye processed successfully',
        'data' => [
            'match_id' => $match_id,
            'winner_id' => $winner_id,
            'winner_name' => $participant['name'],
            'next_match_id' => $next_match_id,
            'additional_byes_processed' => $additionalByes
        ]
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
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

/**
 * Process all remaining byes in bracket
 */
function processAllByesInBracket($pdo, $tournament_id) {
    $totalProcessed = 0;
    $maxIterations = 10;
    $iteration = 0;
    
    while ($iteration < $maxIterations) {
        $iteration++;
        
        $stmt = $pdo->prepare("
            SELECT 
                m.id as match_id,
                m.next_match_id
            FROM matches m
            LEFT JOIN match_participants mp ON m.id = mp.match_id
            WHERE m.tournament_id = ?
            AND m.winner_id IS NULL
            GROUP BY m.id
            HAVING COUNT(mp.id) = 1
            ORDER BY CAST(m.tournament_round_text AS UNSIGNED) ASC
        ");
        $stmt->execute([$tournament_id]);
        $byeMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($byeMatches)) {
            break;
        }

        foreach ($byeMatches as $byeMatch) {
            $match_id = $byeMatch['match_id'];
            
            $stmt = $pdo->prepare("SELECT * FROM match_participants WHERE match_id = ? LIMIT 1");
            $stmt->execute([$match_id]);
            $participant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$participant) continue;
            
            $winner_id = $participant['participant_id'];
            
            $stmt = $pdo->prepare("UPDATE matches SET winner_id = ?, score1 = 0, score2 = 0 WHERE id = ?");
            $stmt->execute([$winner_id, $match_id]);
            
            $stmt = $pdo->prepare("UPDATE match_participants SET is_winner = 1, result_text = 'BYE' WHERE id = ?");
            $stmt->execute([$participant['id']]);
            
            if (!empty($byeMatch['next_match_id'])) {
                $next_match_id = $byeMatch['next_match_id'];
                
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM match_participants WHERE match_id = ? AND participant_id = ?");
                $stmt->execute([$next_match_id, $winner_id]);
                $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                
                if (!$exists) {
                    $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM match_participants");
                    $nextId = $stmt->fetch(PDO::FETCH_ASSOC)['next_id'];
                    
                    $stmt = $pdo->prepare("INSERT INTO match_participants (id, match_id, participant_id, name, picture, status) VALUES (?, ?, ?, ?, ?, 'NOT_PLAYED')");
                    $stmt->execute([$nextId, $next_match_id, $winner_id, $participant['name'], $participant['picture']]);
                }
            }
            
            $totalProcessed++;
        }
    }
    
    return $totalProcessed;
}
