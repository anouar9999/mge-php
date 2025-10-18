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
        $registrations = getAcceptedRegistrations($pdo, $data->tournament_id);
        
        if (empty($registrations)) {
            throw new Exception('No accepted registrations found for this tournament');
        }
        
        clearExistingMatches($pdo, $data->tournament_id);
        
        if ($tournament['bracket_type'] === 'Double Elimination') {
            generateDoubleEliminationBracket($pdo, $tournament, $registrations);
        } else {
            generateSingleEliminationBracket($pdo, $tournament, $registrations);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Matches generated successfully']);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'trace' => $e->getTraceAsString()
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

function generateDoubleEliminationBracket($pdo, $tournament, $registrations) {
    $winnerRounds = ceil(log($tournament['max_participants'], 2));
    
    $winnerMatches = generateWinnersBracket($pdo, $tournament, $registrations, $winnerRounds);
    $loserMatches = [];
    
    $loserMatchCounts = [2, 1];
    
    for ($round = 1; $round <= count($loserMatchCounts); $round++) {
        $matchesInRound = $loserMatchCounts[$round - 1];
        
        for ($pos = 0; $pos < $matchesInRound; $pos++) {
            $match = createMatch($pdo, [
                'tournament_id' => $tournament['id'],
                'round' => $round,
                'position' => $pos,
                'bracket_type' => 'losers'
            ]);
            $loserMatches[] = $match;
        }
    }

    $grandFinals = createMatch($pdo, [
        'tournament_id' => $tournament['id'],
        'round' => $winnerRounds,
        'position' => 0,
        'bracket_type' => 'grand_finals'
    ]);

    setupDoubleEliminationConnections($pdo, $winnerMatches, $loserMatches, $grandFinals);
    return true;
}

function setupDoubleEliminationConnections($pdo, $winnerMatches, $loserMatches, $grandFinals) {
    $stmt = $pdo->prepare("UPDATE matches SET next_match_id = ?, loser_goes_to = ? WHERE id = ?");
    
    $losersByRound = [];
    foreach ($loserMatches as $match) {
        $round = $match['round'];
        if (!isset($losersByRound[$round])) {
            $losersByRound[$round] = [];
        }
        $losersByRound[$round][] = $match;
    }

    foreach ($winnerMatches as $match) {
        if ($match['round'] == 1) {
            $position = $match['position'];
            $loserPos = floor($position / 2);
            
            if (isset($losersByRound[1][$loserPos])) {
                $loserMatch = $losersByRound[1][$loserPos];
                $stmt->execute([null, $loserMatch['id'], $match['id']]);
            }
        }
    }

    if (isset($losersByRound[1])) {
        foreach ($losersByRound[1] as $index => $match) {
            if (isset($losersByRound[2][0])) {
                $stmt->execute([$losersByRound[2][0]['id'], null, $match['id']]);
            }
        }
    }

    if (isset($losersByRound[2][0])) {
        $stmt->execute([$grandFinals['id'], null, $losersByRound[2][0]['id']]);
    }
}

function generateWinnersBracket($pdo, $tournament, $registrations, $numRounds) {
    $matches = [];
    $totalSlots = pow(2, $numRounds);
    $matchesInFirstRound = $totalSlots / 2;
    
    for ($i = 0; $i < $matchesInFirstRound; $i++) {
        $match = createMatch($pdo, [
            'tournament_id' => $tournament['id'],
            'round' => 1,
            'position' => $i,
            'bracket_type' => 'winners'
        ]);
        
        $participant1 = $registrations[$i * 2] ?? null;
        $participant2 = $registrations[$i * 2 + 1] ?? null;
        
        if ($participant1) addParticipantToMatch($pdo, $match['id'], $participant1);
        if ($participant2) addParticipantToMatch($pdo, $match['id'], $participant2);
        
        $matches[] = $match;
    }
    
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
    
    return $matches;
}

function generateSingleEliminationBracket($pdo, $tournament, $registrations) {
    $numRounds = ceil(log(count($registrations), 2));
    $totalSlots = pow(2, $numRounds);
    $matchesInFirstRound = $totalSlots / 2;
    $matches = [];
    
    for ($i = 0; $i < $matchesInFirstRound; $i++) {
        $match = createMatch($pdo, [
            'tournament_id' => $tournament['id'],
            'round' => 1,
            'position' => $i,
            'bracket_type' => 'winners'
        ]);
        
        $participant1 = $registrations[$i * 2] ?? null;
        $participant2 = $registrations[$i * 2 + 1] ?? null;
        
        if ($participant1) addParticipantToMatch($pdo, $match['id'], $participant1);
        if ($participant2) addParticipantToMatch($pdo, $match['id'], $participant2);
        
        $matches[] = $match;
    }
    
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
    
    setupSingleEliminationConnections($pdo, $matches);
}

function setupSingleEliminationConnections($pdo, $matches) {
    $stmt = $pdo->prepare("UPDATE matches SET next_match_id = ? WHERE id = ?");
    
    foreach ($matches as $index => $match) {
        $nextMatchIndex = floor($index / 2) + count($matches) / 2;
        if (isset($matches[$nextMatchIndex])) {
            $stmt->execute([$matches[$nextMatchIndex]['id'], $match['id']]);
        }
    }
}

function createMatch($pdo, $data) {
    // CRITICAL: Get next ID manually since AUTO_INCREMENT is not working
    $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM matches");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextId = intval($result['next_id']);
    
    // Insert with explicit ID
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

function addParticipantToMatch($pdo, $matchId, $participant) {
    // Get next ID for match_participants table
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

function clearExistingMatches($pdo, $tournamentId) {
    // Delete match participants first (foreign key constraint)
    $stmt = $pdo->prepare("DELETE FROM match_participants WHERE match_id IN (SELECT id FROM matches WHERE tournament_id = ?)");
    $stmt->execute([$tournamentId]);
    
    // Then delete matches
    $stmt = $pdo->prepare("DELETE FROM matches WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
}

function getTournamentDetails($pdo, $tournamentId) {
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    return $tournament;
}

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
