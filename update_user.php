<?php
// update_user.php

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enforce PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use PUT method.']);
    exit();
}

header('Content-Type: application/json');

// Load database configuration
$db_config = require 'db_config.php';

try {
    // Database connection
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], $options);
    
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Validate input
    if (!$data || !isset($data['id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid input. User ID is required in the request body.'
        ]);
        exit();
    }
    
    $userId = intval($data['id']);
    
    // Validate user ID
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid user ID'
        ]);
        exit();
    }
    
    // Check if user exists
    $checkSql = "SELECT id, username, email FROM users WHERE id = ?";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([$userId]);
    $existingUser = $checkStmt->fetch();
    
    if (!$existingUser) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => "User with ID {$userId} not found"
        ]);
        exit();
    }
    
    // Check for username uniqueness if username is being updated
    if (isset($data['username']) && $data['username'] !== $existingUser['username']) {
        $usernameSql = "SELECT id FROM users WHERE username = ? AND id != ?";
        $usernameStmt = $pdo->prepare($usernameSql);
        $usernameStmt->execute([$data['username'], $userId]);
        
        if ($usernameStmt->fetch()) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Username already exists'
            ]);
            exit();
        }
    }
    
    // Check for email uniqueness if email is being updated
    if (isset($data['email']) && $data['email'] !== $existingUser['email']) {
        $emailSql = "SELECT id FROM users WHERE email = ? AND id != ?";
        $emailStmt = $pdo->prepare($emailSql);
        $emailStmt->execute([$data['email'], $userId]);
        
        if ($emailStmt->fetch()) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Email already exists'
            ]);
            exit();
        }
    }
    
    // Build update query dynamically based on provided fields
    $allowedFields = [
        'username', 'email', 'bio', 'avatar', 'type', 
        'points', 'rank', 'is_verified', 'user_type'
    ];
    
    $updateFields = [];
    $params = [];
    $updatedFieldsList = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            // Special handling for rank (reserved keyword)
            if ($field === 'rank') {
                $updateFields[] = "`rank` = ?";
            } else {
                $updateFields[] = "$field = ?";
            }
            $params[] = $data[$field];
            $updatedFieldsList[] = $field;
        }
    }
    
    // Handle password update separately (needs hashing)
    if (isset($data['password']) && !empty($data['password'])) {
        $updateFields[] = "password = ?";
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        $updatedFieldsList[] = 'password';
    }
    
    if (empty($updateFields)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No valid fields to update. Allowed fields: ' . implode(', ', $allowedFields) . ', password'
        ]);
        exit();
    }
    
    // Add user ID to params
    $params[] = $userId;
    
    // Execute update
    $updateSql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $updateStmt = $pdo->prepare($updateSql);
    
    if ($updateStmt->execute($params)) {
        // Fetch updated user
        $fetchSql = "
            SELECT 
                id,
                username,
                email,
                type,
                points,
                `rank`,
                is_verified,
                created_at,
                bio,
                avatar,
                user_type
            FROM users
            WHERE id = ?
        ";
        $fetchStmt = $pdo->prepare($fetchSql);
        $fetchStmt->execute([$userId]);
        $updatedUser = $fetchStmt->fetch();
        
        // Log activity
        try {
            $logSql = "
                INSERT INTO activity_log (user_id, action, details, ip_address)
                VALUES (?, 'Profile Updated', ?, ?)
            ";
            $logStmt = $pdo->prepare($logSql);
            $logStmt->execute([
                $userId,
                'Updated fields: ' . implode(', ', $updatedFieldsList),
                $_SERVER['REMOTE_ADDR'] ?? '::1'
            ]);
        } catch (PDOException $e) {
            // Log error but don't fail the request
            error_log('Failed to log activity: ' . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $updatedUser,
            'updated_fields' => $updatedFieldsList
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update user'
        ]);
    }

} catch (PDOException $e) {
    error_log('Database error in update_user.php: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('General error in update_user.php: ' . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}