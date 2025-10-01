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
    
    // RESET EXISTING TOURNAMENT DATA
    // =======================================================================
    
    // Store the tournament_registrations with accepted status (we'll keep these)
    $stmt = $pdo->prepare("
        SELECT id, tournament_id, user_id, team_id, status
        FROM tournament_registrations 
        WHERE tournament_id = ? AND status = 'accepted'
    ");
    $stmt->execute([$tournament_id]);
    $acceptedRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get next activity_log ID
    $stmt = $pdo->query("SELECT MAX(id) as max_id FROM activity_log");
    $activityLogId = (int)$stmt->fetchColumn() + 1;
    
    // Log action
    $logStmt = $pdo->prepare("
        INSERT INTO activity_log (id, user_id, action, details, ip_address)
        VALUES (?, ?, ?, ?, ?)
    ");
    $logStmt->execute([
        $activityLogId,
        1,  // Default to admin ID 1
        'Tournament Reset',
        "Reset tournament ID: {$tournament_id} before round-robin setup",
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
    ]);
    
    // 1. Delete round robin matches
    $stmt = $pdo->prepare("DELETE FROM round_robin_matches WHERE tournament_id = ?");
    $stmt->execute([$tournament_id]);
    
    // 2. Delete round robin standings
    $stmt = $pdo->prepare("DELETE FROM round_robin_standings WHERE tournament_id = ?");
    $stmt->execute([$tournament_id]);
    
    // 3. Delete round robin group teams
    $stmt = $pdo->prepare("
        DELETE rrgt FROM round_robin_group_teams rrgt
        JOIN round_robin_groups rrg ON rrgt.group_id = rrg.id
        WHERE rrg.tournament_id = ?
    ");
    $stmt->execute([$tournament_id]);
    
    // 4. Delete round robin groups
    $stmt = $pdo->prepare("DELETE FROM round_robin_groups WHERE tournament_id = ?");
    $stmt->execute([$tournament_id]);
    
    // We don't need to delete and restore registrations since we'll be using the acceptedRegistrations
    // directly for participant selection
    // =======================================================================
    
    // Check participation type to know if we're handling teams or individuals
    $is_team_tournament = ($tournament['participation_type'] === 'team');
    
    // Get participants registered for this tournament (either teams or individual players)
    if ($is_team_tournament) {
        // Get registered teams with explicit column list instead of t.*
        $stmt = $pdo->prepare("
            SELECT t.id, t.owner_id, t.name, t.tag, t.slug, t.game_id, t.description, 
                   t.logo, t.banner, t.division, t.tier, t.wins, t.losses, t.draws, 
                   t.win_rate, t.total_members, t.captain_id, t.discord, t.twitter, 
                   t.contact_email, t.created_at, t.updated_at
            FROM teams t
            JOIN tournament_registrations tr ON t.id = tr.team_id
            WHERE tr.tournament_id = ? AND tr.status = 'accepted'
        ");
        $stmt->execute([$tournament_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check for minimum teams
        if (count($participants) < 2) {
            throw new Exception('Not enough teams registered for a round robin tournament. Minimum 2 teams required.');
        }
    } else {
        // For individual tournaments, we'll create temporary teams to represent players
        // Get registered individual players
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.avatar 
            FROM users u
            JOIN tournament_registrations tr ON u.id = tr.user_id
            WHERE tr.tournament_id = ? AND tr.status = 'accepted'
        ");
        $stmt->execute([$tournament_id]);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check for minimum players
        if (count($players) < 2) {
            throw new Exception('Not enough players registered for a round robin tournament. Minimum 2 players required.');
        }
        
        // Create virtual "teams" for each player (we'll store metadata about the player in the team name/tag)
        $participants = [];
        foreach ($players as $player) {
            // First check if a virtual team already exists for this player and game
            $stmt = $pdo->prepare("
                SELECT id FROM teams 
                WHERE owner_id = ? 
                AND game_id = ? 
                AND name = ? 
                LIMIT 1
            ");
            $stmt->execute([
                $player['id'],
                $tournament['game_id'],
                $player['username']  // Look for team with exact player name without [PLAYER]
            ]);
            
            $existing_team = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_team) {
                // Use the existing team
                $team_id = $existing_team['id'];
                
                // Make sure it's registered for this tournament
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM tournament_registrations 
                    WHERE tournament_id = ? AND team_id = ?
                ");
                $stmt->execute([$tournament_id, $team_id]);
                $is_registered = (int)$stmt->fetchColumn() > 0;
                
                if (!$is_registered) {
                    // Get next tournament_registrations ID
                    $stmt = $pdo->query("SELECT MAX(id) as max_id FROM tournament_registrations");
                    $regId = (int)$stmt->fetchColumn() + 1;
                    
                    // Register the team for the tournament
                    $stmt = $pdo->prepare("
                        INSERT INTO tournament_registrations (id, tournament_id, team_id, status)
                        VALUES (?, ?, ?, 'accepted')
                    ");
                    $stmt->execute([$regId, $tournament_id, $team_id]);
                }
            } else {
                // Get next team ID
                $stmt = $pdo->query("SELECT MAX(id) as max_id FROM teams");
                $team_id = (int)$stmt->fetchColumn() + 1;
                
                // Create a new temporary team record for the player
                $stmt = $pdo->prepare("
                    INSERT INTO teams (
                        id,
                        owner_id, 
                        name, 
                        tag, 
                        slug, 
                        game_id, 
                        description, 
                        tier, 
                        logo
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'amateur', ?)
                ");
                
                // Generate unique slug for the player's virtual team
                $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $player['username'])) . '-' . uniqid();
                
                // Use player info for virtual team
                $teamName = $player['username'];  // Removed [PLAYER] suffix
                $teamTag = 'P' . substr(uniqid(), -4);
                $description = 'Virtual team representing player #' . $player['id'] . ' in individual tournament #' . $tournament_id;
                
                $stmt->execute([
                    $team_id,
                    $player['id'], // Owner ID is the player
                    $teamName,
                    $teamTag,
                    $slug,
                    $tournament['game_id'],
                    $description,
                    $player['avatar'] // Use player avatar as team logo
                ]);
                
                // Get next tournament_registrations ID
                $stmt = $pdo->query("SELECT MAX(id) as max_id FROM tournament_registrations");
                $regId = (int)$stmt->fetchColumn() + 1;
                
                // Register the team for the tournament
                $stmt = $pdo->prepare("
                    INSERT INTO tournament_registrations (id, tournament_id, team_id, status)
                    VALUES (?, ?, ?, 'accepted')
                ");
                $stmt->execute([$regId, $tournament_id, $team_id]);
            }
            
            // Add this team to our participants list
            $participants[] = [
                'id' => $team_id,
                'name' => $teamName,
                'tag' => $teamTag,
                'player_id' => $player['id'],
                'username' => $player['username'],
                'avatar' => $player['avatar'],
                'is_virtual' => true
            ];
        }
    }
    
    // Calculate optimal number of groups if not specified
    $participant_count = count($participants);
    if ($num_groups === null) {
        // Calculate optimal number of groups based on participant count
        if ($participant_count <= 4) {
            $num_groups = 1; // Small number of participants, just one group
        } else if ($participant_count <= 8) {
            $num_groups = 2; // Medium number, two groups
        } else {
            $num_groups = ceil($participant_count / 4); // Aim for ~4 participants per group
            if ($num_groups > 4) $num_groups = 4; // Cap at 4 groups
        }
    }
    
    // Make sure we don't have too many groups for the number of participants
    if ($num_groups > floor($participant_count / 2)) {
        $num_groups = max(1, floor($participant_count / 2));
    }
    
    // Create groups and distribute participants (using a seeding algorithm)
    $groups = [];
    $group_participants = array_fill(0, $num_groups, []);
    
    // Sort participants by some criteria (e.g., rank or random)
    // For simplicity, we'll use the ID as a proxy for "seeding"
    usort($participants, function($a, $b) {
        return $a['id'] - $b['id']; // Simple sort by ID
    });
    
    // Distribute participants using snake draft (1,2,3,3,2,1,1,2,3...)
    $direction = 1;
    $current_group = 0;
    foreach ($participants as $participant) {
        $group_participants[$current_group][] = $participant;
        
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
        
        // Get next round_robin_groups ID
        $stmt = $pdo->query("SELECT MAX(id) as max_id FROM round_robin_groups");
        $groupId = (int)$stmt->fetchColumn() + 1;
        
        $stmt = $pdo->prepare("
            INSERT INTO round_robin_groups (id, tournament_id, name, is_primary, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$groupId, $tournament_id, $group_name, $is_primary]);
        
        $groups[] = [
            'id' => $groupId,
            'name' => $group_name,
            'is_primary' => $is_primary,
            'participants' => $group_participants[$i]
        ];
        
        // Add teams to the group
        foreach ($group_participants[$i] as $participant) {
            // Get next round_robin_group_teams ID
            $stmt = $pdo->query("SELECT MAX(id) as max_id FROM round_robin_group_teams");
            $groupTeamId = (int)$stmt->fetchColumn() + 1;
            
            $stmt = $pdo->prepare("
                INSERT INTO round_robin_group_teams (id, group_id, team_id, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$groupTeamId, $groupId, $participant['id']]);
            
            // First check if standings record already exists
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM round_robin_standings 
                WHERE tournament_id = ? AND group_id = ? AND team_id = ?
            ");
            $checkStmt->execute([$tournament_id, $groupId, $participant['id']]);
            $recordExists = (int)$checkStmt->fetchColumn() > 0;
            
            if (!$recordExists) {
                // Get next round_robin_standings ID
                $stmt = $pdo->query("SELECT MAX(id) as max_id FROM round_robin_standings");
                $standingsId = (int)$stmt->fetchColumn() + 1;
                
                // Initialize standings record
                $stmt = $pdo->prepare("
                    INSERT INTO round_robin_standings (
                        id,
                        tournament_id, 
                        group_id, 
                        team_id, 
                        matches_played, 
                        wins, 
                        draws, 
                        losses, 
                        goals_for, 
                        goals_against, 
                        points
                    )
                    VALUES (?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 0)
                ");
                $stmt->execute([$standingsId, $tournament_id, $groupId, $participant['id']]);
            }
        }
        
        // Generate round-robin matches for this group
        generateRoundRobinMatches($pdo, $tournament_id, $groupId, $group_participants[$i]);
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
            'groups' => $groups,
            'participation_type' => $tournament['participation_type']
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
 * Generate round-robin matches for a group using the Circle Method algorithm
 * This implementation mirrors the JavaScript version from the frontend
 * and ensures proper auto-increment of match IDs
 *
 * @param PDO $pdo Database connection
 * @param int $tournament_id Tournament ID
 * @param int $group_id Group ID
 * @param array $participants Teams or virtual teams representing players
 */
function generateRoundRobinMatches($pdo, $tournament_id, $group_id, $participants) {
    $participant_count = count($participants);
    
    // If odd number of participants, add a "bye" participant
    $hasBye = false;
    if ($participant_count % 2 !== 0) {
        $hasBye = true;
        // Add a BYE participant
        $participants[] = ['id' => 'bye', 'name' => 'BYE'];
        $participant_count++;
    }
    
    // Number of rounds needed
    $rounds = $participant_count - 1;
    $half = $participant_count / 2;
    
    // Create an array of participant indices (excluding first participant)
    $teamIndices = range(1, $participant_count - 1);
    
    // Generate matches for each round
    for ($round = 0; $round < $rounds; $round++) {
        // For each round, the first team stays fixed and others rotate
        $newIndices = array_merge([0], $teamIndices);
        
        // Generate matches for this round
        $fixtures = [];
        for ($match = 0; $match < $half; $match++) {
            $team1 = $participants[$newIndices[$match]];
            $team2 = $participants[$newIndices[$participant_count - 1 - $match]];
            
            // Skip matches with BYE
            if ($team1['id'] !== 'bye' && $team2['id'] !== 'bye') {
                $fixtures[] = [
                    'team1_id' => $team1['id'],
                    'team2_id' => $team2['id'],
                    'matchIndex' => $match
                ];
            }
        }
        
        // Insert fixtures into database
        foreach ($fixtures as $fixture) {
            // Get next round_robin_matches ID
            $stmt = $pdo->query("SELECT MAX(id) as max_id FROM round_robin_matches");
            $matchId = (int)$stmt->fetchColumn() + 1;
            
            // Let the database handle auto-incrementing the ID
            $stmt = $pdo->prepare("
                INSERT INTO round_robin_matches (
                    id,
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
                VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, 'scheduled', NOW(), NOW())
            ");
            $stmt->execute([
                $matchId,
                $tournament_id,
                $group_id,
                $round,
                $fixture['team1_id'],
                $fixture['team2_id']
            ]);
        }
        
        // Rotate the teams (except the first one) for the next round
        // This is the key to the round robin algorithm
        array_unshift($teamIndices, array_pop($teamIndices));
    }
}
