<?php
// UPDATED login.php - Modified your existing code to use sessions
// CORS headers - Updated to support credentials
$db_config = require 'db_config.php';

header("Access-Control-Allow-Origin: http://{$db_config['api']['host']}:3000"); // Specific origin for credentials
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true'); // IMPORTANT: Allow credentials

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

// START SESSION - IMPORTANT ADDITION
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
        // User not found
        throw new Exception('Invalid email or password');
    }

    if (!password_verify($password, $user['password'])) {
        // Wrong password
        throw new Exception('Invalid email or password');
    }

    if (!$user['is_verified']) {
        throw new Exception('Account not verified. Please check your email for verification instructions.');
    }

    // Reset failed attempts on successful login (if this field exists)
    try {
        $updateStmt = $pdo->prepare("UPDATE users SET failed_attempts = 0 WHERE id = :id");
        $updateStmt->execute([':id' => $user['id']]);
    } catch (Exception $e) {
        // If the column doesn't exist or there's another issue, just log it
        error_log("Failed to reset failed_attempts: " . $e->getMessage());
        // Continue with login process
    }

    // STORE USER DATA IN SESSION - NEW ADDITION
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

    // Generate a session token (keep your existing token system)
    $session_token = bin2hex(random_bytes(32));
    
    // Store remember token in database, including the id field
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
        // If there's an issue with remember_tokens, just log it
        error_log("Failed to store remember token: " . $e->getMessage());
        // Continue with login process, token will still be returned to client
    }

    // Log the successful login in activity_log table
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
        // If there's an issue with activity_log, just log it
        error_log("Failed to log activity: " . $e->getMessage());
        // Continue with login process
    }

    echo json_encode([
        'success' => true, 
        'message' => 'Login successful',
        'user' => $_SESSION['user_data'], // Return user data from session
        'session_token' => $session_token,
        'redirect_url' => "http://{$db_config['api']['host']}:5173/" // Tell frontend where to redirect
    ]);
} catch (Exception $e) {
    // Detailed error logging for debugging
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