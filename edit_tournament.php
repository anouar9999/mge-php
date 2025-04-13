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

    // Generate slug if title is present
    if (isset($data['nom_des_qualifications'])) {
        $data['slug'] = strtolower(
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
    
    $allowedFields = [
        'nom_des_qualifications',
        'slug',
        'start_date',
        'end_date',
        'status',
        'description_des_qualifications',
        'nombre_maximum',
        'prize_pool',
        'format_des_qualifications',
        'type_de_match',
        'type_de_jeu',
        'image',
        'rules'
    ];

    foreach ($allowedFields as $field) {
        if (isset($data[$field]) && $data[$field] !== '') {
            $updateFields[] = "`$field` = :$field";
            $params[":$field"] = $data[$field];
        }
    }

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

    echo json_encode([
        'success' => true,
        'message' => 'Tournament updated successfully',
        'data' => [
            'id' => $data['id'],
            'slug' => $data['slug'] ?? null
        ]
    ]);

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
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