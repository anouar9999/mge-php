<?php
// api/notifications.php - FIXED VERSION WITH UTF8MB4 SUPPORT

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

ini_set('display_errors', 0);
error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db_config = require 'db_config.php';

    // IMPORTANT: Add charset=utf8mb4 to PDO connection
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4" . 
        (isset($db_config['port']) ? ";port={$db_config['port']}" : ""),
        $db_config['user'],
        $db_config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );

    $user_id = null;

    if (isset($_GET['user_id'])) {
        $user_id = (int)$_GET['user_id'];
    }

    if (!$user_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (isset($data['user_id'])) {
            $user_id = (int)$data['user_id'];
        }
    }

    if (!$user_id) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['user_id'])) {
            $user_id = (int)$_SESSION['user_id'];
        }
    }

    if (!$user_id) {
        sendError('Unauthorized. Please provide user_id.', 401);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? trim($_GET['action']) : '';

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
    error_log('Notification API error: ' . $e->getMessage());
    sendError('An error occurred: ' . $e->getMessage(), 500);
}

function handleGetNotifications($pdo, $user_id) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    
    if ($limit < 1) $limit = 1;
    if ($limit > 100) $limit = 100;
    if ($offset < 0) $offset = 0;
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            user_id,
            message,
            sender_name,
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
    ], JSON_UNESCAPED_UNICODE);
}

function handleGetUnreadNotifications($pdo, $user_id) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    if ($limit < 1) $limit = 1;
    if ($limit > 50) $limit = 50;
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            user_id,
            message,
            sender_name,
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
    ], JSON_UNESCAPED_UNICODE);
}

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
    ], JSON_UNESCAPED_UNICODE);
}

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
    ], JSON_UNESCAPED_UNICODE);
}

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
    ], JSON_UNESCAPED_UNICODE);
}

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
    ], JSON_UNESCAPED_UNICODE);
}

function handleCreateNotification($pdo, $user_id) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Invalid JSON input');
        return;
    }
    
    $message = isset($data['message']) ? trim($data['message']) : '';
    $sender_name = isset($data['sender_name']) ? trim($data['sender_name']) : null;
    $target_user_id = isset($data['target_user_id']) ? (int)$data['target_user_id'] : $user_id;
    $expiry_date = isset($data['expiry_date']) ? $data['expiry_date'] : null;
    
    if (empty($message)) {
        sendError('message is required');
        return;
    }
    
    // Strip any emoji characters from message as safety measure
    $message = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $message);
    $message = trim($message);
    
    if (empty($message)) {
        sendError('message cannot be empty after sanitization');
        return;
    }
    
    if ($target_user_id != $user_id) {
        $adminCheck = $pdo->prepare("SELECT id FROM admin WHERE id = :user_id");
        $adminCheck->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $adminCheck->execute();
        
        if (!$adminCheck->fetch()) {
            sendError('Only admins can create notifications for other users', 403);
            return;
        }
    }
    
    if ($expiry_date && !validateDateTime($expiry_date)) {
        sendError('Invalid expiry_date format. Use Y-m-d H:i:s');
        return;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, message, sender_name, expiry_date, created_at) 
        VALUES (:user_id, :message, :sender_name, :expiry_date, NOW())
    ");
    $stmt->bindParam(':user_id', $target_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':message', $message, PDO::PARAM_STR);
    $stmt->bindParam(':sender_name', $sender_name, PDO::PARAM_STR);
    $stmt->bindParam(':expiry_date', $expiry_date, PDO::PARAM_STR);
    $success = $stmt->execute();
    $notification_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => $success,
        'notification_id' => (int)$notification_id,
        'message' => 'Notification created successfully'
    ], JSON_UNESCAPED_UNICODE);
}

function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function validateDateTime($dateTime, $format = 'Y-m-d H:i:s') {
    $d = DateTime::createFromFormat($format, $dateTime);
    return $d && $d->format($format) === $dateTime;
}