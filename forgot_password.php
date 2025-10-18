<?php
// forgot_password.php - Request password reset
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
$db_config = require 'db_config.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}


try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['email']) || empty(trim($data['email']))) {
        throw new Exception('Email is required');
    }

    $email = trim($data['email']);

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }

    // Check if user exists
    $stmt = $pdo->prepare("SELECT `id`, `username`, `email` FROM `users` WHERE `email` = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // For security reasons, don't reveal if email exists or not
        echo json_encode([
            'success' => true,
            'message' => 'If an account with that email exists, a password reset link has been sent.'
        ]);
        exit();
    }

    // Generate secure token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expires in 1 hour

    // Delete any existing tokens for this user
    $deleteStmt = $pdo->prepare("DELETE FROM `password_reset_tokens` WHERE `user_id` = :user_id");
    $deleteStmt->execute([':user_id' => $user['id']]);

    // Get the next available ID
    $maxIdStmt = $pdo->query("SELECT COALESCE(MAX(`id`), 0) + 1 as next_id FROM `password_reset_tokens`");
    $nextId = $maxIdStmt->fetch(PDO::FETCH_ASSOC)['next_id'];

    // Insert new token with explicit ID
    $insertStmt = $pdo->prepare("INSERT INTO `password_reset_tokens` (`id`, `user_id`, `token`, `expires`) VALUES (:id, :user_id, :token, :expires)");
    $insertStmt->execute([
        ':id' => $nextId,
        ':user_id' => $user['id'],
        ':token' => $token,
        ':expires' => $expires
    ]);

    // Log the password reset request
    $logStmt = $pdo->prepare("INSERT INTO `activity_log` (`user_id`, `action`, `ip_address`) VALUES (:user_id, :action, :ip_address)");
    $logStmt->execute([
        ':user_id' => $user['id'],
        ':action' => 'Password reset requested',
        ':ip_address' => $_SERVER['REMOTE_ADDR']
    ]);

    // Create reset link - update this URL to match your frontend
    $resetLink = "http://user.gnews.ma/reset-password?token=" . $token;
    
    // Send password reset email using your email code
    $emailSent = sendPasswordResetEmail($user['email'], $user['username'], $resetLink, $db_config['api']['api_key']);
    
    if ($emailSent) {
        echo json_encode([
            'success' => true,
            'message' => 'Password reset link has been sent to your email.'
            // Remove debug_token in production for security
            // 'debug_token' => $token // Only for testing
        ]);
    } else {
        // Even if email fails, don't reveal this to user for security
        echo json_encode([
            'success' => true,
            'message' => 'If an account with that email exists, a password reset link has been sent.'
        ]);
        
        // Log email failure for admin debugging
        error_log("Failed to send password reset email to: " . $email);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Send password reset email using Brevo API
 */
function sendPasswordResetEmail($email, $username, $resetLink, $api_key) {
    // Brevo API configuration
    $brevo_config = [
        
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
                'email' => $email,
                'name' => $username
            ]
        ],
        'subject' => 'Password Reset Request - ' . $brevo_config['Company'] . ' Tournament Platform',
        'htmlContent' => getPasswordResetEmailTemplate($username, $resetLink),
        'textContent' => getPasswordResetEmailText($username, $resetLink)
    ];

    // Prepare headers
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . $api_key
    ];

    // Send API request
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // for dev only

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    curl_close($ch);

    // Log the response for debugging
    error_log("Brevo API Response (Password Reset) - HTTP Code: $http_code, Response: " . substr($response, 0, 200));

    if ($curl_error) {
        error_log("Curl error (Password Reset): " . $curl_error);
        return false;
    }

    if ($http_code >= 200 && $http_code < 300) {
        return true;
    } else {
        error_log("Brevo API Error (Password Reset) - HTTP " . $http_code . ": " . $response);
        return false;
    }
}

/**
 * Get password reset email template
 */
function getPasswordResetEmailTemplate($username, $resetLink) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Password Reset</title>
        <link rel='preconnect' href='https://fonts.googleapis.com'>
        <link rel='preconnect' href='https://fonts.gstatic.com' crossorigin>
        <link href='https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700;800&display=swap' rel='stylesheet'>
    </head>
    <body style='font-family: \"Saira\", sans-serif; background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); padding: 20px; margin: 0;'>
        <div style='max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3); border: 1px solid #333;'>
            <div style='height: 8px; background: linear-gradient(90deg, #F43620 0%, #ff6b4a 50%, #F43620 100%);'></div>
            
            <div style='background: #1a1a1a; background-image: radial-gradient(circle at 20% 50%, rgba(244, 54, 32, 0.15) 0%, transparent 50%), radial-gradient(circle at 80% 50%, rgba(244, 54, 32, 0.1) 0%, transparent 50%), repeating-linear-gradient(45deg, transparent, transparent 35px, rgba(255, 255, 255, 0.02) 35px, rgba(255, 255, 255, 0.02) 70px); color: white; text-align: center; padding: 45px 30px; position: relative; border-bottom: 3px solid #F43620;'>
                <div style='margin-bottom: 18px;'>
                    <div style='width: 80px; height: 80px; margin: 0 auto; background: radial-gradient(circle, #F43620 0%, #d42810 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 30px rgba(244, 54, 32, 0.5), 0 0 60px rgba(244, 54, 32, 0.3);'>
                        <svg width='40' height='40' viewBox='0 0 40 40' fill='none'>
                            <path d='M15 17 L15 14 Q15 10 19 10 L21 10 Q25 10 25 14 L25 17 M12 17 L28 17 Q30 17 30 19 L30 28 Q30 30 28 30 L12 30 Q10 30 10 28 L10 19 Q10 17 12 17 M20 21 L20 25' stroke='white' stroke-width='2.5' fill='none'/>
                        </svg>
                    </div>
                </div>
                <h1 style='margin: 0 0 12px 0; font-size: 38px; font-weight: 900; letter-spacing: 3px; text-transform: uppercase; text-shadow: 2px 2px 4px rgba(0,0,0,0.3);'>GAMIUS</h1>
                <div style='padding: 8px 24px; background: transparent; border: 2px solid #F43620; border-radius: 30px; display: inline-block;'>
                    <p style='margin: 0; font-size: 13px; font-weight: 700; letter-spacing: 2px; color: #F43620;'>PASSWORD RESET</p>
                </div>
            </div>
            
            <div style='padding: 50px 40px; text-align: center; background: #ffffff;'>
                <div style='display: inline-flex; align-items: center; gap: 10px; background: linear-gradient(135deg, #f5f5f5 0%, #e8e8e8 100%); padding: 12px 24px; border-radius: 50px; margin-bottom: 25px; border: 2px solid #e0e0e0;'>
                    <span style='font-size: 24px;'>🔑</span>
                    <span style='color: #1a1a1a; font-size: 18px; font-weight: 700;'>Hello, " . htmlspecialchars($username) . "!</span>
                </div>
                
                <h2 style='color: #1a1a1a; font-size: 28px; margin-bottom: 20px; font-weight: 700;'>Reset Your Password</h2>
                <p style='color: #6b7280; font-size: 17px; line-height: 1.8; margin-bottom: 30px; max-width: 500px; margin-left: auto; margin-right: auto;'>We received a request to <strong style='color: #F43620;'>reset your password</strong> for your Gamius account. Click the button below to create a new secure password:</p>
                
                <div style='text-align: center; margin: 35px 0;'>
                    <a href='" . htmlspecialchars($resetLink) . "' style='display: inline-block; background: linear-gradient(135deg, #F43620 0%, #ff4520 100%); color: white; padding: 20px 50px; text-decoration: none; border-radius: 50px; font-weight: 800; font-size: 18px; box-shadow: 0 8px 25px rgba(244, 54, 32, 0.4); text-transform: uppercase; letter-spacing: 1px; border: 2px solid #F43620;'>
                        <span style='display: inline-flex; align-items: center; gap: 10px;'>
                            <svg width='20' height='20' viewBox='0 0 20 20' fill='white'>
                                <path d='M5 8 L5 6 Q5 3 8 3 L12 3 Q15 3 15 6 L15 8 M3 8 L17 8 Q18 8 18 9 L18 16 Q18 17 17 17 L3 17 Q2 17 2 16 L2 9 Q2 8 3 8' fill='white'/>
                            </svg>
                            Reset Password
                        </span>
                    </a>
                </div>
                
                <div style='background: linear-gradient(135deg, #fff5f3 0%, #ffe8e5 100%); border: 2px solid #F43620; border-radius: 12px; padding: 25px; margin: 40px 0; text-align: left;'>
                    <div style='display: flex; align-items: flex-start; gap: 15px;'>
                        <div style='flex-shrink: 0;'>
                            <svg width='32' height='32' viewBox='0 0 32 32'>
                                <circle cx='16' cy='16' r='15' fill='#F43620'/>
                                <path d='M16 8 L16 12 M16 20 L16 16 M16 22 L16 24' stroke='white' stroke-width='3' stroke-linecap='round'/>
                            </svg>
                        </div>
                        <div>
                            <p style='margin: 0 0 10px 0; color: #1a1a1a; font-size: 15px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;'>Security Information</p>
                            <p style='margin: 0; color: #4a5568; font-size: 14px; line-height: 1.8;'>
                                • Link expires in <strong style='color: #F43620;'>1 hour</strong><br>
                                • Didn't request this? <strong>Ignore safely</strong><br>
                                • Never share this link with anyone<br>
                                • Your password is safe until you complete the reset
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div style='background: linear-gradient(135deg, #1a1a1a 0%, #000000 100%); color: #9ca3af; text-align: center; padding: 25px 30px; font-size: 13px; border-top: 3px solid #F43620;'>
                <p style='margin: 0; color: #6b7280; font-size: 12px;'>© 2025 Gamius Tournament Platform. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Get password reset email text content (plain text version)
 */
function getPasswordResetEmailText($username, $resetLink) {
    return "
Password Reset Request - Gamius Tournament Platform

Hello {$username},

We received a request to reset your password for your Gamius Tournament Platform account.

If you made this request, click the link below to reset your password:
{$resetLink}

IMPORTANT SECURITY INFORMATION:
- This link will expire in 1 hour for security reasons
- If you didn't request this reset, please ignore this email
- Your password will remain unchanged if you don't click the link

If the link doesn't work, copy and paste it into your browser.

Security Tip: Always verify that password reset emails come from our official domain. Never share your password or reset links with anyone.

© 2025 Gamius Tournament Platform. All rights reserved.
This is an automated message, please do not reply to this email.

Support: support@genius-morocco.com
    ";
}
?>
