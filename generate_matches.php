<?php
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

    $data = json_decode(file_get_contents("php://input"));
    if (!isset($data->tournament_id)) {
        throw new Exception('Tournament ID is required');
    }

    $pdo->beginTransaction();

    try {
        // Get tournament details and registrations
        $tournament = getTournamentDetails($pdo, $data->tournament_id);
        $registrations = getAcceptedRegistrations($pdo, $data->tournament_id);
        
        // Clear existing matches
        clearExistingMatches($pdo, $data->tournament_id);
        
        // Generate bracket based on format
        if ($tournament['format_des_qualifications'] === 'Double Elimination') {
            generateDoubleEliminationBracket($pdo, $tournament, $registrations);
        } else {
            generateSingleEliminationBracket($pdo, $tournament, $registrations);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function generateDoubleEliminationBracket($pdo, $tournament, $registrations) {
    $numParticipants = count($registrations);
    $winnerRounds = ceil(log($tournament['nombre_maximum'], 2));
    
    // Generate winners bracket
    $winnerMatches = generateWinnersBracket($pdo, $tournament, $registrations, $winnerRounds);
    
    // Generate losers bracket
    $loserMatches = [];
    
    // For 8 players:
    // Round 1: 2 matches (receives losers from winners round 1)
    // Round 2: 1 match (consolidation)
    $loserMatchCounts = [2, 1];  // Fixed number of matches per round for losers bracket
    
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

    // Generate grand finals
    $grandFinals = createMatch($pdo, [
        'tournament_id' => $tournament['id'],
        'round' => $winnerRounds,
        'position' => 0,
        'bracket_type' => 'grand_finals'
    ]);

    // Setup connections
    setupDoubleEliminationConnections($pdo, $winnerMatches, $loserMatches, $grandFinals);

    return true;
}

function setupBracketConnections($pdo, $winnerMatches, $loserMatches, $grandFinals, $winnerRounds) {
    $stmt = $pdo->prepare("UPDATE matches SET next_match_id = ?, loser_goes_to = ? WHERE id = ?");
    
    // Group loser matches by round
    $loserMatchesByRound = [];
    foreach ($loserMatches as $match) {
        $round = $match['tournament_round_text'];
        if (!isset($loserMatchesByRound[$round])) {
            $loserMatchesByRound[$round] = [];
        }
        $loserMatchesByRound[$round][] = $match;
    }
    
    // Connect winners bracket matches
    foreach ($winnerMatches as $winnerMatch) {
        $round = $winnerMatch['tournament_round_text'];
        $position = $winnerMatch['position'];
        
        // Find next winner match
        $nextRound = $round + 1;
        $nextPosition = floor($position / 2);
        $nextWinnerMatch = null;
        foreach ($winnerMatches as $match) {
            if ($match['tournament_round_text'] == $nextRound && $match['position'] == $nextPosition) {
                $nextWinnerMatch = $match;
                break;
            }
        }
        
        // Find corresponding loser match
        $loserRound = ($round - 1) * 2 + 1;
        $loserPosition = floor($position / 2);
        $loserMatch = null;
        if (isset($loserMatchesByRound[$loserRound])) {
            foreach ($loserMatchesByRound[$loserRound] as $match) {
                if ($match['position'] == $loserPosition) {
                    $loserMatch = $match;
                    break;
                }
            }
        }
        
        if ($nextWinnerMatch) {
            $stmt->execute([$nextWinnerMatch['id'], null, $winnerMatch['id']]);
        }
        if ($loserMatch) {
            $stmt->execute([null, $loserMatch['id'], $winnerMatch['id']]);
        }
    }
    
    // Connect loser bracket matches
    foreach ($loserMatches as $loserMatch) {
        $round = $loserMatch['tournament_round_text'];
        $position = $loserMatch['position'];
        $isMinorRound = $round % 2 == 1;
        
        if ($isMinorRound) {
            // Connect to next consolidation round
            $nextRound = $round + 1;
            $nextPosition = floor($position / 2);
        } else {
            // Connect to next minor round or finals
            $nextRound = $round + 1;
            $nextPosition = $position;
            if ($round == count($loserMatchesByRound) * 2) {
                // Last loser match goes to grand finals
                $stmt->execute([$grandFinals['id'], null, $loserMatch['id']]);
                continue;
            }
        }
        
        // Find next match
        if (isset($loserMatchesByRound[$nextRound])) {
            foreach ($loserMatchesByRound[$nextRound] as $match) {
                if ($match['position'] == $nextPosition) {
                    $stmt->execute([$match['id'], null, $loserMatch['id']]);
                    break;
                }
            }
        }
    }
}
function setupDoubleEliminationConnections($pdo, $winnerMatches, $loserMatches, $grandFinals) {
    $stmt = $pdo->prepare("UPDATE matches SET next_match_id = ?, loser_goes_to = ? WHERE id = ?");
    
    // Group matches by round
    $losersByRound = [];
    foreach ($loserMatches as $match) {
        $round = $match['round'];
        if (!isset($losersByRound[$round])) {
            $losersByRound[$round] = [];
        }
        $losersByRound[$round][] = $match;
    }

    // Connect winners bracket losers to losers bracket
    foreach ($winnerMatches as $match) {
        if ($match['round'] == 1) {  // Only first round losers go to losers bracket round 1
            $position = $match['position'];
            $loserPos = floor($position / 2);
            
            if (isset($losersByRound[1][$loserPos])) {
                $loserMatch = $losersByRound[1][$loserPos];
                $stmt->execute([null, $loserMatch['id'], $match['id']]);
            }
        }
    }

    // Connect losers bracket matches
    if (isset($losersByRound[1])) {  // Connect round 1 to round 2
        foreach ($losersByRound[1] as $index => $match) {
            if (isset($losersByRound[2][0])) {  // All round 1 matches connect to the single round 2 match
                $stmt->execute([$losersByRound[2][0]['id'], null, $match['id']]);
            }
        }
    }

    // Connect final losers match to grand finals
    if (isset($losersByRound[2][0])) {
        $stmt->execute([$grandFinals['id'], null, $losersByRound[2][0]['id']]);
    }
}

function generateWinnersBracket($pdo, $tournament, $registrations, $numRounds) {
    $matches = [];
    $totalSlots = pow(2, $numRounds);
    
    // Generate first round matches
    $matchesInFirstRound = $totalSlots / 2;
    $positions = generateSeedPositions($matchesInFirstRound);
    
    for ($i = 0; $i < $matchesInFirstRound; $i++) {
        $match = createMatch($pdo, [
            'tournament_id' => $tournament['id'],
            'round' => 1,
            'position' => $i,
            'bracket_type' => 'winners'
        ]);
        
        // Assign participants if available
        $participant1 = $registrations[$i * 2] ?? null;
        $participant2 = $registrations[$i * 2 + 1] ?? null;
        
        if ($participant1) addParticipantToMatch($pdo, $match['id'], $participant1);
        if ($participant2) addParticipantToMatch($pdo, $match['id'], $participant2);
        
        $matches[] = $match;
    }
    
    // Generate subsequent rounds
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

function generateLosersBracket($pdo, $tournament, $numRounds) {
    $matches = [];
    
    // For winners bracket with N rounds:
    // - Losers Round 1: N/2 matches (from winners round 1)
    // - Losers Round 2: N/4 matches (consolidation)
    // - Losers Round 3: N/4 matches (from winners round 2)
    // - Losers Round 4: N/8 matches (consolidation)
    // And so on...

    $matchCount = pow(2, $numRounds - 2); // Start with N/4 matches
    $currentRound = 1;

    while ($matchCount >= 1) {
        // Create matches for this round
        for ($i = 0; $i < $matchCount; $i++) {
            $match = createMatch($pdo, [
                'tournament_id' => $tournament['id'],
                'round' => $currentRound,
                'position' => $i,
                'bracket_type' => 'losers',
                'start_time' => date('Y-m-d')
            ]);
            $matches[] = $match;
        }

        // Move to next round
        $currentRound++;
        
        // Reduce number of matches after every two rounds
        if ($currentRound % 2 == 0) {
            $matchCount = floor($matchCount / 2);
        }
    }
    
    return $matches;
}

function calculateLoserMatchPosition($winnerPosition, $winnerRound, $numRounds) {
    // This formula determines where in the losers bracket a team from winners should go
    $loserRoundMatches = pow(2, $numRounds - 1 - ceil($winnerRound/2));
    return $winnerPosition % $loserRoundMatches;
}

function findMatchByRoundAndPosition($matches, $round, $position) {
    foreach ($matches as $match) {
        if ($match['tournament_round_text'] == $round && $match['position'] == $position) {
            return $match;
        }
    }
    return null;
}

function calculateLosersRoundMatches($round, $winnerRounds) {
    if ($round % 2 === 1) {
        // Minor round (receives losers from winners bracket)
        return pow(2, $winnerRounds - 1 - ceil($round/2));
    } else {
        // Major round (between losers bracket participants)
        return pow(2, $winnerRounds - 1 - floor($round/2));
    }
}

function calculateLoserMatchIndex($winnerMatchIndex, $totalLoserMatches) {
    return $winnerMatchIndex % $totalLoserMatches;
}

function calculateNextLoserMatchIndex($currentIndex, $round, $totalMatches) {
    // If this is a minor round (odd number), matches feed into the next round
    if ($round % 2 == 1) {
        return floor($currentIndex / 2);
    } else {
        // If this is a major round (even number), matches feed into the next bracket level
        return $currentIndex + floor($totalMatches / 2);
    }
}

function generateSingleEliminationBracket($pdo, $tournament, $registrations) {
    $numRounds = ceil(log(count($registrations), 2));
    $totalSlots = pow(2, $numRounds);
    
    // Generate first round matches
    $matchesInFirstRound = $totalSlots / 2;
    $positions = generateSeedPositions($matchesInFirstRound);
    $matches = [];
    
    for ($i = 0; $i < $matchesInFirstRound; $i++) {
        $pos = $positions[$i];
        $match = createMatch($pdo, [
            'tournament_id' => $tournament['id'],
            'round' => 1,
            'position' => $pos,
            'bracket_type' => 'winners'
        ]);
        
        $participant1 = $registrations[$i * 2] ?? null;
        $participant2 = $registrations[$i * 2 + 1] ?? null;
        
        if ($participant1) addParticipantToMatch($pdo, $match['id'], $participant1);
        if ($participant2) addParticipantToMatch($pdo, $match['id'], $participant2);
        
        $matches[] = $match;
    }
    
    // Generate subsequent rounds
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
    
    // Setup match connections
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

function generateSeedPositions($numMatches) {
    $positions = array();
    generateSeedPositionsRecursive($positions, 0, $numMatches - 1, 0);
    return $positions;
}

function generateSeedPositionsRecursive(&$positions, $start, $end, $index) {
    if ($start > $end) return;
    
    $mid = floor(($start + $end) / 2);
    $positions[] = $mid;
    
    generateSeedPositionsRecursive($positions, $start, $mid - 1, $index * 2 + 1);
    generateSeedPositionsRecursive($positions, $mid + 1, $end, $index * 2 + 2);
}

function createMatch($pdo, $data) {
    $stmt = $pdo->prepare("
        INSERT INTO matches (
            tournament_id,
            tournament_round_text,
            position,
            state,
            bracket_type,
            start_time
        ) VALUES (?, ?, ?, 'SCHEDULED', ?, CURDATE())
    ");
    
    $stmt->execute([
        $data['tournament_id'],
        $data['round'],
        $data['position'],
        $data['bracket_type']
    ]);
    
    return [
        'id' => $pdo->lastInsertId(),
        'round' => $data['round'],
        'position' => $data['position']
    ];
}

function addParticipantToMatch($pdo, $matchId, $participant) {
    $stmt = $pdo->prepare("
        INSERT INTO match_participants (
            match_id,
            participant_id,
            name,
            picture,
            status
        ) VALUES (?, ?, ?, ?, 'NOT_PLAYED')
    ");
    
    $stmt->execute([
        $matchId,
        $participant['participant_id'],
        $participant['name'],
        $participant['picture'] ?? null
    ]);
}

function clearExistingMatches($pdo, $tournamentId) {
    $stmt = $pdo->prepare("DELETE FROM match_participants WHERE match_id IN (SELECT id FROM matches WHERE tournament_id = ?)");
    $stmt->execute([$tournamentId]);
    
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
                WHEN tr.team_id IS NOT NULL THEN t.image
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