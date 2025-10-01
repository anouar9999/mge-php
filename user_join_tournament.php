<?php
// Error handling setup
function handleError($errno, $errstr, $errfile, $errline) {
    error_log("Error: [$errno] $errstr in $errfile on line $errline");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit;
}
set_error_handler('handleError');
error_reporting(E_ALL);
ini_set('display_errors', '0');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load database configuration
$db_config = require 'db_config.php';

try {
    // Initialize PDO connection
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get input data with better error handling
    $rawInput = file_get_contents('php://input');
    
    // Check if we received any data
    if (empty($rawInput)) {
        // Try to get data from $_POST if JSON input is empty
        if (!empty($_POST)) {
            $data = $_POST;
            error_log("Using POST data instead of JSON");
        } else {
            throw new Exception('No data received. Please send data in the request.');
        }
    } else {
        // Log raw input for debugging
        error_log('Raw input: ' . $rawInput);
        
        // Parse JSON
        $data = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Log the specific JSON error
            error_log('JSON error: ' . json_last_error_msg());
            throw new Exception('Invalid JSON data: ' . json_last_error_msg());
        }
    }

    // Validate required fields
    if (!isset($data['tournament_id']) || !isset($data['user_id'])) {
        throw new Exception('Missing required fields: tournament_id and user_id are required');
    }

    // Begin transaction
    $pdo->beginTransaction();

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

    // Validate tournament
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

        // Check team eligibility - FIXED QUERY
        $stmt = $pdo->prepare("
            SELECT t.*, 
                  (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) as member_count
            FROM teams t
            WHERE t.id = :team_id 
            AND t.game_id = :game_id
            AND (
                t.captain_id = :user_id 
                OR EXISTS (
                    SELECT 1 
                    FROM team_members tm 
                    WHERE tm.team_id = t.id 
                    AND tm.user_id = :user_id
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
            throw new Exception('You must be the captain or member of an eligible team for this tournament');
        }

        // Check if team has minimum required members (if specified in tournament)
        if (isset($tournament['min_team_size']) && $team['member_count'] < $tournament['min_team_size']) {
            throw new Exception("Team must have at least {$tournament['min_team_size']} members to register");
        }

        // Check if team is already registered
        $stmt = $pdo->prepare("
            SELECT id FROM tournament_registrations 
            WHERE tournament_id = :tournament_id 
            AND team_id = :team_id
            AND status IN ('pending', 'accepted')
        ");
        $stmt->execute([
            ':tournament_id' => $tournament['id'],
            ':team_id' => $team['id']
        ]);
        
        if ($stmt->fetch()) {
            throw new Exception('This team is already registered for the tournament');
        }

        // Get next available ID for tournament_registrations
        $stmt = $pdo->query("SELECT MAX(id) as max_id FROM tournament_registrations");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_id = ($result['max_id'] !== null) ? $result['max_id'] + 1 : 1;

        // Register the team with explicit ID
        $stmt = $pdo->prepare("
            INSERT INTO tournament_registrations 
            (id, tournament_id, user_id, team_id, registration_date, status)
            VALUES (:id, :tournament_id, :user_id, :team_id, NOW(), 'pending')
        ");
        
        $stmt->execute([
            ':id' => $next_id,
            ':tournament_id' => $tournament['id'],
            ':user_id' => $data['user_id'],
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
            AND status IN ('pending', 'accepted')
        ");
        $stmt->execute([
            ':tournament_id' => $tournament['id'],
            ':user_id' => $data['user_id']
        ]);
        
        if ($stmt->fetch()) {
            throw new Exception('You are already registered for this tournament');
        }

        // Get next available ID for tournament_registrations
        $stmt = $pdo->query("SELECT MAX(id) as max_id FROM tournament_registrations");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_id = ($result['max_id'] !== null) ? $result['max_id'] + 1 : 1;

        // Register individual with explicit ID
        $stmt = $pdo->prepare("
            INSERT INTO tournament_registrations 
            (id, tournament_id, user_id, team_id, registration_date, status)
            VALUES (:id, :tournament_id, :user_id, NULL, NOW(), 'pending')
        ");
        
        $stmt->execute([
            ':id' => $next_id,
            ':tournament_id' => $tournament['id'],
            ':user_id' => $data['user_id']
        ]);

        $successMessage = "Individual registration successful";
    }

    // Add entry to activity_log
    $stmt = $pdo->prepare("
        INSERT INTO activity_log (user_id, action, timestamp, details, ip_address)
        VALUES (:user_id, :action, NOW(), :details, :ip_address)
    ");
    
    $stmt->execute([
        ':user_id' => $data['user_id'],
        ':action' => 'Tournament Registration',
        ':details' => 'Registered for tournament ID: ' . $tournament['id'] . ' - ' . $tournament['name'],
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '::1'
    ]);

    // Commit transaction
    $pdo->commit();
    
    // Return success response
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
    // Rollback transaction on database error
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    // Rollback transaction on other errors
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    error_log('Application error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
