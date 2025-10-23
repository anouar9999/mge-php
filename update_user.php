<?php
// Simplified update_user.php

// Handle CORS - CRITICAL: Must be BEFORE any output
$allowed_origins = [
    'https://user.gamius.ma',
    'https://api.gamius.ma',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE, PUT");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Max-Age: 3600");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Accept both PUT and POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

$db_config = require 'db_config.php';

try {
    // Database connection
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}";
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Handle file upload (POST) vs JSON update (PUT)
    $isFileUpload = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['avatar']);
    
    if ($isFileUpload) {
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    }
    
    // Get user ID
    $userId = $data['id'] ?? $data['user_id'] ?? null;
    
    if (!$userId || $userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid user ID required']);
        exit();
    }
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    // Prepare update data
    $updates = [];
    $params = [];
    
    // Handle avatar upload
    if ($isFileUpload && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['avatar'];
        
        // Validate
        if ($file['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'File too large (max 5MB)']);
            exit();
        }
        
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid file type']);
            exit();
        }
        
        // Upload
        $uploadDir = __DIR__ . '/uploads/avatars/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = 'avatar_' . uniqid() . '.' . $ext;
        $uploadPath = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            // Delete old avatar
            if ($user['avatar'] && file_exists(__DIR__ . $user['avatar'])) {
                @unlink(__DIR__ . $user['avatar']);
            }
            
            $updates[] = "avatar = ?";
            $params[] = '/uploads/avatars/' . $filename;
        }
    }
    
    // Handle other fields
    $allowedFields = ['username', 'email', 'bio', 'type', 'points', 'rank', 'is_verified', 'user_type'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field]) && $data[$field] !== '') {
            // Check uniqueness for username/email
            if (in_array($field, ['username', 'email']) && $data[$field] !== $user[$field]) {
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE $field = ? AND id != ?");
                $checkStmt->execute([$data[$field], $userId]);
                if ($checkStmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => ucfirst($field) . ' already exists']);
                    exit();
                }
            }
            
            $updates[] = ($field === 'rank' ? "`rank`" : $field) . " = ?";
            $params[] = $data[$field];
        }
    }
    
    // Handle password
    if (!empty($data['password'])) {
        $updates[] = "password = ?";
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
    }
    
    // Update if there are changes
    if (!empty($updates)) {
        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($params);
    }
    
    // Fetch updated user
    $stmt = $pdo->prepare("SELECT id, username, email, type, points, `rank`, is_verified, bio, avatar, user_type, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $updatedUser = $stmt->fetch();
    
    // Ensure avatar path is included in response
    if (empty($updatedUser['avatar']) && !empty($user['avatar'])) {
        $updatedUser['avatar'] = $user['avatar'];
    }
    
    // Log activity
    try {
        $logStmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, details, ip_address) VALUES (?, 'Profile Updated', ?, ?)");
        $logStmt->execute([$userId, $isFileUpload ? 'Avatar uploaded' : 'Profile updated', $_SERVER['REMOTE_ADDR'] ?? '::1']);
    } catch (Exception $e) {
        error_log('Activity log failed: ' . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => $isFileUpload ? 'Avatar uploaded successfully' : 'Profile updated successfully',
        'data' => $updatedUser,
        'debug' => [
            'avatar_uploaded' => $isFileUpload,
            'updates_made' => count($updates),
            'avatar_path' => $updatedUser['avatar']
        ]
    ]);

} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred', 'error' => $e->getMessage()]);
}
