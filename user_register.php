



<?php

/**
 * REGISTRATION.PHP - Complete rewritten file
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

// CORS and content type headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // Database connection
    $db_config = require 'db_config.php';
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    error_log("Database connection established");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log("Processing registration request");
        
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
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful! Please check your email to verify your account.',
            'user_id' => $userResult['user_id'],
            'avatar' => $avatarPath,
            'email_sent' => $emailResult['success'],
            'requires_verification' => true
        ]);

    } else {
        http_response_code(405);
        die(json_encode(['success' => false, 'message' => 'Method not allowed']));
    }

} catch (PDOException $e) {
    error_log("PDO Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    handleError('Database error occurred: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    handleError($e->getMessage(), 400);
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Generate secure verification token
 */
function generateVerificationToken() {
    return bin2hex(random_bytes(32)); // 64 character secure token
}

/**
 * Handle input from POST or JSON
 */
function handleInput() {
    if (!empty($_POST)) {
        error_log("Processing form POST data");
        return $_POST;
    }
    
    $rawInput = file_get_contents('php://input');
    error_log("Raw input: " . substr($rawInput, 0, 100) . "...");
    
    $jsonInput = json_decode($rawInput, true);
    if ($jsonInput) {
        error_log("Processing JSON data");
        return $jsonInput;
    }
    
    throw new Exception('Invalid input format: no valid POST or JSON data received');
}

/**
 * Validate user input
 */
function validateInput($input) {
    error_log("Validating input data");
    
    if (empty($input['email']) || empty($input['password']) || empty($input['username'])) {
        throw new Exception('Missing required fields: email, password, and username are required');
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
function handleAvatarUpload($file) {
    error_log("Processing avatar upload");
    
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/avatars/';
    $webPath = '/uploads/avatars/';
    
    if (!file_exists($uploadDir)) {
        error_log("Creating upload directory: " . $uploadDir);
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($fileInfo, $file['tmp_name']);
    finfo_close($fileInfo);
    
    error_log("Uploaded file MIME type: " . $mimeType);

    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG, PNG, and GIF are allowed.');
    }

    // Check file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File too large. Maximum size is 5MB.');
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('avatar_') . '.' . $extension;
    $fullPath = $uploadDir . $filename;
    
    error_log("Saving avatar to: " . $fullPath);

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new Exception('Failed to save avatar file');
    }

    chmod($fullPath, 0644);
    return $webPath . $filename;
}

/**
 * Check if user already exists
 */
function checkExistingUser($pdo, $email, $username) {
    error_log("Checking for existing user with email: " . $email . " or username: " . $username);
    
    $stmt = $pdo->prepare('SELECT email, username FROM users WHERE email = ? OR username = ?');
    $stmt->execute([$email, $username]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        if ($existingUser['email'] === $email) {
            throw new Exception('Email address is already registered');
        }
        if ($existingUser['username'] === $username) {
            throw new Exception('Username is already taken');
        }
    }
}

/**
 * Create new user with verification token
 */
function createUser($pdo, $input, $avatarPath) {
    error_log("Creating new user: " . $input['username']);
    
    try {
        // Generate verification token
        $verificationToken = generateVerificationToken();
        
        // Prepare SQL with all required fields
        $stmt = $pdo->prepare('
            INSERT INTO users (
                username,
                email,
                password,
                avatar,
                bio,
                type,
                user_type,
                points,
                rank,
                is_verified,
                verification_token,
                failed_attempts,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');

        $result = $stmt->execute([
            $input['username'],
            $input['email'],
            password_hash($input['password'], PASSWORD_DEFAULT),
            $avatarPath,
            $input['bio'] ?? '',
            'participant',
            '',
            0, // points
            null, // rank
            0, // not verified
            $verificationToken,
            0 // failed attempts
        ]);
        
        if (!$result) {
            error_log("Insert failed: " . implode(", ", $stmt->errorInfo()));
            throw new Exception("Failed to create user account");
        }
        
        $userId = $pdo->lastInsertId();
        error_log("User inserted successfully with ID: " . $userId);
        
        return [
            'success' => true,
            'user_id' => $userId,
            'verification_token' => $verificationToken
        ];
        
    } catch (PDOException $e) {
        error_log("SQL Error: " . $e->getMessage());
        throw new Exception("Database error: " . $e->getMessage());
    }
}

/**
 * Send verification email using Brevo API
 */
function sendVerificationEmail($userEmail, $username, $verificationToken) {
    // Brevo API configuration
    $brevo_config = [
        'api_key' => '', // Replace with your actual API key
        'sender_email' => 'anouar.sabir@genius-morocco.com', // Replace with your verified sender email
        'sender_name' => 'Genius Team',
        'Company'=> 'Gamius'
    ];
    
    $url = 'https://api.brevo.com/v3/smtp/email';
    
    // Prepare email data
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
        'subject' => 'Verify Your Email Address - Welcome to '.$brevo_config['Company'].'!',
        'htmlContent' => createVerificationEmailTemplate($username, $verificationToken),
        'textContent' => createVerificationEmailText($username, $verificationToken)
    ];
    
    // Prepare headers
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . $brevo_config['api_key']
    ];
    
    // Send API request
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    curl_close($ch);
    
    // Log the response for debugging
    error_log("Brevo API Response - HTTP Code: $http_code, Response: " . substr($response, 0, 200));
    
    if ($curl_error) {
        error_log("Curl error: " . $curl_error);
        return [
            'success' => false,
            'message' => 'Failed to connect to email service: ' . $curl_error
        ];
    }
    
    if ($http_code >= 200 && $http_code < 300) {
        return [
            'success' => true,
            'message' => 'Verification email sent successfully'
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Failed to send email - HTTP ' . $http_code . ': ' . $response
        ];
    }
}

/**
 * Create HTML verification email template
 */
function createVerificationEmailTemplate($username, $verificationToken) {
    $verificationUrl = "http://localhost/api/verify-email.php?token=" . urlencode($verificationToken);
    
    return '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Verify Your Email Address</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                line-height: 1.6; 
                color: #333; 
                background-color: #f5f5f5;
                padding: 20px 0;
            }
            .email-wrapper {
                max-width: 600px;
                margin: 0 auto;
                background-color: #ffffff;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                border-radius: 8px;
                overflow: hidden;
            }
            .header { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white; 
                text-align: center; 
                padding: 40px 20px;
                position: relative;
            }
            .header::before {
                content: "";
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-image: 
                    radial-gradient(circle at 25% 25%, rgba(255,255,255,0.1) 2px, transparent 2px),
                    radial-gradient(circle at 75% 75%, rgba(255,255,255,0.1) 2px, transparent 2px);
                background-size: 30px 30px;
            }
            .email-icon {
                width: 80px;
                height: 80px;
                margin: 0 auto 20px;
                background-color: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                z-index: 2;
            }
            .email-icon svg {
                width: 40px;
                height: 40px;
                fill: white;
            }
            .header h1 {
                font-size: 28px;
                font-weight: 600;
                position: relative;
                z-index: 2;
                margin: 0;
            }
            .content { 
                padding: 40px 30px;
                text-align: center;
            }
            .welcome-message {
                font-size: 18px;
                color: #4a5568;
                margin-bottom: 30px;
                line-height: 1.7;
            }
            .verification-box {
                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                padding: 30px;
                border-radius: 12px;
                margin: 30px 0;
                border: 1px solid #dee2e6;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            .user-greeting {
                font-weight: 600;
                color: #2d3748;
                font-size: 20px;
                margin-bottom: 15px;
            }
            .verification-text {
                color: #4a5568;
                margin-bottom: 25px;
                font-size: 16px;
            }
            .verify-button { 
                display: inline-block; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white !important;
                padding: 16px 32px; 
                text-decoration: none; 
                border-radius: 8px; 
                font-weight: 600;
                font-size: 16px;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
                border: none;
                cursor: pointer;
            }
            .verify-button:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
            }
            .backup-link {
                margin-top: 25px;
                padding: 20px;
                background-color: #fff3cd;
                border: 1px solid #ffeaa7;
                border-radius: 8px;
                font-size: 14px;
                color: #856404;
            }
            .backup-link strong {
                display: block;
                margin-bottom: 8px;
            }
            .backup-link a {
                color: #667eea;
                text-decoration: none;
                word-break: break-all;
                font-family: monospace;
                font-size: 12px;
            }
            .features-section {
                margin: 30px 0;
                text-align: left;
            }
            .features-title {
                text-align: center;
                color: #2d3748;
                font-size: 18px;
                font-weight: 600;
                margin-bottom: 20px;
            }
            .features-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                margin-top: 15px;
            }
            .feature-item {
                display: flex;
                align-items: center;
                padding: 12px;
                background-color: #f8f9fa;
                border-radius: 6px;
                font-size: 14px;
            }
            .feature-icon {
                width: 20px;
                height: 20px;
                background-color: #667eea;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 10px;
                color: white;
                font-size: 12px;
                font-weight: bold;
                flex-shrink: 0;
            }
            .security-note {
                background-color: #e3f2fd;
                border: 1px solid #bbdefb;
                border-radius: 8px;
                padding: 20px;
                margin: 25px 0;
                font-size: 14px;
                color: #1565c0;
            }
            .footer { 
                background-color: #2d3748;
                color: #a0aec0;
                text-align: center; 
                padding: 30px 20px;
                font-size: 14px;
            }
            .footer a {
                color: #667eea;
                text-decoration: none;
            }
            .footer a:hover {
                text-decoration: underline;
            }
            .footer-brand {
                font-weight: 600;
                color: #fff;
                margin-bottom: 15px;
            }
            .footer-links {
                margin: 15px 0;
            }
            .footer-links a {
                margin: 0 10px;
            }
            .footer-copyright {
                font-size: 12px;
                opacity: 0.8;
                margin-top: 15px;
            }
            @media only screen and (max-width: 600px) {
                .content {
                    padding: 25px 20px;
                }
                .header {
                    padding: 30px 20px;
                }
                .verification-box {
                    padding: 25px 20px;
                    margin: 20px 0;
                }
                .verify-button {
                    padding: 14px 28px;
                    font-size: 15px;
                }
                .features-grid {
                    grid-template-columns: 1fr;
                }
                .email-wrapper {
                    margin: 0 10px;
                }
            }
        </style>
    </head>
    <body>
        <div class="email-wrapper">
            <div class="header">
                <div class="email-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                    </svg>
                </div>
                <h1>Verify Your Email Address</h1>
            </div>
            
            <div class="content">
                <div class="welcome-message">
                    Welcome to <strong>'.$brevo_config['Company'].'</strong>! We\'re excited to have you join our gaming community.
                </div>
                
                <div class="verification-box">
                    <div class="user-greeting">
                        Hello ' . htmlspecialchars($username) . '! 👋
                    </div>
                    <div class="verification-text">
                        To complete your registration and activate your account, please verify your email address by clicking the button below:
                    </div>
                    
                    <a href="' . $verificationUrl . '" class="verify-button">
                        ✓ Verify My Email
                    </a>
                    
                    <div class="backup-link">
                        <strong>Button not working?</strong>
                        Copy and paste this link into your browser:<br>
                        <a href="' . $verificationUrl . '">' . $verificationUrl . '</a>
                    </div>
                </div>
                
                <div class="features-section">
                    <div class="features-title">🎮 What you can do once verified:</div>
                    <div class="features-grid">
                        <div class="feature-item">
                            <div class="feature-icon">🏆</div>
                            <span>Participate in tournaments</span>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">👥</div>
                            <span>Connect with gamers</span>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">📊</div>
                            <span>Track your progress</span>
                        </div>
                        <div class="feature-item">
                            <div class="feature-icon">🎯</div>
                            <span>Earn points & rewards</span>
                        </div>
                    </div>
                </div>
                
                <div class="security-note">
                    <strong>🔐 Security Note:</strong> This verification link will expire in 24 hours for your security. 
                    If you didn\'t create this account, you can safely ignore this email.
                </div>
            </div>
            
            <div class="footer">
                <div class="footer-brand">'.$brevo_config[''].' Gaming Platform</div>
                <p>Need help? Contact our support team at 
                   <a href="mailto:support@gbarena.com">support@gbarena.com</a>
                </p>
                <div class="footer-links">
                    <a href="https://gbarena.com/privacy">Privacy Policy</a> |
                    <a href="https://gbarena.com/terms">Terms of Service</a> |
                    <a href="https://gbarena.com/help">Help Center</a>
                </div>
                <div class="footer-copyright">
                    © 2024 GBarena. All rights reserved.
                </div>
            </div>
        </div>
    </body>
    </html>';
}

/**
 * Create plain text version of verification email
 */
function createVerificationEmailText($username, $verificationToken) {
    $verificationUrl = "https://yourdomain.com/verify-email.php?token=" . urlencode($verificationToken);
    
    return "
VERIFY YOUR EMAIL ADDRESS - GBARENA

Welcome to GBarena Gaming Platform!

Hello " . $username . "!

Thank you for joining our gaming community. To complete your registration and activate your account, please verify your email address.

VERIFICATION LINK:
" . $verificationUrl . "

What you can do once verified:
• Participate in gaming tournaments
• Connect with other gamers
• Track your gaming progress
• Earn points and rewards
• Access exclusive features

SECURITY NOTE:
This verification link will expire in 24 hours for your security.
If you didn't create this account, you can safely ignore this email.

Need help? Contact our support team at support@gbarena.com

Best regards,
The GBarena Team

---
© 2024 GBarena. All rights reserved.
Privacy Policy: https://gbarena.com/privacy
Terms of Service: https://gbarena.com/terms
    ";
}

/**
 * Handle errors and send JSON response
 */
function handleError($message, $code = 400) {
    error_log("Error response: [$code] $message");
    http_response_code($code);
    die(json_encode([
        'success' => false,
        'message' => $message,
        'error_code' => $code
    ]));
}
