<?php
// create_team.php

// CORS headers - MUST be at the very top
header("Access-Control-Allow-Origin: https://user.gnews.ma");
header("Access-Control-Allow-Methods: POST, OPTIONS, GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header("Content-Type: application/json");

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Check if request is multipart/form-data or JSON
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'multipart/form-data') !== false) {
        // FormData request
        $data = $_POST;
    } else {
        // JSON request
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: ' . json_last_error_msg());
        }
    }

    if (!$data || !isset($data['name']) || !isset($data['owner_id'])) {
        throw new Exception('Missing required fields: name and owner_id are required');
    }

    // Validate game_id
    if (!isset($data['game_id']) || empty($data['game_id'])) {
        throw new Exception('Missing required field: game_id');
    }

    // Generate a slug from the team name
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $data['name']));
    $slug = trim($slug, '-');

    // Create uploads directory if it doesn't exist
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/teams/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    // Handle logo upload from file or base64
    $logo_url = null;
    
    // Check if file was uploaded
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = $_FILES['logo']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            throw new Exception('Invalid logo file type. Only JPEG, PNG, GIF, and WEBP are allowed');
        }
        
        // Check file size (5MB max)
        if ($_FILES['logo']['size'] > 5 * 1024 * 1024) {
            throw new Exception('Logo file size must be less than 5MB');
        }
        
        $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $file_name = uniqid() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $file_path)) {
            $logo_url = '/uploads/teams/' . $file_name;
        }
    } 
    // Check if base64 logo was sent
    elseif (isset($data['logo']) && !empty($data['logo']) && strpos($data['logo'], 'data:image') === 0) {
        $image_parts = explode(";base64,", $data['logo']);
        if (count($image_parts) === 2) {
            $image_type_aux = explode("image/", $image_parts[0]);
            $image_type = $image_type_aux[1] ?? 'png';
            $image_base64 = base64_decode($image_parts[1]);

            if ($image_base64 !== false) {
                $file_name = uniqid() . '.' . $image_type;
                $file_path = $upload_dir . $file_name;

                if (file_put_contents($file_path, $image_base64)) {
                    $logo_url = '/uploads/teams/' . $file_name;
                }
            }
        }
    }

    // Handle banner upload from file or base64
    $banner_url = null;
    
    // Check if file was uploaded
    if (isset($_FILES['banner']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = $_FILES['banner']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            throw new Exception('Invalid banner file type. Only JPEG, PNG, GIF, and WEBP are allowed');
        }
        
        // Check file size (10MB max for banner)
        if ($_FILES['banner']['size'] > 10 * 1024 * 1024) {
            throw new Exception('Banner file size must be less than 10MB');
        }
        
        $file_extension = pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION);
        $file_name = 'banner_' . uniqid() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['banner']['tmp_name'], $file_path)) {
            $banner_url = '/uploads/teams/' . $file_name;
        }
    }
    // Check if base64 banner was sent
    elseif (isset($data['banner']) && !empty($data['banner']) && strpos($data['banner'], 'data:image') === 0) {
        $image_parts = explode(";base64,", $data['banner']);
        if (count($image_parts) === 2) {
            $image_type_aux = explode("image/", $image_parts[0]);
            $image_type = $image_type_aux[1] ?? 'png';
            $image_base64 = base64_decode($image_parts[1]);

            if ($image_base64 !== false) {
                $file_name = 'banner_' . uniqid() . '.' . $image_type;
                $file_path = $upload_dir . $file_name;

                if (file_put_contents($file_path, $image_base64)) {
                    $banner_url = '/uploads/teams/' . $file_name;
                }
            }
        }
    }

    // Start transaction
    $pdo->beginTransaction();

    try {
        // Check if a team with this name+game already exists
        $checkStmt = $pdo->prepare("SELECT id FROM teams WHERE name = :name AND game_id = :game_id");
        $checkStmt->execute([
            ':name' => $data['name'],
            ':game_id' => $data['game_id']
        ]);
        
        if ($checkStmt->rowCount() > 0) {
            throw new Exception('A team with this name already exists for this game');
        }
        
        // Check if a team with this tag+game already exists (only if tag is provided)
        if (isset($data['tag']) && !empty($data['tag'])) {
            $checkTagStmt = $pdo->prepare("SELECT id FROM teams WHERE tag = :tag AND game_id = :game_id");
            $checkTagStmt->execute([
                ':tag' => $data['tag'],
                ':game_id' => $data['game_id']
            ]);
            
            if ($checkTagStmt->rowCount() > 0) {
                throw new Exception('A team with this tag already exists for this game');
            }
        }

        // Insert team
        $stmt = $pdo->prepare("
            INSERT INTO teams (
                owner_id,
                name,
                tag,
                slug,
                game_id,
                description,
                logo,
                banner,
                division,
                tier,
                total_members,
                captain_id,
                discord,
                twitter,
                contact_email,
                created_at,
                updated_at
            ) VALUES (
                :owner_id,
                :name,
                :tag,
                :slug,
                :game_id,
                :description,
                :logo,
                :banner,
                :division,
                :tier,
                1,
                :captain_id,
                :discord,
                :twitter,
                :contact_email,
                NOW(),
                NOW()
            )
        ");

        $stmt->execute([
            ':owner_id' => $data['owner_id'],
            ':name' => $data['name'],
            ':tag' => $data['tag'] ?? '',
            ':slug' => $slug,
            ':game_id' => $data['game_id'],
            ':description' => $data['description'] ?? '',
            ':logo' => $logo_url,
            ':banner' => $banner_url,
            ':division' => $data['division'] ?? 'silver',
            ':tier' => $data['tier'] ?? 'amateur',
            ':captain_id' => $data['captain_id'] ?? $data['owner_id'],
            ':discord' => $data['discord'] ?? null,
            ':twitter' => $data['twitter'] ?? null,
            ':contact_email' => $data['contact_email'] ?? null
        ]);

        $teamId = $pdo->lastInsertId();

        // Insert team member
        $memberStmt = $pdo->prepare("
            INSERT INTO team_members (
                team_id,
                user_id,
                role,
                is_captain,
                join_date
            ) VALUES (
                :team_id,
                :user_id,
                :role,
                1,
                NOW()
            )
        ");

        $memberStmt->execute([
            ':team_id' => $teamId,
            ':user_id' => $data['owner_id'],
            ':role' => 'Captain'
        ]);

        // Activity log (optional)
        try {
            $logStmt = $pdo->prepare("
                INSERT INTO activity_log (
                    user_id,
                    action,
                    details,
                    ip_address
                ) VALUES (
                    :user_id,
                    'Team Created',
                    :details,
                    :ip_address
                )
            ");

            $logStmt->execute([
                ':user_id' => $data['owner_id'],
                ':details' => 'Created team: ' . $data['name'],
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '::1'
            ]);
        } catch (PDOException $e) {
            error_log("Activity log error: " . $e->getMessage());
        }

        $pdo->commit();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Team created successfully',
            'team_id' => $teamId,
            'slug' => $slug,
            'logo_url' => $logo_url,
            'banner_url' => $banner_url
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
