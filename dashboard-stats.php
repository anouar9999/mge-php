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
    
    // Initialize comprehensive stats array
    $stats = [
        // ============================================
        // 1. CORE METRICS
        // ============================================
        'coreMetrics' => [
            'users' => [
                'total' => 0,
                'verified' => 0,
                'unverified' => 0,
                'active' => 0,
                'newThisMonth' => 0,
                'newThisWeek' => 0,
            ],
            'tournaments' => [
                'total' => 0,
                'live' => 0,
                'upcoming' => 0,
                'completed' => 0,
                'draft' => 0,
                'cancelled' => 0,
            ],
            'financial' => [
                'totalPrizePoolDistributed' => 0,
                'activePrizePool' => 0,
                'pendingPrizePool' => 0,
                'avgPrizePerTournament' => 0,
                'largestPrizePool' => 0,
            ],
            'teams' => [
                'total' => 0,
                'active' => 0,
                'amateur' => 0,
                'semiPro' => 0,
                'professional' => 0,
                'averageSize' => 0,
            ],
        ],
        
        // ============================================
        // 2. ENGAGEMENT METRICS
        // ============================================
        'engagement' => [
            'registrations' => [
                'total' => 0,
                'pending' => 0,
                'accepted' => 0,
                'rejected' => 0,
                'acceptanceRate' => 0,
                'rejectionRate' => 0,
            ],
            'teamRequests' => [
                'pending' => 0,
                'accepted' => 0,
                'rejected' => 0,
                'acceptanceRate' => 0,
            ],
            'activity' => [
                'dailyActiveUsers' => 0,
                'weeklyActiveUsers' => 0,
                'monthlyActiveUsers' => 0,
            ],
        ],
        
        // ============================================
        // 3. MATCH & COMPETITION STATS
        // ============================================
        'matches' => [
            'total' => 0,
            'scheduled' => 0,
            'live' => 0,
            'completed' => 0,
            'today' => 0,
            'upcomingThisWeek' => 0,
            'roundRobin' => [
                'activeTournaments' => 0,
                'totalMatches' => 0,
            ],
            'battleRoyale' => [
                'activeTournaments' => 0,
                'totalMatches' => 0,
            ],
        ],
        
        // ============================================
        // 4. GAME DISTRIBUTION
        // ============================================
        'games' => [
            'list' => [],
            'tournamentsPerGame' => [],
            'teamsPerGame' => [],
            'playersPerGame' => [],
            'mostPopular' => null,
        ],
        
        // ============================================
        // 5. PERFORMANCE TRENDS
        // ============================================
        'trends' => [
            'userGrowth' => 0,
            'tournamentGrowth' => 0,
            'teamGrowth' => 0,
            'registrationGrowth' => 0,
            'avgTournamentDuration' => 0,
            'tournamentsByMonth' => [],
        ],
        
        // ============================================
        // 6. QUALITY METRICS
        // ============================================
        'quality' => [
            'tournamentCompletionRate' => 0,
            'tournamentCancellationRate' => 0,
            'matchCompletionRate' => 0,
            'avgParticipantsPerTournament' => 0,
            'tournamentFillRate' => 0,
            'teamsWithFullRoster' => 0,
            'activeTeamPercentage' => 0,
        ],
        
        // ============================================
        // 7. ADMINISTRATIVE INSIGHTS
        // ============================================
        'admin' => [
            'totalAdmins' => 0,
            'recentActions' => [
                'tournamentsCreated' => 0,
                'tournamentResets' => 0,
            ],
            'systemHealth' => [
                'failedLoginAttempts' => 0,
                'passwordResetRequests' => 0,
                'pendingVerifications' => 0,
            ],
            'content' => [
                'totalEvents' => 0,
                'activeLiveStreams' => 0,
                'unreadNotifications' => 0,
            ],
        ],
        
        // ============================================
        // 8. TOURNAMENT TYPE DISTRIBUTION
        // ============================================
        'tournamentTypes' => [
            'byBracketType' => [
                'singleElimination' => 0,
                'doubleElimination' => 0,
                'roundRobin' => 0,
                'battleRoyale' => 0,
            ],
            'byParticipationType' => [
                'individual' => 0,
                'team' => 0,
            ],
            'byStatus' => [
                'draft' => 0,
                'registration_open' => 0,
                'registration_closed' => 0,
                'ongoing' => 0,
                'completed' => 0,
                'cancelled' => 0,
            ],
            'avgPrizeByType' => [],
        ],
        
        // ============================================
        // 9. RECENT ACTIVITY FEED
        // ============================================
        'recentActivity' => [],
        
        // ============================================
        // 10. ALERTS & WARNINGS
        // ============================================
        'alerts' => [
            'critical' => [],
            'warning' => [],
            'info' => [],
        ],
    ];
    
    // ============================================
    // FETCH 1: CORE METRICS - USERS
    // ============================================
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    $stats['coreMetrics']['users']['total'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE is_verified = 1");
    $stats['coreMetrics']['users']['verified'] = (int)$stmt->fetchColumn();
    
    $stats['coreMetrics']['users']['unverified'] = 
        $stats['coreMetrics']['users']['total'] - $stats['coreMetrics']['users']['verified'];
    
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT user_id) 
        FROM activity_log 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stats['coreMetrics']['users']['active'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats['coreMetrics']['users']['newThisMonth'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stats['coreMetrics']['users']['newThisWeek'] = (int)$stmt->fetchColumn();
    
    // ============================================
    // FETCH 2: CORE METRICS - TOURNAMENTS
    // ============================================
    
    $stmt = $conn->query("SELECT COUNT(*) FROM tournaments");
    $stats['coreMetrics']['tournaments']['total'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT status, COUNT(*) as count 
        FROM tournaments 
        GROUP BY status
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        $count = (int)$row['count'];
        $stats['tournamentTypes']['byStatus'][$status] = $count;
        
        switch($status) {
            case 'ongoing':
                $stats['coreMetrics']['tournaments']['live'] = $count;
                break;
            case 'completed':
                $stats['coreMetrics']['tournaments']['completed'] = $count;
                break;
            case 'draft':
                $stats['coreMetrics']['tournaments']['draft'] = $count;
                break;
            case 'cancelled':
                $stats['coreMetrics']['tournaments']['cancelled'] = $count;
                break;
        }
    }
    
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM tournaments 
        WHERE status IN ('draft', 'registration_open', 'registration_closed')
        AND start_date > NOW()
    ");
    $stats['coreMetrics']['tournaments']['upcoming'] = (int)$stmt->fetchColumn();
    
    // ============================================
    // FETCH 3: CORE METRICS - FINANCIAL
    // ============================================
    
    $stmt = $conn->query("
        SELECT SUM(prize_pool) 
        FROM tournaments 
        WHERE status = 'completed'
    ");
    $distributed = $stmt->fetchColumn();
    $stats['coreMetrics']['financial']['totalPrizePoolDistributed'] = $distributed ? (float)$distributed : 0;
    
    $stmt = $conn->query("
        SELECT SUM(prize_pool) 
        FROM tournaments 
        WHERE status IN ('ongoing', 'registration_open', 'registration_closed')
    ");
    $active = $stmt->fetchColumn();
    $stats['coreMetrics']['financial']['activePrizePool'] = $active ? (float)$active : 0;
    
    $stmt = $conn->query("
        SELECT SUM(prize_pool) 
        FROM tournaments 
        WHERE status = 'draft'
    ");
    $pending = $stmt->fetchColumn();
    $stats['coreMetrics']['financial']['pendingPrizePool'] = $pending ? (float)$pending : 0;
    
    $stmt = $conn->query("SELECT AVG(prize_pool) FROM tournaments WHERE prize_pool > 0");
    $avg = $stmt->fetchColumn();
    $stats['coreMetrics']['financial']['avgPrizePerTournament'] = $avg ? round((float)$avg, 2) : 0;
    
    $stmt = $conn->query("SELECT MAX(prize_pool) FROM tournaments");
    $max = $stmt->fetchColumn();
    $stats['coreMetrics']['financial']['largestPrizePool'] = $max ? (float)$max : 0;
    
    // ============================================
    // FETCH 4: CORE METRICS - TEAMS
    // ============================================
    
    $stmt = $conn->query("SELECT COUNT(*) FROM teams");
    $stats['coreMetrics']['teams']['total'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT COUNT(DISTINCT team_id) FROM team_members");
    $stats['coreMetrics']['teams']['active'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT tier, COUNT(*) as count 
        FROM teams 
        GROUP BY tier
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tier = $row['tier'];
        $count = (int)$row['count'];
        
        if ($tier === 'amateur') {
            $stats['coreMetrics']['teams']['amateur'] = $count;
        } elseif ($tier === 'semi-pro') {
            $stats['coreMetrics']['teams']['semiPro'] = $count;
        } elseif ($tier === 'professional') {
            $stats['coreMetrics']['teams']['professional'] = $count;
        }
    }
    
    $stmt = $conn->query("
        SELECT AVG(member_count) as avg_size 
        FROM (
            SELECT team_id, COUNT(*) as member_count 
            FROM team_members 
            GROUP BY team_id
        ) as counts
    ");
    $avgSize = $stmt->fetchColumn();
    $stats['coreMetrics']['teams']['averageSize'] = $avgSize ? round((float)$avgSize, 1) : 0;
    
    // ============================================
    // FETCH 5: ENGAGEMENT - REGISTRATIONS
    // ============================================
    
    $stmt = $conn->query("SELECT COUNT(*) FROM tournament_registrations");
    $stats['engagement']['registrations']['total'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT status, COUNT(*) as count 
        FROM tournament_registrations 
        GROUP BY status
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        $count = (int)$row['count'];
        
        if ($status === 'pending') {
            $stats['engagement']['registrations']['pending'] = $count;
        } elseif ($status === 'accepted') {
            $stats['engagement']['registrations']['accepted'] = $count;
        } elseif ($status === 'rejected') {
            $stats['engagement']['registrations']['rejected'] = $count;
        }
    }
    
    // Calculate rates
    $totalRegs = $stats['engagement']['registrations']['total'];
    if ($totalRegs > 0) {
        $stats['engagement']['registrations']['acceptanceRate'] = 
            round(($stats['engagement']['registrations']['accepted'] / $totalRegs) * 100, 1);
        $stats['engagement']['registrations']['rejectionRate'] = 
            round(($stats['engagement']['registrations']['rejected'] / $totalRegs) * 100, 1);
    }
    
    // ============================================
    // FETCH 6: ENGAGEMENT - TEAM REQUESTS
    // ============================================
    
    $stmt = $conn->query("
        SELECT status, COUNT(*) as count 
        FROM team_join_requests 
        GROUP BY status
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        $count = (int)$row['count'];
        
        if ($status === 'pending') {
            $stats['engagement']['teamRequests']['pending'] = $count;
        } elseif ($status === 'accepted') {
            $stats['engagement']['teamRequests']['accepted'] = $count;
        } elseif ($status === 'rejected') {
            $stats['engagement']['teamRequests']['rejected'] = $count;
        }
    }
    
    $totalRequests = $stats['engagement']['teamRequests']['pending'] + 
                     $stats['engagement']['teamRequests']['accepted'] + 
                     $stats['engagement']['teamRequests']['rejected'];
    
    if ($totalRequests > 0) {
        $stats['engagement']['teamRequests']['acceptanceRate'] = 
            round(($stats['engagement']['teamRequests']['accepted'] / $totalRequests) * 100, 1);
    }
    
    // ============================================
    // FETCH 7: ENGAGEMENT - ACTIVITY
    // ============================================
    
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT user_id) 
        FROM activity_log 
        WHERE DATE(timestamp) = CURDATE()
    ");
    $stats['engagement']['activity']['dailyActiveUsers'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT user_id) 
        FROM activity_log 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stats['engagement']['activity']['weeklyActiveUsers'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT user_id) 
        FROM activity_log 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats['engagement']['activity']['monthlyActiveUsers'] = (int)$stmt->fetchColumn();
    
    // ============================================
    // FETCH 8: MATCHES
    // ============================================
    
    $stmt = $conn->query("SELECT COUNT(*) FROM matches");
    $stats['matches']['total'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT state, COUNT(*) as count 
        FROM matches 
        GROUP BY state
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $state = $row['state'];
        $count = (int)$row['count'];
        
        if ($state === 'SCHEDULED') {
            $stats['matches']['scheduled'] = $count;
        } elseif ($state === 'RUNNING') {
            $stats['matches']['live'] = $count;
        } elseif ($state === 'SCORE_DONE') {
            $stats['matches']['completed'] = $count;
        }
    }
    
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM matches 
        WHERE DATE(start_time) = CURDATE()
    ");
    $stats['matches']['today'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM matches 
        WHERE start_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    ");
    $stats['matches']['upcomingThisWeek'] = (int)$stmt->fetchColumn();
    
    // Round Robin stats
    $stmt = $conn->query("SELECT COUNT(DISTINCT tournament_id) FROM round_robin_standings");
    $stats['matches']['roundRobin']['activeTournaments'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT COUNT(*) FROM round_robin_matches");
    $stats['matches']['roundRobin']['totalMatches'] = (int)$stmt->fetchColumn();
    
    // Battle Royale stats
    $stmt = $conn->query("SELECT COUNT(*) FROM battle_royale_settings");
    $stats['matches']['battleRoyale']['activeTournaments'] = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT COUNT(DISTINCT match_id) FROM battle_royale_match_results");
    $stats['matches']['battleRoyale']['totalMatches'] = (int)$stmt->fetchColumn();
    
    // ============================================
    // FETCH 9: GAME DISTRIBUTION
    // ============================================
    
    $stmt = $conn->query("SELECT id, name, slug FROM games WHERE is_active = 1");
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $gameMapping = [];
    foreach ($games as $game) {
        $gameId = $game['id'];
        $gameName = $game['name'];
        $gameKey = lcfirst(str_replace(' ', '', $gameName));
        
        $gameMapping[$gameId] = [
            'id' => $gameId,
            'name' => $gameName,
            'key' => $gameKey,
            'slug' => $game['slug']
        ];
        
        $stats['games']['list'][] = [
            'id' => $gameId,
            'name' => $gameName,
            'slug' => $game['slug']
        ];
        
        // Initialize counters
        $stats['games']['tournamentsPerGame'][$gameKey] = 0;
        $stats['games']['teamsPerGame'][$gameKey] = 0;
        $stats['games']['playersPerGame'][$gameKey] = 0;
    }
    
    // Tournaments per game
    $stmt = $conn->query("
        SELECT game_id, COUNT(*) as count 
        FROM tournaments 
        GROUP BY game_id
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $gameId = $row['game_id'];
        if (isset($gameMapping[$gameId])) {
            $gameKey = $gameMapping[$gameId]['key'];
            $stats['games']['tournamentsPerGame'][$gameKey] = (int)$row['count'];
        }
    }
    
    // Teams per game
    $stmt = $conn->query("
        SELECT game_id, COUNT(*) as count 
        FROM teams 
        GROUP BY game_id
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $gameId = $row['game_id'];
        if (isset($gameMapping[$gameId])) {
            $gameKey = $gameMapping[$gameId]['key'];
            $stats['games']['teamsPerGame'][$gameKey] = (int)$row['count'];
        }
    }
    
    // Players per game (from tournament registrations)
    $stmt = $conn->query("
        SELECT t.game_id, COUNT(DISTINCT tr.user_id) as count 
        FROM tournament_registrations tr
        JOIN tournaments t ON tr.tournament_id = t.id
        WHERE tr.user_id IS NOT NULL
        GROUP BY t.game_id
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $gameId = $row['game_id'];
        if (isset($gameMapping[$gameId])) {
            $gameKey = $gameMapping[$gameId]['key'];
            $stats['games']['playersPerGame'][$gameKey] = (int)$row['count'];
        }
    }
    
    // Find most popular game
    if (!empty($stats['games']['teamsPerGame'])) {
        $maxTeams = max($stats['games']['teamsPerGame']);
        $popularGameKey = array_search($maxTeams, $stats['games']['teamsPerGame']);
        
        $stats['games']['mostPopular'] = [
            'game' => $popularGameKey,
            'teamCount' => $maxTeams,
            'tournamentCount' => $stats['games']['tournamentsPerGame'][$popularGameKey] ?? 0,
            'playerCount' => $stats['games']['playersPerGame'][$popularGameKey] ?? 0,
        ];
    }
    
    // ============================================
    // FETCH 10: PERFORMANCE TRENDS
    // ============================================
    
    // User growth
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $previousMonthUsers = (int)$stmt->fetchColumn();
    
    if ($previousMonthUsers > 0) {
        $stats['trends']['userGrowth'] = round(
            (($stats['coreMetrics']['users']['newThisMonth'] - $previousMonthUsers) / $previousMonthUsers) * 100, 
            1
        );
    }
    
    // Tournament growth
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM tournaments 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $currentMonthTournaments = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM tournaments 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $previousMonthTournaments = (int)$stmt->fetchColumn();
    
    if ($previousMonthTournaments > 0) {
        $stats['trends']['tournamentGrowth'] = round(
            (($currentMonthTournaments - $previousMonthTournaments) / $previousMonthTournaments) * 100, 
            1
        );
    }
    
    // Team growth
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM teams 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $currentMonthTeams = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM teams 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $previousMonthTeams = (int)$stmt->fetchColumn();
    
    if ($previousMonthTeams > 0) {
        $stats['trends']['teamGrowth'] = round(
            (($currentMonthTeams - $previousMonthTeams) / $previousMonthTeams) * 100, 
            1
        );
    }
    
    // Registration growth
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM tournament_registrations 
        WHERE registration_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $currentMonthRegs = (int)$stmt->fetchColumn();
    
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM tournament_registrations 
        WHERE registration_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        AND registration_date < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $previousMonthRegs = (int)$stmt->fetchColumn();
    
    if ($previousMonthRegs > 0) {
        $stats['trends']['registrationGrowth'] = round(
            (($currentMonthRegs - $previousMonthRegs) / $previousMonthRegs) * 100, 
            1
        );
    }
    
    // Average tournament duration
    $stmt = $conn->query("
        SELECT AVG(DATEDIFF(end_date, start_date)) 
        FROM tournaments 
        WHERE status = 'completed'
    ");
    $avgDuration = $stmt->fetchColumn();
    $stats['trends']['avgTournamentDuration'] = $avgDuration ? round((float)$avgDuration, 1) : 0;
    
    // Tournaments by month (last 12 months)
    $stmt = $conn->query("
        SELECT 
            DATE_FORMAT(start_date, '%Y-%m') as month,
            COUNT(*) as count
        FROM tournaments
        WHERE start_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(start_date, '%Y-%m')
        ORDER BY month ASC
    ");
    $stats['trends']['tournamentsByMonth'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ============================================
    // FETCH 11: QUALITY METRICS
    // ============================================
    
    // Tournament completion rate
    $totalStarted = $stats['coreMetrics']['tournaments']['completed'] + 
                    $stats['coreMetrics']['tournaments']['live'];
    
    if ($totalStarted > 0) {
        $stats['quality']['tournamentCompletionRate'] = round(
            ($stats['coreMetrics']['tournaments']['completed'] / $totalStarted) * 100, 
            1
        );
    }
    
    // Tournament cancellation rate
    if ($stats['coreMetrics']['tournaments']['total'] > 0) {
        $stats['quality']['tournamentCancellationRate'] = round(
            ($stats['coreMetrics']['tournaments']['cancelled'] / $stats['coreMetrics']['tournaments']['total']) * 100, 
            1
        );
    }
    
    // Match completion rate
    $totalMatches = $stats['matches']['completed'] + $stats['matches']['live'] + $stats['matches']['scheduled'];
    if ($totalMatches > 0) {
        $stats['quality']['matchCompletionRate'] = round(
            ($stats['matches']['completed'] / $totalMatches) * 100, 
            1
        );
    }
    
    // Average participants per tournament
    $stmt = $conn->query("
        SELECT AVG(participant_count) as avg_participants
        FROM (
            SELECT tournament_id, COUNT(*) as participant_count
            FROM tournament_registrations
            WHERE status = 'accepted'
            GROUP BY tournament_id
        ) as counts
    ");
    $avgPart = $stmt->fetchColumn();
    $stats['quality']['avgParticipantsPerTournament'] = $avgPart ? round((float)$avgPart, 1) : 0;
    
    // Tournament fill rate
    $stmt = $conn->query("
        SELECT 
            AVG((participant_count / max_participants) * 100) as fill_rate
        FROM (
            SELECT 
                t.id,
                t.max_participants,
                COUNT(tr.id) as participant_count
            FROM tournaments t
            LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id AND tr.status = 'accepted'
            WHERE t.max_participants > 0
            GROUP BY t.id, t.max_participants
        ) as fill_data
    ");
    $fillRate = $stmt->fetchColumn();
    $stats['quality']['tournamentFillRate'] = $fillRate ? round((float)$fillRate, 1) : 0;
    
    // Teams with full roster (assuming min 5 members)
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM (
            SELECT team_id, COUNT(*) as member_count
            FROM team_members
            GROUP BY team_id
            HAVING member_count >= 5
        ) as full_teams
    ");
    $stats['quality']['teamsWithFullRoster'] = (int)$stmt->fetchColumn();
    
    // Active team percentage
    if ($stats['coreMetrics']['teams']['total'] > 0) {
        $stats['quality']['activeTeamPercentage'] = round(
            ($stats['coreMetrics']['teams']['active'] / $stats['coreMetrics']['teams']['total']) * 100, 
            1
        );
    }
    
    // ============================================
    // FETCH 12: ADMINISTRATIVE INSIGHTS
    // ============================================
    
    // Total admins
    $stmt = $conn->query("SELECT COUNT(*) FROM admin");
    $stats['admin']['totalAdmins'] = (int)$stmt->fetchColumn();
    
    // Tournaments created (last 30 days)
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM tournaments 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats['admin']['recentActions']['tournamentsCreated'] = (int)$stmt->fetchColumn();
    
    // Tournament resets (last 30 days)
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM activity_log 
        WHERE action = 'Tournament Reset'
        AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats['admin']['recentActions']['tournamentResets'] = (int)$stmt->fetchColumn();
    
    // Failed login attempts
    $stmt = $conn->query("SELECT COUNT(*) FROM login_attempts WHERE attempts > 0");
    $stats['admin']['systemHealth']['failedLoginAttempts'] = (int)$stmt->fetchColumn();
    
    // Password reset requests
    $stmt = $conn->query("SELECT COUNT(*) FROM password_reset_tokens");
    $stats['admin']['systemHealth']['passwordResetRequests'] = (int)$stmt->fetchColumn();
    
    // Pending verifications
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE is_verified = 0 
        AND verification_token IS NOT NULL
    ");
    $stats['admin']['systemHealth']['pendingVerifications'] = (int)$stmt->fetchColumn();
    
    // Total events
    $stmt = $conn->query("SELECT COUNT(*) FROM events");
    $stats['admin']['content']['totalEvents'] = (int)$stmt->fetchColumn();
    
    // Active live streams
    $stmt = $conn->query("SELECT COUNT(*) FROM live_streams WHERE is_active = 1");
    $stats['admin']['content']['activeLiveStreams'] = (int)$stmt->fetchColumn();
    
    // Unread notifications
    $stmt = $conn->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
    $stats['admin']['content']['unreadNotifications'] = (int)$stmt->fetchColumn();
    
    // ============================================
    // FETCH 13: TOURNAMENT TYPE DISTRIBUTION
    // ============================================
    
    // By bracket type
    $stmt = $conn->query("
        SELECT bracket_type, COUNT(*) as count 
        FROM tournaments 
        GROUP BY bracket_type
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $type = $row['bracket_type'];
        $count = (int)$row['count'];
        
        if ($type === 'Single Elimination') {
            $stats['tournamentTypes']['byBracketType']['singleElimination'] = $count;
        } elseif ($type === 'Double Elimination') {
            $stats['tournamentTypes']['byBracketType']['doubleElimination'] = $count;
        } elseif ($type === 'Round Robin') {
            $stats['tournamentTypes']['byBracketType']['roundRobin'] = $count;
        } elseif ($type === 'Battle Royale') {
            $stats['tournamentTypes']['byBracketType']['battleRoyale'] = $count;
        }
    }
    
    // By participation type
    $stmt = $conn->query("
        SELECT participation_type, COUNT(*) as count 
        FROM tournaments 
        GROUP BY participation_type
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $type = $row['participation_type'];
        $count = (int)$row['count'];
        $stats['tournamentTypes']['byParticipationType'][$type] = $count;
    }
    
    // Average prize by bracket type
    $stmt = $conn->query("
        SELECT 
            bracket_type, 
            AVG(prize_pool) as avg_prize,
            COUNT(*) as count
        FROM tournaments 
        WHERE prize_pool > 0
        GROUP BY bracket_type
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $type = $row['bracket_type'];
        $stats['tournamentTypes']['avgPrizeByType'][$type] = [
            'average' => round((float)$row['avg_prize'], 2),
            'count' => (int)$row['count']
        ];
    }
    
    // ============================================
    // FETCH 14: RECENT ACTIVITY FEED
    // ============================================
    
    $stmt = $conn->query("
        SELECT 
            al.id,
            al.user_id,
            al.action,
            al.details,
            al.timestamp,
            al.ip_address,
            u.username,
            u.avatar,
            u.email
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.timestamp DESC
        LIMIT 20
    ");
    
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($activities as $activity) {
        $stats['recentActivity'][] = [
            'id' => (int)$activity['id'],
            'userId' => $activity['user_id'] ? (int)$activity['user_id'] : null,
            'username' => $activity['username'],
            'avatar' => $activity['avatar'],
            'action' => $activity['action'],
            'details' => $activity['details'],
            'timestamp' => $activity['timestamp'],
            'ipAddress' => $activity['ip_address'],
        ];
    }
    
    // ============================================
    // FETCH 15: ALERTS & WARNINGS
    // ============================================
    
    // Critical Alerts
    
    // Tournaments needing attention (ongoing but no recent matches)
    $stmt = $conn->query("
        SELECT 
            t.id,
            t.name,
            t.slug,
            t.start_date
        FROM tournaments t
        WHERE t.status = 'ongoing'
        AND NOT EXISTS (
            SELECT 1 
            FROM matches m 
            WHERE m.tournament_id = t.id 
            AND m.start_time >= DATE_SUB(NOW(), INTERVAL 3 DAY)
        )
        LIMIT 5
    ");
    $needAttention = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($needAttention)) {
        $stats['alerts']['critical'][] = [
            'type' => 'tournaments_need_attention',
            'severity' => 'critical',
            'message' => count($needAttention) . ' ongoing tournaments have no recent matches',
            'count' => count($needAttention),
            'data' => $needAttention
        ];
    }
    
    // Pending approvals (combined)
    $totalPending = $stats['engagement']['registrations']['pending'] + 
                    $stats['engagement']['teamRequests']['pending'];
    if ($totalPending > 0) {
        $stats['alerts']['warning'][] = [
            'type' => 'pending_approvals',
            'severity' => 'warning',
            'message' => $totalPending . ' items pending approval',
            'count' => $totalPending,
            'data' => [
                'registrations' => $stats['engagement']['registrations']['pending'],
                'teamRequests' => $stats['engagement']['teamRequests']['pending']
            ]
        ];
    }
    
    // Unverified old accounts (created > 7 days ago, still unverified)
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM users 
        WHERE is_verified = 0 
        AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $oldUnverified = (int)$stmt->fetchColumn();
    if ($oldUnverified > 0) {
        $stats['alerts']['warning'][] = [
            'type' => 'old_unverified_accounts',
            'severity' => 'warning',
            'message' => $oldUnverified . ' accounts created over 7 days ago remain unverified',
            'count' => $oldUnverified
        ];
    }
    
    // Abandoned teams (teams with 0 members)
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM teams t
        WHERE NOT EXISTS (
            SELECT 1 
            FROM team_members tm 
            WHERE tm.team_id = t.id
        )
    ");
    $abandonedTeams = (int)$stmt->fetchColumn();
    if ($abandonedTeams > 0) {
        $stats['alerts']['info'][] = [
            'type' => 'abandoned_teams',
            'severity' => 'info',
            'message' => $abandonedTeams . ' teams have no members',
            'count' => $abandonedTeams
        ];
    }
    
    // Upcoming deadlines (tournaments starting < 24 hours)
    $stmt = $conn->query("
        SELECT 
            id,
            name,
            slug,
            start_date,
            max_participants,
            (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = tournaments.id AND status = 'accepted') as current_participants
        FROM tournaments
        WHERE start_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
        AND status IN ('registration_open', 'registration_closed')
        ORDER BY start_date ASC
    ");
    $upcomingDeadlines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($upcomingDeadlines)) {
        $stats['alerts']['info'][] = [
            'type' => 'upcoming_deadlines',
            'severity' => 'info',
            'message' => count($upcomingDeadlines) . ' tournaments starting in less than 24 hours',
            'count' => count($upcomingDeadlines),
            'data' => $upcomingDeadlines
        ];
    }
    
    // Low fill rate tournaments (< 50% filled, starting soon)
    $stmt = $conn->query("
        SELECT 
            t.id,
            t.name,
            t.slug,
            t.start_date,
            t.max_participants,
            COUNT(tr.id) as current_participants,
            ROUND((COUNT(tr.id) / t.max_participants) * 100, 1) as fill_rate
        FROM tournaments t
        LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id AND tr.status = 'accepted'
        WHERE t.start_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
        AND t.status = 'registration_open'
        AND t.max_participants > 0
        GROUP BY t.id, t.name, t.slug, t.start_date, t.max_participants
        HAVING fill_rate < 50
        ORDER BY t.start_date ASC
        LIMIT 5
    ");
    $lowFillRate = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($lowFillRate)) {
        $stats['alerts']['warning'][] = [
            'type' => 'low_fill_rate',
            'severity' => 'warning',
            'message' => count($lowFillRate) . ' tournaments starting soon with low registration',
            'count' => count($lowFillRate),
            'data' => $lowFillRate
        ];
    }
    
    // Failed login attempts spike
    if ($stats['admin']['systemHealth']['failedLoginAttempts'] > 10) {
        $stats['alerts']['critical'][] = [
            'type' => 'failed_login_spike',
            'severity' => 'critical',
            'message' => 'High number of failed login attempts detected',
            'count' => $stats['admin']['systemHealth']['failedLoginAttempts']
        ];
    }
    
    // ============================================
    // ADDITIONAL COMPUTED METRICS
    // ============================================
    
    // Return player rate (players who participated in 2+ tournaments)
    $stmt = $conn->query("
        SELECT COUNT(*) as return_players
        FROM (
            SELECT user_id, COUNT(DISTINCT tournament_id) as tournament_count
            FROM tournament_registrations
            WHERE user_id IS NOT NULL
            AND status = 'accepted'
            GROUP BY user_id
            HAVING tournament_count >= 2
        ) as returners
    ");
    $returnPlayers = $stmt->fetchColumn();
    $stats['quality']['returnPlayerCount'] = $returnPlayers ? (int)$returnPlayers : 0;
    
    $totalPlayers = $stats['coreMetrics']['users']['total'];
    if ($totalPlayers > 0) {
        $stats['quality']['returnPlayerRate'] = round(($stats['quality']['returnPlayerCount'] / $totalPlayers) * 100, 1);
    } else {
        $stats['quality']['returnPlayerRate'] = 0;
    }
    
    // Average time to fill tournament (registration_open to filled)
    $stmt = $conn->query("
        SELECT 
            AVG(TIMESTAMPDIFF(HOUR, t.registration_start, first_full.fill_time)) as avg_hours
        FROM tournaments t
        INNER JOIN (
            SELECT 
                tournament_id,
                MIN(registration_date) as fill_time
            FROM (
                SELECT 
                    tr.tournament_id,
                    tr.registration_date,
                    COUNT(*) OVER (PARTITION BY tr.tournament_id ORDER BY tr.registration_date) as running_count,
                    t.max_participants
                FROM tournament_registrations tr
                JOIN tournaments t ON tr.tournament_id = t.id
                WHERE tr.status = 'accepted'
            ) as counts
            WHERE running_count >= max_participants
            GROUP BY tournament_id
        ) as first_full ON t.id = first_full.tournament_id
        WHERE t.registration_start IS NOT NULL
    ");
    $avgFillTime = $stmt->fetchColumn();
    $stats['trends']['avgTimeToFillTournament'] = $avgFillTime ? round((float)$avgFillTime, 1) : null;
    
    // Peak registration days
    $stmt = $conn->query("
        SELECT 
            DAYNAME(registration_date) as day_name,
            COUNT(*) as count
        FROM tournament_registrations
        GROUP BY DAYNAME(registration_date), DAYOFWEEK(registration_date)
        ORDER BY count DESC
        LIMIT 3
    ");
    $stats['trends']['peakRegistrationDays'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tournaments starting soon (next 7 days)
    $stmt = $conn->query("
        SELECT COUNT(*) 
        FROM tournaments 
        WHERE start_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
        AND status IN ('registration_open', 'registration_closed', 'draft')
    ");
    $stats['coreMetrics']['tournaments']['startingSoon'] = (int)$stmt->fetchColumn();
    
    // Popular time slots for matches
    $stmt = $conn->query("
        SELECT 
            HOUR(start_time) as hour,
            COUNT(*) as count
        FROM matches
        WHERE start_time IS NOT NULL
        GROUP BY HOUR(start_time)
        ORDER BY count DESC
        LIMIT 5
    ");
    $stats['trends']['popularMatchHours'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Team performance metrics
    $stmt = $conn->query("
        SELECT 
            AVG(wins) as avg_wins,
            AVG(losses) as avg_losses,
            AVG(draws) as avg_draws,
            AVG(win_rate) as avg_win_rate
        FROM teams
        WHERE wins + losses + draws > 0
    ");
    $teamPerformance = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($teamPerformance) {
        $stats['quality']['teamPerformance'] = [
            'avgWins' => round((float)$teamPerformance['avg_wins'], 1),
            'avgLosses' => round((float)$teamPerformance['avg_losses'], 1),
            'avgDraws' => round((float)$teamPerformance['avg_draws'], 1),
            'avgWinRate' => round((float)$teamPerformance['avg_win_rate'], 1),
        ];
    }
    
    // Most active tournament organizers
    $stmt = $conn->query("
        SELECT 
            created_by,
            COUNT(*) as tournament_count
        FROM tournaments
        WHERE created_by IS NOT NULL
        GROUP BY created_by
        ORDER BY tournament_count DESC
        LIMIT 5
    ");
    $stats['admin']['topOrganizers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Registration velocity (registrations per day, last 7 days)
    $stmt = $conn->query("
        SELECT 
            DATE(registration_date) as reg_date,
            COUNT(*) as count
        FROM tournament_registrations
        WHERE registration_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(registration_date)
        ORDER BY reg_date DESC
    ");
    $stats['trends']['registrationVelocity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tournament capacity utilization
    $stmt = $conn->query("
        SELECT 
            CASE 
                WHEN fill_rate >= 90 THEN '90-100%'
                WHEN fill_rate >= 75 THEN '75-89%'
                WHEN fill_rate >= 50 THEN '50-74%'
                WHEN fill_rate >= 25 THEN '25-49%'
                ELSE '0-24%'
            END as capacity_range,
            COUNT(*) as tournament_count
        FROM (
            SELECT 
                t.id,
                ROUND((COUNT(tr.id) / t.max_participants) * 100, 1) as fill_rate
            FROM tournaments t
            LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id AND tr.status = 'accepted'
            WHERE t.max_participants > 0
            AND t.status IN ('ongoing', 'completed', 'registration_closed')
            GROUP BY t.id, t.max_participants
        ) as fill_data
        GROUP BY capacity_range
        ORDER BY capacity_range DESC
    ");
    $stats['quality']['capacityUtilization'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Summary counts for quick reference
    $stats['summary'] = [
        'totalUsers' => $stats['coreMetrics']['users']['total'],
        'totalTournaments' => $stats['coreMetrics']['tournaments']['total'],
        'totalTeams' => $stats['coreMetrics']['teams']['total'],
        'totalMatches' => $stats['matches']['total'],
        'totalPrizePool' => $stats['coreMetrics']['financial']['totalPrizePoolDistributed'] + 
                           $stats['coreMetrics']['financial']['activePrizePool'],
        'activeUsers' => $stats['coreMetrics']['users']['active'],
        'activeTournaments' => $stats['coreMetrics']['tournaments']['live'],
        'activeTeams' => $stats['coreMetrics']['teams']['active'],
        'pendingItems' => $totalPending,
        'criticalAlerts' => count($stats['alerts']['critical']),
        'warnings' => count($stats['alerts']['warning']),
    ];
    
    // Return success response with comprehensive stats
    echo json_encode([
        'success' => true,
        'data' => $stats,
        'timestamp' => date('Y-m-d H:i:s'),
        'generatedIn' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
    ]);
    
} catch (PDOException $e) {
    error_log('Dashboard stats PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('Dashboard stats general error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error occurred',
        'message' => $e->getMessage()
    ]);
}