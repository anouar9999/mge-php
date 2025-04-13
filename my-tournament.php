<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $db_config = require 'db_config.php';
    if (!$db_config) {
        throw new Exception('Database configuration not found');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if (!isset($_GET['user_id'])) {
        throw new Exception('User ID is required');
    }
    $user_id = filter_var($_GET['user_id'], FILTER_VALIDATE_INT);
    if (!$user_id) {
        throw new Exception('Invalid user ID');
    }

    // Get user's username for team membership checks
    $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = :user_id");
    $userStmt->execute([':user_id' => $user_id]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        throw new Exception('User not found');
    }
    
    $username = $userData['username'];

    // Step 1: Get tournaments where the user is directly registered
    $individualQuery = "
        SELECT t.*, 
               g.name as game_name,
               g.slug as game_slug,
               g.image as game_image,
               g.id as game_id,
               tr.status as registration_status,
               tr.registration_date,
               NULL as team_name,
               (SELECT COUNT(*) FROM tournament_registrations 
                WHERE tournament_id = t.id AND status IN ('pending', 'accepted')) as registered_count
        FROM tournaments t
        JOIN tournament_registrations tr ON t.id = tr.tournament_id
        LEFT JOIN games g ON t.game_id = g.id
        WHERE t.participation_type = 'individual' 
        AND tr.user_id = :user_id
    ";
    
    // Step 2: Get tournaments where user is part of a registered team (as owner)
    $teamOwnerQuery = "
        SELECT t.*, 
               g.name as game_name,
               g.slug as game_slug,
               g.image as game_image,
               g.id as game_id,
               tr.status as registration_status,
               tr.registration_date,
               tm.name as team_name,
               (SELECT COUNT(*) FROM tournament_registrations 
                WHERE tournament_id = t.id AND status IN ('pending', 'accepted')) as registered_count
        FROM tournaments t
        JOIN tournament_registrations tr ON t.id = tr.tournament_id
        JOIN teams tm ON tr.team_id = tm.id
        LEFT JOIN games g ON t.game_id = g.id
        WHERE t.participation_type = 'team' 
        AND tm.owner_id = :user_id
    ";
    
    // Execute each query separately for easier debugging
    $individualTournaments = [];
    $teamOwnerTournaments = [];
    $teamMemberTournaments = [];
    
    // Try individual query
    try {
        $stmt1 = $pdo->prepare($individualQuery);
        $stmt1->execute([':user_id' => $user_id]);
        $individualTournaments = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log but continue
        error_log("Individual query failed: " . $e->getMessage());
    }
    
    // Try team owner query
    try {
        $stmt2 = $pdo->prepare($teamOwnerQuery);
        $stmt2->execute([':user_id' => $user_id]);
        $teamOwnerTournaments = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log but continue
        error_log("Team owner query failed: " . $e->getMessage());
    }
    
    // For team member tournaments, use a more robust approach
    try {
        // First get all teams the user is a member of
        // Get the structure of team_members table
        $tableInfoQuery = "DESCRIBE team_members";
        $tableInfoStmt = $pdo->prepare($tableInfoQuery);
        $tableInfoStmt->execute();
        $columns = $tableInfoStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Build query based on available columns
        $teamMembershipQuery = "SELECT team_id FROM team_members WHERE ";
        
        $conditions = [];
        $params = [];
        
        // Check if 'name' column exists
        if (in_array('name', $columns)) {
            $conditions[] = "name = :username";
            $params[':username'] = $username;
        }
        
        // Check if 'is_active' column exists
        if (in_array('is_active', $columns)) {
            $conditions[] = "is_active = 1";
        }
        
        if (empty($conditions)) {
            // If no usable conditions, just get all team IDs
            $teamMembershipQuery = "SELECT team_id FROM team_members";
        } else {
            $teamMembershipQuery .= implode(" AND ", $conditions);
        }
        
        $teamMembershipStmt = $pdo->prepare($teamMembershipQuery);
        $teamMembershipStmt->execute($params);
        $userTeams = $teamMembershipStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($userTeams)) {
            // Get tournaments for these teams
            $placeholders = implode(',', array_fill(0, count($userTeams), '?'));
            $teamMemberQuery = "
                SELECT t.*, 
                       g.name as game_name,
                       g.slug as game_slug,
                       g.image as game_image,
                       g.id as game_id,
                       tr.status as registration_status,
                       tr.registration_date,
                       tm.name as team_name,
                       (SELECT COUNT(*) FROM tournament_registrations 
                        WHERE tournament_id = t.id AND status IN ('pending', 'accepted')) as registered_count
                FROM tournaments t
                JOIN tournament_registrations tr ON t.id = tr.tournament_id
                JOIN teams tm ON tr.team_id = tm.id
                LEFT JOIN games g ON t.game_id = g.id
                WHERE t.participation_type = 'team' 
                AND tr.team_id IN ($placeholders)
            ";
            
            $stmt3 = $pdo->prepare($teamMemberQuery);
            $stmt3->execute($userTeams);
            $teamMemberTournaments = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Log but continue
        error_log("Team member query failed: " . $e->getMessage());
        
        // Alternative approach - get tournaments with team participation and check membership manually
        try {
            // Get all team-based tournaments
            $allTeamTournamentsQuery = "
                SELECT t.id 
                FROM tournaments t
                JOIN tournament_registrations tr ON t.id = tr.tournament_id
                WHERE t.participation_type = 'team' 
                AND tr.team_id IS NOT NULL
            ";
            
            $allTeamStmt = $pdo->prepare($allTeamTournamentsQuery);
            $allTeamStmt->execute();
            $allTeamTournaments = $allTeamStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // For each tournament, check if user is a member of the registered team
            foreach ($allTeamTournaments as $tournamentId) {
                // Get the team for this tournament
                $teamQuery = "
                    SELECT tr.team_id, t.* 
                    FROM tournament_registrations tr
                    JOIN teams t ON tr.team_id = t.id
                    WHERE tr.tournament_id = :tournament_id
                    LIMIT 1
                ";
                
                $teamStmt = $pdo->prepare($teamQuery);
                $teamStmt->execute([':tournament_id' => $tournamentId]);
                $teamData = $teamStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($teamData) {
                    // Check if user is a member of this team
                    // First by checking if owner
                    $isOwner = ($teamData['owner_id'] == $user_id);
                    
                    // Then by checking membership
                    $isMember = false;
                    
                    // Custom query to check membership based on username
                    $memberCheckQuery = "
                        SELECT COUNT(*) as count 
                        FROM team_members 
                        WHERE team_id = :team_id
                    ";
                    
                    // Add name condition if column exists
                    if (in_array('name', $columns)) {
                        $memberCheckQuery .= " AND name = :username";
                    }
                    
                    // Add is_active condition if column exists
                    if (in_array('is_active', $columns)) {
                        $memberCheckQuery .= " AND is_active = 1";
                    }
                    
                    $memberParams = [':team_id' => $teamData['team_id']];
                    if (in_array('name', $columns)) {
                        $memberParams[':username'] = $username;
                    }
                    
                    $memberStmt = $pdo->prepare($memberCheckQuery);
                    $memberStmt->execute($memberParams);
                    $memberData = $memberStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $isMember = ($memberData['count'] > 0);
                    
                    // If user is a member and not already counted as owner
                    if ($isMember && !$isOwner) {
                        // Get full tournament data
                        $tournamentQuery = "
                            SELECT t.*, 
                                   g.name as game_name,
                                   g.slug as game_slug,
                                   g.image as game_image,
                                   g.id as game_id,
                                   tr.status as registration_status,
                                   tr.registration_date,
                                   tm.name as team_name,
                                   (SELECT COUNT(*) FROM tournament_registrations 
                                    WHERE tournament_id = t.id AND status IN ('pending', 'accepted')) as registered_count
                            FROM tournaments t
                            JOIN tournament_registrations tr ON t.id = tr.tournament_id
                            JOIN teams tm ON tr.team_id = tm.id
                            LEFT JOIN games g ON t.game_id = g.id
                            WHERE t.id = :tournament_id
                        ";
                        
                        $tournamentStmt = $pdo->prepare($tournamentQuery);
                        $tournamentStmt->execute([':tournament_id' => $tournamentId]);
                        $tournamentData = $tournamentStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($tournamentData) {
                            $teamMemberTournaments[] = $tournamentData;
                        }
                    }
                }
            }
        } catch (PDOException $e2) {
            // Log but continue
            error_log("Alternative team member approach failed: " . $e2->getMessage());
        }
    }
    
    // Combine results
    $tournaments = array_merge($individualTournaments, $teamOwnerTournaments, $teamMemberTournaments);
    
    // Remove duplicates (if a user is both team owner and member)
    $uniqueTournaments = [];
    $seenIds = [];
    foreach ($tournaments as $tournament) {
        if (!in_array($tournament['id'], $seenIds)) {
            $uniqueTournaments[] = $tournament;
            $seenIds[] = $tournament['id'];
        }
    }
    
    // Sort by creation date
    usort($uniqueTournaments, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    // Process tournaments with calculated fields
    $processedTournaments = array_map(function($tournament) {
        $maxSpots = isset($tournament['max_participants']) ? (int)$tournament['max_participants'] : 0;
        $registeredCount = isset($tournament['registered_count']) ? (int)$tournament['registered_count'] : 0;

        // Create a game object with game-related information
        $gameInfo = [
            'id' => isset($tournament['game_id']) ? (int)$tournament['game_id'] : null,
            'name' => isset($tournament['game_name']) ? $tournament['game_name'] : null,
            'slug' => isset($tournament['game_slug']) ? $tournament['game_slug'] : null,
            'image' => isset($tournament['game_image']) ? $tournament['game_image'] : null
        ];

        $result = [
            'id' => (int)$tournament['id'],
            'registered_count' => $registeredCount,
            'max_spots' => $maxSpots,
            'spots_remaining' => max(0, $maxSpots - $registeredCount),
            'registration_progress' => [
                'total' => $maxSpots,
                'filled' => $registeredCount,
                'percentage' => $maxSpots > 0 ? round(($registeredCount / $maxSpots) * 100, 1) : 0
            ],
            'time_info' => [
                'is_started' => strtotime($tournament['start_date']) <= time(),
                'is_ended' => strtotime($tournament['end_date']) < time(),
                'days_remaining' => max(0, ceil((strtotime($tournament['end_date']) - time()) / (60 * 60 * 24)))
            ],
            'game' => $gameInfo  // Added game info as a structured object
        ];
        
        // Copy all other fields directly
        foreach ($tournament as $key => $value) {
            if (!isset($result[$key]) && $key != 'game_id' && $key != 'game_name' && $key != 'game_slug' && $key != 'game_image') {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }, $uniqueTournaments);

    echo json_encode([
        'success' => true,
        'tournaments' => $processedTournaments,
        'total_count' => count($processedTournaments),
        'debug' => [
            'individual_count' => count($individualTournaments),
            'team_owner_count' => count($teamOwnerTournaments),
            'team_member_count' => count($teamMemberTournaments)
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage(), // Remove in production
        'trace' => $e->getTraceAsString() // Remove in production
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>