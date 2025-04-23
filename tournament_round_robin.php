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
    
    // Check participation type to know if we're handling teams or individuals
    $is_team_tournament = ($tournament['participation_type'] === 'team');
    
    // Get participants registered for this tournament (either teams or individual players)
    if ($is_team_tournament) {
        // Get registered teams
        $stmt = $pdo->prepare("
            SELECT t.* 
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
                AND name LIKE ? 
                LIMIT 1
            ");
            $stmt->execute([
                $player['id'],
                $tournament['game_id'],
                $player['username'] . ' [PLAYER]'
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
                    // Register the team for the tournament
                    $stmt = $pdo->prepare("
                        INSERT INTO tournament_registrations (tournament_id, team_id, status)
                        VALUES (?, ?, 'accepted')
                    ");
                    $stmt->execute([$tournament_id, $team_id]);
                }
            } else {
                // Create a new temporary team record for the player
                $stmt = $pdo->prepare("
                    INSERT INTO teams (
                        owner_id, 
                        name, 
                        tag, 
                        slug, 
                        game_id, 
                        description, 
                        tier, 
                        logo
                    )
                    VALUES (?, ?, ?, ?, ?, ?, 'amateur', ?)
                ");
                
                // Generate unique slug for the player's virtual team
                $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $player['username'])) . '-' . uniqid();
                
                // Use player info but mark as a virtual team
                $teamName = $player['username'] . ' [PLAYER]';
                $teamTag = 'P' . substr(uniqid(), -4);
                $description = 'Virtual team representing player #' . $player['id'] . ' in individual tournament #' . $tournament_id;
                
                $stmt->execute([
                    $player['id'], // Owner ID is the player
                    $teamName,
                    $teamTag,
                    $slug,
                    $tournament['game_id'],
                    $description,
                    $player['avatar'] // Use player avatar as team logo
                ]);
                
                $team_id = $pdo->lastInsertId();
                
                // Register the team for the tournament
                $stmt = $pdo->prepare("
                    INSERT INTO tournament_registrations (tournament_id, team_id, status)
                    VALUES (?, ?, 'accepted')
                ");
                $stmt->execute([$tournament_id, $team_id]);
            }
            
            // Add team member record to link the player to their team
            $stmt = $pdo->prepare("
                INSERT INTO team_members (team_id, user_id, role, is_captain)
                VALUES (?, ?, 'Player', 1)
            ");
            $stmt->execute([$team_id, $player['id']]);
            
            // Register the team for the tournament
            $stmt = $pdo->prepare("
                INSERT INTO tournament_registrations (tournament_id, team_id, status)
                VALUES (?, ?, 'accepted')
            ");
            $stmt->execute([$tournament_id, $team_id]);
            
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
    
    // Check if groups already exist for this tournament
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM round_robin_groups WHERE tournament_id = ?");
    $stmt->execute([$tournament_id]);
    $existing_groups = (int)$stmt->fetchColumn();
    
    if ($existing_groups > 0) {
        throw new Exception('Round robin groups already exist for this tournament');
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
            'participants' => $group_participants[$i]
        ];
        
        // Add teams to the group
        foreach ($group_participants[$i] as $participant) {
            $stmt = $pdo->prepare("
                INSERT INTO round_robin_group_teams (group_id, team_id, created_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$group_id, $participant['id']]);
            
            // Check if standings already exist for this combination before inserting
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM round_robin_standings 
                WHERE tournament_id = ? AND group_id = ? AND team_id = ?
            ");
            $stmt->execute([$tournament_id, $group_id, $participant['id']]);
            $standings_exist = (int)$stmt->fetchColumn() > 0;
            
            // Only insert if standings don't already exist
            if (!$standings_exist) {
                // Initialize standings record
                $stmt = $pdo->prepare("
                    INSERT INTO round_robin_standings (
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
                    VALUES (?, ?, ?, 0, 0, 0, 0, 0, 0, 0)
                ");
                $stmt->execute([$tournament_id, $group_id, $participant['id']]);
            }
        }
        
        // Generate round-robin matches for this group
        generateRoundRobinMatches($pdo, $tournament_id, $group_id, $group_participants[$i]);
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
 * Generate round-robin matches for a group
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
        $participants[] = ['id' => null, 'name' => 'BYE'];
        $participant_count++;
    }
    
    // Check if matches already exist for this group
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM round_robin_matches 
        WHERE tournament_id = ? AND group_id = ?
    ");
    $stmt->execute([$tournament_id, $group_id]);
    $existing_matches = (int)$stmt->fetchColumn();
    
    // If matches already exist, don't generate new ones
    if ($existing_matches > 0) {
        return;
    }
    
    // Create an array of team IDs
    $team_ids = array_map(function($participant) {
        return $participant['id'];
    }, $participants);
    
    // Total number of rounds needed = (n-1) where n is the number of teams
    $rounds = $participant_count - 1;
    
    // Implementing the Circle Method for round-robin scheduling
    // Team at index 0 stays fixed, others rotate
    
    // Create fixtures for each round
    for ($round = 0; $round < $rounds; $round++) {
        $fixtures = [];
        
        // First match: Fixed team vs rotating team
        $fixtures[] = [$team_ids[0], $team_ids[1 + $round % ($participant_count - 1)]];
        
        // Other matches: Pair teams in circle method
        for ($i = 1; $i < $participant_count / 2; $i++) {
            $team1_idx = (1 + $round - $i + ($participant_count - 1)) % ($participant_count - 1) + 1;
            $team2_idx = (1 + $round + $i) % ($participant_count - 1) + 1;
            $fixtures[] = [$team_ids[$team1_idx], $team_ids[$team2_idx]];
        }
        
        // Insert fixtures into database
        foreach ($fixtures as $fixture) {
            // Skip if either team is a bye
            if ($fixture[0] === null || $fixture[1] === null) {
                continue;
            }
            
            // Check if this match already exists
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM round_robin_matches 
                WHERE tournament_id = ? AND group_id = ? AND round_number = ? 
                AND ((team1_id = ? AND team2_id = ?) OR (team1_id = ? AND team2_id = ?))
            ");
            $stmt->execute([
                $tournament_id,
                $group_id,
                $round,
                $fixture[0], $fixture[1],
                $fixture[1], $fixture[0]
            ]);
            $match_exists = (int)$stmt->fetchColumn() > 0;
            
            // Only insert if match doesn't already exist
            if (!$match_exists) {
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
                    $fixture[0],
                    $fixture[1]
                ]);
            }
        }
    }
}