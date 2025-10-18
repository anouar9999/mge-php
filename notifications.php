<?php
// api/notifications.php

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Prevent PHP from outputting HTML errors
ini_set('display_errors', 0);
error_reporting(0);

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Load database configuration
    $db_config = require 'db_config.php';

    // Connect to the database
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4" . 
        (isset($db_config['port']) ? ";port={$db_config['port']}" : ""),
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get user_id from multiple sources
    $user_id = null;

    // Option 1: From query parameter
    if (isset($_GET['user_id'])) {
        $user_id = (int)$_GET['user_id'];
    }

    // Option 2: From POST body
    if (!$user_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (isset($data['user_id'])) {
            $user_id = (int)$data['user_id'];
        }
    }

    // Option 3: From session (fallback)
    if (!$user_id) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['user_id'])) {
            $user_id = (int)$_SESSION['user_id'];
        }
    }

    // Check if user_id is valid
    if (!$user_id) {
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized. Please provide user_id.'
        ]);
        http_response_code(401);
        exit;
    }

    // Get request method and action
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? trim($_GET['action']) : '';

    // Route to appropriate handler
    switch ($action) {
        case 'get':
            if ($method === 'GET') {
                handleGetNotifications($pdo, $user_id);
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'unread':
            if ($method === 'GET') {
                handleGetUnreadNotifications($pdo, $user_id);
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'count':
            if ($method === 'GET') {
                handleGetUnreadCount($pdo, $user_id);
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'mark_read':
            if ($method === 'POST') {
                handleMarkAsRead($pdo, $user_id);
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'mark_all_read':
            if ($method === 'POST') {
                handleMarkAllAsRead($pdo, $user_id);
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'delete':
            if ($method === 'POST') {
                handleDeleteNotification($pdo, $user_id);
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        case 'create':
            if ($method === 'POST') {
                handleCreateNotification($pdo, $user_id);
            } else {
                sendError('Method not allowed', 405);
            }
            break;
            
        default:
            sendError('Invalid action. Valid actions: get, unread, count, mark_read, mark_all_read, delete, create', 400);
            break;
    }

} catch (Exception $e) {
    // Log error to server log but don't expose details
    error_log('Notification API error: ' . $e->getMessage());
    
    // Return a clean error response
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'error' => $e->getMessage()
    ]);
}

// ============================================
// FUNCTION: GET ALL NOTIFICATIONS
// ============================================
function handleGetNotifications($pdo, $user_id) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    
    // Validate parameters
    if ($limit < 1) $limit = 1;
    if ($limit > 100) $limit = 100;
    if ($offset < 0) $offset = 0;
    
    // Get notifications for user
    $stmt = $pdo->prepare("
        SELECT 
            id,
            user_id,
            message,
            is_read,
            created_at,
            expiry_date
        FROM notifications 
        WHERE user_id = :user_id 
        AND (expiry_date IS NULL OR expiry_date > NOW())
        ORDER BY created_at DESC 
        LIMIT :limit OFFSET :offset
    ");
    
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM notifications 
        WHERE user_id = :user_id 
        AND (expiry_date IS NULL OR expiry_date > NOW())
    ");
    $countStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo json_encode([
        'success' => true,
        'user_id' => $user_id,
        'notifications' => $notifications,
        'total' => (int)$totalCount,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

// ============================================
// FUNCTION: GET UNREAD NOTIFICATIONS
// ============================================
function handleGetUnreadNotifications($pdo, $user_id) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    // Validate limit
    if ($limit < 1) $limit = 1;
    if ($limit > 50) $limit = 50;
    
    // Get unread notifications
    $stmt = $pdo->prepare("
        SELECT 
            id,
            user_id,
            message,
            is_read,
            created_at,
            expiry_date
        FROM notifications 
        WHERE user_id = :user_id 
        AND is_read = 0 
        AND (expiry_date IS NULL OR expiry_date > NOW())
        ORDER BY created_at DESC 
        LIMIT :limit
    ");
    
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'user_id' => $user_id,
        'notifications' => $notifications,
        'count' => count($notifications)
    ]);
}

// ============================================
// FUNCTION: GET UNREAD COUNT
// ============================================
function handleGetUnreadCount($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE user_id = :user_id 
        AND is_read = 0 
        AND (expiry_date IS NULL OR expiry_date > NOW())
    ");
    
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'user_id' => $user_id,
        'count' => (int)$result['count']
    ]);
}

// ============================================
// FUNCTION: MARK NOTIFICATION AS READ
// ============================================
function handleMarkAsRead($pdo, $user_id) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Invalid JSON input');
        return;
    }
    
    $notification_id = isset($data['notification_id']) ? (int)$data['notification_id'] : 0;
    
    if (!$notification_id) {
        sendError('notification_id is required');
        return;
    }
    
    // Verify notification belongs to user
    $checkStmt = $pdo->prepare("
        SELECT id 
        FROM notifications 
        WHERE id = :notif_id AND user_id = :user_id
    ");
    $checkStmt->bindParam(':notif_id', $notification_id, PDO::PARAM_INT);
    $checkStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $checkStmt->execute();
    
    if (!$checkStmt->fetch()) {
        sendError('Notification not found or access denied', 404);
        return;
    }
    
    // Mark as read
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE id = :notif_id AND user_id = :user_id
    ");
    $stmt->bindParam(':notif_id', $notification_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $success = $stmt->execute();
    
    echo json_encode([
        'success' => $success,
        'message' => 'Notification marked as read'
    ]);
}

// ============================================
// FUNCTION: MARK ALL AS READ
// ============================================
function handleMarkAllAsRead($pdo, $user_id) {
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE user_id = :user_id 
        AND is_read = 0
    ");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $success = $stmt->execute();
    $affectedRows = $stmt->rowCount();
    
    echo json_encode([
        'success' => $success,
        'marked_count' => $affectedRows,
        'message' => "Marked {$affectedRows} notification(s) as read"
    ]);
}

// ============================================
// FUNCTION: DELETE NOTIFICATION
// ============================================
function handleDeleteNotification($pdo, $user_id) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Invalid JSON input');
        return;
    }
    
    $notification_id = isset($data['notification_id']) ? (int)$data['notification_id'] : 0;
    
    if (!$notification_id) {
        sendError('notification_id is required');
        return;
    }
    
    // Verify notification belongs to user
    $checkStmt = $pdo->prepare("
        SELECT id 
        FROM notifications 
        WHERE id = :notif_id AND user_id = :user_id
    ");
    $checkStmt->bindParam(':notif_id', $notification_id, PDO::PARAM_INT);
    $checkStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $checkStmt->execute();
    
    if (!$checkStmt->fetch()) {
        sendError('Notification not found or access denied', 404);
        return;
    }
    
    // Delete notification
    $stmt = $pdo->prepare("
        DELETE FROM notifications 
        WHERE id = :notif_id AND user_id = :user_id
    ");
    $stmt->bindParam(':notif_id', $notification_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $success = $stmt->execute();
    
    echo json_encode([
        'success' => $success,
        'message' => 'Notification deleted successfully'
    ]);
}

// ============================================
// FUNCTION: CREATE NOTIFICATION
// ============================================
function handleCreateNotification($pdo, $user_id) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Invalid JSON input');
        return;
    }
    
    $message = isset($data['message']) ? trim($data['message']) : '';
    $target_user_id = isset($data['target_user_id']) ? (int)$data['target_user_id'] : $user_id;
    $expiry_date = isset($data['expiry_date']) ? $data['expiry_date'] : null;
    
    if (empty($message)) {
        sendError('message is required');
        return;
    }
    
    // Check if current user is admin to allow creating notifications for others
    if ($target_user_id != $user_id) {
        $adminCheck = $pdo->prepare("SELECT id FROM admin WHERE id = :user_id");
        $adminCheck->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $adminCheck->execute();
        
        if (!$adminCheck->fetch()) {
            sendError('Only admins can create notifications for other users', 403);
            return;
        }
    }
    
    // Validate expiry date if provided
    if ($expiry_date && !validateDateTime($expiry_date)) {
        sendError('Invalid expiry_date format. Use Y-m-d H:i:s');
        return;
    }
    
    // Insert notification
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, message, expiry_date, created_at) 
        VALUES (:user_id, :message, :expiry_date, NOW())
    ");
    $stmt->bindParam(':user_id', $target_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':message', $message, PDO::PARAM_STR);
    $stmt->bindParam(':expiry_date', $expiry_date, PDO::PARAM_STR);
    $success = $stmt->execute();
    $notification_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => $success,
        'notification_id' => (int)$notification_id,
        'message' => 'Notification created successfully'
    ]);
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Send error response
 */
function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message
    ]);
    exit;
}

/**
 * Validate datetime string
 */
function validateDateTime($dateTime, $format = 'Y-m-d H:i:s') {
    $d = DateTime::createFromFormat($format, $dateTime);
    return $d && $d->format($format) === $dateTime;
}
