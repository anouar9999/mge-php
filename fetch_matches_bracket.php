<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if (!file_exists('db_config.php')) {
        throw new Exception('Database configuration file not found');
    }
    $db_config = require 'db_config.php';

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $data = json_decode(file_get_contents('php://input'));
    if (!$data || !isset($data->tournament_id)) {
        throw new Exception('Tournament ID is required');
    }

    // Get tournament details
    $tournament = getTournamentDetails($pdo, $data->tournament_id);
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }

    // Calculate rounds - FIXED: use max_participants instead of nombre_maximum
    $participantCount = $tournament['max_participants'];
    $winnerRounds = ceil(log($participantCount, 2));
    // FIXED: use bracket_type instead of format_des_qualifications
    $loserRounds = $tournament['bracket_type'] === 'Double Elimination' ? 
                   ($winnerRounds - 1) * 2 : 0;

    // Get formatted matches
    $matches = getFormattedMatches($pdo, $data->tournament_id);

    $response = [
        'success' => true,
        'data' => [
            'tournament' => $tournament,
            'matches' => $matches,
            'format' => $tournament['bracket_type'], // FIXED
            'is_team_tournament' => $tournament['participation_type'] === 'team',
            'total_rounds' => $winnerRounds,
            'loser_rounds' => $loserRounds
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function getTournamentDetails($pdo, $tournamentId) {
    $query = "
        SELECT 
            t.*,
            COUNT(DISTINCT CASE WHEN tr.status = 'accepted' THEN tr.id END) as participant_count
        FROM tournaments t
        LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id
        WHERE t.id = ?
        GROUP BY t.id
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$tournamentId]);
    return $stmt->fetch();
}

function getFormattedMatches($pdo, $tournamentId) {
    // First get matches with proper ordering
    $matchQuery = "
        SELECT 
            m.*,
            CAST(tournament_round_text AS UNSIGNED) as round_number
        FROM matches m
        WHERE m.tournament_id = ? 
        ORDER BY 
            FIELD(m.bracket_type, 'winners', 'losers', 'grand_finals'),
            CAST(tournament_round_text AS UNSIGNED),
            m.position
    ";
    
    $stmt = $pdo->prepare($matchQuery);
    $stmt->execute([$tournamentId]);
    $matches = $stmt->fetchAll();

    if (empty($matches)) {
        return [];
    }

    // Get participants for all matches
    $matchIds = array_column($matches, 'id');
    $participants = getMatchParticipants($pdo, $matchIds);
    
    // Group participants by match
    $participantsByMatch = [];
    foreach ($participants as $participant) {
        $matchId = $participant['match_id'];
        $participantsByMatch[$matchId][] = $participant;
    }

    // Format matches
    $formattedMatches = [];
    foreach ($matches as $match) {
        $matchParticipants = $participantsByMatch[$match['id']] ?? [];
        usort($matchParticipants, function($a, $b) {
            return $a['id'] - $b['id'];
        });

        $formattedMatch = [
            'id' => $match['id'],
            'round' => $match['round_number'],
            'start_time' => $match['start_time'],
            'status' => $match['state'],
            'position' => $match['position'],
            'bracket_type' => $match['bracket_type'],
            'score1' => $match['score1'],
            'score2' => $match['score2'],
            'nextMatchId' => $match['next_match_id'],
            'loserMatchId' => $match['loser_goes_to'],
            'teams' => [
                formatTeam($matchParticipants[0] ?? null, $match['score1']),
                formatTeam($matchParticipants[1] ?? null, $match['score2'])
            ]
        ];

        $formattedMatches[] = $formattedMatch;
    }

    return $formattedMatches;
}

function getMatchParticipants($pdo, $matchIds) {
    if (empty($matchIds)) {
        return [];
    }

    $placeholders = str_repeat('?,', count($matchIds) - 1) . '?';
    $query = "
        SELECT 
            mp.*,
            CASE 
                WHEN mp.participant_id LIKE 'team_%' THEN t.name
                ELSE u.username
            END as participant_name,
            CASE 
                WHEN mp.participant_id LIKE 'team_%' THEN t.logo
                ELSE u.avatar
            END as avatar_url
        FROM match_participants mp
        LEFT JOIN users u ON mp.participant_id = CONCAT('player_', u.id)
        LEFT JOIN teams t ON mp.participant_id = CONCAT('team_', t.id)
        WHERE mp.match_id IN ($placeholders)
        ORDER BY mp.match_id, mp.id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($matchIds);
    return $stmt->fetchAll();
}

function formatTeam($participant, $score) {
    if (!$participant) {
        return [
            'id' => null,
            'name' => 'TBD',
            'score' => 0,
            'winner' => false,
            'avatar' => null,
            'status' => 'NOT_PLAYED'
        ];
    }

    return [
        'id' => $participant['participant_id'],
        'name' => $participant['participant_name'] ?? 'TBD',
        'score' => intval($score ?? $participant['result_text'] ?? 0),
        'winner' => (bool)($participant['is_winner'] ?? false),
        'avatar' => $participant['avatar_url'],
        'status' => $participant['status']
    ];
}
