<?php
function handleError($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit;
}
set_error_handler('handleError');
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db_config = require 'db_config.php';
try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->beginTransaction();

    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data');
    }

    if (!isset($data['tournament_id']) || !isset($data['user_id'])) {
        throw new Exception('Missing required fields');
    }

    // Get tournament details with registration count
    $stmt = $pdo->prepare("
        SELECT t.*, 
               COALESCE((
                   SELECT COUNT(*) 
                   FROM tournament_registrations tr 
                   WHERE tr.tournament_id = t.id 
                   AND tr.status IN ('pending', 'accepted')
               ), 0) as current_registrations
        FROM tournaments t
        WHERE t.id = :tournament_id
    ");
    $stmt->execute([':tournament_id' => $data['tournament_id']]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        throw new Exception('Tournament not found');
    }

    if ($tournament['status'] !== 'registration_open') {
        throw new Exception('Tournament is not open for registration');
    }

    if ($tournament['current_registrations'] >= $tournament['max_participants']) {
        throw new Exception('Tournament is full');
    }

    // Handle team tournament registration
    if ($tournament['participation_type'] === 'team') {
        if (!isset($data['team_id'])) {
            throw new Exception('Team ID is required for team tournaments');
        }

        // Check team eligibility
        $stmt = $pdo->prepare("
        SELECT t.*, 
              (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) as member_count
        FROM teams t
        WHERE t.id = :team_id 
        AND t.team_game = (SELECT name FROM games WHERE id = :game_id)
        AND (
            t.owner_id = :user_id 
            OR EXISTS (
                SELECT 1 
                FROM team_members tm 
                WHERE tm.team_id = t.id 
                AND tm.name = (
                    SELECT username 
                    FROM users 
                    WHERE id = :user_id
                )
            )
        )
    ");
    
    $stmt->execute([
        ':team_id' => $data['team_id'],
        ':user_id' => $data['user_id'],
        ':game_id' => $tournament['game_id']
    ]);
        
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$team) {
            throw new Exception('You must be a member or owner of an eligible team for this tournament');
        }

        // Check if team has minimum required members
        if ($team['member_count'] < $tournament['min_team_size']) {
            throw new Exception('Your team does not have the minimum required number of members');
        }

        // Check if team is already registered
        $stmt = $pdo->prepare("
            SELECT id FROM tournament_registrations 
            WHERE tournament_id = :tournament_id 
            AND team_id = :team_id
        ");
        $stmt->execute([
            ':tournament_id' => $tournament['id'],
            ':team_id' => $team['id']
        ]);
        
        if ($stmt->fetch()) {
            throw new Exception('This team is already registered for the tournament');
        }

        // Register the team
        $stmt = $pdo->prepare("
            INSERT INTO tournament_registrations 
            (tournament_id, team_id, registration_date, status)
            VALUES (:tournament_id, :team_id, NOW(), 'pending')
        ");
        
        $stmt->execute([
            ':tournament_id' => $tournament['id'],
            ':team_id' => $team['id']
        ]);

        $successMessage = "Team registration successful";
    } 
    // Handle individual registration
    else {
        // Check for existing registration
        $stmt = $pdo->prepare("
            SELECT id FROM tournament_registrations 
            WHERE tournament_id = :tournament_id 
            AND user_id = :user_id
        ");
        $stmt->execute([
            ':tournament_id' => $tournament['id'],
            ':user_id' => $data['user_id']
        ]);
        
        if ($stmt->fetch()) {
            throw new Exception('You are already registered for this tournament');
        }

        // Register individual
        $stmt = $pdo->prepare("
            INSERT INTO tournament_registrations 
            (tournament_id, user_id, registration_date, status)
            VALUES (:tournament_id, :user_id, NOW(), 'pending')
        ");
        
        $stmt->execute([
            ':tournament_id' => $tournament['id'],
            ':user_id' => $data['user_id']
        ]);

        $successMessage = "Individual registration successful";
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'message' => $successMessage,
        'tournament' => [
            'id' => $tournament['id'],
            'name' => $tournament['name'],
            'registered_count' => $tournament['current_registrations'] + 1
        ]
    ]);

} catch (PDOException $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}