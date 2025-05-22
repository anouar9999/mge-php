<?php
// Error handling and logging setup
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
    
    // Check and update the table structure if needed
    ensureAutoIncrementId($pdo);

    // For POST requests, process registration
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Log incoming request for debugging
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

        // Create new user
        createUser($pdo, $input, $avatarPath);
        error_log("User created successfully");

        // Send welcome email via Brevo API
        $emailResult = sendWelcomeEmail($input['email'], $input['username']);
        
        if ($emailResult['success']) {
            error_log("Welcome email sent successfully to: " . $input['email']);
        } else {
            error_log("Failed to send welcome email: " . $emailResult['message']);
            // Don't fail registration if email fails - just log it
        }

        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful',
            'avatar' => $avatarPath,
            'email_sent' => $emailResult['success']
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

/**
 * Send welcome email using Brevo API
 */
function sendWelcomeEmail($userEmail, $username) {
    // Brevo API configuration
    $brevo_config = [
        'api_key' => 'YOUR_BREVO_API_KEY', // Replace with your actual API key
        'sender_email' => 'noreply@yourdomain.com', // Replace with your verified sender email
        'sender_name' => 'Your Platform Name' // Replace with your platform name
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
        'subject' => 'Welcome to Our Platform!',
        'htmlContent' => createWelcomeEmailTemplate($username),
        'textContent' => createWelcomeEmailText($username)
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
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    curl_close($ch);
    
    // Log the response for debugging
    error_log("Brevo API Response - HTTP Code: $http_code, Response: $response");
    
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
            'message' => 'Welcome email sent successfully',
            'response' => $response
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Failed to send email - HTTP ' . $http_code . ': ' . $response
        ];
    }
}

/**
 * Create HTML email template for welcome email
 */
function createWelcomeEmailTemplate($username) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Welcome to Our Platform</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                max-width: 600px; 
                margin: 0 auto; 
                padding: 20px; 
            }
            .header { 
                background-color: #4CAF50; 
                color: white; 
                text-align: center; 
                padding: 20px; 
                border-radius: 10px 10px 0 0; 
            }
            .content { 
                background-color: #f9f9f9; 
                padding: 30px; 
                border-radius: 0 0 10px 10px; 
            }
            .button { 
                display: inline-block; 
                background-color: #4CAF50; 
                color: white; 
                padding: 12px 24px; 
                text-decoration: none; 
                border-radius: 5px; 
                margin: 20px 0; 
            }
            .footer { 
                text-align: center; 
                color: #666; 
                font-size: 12px; 
                margin-top: 20px; 
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Welcome to Our Platform!</h1>
        </div>
        <div class="content">
            <h2>Hello ' . htmlspecialchars($username) . '!</h2>
            
            <p>Thank you for joining our platform. We\'re excited to have you as part of our community!</p>
            
            <p>Your account has been successfully created and you can now:</p>
            <ul>
                <li>Access all platform features</li>
                <li>Connect with other users</li>
                <li>Participate in events and activities</li>
                <li>Customize your profile</li>
            </ul>
            
            <p>To get started, click the button below to access your dashboard:</p>
            
            <a href="https://yourdomain.com/dashboard" class="button">Go to Dashboard</a>
            
            <p>If you have any questions or need assistance, feel free to contact our support team.</p>
            
            <p>Best regards,<br>The Platform Team</p>
        </div>
        <div class="footer">
            <p>© 2024 Your Platform Name. All rights reserved.</p>
            <p>If you didn\'t create this account, please ignore this email.</p>
        </div>
    </body>
    </html>';
}

/**
 * Create plain text version of welcome email
 */
function createWelcomeEmailText($username) {
    return "
Welcome to Our Platform!

Hello " . $username . "!

Thank you for joining our platform. We're excited to have you as part of our community!

Your account has been successfully created and you can now:
- Access all platform features
- Connect with other users
- Participate in events and activities
- Customize your profile

To get started, visit: https://yourdomain.com/dashboard

If you have any questions or need assistance, feel free to contact our support team.

Best regards,
The Platform Team

---
© 2024 Your Platform Name. All rights reserved.
If you didn't create this account, please ignore this email.
    ";
}

/**
 * Send notification email to admin about new registration
 */
function sendAdminNotification($userEmail, $username) {
    // Admin notification configuration
    $admin_config = [
        'api_key' => 'YOUR_BREVO_API_KEY', // Same as above
        'sender_email' => 'noreply@yourdomain.com', // Same as above
        'sender_name' => 'Registration System',
        'admin_email' => 'admin@yourdomain.com' // Replace with admin email
    ];
    
    $url = 'https://api.brevo.com/v3/smtp/email';
    
    // Prepare email data
    $data = [
        'sender' => [
            'name' => $admin_config['sender_name'],
            'email' => $admin_config['sender_email']
        ],
        'to' => [
            [
                'email' => $admin_config['admin_email'],
                'name' => 'Administrator'
            ]
        ],
        'subject' => 'New User Registration: ' . $username,
        'htmlContent' => createAdminNotificationTemplate($userEmail, $username),
        'textContent' => "New user registered:\nUsername: $username\nEmail: $userEmail\nTime: " . date('Y-m-d H:i:s')
    ];
    
    // Prepare headers
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . $admin_config['api_key']
    ];
    
    // Send API request
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($http_code >= 200 && $http_code < 300);
}

/**
 * Create admin notification email template
 */
function createAdminNotificationTemplate($userEmail, $username) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>New User Registration</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #2196F3; color: white; padding: 15px; border-radius: 5px; }
            .content { padding: 20px; background-color: #f9f9f9; margin-top: 10px; border-radius: 5px; }
            .info-row { margin: 10px 0; }
            .label { font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>New User Registration</h2>
            </div>
            <div class="content">
                <p>A new user has registered on the platform:</p>
                
                <div class="info-row">
                    <span class="label">Username:</span> ' . htmlspecialchars($username) . '
                </div>
                <div class="info-row">
                    <span class="label">Email:</span> ' . htmlspecialchars($userEmail) . '
                </div>
                <div class="info-row">
                    <span class="label">Registration Time:</span> ' . date('Y-m-d H:i:s') . '
                </div>
                
                <p>Please review the new user account in the admin panel.</p>
            </div>
        </div>
    </body>
    </html>';
}

/**
 * Ensures the users table has an auto-increment ID column
 */
function ensureAutoIncrementId($pdo) {
    try {
        // First, check if the table already has any auto_increment column
        $stmt = $pdo->query("SHOW COLUMNS FROM users");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existingAutoIncrement = false;
        
        foreach ($columns as $column) {
            if (strpos(strtoupper($column['Extra']), 'AUTO_INCREMENT') !== false) {
                error_log("Table already has auto-increment column: " . $column['Field']);
                $existingAutoIncrement = true;
                break;
            }
        }
        
        if ($existingAutoIncrement) {
            // Table already has an auto-increment column, no action needed
            return;
        }
        
        // Check for primary key
        $stmt = $pdo->query("SHOW KEYS FROM users WHERE Key_name = 'PRIMARY'");
        $primaryKey = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if the ID column exists
        $hasIdColumn = false;
        foreach ($columns as $column) {
            if ($column['Field'] === 'id') {
                $hasIdColumn = true;
                break;
            }
        }
        
        // Implement the changes based on current structure
        if (!empty($primaryKey)) {
            // There's already a primary key
            error_log("Table already has primary key: " . json_encode($primaryKey));
            
            if ($hasIdColumn) {
                // ID exists but isn't auto_increment, try to modify it
                error_log("Attempting to modify existing ID column to auto_increment");
                
                // Check if ID is part of the primary key
                $isIdPrimary = false;
                foreach ($primaryKey as $key) {
                    if ($key['Column_name'] === 'id') {
                        $isIdPrimary = true;
                        break;
                    }
                }
                
                if ($isIdPrimary) {
                    // ID is the primary key, just add auto_increment
                    $pdo->exec("ALTER TABLE users MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT");
                } else {
                    // ID exists but isn't primary key - complex situation, drop constraints
                    // This is risky and might require dropping the primary key first
                    error_log("Complex table structure - dropping primary key first");
                    $pdo->exec("ALTER TABLE users DROP PRIMARY KEY");
                    $pdo->exec("ALTER TABLE users MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY");
                }
            } else {
                // No ID column and already has primary key - add ID as separate key
                error_log("Adding ID column with separate auto_increment constraint");
                $pdo->exec("ALTER TABLE users ADD COLUMN id INT NOT NULL AUTO_INCREMENT UNIQUE KEY");
            }
        } else {
            // No primary key exists
            if ($hasIdColumn) {
                // ID exists but isn't primary or auto_increment
                error_log("Making existing ID column primary key and auto_increment");
                $pdo->exec("ALTER TABLE users MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY");
            } else {
                // No ID column and no primary key - simplest case
                error_log("Adding new ID column as primary key with auto_increment");
                $pdo->exec("ALTER TABLE users ADD COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY");
            }
        }
        
        error_log("Auto-increment ID column configured successfully");
    } catch (PDOException $e) {
        error_log("Error ensuring auto-increment ID: " . $e->getMessage());
        throw new Exception("Failed to configure database schema: " . $e->getMessage());
    }
}

function handleInput() {
    // Try to get POST data
    if (!empty($_POST)) {
        error_log("Processing form POST data");
        return $_POST;
    }
    
    // Try to get JSON data
    $rawInput = file_get_contents('php://input');
    error_log("Raw input: " . substr($rawInput, 0, 100) . "...");
    
    $jsonInput = json_decode($rawInput, true);
    if ($jsonInput) {
        error_log("Processing JSON data");
        return $jsonInput;
    }
    
    throw new Exception('Invalid input format: no valid POST or JSON data received');
}

function validateInput($input) {
    error_log("Validating input data");
    
    if (empty($input['email']) || empty($input['password']) || empty($input['username'])) {
        throw new Exception('Missing required fields');
    }

    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }

    if (strlen($input['password']) < 8) {
        throw new Exception('Password must be at least 8 characters');
    }

    if (strlen($input['username']) < 3 || strlen($input['username']) > 30) {
        throw new Exception('Username must be between 3 and 30 characters');
    }
}

function handleAvatarUpload($file) {
    error_log("Processing avatar upload");
    
    // Define paths
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/avatars/';
    $webPath = '/uploads/avatars/';
    
    // Create directory if it doesn't exist
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

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('avatar_') . '.' . $extension;
    $fullPath = $uploadDir . $filename;
    
    error_log("Saving avatar to: " . $fullPath);

    // Move and process file
    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new Exception('Failed to save avatar file');
    }

    // Set proper permissions
    chmod($fullPath, 0644);

    // Return web-accessible path
    return $webPath . $filename;
}

function checkExistingUser($pdo, $email, $username) {
    error_log("Checking for existing user with email: " . $email . " or username: " . $username);
    
    $stmt = $pdo->prepare('SELECT email FROM users WHERE email = ? OR username = ?');
    $stmt->execute([$email, $username]);
    
    if ($stmt->fetch()) {
        throw new Exception('Email or username already exists');
    }
}

function createUser($pdo, $input, $avatarPath) {
    error_log("Creating new user: " . $input['username']);
    
    try {
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
                created_at,
                is_verified
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ');

        $result = $stmt->execute([
            $input['username'],
            $input['email'],
            password_hash($input['password'], PASSWORD_DEFAULT),
            $avatarPath,
            $input['bio'] ?? null,
            "participant",
            "", // Empty string for user_type
            0  // Not verified by default
        ]);
        
        if (!$result) {
            error_log("Insert failed: " . implode(", ", $stmt->errorInfo()));
            throw new Exception("Failed to insert user");
        }
        
        error_log("User inserted successfully with ID: " . $pdo->lastInsertId());
        return $result;
    } catch (PDOException $e) {
        error_log("SQL Error: " . $e->getMessage());
        error_log("SQL State: " . $e->getCode());
        throw $e;
    }
}

function handleError($message, $code = 400) {
    error_log("Error response: [$code] $message");
    http_response_code($code);
    die(json_encode([
        'success' => false,
        'message' => $message
    ]));
}