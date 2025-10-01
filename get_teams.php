<?php
// get_teams.php

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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
    
    // Main query to fetch teams with basic info
    $sql = "
        SELECT 
            t.*,
            u.username AS owner_username,
            u.avatar AS owner_avatar,
            g.name AS game_name,
            g.slug AS game_slug,
            g.image AS game_image,
            g.publisher AS game_publisher
        FROM teams t
        LEFT JOIN users u ON t.owner_id = u.id
        LEFT JOIN games g ON t.game_id = g.id
        ORDER BY t.created_at DESC
    ";
    
    $stmt = $pdo->query($sql);
    $teams = [];
    
    while ($team = $stmt->fetch()) {
        $teamId = $team['id'];
        
        // Prepare game data
        if (!empty($team['game_id']) && $team['game_id'] > 0 && !empty($team['game_name'])) {
            $team['game'] = [
                'id' => $team['game_id'],
                'name' => $team['game_name'],
                'slug' => $team['game_slug'],
                'image' => $team['game_image'],
                'publisher' => $team['game_publisher']
            ];
        } else {
            $team['game'] = null;
        }
        
        // Remove redundant game fields
        unset($team['game_id'], $team['game_name'], $team['game_slug'], $team['game_image'], $team['game_publisher']);
        
        // Get team members
        $memberSql = "
            SELECT 
                tm.id AS member_id,
                tm.user_id,
                tm.role,
                tm.is_captain,
                tm.join_date,
                u.username,
                u.email,
                u.avatar
            FROM team_members tm
            JOIN users u ON tm.user_id = u.id
            WHERE tm.team_id = ?
        ";
        $memberStmt = $pdo->prepare($memberSql);
        $memberStmt->execute([$teamId]);
        $team['members'] = $memberStmt->fetchAll();
        
        // Get pending join requests - FIXED: Added backticks around reserved keyword 'rank'
        $requestSql = "
            SELECT 
                id,
                name,
                role,
                `rank`,
                experience,
                status,
                avatar_url,
                created_at
            FROM team_join_requests
            WHERE team_id = ? AND status = 'pending'
        ";
        $requestStmt = $pdo->prepare($requestSql);
        $requestStmt->execute([$teamId]);
        $team['join_requests'] = $requestStmt->fetchAll();
        
        // Get captain info if exists
        if (!empty($team['captain_id'])) {
            $captainSql = "
                SELECT id, username, email, avatar, bio
                FROM users
                WHERE id = ?
            ";
            $captainStmt = $pdo->prepare($captainSql);
            $captainStmt->execute([$team['captain_id']]);
            $captain = $captainStmt->fetch();
            
            if ($captain) {
                $team['captain'] = $captain;
            } else {
                $team['captain'] = null;
            }
        } else {
            $team['captain'] = null;
        }
        
        $teams[] = $team;
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'count' => count($teams),
        'data' => $teams
    ]);

} catch (PDOException $e) {
    // Log the error
    error_log('Database error in get_teams.php: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    // Log the error
    error_log('General error in get_teams.php: ' . $e->getMessage());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}
