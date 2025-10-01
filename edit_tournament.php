<?php
// Disable error display in output
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Enable error logging to file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Set headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
header('Content-Type: application/json');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Ensure we only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Include database configuration
    $db_config = require __DIR__ . '/db_config.php';

    // Connect to database
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get and decode JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    // Log received data for debugging
    error_log("Received data: " . print_r($data, true));

    // Validate tournament ID
    if (!isset($data['id']) || empty($data['id'])) {
        throw new Exception('Tournament ID is required');
    }

    // Direct field mapping
    $columnMappings = [
        'name' => 'name',
        'description_des_qualifications' => 'description',
        'rules' => 'rules',
        'format_des_qualifications' => 'bracket_type',
        'nombre_maximum' => 'max_participants',
        'prize_pool' => 'prize_pool',
        'type_de_match' => 'match_format',
        'competition_type' => 'game_id',  // May need to convert string to ID
        'participation_type' => 'participation_type',
        'status' => 'status'
    ];

    // Build update query
    $updateFields = [];
    $params = [':id' => $data['id']];

    // Generate slug from name if name is present
    if (isset($data['name']) && !empty($data['name'])) {
        $slug = strtolower(
            trim(
                preg_replace('/[^a-zA-Z0-9]+/', '-', $data['name']),
                '-'
            )
        );
        $updateFields[] = "`slug` = :slug";
        $params[':slug'] = $slug;
    }

    // Process competition_type (game name) to get game_id
    if (isset($data['competition_type']) && !empty($data['competition_type'])) {
        // First, check if the value is numeric (already a game_id)
        if (is_numeric($data['competition_type'])) {
            $updateFields[] = "`game_id` = :game_id";
            $params[':game_id'] = $data['competition_type'];
        } else {
            // It's a game name, lookup the ID
            $gameStmt = $pdo->prepare("SELECT id FROM games WHERE name = :game_name");
            $gameStmt->execute([':game_name' => $data['competition_type']]);
            $game = $gameStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($game) {
                $updateFields[] = "`game_id` = :game_id";
                $params[':game_id'] = $game['id'];
            } else {
                // Log that we couldn't find the game
                error_log("Could not find game ID for: " . $data['competition_type']);
            }
        }
    }

    // Process dates
    if (isset($data['start_date']) && !empty($data['start_date'])) {
        $updateFields[] = "`start_date` = :start_date";
        $params[':start_date'] = $data['start_date'];
    }
    
    if (isset($data['end_date']) && !empty($data['end_date'])) {
        $updateFields[] = "`end_date` = :end_date";
        $params[':end_date'] = $data['end_date'];
    }

    // Process other fields using mapping
    foreach ($columnMappings as $formField => $dbField) {
        // Skip fields that are handled separately
        if (in_array($formField, ['competition_type'])) {
            continue;
        }
        
        if (isset($data[$formField]) && $data[$formField] !== '') {
            $updateFields[] = "`$dbField` = :$dbField";
            $params[":$dbField"] = $data[$formField];
        }
    }

    // Add updated_at timestamp
    $updateFields[] = "`updated_at` = NOW()";

    if (empty($updateFields)) {
        throw new Exception('No fields to update');
    }

    // Execute update query
    $sql = "UPDATE tournaments SET " . implode(', ', $updateFields) . " WHERE id = :id";
    error_log("SQL Query: " . $sql);
    error_log("Params: " . print_r($params, true));
    
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute($params);

    if (!$success) {
        throw new Exception('Failed to update tournament: ' . implode(', ', $stmt->errorInfo()));
    }

    // Get updated tournament data
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = :id");
    $stmt->execute([':id' => $data['id']]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Tournament updated successfully',
        'data' => [
            'id' => $tournament['id'],
            'slug' => $tournament['slug'],
            'name' => $tournament['name']
        ]
    ]);

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>