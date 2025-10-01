<?php
// edit_team.php - Complete rewrite
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Set content type header for responses
header('Content-Type: application/json');

// Load database configuration
$db_config = require 'db_config.php';

// Detailed error logging
function logDebug($message, $data = null) {
    $logMessage = date('[Y-m-d H:i:s]') . " $message";
    if ($data !== null) {
        $logMessage .= ": " . print_r($data, true);
    }
    error_log($logMessage);
}

try {
    // Establish database connection
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Handle POST and PUT requests for team updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
        logDebug("Processing " . $_SERVER['REQUEST_METHOD'] . " request");
        
        // Get request data - handle both form data and JSON
        $data = [];
        $uploadedLogo = null;
        
        // Process form data (multipart/form-data)
        if (!empty($_POST)) {
            logDebug("Processing form data", $_POST);
            $data = $_POST;
            
            // Check for file upload
            if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                logDebug("File upload detected", $_FILES['logo']);
                $uploadedLogo = $_FILES['logo'];
            }
        } 
        // Process JSON data
        else {
            $input = file_get_contents('php://input');
            logDebug("Raw input", $input);
            
            if (!empty($input)) {
                $jsonData = json_decode($input, true);
                if ($jsonData !== null) {
                    logDebug("Parsed JSON data", $jsonData);
                    $data = $jsonData;
                } else {
                    logDebug("Failed to parse JSON: " . json_last_error_msg());
                }
            } else {
                logDebug("No input data received");
            }
        }
        
        // Validate team ID
        if (empty($data['id'])) {
            logDebug("Team ID is missing");
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Team ID is required']);
            exit();
        }
        
        $teamId = (int)$data['id'];
        logDebug("Team ID", $teamId);
        
        // First, check if the team exists
        $checkStmt = $pdo->prepare("SELECT * FROM teams WHERE id = ?");
        $checkStmt->execute([$teamId]);
        $team = $checkStmt->fetch();
        
        if (!$team) {
            logDebug("Team not found", $teamId);
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Team not found']);
            exit();
        }
        
        // Define allowed fields for update based on your database schema
        $allowedFields = [
            'name',
            'tag',
            'game_id',
            'description',
            'division',
            'tier',
            'discord',
            'twitter',
            'contact_email'
        ];
        
        // Build update data
        $updates = [];
        $updateParams = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $updates[] = "{$field} = ?";
                $updateParams[] = $data[$field];
            }
        }
        
        // Process logo upload if present
        if ($uploadedLogo) {
            $uploadDir = __DIR__ . '/../uploads/teams/';
            
            // Create directory if it doesn't exist
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $fileName = uniqid() . '.' . pathinfo($uploadedLogo['name'], PATHINFO_EXTENSION);
            $filePath = $uploadDir . $fileName;
            $fileUrl = '/uploads/teams/' . $fileName;
            
            // Move uploaded file
            if (move_uploaded_file($uploadedLogo['tmp_name'], $filePath)) {
                logDebug("File uploaded successfully", $fileUrl);
                $updates[] = "logo = ?";
                $updateParams[] = $fileUrl;
            } else {
                logDebug("File upload failed", error_get_last());
            }
        }
        
        // If nothing to update, return error
        if (empty($updates)) {
            logDebug("No valid fields to update");
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No valid fields to update']);
            exit();
        }
        
        // Add updated_at
        $updates[] = "updated_at = NOW()";
        
        // Add team ID to parameters array
        $updateParams[] = $teamId;
        
        // Prepare and execute update query
        $query = "UPDATE teams SET " . implode(', ', $updates) . " WHERE id = ?";
        logDebug("Update query", $query);
        logDebug("Update parameters", $updateParams);
        
        $stmt = $pdo->prepare($query);
        $result = $stmt->execute($updateParams);
        
        if ($result) {
            // Fetch the updated team to return
            $selectStmt = $pdo->prepare("SELECT * FROM teams WHERE id = ?");
            $selectStmt->execute([$teamId]);
            $updatedTeam = $selectStmt->fetch();
            
            // Success response
            echo json_encode([
                'success' => true, 
                'message' => 'Team updated successfully',
                'data' => $updatedTeam
            ]);
        } else {
            logDebug("Update failed");
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update team']);
        }
    } 
    // Handle unsupported methods
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    logDebug("Database error", $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    logDebug("General error", $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>