<?php
/**
 * update_single_elimination_match.php
 *
 * Updates the result of a single-elimination match (bracket).
 * Accepts POST requests with JSON body { team1_score, team2_score } and a
 * query parameter match_id. It updates the `matches` and `match_participants`
 * tables, marks the match as completed and advances the winner to the next
 * match if applicable.
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

function safe_json_encode($data)
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo safe_json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

$match_id = isset($_GET['match_id']) ? $_GET['match_id'] : null;
$db_config = require 'db_config.php';

try {
    if ($match_id === null) {
        throw new Exception('match_id is required as a query parameter');
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['team1_score']) || !isset($data['team2_score'])) {
        throw new Exception('Both team1_score and team2_score must be provided');
    }

    $team1_score = (int)$data['team1_score'];
    $team2_score = (int)$data['team2_score'];

    if ($team1_score < 0 || $team2_score < 0) {
        throw new Exception('Scores cannot be negative');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->beginTransaction();

    // Load match
    $stmt = $pdo->prepare("SELECT * FROM matches WHERE id = ? FOR UPDATE");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        throw new Exception('Match not found');
    }

    // Prevent updating an already completed match
    if (isset($match['state']) && in_array(strtoupper($match['state']), ['SCORE_DONE', 'COMPLETED', 'finished', 'done'])) {
        throw new Exception('Match is already completed');
    }

    // Load participants
    $stmt = $pdo->prepare("SELECT * FROM match_participants WHERE match_id = ? ORDER BY id");
    $stmt->execute([$match_id]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($participants) < 2) {
        throw new Exception('Match does not have two participants');
    }

    // Determine winner participant_id
    $winner_id = null;
    if ($team1_score > $team2_score) {
        $winner_id = $participants[0]['participant_id'];
    } elseif ($team2_score > $team1_score) {
        $winner_id = $participants[1]['participant_id'];
    } else {
        // Single-elimination typically doesn't allow draws. Decide policy: treat as error.
        throw new Exception('Draws are not allowed in single-elimination matches');
    }

    // Update matches table
    $stmt = $pdo->prepare("UPDATE matches SET score1 = ?, score2 = ?, winner_id = ?, state = 'SCORE_DONE', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$team1_score, $team2_score, $winner_id, $match_id]);

    // Update participants (set result_text and is_winner)
    foreach ($participants as $index => $participant) {
        $is_winner = $participant['participant_id'] == $winner_id ? 1 : 0;
        $score = $index === 0 ? $team1_score : $team2_score;
        $opponent_score = $index === 0 ? $team2_score : $team1_score;
        $result_text = "{$score}-{$opponent_score}";

        $stmt = $pdo->prepare("UPDATE match_participants SET is_winner = ?, result_text = ?, status = 'PLAYED', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$is_winner, $result_text, $participant['id']]);
    }

    // Advance winner to next match if applicable
    if ($winner_id && !empty($match['next_match_id'])) {
        // Check if the participant already exists in next match
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM match_participants WHERE match_id = ? AND participant_id = ?");
        $stmt->execute([$match['next_match_id'], $winner_id]);
        $exists = (bool)$stmt->fetchColumn();

        if (!$exists) {
            // Insert into next match participants. Try to fill an empty slot if possible.
            // If there are already participants present, append as new participant.
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM match_participants WHERE match_id = ?");
            $stmt->execute([$match['next_match_id']]);
            $countNext = (int)$stmt->fetchColumn();

            if ($countNext < 2) {
                // Create participant record in next match
                $stmt = $pdo->prepare("INSERT INTO match_participants (match_id, participant_id, name, picture, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'NOT_PLAYED', NOW(), NOW())");

                // Try to fetch team name/logo (if participant is a team)
                $teamName = null;
                $teamLogo = null;
                $teamStmt = $pdo->prepare("SELECT name, logo FROM teams WHERE id = ?");
                $teamStmt->execute([$winner_id]);
                $teamRow = $teamStmt->fetch(PDO::FETCH_ASSOC);
                if ($teamRow) {
                    $teamName = $teamRow['name'];
                    $teamLogo = $teamRow['logo'];
                }

                $stmt->execute([$match['next_match_id'], $winner_id, $teamName, $teamLogo]);
            } else {
                // There's already 2 participants; add anyway (robustness) or update a placeholder slot
                $stmt = $pdo->prepare("INSERT INTO match_participants (match_id, participant_id, name, picture, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'NOT_PLAYED', NOW(), NOW())");
                $teamName = null;
                $teamLogo = null;
                $teamStmt = $pdo->prepare("SELECT name, logo FROM teams WHERE id = ?");
                $teamStmt->execute([$winner_id]);
                $teamRow = $teamStmt->fetch(PDO::FETCH_ASSOC);
                if ($teamRow) {
                    $teamName = $teamRow['name'];
                    $teamLogo = $teamRow['logo'];
                }
                $stmt->execute([$match['next_match_id'], $winner_id, $teamName, $teamLogo]);
            }
        }
    }

    $pdo->commit();

    echo safe_json_encode([
        'success' => true,
        'message' => 'Single-elimination match updated successfully',
        'data' => [
            'match_id' => $match_id,
            'team1_score' => $team1_score,
            'team2_score' => $team2_score,
            'winner_id' => $winner_id,
            'next_match_id' => $match['next_match_id']
        ]
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo safe_json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo safe_json_encode(['success' => false, 'message' => $e->getMessage()]);
}
