<?php
// File: api/dashboard-stats.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$db_config = require 'db_config.php';

try {
    $conn = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Initialize stats array with all required properties
    $stats = [
        'totalUsers' => 0,
        'totalTournaments' => 0,
        'recentLogins' => 0,
        'upcomingTournaments' => 0,
        'newUsers' => 0,
        'totalAdmins' => 0,
        'avgTournamentDuration' => 0,
        'totalPrizePool' => 0,
        'totalTeams' => 0,
        'activeTeams' => 0,
        'averageTeamSize' => 0,
        'pendingJoinRequests' => 0,
        'teamsPerGame' => [
            'valorant' => 0,
            'freeFire' => 0,
            'streetFighter' => 0, 
            'fcFootball' => 0,
        ],
        'teamPrivacyDistribution' => [
            'public' => 0,
            'private' => 0,
            'invitationOnly' => 0,
        ],
        'tournamentsByType' => [
            'singleElimination' => 0,
            'doubleElimination' => 0,
            'roundRobin' => 0
        ]
    ];
    
    // Total Users
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    $stats['totalUsers'] = (int)$stmt->fetchColumn();
    
    // Total Tournaments
    $stmt = $conn->query("SELECT COUNT(*) FROM tournaments");
    $stats['totalTournaments'] = (int)$stmt->fetchColumn();
    
    // Recent Logins (last 7 days)
    $stmt = $conn->query("SELECT COUNT(DISTINCT user_id) FROM activity_log WHERE action = 'Connexion réussie' AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['recentLogins'] = (int)$stmt->fetchColumn();
    
    // Upcoming Tournaments
    $stmt = $conn->query("SELECT COUNT(*) FROM tournaments WHERE status IN ('draft', 'registration_open')");
    $stats['upcomingTournaments'] = (int)$stmt->fetchColumn();
    
    // New Users (last 30 days)
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stats['newUsers'] = (int)$stmt->fetchColumn();
    
    // Total Admins
    $stmt = $conn->query("SELECT COUNT(*) FROM admin");
    $stats['totalAdmins'] = (int)$stmt->fetchColumn();
    
    // Average Tournament Duration
    $stmt = $conn->query("SELECT AVG(TIMESTAMPDIFF(DAY, start_date, end_date)) FROM tournaments");
    $avgDuration = $stmt->fetchColumn();
    $stats['avgTournamentDuration'] = $avgDuration ? round((float)$avgDuration, 1) : 0;
    
    // Total Prize Pool
    $stmt = $conn->query("SELECT SUM(prize_pool) FROM tournaments");
    $totalPrize = $stmt->fetchColumn();
    $stats['totalPrizePool'] = $totalPrize ? (float)$totalPrize : 0;
    
    // Total Teams
    $stmt = $conn->query("SELECT COUNT(*) FROM teams");
    $stats['totalTeams'] = (int)$stmt->fetchColumn();
    
    // Active Teams (using team_members table)
    try {
        $stmt = $conn->query("SELECT COUNT(DISTINCT team_id) FROM team_members");
        $stats['activeTeams'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        // If query fails, use total teams as fallback
        $stats['activeTeams'] = $stats['totalTeams'];
    }
    
    // Average Team Size
    try {
        $stmt = $conn->query("
            SELECT AVG(member_count) as avg_size 
            FROM (
                SELECT team_id, COUNT(*) as member_count 
                FROM team_members 
                GROUP BY team_id
            ) as team_counts
        ");
        $avgSize = $stmt->fetchColumn();
        $stats['averageTeamSize'] = $avgSize ? round((float)$avgSize, 1) : 0;
    } catch (Exception $e) {
        // Fallback if query fails
        $stats['averageTeamSize'] = 0;
    }
    
    // Pending Join Requests
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM team_join_requests WHERE status = 'pending'");
        $stats['pendingJoinRequests'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $stats['pendingJoinRequests'] = 0;
    }
    
    // Teams Per Game
    try {
        $stmt = $conn->query("SELECT team_game, COUNT(*) as count FROM teams GROUP BY team_game");
        $teamResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($teamResults as $row) {
            if ($row['team_game'] === 'Valorant') {
                $stats['teamsPerGame']['valorant'] = (int)$row['count'];
            } elseif ($row['team_game'] === 'Free Fire') {
                $stats['teamsPerGame']['freeFire'] = (int)$row['count'];
            }
        }
    } catch (Exception $e) {
        // Fallback if query fails
    }
    
    // Count tournaments for Street Fighter and FC Football
    try {
        // Street Fighter (game_id = 4)
        $stmt = $conn->query("SELECT COUNT(*) FROM tournaments WHERE game_id = 4");
        $stats['teamsPerGame']['streetFighter'] = (int)$stmt->fetchColumn();
        
        // FC Football (game_id = 3)
        $stmt = $conn->query("SELECT COUNT(*) FROM tournaments WHERE game_id = 3");
        $stats['teamsPerGame']['fcFootball'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        // Fallback if query fails
    }
    
    // Team Privacy Distribution
    try {
        $stmt = $conn->query("SELECT privacy_level, COUNT(*) as count FROM teams GROUP BY privacy_level");
        $privacyResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($privacyResults as $row) {
            $level = strtolower(str_replace(' ', '', $row['privacy_level']));
            if ($level === 'public') {
                $stats['teamPrivacyDistribution']['public'] = (int)$row['count'];
            } elseif ($level === 'private') {
                $stats['teamPrivacyDistribution']['private'] = (int)$row['count'];
            } elseif ($level === 'invitationonly') {
                $stats['teamPrivacyDistribution']['invitationOnly'] = (int)$row['count'];
            }
        }
    } catch (Exception $e) {
        // Fallback if query fails
    }
    
    // Tournament Types Distribution
    try {
        $stmt = $conn->query("
            SELECT bracket_type, COUNT(*) as count 
            FROM tournaments 
            GROUP BY bracket_type
        ");
        $tournamentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tournamentTypes as $type) {
            if ($type['bracket_type'] === 'Single Elimination') {
                $stats['tournamentsByType']['singleElimination'] = (int)$type['count'];
            } elseif ($type['bracket_type'] === 'Double Elimination') {
                $stats['tournamentsByType']['doubleElimination'] = (int)$type['count'];
            } elseif ($type['bracket_type'] === 'Round Robin') {
                $stats['tournamentsByType']['roundRobin'] = (int)$type['count'];
            }
        }
    } catch (Exception $e) {
        // Fallback if query fails
    }
    
    // Return success response with stats data
    echo json_encode([
        'success' => true,
        'data' => $stats
    ]);
    
} catch (PDOException $e) {
    // Log the error for server-side debugging
    error_log('Dashboard stats PDO error: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // Handle any other exceptions
    error_log('Dashboard stats general error: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}