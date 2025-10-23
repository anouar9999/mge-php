<?php
$db_config = require '../db_config.php'; // Adjust path as needed

// api/auth/logout.php - NEW FILE (Create this file)
$allowedOrigins = ["https://user.gamius.ma","https://gamius.ma", "http://{$db_config['api']['host']}:3000", "http://{$db_config['api']['host']}:5173"];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

session_start();



try {
    // Get user ID before destroying session (for logging and token cleanup)
    $userId = $_SESSION['user_id'] ?? null;

    // Connect to database for cleanup
    if ($userId) {
        $pdo = new PDO(
            "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
            $db_config['user'],
            $db_config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Optional: Delete remember tokens for this user (force logout on all devices)
        try {
            $deleteTokensStmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = :user_id");
            $deleteTokensStmt->execute([':user_id' => $userId]);
        } catch (Exception $e) {
            error_log("Failed to delete remember tokens on logout: " . $e->getMessage());
        }

        // Log the logout
        try {
            $maxIdQuery = $pdo->query("SELECT MAX(id) as max_id FROM activity_log");
            $maxId = $maxIdQuery->fetch(PDO::FETCH_ASSOC)['max_id'];
            $newId = $maxId !== null ? $maxId + 1 : 1;
            
            $logStmt = $pdo->prepare("INSERT INTO activity_log (id, user_id, action, ip_address) VALUES (:id, :user_id, :action, :ip_address)");
            $logStmt->execute([
                ':id' => $newId,
                ':user_id' => $userId,
                ':action' => 'Déconnexion',
                ':ip_address' => $_SERVER['REMOTE_ADDR']
            ]);
        } catch (Exception $e) {
            error_log("Failed to log logout activity: " . $e->getMessage());
        }
    }

    // Destroy the session
    $_SESSION = array();
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();

    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);

} catch (Exception $e) {
    error_log("Logout error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Logout failed'
    ]);
}
?>
