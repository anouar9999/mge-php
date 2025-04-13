<?php
// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['registration_id']) || !isset($data['status']) || !isset($data['admin_id'])) {
        throw new Exception('Missing required parameters');
    }

    $registration_id = $data['registration_id'];
    $status = $data['status'];
    $admin_id = $data['admin_id'];

    $stmt = $pdo->prepare("UPDATE tournament_registrations SET status = :status, admin_id = :admin_id WHERE id = :registration_id");
    $stmt->execute([
        ':status' => $status,
        ':admin_id' => $admin_id,
        ':registration_id' => $registration_id
    ]);

    echo json_encode(['success' => true, 'message' => 'Registration status updated successfully']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>