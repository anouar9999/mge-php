<?php
// tournament_round_robin.php - Handles creating round robin tournament groups and matches

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

    // Get request data for any additional parameters
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    
    // Get number of groups from request body if provided
    $num_groups = isset($data['num_groups']) ? (int)$data['num_groups'] : null;
    
    // Validate number of groups if provided
    if ($num_groups !== null && $num_groups < 1) {
        $num_groups = 1;
    }
    
    // Start a transaction for data consistency
    $pdo->beginTransaction();
    
    // First get the tournament details
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    // Check if tournament is round robin
    if ($tournament['bracket_type'] !== 'Round Robin') {
        throw new Exception('This tournament is not a Round Robin tournament');
    }
    
    // Get teams registered for this tournament
    $stmt = $pdo->prepare("
        SELECT t.* 
        FROM teams t
        JOIN tournament_registrations tr ON t.id = tr.team_id
        WHERE tr.tournament_id = ? AND tr.status = 'accepted'
    ");
    $stmt->execute([$tournament_id]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($teams) < 2) {
        throw new Exception('Not enough teams registered for a round robin tournament. Minimum 2 teams required.');
    }
    
    // Calculate optimal number of groups if not specified
    $team_count = count($teams);
    if ($num_groups === null) {
        // Calculate optimal number of groups based on team count
        if ($team_count <= 4) {
            $num_groups = 1; // Small number of teams, just one group
        } else if ($team_count <= 8) {
            $num_groups = 2; // Medium number, two groups
        } else {
            $num_groups = ceil($team_count / 4); // Aim for ~4 teams per group
            if ($num_groups > 4) $num_groups = 4; // Cap at 4 groups
        }
    }
    
    // Make sure we don't have too many groups for the number of teams
    if ($num_groups > floor($team_count / 2)) {
        $num_groups = max(1, floor($team_count / 2));
    }
    
    // Check if groups already exist for this tournament
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM round_robin_groups WHERE tournament_id = ?");
    $stmt->execute([$tournament_id]);
    $existing_groups = (int)$stmt->fetchColumn();
    
    if ($existing_groups > 0) {
        throw new Exception('Round robin groups already exist for this tournament');
    }
    
    // Create groups and distribute teams (using a seeding algorithm)
    $groups = [];
    $group_teams = array_fill(0, $num_groups, []);
    
    // Sort teams by some criteria (e.g., rank or random)
    // For simplicity, we'll use the team ID as a proxy for "seeding"
    // In a real app, you might use ELO ratings or past performance
    usort($teams, function($a, $b) {
        return $a['id'] - $b['id']; // Simple sort by ID (proxy for seeding)
    });
    
    // Distribute teams using snake draft (1,2,3,3,2,1,1,2,3...)
    $direction = 1;
    $current_group = 0;
    foreach ($teams as $team) {
        $group_teams[$current_group][] = $team;
        
        // Move to next group in the appropriate direction
        $current_group += $direction;
        
        // If we reached the last or first group, change direction
        if ($current_group >= $num_groups) {
            $current_group = $num_groups - 1;
            $direction = -1;
        } else if ($current_group < 0) {
            $current_group = 0;
            $direction = 1;
        }
    }
    
    // Create groups in the database
    for ($i = 0; $i < $num_groups; $i++) {
        $group_name = "Group " . chr(65 + $i); // A, B, C, etc.
        $is_primary = ($num_groups === 1) ? 1 : 0;
        
        $stmt = $pdo->prepare("
            INSERT INTO round_robin_groups (tournament_id, name, is_primary, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$tournament_id, $group_name, $is_primary]);
        $group_id = $pdo->lastInsertId();
        
        $groups[] = [
            'id' => $group_id,
            'name' => $group_name,
            'is_primary' => $is_primary,
            'teams' => $group_teams[$i]
        ];
        
        // Add teams to the group
        foreach ($group_teams[$i] as $team) {
            $stmt = $pdo->prepare("
                INSERT INTO round_robin_group_teams (group_id, team_id, created_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$group_id, $team['id']]);
        }
        
        // Generate round-robin matches for this group
        generateRoundRobinMatches($pdo, $tournament_id, $group_id, $group_teams[$i]);
    }
    
    // Update tournament status if needed
    if ($tournament['status'] === 'registration_closed') {
        $stmt = $pdo->prepare("
            UPDATE tournaments
            SET status = 'ongoing', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$tournament_id]);
    }
    
    // Commit the transaction
    $pdo->commit();
    
    // Return success response
    echo safe_json_encode([
        'success' => true,
        'message' => 'Round robin groups and matches created successfully',
        'data' => [
            'tournament_id' => $tournament_id,
            'groups' => $groups
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
 * Generate round-robin matches for a group
 *
 * @param PDO $pdo Database connection
 * @param int $tournament_id Tournament ID
 * @param int $group_id Group ID
 * @param array $teams Teams in the group
 */
function generateRoundRobinMatches($pdo, $tournament_id, $group_id, $teams) {
    $team_count = count($teams);
    
    // If odd number of teams, add a "bye" team
    $hasBye = false;
    if ($team_count % 2 !== 0) {
        $hasBye = true;
        $team_count++;
    }
    
    $rounds = $team_count - 1;
    $matches_per_round = $team_count / 2;
    
    // Create array of team IDs
    $team_ids = array_map(function($team) {
        return $team['id'];
    }, $teams);
    
    // If we have a bye, add a null value
    if ($hasBye) {
        $team_ids[] = null;
    }
    
    // Calculate the number of days required for the tournament
    // In a real app, you'd use tournament start/end dates to spread matches
    $tournament_days = $rounds;
    
    // Generate the schedule using the circle method
    for ($round = 0; $round < $rounds; $round++) {
        // The first team is fixed
        $first_team = $team_ids[0];
        
        // All other teams rotate clockwise
        $other_teams = array_slice($team_ids, 1);
        
        // Generate pairings for this round
        $pairings = [];
        for ($i = 0; $i < $matches_per_round; $i++) {
            if ($i === 0) {
                // First team is paired with the team across from it
                $team1 = $first_team;
                $team2 = $other_teams[0];
            } else {
                // All other pairings
                $team1 = $other_teams[$i - 1];
                $team2 = $other_teams[count($other_teams) - $i];
            }
            
            // Skip matches involving the bye team
            if ($team1 !== null && $team2 !== null) {
                $pairings[] = [$team1, $team2];
            }
        }
        
        // Rotate for next round (Circle method)
        array_unshift($other_teams, array_pop($other_teams));
        $team_ids = array_merge([$first_team], $other_teams);
        
        // Insert matches into database
        foreach ($pairings as $pair) {
            $stmt = $pdo->prepare("
                INSERT INTO round_robin_matches (
                    tournament_id, 
                    group_id, 
                    round_number, 
                    team1_id, 
                    team2_id, 
                    team1_score, 
                    team2_score, 
                    winner_id, 
                    match_date, 
                    status,
                    created_at, 
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, 'scheduled', NOW(), NOW())
            ");
            $stmt->execute([
                $tournament_id,
                $group_id,
                $round,
                $pair[0],
                $pair[1]
            ]);
        }
    }
}