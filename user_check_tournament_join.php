<?php
function handleError($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred'
    ]);
    exit;
}
set_error_handler('handleError');

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Max-Age: 3600');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Validate input
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload');
    }

    if (!isset($data->tournament_id) || !isset($data->user_id)) {
        throw new Exception('Missing required parameters');
    }

    $tournament_id = filter_var($data->tournament_id, FILTER_VALIDATE_INT);
    $user_id = filter_var($data->user_id, FILTER_VALIDATE_INT);

    if ($tournament_id === false || $user_id === false) {
        throw new Exception('Invalid parameters');
    }

    // Database connection
    $db_config = require 'db_config.php';
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get tournament details
    $tournamentQuery = "
        SELECT 
            t.id,
            t.participation_type,
            t.status as tournament_status,
            t.name as tournament_name
        FROM tournaments t
        WHERE t.id = :tournament_id
    ";
    
    $stmt = $pdo->prepare($tournamentQuery);
    $stmt->execute(['tournament_id' => $tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        throw new Exception('Tournament not found');
    }

    // Count current registrations
    $registrationsCountQuery = "
        SELECT COUNT(*) as count
        FROM tournament_registrations
        WHERE tournament_id = :tournament_id
        AND status IN ('pending', 'accepted')
    ";
    
    $stmt = $pdo->prepare($registrationsCountQuery);
    $stmt->execute(['tournament_id' => $tournament_id]);
    $registrationsCount = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_registrations = $registrationsCount ? $registrationsCount['count'] : 0;

    // Initialize response array
    $response = [
        'success' => true,
        'tournament_name' => $tournament['tournament_name'],
        'tournament_type' => $tournament['participation_type'],
        'tournament_status' => $tournament['tournament_status'],
        'current_registrations' => $current_registrations,
        'registrations' => []
    ];

    $hasJoined = false;

    if ($tournament['participation_type'] === 'team') {
        // For team tournaments - check only for ownership to simplify
        $ownedTeamQuery = "
            SELECT 
                tr.id as registration_id,
                tr.status as registration_status,
                tr.registration_date,
                t.id as team_id,
                t.name as team_name,
                'owner' as role
            FROM tournament_registrations tr
            JOIN teams t ON tr.team_id = t.id
            WHERE 
                tr.tournament_id = :tournament_id
                AND t.owner_id = :user_id
            LIMIT 1
        ";
        
        $stmt = $pdo->prepare($ownedTeamQuery);
        $stmt->execute([
            'tournament_id' => $tournament_id,
            'user_id' => $user_id
        ]);
        
        $teamRegistration = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($teamRegistration) {
            $hasJoined = true;
            
            // If a registration is found, include it in the response
            $response['registrations'][] = [
                'id' => $teamRegistration['registration_id'],
                'team_id' => $teamRegistration['team_id'],
                'team_name' => $teamRegistration['team_name'],
                'status' => $teamRegistration['registration_status'],
                'role' => $teamRegistration['role'],
                'registration_date' => $teamRegistration['registration_date']
            ];
        }
    } else {
        // For individual tournaments
        $individualQuery = "
            SELECT 
                tr.id as registration_id,
                tr.status as registration_status,
                tr.registration_date,
                u.username
            FROM tournament_registrations tr
            JOIN users u ON u.id = tr.user_id
            WHERE 
                tr.tournament_id = :tournament_id 
                AND tr.user_id = :user_id
            LIMIT 1
        ";
        
        $stmt = $pdo->prepare($individualQuery);
        $stmt->execute([
            'tournament_id' => $tournament_id,
            'user_id' => $user_id
        ]);
        
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($registration) {
            $hasJoined = true;
            
            $response['registrations'][] = [
                'id' => $registration['registration_id'],
                'status' => $registration['registration_status'],
                'username' => $registration['username'],
                'registration_date' => $registration['registration_date']
            ];
        }
    }
    
    // Set has_joined in response
    $response['has_joined'] = $hasJoined;

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}