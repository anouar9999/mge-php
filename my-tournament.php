<?php
// user_tournaments.php - Get tournaments for a specific user
// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
    $db_config = require 'db_config.php';

// 🔥 FIXED: Proper CORS headers with multiple origins support
$allowedOrigins = [
    "http://{$db_config['api']['host']}:3000",  // Next.js app
    "http://{$db_config['api']['host']}:5173",  // Vite app
	'https://user.gnews.ma',
    'https://yourdomain.com'  // Production (update as needed)
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Fallback for development
    header("Access-Control-Allow-Origin: http://{$db_config['api']['host']}:3000");
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

try {
    if (!$db_config) {
        throw new Exception('Database configuration not found');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 🔥 FIXED: Better parameter validation
    if (!isset($_GET['user_id'])) {
        throw new Exception('User ID is required');
    }
    
    $user_id = filter_var($_GET['user_id'], FILTER_VALIDATE_INT);
    if (!$user_id || $user_id <= 0) {
        throw new Exception('Invalid user ID format');
    }

    // 🔥 FIXED: Check if user exists first
    $userStmt = $pdo->prepare("SELECT id, username FROM users WHERE id = :user_id");
    $userStmt->execute([':user_id' => $user_id]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        throw new Exception('User not found');
    }
    
    $username = $userData['username'];

    // 🔥 IMPROVED: Separate queries for better debugging and performance
    $tournaments = [];
    
    // Query 1: Individual tournaments
    try {
        $individualQuery = "
            SELECT t.*, 
                   g.name as game_name,
                   g.slug as game_slug,
                   g.image as game_image,
                   g.id as game_id,
                   tr.status as registration_status,
                   tr.registration_date,
                   NULL as team_name,
                   'individual' as participation_source,
                   (SELECT COUNT(*) FROM tournament_registrations 
                    WHERE tournament_id = t.id AND status = 'accepted') as registered_count
            FROM tournaments t
            INNER JOIN tournament_registrations tr ON t.id = tr.tournament_id
            LEFT JOIN games g ON t.game_id = g.id
            WHERE t.participation_type = 'individual' 
            AND tr.user_id = :user_id
            AND tr.status IN ('pending', 'accepted')
        ";
        
        $stmt1 = $pdo->prepare($individualQuery);
        $stmt1->execute([':user_id' => $user_id]);
        $individualTournaments = $stmt1->fetchAll(PDO::FETCH_ASSOC);
        
        $tournaments = array_merge($tournaments, $individualTournaments);
        
    } catch (PDOException $e) {
        error_log("Individual tournaments query failed: " . $e->getMessage());
    }

    // Query 2: Team tournaments where user is team owner
    try {
        $teamOwnerQuery = "
            SELECT t.*, 
                   g.name as game_name,
                   g.slug as game_slug,
                   g.image as game_image,
                   g.id as game_id,
                   tr.status as registration_status,
                   tr.registration_date,
                   tm.name as team_name,
                   'team_owner' as participation_source,
                   (SELECT COUNT(*) FROM tournament_registrations 
                    WHERE tournament_id = t.id AND status = 'accepted') as registered_count
            FROM tournaments t
            INNER JOIN tournament_registrations tr ON t.id = tr.tournament_id
            INNER JOIN teams tm ON tr.team_id = tm.id
            LEFT JOIN games g ON t.game_id = g.id
            WHERE t.participation_type = 'team' 
            AND tm.owner_id = :user_id
            AND tr.status IN ('pending', 'accepted')
        ";
        
        $stmt2 = $pdo->prepare($teamOwnerQuery);
        $stmt2->execute([':user_id' => $user_id]);
        $teamOwnerTournaments = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        
        $tournaments = array_merge($tournaments, $teamOwnerTournaments);
        
    } catch (PDOException $e) {
        error_log("Team owner tournaments query failed: " . $e->getMessage());
    }

    // Query 3: Team tournaments where user is team member
    try {
        $teamMemberQuery = "
            SELECT t.*, 
                   g.name as game_name,
                   g.slug as game_slug,
                   g.image as game_image,
                   g.id as game_id,
                   tr.status as registration_status,
                   tr.registration_date,
                   tm.name as team_name,
                   'team_member' as participation_source,
                   (SELECT COUNT(*) FROM tournament_registrations 
                    WHERE tournament_id = t.id AND status = 'accepted') as registered_count
            FROM tournaments t
            INNER JOIN tournament_registrations tr ON t.id = tr.tournament_id
            INNER JOIN teams tm ON tr.team_id = tm.id
            INNER JOIN team_members tmem ON tm.id = tmem.team_id
            LEFT JOIN games g ON t.game_id = g.id
            WHERE t.participation_type = 'team' 
            AND tmem.user_id = :user_id
            AND tr.status IN ('pending', 'accepted')
            AND tm.owner_id != :user_id
        ";
        
        $stmt3 = $pdo->prepare($teamMemberQuery);
        $stmt3->execute([':user_id' => $user_id]);
        $teamMemberTournaments = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        
        $tournaments = array_merge($tournaments, $teamMemberTournaments);
        
    } catch (PDOException $e) {
        error_log("Team member tournaments query failed: " . $e->getMessage());
        
        // 🔥 FALLBACK: Alternative approach if team_members table structure is different
        try {
            // Check if team_members table has 'name' column instead of 'user_id'
            $tableInfoQuery = "DESCRIBE team_members";
            $tableInfoStmt = $pdo->prepare($tableInfoQuery);
            $tableInfoStmt->execute();
            $columns = $tableInfoStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (in_array('name', $columns) && !in_array('user_id', $columns)) {
                // Use name-based lookup as fallback
                $teamMemberByNameQuery = "
                    SELECT t.*, 
                           g.name as game_name,
                           g.slug as game_slug,
                           g.image as game_image,
                           g.id as game_id,
                           tr.status as registration_status,
                           tr.registration_date,
                           tm.name as team_name,
                           'team_member_by_name' as participation_source,
                           (SELECT COUNT(*) FROM tournament_registrations 
                            WHERE tournament_id = t.id AND status = 'accepted') as registered_count
                    FROM tournaments t
                    INNER JOIN tournament_registrations tr ON t.id = tr.tournament_id
                    INNER JOIN teams tm ON tr.team_id = tm.id
                    INNER JOIN team_members tmem ON tm.id = tmem.team_id
                    LEFT JOIN games g ON t.game_id = g.id
                    WHERE t.participation_type = 'team' 
                    AND tmem.name = :username
                    AND tr.status IN ('pending', 'accepted')
                    AND tm.owner_id != :user_id
                ";
                
                $stmt4 = $pdo->prepare($teamMemberByNameQuery);
                $stmt4->execute([':username' => $username, ':user_id' => $user_id]);
                $teamMemberByNameTournaments = $stmt4->fetchAll(PDO::FETCH_ASSOC);
                
                $tournaments = array_merge($tournaments, $teamMemberByNameTournaments);
            }
        } catch (PDOException $e2) {
            error_log("Fallback team member query also failed: " . $e2->getMessage());
        }
    }
    
    // 🔥 FIXED: Remove duplicates based on tournament ID
    $uniqueTournaments = [];
    $seenIds = [];
    
    foreach ($tournaments as $tournament) {
        $tournamentId = $tournament['id'];
        if (!in_array($tournamentId, $seenIds)) {
            $uniqueTournaments[] = $tournament;
            $seenIds[] = $tournamentId;
        }
    }
    
    // 🔥 IMPROVED: Sort by multiple criteria
    usort($uniqueTournaments, function($a, $b) {
        // First by status (ongoing tournaments first)
        $statusOrder = ['ongoing' => 1, 'registration_open' => 2, 'registration_closed' => 3, 'completed' => 4, 'cancelled' => 5];
        $statusA = $statusOrder[$a['status']] ?? 6;
        $statusB = $statusOrder[$b['status']] ?? 6;
        
        if ($statusA !== $statusB) {
            return $statusA - $statusB;
        }
        
        // Then by start date (nearest first)
        return strtotime($a['start_date']) - strtotime($b['start_date']);
    });

    // 🔥 ENHANCED: Process tournaments with better calculated fields
    $processedTournaments = array_map(function($tournament) {
        $maxSpots = (int)($tournament['max_participants'] ?? 0);
        $registeredCount = (int)($tournament['registered_count'] ?? 0);
        $startDate = strtotime($tournament['start_date'] ?? '');
        $endDate = strtotime($tournament['end_date'] ?? '');
        $regStart = strtotime($tournament['registration_start'] ?? '');
        $regEnd = strtotime($tournament['registration_end'] ?? '');
        $now = time();

        // Enhanced time calculations
        $timeInfo = [
            'is_registration_open' => ($regStart <= $now && $regEnd >= $now),
            'is_registration_closed' => ($regEnd < $now),
            'is_started' => ($startDate <= $now),
            'is_ended' => ($endDate < $now),
            'days_until_start' => $startDate > $now ? ceil(($startDate - $now) / 86400) : 0,
            'days_until_end' => $endDate > $now ? ceil(($endDate - $now) / 86400) : 0,
            'registration_days_remaining' => $regEnd > $now ? ceil(($regEnd - $now) / 86400) : 0
        ];

        // Game information
        $gameInfo = [
            'id' => (int)($tournament['game_id'] ?? 0),
            'name' => $tournament['game_name'] ?? 'Unknown Game',
            'slug' => $tournament['game_slug'] ?? '',
            'image' => $tournament['game_image'] ?? null
        ];

        // Registration progress
        $registrationProgress = [
            'total' => $maxSpots,
            'filled' => $registeredCount,
            'remaining' => max(0, $maxSpots - $registeredCount),
            'percentage' => $maxSpots > 0 ? round(($registeredCount / $maxSpots) * 100, 1) : 0,
            'is_full' => ($registeredCount >= $maxSpots && $maxSpots > 0)
        ];

        // Build result
        $result = [
            'id' => (int)$tournament['id'],
            'name' => $tournament['name'] ?? '',
            'slug' => $tournament['slug'] ?? '',
            'description' => $tournament['description'] ?? '',
            'status' => $tournament['status'] ?? 'draft',
            'bracket_type' => $tournament['bracket_type'] ?? 'Single Elimination',
            'participation_type' => $tournament['participation_type'] ?? 'team',
            'participation_source' => $tournament['participation_source'] ?? 'unknown',
            'max_participants' => $maxSpots,
            'prize_pool' => (float)($tournament['prize_pool'] ?? 0),
            'start_date' => $tournament['start_date'] ?? null,
            'end_date' => $tournament['end_date'] ?? null,
            'registration_start' => $tournament['registration_start'] ?? null,
            'registration_end' => $tournament['registration_end'] ?? null,
            'created_at' => $tournament['created_at'] ?? null,
            'featured_image' => $tournament['featured_image'] ?? null,
            'team_name' => $tournament['team_name'] ?? null,
            'registration_status' => $tournament['registration_status'] ?? 'pending',
            'registration_date' => $tournament['registration_date'] ?? null,
            'game' => $gameInfo,
            'registration_progress' => $registrationProgress,
            'time_info' => $timeInfo
        ];
        
        return $result;
    }, $uniqueTournaments);

    // 🔥 ENHANCED: Better response with metadata
    $response = [
        'success' => true,
        'data' => [
            'tournaments' => $processedTournaments,
            'summary' => [
                'total_count' => count($processedTournaments),
                'by_status' => [],
                'by_participation_type' => [
                    'individual' => 0,
                    'team' => 0
                ],
                'by_participation_source' => []
            ]
        ]
    ];

    // Calculate summary statistics
    $statusCounts = [];
    $sourceCounts = [];
    
    foreach ($processedTournaments as $tournament) {
        $status = $tournament['status'];
        $participationType = $tournament['participation_type'];
        $participationSource = $tournament['participation_source'];
        
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        $response['data']['summary']['by_participation_type'][$participationType]++;
        $sourceCounts[$participationSource] = ($sourceCounts[$participationSource] ?? 0) + 1;
    }
    
    $response['data']['summary']['by_status'] = $statusCounts;
    $response['data']['summary']['by_participation_source'] = $sourceCounts;

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Database error in user_tournaments.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error_code' => 'DB_ERROR'
        // Remove detailed error info in production
        // 'debug' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("General error in user_tournaments.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'GENERAL_ERROR'
    ]);
}
?>
