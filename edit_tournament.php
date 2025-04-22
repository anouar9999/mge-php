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
        throw new Exception('Invalid JSON input');
    }

    // Validate tournament ID
    if (!isset($data['id'])) {
        throw new Exception('Tournament ID is required');
    }

    // Map form field names to database column names
    $fieldMapping = [
        'nom_des_qualifications' => 'name',
        'description_des_qualifications' => 'description',
        'rules' => 'rules',
        'format_des_qualifications' => 'bracket_type',
        'nombre_maximum' => 'max_participants',
        'prize_pool' => 'prize_pool',
        'type_de_match' => 'match_format',
        'type_de_jeu' => 'game_id',
        'image' => 'featured_image',
        'status' => 'status'
    ];

    // Status mapping if provided
    $statusMapping = [
        'Ouvert aux inscriptions' => 'registration_open',
        'En cours' => 'ongoing',
        'Terminé' => 'completed',
        'Annulé' => 'cancelled'
    ];

    // Generate slug if name is present
    if (isset($data['nom_des_qualifications'])) {
        $slug = strtolower(
            trim(
                preg_replace('/[^a-zA-Z0-9]+/', '-', 
                    $data['nom_des_qualifications']
                ),
                '-'
            )
        );
    }

    // Build update query
    $updateFields = [];
    $params = [':id' => $data['id']];
    
    // Handle slug separately
    if (isset($slug)) {
        $updateFields[] = "`slug` = :slug";
        $params[':slug'] = $slug;
    }
    
    // Handle status mapping
    if (isset($data['status']) && isset($statusMapping[$data['status']])) {
        $updateFields[] = "`status` = :status";
        $params[':status'] = $statusMapping[$data['status']];
    }

    // Process dates
    if (isset($data['start_date'])) {
        $updateFields[] = "`start_date` = :start_date";
        $params[':start_date'] = $data['start_date'];
    }
    
    if (isset($data['end_date'])) {
        $updateFields[] = "`end_date` = :end_date";
        $params[':end_date'] = $data['end_date'];
    }

    // Process other fields using mapping
    foreach ($fieldMapping as $formField => $dbField) {
        if (isset($data[$formField]) && $formField != 'status') { // Status handled separately
            $updateFields[] = "`$dbField` = :$dbField";
            $params[":$dbField"] = $data[$formField];
        }
    }

    // Handle bracket type conversion
    if (isset($data['format_des_qualifications'])) {
        $updateFields[] = "`bracket_type` = :bracket_type";
        $params[':bracket_type'] = $data['format_des_qualifications'];
    }

    // Add updated_at timestamp
    $updateFields[] = "`updated_at` = NOW()";

    if (empty($updateFields)) {
        throw new Exception('No fields to update');
    }

    // Execute update query
    $sql = "UPDATE tournaments SET " . implode(', ', $updateFields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute($params);

    if (!$success) {
        throw new Exception('Failed to update tournament');
    }

    // Get updated tournament data
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = :id");
    $stmt->execute([':id' => $data['id']]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Tournament updated successfully',
        'data' => [
            'id' => $data['id'],
            'slug' => $tournament['slug'] ?? null,
            'name' => $tournament['name'] ?? null
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