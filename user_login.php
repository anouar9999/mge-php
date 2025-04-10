<?php
// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

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

    // Check login attempts first
    $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE username = :email");
    $stmt->execute([':email' => $email]);
    $loginAttempt = $stmt->fetch(PDO::FETCH_ASSOC);

    // If there are too many attempts (more than 5 in the last hour)
    if ($loginAttempt && $loginAttempt['attempts'] >= 5) {
        $lastAttempt = new DateTime($loginAttempt['last_attempt']);
        $now = new DateTime();
        $diff = $now->diff($lastAttempt);
        
        // If last attempt was less than 1 hour ago
        if ($diff->h < 1) {
            throw new Exception('Too many failed login attempts. Please try again later.');
        } else {
            // Reset attempts after 1 hour
            $resetStmt = $pdo->prepare("UPDATE login_attempts SET attempts = 1, last_attempt = NOW() WHERE username = :email");
            $resetStmt->execute([':email' => $email]);
        }
    }

    // Get user data
    $stmt = $pdo->prepare("SELECT id, username, email, password, type, avatar, bio, points, is_verified FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Record login attempt for non-existent user
        recordLoginAttempt($pdo, $email);
        throw new Exception('Invalid email or password');
    }

    if (!password_verify($password, $user['password'])) {
        // Record login attempt for failed password
        recordLoginAttempt($pdo, $email);
        
        // Update user's failed attempts
        $updateStmt = $pdo->prepare("UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = :id");
        $updateStmt->execute([':id' => $user['id']]);
        
        throw new Exception('Invalid email or password');
    }

    if (!$user['is_verified']) {
        throw new Exception('Account not verified. Please check your email for verification instructions.');
    }

    // Reset failed attempts on successful login
    $updateStmt = $pdo->prepare("UPDATE users SET failed_attempts = 0 WHERE id = :id");
    $updateStmt->execute([':id' => $user['id']]);

    // Clear login attempts record on successful login
    $clearStmt = $pdo->prepare("DELETE FROM login_attempts WHERE username = :email");
    $clearStmt->execute([':email' => $email]);

    // Generate a session token (a more secure method for production)
    $session_token = bin2hex(random_bytes(32));
    
    // Store remember token in database
    $expiry = (new DateTime())->modify('+30 days');
    $tokenStmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, token, expires) VALUES (:user_id, :token, :expires)");
    $tokenStmt->execute([
        ':user_id' => $user['id'],
        ':token' => $session_token,
        ':expires' => $expiry->format('Y-m-d H:i:s')
    ]);

    // Log the successful login in activity_log table - FIXED to not use ID
    $logStmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, ip_address) VALUES (:user_id, :action, :ip_address)");
    $logStmt->execute([
        ':user_id' => $user['id'],
        ':action' => 'Connexion réussie',
        ':ip_address' => $_SERVER['REMOTE_ADDR']
    ]);

    echo json_encode([
        'success' => true, 
        'message' => 'Login successful',
        'user_id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'avatar' => $user['avatar'],
        'bio' => $user['bio'],
        'points' => $user['points'],
        'user_type' => $user['type'],
        'session_token' => $session_token
    ]);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => $e->getMessage() . (isset($pdo) ? ' - SQL Error: ' . implode(', ', $pdo->errorInfo()) : '')]);
}

/**
 * Record a failed login attempt
 */
function recordLoginAttempt($pdo, $username) {
    $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($attempt) {
        // Increment existing record
        $updateStmt = $pdo->prepare("UPDATE login_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE username = :username");
        $updateStmt->execute([':username' => $username]);
    } else {
        // Create new record
        $insertStmt = $pdo->prepare("INSERT INTO login_attempts (username, attempts, last_attempt) VALUES (:username, 1, NOW())");
        $insertStmt->execute([':username' => $username]);
    }
}
?>