<?php
// UPDATED login.php - Fixed CORS for production
$db_config = require 'db_config.php';

// Get the origin of the request
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Define allowed origins
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:5173',
    'https://user.gnews.ma',
    'https://api.gnews.ma'
];

// Check if the origin is allowed
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Fallback for development - remove in production
    header("Access-Control-Allow-Origin: https://user.gnews.ma");
}

header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

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

// START SESSION
session_start();

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

    // Get user data
    $stmt = $pdo->prepare("SELECT id, username, email, password, type, avatar, bio, points, is_verified FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('Invalid email or password');
    }

    if (!password_verify($password, $user['password'])) {
        throw new Exception('Invalid email or password');
    }

    if (!$user['is_verified']) {
        throw new Exception('Account not verified. Please check your email for verification instructions.');
    }

    // Reset failed attempts on successful login
    try {
        $updateStmt = $pdo->prepare("UPDATE users SET failed_attempts = 0 WHERE id = :id");
        $updateStmt->execute([':id' => $user['id']]);
    } catch (Exception $e) {
        error_log("Failed to reset failed_attempts: " . $e->getMessage());
    }

    // STORE USER DATA IN SESSION
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_data'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'avatar' => $user['avatar'],
        'bio' => $user['bio'],
        'points' => $user['points'],
        'user_type' => $user['type']
    ];
    $_SESSION['login_time'] = time();

    // Generate a session token
    $session_token = bin2hex(random_bytes(32));
    
    // Store remember token in database
    try {
        $expiry = (new DateTime())->modify('+30 days');
        $maxIdQuery = $pdo->query("SELECT MAX(id) as max_id FROM remember_tokens");
        $maxId = $maxIdQuery->fetch(PDO::FETCH_ASSOC)['max_id'];
        $newId = $maxId !== null ? $maxId + 1 : 1;
        
        $tokenStmt = $pdo->prepare("INSERT INTO remember_tokens (id, user_id, token, expires) VALUES (:id, :user_id, :token, :expires)");
        $tokenStmt->execute([
            ':id' => $newId,
            ':user_id' => $user['id'],
            ':token' => $session_token,
            ':expires' => $expiry->format('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        error_log("Failed to store remember token: " . $e->getMessage());
    }

    // Log the successful login
    try {
        $maxIdQuery = $pdo->query("SELECT MAX(id) as max_id FROM activity_log");
        $maxId = $maxIdQuery->fetch(PDO::FETCH_ASSOC)['max_id'];
        $newId = $maxId !== null ? $maxId + 1 : 1;
        
        $logStmt = $pdo->prepare("INSERT INTO activity_log (id, user_id, action, ip_address) VALUES (:id, :user_id, :action, :ip_address)");
        $logStmt->execute([
            ':id' => $newId,
            ':user_id' => $user['id'],
            ':action' => 'Connexion réussie',
            ':ip_address' => $_SERVER['REMOTE_ADDR']
        ]);
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }

    // Return response structure that matches frontend expectations
    echo json_encode([
        'success' => true, 
        'message' => 'Login successful',
        'username' => $user['username'],
        'user_id' => $user['id'], 
        'user_type' => $user['type'],
        'avatar' => $user['avatar'],
        'bio' => $user['bio'],
        'points' => $user['points'],
        'session_token' => $session_token,
        'redirect_url' => 'https://user.gnews.ma/'
    ]);

} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage() . 
              (isset($pdo) && $pdo->errorInfo()[0] !== '00000' ? 
              " - SQL Error: " . implode(', ', $pdo->errorInfo()) : ''));
              
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>
