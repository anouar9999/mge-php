<?php
/**
 * USER_REGISTER.PHP - With Enhanced Email Logging
 */

// ============================================
// 🔥 STEP 1: SEND CORS HEADERS IMMEDIATELY
// ============================================
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'https://user.gamius.ma',
    'https://api.gamius.ma',
    'http://localhost:3000'
];

if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://user.gamius.ma');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

// ============================================
// STEP 2: Error Handling Configuration
// ============================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

// ============================================
// STEP 3: Define Log Files
// ============================================
define('GENERAL_LOG', __DIR__ . '/error.log');
define('EMAIL_LOG', __DIR__ . '/email_log.log');
define('REGISTRATION_LOG', __DIR__ . '/registration_log.log');

// ============================================
// STEP 4: Load Configuration
// ============================================
$db_config = require __DIR__ . '/db_config.php';

// ============================================
// STEP 5: Main Processing
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

    logToFile(GENERAL_LOG, "Database connection established");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        logToFile(REGISTRATION_LOG, "=== NEW REGISTRATION ATTEMPT ===");
        logToFile(REGISTRATION_LOG, "Origin: " . $origin);
        logToFile(REGISTRATION_LOG, "IP Address: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

        // Get and validate input
        $input = handleInput();
        validateInput($input);

        logToFile(REGISTRATION_LOG, "Input validated for user: " . $input['username'] . " | Email: " . $input['email']);

        // Handle avatar upload if present
        $avatarPath = null;
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $avatarPath = handleAvatarUpload($_FILES['avatar']);
            logToFile(REGISTRATION_LOG, "Avatar uploaded: " . $avatarPath);
        }

        // Check for existing user
        checkExistingUser($pdo, $input['email'], $input['username']);
        logToFile(REGISTRATION_LOG, "User uniqueness verified");

        // Create new user with verification token
        $userResult = createUser($pdo, $input, $avatarPath);
        logToFile(REGISTRATION_LOG, "✅ User created successfully | ID: " . $userResult['user_id'] . " | Token: " . substr($userResult['verification_token'], 0, 10) . "...");

        // Send verification email
        $emailResult = sendVerificationEmail(
            $input['email'],
            $input['username'],
            $userResult['verification_token']
        );

        if ($emailResult['success']) {
            logToFile(REGISTRATION_LOG, "✅ REGISTRATION COMPLETE | User ID: " . $userResult['user_id'] . " | Email sent successfully");
        } else {
            logToFile(REGISTRATION_LOG, "⚠️ REGISTRATION COMPLETE BUT EMAIL FAILED | User ID: " . $userResult['user_id'] . " | " . $emailResult['message']);
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
    logToFile(GENERAL_LOG, "❌ PDO ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    handleError('Database error occurred. Please try again later.', 500);
} catch (Exception $e) {
    logToFile(GENERAL_LOG, "❌ GENERAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    handleError($e->getMessage(), 400);
}

exit(0);

// =============================================================================
// LOGGING FUNCTIONS
// =============================================================================

/**
 * Write to specific log file with timestamp
 */
function logToFile($logFile, $message)
{
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Log email activity to dedicated email log
 */
function logEmail($level, $message, $data = [])
{
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}";
    
    if (!empty($data)) {
        $logEntry .= " | Data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    $logEntry .= "\n";
    file_put_contents(EMAIL_LOG, $logEntry, FILE_APPEND | LOCK_EX);
}

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
 * Calculate token expiration (24 hours from now)
 */
function getTokenExpiration()
{
    return date('Y-m-d H:i:s', strtotime('+24 hours'));
}

/**
 * Handle input from POST or JSON
 */
function handleInput()
{
    if (!empty($_POST)) {
        logToFile(GENERAL_LOG, "Processing form POST data");
        return $_POST;
    }

    $rawInput = file_get_contents('php://input');
    logToFile(GENERAL_LOG, "Raw input received: " . strlen($rawInput) . " bytes");

    if (empty($rawInput)) {
        throw new Exception('No input data received');
    }

    $jsonInput = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON format: ' . json_last_error_msg());
    }

    if ($jsonInput) {
        logToFile(GENERAL_LOG, "Processing JSON data");
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
    $tokenExpiration = getTokenExpiration();

    $stmt = $pdo->prepare('
        INSERT INTO users (
            username, 
            password, 
            email, 
            type, 
            points, 
            `rank`, 
            is_verified, 
            verification_token, 
            bio, 
            avatar, 
            failed_attempts, 
            user_type, 
            token_expires_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $result = $stmt->execute([
        $input['username'],
        password_hash($input['password'], PASSWORD_BCRYPT, ['cost' => 12]),
        $input['email'],
        'participant',
        0,
        null,
        0,
        $verificationToken,
        $input['bio'] ?? '',
        $avatarPath,
        0,
        '',
        $tokenExpiration
    ]);

    if (!$result) {
        throw new Exception("Failed to create user account");
    }

    $userId = $pdo->lastInsertId();

    return [
        'success' => true,
        'user_id' => $userId,
        'verification_token' => $verificationToken
    ];
}

/**
 * Send verification email with detailed logging
 */
function sendVerificationEmail($userEmail, $username, $verificationToken)
{
    logEmail('INFO', '=== STARTING EMAIL SEND PROCESS ===', [
        'recipient' => $userEmail,
        'username' => $username,
        'token_preview' => substr($verificationToken, 0, 10) . '...'
    ]);

    $db_config = require __DIR__ . '/db_config.php';
    
    // Verify API key exists
    if (empty($db_config['api']['api_key'])) {
        logEmail('ERROR', 'Brevo API key is missing in db_config.php');
        return ['success' => false, 'message' => 'Email configuration error: API key missing'];
    }

    logEmail('INFO', 'API key found (length: ' . strlen($db_config['api']['api_key']) . ')');
    
    $brevo_config = [
        'sender_email' => 'No-reply@gamiusgroup.com',
        'sender_name' => 'Genius Team',
        'Company' => 'Gamius'
    ];

    $verificationUrl = "https://{$db_config['api']['host']}/api/verify-email.php?token=" . urlencode($verificationToken);
    
    logEmail('INFO', 'Verification URL generated', ['url' => $verificationUrl]);

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

    logEmail('INFO', 'Email payload prepared', [
        'sender' => $brevo_config['sender_email'],
        'recipient' => $userEmail,
        'subject' => $data['subject']
    ]);

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

    logEmail('INFO', 'Sending request to Brevo API...');

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Log detailed response
    logEmail('INFO', 'Brevo API Response', [
        'http_code' => $http_code,
        'response' => substr($response, 0, 500),
        'curl_error' => $curl_error ?: 'none'
    ]);

    if ($curl_error) {
        logEmail('ERROR', 'cURL Error occurred', ['error' => $curl_error]);
        return ['success' => false, 'message' => 'Email service error: ' . $curl_error];
    }

    $success = ($http_code >= 200 && $http_code < 300);

    if ($success) {
        logEmail('SUCCESS', '✅ Email sent successfully to ' . $userEmail);
    } else {
        logEmail('ERROR', '❌ Failed to send email', [
            'http_code' => $http_code,
            'response' => $response
        ]);
    }

    return [
        'success' => $success,
        'message' => $success 
            ? 'Verification email sent successfully' 
            : 'Failed to send email - HTTP ' . $http_code,
        'http_code' => $http_code
    ];
}

/**
 * Create HTML email template
 */
function createVerificationEmailTemplate($username, $verificationToken)
{
    $db_config = require __DIR__ . '/db_config.php';
    $verificationUrl = "https://{$db_config['api']['host']}/api/verify-email.php?token=" . urlencode($verificationToken);

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email Address</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body style="font-family: \'Saira\', sans-serif; background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); padding: 20px; margin: 0;">
    <div style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3); border: 1px solid #333;">
        <div style="height: 8px; background: linear-gradient(90deg, #F43620 0%, #ff6b4a 50%, #F43620 100%);"></div>
        
        <div style="background: #1a1a1a; background-image: radial-gradient(circle at 20% 50%, rgba(244, 54, 32, 0.15) 0%, transparent 50%), radial-gradient(circle at 80% 50%, rgba(244, 54, 32, 0.1) 0%, transparent 50%), repeating-linear-gradient(45deg, transparent, transparent 35px, rgba(255, 255, 255, 0.02) 35px, rgba(255, 255, 255, 0.02) 70px); color: white; text-align: center; padding: 45px 30px; position: relative; border-bottom: 3px solid #F43620;">
            <div style="margin-bottom: 18px;">
                <div style="width: 80px; height: 80px; margin: 0 auto; background: #ffffff; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 30px rgba(255, 255, 255, 0.3), 0 0 60px rgba(255, 255, 255, 0.15); border: 2px solid rgba(255, 255, 255, 0.4);">
                    <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                        <circle cx="20" cy="20" r="18" stroke="#1a1a1a" stroke-width="2.5" fill="none"/>
                        <path d="M12 20 L17 25 L28 14" stroke="#1a1a1a" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    </svg>
                </div>
            </div>
            <h1 style="margin: 0 0 12px 0; font-size: 38px; font-weight: 900; letter-spacing: 3px; text-transform: uppercase; text-shadow: 2px 2px 4px rgba(0,0,0,0.3);">GAMIUS</h1>
            <div style="padding: 8px 24px; background: transparent; border: 2px solid #F43620; border-radius: 30px; display: inline-block;">
                <p style="margin: 0; font-size: 13px; font-weight: 700; letter-spacing: 2px; color: #F43620;">EMAIL VERIFICATION</p>
            </div>
        </div>
        
        <div style="padding: 50px 40px; text-align: center; background: #ffffff;">
            <div style="display: inline-flex; align-items: center; gap: 10px; background: linear-gradient(135deg, #f5f5f5 0%, #e8e8e8 100%); padding: 12px 24px; border-radius: 50px; margin-bottom: 25px; border: 2px solid #e0e0e0;">
                <span style="font-size: 24px;">👋</span>
                <span style="color: #1a1a1a; font-size: 18px; font-weight: 700;">Welcome, ' . htmlspecialchars($username) . '!</span>
            </div>
            
            <h2 style="color: #1a1a1a; font-size: 28px; margin-bottom: 20px; font-weight: 700;">Verify Your Email Address</h2>
            
            <p style="color: #6b7280; font-size: 17px; line-height: 1.8; margin-bottom: 30px; max-width: 500px; margin-left: auto; margin-right: auto;">
                To complete your registration and access your Gamius account, please verify your email address by clicking the button below.
            </p>
            
            <a href="' . $verificationUrl . '" style="display: inline-block; background: linear-gradient(135deg, #F43620 0%, #ff4520 100%); color: white; padding: 20px 50px; text-decoration: none; border-radius: 50px; font-weight: 800; font-size: 18px; margin: 25px 0; box-shadow: 0 8px 25px rgba(244, 54, 32, 0.4); transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 1px; border: 2px solid #F43620;">
                <span style="display: inline-flex; align-items: center; gap: 10px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" style="vertical-align: middle;">
                        <circle cx="12" cy="12" r="10" stroke="white" stroke-width="2"/>
                        <path d="M8 12 L11 15 L16 9" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                    Verify My Email
                </span>
            </a>
            
            <div style="background: linear-gradient(135deg, #fff5f3 0%, #ffe8e5 100%); border: 2px solid #F43620; border-radius: 12px; padding: 25px; margin-top: 40px; text-align: left;">
                <div style="display: flex; align-items: flex-start; gap: 15px;">
                    <div style="flex-shrink: 0;">
                        <svg width="32" height="32" viewBox="0 0 32 32">
                            <circle cx="16" cy="16" r="15" fill="#F43620"/>
                            <path d="M16 8 L16 12 M16 20 L16 16 M16 22 L16 24" stroke="white" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div>
                        <p style="margin: 0 0 10px 0; color: #1a1a1a; font-size: 15px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;">Security Notice</p>
                        <p style="margin: 0; color: #4a5568; font-size: 14px; line-height: 1.8;">
                            • This verification link expires in <strong style="color: #F43620;">24 hours</strong><br>
                            • Do not share this link with anyone<br>
                            • If you did not create an account, please disregard this email
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="background: linear-gradient(135deg, #1a1a1a 0%, #000000 100%); color: #9ca3af; text-align: center; padding: 25px 30px; font-size: 13px; border-top: 3px solid #F43620;">
            <p style="margin: 0; color: #6b7280; font-size: 12px;">&copy; ' . date('Y') . ' Gamius Tournament Platform. All rights reserved.</p>
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
    logToFile(GENERAL_LOG, "Error [$code]: $message");
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error_code' => $code
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}
?>
