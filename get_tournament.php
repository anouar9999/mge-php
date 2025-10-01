    <?php
    // get_tournament.php

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

    // Only process GET requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
        exit();
    }

    // Include database configuration
    $db_config = require 'db_config.php';

    try {
        // Connect to the database
        $pdo = new PDO(
            "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
            $db_config['user'],
            $db_config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Get the tournament ID from the query string
        $tournament_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($tournament_id <= 0) {
            throw new Exception('Invalid tournament ID');
        }

        // Prepare and execute the query
        $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = :id");
        $stmt->execute([':id' => $tournament_id]);

        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tournament) {
            throw new Exception('Tournament not found');
        }

        // Return the tournament data as JSON
        echo json_encode([
            'success' => true,
            'data' => $tournament
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    ?>