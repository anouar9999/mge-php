<?php
// reset_password.php - Reset password with token
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Validate token (for displaying reset form)
        if (!isset($_GET['token'])) {
            throw new Exception('Reset token is required');
        }

        $token = $_GET['token'];

        // Check if token exists and is valid
        $stmt = $pdo->prepare("
            SELECT prt.`user_id`, prt.`expires`, u.`username`, u.`email`
            FROM `password_reset_tokens` prt
            JOIN `users` u ON prt.`user_id` = u.`id`
            WHERE prt.`token` = :token
        ");
        $stmt->execute([':token' => $token]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenData) {
            throw new Exception('Invalid or expired reset token');
        }

        // Check if token has expired
        if (strtotime($tokenData['expires']) < time()) {
            // Delete expired token
            $deleteStmt = $pdo->prepare("DELETE FROM `password_reset_tokens` WHERE `token` = :token");
            $deleteStmt->execute([':token' => $token]);
            
            throw new Exception('Reset token has expired. Please request a new password reset.');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Token is valid',
            'data' => [
                'username' => $tokenData['username'],
                'email' => $tokenData['email'],
                'token' => $token
            ]
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Reset password
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['token']) || !isset($data['password'])) {
            throw new Exception('Token and new password are required');
        }

        $token = $data['token'];
        $newPassword = $data['password'];
        $confirmPassword = $data['confirm_password'] ?? '';

        // Validate password
        if (empty($newPassword)) {
            throw new Exception('Password cannot be empty');
        }

        if (strlen($newPassword) < 6) {
            throw new Exception('Password must be at least 6 characters long');
        }

        // Additional password strength validation
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{6,}$/', $newPassword)) {
            throw new Exception('Password must contain at least one uppercase letter, one lowercase letter, and one number');
        }

        if ($newPassword !== $confirmPassword) {
            throw new Exception('Passwords do not match');
        }

        // Check if token exists and is valid
        $stmt = $pdo->prepare("
            SELECT `user_id`, `expires`
            FROM `password_reset_tokens`
            WHERE `token` = :token
        ");
        $stmt->execute([':token' => $token]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenData) {
            throw new Exception('Invalid reset token');
        }

        // Check if token has expired
        if (strtotime($tokenData['expires']) < time()) {
            // Delete expired token
            $deleteStmt = $pdo->prepare("DELETE FROM `password_reset_tokens` WHERE `token` = :token");
            $deleteStmt->execute([':token' => $token]);
            
            throw new Exception('Reset token has expired. Please request a new password reset.');
        }

        $userId = $tokenData['user_id'];

        // Begin transaction
        $pdo->beginTransaction();

        try {
            // Update user password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare("UPDATE `users` SET `password` = :password WHERE `id` = :id");
            $updateStmt->execute([
                ':password' => $hashedPassword,
                ':id' => $userId
            ]);

            // Verify password was updated
            if ($updateStmt->rowCount() === 0) {
                throw new Exception('Failed to update password. User not found.');
            }

            // Delete the used token
            $deleteStmt = $pdo->prepare("DELETE FROM `password_reset_tokens` WHERE `token` = :token");
            $deleteStmt->execute([':token' => $token]);

            // Delete all remember tokens for this user (force re-login on all devices)
            $deleteRememberStmt = $pdo->prepare("DELETE FROM `remember_tokens` WHERE `user_id` = :user_id");
            $deleteRememberStmt->execute([':user_id' => $userId]);

            // Log the password reset
            $logStmt = $pdo->prepare("INSERT INTO `activity_log` (`user_id`, `action`, `ip_address`) VALUES (:user_id, :action, :ip_address)");
            $logStmt->execute([
                ':user_id' => $userId,
                ':action' => 'Password reset completed',
                ':ip_address' => $_SERVER['REMOTE_ADDR']
            ]);

            // Send confirmation email (optional)
            sendPasswordChangeConfirmationEmail($userId, $pdo);

            // Commit transaction
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Password has been reset successfully! You can now login with your new password.'
            ]);

        } catch (Exception $e) {
            $pdo->rollback();
            throw $e;
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Send password change confirmation email (optional security feature)
 */
function sendPasswordChangeConfirmationEmail($userId, $pdo) {
    try {
        // Get user details
        $stmt = $pdo->prepare("SELECT `username`, `email` FROM `users` WHERE `id` = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return false;

        // Brevo API configuration
        $brevo_config = [
            'api_key' => '',
            'sender_email' => 'anouar.sabir@genius-morocco.com',
            'sender_name' => 'Genius Team',
            'Company' => 'Gamius'
        ];

        $url = 'https://api.brevo.com/v3/smtp/email';

        // Prepare email data
        $data = [
            'sender' => [
                'name' => $brevo_config['sender_name'],
                'email' => $brevo_config['sender_email']
            ],
            'to' => [
                [
                    'email' => $user['email'],
                    'name' => $user['username']
                ]
            ],
            'subject' => 'Password Changed Successfully - ' . $brevo_config['Company'] . ' Tournament Platform',
            'htmlContent' => getPasswordChangeConfirmationTemplate($user['username']),
            'textContent' => getPasswordChangeConfirmationText($user['username'])
        ];

        // Prepare headers
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'api-key: ' . $brevo_config['api_key']
        ];

        // Send API request
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Log the response for debugging
        error_log("Password change confirmation email - HTTP Code: $http_code");

        return ($http_code >= 200 && $http_code < 300);

    } catch (Exception $e) {
        error_log("Failed to send password change confirmation: " . $e->getMessage());
        return false;
    }
}

/**
 * Get password change confirmation email template
 */
function getPasswordChangeConfirmationTemplate($username) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Password Changed Successfully</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 30px 20px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; font-weight: 300; }
            .content { padding: 40px 30px; background: white; }
            .content h2 { color: #333; margin-bottom: 20px; font-size: 24px; }
            .content p { margin-bottom: 15px; font-size: 16px; line-height: 1.5; }
            .success-icon { font-size: 48px; text-align: center; margin: 20px 0; }
            .security-note { background: #e7f3ff; border: 1px solid #b3d9ff; color: #0066cc; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .footer { text-align: center; padding: 20px; background: #f8f9fa; color: #666; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🏆 Gamius Tournament Platform</h1>
            </div>
            <div class='content'>
                <div class='success-icon'>✅</div>
                <h2>Password Changed Successfully</h2>
                <p>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p>
                <p>Your password has been successfully changed for your Gamius Tournament Platform account.</p>
                <p><strong>When:</strong> " . date('F j, Y \a\t g:i A T') . "</p>
                <p><strong>IP Address:</strong> " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "</p>
                
                <div class='security-note'>
                    <strong>🔒 Security Information:</strong>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li>If you made this change, no further action is required</li>
                        <li>If you did NOT change your password, please contact support immediately</li>
                        <li>You have been logged out of all devices for security</li>
                    </ul>
                </div>
                
                <p>You can now log in to your account using your new password.</p>
                <p>If you have any concerns about your account security, please don't hesitate to contact our support team.</p>
            </div>
            <div class='footer'>
                <p>© 2025 Gamius Tournament Platform. All rights reserved.</p>
                <p>Support: <a href='mailto:support@genius-morocco.com'>support@genius-morocco.com</a></p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Get password change confirmation text content
 */
function getPasswordChangeConfirmationText($username) {
    return "
Password Changed Successfully - Gamius Tournament Platform

Hello {$username},

Your password has been successfully changed for your Gamius Tournament Platform account.

When: " . date('F j, Y \a\t g:i A T') . "
IP Address: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "

SECURITY INFORMATION:
- If you made this change, no further action is required
- If you did NOT change your password, please contact support immediately
- You have been logged out of all devices for security

You can now log in to your account using your new password.

If you have any concerns about your account security, please contact our support team.

© 2025 Gamius Tournament Platform. All rights reserved.
Support: support@genius-morocco.com
    ";
}
?>