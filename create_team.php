<?php
// create_team.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get JSON data
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || !isset($data['name']) || !isset($data['owner_id'])) {
        throw new Exception('Missing required fields');
    }

    // Generate a slug from the team name
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $data['name']));
    $slug = trim($slug, '-');

    // Handle logo upload
    $logo_url = null;
    if (isset($data['logo']) && !empty($data['logo'])) {
        // Create uploads directory if it doesn't exist
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/teams/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Process base64 image
        $image_parts = explode(";base64,", $data['logo']);
        $image_type_aux = explode("image/", $image_parts[0]);
        $image_type = $image_type_aux[1];
        $image_base64 = base64_decode($image_parts[1]);

        // Generate unique filename
        $file_name = uniqid() . '.' . $image_type;
        $file_path = $upload_dir . $file_name;

        // Save image
        if (file_put_contents($file_path, $image_base64)) {
            $logo_url = '/uploads/teams/' . $file_name;
        }
    }

    // Handle banner upload
    $banner_url = null;
    if (isset($data['banner']) && !empty($data['banner'])) {
        // Create uploads directory if it doesn't exist
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/teams/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Process base64 image
        $image_parts = explode(";base64,", $data['banner']);
        $image_type_aux = explode("image/", $image_parts[0]);
        $image_type = $image_type_aux[1];
        $image_base64 = base64_decode($image_parts[1]);

        // Generate unique filename
        $file_name = 'banner_' . uniqid() . '.' . $image_type;
        $file_path = $upload_dir . $file_name;

        // Save image
        if (file_put_contents($file_path, $image_base64)) {
            $banner_url = '/uploads/teams/' . $file_name;
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
        
        // Check if a team with this tag+game already exists
        $checkTagStmt = $pdo->prepare("SELECT id FROM teams WHERE tag = :tag AND game_id = :game_id");
        $checkTagStmt->execute([
            ':tag' => $data['tag'],
            ':game_id' => $data['game_id']
        ]);
        
        if ($checkTagStmt->rowCount() > 0) {
            throw new Exception('A team with this tag already exists for this game');
        }

        // Insert team with updated fields to match the teams table structure
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

        $result = $stmt->execute([
            ':owner_id' => $data['owner_id'],
            ':name' => $data['name'],
            ':tag' => $data['tag'],
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

        // Now insert a record in the team_members table
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

        // Update the activity log
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

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Team created successfully',
            'team_id' => $teamId,
            'logo_url' => $logo_url,
            'banner_url' => $banner_url
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}