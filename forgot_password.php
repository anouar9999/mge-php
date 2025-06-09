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

    // Insert new token
    $insertStmt = $pdo->prepare("INSERT INTO `password_reset_tokens` (`user_id`, `token`, `expires`) VALUES (:user_id, :token, :expires)");
    $insertStmt->execute([
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
    $resetLink = "http://{$db_config['api']['host']}:3000/reset-password?token=" . $token;
    
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
        <style>
            body { 
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 0; 
                background-color: #f4f4f4; 
            }
            .container { 
                max-width: 600px; 
                margin: 20px auto; 
                background: white; 
                border-radius: 10px; 
                overflow: hidden; 
                box-shadow: 0 0 20px rgba(0,0,0,0.1); 
            }
            .header { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                color: white; 
                padding: 30px 20px; 
                text-align: center; 
            }
            .header h1 { 
                margin: 0; 
                font-size: 28px; 
                font-weight: 300; 
            }
            .content { 
                padding: 40px 30px; 
                background: white; 
            }
            .content h2 { 
                color: #333; 
                margin-bottom: 20px; 
                font-size: 24px; 
            }
            .content p { 
                margin-bottom: 15px; 
                font-size: 16px; 
                line-height: 1.5; 
            }
            .button { 
                display: inline-block; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                color: white !important; 
                padding: 15px 30px; 
                text-decoration: none; 
                border-radius: 25px; 
                margin: 25px 0; 
                font-weight: 600; 
                text-align: center; 
                transition: transform 0.2s ease; 
            }
            .button:hover { 
                transform: translateY(-2px); 
            }
            .warning { 
                background: #fff3cd; 
                border: 1px solid #ffeaa7; 
                color: #856404; 
                padding: 15px; 
                border-radius: 5px; 
                margin: 20px 0; 
            }
            .footer { 
                text-align: center; 
                padding: 20px; 
                background: #f8f9fa; 
                color: #666; 
                font-size: 14px; 
            }
            .security-note { 
                font-size: 12px; 
                color: #999; 
                margin-top: 20px; 
                padding-top: 20px; 
                border-top: 1px solid #eee; 
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🏆 Gamius Tournament Platform</h1>
            </div>
            <div class='content'>
                <h2>Password Reset Request</h2>
                <p>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p>
                <p>We received a request to reset your password for your Gamius Tournament Platform account. If you made this request, click the button below to reset your password:</p>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . htmlspecialchars($resetLink) . "' class='button'>Reset My Password</a>
                </div>
                
                <div class='warning'>
                    <strong>⚠️ Important Security Information:</strong>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li>This link will expire in <strong>1 hour</strong> for security reasons</li>
                        <li>If you didn't request this reset, please ignore this email</li>
                        <li>Your password will remain unchanged if you don't click the link</li>
                    </ul>
                </div>
                
                <p>If the button doesn't work, you can copy and paste this link into your browser:</p>
                <p style='word-break: break-all; background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 14px;'>" . htmlspecialchars($resetLink) . "</p>
                
                <div class='security-note'>
                    <p><strong>Security Tip:</strong> Always verify that password reset emails come from our official domain. Never share your password or reset links with anyone.</p>
                </div>
            </div>
            <div class='footer'>
                <p>© 2025 Gamius Tournament Platform. All rights reserved.</p>
                <p>This is an automated message, please do not reply to this email.</p>
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

/**
 * Alternative email sending function using SMTP (if you prefer this approach)
 * Uncomment and configure this if you want to use SMTP instead
 */
/*
function sendPasswordResetEmailSMTP($email, $username, $resetLink) {
    require_once 'path/to/PHPMailer/PHPMailer.php';
    require_once 'path/to/PHPMailer/SMTP.php';
    require_once 'path/to/PHPMailer/Exception.php';
    
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\SMTP;
    use PHPMailer\PHPMailer\Exception;
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Replace with your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your-email@gmail.com'; // Replace with your email
        $mail->Password   = 'your-app-password'; // Replace with your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('noreply@yoursite.com', 'Tournament Platform');
        $mail->addAddress($email, $username);
        $mail->addReplyTo('support@yoursite.com', 'Tournament Support');
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - Tournament Platform';
        $mail->Body    = getPasswordResetEmailTemplate($username, $resetLink);
        $mail->AltBody = "Hello {$username},\n\nYou have requested to reset your password. Visit this link to reset your password: {$resetLink}\n\nThis link will expire in 1 hour.\n\nIf you didn't request this, please ignore this email.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("SMTP Email sending failed: {$mail->ErrorInfo}");
        return false;
    }
}
*/
?>