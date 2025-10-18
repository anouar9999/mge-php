<?php

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

    if (!isset($data['email']) || !isset($data['password'])) {
        throw new Exception('Missing email or password');
    }

    $email = $data['email'];
    $password = $data['password'];
    
    // Check if remember_me was passed
    $remember_me = isset($data['remember_me']) ? $data['remember_me'] : false;

    // Query using email instead of username
    $stmt = $pdo->prepare("SELECT id, username, email, password FROM admin WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        throw new Exception('Invalid email or password');
    }

    if (!password_verify($password, $admin['password'])) {
        throw new Exception('Invalid email or password');
    }

    // Generate a session token
    $session_token = bin2hex(random_bytes(32));

    echo json_encode([
        'success' => true, 
        'message' => 'Login successful',
        'admin_id' => $admin['id'],
        'username' => $admin['username'],
        'email' => $admin['email'],
        'session_token' => $session_token
    ]);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>