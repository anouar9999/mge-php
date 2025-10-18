<?php
// test_send_notification.php
// Place this file in your api/ folder and access it directly via browser

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

// Prevent errors from displaying
ini_set('display_errors', 1);
error_reporting(E_ALL);

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

    // Get user_id from URL parameter (default to 126)
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 126;
    
    // Get custom message or use default
    $message = isset($_GET['message']) 
        ? $_GET['message'] 
        : 'Test Notification: This is a test message sent at ' . date('Y-m-d H:i:s');

    // Get notification type for better categorization
    $type = isset($_GET['type']) ? $_GET['type'] : 'test';
    
    // Add prefix based on type
    switch($type) {
        case 'tournament':
            $message = 'Tournament Alert: ' . $message;
            break;
        case 'team':
            $message = 'Team Notification: ' . $message;
            break;
        case 'match':
            $message = 'Match Update: ' . $message;
            break;
        case 'message':
            $message = 'New Message: ' . $message;
            break;
        default:
            $message = 'Test Notification: ' . $message;
    }

    // Check if user exists
    $checkUser = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
    $checkUser->execute([$user_id]);
    $user = $checkUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode([
            'success' => false,
            'error' => "User with ID {$user_id} not found"
        ]);
        exit;
    }

    // Insert notification
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, message, is_read, created_at) 
        VALUES (?, ?, 0, NOW())
    ");
    
    $success = $stmt->execute([$user_id, $message]);
    $notification_id = $pdo->lastInsertId();

    if ($success) {
        // Get the created notification
        $getNotif = $pdo->prepare("SELECT * FROM notifications WHERE id = ?");
        $getNotif->execute([$notification_id]);
        $notification = $getNotif->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Notification sent successfully!',
            'notification_id' => $notification_id,
            'user_id' => $user_id,
            'username' => $user['username'],
            'notification' => $notification,
            'preview' => [
                'id' => $notification['id'],
                'message' => $notification['message'],
                'created_at' => $notification['created_at']
            ]
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to insert notification'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Notification Sender</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }
        input, textarea, select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        textarea {
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        button:active {
            transform: translateY(0);
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            display: none;
        }
        .result.success {
            background: #d4edda;
            border: 2px solid #28a745;
            color: #155724;
        }
        .result.error {
            background: #f8d7da;
            border: 2px solid #dc3545;
            color: #721c24;
        }
        .result pre {
            margin-top: 10px;
            background: rgba(0,0,0,0.05);
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
        }
        .quick-links {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
        }
        .quick-links h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #333;
        }
        .quick-links a {
            display: inline-block;
            margin-right: 10px;
            margin-bottom: 10px;
            padding: 8px 16px;
            background: #f0f0f0;
            color: #333;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            transition: all 0.2s;
        }
        .quick-links a:hover {
            background: #667eea;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔔 Test Notification Sender</h1>
        <p class="subtitle">Send a test notification to any user</p>
        
        <form id="notificationForm">
            <div class="form-group">
                <label for="user_id">User ID</label>
                <input type="number" id="user_id" name="user_id" value="126" required>
            </div>

            <div class="form-group">
                <label for="type">Notification Type</label>
                <select id="type" name="type">
                    <option value="test">Test</option>
                    <option value="tournament">Tournament</option>
                    <option value="team">Team</option>
                    <option value="match">Match</option>
                    <option value="message">Message</option>
                </select>
            </div>

            <div class="form-group">
                <label for="message">Message</label>
                <textarea id="message" name="message" placeholder="Enter your notification message here...">Your registration has been approved! Welcome to the tournament.</textarea>
            </div>

            <button type="submit">Send Notification</button>
        </form>

        <div id="result" class="result"></div>

        <div class="quick-links">
            <h3>Quick Actions:</h3>
            <a href="?user_id=126&type=tournament&message=Summer Championship starts tomorrow!">Tournament Alert</a>
            <a href="?user_id=126&type=team&message=You have been invited to join Team Alpha">Team Invite</a>
            <a href="?user_id=126&type=match&message=Your match is scheduled for 3 PM today">Match Update</a>
            <a href="?user_id=126&type=message&message=You have a new message from admin">New Message</a>
        </div>
    </div>

    <script>
        document.getElementById('notificationForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const userId = document.getElementById('user_id').value;
            const type = document.getElementById('type').value;
            const message = document.getElementById('message').value;
            
            const resultDiv = document.getElementById('result');
            resultDiv.style.display = 'block';
            resultDiv.className = 'result';
            resultDiv.innerHTML = '<strong>Sending...</strong>';
            
            try {
                const url = `?user_id=${userId}&type=${type}&message=${encodeURIComponent(message)}`;
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    resultDiv.className = 'result success';
                    resultDiv.innerHTML = `
                        <strong>✅ Success!</strong><br>
                        Notification sent to user <strong>${data.username}</strong> (ID: ${data.user_id})<br>
                        Notification ID: ${data.notification_id}
                        <pre>${JSON.stringify(data.preview, null, 2)}</pre>
                    `;
                } else {
                    resultDiv.className = 'result error';
                    resultDiv.innerHTML = `
                        <strong>❌ Error!</strong><br>
                        ${data.error}
                    `;
                }
            } catch (error) {
                resultDiv.className = 'result error';
                resultDiv.innerHTML = `
                    <strong>❌ Error!</strong><br>
                    ${error.message}
                `;
            }
        });

        // Auto-submit if URL has parameters
        if (window.location.search.includes('user_id')) {
            document.getElementById('result').style.display = 'block';
        }
    </script>
</body>
</html>
