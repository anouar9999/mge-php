<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

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
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Determine which tournament table to use - first check tournaments_old table
    $stmt = $pdo->query("SHOW TABLES LIKE 'tournaments_old'");
    $useOldTable = $stmt->rowCount() > 0;
    
    $tableName = $useOldTable ? 'tournaments_old' : 'tournaments';
    
    // Build query based on available tables - now including game information
    $query = "
        SELECT 
            t.*,
            COUNT(DISTINCT tr.id) as registered_count,
            g.name as game_name,
            g.slug as game_slug,
            g.image as game_image,
            g.publisher as game_publisher
        FROM $tableName t
        LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id AND tr.status = 'accepted'
        LEFT JOIN games g ON t.game_id = g.id
        GROUP BY t.id
        ORDER BY t.created_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process tournaments data
    foreach ($tournaments as &$tournament) {
        // Handle missing fields that your frontend expects
        if (!isset($tournament['description_des_qualifications'])) {
            $tournament['description_des_qualifications'] = '';
        }
        
        if (!isset($tournament['type_de_jeu'])) {
            $tournament['type_de_jeu'] = '';
        }
        
        if (!isset($tournament['type_de_match'])) {
            $tournament['type_de_match'] = '';
        }
        
        if (!isset($tournament['prize_pool'])) {
            $tournament['prize_pool'] = '0';
        }
        
        if (!isset($tournament['slug'])) {
            // Generate a slug if not present
            $tournament['slug'] = strtolower(
                preg_replace('/[^a-zA-Z0-9]+/', '-', $tournament['nom_des_qualifications'] ?? $tournament['name'])
            );
        }
        
        // Process registration info
        $max_spots = isset($tournament['nombre_maximum']) ? intval($tournament['nombre_maximum']) : 
                    (isset($tournament['max_participants']) ? intval($tournament['max_participants']) : 0);
        $registered = intval($tournament['registered_count']);
        $spots_remaining = max(0, $max_spots - $registered);
        $percentage = $max_spots > 0 ? round(($registered / $max_spots) * 100) : 0;

        $tournament['spots_remaining'] = $spots_remaining;
        $tournament['registration_progress'] = [
            'total' => $max_spots,
            'filled' => $registered,
            'percentage' => $percentage
        ];
        
        // Process time info
        if (isset($tournament['start_date']) && isset($tournament['end_date'])) {
            $start = new DateTime($tournament['start_date']);
            $end = new DateTime($tournament['end_date']);
            $now = new DateTime();

            $tournament['time_info'] = [
                'is_started' => $now >= $start,
                'is_ended' => $now > $end,
                'days_remaining' => max(0, $now > $start ? 0 : $start->diff($now)->days)
            ];
        } else {
            $tournament['time_info'] = [
                'is_started' => false,
                'is_ended' => false,
                'days_remaining' => 0
            ];
        }
        
        // Image path processing
        if (isset($tournament['image']) || isset($tournament['featured_image'])) {
            $imagePath = isset($tournament['featured_image']) ? $tournament['featured_image'] : $tournament['image'];
            
            // Make sure image URL is absolute if it's a relative path
            if (strpos($imagePath, 'http') !== 0 && strpos($imagePath, '//') !== 0) {
                // Only prepend the base URL if the image path doesn't already have it
                $baseUrl = rtrim(getenv('BASE_URL') ?: '', '/');
                $tournament['image'] = $baseUrl . '/' . ltrim($imagePath, '/');
            }
        }
        
        // Game information processing
        if (isset($tournament['game_name'])) {
            $tournament['game'] = [
                'name' => $tournament['game_name'],
                'slug' => $tournament['game_slug'],
                'image' => $tournament['game_image'],
                'publisher' => $tournament['game_publisher']
            ];
            
            // Process game image if present
            if (isset($tournament['game_image']) && $tournament['game_image']) {
                if (strpos($tournament['game_image'], 'http') !== 0 && strpos($tournament['game_image'], '//') !== 0) {
                    $baseUrl = rtrim(getenv('BASE_URL') ?: '', '/');
                    $tournament['game']['image'] = $baseUrl . '/' . ltrim($tournament['game_image'], '/');
                }
            }
            
            // Remove the redundant game fields from the main tournament object
            unset($tournament['game_name']);
            unset($tournament['game_slug']);
            unset($tournament['game_image']);
            unset($tournament['game_publisher']);
        } else {
            $tournament['game'] = null;
        }
    }

    echo json_encode([
        'success' => true, 
        'tournaments' => $tournaments,
        'total_count' => count($tournaments)
    ]);

} catch (Exception $e) {
    // Log error to server log but don't expose details
    error_log('Tournament fetch error: ' . $e->getMessage());
    
    // Return a clean error response
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch tournaments',
        'error' => $e->getMessage()
    ]);
}
?>