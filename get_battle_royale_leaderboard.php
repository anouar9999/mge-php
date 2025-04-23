<?php
// generate_battle_royale_leaderboard.php
// Enhanced endpoint that generates a comprehensive battle royale tournament leaderboard 
// supporting both individual players and teams

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Load database configuration
$db_config = require 'db_config.php';

// Parse request parameters
$tournament_id = isset($_GET['tournament_id']) ? intval($_GET['tournament_id']) : 0;
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'full'; // Options: full, basic, compact
$match_id = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0; // If specified, get data for a specific match only
$include_matches = isset($_GET['include_matches']) ? filter_var($_GET['include_matches'], FILTER_VALIDATE_BOOLEAN) : false;

// Validate tournament ID
if ($tournament_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid tournament ID']);
    exit();
}

try {
    // Database connection
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], $options);
    
    // Get tournament information
    $tournamentSql = "
        SELECT 
            t.id, 
            t.name, 
            t.slug,
            t.bracket_type, 
            t.participation_type,
            t.game_id,
            t.featured_image,
            t.description,
            t.start_date,
            t.end_date,
            t.status,
            g.name AS game_name,
            g.image AS game_image
        FROM tournaments t
        LEFT JOIN games g ON t.game_id = g.id
        WHERE t.id = ?
    ";
    $tournamentStmt = $pdo->prepare($tournamentSql);
    $tournamentStmt->execute([$tournament_id]);
    $tournament = $tournamentStmt->fetch();
    
    if (!$tournament) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tournament not found']);
        exit();
    }
    
    // Update tournament bracket_type if it's not already set to Battle Royale
    if ($tournament['bracket_type'] !== 'Battle Royale') {
        $updateSql = "UPDATE tournaments SET bracket_type = 'Battle Royale' WHERE id = ?";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([$tournament_id]);
        $tournament['bracket_type'] = 'Battle Royale';
    }
    
    // Get or create battle royale settings for the tournament
    $settingsSql = "
        SELECT 
            kill_points, 
            placement_points_distribution, 
            match_count
        FROM battle_royale_settings 
        WHERE tournament_id = ?
    ";
    $settingsStmt = $pdo->prepare($settingsSql);
    $settingsStmt->execute([$tournament_id]);
    $settings = $settingsStmt->fetch();
    
    if (!$settings) {
        // Create default settings if none exist
        $defaultPointsDistribution = [
            "1" => 15, "2" => 12, "3" => 10, "4" => 8, "5" => 6,
            "6" => 4, "7" => 2, "8" => 1
        ];
        
        $createSettingsSql = "
            INSERT INTO battle_royale_settings 
            (tournament_id, kill_points, placement_points_distribution, match_count)
            VALUES (?, 1, ?, 0)
        ";
        $createSettingsStmt = $pdo->prepare($createSettingsSql);
        $createSettingsStmt->execute([
            $tournament_id, 
            json_encode($defaultPointsDistribution)
        ]);
        
        $settings = [
            'kill_points' => 1,
            'placement_points_distribution' => json_encode($defaultPointsDistribution),
            'match_count' => 0
        ];
    }
    
    // Parse placement points distribution
    $placementPoints = json_decode($settings['placement_points_distribution'], true);
    $settings['placement_points_distribution'] = $placementPoints;
    
    // Determine participant type based on tournament settings (individual or team)
    $participantType = strtolower($tournament['participation_type']);
    $isTeamTournament = ($participantType === 'team');
    
    // Get all participants for the tournament based on type
    $participants = [];
    
    if ($isTeamTournament) {
        // For team tournaments, get registered teams
        $teamsSql = "
            SELECT 
                t.id AS participant_id, 
                t.name AS participant_name, 
                t.tag AS participant_tag,
                t.slug AS participant_slug,
                t.logo AS participant_image, 
                t.banner AS participant_banner,
                t.tier AS participant_tier,
                COALESCE(tm.total_members, 0) AS member_count,
                t.win_rate
            FROM teams t
            JOIN tournament_registrations tr ON t.id = tr.team_id
            LEFT JOIN (
                SELECT team_id, COUNT(*) AS total_members 
                FROM team_members 
                GROUP BY team_id
            ) tm ON t.id = tm.team_id
            WHERE tr.tournament_id = ? AND tr.status = 'accepted'
        ";
        $participantsStmt = $pdo->prepare($teamsSql);
        $participantsStmt->execute([$tournament_id]);
    } else {
        // For individual tournaments, get registered users
        $usersSql = "
            SELECT 
                u.id AS participant_id,
                u.username AS participant_name,
                '' AS participant_tag,
                '' AS participant_slug,
                u.avatar AS participant_image,
                NULL AS participant_banner,
                'amateur' AS participant_tier,
                1 AS member_count,
                0 AS win_rate
            FROM users u
            JOIN tournament_registrations tr ON u.id = tr.user_id
            WHERE tr.tournament_id = ? AND tr.status = 'accepted'
        ";
        $participantsStmt = $pdo->prepare($usersSql);
        $participantsStmt->execute([$tournament_id]);
    }
    
    $participantsData = $participantsStmt->fetchAll();
    
    // Create a participants lookup array with initial stats
    foreach ($participantsData as $participant) {
        $participantId = $participant['participant_id'];
        $participants[$participantId] = [
            'participant_id' => $participantId,
            'participant_name' => $participant['participant_name'],
            'participant_tag' => $participant['participant_tag'],
            'participant_slug' => $participant['participant_slug'],
            'participant_image' => $participant['participant_image'],
            'participant_banner' => $participant['participant_banner'],
            'participant_tier' => $participant['participant_tier'],
            'member_count' => $participant['member_count'],
            'win_rate' => floatval($participant['win_rate']),
            'total_kills' => 0,
            'total_placement_points' => 0,
            'total_points' => 0,
            'matches_played' => 0,
            'highest_position' => null,
            'lowest_position' => null,
            'avg_position' => 0,
            'positions' => [],
            'match_history' => [],
            'is_team' => $isTeamTournament
        ];
    }
    
    // Get all matches for the tournament if no specific match_id was provided
    $matchCondition = $match_id > 0 ? "AND m.id = ?" : "";
    $matchesParams = $match_id > 0 ? [$tournament_id, $match_id] : [$tournament_id];
    
    $matchesSql = "
        SELECT 
            m.id, 
            m.tournament_round_text, 
            m.start_time, 
            m.state,
            m.created_at
        FROM matches m
        WHERE m.tournament_id = ? $matchCondition
        ORDER BY m.start_time ASC, m.id ASC
    ";
    $matchesStmt = $pdo->prepare($matchesSql);
    $matchesStmt->execute($matchesParams);
    $matches = $matchesStmt->fetchAll();
    
    // Initialize match lookup and IDs array
    $matchLookup = [];
    $matchIds = [];
    
    foreach ($matches as $match) {
        $matchId = $match['id'];
        $matchIds[] = $matchId;
        $matchLookup[$matchId] = [
            'id' => $matchId,
            'round' => $match['tournament_round_text'],
            'start_time' => $match['start_time'],
            'state' => $match['state'],
            'created_at' => $match['created_at'],
            'results' => []
        ];
    }
    
    // Get battle royale match results if matches exist
    if (count($matchIds) > 0) {
        // Define the column name for participant ID based on tournament type
        $participantIdColumn = $isTeamTournament ? 'team_id' : 'user_id';
        
        $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
        $resultsSql = "
            SELECT 
                br.match_id,
                br.$participantIdColumn AS participant_id,
                br.position,
                br.kills,
                br.placement_points,
                br.total_points
            FROM battle_royale_match_results br
            WHERE br.match_id IN ($placeholders)
            ORDER BY br.match_id, br.position
        ";
        $resultsStmt = $pdo->prepare($resultsSql);
        $resultsStmt->execute($matchIds);
        $results = $resultsStmt->fetchAll();
        
        // Process results into both participant stats and match results
        foreach ($results as $result) {
            $matchId = $result['match_id'];
            $participantId = $result['participant_id'];
            $position = (int)$result['position'];
            $kills = (int)$result['kills'];
            $placementPoints = (int)$result['placement_points'];
            $totalPoints = (int)$result['total_points'];
            
            // Skip if participant not in our lookup
            if (!isset($participants[$participantId])) {
                continue;
            }
            
            // Add to match lookup
            if (isset($matchLookup[$matchId])) {
                $matchLookup[$matchId]['results'][] = [
                    'participant_id' => $participantId,
                    'position' => $position,
                    'kills' => $kills,
                    'placement_points' => $placementPoints,
                    'total_points' => $totalPoints
                ];
            }
            
            // Update participant stats
            $participants[$participantId]['total_kills'] += $kills;
            $participants[$participantId]['total_placement_points'] += $placementPoints;
            $participants[$participantId]['total_points'] += $totalPoints;
            $participants[$participantId]['matches_played']++;
            $participants[$participantId]['positions'][] = $position;
            
            // Track match history for the participant
            $participants[$participantId]['match_history'][] = [
                'match_id' => $matchId,
                'position' => $position,
                'kills' => $kills,
                'placement_points' => $placementPoints,
                'total_points' => $totalPoints
            ];
            
            // Update highest (best) position - lowest number
            if ($participants[$participantId]['highest_position'] === null || $position < $participants[$participantId]['highest_position']) {
                $participants[$participantId]['highest_position'] = $position;
            }
            
            // Update lowest (worst) position - highest number
            if ($participants[$participantId]['lowest_position'] === null || $position > $participants[$participantId]['lowest_position']) {
                $participants[$participantId]['lowest_position'] = $position;
            }
        }
        
        // Calculate additional stats for each participant
        foreach ($participants as $participantId => &$participant) {
            if ($participant['matches_played'] > 0) {
                // Calculate average position
                $participant['avg_position'] = array_sum($participant['positions']) / count($participant['positions']);
                $participant['avg_position'] = round($participant['avg_position'], 1);
                
                // Calculate averages per match
                $participant['avg_kills_per_match'] = $participant['total_kills'] / $participant['matches_played'];
                $participant['avg_placement_points_per_match'] = $participant['total_placement_points'] / $participant['matches_played'];
                $participant['avg_points_per_match'] = $participant['total_points'] / $participant['matches_played'];
                
                // Round averages to 1 decimal place
                $participant['avg_kills_per_match'] = round($participant['avg_kills_per_match'], 1);
                $participant['avg_placement_points_per_match'] = round($participant['avg_placement_points_per_match'], 1);
                $participant['avg_points_per_match'] = round($participant['avg_points_per_match'], 1);
            }
            
            // Sort match history by match ID
            usort($participant['match_history'], function($a, $b) {
                return $a['match_id'] - $b['match_id'];
            });
        }
        
        // For matches, resolve participant names for display
        foreach ($matchLookup as $matchId => &$matchData) {
            foreach ($matchData['results'] as &$result) {
                $participantId = $result['participant_id'];
                if (isset($participants[$participantId])) {
                    $result['participant_name'] = $participants[$participantId]['participant_name'];
                    if ($isTeamTournament) {
                        $result['participant_tag'] = $participants[$participantId]['participant_tag'];
                    }
                    $result['participant_image'] = $participants[$participantId]['participant_image'];
                }
            }
        }
    }
    
    // Convert participants to indexed array for output
    $participantsArray = array_values($participants);
    
    // Sort participants by total points (descending) and other tiebreakers
    usort($participantsArray, function($a, $b) {
        // Sort by total points (descending)
        if ($a['total_points'] != $b['total_points']) {
            return $b['total_points'] - $a['total_points'];
        }
        
        // If points tied, sort by kills (descending)
        if ($a['total_kills'] != $b['total_kills']) {
            return $b['total_kills'] - $a['total_kills'];
        }
        
        // If kills tied, sort by best position (ascending)
        if ($a['highest_position'] != $b['highest_position'] && 
            $a['highest_position'] !== null && 
            $b['highest_position'] !== null) {
            return $a['highest_position'] - $b['highest_position'];
        }
        
        // If best position tied, sort by alphabetical participant name
        return strcmp($a['participant_name'], $b['participant_name']);
    });
    
    // Add rank to each participant
    foreach ($participantsArray as $index => &$participant) {
        $participant['rank'] = $index + 1;
    }
    
    // Prepare response data based on format
    $responseData = [
        'success' => true,
        'tournament' => [
            'id' => $tournament['id'],
            'name' => $tournament['name'],
            'slug' => $tournament['slug'],
            'game_id' => $tournament['game_id'],
            'game_name' => $tournament['game_name'],
            'game_image' => $tournament['game_image'],
            'participation_type' => $tournament['participation_type'],
            'status' => $tournament['status'],
            'description' => $tournament['description'],
            'start_date' => $tournament['start_date'],
            'end_date' => $tournament['end_date'],
            'featured_image' => $tournament['featured_image']
        ],
        'settings' => [
            'kill_points' => (int)$settings['kill_points'],
            'placement_points_distribution' => $placementPoints,
            'match_count' => (int)$settings['match_count']
        ],
        'stats' => [
            'total_matches' => count($matches),
            'total_participants' => count($participantsArray),
            'participants_type' => $isTeamTournament ? 'teams' : 'players'
        ]
    ];
    
    // Add participants data based on requested format
    switch ($format) {
        case 'basic':
            // Basic format only includes essential participant data
            $basicParticipants = [];
            foreach ($participantsArray as $participant) {
                $entry = [
                    'rank' => $participant['rank'],
                    'participant_id' => $participant['participant_id'],
                    'participant_name' => $participant['participant_name'],
                    'participant_image' => $participant['participant_image'],
                    'matches_played' => $participant['matches_played'],
                    'total_kills' => $participant['total_kills'],
                    'total_placement_points' => $participant['total_placement_points'],
                    'total_points' => $participant['total_points']
                ];
                
                // Add team-specific fields if applicable
                if ($isTeamTournament) {
                    $entry['participant_tag'] = $participant['participant_tag'];
                }
                
                $basicParticipants[] = $entry;
            }
            $responseData['participants'] = $basicParticipants;
            break;
            
        case 'compact':
            // Compact format is a minimal version for lightweight usage
            $compactParticipants = [];
            foreach ($participantsArray as $participant) {
                $compactParticipants[] = [
                    'r' => $participant['rank'],
                    'id' => $participant['participant_id'],
                    'n' => $participant['participant_name'],
                    'k' => $participant['total_kills'],
                    'p' => $participant['total_placement_points'],
                    't' => $participant['total_points']
                ];
            }
            $responseData['participants'] = $compactParticipants;
            break;
            
        case 'full':
        default:
            // Full format includes all participant data
            $responseData['participants'] = $participantsArray;
            
            // Add matches data if requested
            if ($include_matches && count($matches) > 0) {
                $responseData['matches'] = array_values($matchLookup);
            }
            break;
    }
    
    // Return JSON response
    echo json_encode($responseData);

} catch (PDOException $e) {
    // Log the error
    error_log('Database error in generate_battle_royale_leaderboard.php: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    // Log the error
    error_log('General error in generate_battle_royale_leaderboard.php: ' . $e->getMessage());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}