<?php
/**
 * fetch_matches_bracket_COMPATIBLE.php
 * 
 * Works with standard database (no custom states needed)
 * 
 * Filters matches by:
 * - winner_id = 'EMPTY' → Hide (empty match)
 * - score = 0,0 and winner_id exists → Bye (optionally show/hide)
 * - winner_id = NULL and participants >= 2 → Show (needs score)
 * - winner_id exists and scores > 0 → Show (completed)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
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

    // Support GET and POST
    $tournamentId = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'));
        if ($data && isset($data->tournament_id)) {
            $tournamentId = $data->tournament_id;
        }
    } else {
        $tournamentId = $_GET['tournament_id'] ?? null;
    }

    if (!$tournamentId) {
        throw new Exception('Tournament ID is required');
    }

    $tournament = getTournamentDetails($pdo, $tournamentId);
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }

    $participantCount = $tournament['participant_count'] > 0 ? $tournament['participant_count'] : 2;
    $winnerRounds = ceil(log($participantCount, 2));

    // Get matches (filters out empty ones)
    $matches = getFormattedMatches($pdo, $tournamentId);

    $response = [
        'success' => true,
        'data' => [
            'matches' => $matches,
            'bracket_info' => [
                'format' => $tournament['bracket_type'],
                'is_team_tournament' => $tournament['participation_type'] === 'team',
                'total_rounds' => $winnerRounds,
                'loser_rounds' => 0
            ],
            'tournament' => $tournament,
            'format' => $tournament['bracket_type'],
            'is_team_tournament' => $tournament['participation_type'] === 'team',
            'total_rounds' => $winnerRounds,
            'loser_rounds' => 0
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}

function getTournamentDetails($pdo, $tournamentId) {
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            COUNT(DISTINCT CASE WHEN tr.status = 'accepted' THEN tr.id END) as participant_count
        FROM tournaments t
        LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id
        WHERE t.id = ?
        GROUP BY t.id
    ");
    $stmt->execute([$tournamentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getFormattedMatches($pdo, $tournamentId) {
    // FILTER: Exclude empty matches (winner_id = 'EMPTY' means no participants ever)
    $matchQuery = "
        SELECT 
            m.*,
            CAST(tournament_round_text AS UNSIGNED) as round_number
        FROM matches m
        WHERE m.tournament_id = ? 
        AND (m.winner_id IS NULL OR m.winner_id != 'EMPTY')
        ORDER BY 
            FIELD(m.bracket_type, 'winners', 'losers', 'grand_finals'),
            CAST(tournament_round_text AS UNSIGNED),
            m.position
    ";
    
    $stmt = $pdo->prepare($matchQuery);
    $stmt->execute([$tournamentId]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($matches)) {
        return [];
    }

    $matchIds = array_column($matches, 'id');
    $participants = getMatchParticipants($pdo, $matchIds);
    
    $participantsByMatch = [];
    foreach ($participants as $participant) {
        $participantsByMatch[$participant['match_id']][] = $participant;
    }

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
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        'name' => $participant['participant_name'] ?? $participant['name'] ?? 'TBD',
        'score' => intval($score ?? $participant['result_text'] ?? 0),
        'winner' => (bool)($participant['is_winner'] ?? false),
        'avatar' => $participant['avatar_url'],
        'status' => $participant['status']
    ];
}
