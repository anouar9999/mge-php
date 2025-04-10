<?php
// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// Check if tournament_id is provided
if (!isset($_GET['tournament_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Tournament ID is required']);
    exit();
}

$tournament_id = intval($_GET['tournament_id']);

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // First get the tournament details
    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.name,
            t.participation_type,
            t.status,
            t.bracket_type,
            t.max_participants,
            g.name AS game_name
        FROM tournaments t
        LEFT JOIN games g ON t.game_id = g.id
        WHERE t.id = :tournament_id
    ");
    $stmt->execute([':tournament_id' => $tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tournament not found']);
        exit();
    }

    // Check if the tournament status allows viewing participants
    $validStatuses = ['registration_closed', 'ongoing', 'completed'];
    if (!in_array($tournament['status'], $validStatuses)) {
        // For registration_open, draft, and cancelled tournaments, we still show participants but add a notice
        $statusMessage = ($tournament['status'] === 'registration_open') 
            ? "Registration is still open for this tournament" 
            : "This tournament is not active";
    } else {
        $statusMessage = null;
    }

    // Prepare the appropriate query based on tournament type
    if ($tournament['participation_type'] === 'individual') {
        // Query for individual participants
        $stmt = $pdo->prepare("
            SELECT 
                tr.id as registration_id,
                tr.registration_date,
                tr.status,
                tr.decision_date,
                u.id as user_id,
                u.username,
                u.email,
                u.avatar,
                u.bio,
                u.points,
                'individual' as type
            FROM tournament_registrations tr
            LEFT JOIN users u ON tr.user_id = u.id
            WHERE tr.tournament_id = :tournament_id
              AND tr.status = 'accepted'
              AND tr.user_id IS NOT NULL
            ORDER BY tr.registration_date ASC
        ");
    } else {
        // Query for team participants
        $stmt = $pdo->prepare("
            SELECT 
                tr.id as registration_id,
                tr.registration_date,
                tr.status,
                tr.decision_date,
                t.id as team_id,
                t.name as team_name,
                t.image as team_avatar,
                t.description as team_bio,
                t.division,
                t.mmr,
                t.win_rate,
                t.owner_id,
                t.total_members,
                t.active_members,
                t.average_rank,
                t.team_game,
                'team' as type
            FROM tournament_registrations tr
            LEFT JOIN teams t ON tr.team_id = t.id
            WHERE tr.tournament_id = :tournament_id
              AND tr.status = 'accepted'
              AND tr.team_id IS NOT NULL
            ORDER BY tr.registration_date ASC
        ");
    }

    $stmt->execute([':tournament_id' => $tournament_id]);
    $registrants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get the total count of match participants for bracket data if tournament is ongoing or completed
    $totalParticipants = count($registrants);
    $bracketData = null;

    if (in_array($tournament['status'], ['ongoing', 'completed'])) {
        // Get matches data for this tournament
        $stmt = $pdo->prepare("
            SELECT 
                m.id,
                m.tournament_round_text,
                m.start_time,
                m.state,
                m.match_state,
                m.winner_id,
                m.score1,
                m.score2,
                m.position,
                m.bracket_position,
                m.bracket_type,
                m.next_match_id,
                m.previous_match_id
            FROM matches m
            WHERE m.tournament_id = :tournament_id
            ORDER BY m.position ASC
        ");
        $stmt->execute([':tournament_id' => $tournament_id]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($matches) > 0) {
            // Get participants for each match
            foreach ($matches as &$match) {
                $stmt = $pdo->prepare("
                    SELECT 
                        mp.id,
                        mp.participant_id,
                        mp.name,
                        mp.picture,
                        mp.result_text,
                        mp.is_winner,
                        mp.status
                    FROM match_participants mp
                    WHERE mp.match_id = :match_id
                ");
                $stmt->execute([':match_id' => $match['id']]);
                $match['participants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $bracketData = $matches;
        }
    }

    // Get team members if tournament is team-based
    if ($tournament['participation_type'] === 'team') {
        foreach ($registrants as &$team) {
            $stmt = $pdo->prepare("
                SELECT 
                    tm.id,
                    tm.name,
                    tm.role,
                    tm.rank,
                    tm.status,
                    tm.avatar_url,
                    tm.is_active,
                    tm.is_substitute
                FROM team_members tm
                WHERE tm.team_id = :team_id
                ORDER BY tm.is_substitute ASC, tm.created_at ASC
            ");
            $stmt->execute([':team_id' => $team['team_id']]);
            $team['members'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Construct response
    $response = [
        'success' => true,
        'tournament' => [
            'id' => $tournament_id,
            'name' => $tournament['name'],
            'participation_type' => $tournament['participation_type'],
            'status' => $tournament['status'],
            'total_participants' => $totalParticipants,
            'bracket_type' => $tournament['bracket_type'],
            'game' => $tournament['game_name']
        ],
        'participants' => $registrants
    ];

    // Add status message if applicable
    if ($statusMessage) {
        $response['message'] = $statusMessage;
    }

    // Add bracket data if available
    if ($bracketData) {
        $response['bracket'] = $bracketData;
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}