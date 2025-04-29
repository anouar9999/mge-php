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
    
    // Check if tournament exists
    $stmt = $conn->prepare("SELECT id, bracket_type FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception("Tournament with ID {$tournamentId} not found.");
    }
    
    // Store the tournament_registrations with accepted status (we'll keep these)
    $stmt = $conn->prepare("
        SELECT id, tournament_id, user_id, team_id, status
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
        "Reset tournament ID: {$tournamentId}",
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
    
    // 4. Delete round robin matches
    $stmt = $conn->prepare("DELETE FROM round_robin_matches WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    // 5. Delete round robin standings
    $stmt = $conn->prepare("DELETE FROM round_robin_standings WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    // 6. Delete round robin group teams (keep the groups themselves)
    $stmt = $conn->prepare("
        DELETE rrgt FROM round_robin_group_teams rrgt
        JOIN round_robin_groups rrg ON rrgt.group_id = rrg.id
        WHERE rrg.tournament_id = ?
    ");
    $stmt->execute([$tournamentId]);
    
    // 7. Reset battle royale match results
    if ($tournament['bracket_type'] === 'Battle Royale') {
        // First, find all match IDs for this tournament
        $stmt = $conn->prepare("
            SELECT id FROM matches 
            WHERE tournament_id = ?
        ");
        $stmt->execute([$tournamentId]);
        $matchIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($matchIds)) {
            // Convert array to comma-separated string for IN clause
            $matchIdsStr = implode(',', $matchIds);
            
            // Delete match results
            $stmt = $conn->query("DELETE FROM battle_royale_match_results WHERE match_id IN ($matchIdsStr)");
        }
        
        // Reset match count in battle_royale_settings
        $stmt = $conn->prepare("
            UPDATE battle_royale_settings 
            SET match_count = 0 
            WHERE tournament_id = ?
        ");
        $stmt->execute([$tournamentId]);
    }
    
    // 8. Delete all tournament registrations (we'll restore the accepted ones later)
    $stmt = $conn->prepare("DELETE FROM tournament_registrations WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    // 9. Reset tournament state to registration_open
    $stmt = $conn->prepare("
        UPDATE tournaments 
        SET status = 'registration_open'
        WHERE id = ?
    ");
    $stmt->execute([$tournamentId]);
    
    // 10. Restore accepted registrations
    if (!empty($acceptedRegistrations)) {
        $restoreStmt = $conn->prepare("
            INSERT INTO tournament_registrations (tournament_id, user_id, team_id, status)
            VALUES (?, ?, ?, 'accepted')
        ");
        
        foreach ($acceptedRegistrations as $reg) {
            $restoreStmt->execute([
                $reg['tournament_id'],
                $reg['user_id'],
                $reg['team_id']
            ]);
        }
    }
    
    // Commit all changes
    $conn->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => "Tournament ID {$tournamentId} has been reset successfully. Preserved " . count($acceptedRegistrations) . " accepted registrations.",
        'tournament_id' => $tournamentId
    ]);
    
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($conn->inTransaction()) {
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
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Handle any other exceptions
    error_log('Tournament reset general error: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}