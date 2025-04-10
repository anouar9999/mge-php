<?php
// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Function to handle paths for images
function formatImagePath($path) {
    if (empty($path)) return null;
    
    // Check if the path is already absolute
    if (strpos($path, 'http') === 0 || strpos($path, '//') === 0) {
        return $path;
    }
    
    // Remove any leading slash
    $path = ltrim($path, '/');
    
    // Get base URL
    $baseUrl = isset($_SERVER['HTTP_HOST']) ? 
        ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : 
        '';
    
    return $baseUrl . '/' . $path;
}

try {
    // Load database configuration
    if (!file_exists('db_config.php')) {
        throw new Exception('Database configuration file not found');
    }
    
    $db_config = require 'db_config.php';
    
    // Connect to the database
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4" . 
        (isset($db_config['port']) ? ";port={$db_config['port']}" : ""),
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Determine which tournament table to use
    $stmt = $pdo->query("SHOW TABLES LIKE 'tournaments'");
    if ($stmt->rowCount() > 0) {
        $tableName = 'tournaments';
    } else {
        $stmt = $pdo->query("SHOW TABLES LIKE 'tournaments_backup_new'");
        if ($stmt->rowCount() > 0) {
            $tableName = 'tournaments_backup_new';
        } else {
            throw new Exception('No tournaments table found in database');
        }
    }
    
    // Get all tournaments
    $query = "SELECT * FROM $tableName ORDER BY created_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process each tournament
    foreach ($tournaments as &$tournament) {
        // Get game information
        if (isset($tournament['game_id'])) {
            $gameQuery = "SELECT id, name, slug, image, publisher FROM games WHERE id = ?";
            $gameStmt = $pdo->prepare($gameQuery);
            $gameStmt->execute([$tournament['game_id']]);
            $gameData = $gameStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($gameData) {
                // Format game image if available
                if (!empty($gameData['image'])) {
                    $gameData['image'] = formatImagePath($gameData['image']);
                }
                
                $tournament['game'] = $gameData;
            } else {
                $tournament['game'] = [
                    'id' => $tournament['game_id'],
                    'name' => 'Unknown Game',
                    'slug' => '',
                    'image' => null,
                    'publisher' => null
                ];
            }
        } else {
            $tournament['game'] = [
                'id' => 0,
                'name' => 'Unknown Game',
                'slug' => '',
                'image' => null,
                'publisher' => null
            ];
        }
        
        // Format featured image
        if (isset($tournament['featured_image']) && !empty($tournament['featured_image'])) {
            $tournament['featured_image'] = formatImagePath($tournament['featured_image']);
        }
        
        // Add registration info if available
        if (isset($tournament['id'])) {
            $regQuery = "SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = ?";
            $regStmt = $pdo->prepare($regQuery);
            $regStmt->execute([$tournament['id']]);
            $registeredCount = (int)$regStmt->fetchColumn();
            
            $maxParticipants = $tournament['max_participants'] ?? 0;
            $spotsRemaining = max(0, $maxParticipants - $registeredCount);
            $percentage = $maxParticipants > 0 ? round(($registeredCount / $maxParticipants) * 100) : 0;
            
            $tournament['registration_count'] = $registeredCount;
            $tournament['spots_remaining'] = $spotsRemaining;
            $tournament['registration_progress'] = [
                'total' => $maxParticipants,
                'filled' => $registeredCount,
                'percentage' => min(100, $percentage)
            ];
        }
        
        // Ensure required fields exist
        if (!isset($tournament['description'])) {
            $tournament['description'] = '';
        }
        
        if (!isset($tournament['match_format'])) {
            $tournament['match_format'] = '';
        }
        
        if (!isset($tournament['prize_pool'])) {
            $tournament['prize_pool'] = '0';
        }
        
        // Generate slug if not present
        if (!isset($tournament['slug']) || empty($tournament['slug'])) {
            $tournament['slug'] = isset($tournament['name']) ? 
                strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $tournament['name'])) : '';
        }
        
        // Add time info
        if (isset($tournament['start_date']) && isset($tournament['end_date'])) {
            $now = new DateTime();
            $startDate = new DateTime($tournament['start_date']);
            $endDate = new DateTime($tournament['end_date']);
            
            $tournament['time_info'] = [
                'is_started' => $now >= $startDate,
                'is_ended' => $now > $endDate,
                'days_remaining' => $startDate > $now ? $now->diff($startDate)->days : 0
            ];
        }
    }
    
    // Return the data as JSON
    echo json_encode([
        'success' => true,
        'tournaments' => $tournaments,
        'total_count' => count($tournaments)
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>