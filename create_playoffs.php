<?php
/**
 * create_playoffs.php - Creates playoff brackets for a round robin tournament
 * 
 * This script generates playoff matches based on the standings from round robin groups.
 * It takes the top teams from each group and creates a single or double elimination bracket.
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

// Get tournament_id from query parameters
$tournament_id = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : null;

// Get the database configuration
$db_config = require 'db_config.php';

try {
    if ($tournament_id === null) {
        throw new Exception('tournament_id is required as a query parameter');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Start a transaction for data consistency
    $pdo->beginTransaction();

    // First get the tournament details
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    // Check if playoffs already exist for this tournament
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM matches 
        WHERE tournament_id = ? AND bracket_type = 'winners'
    ");
    $stmt->execute([$tournament_id]);
    $playoffsExist = (bool)$stmt->fetchColumn();
    
    if ($playoffsExist) {
        throw new Exception('Playoffs already exist for this tournament. Cannot create new ones.');
    }
    
    // Check that all round robin matches are completed
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM round_robin_matches 
        WHERE tournament_id = ? AND status != 'completed'
    ");
    $stmt->execute([$tournament_id]);
    $incompleteMatches = (int)$stmt->fetchColumn();
    
    if ($incompleteMatches > 0) {
        throw new Exception('Cannot create playoffs until all round robin matches are completed. There are still ' . $incompleteMatches . ' incomplete matches.');
    }
    
    // Get all groups for this tournament
    $stmt = $pdo->prepare("SELECT * FROM round_robin_groups WHERE tournament_id = ? ORDER BY name");
    $stmt->execute([$tournament_id]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($groups) === 0) {
        throw new Exception('No groups found for this tournament');
    }
    
    // Get top teams from each group (top 2 by default)
    $teamsPerGroup = 2; // Can be modified if needed
    $qualifyingTeams = [];
    
    foreach ($groups as $group) {
        // Get standings for this group, ordered by points and other metrics
        $stmt = $pdo->prepare("
            SELECT 
                s.*,
                t.name as team_name,
                t.logo as team_logo
            FROM round_robin_standings s
            JOIN teams t ON s.team_id = t.id
            WHERE s.group_id = ?
            ORDER BY s.points DESC, (s.goals_for - s.goals_against) DESC, s.goals_for DESC
            LIMIT " . $teamsPerGroup
        );
        $stmt->execute([$group['id']]);
        $topTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($topTeams as $position => $team) {
            $qualifyingTeams[] = [
                'team_id' => $team['team_id'],
                'team_name' => $team['team_name'],
                'team_logo' => $team['team_logo'],
                'group_id' => $group['id'],
                'group_name' => $group['name'],
                'group_position' => $position + 1, // 1 for winner, 2 for runner-up
                'points' => $team['points'],
                'goal_diff' => $team['goals_for'] - $team['goals_against']
            ];
        }
    }
    
    // Determine bracket size based on number of qualifying teams
    $numTeams = count($qualifyingTeams);
    $bracketSize = 0;
    
    // Find the appropriate bracket size (power of 2)
    if ($numTeams <= 2) $bracketSize = 2;
    else if ($numTeams <= 4) $bracketSize = 4;
    else if ($numTeams <= 8) $bracketSize = 8;
    else if ($numTeams <= 16) $bracketSize = 16;
    else $bracketSize = 32;
    
    // Calculate number of matches in the bracket
    $numMatches = $bracketSize - 1;
    
    // Determine if we need to add byes for some teams
    $numByes = $bracketSize - $numTeams;
    
    // Create playoffs data structure
    $playoffsData = [
        'tournament_id' => $tournament_id,
        'bracket_size' => $bracketSize,
        'teams' => $qualifyingTeams,
        'rounds' => ceil(log($bracketSize, 2)), // Number of rounds needed
        'matches' => []
    ];
    
    // Seed the bracket - we'll do a standard seeding where group winners face runners-up
    // from other groups to avoid immediate rematches
    $seededTeams = seedBracket($qualifyingTeams, $numByes, count($groups));
    
    // Create matches for each round
    $roundMatches = []; // Track matches by round
    $matchIds = []; // Track match IDs to establish next_match relationships
    
    for ($round = 0; $round < $playoffsData['rounds']; $round++) {
        $matchesInRound = $bracketSize / pow(2, $round + 1);
        $roundName = getRoundName($round, $playoffsData['rounds']);
        
        $roundMatches[$round] = [];
        
        for ($i = 0; $i < $matchesInRound; $i++) {
            // For first round, assign teams from seededTeams array
            $team1 = null;
            $team2 = null;
            
            if ($round === 0) {
                $team1Idx = $i * 2;
                $team2Idx = $i * 2 + 1;
                
                if ($team1Idx < count($seededTeams) && $seededTeams[$team1Idx] !== null) {
                    $team1 = $seededTeams[$team1Idx];
                }
                
                if ($team2Idx < count($seededTeams) && $seededTeams[$team2Idx] !== null) {
                    $team2 = $seededTeams[$team2Idx];
                }
            }
            
            // Generate a unique match ID
            $matchId = generateMatchId($tournament_id, 'playoffs', $round, $i);
            $matchIds[$round][$i] = $matchId;
            
            // Determine the next match ID (if not final)
            $nextMatchId = null;
            if ($round < $playoffsData['rounds'] - 1) {
                $nextRound = $round + 1;
                $nextMatchIndex = floor($i / 2);
                $nextMatchId = generateMatchId($tournament_id, 'playoffs', $nextRound, $nextMatchIndex);
            }
            
            // Create the match record in the database
            $stmt = $pdo->prepare("
                INSERT INTO matches (
                    id, tournament_id, next_match_id, tournament_round_text, 
                    start_time, state, position, bracket_position, bracket_type,
                    created_at, updated_at
                )
                VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), 'SCHEDULED', ?, ?, 'winners', NOW(), NOW())
            ");
            
            $roundDays = $round * 7; // Space rounds a week apart
            $stmt->execute([
                $matchId,
                $tournament_id,
                $nextMatchId,
                $roundName,
                $roundDays,
                $i,
                $i
            ]);
            
            // Create match participants if teams are known
            if ($team1 !== null) {
                addMatchParticipant($pdo, $matchId, $team1['team_id'], $team1['team_name'], $team1['team_logo']);
            }
            
            if ($team2 !== null) {
                addMatchParticipant($pdo, $matchId, $team2['team_id'], $team2['team_name'], $team2['team_logo']);
            }
            
            // Store match data for response
            $match = [
                'id' => $matchId,
                'tournament_id' => $tournament_id,
                'next_match_id' => $nextMatchId,
                'round' => $round,
                'round_name' => $roundName,
                'bracket_position' => $i,
                'state' => 'SCHEDULED',
                'participants' => []
            ];
            
            if ($team1 !== null) {
                $match['participants'][] = [
                    'id' => $team1['team_id'],
                    'name' => $team1['team_name'],
                    'picture' => $team1['team_logo'],
                    'group_name' => $team1['group_name'],
                    'group_position' => $team1['group_position']
                ];
            }
            
            if ($team2 !== null) {
                $match['participants'][] = [
                    'id' => $team2['team_id'],
                    'name' => $team2['team_name'],
                    'picture' => $team2['team_logo'],
                    'group_name' => $team2['group_name'],
                    'group_position' => $team2['group_position']
                ];
            }
            
            $roundMatches[$round][] = $match;
        }
    }
    
    // If this is a double-elimination tournament, create losers bracket too
    if ($tournament['bracket_type'] === 'Double Elimination') {
        // Implementation for double elimination bracket
        // (This would be more complex and require additional match creation and linking)
    }
    
    // Update tournament status if needed
    if ($tournament['status'] === 'ongoing') {
        $stmt = $pdo->prepare("
            UPDATE tournaments
            SET status = 'ongoing', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$tournament_id]);
    }
    
    // Prepare the playoff bracket data structure for the response
    $rounds = [];
    foreach ($roundMatches as $roundIndex => $matches) {
        $rounds[] = $matches;
    }
    
    // Commit the transaction
    $pdo->commit();
    
    // Return success response
    echo safe_json_encode([
        'success' => true,
        'message' => 'Playoff bracket created successfully',
        'data' => [
            'tournament_id' => $tournament_id,
            'has_playoffs' => true,
            'bracket' => [
                'rounds' => $rounds
            ]
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
 * Generate a unique match ID
 *
 * @param int $tournamentId Tournament ID
 * @param string $stage Playoff stage
 * @param int $round Round number
 * @param int $match Match number within round
 * @return string Unique match ID
 */
function generateMatchId($tournamentId, $stage, $round, $match) {
    return $tournamentId . '-' . $stage . '-r' . $round . '-m' . $match;
}

/**
 * Get human-readable round name
 *
 * @param int $round Round index (0-based)
 * @param int $totalRounds Total number of rounds
 * @return string Round name
 */
function getRoundName($round, $totalRounds) {
    $roundFromEnd = $totalRounds - $round - 1;
    
    if ($roundFromEnd === 0) return 'Final';
    if ($roundFromEnd === 1) return 'Semi-finals';
    if ($roundFromEnd === 2) return 'Quarter-finals';
    if ($roundFromEnd === 3) return 'Round of 16';
    if ($roundFromEnd === 4) return 'Round of 32';
    
    return 'Round ' . ($round + 1);
}

/**
 * Add a participant to a match
 *
 * @param PDO $pdo Database connection
 * @param string $matchId Match ID
 * @param int $teamId Team ID
 * @param string $teamName Team name
 * @param string $teamLogo Team logo URL
 */
function addMatchParticipant($pdo, $matchId, $teamId, $teamName, $teamLogo) {
    $stmt = $pdo->prepare("
        INSERT INTO match_participants (
            match_id, participant_id, name, picture, 
            status, created_at, updated_at
        )
        VALUES (?, ?, ?, ?, 'NOT_PLAYED', NOW(), NOW())
    ");
    
    $stmt->execute([
        $matchId,
        $teamId,
        $teamName,
        $teamLogo
    ]);
}

/**
 * Seed the bracket to create fair matchups
 *
 * @param array $teams Qualifying teams
 * @param int $numByes Number of byes needed
 * @param int $numGroups Number of groups
 * @return array Seeded teams array with byes as null
 */
function seedBracket($teams, $numByes, $numGroups) {
    // Sort teams: first by group position, then by points, then by goal difference
    usort($teams, function($a, $b) {
        // Sort by group position first (winners first)
        if ($a['group_position'] !== $b['group_position']) {
            return $a['group_position'] - $b['group_position'];
        }
        
        // Then by points
        if ($a['points'] !== $b['points']) {
            return $b['points'] - $a['points'];
        }
        
        // Then by goal difference
        return $b['goal_diff'] - $a['goal_diff'];
    });
    
    // Get all group winners and runners-up
    $groupWinners = array_filter($teams, function($team) {
        return $team['group_position'] === 1;
    });
    
    $groupRunnersUp = array_filter($teams, function($team) {
        return $team['group_position'] === 2;
    });
    
    // Reindex arrays
    $groupWinners = array_values($groupWinners);
    $groupRunnersUp = array_values($groupRunnersUp);
    
    // Create seeded bracket
    $bracket = [];
    $bracketSize = count($teams) + $numByes;
    
    // Standard seeding for single elimination
    for ($i = 0; $i < $bracketSize; $i++) {
        $bracket[$i] = null; // Start with all positions empty
    }
    
    // Place group winners at positions to potentially meet in later rounds
    for ($i = 0; $i < count($groupWinners); $i++) {
        // Use standard seeding positions (1, 4, 5, 8, 9, 12, 13, 16, etc.)
        $seedPosition = getSeedPosition($i, $bracketSize);
        $bracket[$seedPosition] = $groupWinners[$i];
    }
    
    // Place runners-up avoiding initial matchups against winners from same group
    $runnersUpPositions = [];
    for ($i = 0; $i < $bracketSize; $i++) {
        if ($bracket[$i] === null) {
            $runnersUpPositions[] = $i;
        }
    }
    
    // Try to place runners-up to avoid same-group matchups in first round
    for ($i = 0; $i < count($groupRunnersUp); $i++) {
        $runnerUp = $groupRunnersUp[$i];
        
        // Find appropriate position - ideally not facing winner from same group in first round
        $positionAssigned = false;
        
        foreach ($runnersUpPositions as $key => $position) {
            // Check the opponent in the first round
            $opponentPosition = $position % 2 === 0 ? $position + 1 : $position - 1;
            
            // If opponent exists and is from the same group, try another position
            if (isset($bracket[$opponentPosition]) && 
                $bracket[$opponentPosition] !== null && 
                $bracket[$opponentPosition]['group_id'] === $runnerUp['group_id']) {
                continue;
            }
            
            // This position works, assign it
            $bracket[$position] = $runnerUp;
            unset($runnersUpPositions[$key]);
            $positionAssigned = true;
            break;
        }
        
        // If no optimal position found, just use the first available
        if (!$positionAssigned && count($runnersUpPositions) > 0) {
            $position = reset($runnersUpPositions);
            $key = key($runnersUpPositions);
            $bracket[$position] = $runnerUp;
            unset($runnersUpPositions[$key]);
        }
    }
    
    return $bracket;
}

/**
 * Get standard seeding position for tournament brackets
 *
 * @param int $index Index of the seed (0-based)
 * @param int $size Size of the bracket
 * @return int Position in the bracket
 */
function getSeedPosition($index, $size) {
    // This implements a standard tournament seeding pattern:
    // For a 16-team bracket: 1, 16, 8, 9, 5, 12, 4, 13, 3, 14, 6, 11, 7, 10, 2, 15
    if ($size <= 1) return 0;
    
    if ($index === 0) return 0; // Top seed always at position 0
    if ($index === 1) return $size - 1; // Second seed always at last position
    
    // Use binary tree traversal to determine positions
    $power = 1;
    while ($power * 2 <= $index) {
        $power *= 2;
    }
    
    return ($size - 1) - getSeedPosition($index - $power, $power * 2);
}