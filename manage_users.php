<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Helper function to convert to boolean
function toBool($value) {
    if (is_string($value)) {
        $value = strtolower($value);
        return in_array($value, ['true', '1', 'yes', 'on']);
    }
    return (bool)$value;
}

try {
    if (!file_exists('db_config.php')) {
        throw new Exception('Database configuration file not found');
    }
    $db_config = require 'db_config.php';

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            try {
                // Get all users with error handling
                $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
                if (!$stmt) {
                    throw new Exception('Failed to execute query');
                }
                
                $users = $stmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'users' => $users ?: [] // Return empty array if no users
                ]);
            } catch (Exception $e) {
                error_log("Error in GET request: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Error fetching users: ' . $e->getMessage()
                ]);
            }
            break;

        case 'POST':
            if (!isset($_POST['id'])) {
                throw new Exception('User ID is required');
            }
            
            $userId = filter_var($_POST['id'], FILTER_VALIDATE_INT);
            // Check if the filter failed (returns false) rather than checking if the value is truthy
            if ($userId === false) {
                throw new Exception('Invalid user ID format');
            }
            
            // Now we know $userId is a valid integer (which could be 0)

            $updates = [];
            $params = [];

            // Handle text fields
            $textFields = ['username', 'email', 'type', 'user_type', 'bio', 'verification_token'];
            foreach ($textFields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $updates[] = "`$field` = :$field";
                    $params[$field] = $_POST[$field];
                }
            }

            // Handle numeric fields
            $numericFields = ['points', 'rank', 'failed_attempts'];
            foreach ($numericFields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    if (!is_numeric($_POST[$field])) {
                        throw new Exception("$field must be a number");
                    }
                    $updates[] = "`$field` = :$field";
                    $params[$field] = (int)$_POST[$field];
                }
            }

            // Handle boolean fields
            if (isset($_POST['is_verified'])) {
                $updates[] = "is_verified = :is_verified";
                $params['is_verified'] = toBool($_POST['is_verified']) ? 1 : 0;
            }

            // Handle password
            if (isset($_POST['password']) && $_POST['password'] !== '') {
                $updates[] = "password = :password";
                $params['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }

            // Handle avatar upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/avatars/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                $fileType = $_FILES['avatar']['type'];
                if (!in_array($fileType, $allowedTypes)) {
                    throw new Exception('Invalid file type. Only JPEG, PNG, and GIF are allowed.');
                }

                $fileExt = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
                $fileName = 'avatar_' . uniqid() . '.' . $fileExt;
                $uploadPath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
                    // Delete old avatar if exists
                    $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = :id");
                    $stmt->execute(['id' => $userId]);
                    $oldAvatar = $stmt->fetchColumn();
                    
                    if ($oldAvatar && file_exists($_SERVER['DOCUMENT_ROOT'] . $oldAvatar)) {
                        unlink($_SERVER['DOCUMENT_ROOT'] . $oldAvatar);
                    }

                    $updates[] = "avatar = :avatar";
                    $params['avatar'] = '/' . $uploadPath;
                }
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            // Perform update
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $params['id'] = $userId;
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'message' => 'User updated successfully'
            ]);
            break;

        case 'DELETE':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['id'])) {
                throw new Exception('User ID is required');
            }

            $pdo->beginTransaction();

            try {
                // Get and delete avatar if exists
                $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = :id");
                $stmt->execute(['id' => $data['id']]);
                $avatar = $stmt->fetchColumn();

                // Delete user
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
                $stmt->execute(['id' => $data['id']]);

                if ($avatar && file_exists($_SERVER['DOCUMENT_ROOT'] . $avatar)) {
                    unlink($_SERVER['DOCUMENT_ROOT'] . $avatar);
                }

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'User deleted successfully'
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            throw new Exception('Method not allowed');
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}