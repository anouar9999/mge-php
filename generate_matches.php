<?php
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

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput);
    
    if (!$data) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }
    
    if (!isset($data->tournament_id)) {
        throw new Exception('Tournament ID is required');
    }

    $pdo->beginTransaction();

    try {
        $tournament = getTournamentDetails($pdo, $data->tournament_id);
        
        // Get ONLY accepted registrations
        $registrations = getAcceptedRegistrations($pdo, $data->tournament_id);
        
        if (empty($registrations)) {
            throw new Exception('No accepted registrations found for this tournament');
        }
        
        // Check if accepted participants exceed max_participants
        if (count($registrations) > $tournament['max_participants']) {
            throw new Exception('Too many accepted participants (' . count($registrations) . '). Tournament max is ' . $tournament['max_participants']);
        }
        
        // Clear any existing matches
        clearExistingMatches($pdo, $data->tournament_id);
        
        // Generate bracket using max_participants for bracket size
        // But fill only with accepted participants
        generateSingleEliminationBracket($pdo, $tournament, $registrations);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Matches generated successfully',
            'participants_count' => count($registrations),
            'max_participants' => $tournament['max_participants'],
            'bracket_type' => 'Single Elimination',
            'rounds' => ceil(log($tournament['max_participants'], 2))
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => basename($e->getFile())
    ]);
}

/**
 * Generate Single Elimination Bracket
 * 
 * IMPORTANT: Bracket size is based on max_participants
 * But only accepted participants are placed in matches
 * 
 * Example:
 * - max_participants = 16
 * - accepted = 4 participants
 * 
 * Result:
 * - Bracket has 16 slots (4 rounds)
 * - Only 4 participants are placed (rest are TBD/bye)
 * - First round: 8 matches (4 with participants, 4 empty)
 */
function generateSingleEliminationBracket($pdo, $tournament, $registrations) {
    // CRITICAL: Use max_participants to determine bracket size
    // This ensures the bracket structure matches tournament settings
    $maxParticipants = $tournament['max_participants'];
    
    // Calculate bracket structure based on max_participants
    $numRounds = ceil(log($maxParticipants, 2));
    $totalSlots = pow(2, $numRounds);
    $matchesInFirstRound = $totalSlots / 2;
    
    $matches = [];
    
    // STEP 1: Create first round matches
    // Fill with accepted participants, leave rest as TBD
    for ($i = 0; $i < $matchesInFirstRound; $i++) {
        $match = createMatch($pdo, [
            'tournament_id' => $tournament['id'],
            'round' => 1,
            'position' => $i,
            'bracket_type' => 'winners'
        ]);
        
        // Add accepted participants (if available for this match slot)
        $participant1 = $registrations[$i * 2] ?? null;
        $participant2 = $registrations[$i * 2 + 1] ?? null;
        
        if ($participant1) {
            addParticipantToMatch($pdo, $match['id'], $participant1);
        }
        
        if ($participant2) {
            addParticipantToMatch($pdo, $match['id'], $participant2);
        }
        
        // Matches with 0 or 1 participant will show as TBD
        
        $matches[] = $match;
    }
    
    // STEP 2: Create empty matches for subsequent rounds
    for ($round = 2; $round <= $numRounds; $round++) {
        $matchesInRound = $totalSlots / pow(2, $round);
        
        for ($i = 0; $i < $matchesInRound; $i++) {
            $match = createMatch($pdo, [
                'tournament_id' => $tournament['id'],
                'round' => $round,
                'position' => $i,
                'bracket_type' => 'winners'
            ]);
            $matches[] = $match;
        }
    }
    
    // STEP 3: Connect matches
    setupSingleEliminationConnections($pdo, $matches, $numRounds);
}

/**
 * Setup match connections for Single Elimination
 */
function setupSingleEliminationConnections($pdo, $matches, $numRounds) {
    $stmt = $pdo->prepare("UPDATE matches SET next_match_id = ? WHERE id = ?");
    
    // Organize matches by round
    $matchesByRound = [];
    foreach ($matches as $match) {
        $round = $match['round'];
        if (!isset($matchesByRound[$round])) {
            $matchesByRound[$round] = [];
        }
        $matchesByRound[$round][] = $match;
    }
    
    // Connect each round to the next
    for ($round = 1; $round < $numRounds; $round++) {
        if (!isset($matchesByRound[$round])) continue;
        
        foreach ($matchesByRound[$round] as $index => $match) {
            // Every 2 matches feed into 1 match in next round
            $nextMatchIndex = floor($index / 2);
            
            if (isset($matchesByRound[$round + 1][$nextMatchIndex])) {
                $nextMatchId = $matchesByRound[$round + 1][$nextMatchIndex]['id'];
                $stmt->execute([$nextMatchId, $match['id']]);
            }
        }
    }
}

/**
 * Create a match record
 */
function createMatch($pdo, $data) {
    // Get next ID manually
    $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM matches");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextId = intval($result['next_id']);
    
    $stmt = $pdo->prepare("
        INSERT INTO matches (
            id,
            tournament_id,
            tournament_round_text,
            position,
            state,
            bracket_type,
            start_time,
            score1,
            score2
        ) VALUES (?, ?, ?, ?, 'SCHEDULED', ?, CURDATE(), 0, 0)
    ");
    
    $success = $stmt->execute([
        $nextId,
        $data['tournament_id'],
        $data['round'],
        $data['position'],
        $data['bracket_type']
    ]);
    
    if (!$success) {
        throw new Exception('Failed to create match');
    }
    
    return [
        'id' => $nextId,
        'round' => $data['round'],
        'position' => $data['position']
    ];
}

/**
 * Add a participant to a match
 */
function addParticipantToMatch($pdo, $matchId, $participant) {
    $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM match_participants");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextId = intval($result['next_id']);
    
    $stmt = $pdo->prepare("
        INSERT INTO match_participants (
            id,
            match_id,
            participant_id,
            name,
            picture,
            status
        ) VALUES (?, ?, ?, ?, ?, 'NOT_PLAYED')
    ");
    
    $stmt->execute([
        $nextId,
        $matchId,
        $participant['participant_id'],
        $participant['name'],
        $participant['picture'] ?? null
    ]);
}

/**
 * Clear existing matches
 */
function clearExistingMatches($pdo, $tournamentId) {
    $stmt = $pdo->prepare("DELETE FROM match_participants WHERE match_id IN (SELECT id FROM matches WHERE tournament_id = ?)");
    $stmt->execute([$tournamentId]);
    
    $stmt = $pdo->prepare("DELETE FROM matches WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
}

/**
 * Get tournament details
 */
function getTournamentDetails($pdo, $tournamentId) {
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    return $tournament;
}

/**
 * Get ONLY accepted registrations
 * These are the participants that will be placed in the bracket
 */
function getAcceptedRegistrations($pdo, $tournamentId) {
    $stmt = $pdo->prepare("
        SELECT 
            tr.*,
            CASE 
                WHEN tr.team_id IS NOT NULL THEN CONCAT('team_', tr.team_id)
                ELSE CONCAT('player_', tr.user_id)
            END as participant_id,
            CASE 
                WHEN tr.team_id IS NOT NULL THEN t.name
                ELSE u.username
            END as name,
            CASE 
                WHEN tr.team_id IS NOT NULL THEN t.logo
                ELSE u.avatar
            END as picture
        FROM tournament_registrations tr
        LEFT JOIN teams t ON tr.team_id = t.id
        LEFT JOIN users u ON tr.user_id = u.id
        WHERE tr.tournament_id = ? 
        AND tr.status = 'accepted'
        ORDER BY RAND()
    ");
    
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
