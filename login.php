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

    if (!isset($data['username']) || !isset($data['password'])) {
        throw new Exception('Missing username or password');
    }

    $username = $data['username'];
    $password = $data['password'];

    $stmt = $pdo->prepare("SELECT id, username, password FROM admin WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        throw new Exception('Invalid username or password');
    }

    // Check if the password in the database is already hashed
    if (password_get_info($admin['password'])['algoName'] !== 'unknown') {
        // The password is hashed, use password_verify
        if (!password_verify($password, $admin['password'])) {
            throw new Exception('Invalid username or password');
        }
    } else {
        // The password is not hashed (plain text), compare directly
        // WARNING: This is for testing only and should be removed in production
        if ($password !== $admin['password']) {
            throw new Exception('Invalid username or password');
        }
        
        // Optionally, you can hash the password and update it in the database
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare("UPDATE admin SET password = :password WHERE id = :id");
        $updateStmt->execute([':password' => $hashedPassword, ':id' => $admin['id']]);
    }

    // Generate a session token (you may want to use a more secure method in production)
    $session_token = bin2hex(random_bytes(32));

    echo json_encode([
        'success' => true, 
        'message' => 'Login successful',
        'admin_id' => $admin['id'],
        'username' => $admin['username'],
        'session_token' => $session_token
    ]);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>