// tournament_playoffs.php - Handles creating playoff bracket from round robin results

<?php
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

// Parse URL parameters
$url_parts = parse_url($_SERVER['REQUEST_URI']);
$path = $url_parts['path'];
$path_segments = explode('/', $path);

// Extract tournament_id from URL path
// Expected format: /api/tournaments/{tournament_id}/create-playoffs
$tournament_id = null;
foreach ($path_segments as $i => $segment) {
    if ($segment === 'tournaments' && isset($path_segments[$i+1]) && is_numeric($path_segments[$i+1])) {
        $tournament_id = (int)$path_segments[$i+1];
        break;
    }
}

// Get the database configuration
$db_config = require 'db_config.php';

try {
    if ($tournament_id === null) {
        throw new Exception('Tournament ID is required in the URL path (/api/tournaments/{tournament_id}/create-playoffs)');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Start a transaction for data consistency
    $pdo->beginTransaction();
    
    // Get the tournament details
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    // Check if tournament is in the correct state
    if ($tournament['status'] !== 'ongoing') {
        throw new Exception('Tournament must be in "ongoing" status to create playoffs');
    }
    
    // Get all groups for this tournament
    $stmt = $pdo->prepare("SELECT * FROM round_robin_groups WHERE tournament_id = ?");
    $stmt->execute([$tournament_id]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($groups) === 0) {
        throw new Exception('No round robin groups found for this tournament');
    }
    
    // Check if all matches in all groups are completed
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM round_robin_matches 
        WHERE tournament_id = ? AND status != 'completed'
    ");
    $stmt->execute([$tournament_id]);
    $pending_matches = (int)$stmt->fetchColumn();
    
    if ($pending_matches > 0) {
        throw new Exception('All round robin matches must be completed before creating playoffs. Pending matches: ' . $pending_matches);
    }
    
    // Check if playoff matches already exist
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM matches 
        WHERE tournament_id = ? AND tournament_round_text LIKE 'Playoff%'
    ");
    $stmt->execute([$tournament_id]);
    $existing_playoffs = (int)$stmt->fetchColumn();
    
    if ($existing_playoffs > 0) {
        throw new Exception('Playoff matches already exist for this tournament');
    }
    
    // Get top 2 teams from each group
    $qualified_teams = [];
    foreach ($groups as $group) {
        $stmt = $pdo->prepare("
            SELECT s.*, t.name as team_name 
            FROM round_robin_standings s
            JOIN teams t ON s.team_id = t.id
            WHERE s.tournament_id = ? AND s.group_id = ?
            ORDER BY s.points DESC, (s.goals_for - s.goals_against) DESC, s.goals_for DESC
            LIMIT 2
        ");
        $stmt->execute([$tournament_id, $group['id']]);
        $top_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If we don't have 2 teams, fill with nulls
        while (count($top_teams) < 2) {
            $top_teams[] = ['team_id' => null, 'team_name' => 'TBD'];
        }
        
        // Add to qualified teams array with their group and position
        foreach ($top_teams as $index => $team) {
            $qualified_teams[] = [
                'team_id' => $team['team_id'],
                'team_name' => $team['team_name'],
                'group_id' => $group['id'],
                'group_name' => $group['name'],
                'position' => $index + 1  // 1 for winner, 2 for runner-up
            ];
        }
    }
    
    // Determine playoff format based on number of qualified teams
    $num_qualified = count($qualified_teams);
    $playoff_format = determinePlayoffFormat($num_qualified);
    
    if (!$playoff_format) {
        throw new Exception('Could not determine playoff format for ' . $num_qualified . ' teams');
    }
    
    // Create playoff bracket matches
    $playoff_matches = createPlayoffBracket($pdo, $tournament_id, $qualified_teams, $playoff_format);
    
    // Commit the transaction
    $pdo->commit();
    
    // Return success response
    echo safe_json_encode([
        'success' => true,
        'message' => 'Playoff bracket created successfully',
        'data' => [
            'tournament_id' => $tournament_id,
            'qualified_teams' => $qualified_teams,
            'playoff_format' => $playoff_format,
            'playoff_matches' => $playoff_matches
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
 * Determine the playoff format based on the number of qualified teams
 *
 * @param int $num_teams Number of qualified teams
 * @return array|null Format information or null if unsupported
 */
function determinePlayoffFormat($num_teams) {
    switch ($num_teams) {
        case 2:
            return [
                'name' => 'Final only',
                'rounds' => 1,
                'matches_per_round' => [1],
                'round_names' => ['Final']
            ];
        case 4:
            return [
                'name' => 'Semifinal and Final',
                'rounds' => 2,
                'matches_per_round' => [2, 1],
                'round_names' => ['Semifinal', 'Final']
            ];
        case 8:
            return [
                'name' => 'Quarterfinal, Semifinal and Final',
                'rounds' => 3,
                'matches_per_round' => [4, 2, 1],
                'round_names' => ['Quarterfinal', 'Semifinal', 'Final']
            ];
        case 16:
            return [
                'name' => 'Round of 16, Quarterfinal, Semifinal and Final',
                'rounds' => 4,
                'matches_per_round' => [8, 4, 2, 1],
                'round_names' => ['Round of 16', 'Quarterfinal', 'Semifinal', 'Final']
            ];
        default:
            // For other numbers, approximate to the nearest power of 2
            if ($num_teams > 2 && $num_teams <= 4) {
                return determinePlayoffFormat(4);
            } else if ($num_teams > 4 && $num_teams <= 8) {
                return determinePlayoffFormat(8);
            } else if ($num_teams > 8 && $num_teams <= 16) {
                return determinePlayoffFormat(16);
            }
            return null;
    }
}

/**
 * Create playoff bracket matches
 *
 * @param PDO $pdo Database connection
 * @param int $tournament_id Tournament ID
 * @param array $qualified_teams Qualified teams from group stage
 * @param array $playoff_format Playoff format information
 * @return array Created playoff matches
 */
function createPlayoffBracket($pdo, $tournament_id, $qualified_teams, $playoff_format) {
    $created_matches = [];
    $rounds = $playoff_format['rounds'];
    
    // Get tournament dates to distribute match dates
    $stmt = $pdo->prepare("SELECT start_date, end_date FROM tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $dates = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $start_date = new DateTime($dates['start_date']);
    $end_date = new DateTime($dates['end_date']);
    $days_available = $start_date->diff($end_date)->days + 1;
    
    // Make sure we have at least as many days as rounds
    $days_per_round = max(1, floor($days_available / $rounds));
    
    // Seed teams for initial round
    // For simplicity, we'll use a fixed seeding pattern based on group position
    // A more sophisticated approach would consider group strength, etc.
    $seeded_teams = seedTeamsForBracket($qualified_teams, $playoff_format);
    
    // Create matches for each round
    for ($round = 0; $round < $rounds; $round++) {
        $round_name = $playoff_format['round_names'][$round];
        $matches_in_round = $playoff_format['matches_per_round'][$round];
        
        // Calculate match date for this round
        $match_date = clone $start_date;
        $match_date->modify('+' . ($round * $days_per_round) . ' days');
        
        for ($match = 0; $match < $matches_in_round; $match++) {
            $match_position = $match + 1;
            
            // For first round, use seeded teams
            if ($round === 0) {
                $team1_id = isset($seeded_teams[$match * 2]) ? $seeded_teams[$match * 2]['team_id'] : null;
                $team2_id = isset($seeded_teams[$match * 2 + 1]) ? $seeded_teams[$match * 2 + 1]['team_id'] : null;
            } else {
                // For later rounds, teams are determined by previous matches
                $team1_id = null; // Will be determined by previous match winner
                $team2_id = null; // Will be determined by previous match winner
            }
            
            // For bracket positions, use consistent numbering
            // First round: positions 1-8 (for quarterfinals)
            // Second round: positions 9-12 (for semifinals)
            // Third round: positions 13-14 (for finals)
            $bracket_position = 0;
            if ($rounds === 3) { // Standard 8-team bracket
                if ($round === 0) $bracket_position = $match + 1;
                else if ($round === 1) $bracket_position = 5 + $match;
                else if ($round === 2) $bracket_position = 7 + $match;
            } else if ($rounds === 2) { // 4-team bracket
                if ($round === 0) $bracket_position = $match + 1;
                else if ($round === 1) $bracket_position = 3 + $match;
            } else if ($rounds === 4) { // 16-team bracket
                if ($round === 0) $bracket_position = $match + 1;
                else if ($round === 1) $bracket_position = 9 + $match;
                else if ($round === 2) $bracket_position = 13 + $match;
                else if ($round === 3) $bracket_position = 15 + $match;
            }
            
            // Generate a unique ID for the match
            // In real app, you'd use auto-increment, but for clarity we'll use a formula
            $match_id = $tournament_id * 1000 + $round * 100 + $match;
            
            // Calculate next match ID (where winner advances to)
            $next_match_id = null;
            if ($round < $rounds - 1) {
                $next_match = floor($match / 2);
                $next_match_id = $tournament_id * 1000 + ($round + 1) * 100 + $next_match;
            }
            
            // Insert match record
            $stmt = $pdo->prepare("
                INSERT INTO matches (
                    id,
                    tournament_id,
                    next_match_id,
                    tournament_round_text,
                    start_time,
                    state,
                    match_state,
                    position,
                    bracket_position,
                    bracket_type,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, 'SCHEDULED', 'pending', ?, ?, 'winners', NOW(), NOW())
            ");
            $stmt->execute([
                $match_id,
                $tournament_id,
                $next_match_id,
                'Playoff - ' . $round_name,
                $match_date->format('Y-m-d'),
                $match_position,
                $bracket_position
            ]);
            
            // If we have teams for this match, add them as participants
            if ($team1_id !== null) {
                addMatchParticipant($pdo, $match_id, $team1_id, "team1");
            }
            
            if ($team2_id !== null) {
                addMatchParticipant($pdo, $match_id, $team2_id, "team2");
            }
            
            $created_matches[] = [
                'id' => $match_id,
                'tournament_id' => $tournament_id,
                'round' => $round,
                'round_name' => $round_name,
                'match_position' => $match_position,
                'bracket_position' => $bracket_position,
                'team1_id' => $team1_id,
                'team2_id' => $team2_id,
                'next_match_id' => $next_match_id,
                'match_date' => $match_date->format('Y-m-d')
            ];
        }
    }
    
    return $created_matches;
}

/**
 * Add a team as a participant in a match
 *
 * @param PDO $pdo Database connection
 * @param int $match_id Match ID
 * @param int $team_id Team ID
 * @param string $position "team1" or "team2" position
 */
function addMatchParticipant($pdo, $match_id, $team_id, $position) {
    // Get team information
    $stmt = $pdo->prepare("SELECT name, logo FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$team) {
        return; // Team not found
    }
    
    // Insert into match_participants
    $stmt = $pdo->prepare("
        INSERT INTO match_participants (
            match_id,
            participant_id,
            name,
            picture,
            status,
            created_at,
            updated_at
        )
        VALUES (?, ?, ?, ?, 'NOT_PLAYED', NOW(), NOW())
    ");
    $stmt->execute([
        $match_id,
        $team_id,
        $team['name'],
        $team['logo']
    ]);
}

/**
 * Seed teams for the playoff bracket
 *
 * @param array $qualified_teams Qualified teams from group stage
 * @param array $playoff_format Playoff format information
 * @return array Seeded teams in bracket order
 */
function seedTeamsForBracket($qualified_teams, $playoff_format) {
    $seeded_teams = [];
    $group_count = count($qualified_teams) / 2; // Number of groups based on 2 teams per group
    
    // Add valid teams from qualified list to our seeded array
    foreach ($qualified_teams as $team) {
        if ($team['team_id'] !== null) {
            $seeded_teams[] = $team;
        }
    }
    
    // For matches with 4 groups (8 teams)
    if ($group_count === 4) {
        // Traditional seeding is:
        // 1A vs 2B, 1C vs 2D, 1B vs 2A, 1D vs 2C
        // Sort the seeded array based on this pattern
        usort($seeded_teams, function($a, $b) {
            // First by position (group winners first)
            if ($a['position'] !== $b['position']) {
                return $a['position'] - $b['position'];
            }
            
            // Then by group (A, B, C, D order)
            return strcmp($a['group_name'], $b['group_name']);
        });
        
        // Now reorder to match the seeding pattern
        if (count($seeded_teams) >= 8) {
            $ordered = array();
            $ordered[0] = $seeded_teams[0]; // 1A
            $ordered[1] = $seeded_teams[5]; // 2B
            $ordered[2] = $seeded_teams[2]; // 1C
            $ordered[3] = $seeded_teams[7]; // 2D
            $ordered[4] = $seeded_teams[1]; // 1B
            $ordered[5] = $seeded_teams[4]; // 2A
            $ordered[6] = $seeded_teams[3]; // 1D
            $ordered[7] = $seeded_teams[6]; // 2C
            $seeded_teams = $ordered;
        }
    } 
    // For matches with 2 groups (4 teams)
    else if ($group_count === 2) {
        // Standard crossover: 1A vs 2B, 1B vs 2A
        usort($seeded_teams, function($a, $b) {
            // First by position
            if ($a['position'] !== $b['position']) {
                return $a['position'] - $b['position'];
            }
            
            // Then by group
            return strcmp($a['group_name'], $b['group_name']);
        });
        
        // Reorder for crossover
        if (count($seeded_teams) >= 4) {
            $ordered = array();
            $ordered[0] = $seeded_teams[0]; // 1A
            $ordered[1] = $seeded_teams[3]; // 2B
            $ordered[2] = $seeded_teams[1]; // 1B
            $ordered[3] = $seeded_teams[2]; // 2A
            $seeded_teams = $ordered;
        }
    }
    // For other numbers of groups, maintain a sensible seeding
    else {
        // Sort by position (winners first), then by group
        usort($seeded_teams, function($a, $b) {
            if ($a['position'] !== $b['position']) {
                return $a['position'] - $b['position'];
            }
            return strcmp($a['group_name'], $b['group_name']);
        });
        
        // For other formats, just ensure that group winners don't face each other in first round
        // and same for runners-up, using snake seeding (1, 2, 2, 1, 1, 2, 2, 1)
        if (count($seeded_teams) > 4) {
            $winners = array_filter($seeded_teams, function($team) {
                return $team['position'] === 1;
            });
            $runners = array_filter($seeded_teams, function($team) {
                return $team['position'] === 2;
            });
            
            $winners = array_values($winners);
            $runners = array_values($runners);
            
            $seeded_teams = array();
            for ($i = 0; $i < count($winners); $i++) {
                if ($i % 2 === 0) {
                    $seeded_teams[] = $winners[$i / 2];
                    $seeded_teams[] = $runners[count($runners) - 1 - ($i / 2)];
                } else {
                    $seeded_teams[] = $winners[count($winners) - 1 - floor($i / 2)];
                    $seeded_teams[] = $runners[floor($i / 2)];
                }
            }
        }
    }
    
    return $seeded_teams;
}