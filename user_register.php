<?php
/**
 * USER_REGISTER.PHP (or REGISTRATION.PHP) - Fixed CORS Implementation
 * CRITICAL: CORS headers MUST be sent FIRST before ANY other code
 */

// ============================================
// 🔥 STEP 1: SEND CORS HEADERS IMMEDIATELY
// ============================================
// This MUST happen before any other code, errors, or output

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'https://user.gnews.ma',
    'https://api.gnews.ma'
];

// Send CORS headers for allowed origins
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    // Fallback - send a default origin header
    header('Access-Control-Allow-Origin: https://user.gnews.ma');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    exit(0);
}

// ============================================
// STEP 2: Error Handling Configuration
// ============================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . 'error.log');

// ============================================
// STEP 3: Load Configuration
// ============================================
$db_config = require __DIR__ . 'db_config.php';

// ============================================
// STEP 4: Main Processing
// ============================================
try {
    // Database connection
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    error_log("Database connection established");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log("Processing registration request from origin: " . $origin);

        // Get and validate input
        $input = handleInput();
        validateInput($input);

        error_log("Input validated for user: " . $input['username']);

        // Handle avatar upload if present
        $avatarPath = null;
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $avatarPath = handleAvatarUpload($_FILES['avatar']);
            error_log("Avatar uploaded: " . $avatarPath);
        }

        // Check for existing user
        checkExistingUser($pdo, $input['email'], $input['username']);
        error_log("User uniqueness verified");

        // Create new user with verification token
        $userResult = createUser($pdo, $input, $avatarPath);
        error_log("User created successfully with ID: " . $userResult['user_id']);

        // Send verification email
        $emailResult = sendVerificationEmail(
            $input['email'],
            $input['username'],
            $userResult['verification_token']
        );

        if ($emailResult['success']) {
            error_log("Verification email sent successfully to: " . $input['email']);
        } else {
            error_log("Failed to send verification email: " . $emailResult['message']);
        }

        // Return success response
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful! Please check your email to verify your account.',
            'user_id' => $userResult['user_id'],
            'avatar' => $avatarPath,
            'email_sent' => $emailResult['success'],
            'requires_verification' => true
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
    } else {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed. Only POST requests are accepted.'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("PDO Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    handleError('Database error occurred. Please try again later.', 500);
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    handleError($e->getMessage(), 400);
}

exit(0);

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Generate secure verification token
 */
function generateVerificationToken()
{
    return bin2hex(random_bytes(32));
}

/**
 * Handle input from POST or JSON
 */
function handleInput()
{
    if (!empty($_POST)) {
        error_log("Processing form POST data");
        return $_POST;
    }

    $rawInput = file_get_contents('php://input');
    error_log("Raw input received: " . strlen($rawInput) . " bytes");

    if (empty($rawInput)) {
        throw new Exception('No input data received');
    }

    $jsonInput = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON format: ' . json_last_error_msg());
    }

    if ($jsonInput) {
        error_log("Processing JSON data");
        return $jsonInput;
    }

    throw new Exception('Invalid input format');
}

/**
 * Validate user input
 */
function validateInput($input)
{
    $required = ['email', 'password', 'username'];
    $missing = array_diff($required, array_keys($input));
    
    if (!empty($missing)) {
        throw new Exception('Missing required fields: ' . implode(', ', $missing));
    }

    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }

    if (strlen($input['password']) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }

    if (strlen($input['username']) < 3 || strlen($input['username']) > 30) {
        throw new Exception('Username must be between 3 and 30 characters');
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $input['username'])) {
        throw new Exception('Username can only contain letters, numbers, and underscores');
    }
}

/**
 * Handle avatar file upload
 */
function handleAvatarUpload($file)
{
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/avatars/';
    $webPath = '/uploads/avatars/';

    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($fileInfo, $file['tmp_name']);
    finfo_close($fileInfo);

    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File too large. Maximum size is 5MB.');
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('avatar_', true) . '.' . $extension;
    $fullPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new Exception('Failed to save avatar file');
    }

    chmod($fullPath, 0644);
    return $webPath . $filename;
}

/**
 * Check if user already exists
 */
function checkExistingUser($pdo, $email, $username)
{
    $stmt = $pdo->prepare('SELECT email, username FROM users WHERE email = ? OR username = ? LIMIT 1');
    $stmt->execute([$email, $username]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        if (strtolower($existingUser['email']) === strtolower($email)) {
            throw new Exception('Email address is already registered');
        }
        if (strtolower($existingUser['username']) === strtolower($username)) {
            throw new Exception('Username is already taken');
        }
    }
}

/**
 * Create new user with verification token
 */
function createUser($pdo, $input, $avatarPath)
{
    $verificationToken = generateVerificationToken();

    $stmt = $pdo->prepare('
        INSERT INTO users (
            username, email, password, avatar, bio, 
            type, user_type, points, rank, is_verified, 
            verification_token, failed_attempts, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');

    $result = $stmt->execute([
        $input['username'],
        $input['email'],
        password_hash($input['password'], PASSWORD_BCRYPT, ['cost' => 12]),
        $avatarPath,
        $input['bio'] ?? '',
        'participant',
        '',
        0,
        null,
        0,
        $verificationToken,
        0
    ]);

    if (!$result) {
        throw new Exception("Failed to create user account");
    }

    $userId = $pdo->lastInsertId();
    error_log("User created with ID: " . $userId);

    return [
        'success' => true,
        'user_id' => $userId,
        'verification_token' => $verificationToken
    ];
}

/**
 * Send verification email
 */
function sendVerificationEmail($userEmail, $username, $verificationToken)
{
    $db_config = require __DIR__ . '/db_config.php';
    
    $brevo_config = [
        'sender_email' => 'anouar.sabir@genius-morocco.com',
        'sender_name' => 'Genius Team',
        'Company' => 'Gamius'
    ];

    $verificationUrl = "https://{$db_config['api']['host']}/api/verify-email.php?token=" . urlencode($verificationToken);

    $data = [
        'sender' => [
            'name' => $brevo_config['sender_name'],
            'email' => $brevo_config['sender_email']
        ],
        'to' => [
            [
                'email' => $userEmail,
                'name' => $username
            ]
        ],
        'subject' => 'Verify Your Email Address - Welcome to ' . $brevo_config['Company'] . '!',
        'htmlContent' => createVerificationEmailTemplate($username, $verificationToken),
        'textContent' => createVerificationEmailText($username, $verificationToken)
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'api-key: ' . $db_config['api']['api_key']
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    error_log("Brevo API - HTTP: $http_code, Response: " . substr($response, 0, 200));

    if ($curl_error) {
        return ['success' => false, 'message' => 'Email service error: ' . $curl_error];
    }

    return [
        'success' => ($http_code >= 200 && $http_code < 300),
        'message' => ($http_code >= 200 && $http_code < 300) 
            ? 'Verification email sent successfully' 
            : 'Failed to send email - HTTP ' . $http_code
    ];
}

/**
 * Create HTML email template
 */
function createVerificationEmailTemplate($username, $verificationToken)
{
    $db_config = require __DIR__ . '/db_config.php';
    $verificationUrl = "https://{$db_config['api']['host']}/api/verify-email.php?token=" . urlencode($verificationToken);
    $backgroundImageUrl = "https://{$db_config['api']['host']}/uploads/bg-header.png";

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email Address</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f5f5f5; padding: 20px; margin: 0;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #2d3748 0%, #ff3d08 100%); color: white; text-align: center; padding: 40px 20px;">
            <h1 style="margin: 0; font-size: 28px;">Verify Your Email</h1>
        </div>
        <div style="padding: 40px 30px; text-align: center;">
            <h2 style="color: #2d3748;">Hello ' . htmlspecialchars($username) . '! 👋</h2>
            <p style="color: #4a5568; font-size: 16px; line-height: 1.6;">
                Welcome to Gamius! To complete your registration, please verify your email address.
            </p>
            <a href="' . $verificationUrl . '" style="display: inline-block; background: linear-gradient(135deg, #2d3748 0%, #ff3d08 100%); color: white; padding: 16px 32px; text-decoration: none; border-radius: 8px; font-weight: 600; margin: 20px 0;">
                ✓ Verify My Email
            </a>
            <p style="color: #718096; font-size: 14px; margin-top: 30px;">
                If the button doesn\'t work, copy this link:<br>
                <a href="' . $verificationUrl . '" style="color: #667eea; word-break: break-all;">' . htmlspecialchars($verificationUrl) . '</a>
            </p>
            <div style="background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 15px; margin-top: 30px; color: #856404;">
                <strong>🔐 Security:</strong> This link expires in 24 hours.
            </div>
        </div>
        <div style="background-color: #2d3748; color: #a0aec0; text-align: center; padding: 20px; font-size: 13px;">
            <p style="margin: 0;">&copy; ' . date('Y') . ' Gamius. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
}

/**
 * Create plain text email
 */
function createVerificationEmailText($username, $verificationToken)
{
    $db_config = require __DIR__ . '/db_config.php';
    $verificationUrl = "https://{$db_config['api']['host']}/api/verify-email.php?token=" . urlencode($verificationToken);

    return "VERIFY YOUR EMAIL - Gamius\n\nHello {$username}!\n\nWelcome to Gamius. Please verify your email:\n\n{$verificationUrl}\n\nThis link expires in 24 hours.\n\n© " . date('Y') . " Gamius";
}

/**
 * Handle errors with CORS headers already sent
 */
function handleError($message, $code = 400)
{
    error_log("Error [$code]: $message");
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error_code' => $code
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}
