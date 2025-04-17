<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$db_config = require 'db_config.php';

try {
    // Database connection
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Validate tournament ID
    $tournament_id = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : null;
    if (!$tournament_id) {
        throw new Exception('Tournament ID parameter is required');
    }

    // Get tournament details
    $tournamentQuery = "
        SELECT participation_type, bracket_type, status, max_participants
        FROM tournaments 
        WHERE id = :tournament_id
    ";
    $stmt = $pdo->prepare($tournamentQuery);
    $stmt->execute([':tournament_id' => $tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        throw new Exception('Tournament not found');
    }

    $participants = [];

    if ($tournament['participation_type'] === 'team') {
        // Query for team registrations
        $teamQuery = "
            SELECT 
                tr.id,
                tr.tournament_id,
                tr.team_id,
                tr.registration_date,
                tr.status,
                t.name as team_name,
                t.game_id as team_game,
                t.logo as team_image,
                t.description,
                t.win_rate,
                t.division,
                u.username as owner_name,
                u.email as owner_email,
                u.avatar as owner_avatar,
                t.total_members as member_count
            FROM tournament_registrations tr
            JOIN teams t ON tr.team_id = t.id
            JOIN users u ON t.owner_id = u.id
            WHERE tr.tournament_id = :tournament_id
            ORDER BY tr.registration_date DESC
        ";

        $stmt = $pdo->prepare($teamQuery);
        $stmt->execute([':tournament_id' => $tournament_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fix team image paths
        foreach ($participants as &$participant) {
            if ($participant['team_image'] && strpos($participant['team_image'], 'http') !== 0) {
                $participant['team_image'] = $participant['team_image'];
            }
            
            if ($participant['owner_avatar'] && strpos($participant['owner_avatar'], 'http') !== 0) {
                $participant['owner_avatar'] = $participant['owner_avatar'];
            }
        }

        // Get team members for each team
        foreach ($participants as &$participant) {
            $memberQuery = "
                SELECT 
                    tm.id as member_id,
                    u.username as name,
                    tm.role,
                    u.rank,
                    'active' as member_status,
                    0 as is_substitute,
                    u.id as user_id,
                    u.email,
                    u.points,
                    u.avatar as avatar,
                    CASE 
                        WHEN u.id = t.owner_id THEN 'Captain'
                        ELSE 'Member' 
                    END as position
                FROM team_members tm
                JOIN teams t ON tm.team_id = t.id
                JOIN users u ON tm.user_id = u.id
                WHERE tm.team_id = :team_id 
                ORDER BY 
                    tm.is_captain DESC,
                    tm.join_date DESC
            ";
            
            $memberStmt = $pdo->prepare($memberQuery);
            $memberStmt->execute([':team_id' => $participant['team_id']]);
            $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

            // Process member data
            foreach ($members as &$member) {
                // Fix avatar paths
                if ($member['avatar'] && strpos($member['avatar'], 'http') !== 0) {
                    $member['avatar'] = $member['avatar'];
                }
                
                // Generate a data URI for default avatar if none exists
                if (empty($member['avatar'])) {
                    $member['avatar'] = 'data:image/svg+xml;utf8,' . urlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="50" height="50"><rect width="100" height="100" fill="#E0E0E0"/><circle cx="50" cy="40" r="20" fill="#A0A0A0"/><circle cx="50" cy="100" r="35" fill="#A0A0A0"/></svg>');
                }
            }

            $participant['members'] = $members;
        }
    } else {
        // Query for individual participants
        $playerQuery = "
            SELECT 
                tr.id,
                tr.tournament_id,
                tr.user_id,
                tr.registration_date,
                tr.status,
                u.username as name,
                u.email,
                u.avatar,
                u.bio,
                u.points,
                u.rank
            FROM tournament_registrations tr
            JOIN users u ON tr.user_id = u.id
            WHERE tr.tournament_id = :tournament_id
            ORDER BY tr.registration_date DESC
        ";
            
        $stmt = $pdo->prepare($playerQuery);
        $stmt->execute([':tournament_id' => $tournament_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process individual participant avatars
        foreach ($participants as &$participant) {
            if ($participant['avatar'] && strpos($participant['avatar'], 'http') !== 0) {
                $participant['avatar'] = $participant['avatar'];
            }
            
            // Generate a data URI for default avatar if none exists
            if (empty($participant['avatar'])) {
                $participant['avatar'] = 'data:image/svg+xml;utf8,' . urlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="50" height="50"><rect width="100" height="100" fill="#E0E0E0"/><circle cx="50" cy="40" r="20" fill="#A0A0A0"/><circle cx="50" cy="100" r="35" fill="#A0A0A0"/></svg>');
            }
        }
    }

    // Return response with additional tournament details
    echo json_encode([
        'success' => true,
        'tournament_type' => $tournament['participation_type'],
        'game_type' => $tournament['bracket_type'],
        'tournament_status' => $tournament['status'],
        'max_participants' => $tournament['max_participants'],
        'current_participants' => count($participants),
        'profiles' => $participants,
        'message' => count($participants) > 0 ? null : 'No registrations found for this tournament'
    ]);

} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('General Error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>