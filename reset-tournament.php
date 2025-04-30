<?php
// File: api/reset-tournament.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit();
}

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Get JSON data from request
$data = json_decode(file_get_contents('php://input'), true);

// Validate tournament ID
if (!isset($data['tournament_id']) || !is_numeric($data['tournament_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Tournament ID is required and must be numeric.'
    ]);
    exit();
}

$tournamentId = (int)$data['tournament_id'];
$db_config = require 'db_config.php';

try {
    $conn = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Start transaction to ensure all operations succeed or fail together
    $conn->beginTransaction();
    
    // Check if tournament exists and get bracket type
    $stmt = $conn->prepare("SELECT id, bracket_type, status FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception("Tournament with ID {$tournamentId} not found.");
    }
    
    // Store the tournament_registrations with accepted status (we'll keep these)
    $stmt = $conn->prepare("
        SELECT id, tournament_id, user_id, team_id, status, registration_date
        FROM tournament_registrations 
        WHERE tournament_id = ? AND status = 'accepted'
    ");
    $stmt->execute([$tournamentId]);
    $acceptedRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log action
    $adminId = isset($data['admin_id']) ? (int)$data['admin_id'] : 1; // Default to admin ID 1 if not provided
    $logStmt = $conn->prepare("
        INSERT INTO activity_log (user_id, action, details, ip_address)
        VALUES (?, ?, ?, ?)
    ");
    $logStmt->execute([
        $adminId,
        'Tournament Reset',
        "Reset tournament ID: {$tournamentId} - Type: {$tournament['bracket_type']}",
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
    ]);
    
    // ====== RESET TOURNAMENT DATA ======
    
    // 1. Delete match participants
    $stmt = $conn->prepare("
        DELETE mp FROM match_participants mp
        JOIN matches m ON mp.match_id = m.id
        WHERE m.tournament_id = ?
    ");
    $stmt->execute([$tournamentId]);
    
    // 2. Delete match state history
    $stmt = $conn->prepare("
        DELETE msh FROM match_state_history msh
        JOIN matches m ON msh.match_id = m.id
        WHERE m.tournament_id = ?
    ");
    $stmt->execute([$tournamentId]);
    
    // 3. Delete matches
    $stmt = $conn->prepare("DELETE FROM matches WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    // 4. Handle Round Robin specific data
    if ($tournament['bracket_type'] === 'Round Robin') {
        // 4.1 Delete round robin matches
        $stmt = $conn->prepare("DELETE FROM round_robin_matches WHERE tournament_id = ?");
        $stmt->execute([$tournamentId]);
        
        // 4.2 Delete round robin standings
        $stmt = $conn->prepare("DELETE FROM round_robin_standings WHERE tournament_id = ?");
        $stmt->execute([$tournamentId]);
        
        // 4.3 Delete round robin group teams but keep the groups themselves
        $stmt = $conn->prepare("
            DELETE rrgt FROM round_robin_group_teams rrgt
            JOIN round_robin_groups rrg ON rrgt.group_id = rrg.id
            WHERE rrg.tournament_id = ?
        ");
        $stmt->execute([$tournamentId]);
        
        // 4.4 If you want to keep the groups structure but recreate participants later,
        // you can fetch the existing groups to recreate team assignments later
        $stmt = $conn->prepare("
            SELECT id, name 
            FROM round_robin_groups 
            WHERE tournament_id = ?
        ");
        $stmt->execute([$tournamentId]);
        $existingGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 5. Handle Battle Royale specific data
    if ($tournament['bracket_type'] === 'Battle Royale') {
        // 5.1 Delete battle royale match results
        $stmt = $conn->prepare("
            DELETE brmr FROM battle_royale_match_results brmr
            JOIN matches m ON brmr.match_id = m.id
            WHERE m.tournament_id = ?
        ");
        $stmt->execute([$tournamentId]);
        
        // 5.2 Reset match count in battle_royale_settings
        $stmt = $conn->prepare("
            UPDATE battle_royale_settings 
            SET match_count = 0 
            WHERE tournament_id = ?
        ");
        $stmt->execute([$tournamentId]);
    }
    
    // 6. Delete all tournament registrations (we'll restore the accepted ones later)
    $stmt = $conn->prepare("DELETE FROM tournament_registrations WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    // 7. Reset tournament state to registration_open
    $stmt = $conn->prepare("
        UPDATE tournaments 
        SET status = 'registration_open',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$tournamentId]);
    
    // 8. Restore accepted registrations
    if (!empty($acceptedRegistrations)) {
        $restoreStmt = $conn->prepare("
            INSERT INTO tournament_registrations 
            (tournament_id, user_id, team_id, status, registration_date)
            VALUES (?, ?, ?, 'accepted', ?)
        ");
        
        foreach ($acceptedRegistrations as $reg) {
            $restoreStmt->execute([
                $reg['tournament_id'],
                $reg['user_id'],
                $reg['team_id'],
                $reg['registration_date']
            ]);
        }
        
        // 9. If this is a Round Robin tournament and we have accepted registrations,
        // recreate the group assignments
        if ($tournament['bracket_type'] === 'Round Robin' && !empty($existingGroups)) {
            // Get all team IDs from accepted registrations
            $teamIds = array_column(array_filter($acceptedRegistrations, function($reg) {
                return !is_null($reg['team_id']);
            }), 'team_id');
            
            if (!empty($teamIds)) {
                // Get the first group for simplicity (you might want a more sophisticated assignment)
                $defaultGroupId = $existingGroups[0]['id'];
                
                $insertTeamStmt = $conn->prepare("
                    INSERT INTO round_robin_group_teams (group_id, team_id)
                    VALUES (?, ?)
                ");
                
                foreach ($teamIds as $teamId) {
                    // This will trigger the after_team_add_to_group trigger to create standings
                    $insertTeamStmt->execute([$defaultGroupId, $teamId]);
                }
            }
        }
    }
    
    // 10. Delete tournament rounds if they exist
    $stmt = $conn->prepare("DELETE FROM tournament_rounds WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    // 11. Delete tournament stages if they exist
    $stmt = $conn->prepare("DELETE FROM tournament_stages WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    // Commit all changes
    $conn->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => "Tournament ID {$tournamentId} ({$tournament['bracket_type']}) has been reset successfully. Preserved " . count($acceptedRegistrations) . " accepted registrations.",
        'tournament_id' => $tournamentId,
        'bracket_type' => $tournament['bracket_type']
    ]);
    
} catch (PDOException $e) {
    // Roll back transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Log the error for server-side debugging
    error_log('Tournament reset PDO error: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // Roll back transaction on error
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Handle any other exceptions
    error_log('Tournament reset general error: ' . $e->getMessage());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}