<?php
// get_users.php

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enforce GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

header('Content-Type: application/json');

// Load database configuration
$db_config = require 'db_config.php';

try {
    // Database connection
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], $options);
    
    // Get user ID from query parameter
    $userId = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    // Validate user ID
    if (!$userId || $userId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Valid user ID is required. Example: ?id=126'
        ]);
        exit();
    }
    
    // Fetch specific user
        $sql = "
            SELECT 
                id,
                username,
                email,
                type,
                points,
                `rank`,
                is_verified,
                created_at,
                bio,
                avatar,
                user_type
            FROM users
            WHERE id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'User not found'
            ]);
            exit();
        }
        
        // Get user's teams
        $teamSql = "
            SELECT 
                t.id,
                t.name,
                t.tag,
                t.slug,
                t.logo,
                t.banner,
                t.division,
                t.tier,
                t.wins,
                t.losses,
                t.draws,
                t.win_rate,
                tm.role,
                tm.is_captain,
                tm.join_date,
                g.name AS game_name,
                g.slug AS game_slug,
                g.image AS game_image
            FROM team_members tm
            JOIN teams t ON tm.team_id = t.id
            LEFT JOIN games g ON t.game_id = g.id
            WHERE tm.user_id = ?
            ORDER BY tm.join_date DESC
        ";
        $teamStmt = $pdo->prepare($teamSql);
        $teamStmt->execute([$userId]);
        $teams = $teamStmt->fetchAll();
        
        // Format teams data
        foreach ($teams as &$team) {
            if (!empty($team['game_name'])) {
                $team['game'] = [
                    'name' => $team['game_name'],
                    'slug' => $team['game_slug'],
                    'image' => $team['game_image']
                ];
            } else {
                $team['game'] = null;
            }
            unset($team['game_name'], $team['game_slug'], $team['game_image']);
        }
        
        $user['teams'] = $teams;
        
        // Get user's tournament registrations
        $tournamentSql = "
            SELECT 
                tr.id AS registration_id,
                tr.registration_date,
                tr.status AS registration_status,
                t.id AS tournament_id,
                t.name AS tournament_name,
                t.slug AS tournament_slug,
                t.bracket_type,
                t.participation_type,
                t.status AS tournament_status,
                t.start_date,
                t.end_date,
                t.featured_image,
                g.name AS game_name,
                g.slug AS game_slug,
                g.image AS game_image
            FROM tournament_registrations tr
            JOIN tournaments t ON tr.tournament_id = t.id
            LEFT JOIN games g ON t.game_id = g.id
            WHERE tr.user_id = ?
            ORDER BY tr.registration_date DESC
        ";
        $tournamentStmt = $pdo->prepare($tournamentSql);
        $tournamentStmt->execute([$userId]);
        $tournaments = $tournamentStmt->fetchAll();
        
        // Format tournaments data
        foreach ($tournaments as &$tournament) {
            if (!empty($tournament['game_name'])) {
                $tournament['game'] = [
                    'name' => $tournament['game_name'],
                    'slug' => $tournament['game_slug'],
                    'image' => $tournament['game_image']
                ];
            } else {
                $tournament['game'] = null;
            }
            unset($tournament['game_name'], $tournament['game_slug'], $tournament['game_image']);
        }
        
        $user['tournament_registrations'] = $tournaments;
        
        // Get user's activity log (last 10 activities)
        $activitySql = "
            SELECT 
                action,
                timestamp,
                details,
                ip_address
            FROM activity_log
            WHERE user_id = ?
            ORDER BY timestamp DESC
            LIMIT 10
        ";
        $activityStmt = $pdo->prepare($activitySql);
        $activityStmt->execute([$userId]);
        $user['recent_activities'] = $activityStmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $user
        ]);

} catch (PDOException $e) {
    error_log('Database error in get_users.php: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('General error in get_users.php: ' . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}