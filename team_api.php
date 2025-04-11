<?php
// team_api.php

// Enable error reporting for development
ini_set('display_errors', 1);
error_reporting(E_ALL);

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
$db_config = require 'db_config.php';

// Response helper function
function sendResponse($success, $data = null, $message = null, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit();
}

// Main API logic
try {
    // Connect to database
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get endpoint from query parameter
    $endpoint = $_GET['endpoint'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    // Get request body for POST/PUT requests
    $requestBody = null;
    if ($method === 'POST' || $method === 'PUT') {
        $requestBody = json_decode(file_get_contents('php://input'), true);
    }

    // Main routing logic
    switch ($endpoint) {
        case 'team-stats':
            if ($method !== 'GET') {
                sendResponse(false, null, 'Method not allowed', 405);
            }
            handleGetTeamStats($pdo);
            break;

        case 'team-members':
            handleTeamMembers($pdo, $method, $requestBody);
            break;

        case 'team-requests':
            handleTeamRequests($pdo, $method, $requestBody);
            break;

        case 'team-settings':
            handleTeamSettings($pdo, $method, $requestBody);
            break;
            
        case 'join-request':
            handleJoinRequest($pdo, $method, $requestBody);
            break;

        default:
            sendResponse(false, null, 'Endpoint not found', 404);
    }
} catch (PDOException $e) {
    sendResponse(false, null, 'Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    sendResponse(false, null, $e->getMessage(), 500);
}

// Team Stats Handler
function handleGetTeamStats($pdo)
{
    $teamId = $_GET['team_id'] ?? null;
    if (!$teamId) {
        sendResponse(false, null, 'Team ID is required', 400);
    }

    try {
        $query = "
            SELECT 
                t.*,
                COUNT(DISTINCT tm.id) as total_members,
                COUNT(DISTINCT tjr.id) as pending_requests
            FROM teams t
            LEFT JOIN team_members tm ON t.id = tm.team_id
            LEFT JOIN team_join_requests tjr ON t.id = tjr.team_id AND tjr.status = 'pending'
            WHERE t.id = ?
            GROUP BY t.id
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([$teamId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stats) {
            sendResponse(false, null, 'Team not found', 404);
        }

        sendResponse(true, $stats);
    } catch (Exception $e) {
        sendResponse(false, null, $e->getMessage(), 500);
    }
}

// Team Members Handler
function handleTeamMembers($pdo, $method, $requestBody = null)
{
    switch ($method) {
        case 'GET':
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                sendResponse(false, null, 'Team ID is required', 400);
            }

            try {
                // First get team info
                $teamQuery = "
                    SELECT 
                        t.*,
                        u.username as owner_name,
                        u.email as owner_email,
                        u.avatar as owner_avatar
                    FROM teams t
                    LEFT JOIN users u ON t.owner_id = u.id
                    WHERE t.id = ?
                ";

                $teamStmt = $pdo->prepare($teamQuery);
                $teamStmt->execute([$teamId]);
                $teamInfo = $teamStmt->fetch(PDO::FETCH_ASSOC);

                if (!$teamInfo) {
                    sendResponse(false, null, 'Team not found', 404);
                }

                // Then get members with their info - Updated to match the actual database schema
                $memberQuery = "
                    SELECT 
                        tm.id,
                        tm.user_id,
                        tm.role,
                        tm.is_captain,
                        tm.join_date,
                        u.username,
                        u.avatar,
                        CASE 
                            WHEN t.owner_id = u.id THEN 'Captain'
                            WHEN tm.is_captain = 1 THEN 'Captain'
                            ELSE 'Member'
                        END as position
                    FROM team_members tm
                    LEFT JOIN teams t ON tm.team_id = t.id
                    LEFT JOIN users u ON tm.user_id = u.id
                    WHERE tm.team_id = ?
                    ORDER BY 
                        CASE 
                            WHEN t.owner_id = u.id THEN 0
                            WHEN tm.is_captain = 1 THEN 1
                            ELSE 2
                        END,
                        tm.join_date DESC
                ";

                $memberStmt = $pdo->prepare($memberQuery);
                $memberStmt->execute([$teamId]);
                $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

                // Get total member count
                $countQuery = "SELECT COUNT(*) FROM team_members WHERE team_id = ?";
                $countStmt = $pdo->prepare($countQuery);
                $countStmt->execute([$teamId]);
                $memberCount = $countStmt->fetchColumn();

                // Combine all information
                $response = [
                    'team_info' => $teamInfo,
                    'members' => $members,
                    'total_members' => $memberCount
                ];

                // Log the response for debugging
                error_log('Team members response: ' . json_encode($response));

                sendResponse(true, $response);
            } catch (Exception $e) {
                error_log('Error in handleTeamMembers: ' . $e->getMessage());
                sendResponse(false, null, 'Failed to fetch team members: ' . $e->getMessage(), 500);
            }
            break;

        case 'POST':
            if (!isset($requestBody['team_id'], $requestBody['user_id'], $requestBody['role'])) {
                sendResponse(false, null, 'Missing required fields', 400);
            }

            try {
                // Check if the user is already a member
                $checkQuery = "SELECT id FROM team_members WHERE team_id = ? AND user_id = ?";
                $checkStmt = $pdo->prepare($checkQuery);
                $checkStmt->execute([$requestBody['team_id'], $requestBody['user_id']]);
                
                if ($checkStmt->rowCount() > 0) {
                    sendResponse(false, null, 'User is already a member of this team', 400);
                }
                
                $query = "
                    INSERT INTO team_members (team_id, user_id, role, is_captain, join_date)
                    VALUES (?, ?, ?, ?, NOW())
                ";

                $stmt = $pdo->prepare($query);
                $result = $stmt->execute([
                    $requestBody['team_id'],
                    $requestBody['user_id'],
                    $requestBody['role'],
                    $requestBody['is_captain'] ?? 0
                ]);

                if (!$result) {
                    throw new Exception('Failed to add member');
                }

                sendResponse(true, null, 'Member added successfully');
            } catch (Exception $e) {
                error_log('Error adding team member: ' . $e->getMessage());
                sendResponse(false, null, 'Failed to add member: ' . $e->getMessage(), 500);
            }
            break;

        case 'DELETE':
            $memberId = $_GET['member_id'] ?? null;
            if (!$memberId) {
                sendResponse(false, null, 'Member ID is required', 400);
            }

            try {
                $query = "DELETE FROM team_members WHERE id = ?";
                $stmt = $pdo->prepare($query);
                $result = $stmt->execute([$memberId]);

                if (!$result) {
                    throw new Exception('Failed to remove member');
                }

                if ($stmt->rowCount() === 0) {
                    sendResponse(false, null, 'Member not found', 404);
                }

                sendResponse(true, null, 'Member removed successfully');
            } catch (Exception $e) {
                error_log('Error removing team member: ' . $e->getMessage());
                sendResponse(false, null, 'Failed to remove member: ' . $e->getMessage(), 500);
            }
            break;

        default:
            sendResponse(false, null, 'Method not allowed', 405);
    }
}

// Team Requests Handler
function handleTeamRequests($pdo, $method, $requestBody = null)
{
    switch ($method) {
        case 'GET':
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                sendResponse(false, null, 'Team ID is required', 400);
            }

            try {
                $query = "
                    SELECT 
                        tjr.*,
                        u.username,
                        u.avatar
                    FROM team_join_requests tjr
                    LEFT JOIN users u ON tjr.name = u.username
                    WHERE tjr.team_id = ? AND tjr.status = 'pending'
                    ORDER BY tjr.created_at DESC
                ";

                $stmt = $pdo->prepare($query);
                $stmt->execute([$teamId]);
                $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

                sendResponse(true, $requests);
            } catch (Exception $e) {
                error_log('Error fetching team requests: ' . $e->getMessage());
                sendResponse(false, null, 'Failed to fetch team requests: ' . $e->getMessage(), 500);
            }
            break;

        case 'POST':
            error_log('Processing team request with data: ' . json_encode($requestBody));

            if (!isset($requestBody['request_id'], $requestBody['action'])) {
                sendResponse(false, null, 'Missing required fields', 400);
            }

            $pdo->beginTransaction();
            try {
                // Get request details
                $requestQuery = "
                    SELECT tjr.*, u.avatar 
                    FROM team_join_requests tjr
                    LEFT JOIN users u ON tjr.name = u.username
                    WHERE tjr.id = ? AND tjr.status = 'pending'
                ";
                $requestStmt = $pdo->prepare($requestQuery);
                $requestStmt->execute([$requestBody['request_id']]);
                $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

                if (!$request) {
                    throw new Exception('Request not found or already processed');
                }

                error_log('Found request details: ' . json_encode($request));

                if ($requestBody['action'] === 'accepted') {
                    // Check if already a member
                    $memberCheckQuery = "
                        SELECT id FROM team_members 
                        WHERE team_id = ? AND name = ?
                    ";
                    $memberCheckStmt = $pdo->prepare($memberCheckQuery);
                    $memberCheckStmt->execute([$request['team_id'], $request['name']]);

                    if ($memberCheckStmt->rowCount() > 0) {
                        throw new Exception('User is already a team member');
                    }

                    // Add to team members
                    $addMemberQuery = "
                        INSERT INTO team_members (
                            team_id,
                            name,
                            role,
                            avatar_url,
                            `rank`,
                            status,
                            created_at
                        ) VALUES (?, ?, ?, ?, ?, 'online', NOW())
                    ";

                    $memberParams = [
                        $request['team_id'],
                        $request['name'],
                        $request['role'],
                        $request['avatar_url'] ?? null,
                        $request['rank']
                    ];

                    error_log('Adding member with params: ' . json_encode($memberParams));

                    $addMemberStmt = $pdo->prepare($addMemberQuery);
                    if (!$addMemberStmt->execute($memberParams)) {
                        error_log('SQL Error: ' . json_encode($addMemberStmt->errorInfo()));
                        throw new Exception('Failed to add member to team');
                    }
                }

                // Update request status
                $status = $requestBody['action'] === 'accepted' ? 'accepted' : 'rejected';
                $updateQuery = "
                    UPDATE team_join_requests 
                    SET status = ? 
                    WHERE id = ?
                ";
                $updateStmt = $pdo->prepare($updateQuery);

                if (!$updateStmt->execute([$status, $requestBody['request_id']])) {
                    error_log('SQL Error: ' . json_encode($updateStmt->errorInfo()));
                    throw new Exception('Failed to update request status');
                }

                $pdo->commit();
                sendResponse(true, null, 'Request ' . $status . ' successfully');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Error in request processing: ' . $e->getMessage());
                sendResponse(false, null, $e->getMessage(), 500);
            }
            break;

        default:
            sendResponse(false, null, 'Method not allowed', 405);
    }
}

// Team Settings Handler
function handleTeamSettings($pdo, $method, $requestBody = null)
{
    switch ($method) {
        case 'GET':
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                sendResponse(false, null, 'Team ID is required', 400);
            }

            try {
                $query = "
                    SELECT 
                        t.*,
                        u.username as owner_username,
                        u.avatar as owner_avatar
                    FROM teams t
                    LEFT JOIN users u ON t.owner_id = u.id
                    WHERE t.id = ?
                ";

                $stmt = $pdo->prepare($query);
                $stmt->execute([$teamId]);
                $settings = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$settings) {
                    sendResponse(false, null, 'Team not found', 404);
                }

                sendResponse(true, $settings);
            } catch (Exception $e) {
                error_log('Error fetching team settings: ' . $e->getMessage());
                sendResponse(false, null, 'Failed to fetch team settings: ' . $e->getMessage(), 500);
            }
            break;

        case 'PUT':
            if (!isset($requestBody['team_id'])) {
                sendResponse(false, null, 'Team ID is required', 400);
            }

            $allowedFields = [
                'name',
                'description',
                'privacy_level',
                'division',
                'average_rank',
                'team_game',
                'tag'  // Add tag field which appears in the database schema
            ];

            $updates = array_filter($requestBody, function ($key) use ($allowedFields) {
                return in_array($key, $allowedFields);
            }, ARRAY_FILTER_USE_KEY);

            if (empty($updates)) {
                sendResponse(false, null, 'No valid fields to update', 400);
            }

            // Check for valid games from database
            if (isset($updates['team_game'])) {
                try {
                    $gameQuery = "SELECT id FROM games WHERE name = ? AND is_active = 1";
                    $gameStmt = $pdo->prepare($gameQuery);
                    $gameStmt->execute([$updates['team_game']]);
                    
                    if ($gameStmt->rowCount() === 0) {
                        sendResponse(false, null, 'Invalid game selection', 400);
                    }
                } catch (Exception $e) {
                    error_log('Error checking game: ' . $e->getMessage());
                }
            }

            try {
                $setClauses = array_map(function ($field) {
                    return "{$field} = ?";
                }, array_keys($updates));

                $query = "UPDATE teams SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE id = ?";
                $params = array_merge(array_values($updates), [$requestBody['team_id']]);

                $stmt = $pdo->prepare($query);
                $result = $stmt->execute($params);

                if (!$result) {
                    throw new Exception('Failed to update team settings');
                }

                if ($stmt->rowCount() === 0) {
                    sendResponse(false, null, 'Team not found or no changes made', 404);
                }

                sendResponse(true, null, 'Settings updated successfully');
            } catch (Exception $e) {
                error_log('Error updating team settings: ' . $e->getMessage());
                sendResponse(false, null, 'Failed to update settings: ' . $e->getMessage(), 500);
            }
            break;

        case 'DELETE':
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                sendResponse(false, null, 'Team ID is required', 400);
            }

            $pdo->beginTransaction();
            try {
                // Delete team members
                $stmt = $pdo->prepare("DELETE FROM team_members WHERE team_id = ?");
                $stmt->execute([$teamId]);

                // Delete join requests
                $stmt = $pdo->prepare("DELETE FROM team_join_requests WHERE team_id = ?");
                $stmt->execute([$teamId]);

                // Delete team
                $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ?");
                $stmt->execute([$teamId]);

                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    sendResponse(false, null, 'Team not found', 404);
                }

                $pdo->commit();
                sendResponse(true, null, 'Team deleted successfully');
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Error deleting team: ' . $e->getMessage());
                sendResponse(false, null, 'Failed to delete team: ' . $e->getMessage(), 500);
            }
            break;

        default:
            sendResponse(false, null, 'Method not allowed', 405);
    }
}

function handleJoinRequest($pdo, $method, $requestBody = null)
{
    if ($method !== 'POST') {
        sendResponse(false, null, 'Method not allowed', 405);
    }

    // Validate required fields
    if (!isset($requestBody['team_id'], $requestBody['user_id'])) {
        sendResponse(false, null, 'Missing required fields', 400);
    }

    try {
        // First check if user already has a pending request
        $checkQuery = "
            SELECT id FROM team_join_requests 
            WHERE team_id = ? AND name = (SELECT username FROM users WHERE id = ?) 
            AND status = 'pending'
        ";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$requestBody['team_id'], $requestBody['user_id']]);

        if ($checkStmt->rowCount() > 0) {
            sendResponse(false, null, 'You already have a pending request for this team', 400);
        }

        // Check if user is already a member
        $memberCheckQuery = "
            SELECT id FROM team_members 
            WHERE team_id = ? AND name = (SELECT username FROM users WHERE id = ?)
        ";
        $memberCheckStmt = $pdo->prepare($memberCheckQuery);
        $memberCheckStmt->execute([$requestBody['team_id'], $requestBody['user_id']]);

        if ($memberCheckStmt->rowCount() > 0) {
            sendResponse(false, null, 'You are already a member of this team', 400);
        }

        // Get user details
        $userQuery = "SELECT username, avatar, bio as experience FROM users WHERE id = ?";
        $userStmt = $pdo->prepare($userQuery);
        $userStmt->execute([$requestBody['user_id']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            sendResponse(false, null, 'User not found', 404);
        }

        // Insert join request with corrected parameters
        $insertQuery = "
            INSERT INTO team_join_requests (
                team_id, 
                name, 
                avatar_url,
                role, 
                `rank`, 
                experience,
                status, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ";

        $stmt = $pdo->prepare($insertQuery);
        $result = $stmt->execute([
            $requestBody['team_id'],
            $user['username'],
            $user['avatar'],
            $requestBody['role'] ?? 'Mid',
            $requestBody['rank'] ?? 'Unranked',
            $user['experience'] ?? 'No experience listed'
        ]);

        if (!$result) {
            throw new Exception('Failed to insert join request');
        }

        sendResponse(true, null, 'Join request sent successfully');
    } catch (Exception $e) {
        error_log("Join request error: " . $e->getMessage());
        sendResponse(false, null, 'Failed to send join request: ' . $e->getMessage(), 500);
    }
}