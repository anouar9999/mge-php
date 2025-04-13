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

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

try {
    // Log incoming request for debugging
    error_log("Processing registration request");
    
    // Database connection
    $db_config = require 'db_config.php';
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    error_log("Database connection established");

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

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful',
        'avatar' => $avatarPath
    ]);

} catch (PDOException $e) {
    error_log("PDO Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    handleError('Database error occurred: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    handleError($e->getMessage(), 400);
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
        // Show all user table columns for debugging
        $stmt = $pdo->query("SHOW COLUMNS FROM users");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        error_log("User table columns: " . implode(", ", $columns));
        
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