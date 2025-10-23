<?php
// api/auth/me.php - Complete CORS and Session Fix
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// 🔥 CORS Configuration - Allow all necessary origins
// ============================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'https://gamius.ma',
    'https://www.gamius.ma',
    'https://user.gamius.ma',
    'https://api.gamius.ma'
];

// Allow any origin that matches
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Fallback - allow gamius.ma
    header("Access-Control-Allow-Origin: https://gamius.ma");
}

// Critical CORS headers
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
header('Access-Control-Max-Age: 3600');
header('Vary: Origin');

// Handle preflight OPTIONS request immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header('Content-Type: application/json');

// ============================================
// Session Configuration for Cross-Subdomain
// ============================================
// MUST be set before session_start()
ini_set('session.cookie_domain', '.gamius.ma');
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');

session_set_cookie_params([
    'lifetime' => 86400,           // 24 hours
    'path' => '/',
    'domain' => '.gamius.ma',       // Leading dot allows all subdomains
    'secure' => true,              // HTTPS only
    'httponly' => true,            // No JavaScript access
    'samesite' => 'None'           // Required for cross-site cookies
]);

try {
    // Only allow GET requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
        exit();
    }

    // Start session
    session_start();

    // Load database config (optional - only if you need to fetch fresh user data)
    $config_path = __DIR__ . '/../db_config.php';
    $db_config = null;
    
    if (file_exists($config_path)) {
        $db_config = require $config_path;
    }

    // Check if user is logged in via session
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_data'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Not authenticated',
            'debug' => [
                'session_started' => session_status() === PHP_SESSION_ACTIVE,
                'session_id' => session_id(),
                'user_id_exists' => isset($_SESSION['user_id']),
                'user_data_exists' => isset($_SESSION['user_data']),
                'origin' => $origin,
                'cookie_domain' => ini_get('session.cookie_domain'),
                'cookies_received' => isset($_COOKIE[session_name()]) ? 'yes' : 'no',
                'session_name' => session_name()
            ]
        ]);
        exit();
    }

    // Check session expiry
    $sessionLifetime = 24 * 60 * 60; // 24 hours
    if (isset($_SESSION['login_time'])) {
        $elapsed = time() - $_SESSION['login_time'];
        if ($elapsed > $sessionLifetime) {
            session_destroy();
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Session expired'
            ]);
            exit();
        }
    }

    // User is authenticated - return user data
    echo json_encode([
        'success' => true,
        'authenticated' => true,
        'user' => [
            'id' => $_SESSION['user_data']['id'] ?? $_SESSION['user_id'],
            'username' => $_SESSION['user_data']['username'] ?? '',
            'email' => $_SESSION['user_data']['email'] ?? '',
            'user_type' => $_SESSION['user_data']['user_type'] ?? '',
            'avatar' => $_SESSION['user_data']['avatar'] ?? '',
            'bio' => $_SESSION['user_data']['bio'] ?? '',
            'points' => $_SESSION['user_data']['points'] ?? 0
        ],
        'session_time_remaining' => $sessionLifetime - (time() - ($_SESSION['login_time'] ?? time())),
        'debug' => [
            'session_active' => true,
            'user_id' => $_SESSION['user_id'] ?? null,
            'origin_received' => $origin,
            'cookie_domain' => ini_get('session.cookie_domain')
        ]
    ]);

} catch (Exception $e) {
    error_log("Auth check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'debug' => [
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => basename($e->getFile())
        ]
    ]);
}
?>
