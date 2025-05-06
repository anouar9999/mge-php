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
    
    error_log("Step 1: Checking if tournament exists");
    // Check if tournament exists and get bracket type
    $stmt = $conn->prepare("SELECT id, bracket_type, status FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception("Tournament with ID {$tournamentId} not found.");
    }
    
    error_log("Step 2: Storing accepted registrations");
    // Store the tournament_registrations with accepted status (we'll keep these)
    $stmt = $conn->prepare("
        SELECT id, tournament_id, user_id, team_id, status, registration_date
        FROM tournament_registrations 
        WHERE tournament_id = ? AND status = 'accepted'
    ");
    $stmt->execute([$tournamentId]);
    $acceptedRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($acceptedRegistrations) . " accepted registrations");
    
    error_log("Step 3: Logging action");
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
    
    error_log("Step 4: Deleting match participants");
    // 1. Delete match participants
    $stmt = $conn->prepare("
        DELETE mp FROM match_participants mp
        JOIN matches m ON mp.match_id = m.id
        WHERE m.tournament_id = ?
    ");
    $stmt->execute([$tournamentId]);
    
    error_log("Step 5: Deleting match state history");
    // 2. Delete match state history
    $stmt = $conn->prepare("
        DELETE msh FROM match_state_history msh
        JOIN matches m ON msh.match_id = m.id
        WHERE m.tournament_id = ?
    ");
    $stmt->execute([$tournamentId]);
    
    error_log("Step 6: Deleting matches");
    // 3. Delete matches
    $stmt = $conn->prepare("DELETE FROM matches WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    // 4. Handle Round Robin specific data
    if ($tournament['bracket_type'] === 'Round Robin') {
        error_log("Step 7: Processing Round Robin specific data");
        // 4.1 Delete round robin matches
        $stmt = $conn->prepare("DELETE FROM round_robin_matches WHERE tournament_id = ?");
        $stmt->execute([$tournamentId]);
        
        // 4.2 Delete round robin standings
        $stmt = $conn->prepare("DELETE FROM round_robin_standings WHERE tournament_id = ?");
        $stmt->execute([$tournamentId]);
        
        // 4.3 Delete round robin group teams 
        // CRITICAL FIX: Delete with JOIN to properly handle all group-team relationships
        // This is more reliable than retrieving and deleting individually
        $stmt = $conn->prepare("
            DELETE rrgt FROM round_robin_group_teams rrgt
            JOIN round_robin_groups rrg ON rrgt.group_id = rrg.id
            WHERE rrg.tournament_id = ?
        ");
        $stmt->execute([$tournamentId]);
        
        // 4.4 Get the existing groups for later recreation
        $stmt = $conn->prepare("
            SELECT id, name 
            FROM round_robin_groups 
            WHERE tournament_id = ?
        ");
        $stmt->execute([$tournamentId]);
        $existingGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Found " . count($existingGroups) . " existing groups");
    }
    
    // 5. Handle Battle Royale specific data
    if ($tournament['bracket_type'] === 'Battle Royale') {
        error_log("Step 8: Processing Battle Royale specific data");
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
    
    error_log("Step 9: Deleting tournament registrations");
    // 6. Delete all tournament registrations (we'll restore the accepted ones later)
    $stmt = $conn->prepare("DELETE FROM tournament_registrations WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    error_log("Step 10: Resetting tournament state");
    // 7. Reset tournament state to registration_open
    $stmt = $conn->prepare("
        UPDATE tournaments 
        SET status = 'registration_open',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$tournamentId]);
    
    error_log("Step 11: Restoring accepted registrations");
    // 8. Restore accepted registrations - including the id field to fix the error
    if (!empty($acceptedRegistrations)) {
        // Check if any accepted registrations exist
        error_log("Found " . count($acceptedRegistrations) . " accepted registrations to restore");
        
        // Check structure of first registration
        if (!empty($acceptedRegistrations[0])) {
            error_log("First registration structure: " . json_encode($acceptedRegistrations[0]));
        }
        
        $restoreStmt = $conn->prepare("
            INSERT INTO tournament_registrations 
            (id, tournament_id, user_id, team_id, status, registration_date)
            VALUES (?, ?, ?, ?, 'accepted', ?)
        ");
        
        foreach ($acceptedRegistrations as $index => $reg) {
            error_log("Restoring registration " . ($index+1) . " of " . count($acceptedRegistrations) . ": ID=" . $reg['id']);
            try {
                $restoreStmt->execute([
                    $reg['id'],
                    $reg['tournament_id'],
                    $reg['user_id'],
                    $reg['team_id'],
                    $reg['registration_date']
                ]);
            } catch (Exception $e) {
                error_log("Error restoring registration ID " . $reg['id'] . ": " . $e->getMessage());
                throw $e; // Re-throw to be caught by outer catch
            }
        }
        
        error_log("Step 12: Recreating group assignments for Round Robin");
        // 9. If this is a Round Robin tournament and we have accepted registrations,
        // recreate the group assignments - only if existingGroups exists and is not empty
        if ($tournament['bracket_type'] === 'Round Robin' && !empty($existingGroups)) {
            // Get all team IDs from accepted registrations
            $teamIds = array_column(array_filter($acceptedRegistrations, function($reg) {
                return !is_null($reg['team_id']);
            }), 'team_id');
            
            error_log("Found " . count($teamIds) . " team IDs to assign to groups");
            
            if (!empty($teamIds)) {
                // Get the first group for simplicity (you might want a more sophisticated assignment)
                $defaultGroupId = $existingGroups[0]['id'];
                error_log("Using default group ID: " . $defaultGroupId);
                
                // IMPROVED APPROACH: Use INSERT IGNORE to avoid duplicate key errors
                // This is more efficient and safer than individual checks
                // First check if the table has auto-increment ID
                $tableInfoQuery = $conn->prepare("SHOW COLUMNS FROM round_robin_group_teams LIKE 'id'");
                $tableInfoQuery->execute();
                $column = $tableInfoQuery->fetch(PDO::FETCH_ASSOC);
                $hasAutoIncrementId = ($column && strpos(strtoupper($column['Extra']), 'AUTO_INCREMENT') !== false);
                
                error_log("round_robin_group_teams has Auto Increment ID: " . ($hasAutoIncrementId ? "Yes" : "No"));
                
                if ($hasAutoIncrementId) {
                    // Use INSERT IGNORE with auto-increment ID
                    $insertTeamStmt = $conn->prepare("
                        INSERT IGNORE INTO round_robin_group_teams (group_id, team_id)
                        VALUES (?, ?)
                    ");
                    
                    foreach ($teamIds as $teamId) {
                        error_log("Attempting to insert team " . $teamId . " to group " . $defaultGroupId);
                        $insertTeamStmt->execute([$defaultGroupId, $teamId]);
                    }
                } else {
                    // We need to manage IDs manually, so first get max ID
                    $maxIdQuery = $conn->prepare("SELECT COALESCE(MAX(id), 0) FROM round_robin_group_teams");
                    $maxIdQuery->execute();
                    $maxId = (int)$maxIdQuery->fetchColumn();
                    error_log("Current max ID in round_robin_group_teams: " . $maxId);
                    
                    // Then check which team-group combinations already exist to avoid duplicates
                    $existingAssignmentsStmt = $conn->prepare("
                        SELECT team_id FROM round_robin_group_teams WHERE group_id = ?
                    ");
                    $existingAssignmentsStmt->execute([$defaultGroupId]);
                    $existingAssignments = $existingAssignmentsStmt->fetchAll(PDO::FETCH_COLUMN);
                    error_log("Teams already assigned: " . count($existingAssignments));
                    
                    // Filter out teams that are already assigned
                    $teamsToAssign = array_diff($teamIds, $existingAssignments);
                    error_log("New teams to assign: " . count($teamsToAssign));
                    
                    if (!empty($teamsToAssign)) {
                        $insertTeamStmt = $conn->prepare("
                            INSERT INTO round_robin_group_teams (id, group_id, team_id) 
                            VALUES (?, ?, ?)
                        ");
                        
                        $newId = $maxId + 1;
                        foreach ($teamsToAssign as $teamId) {
                            error_log("Inserting team " . $teamId . " with ID " . $newId);
                            try {
                                $insertTeamStmt->execute([$newId, $defaultGroupId, $teamId]);
                                $newId++;
                            } catch (Exception $e) {
                                // Log error but don't stop the process
                                error_log("Error inserting team " . $teamId . ": " . $e->getMessage());
                                // Continue with next team
                            }
                        }
                    }
                }
            }
        }
    }
    
    error_log("Step 13: Deleting tournament rounds");
    // 10. Delete tournament rounds if they exist
    $stmt = $conn->prepare("DELETE FROM tournament_rounds WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    error_log("Step 14: Deleting tournament stages");
    // 11. Delete tournament stages if they exist
    $stmt = $conn->prepare("DELETE FROM tournament_stages WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    
    error_log("Step 15: Committing transaction");
    // Commit all changes
    $conn->commit();
    
    error_log("Tournament reset completed successfully");
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
    error_log('Stack trace: ' . $e->getTraceAsString());
    
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
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
