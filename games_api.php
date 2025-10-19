<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Prevent PHP from outputting HTML errors
ini_set('display_errors', 0);
error_reporting(0);

try {
    $db_config = require 'db_config.php';

    // Connect to the database
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4" . 
        (isset($db_config['port']) ? ";port={$db_config['port']}" : ""),
        $db_config['user'],
        $db_config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Fetch all games with statistics
    if ($method === 'GET') {
        $query = "
            SELECT 
                g.id,
                g.name,
                g.slug,
                g.image,
                g.publisher,
                g.is_active,
                COUNT(DISTINCT t.id) as tournaments_count,
                COUNT(DISTINCT CASE 
                    WHEN t.participation_type = 'individual' THEN tr.user_id
                    ELSE NULL
                END) as individual_players_count,
                COUNT(DISTINCT CASE 
                    WHEN t.participation_type = 'team' THEN tm.user_id
                    ELSE NULL
                END) as team_players_count
            FROM games g
            LEFT JOIN tournaments t ON g.id = t.game_id
            LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id
            LEFT JOIN teams te ON tr.team_id = te.id
            LEFT JOIN team_members tm ON te.id = tm.team_id
            GROUP BY g.id, g.name, g.slug, g.image, g.publisher, g.is_active
            ORDER BY g.name ASC
        ";
        
        $stmt = $pdo->query($query);
        $games = $stmt->fetchAll();
        
        // Calculate overall stats
        $totalGames = 0;
        $activeGames = 0;
        $totalTournaments = 0;
        $totalPlayers = 0;
        
        // Process data
        $baseUrl = rtrim(getenv('BASE_URL') ?: 'http://localhost', '/');
        foreach ($games as &$game) {
            // Process image paths
            if (isset($game['image']) && $game['image']) {
                if (strpos($game['image'], 'http') !== 0 && strpos($game['image'], '//') !== 0) {
                    $game['image'] = $baseUrl . '/' . ltrim($game['image'], '/');
                }
            }
            
            // Convert to proper types
            $game['id'] = (int)$game['id'];
            $game['is_active'] = (int)$game['is_active'];
            $game['tournaments_count'] = (int)$game['tournaments_count'];
            
            // Calculate total unique players
            $game['players_count'] = (int)$game['individual_players_count'] + (int)$game['team_players_count'];
            
            // Accumulate stats
            $totalGames++;
            if ($game['is_active'] === 1) {
                $activeGames++;
            }
            $totalTournaments += $game['tournaments_count'];
            $totalPlayers += $game['players_count'];
            
            // Remove temporary counts
            unset($game['individual_players_count']);
            unset($game['team_players_count']);
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'games' => $games,
            'total' => count($games),
            'stats' => [
                'total' => $totalGames,
                'active' => $activeGames,
                'tournaments' => $totalTournaments,
                'players' => $totalPlayers
            ]
        ]);
    }

    // POST - Create new game
    elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['name']) || !isset($data['slug'])) {
            http_response_code(400);
            throw new Exception('Name and slug are required');
        }

        $query = "
            INSERT INTO games (name, slug, image, publisher, is_active)
            VALUES (:name, :slug, :image, :publisher, :is_active)
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':image' => $data['image'] ?? null,
            ':publisher' => $data['publisher'] ?? null,
            ':is_active' => $data['is_active'] ?? 1
        ]);

        $gameId = $pdo->lastInsertId();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Game created successfully',
            'game_id' => (int)$gameId
        ]);
    }

    // PUT - Update game
    elseif ($method === 'PUT') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['id'])) {
            http_response_code(400);
            throw new Exception('Game ID is required');
        }

        $query = "
            UPDATE games 
            SET name = :name,
                slug = :slug,
                image = :image,
                publisher = :publisher,
                is_active = :is_active
            WHERE id = :id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':id' => $data['id'],
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':image' => $data['image'] ?? null,
            ':publisher' => $data['publisher'] ?? null,
            ':is_active' => $data['is_active'] ?? 1
        ]);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Game updated successfully'
        ]);
    }

    // DELETE - Delete game
    elseif ($method === 'DELETE') {
        $gameId = $_GET['id'] ?? null;
        
        if (!$gameId || !is_numeric($gameId)) {
            http_response_code(400);
            throw new Exception('Valid game ID is required');
        }

        // Check if game has tournaments
        $checkQuery = "SELECT COUNT(*) as count FROM tournaments WHERE game_id = :game_id";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([':game_id' => $gameId]);
        $result = $checkStmt->fetch();

        if ($result['count'] > 0) {
            http_response_code(400);
            throw new Exception('Cannot delete game with existing tournaments');
        }

        $query = "DELETE FROM games WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':id' => $gameId]);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Game deleted successfully'
        ]);
    }

    else {
        http_response_code(405);
        throw new Exception('Method not allowed');
    }

} catch (Exception $e) {
    // Log error
    error_log('Games API error: ' . $e->getMessage());
    
    // Return error response (keep existing status code if already set)
    if (http_response_code() === 200) {
        http_response_code(400);
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
