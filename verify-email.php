<?php
// EMAIL VERIFICATION ENDPOINT - Save as verify-email.php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

header('Content-Type: text/html; charset=UTF-8');

try {
    // Database connection
    $db_config = require 'db_config.php';
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Get verification token from URL
    $token = $_GET['token'] ?? '';
    
    if (empty($token)) {
        throw new Exception('Missing verification token. Please check your email for the correct link.');
    }
    
    error_log("Processing verification for token: " . substr($token, 0, 10) . "...");
    
    // Find user with this verification token
    $stmt = $pdo->prepare('
        SELECT id, username, email, is_verified, created_at 
        FROM users 
        WHERE verification_token = ? AND is_verified = 0
    ');
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Check if user exists but is already verified
        $checkStmt = $pdo->prepare('
            SELECT id, username, is_verified 
            FROM users 
            WHERE verification_token = ? OR id IN (
                SELECT id FROM users WHERE verification_token = ? AND is_verified = 1
            )
        ');
        $checkStmt->execute([$token, $token]);
        $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser && $existingUser['is_verified']) {
            throw new Exception('This email address has already been verified. You can now log in to your account.');
        } else {
            throw new Exception('Invalid or expired verification link. Please register again or contact support.');
        }
    }
    
    // Check if verification link is expired (24 hours)
    $createdAt = new DateTime($user['created_at']);
    $now = new DateTime();
    $interval = $now->diff($createdAt);
    $hoursDiff = ($interval->days * 24) + $interval->h;
    
    if ($hoursDiff > 24) {
        throw new Exception('Verification link has expired. Links are valid for 24 hours. Please register again.');
    }
    
    // Verify the user account
    $updateStmt = $pdo->prepare('
        UPDATE users 
        SET is_verified = 1, verification_token = NULL 
        WHERE id = ?
    ');
    $result = $updateStmt->execute([$user['id']]);
    
    if ($result) {
        error_log("User verified successfully: " . $user['username'] . " (ID: " . $user['id'] . ")");
        
        // Log verification for analytics
        logVerificationEvent($pdo, $user['id'], 'success');
        
        // Display success page
        echo generateSuccessPage($user['username']);
        
    } else {
        throw new Exception('Failed to verify account. Please try again or contact support.');
    }
    
} catch (Exception $e) {
    error_log("Verification error: " . $e->getMessage());
    echo generateErrorPage($e->getMessage());
}

function logVerificationEvent($pdo, $userId, $status) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO verification_logs (user_id, status, verified_at, ip_address) 
            VALUES (?, ?, NOW(), ?)
        ");
        $stmt->execute([$userId, $status, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    } catch (Exception $e) {
        error_log("Failed to log verification event: " . $e->getMessage());
    }
}

function generateSuccessPage($username) {
    return '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Email Verified Successfully - GBarena</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .success-container {
                background: white;
                border-radius: 16px;
                padding: 40px;
                text-align: center;
                box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                max-width: 500px;
                width: 100%;
                animation: slideUp 0.6s ease-out;
            }
            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            .success-icon {
                width: 100px;
                height: 100px;
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                border-radius: 50%;
                margin: 0 auto 30px;
                display: flex;
                align-items: center;
                justify-content: center;
                animation: checkmark 0.8s ease-in-out 0.3s both;
            }
            @keyframes checkmark {
                0% {
                    transform: scale(0);
                }
                50% {
                    transform: scale(1.2);
                }
                100% {
                    transform: scale(1);
                }
            }
            .success-icon::before {
                content: "✓";
                font-size: 50px;
                color: white;
                font-weight: bold;
            }
            .success-title {
                color: #1f2937;
                margin-bottom: 15px;
                font-size: 32px;
                font-weight: 700;
            }
            .success-subtitle {
                color: #6b7280;
                line-height: 1.6;
                margin-bottom: 30px;
                font-size: 18px;
            }
            .username-highlight {
                color: #667eea;
                font-weight: 600;
            }
            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 15px;
                margin-top: 30px;
            }
            .btn-primary {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 16px 32px;
                text-decoration: none;
                border-radius: 10px;
                font-weight: 600;
                font-size: 16px;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
                display: inline-block;
            }
            .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
            }
            .btn-secondary {
                background: transparent;
                color: #667eea;
                padding: 12px 24px;
                text-decoration: none;
                border: 2px solid #667eea;
                border-radius: 10px;
                font-weight: 600;
                transition: all 0.3s ease;
                display: inline-block;
            }
            .btn-secondary:hover {
                background: #667eea;
                color: white;
            }
            .features-preview {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 25px;
                margin: 30px 0;
                text-align: left;
            }
            .features-title {
                text-align: center;
                color: #2d3748;
                font-weight: 600;
                margin-bottom: 20px;
                font-size: 18px;
            }
            .feature-list {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .feature-item {
                display: flex;
                align-items: center;
                font-size: 14px;
                color: #4a5568;
            }
            .feature-icon {
                margin-right: 8px;
                font-size: 16px;
            }
            @media (max-width: 600px) {
                .success-container {
                    padding: 30px 20px;
                    margin: 10px;
                }
                .success-title {
                    font-size: 28px;
                }
                .success-subtitle {
                    font-size: 16px;
                }
                .feature-list {
                    grid-template-columns: 1fr;
                }
                .action-buttons {
                    gap: 12px;
                }
            }
        </style>
    </head>
    <body>
        <div class="success-container">
            <div class="success-icon"></div>
            
            <h1 class="success-title">Email Verified! 🎉</h1>
            
            <p class="success-subtitle">
                Welcome to GBarena, <span class="username-highlight">' . htmlspecialchars($username) . '</span>!<br>
                Your email has been successfully verified and your account is now active.
            </p>
            
            <div class="features-preview">
                <div class="features-title">🎮 You can now enjoy:</div>
                <div class="feature-list">
                    <div class="feature-item">
                        <span class="feature-icon">🏆</span>
                        <span>Join tournaments</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">👥</span>
                        <span>Connect with players</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">📊</span>
                        <span>Track your stats</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">🎯</span>
                        <span>Earn rewards</span>
                    </div>
                </div>
            </div>
            
            <div class="action-buttons">
                <a href="https://yourdomain.com/login" class="btn-primary">
                    🚀 Start Gaming Now
                </a>
                <a href="https://yourdomain.com/profile" class="btn-secondary">
                    ⚙️ Complete Your Profile
                </a>
            </div>
        </div>
    </body>
    </html>';
}

function generateErrorPage($message) {
    return '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Verification Error - GBarena</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .error-container {
                background: white;
                border-radius: 16px;
                padding: 40px;
                text-align: center;
                box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                max-width: 500px;
                width: 100%;
                animation: slideUp 0.6s ease-out;
            }
            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            .error-icon {
                width: 100px;
                height: 100px;
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                border-radius: 50%;
                margin: 0 auto 30px;
                display: flex;
                align-items: center;
                justify-content: center;
                animation: shake 0.8s ease-in-out;
            }
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                25% { transform: translateX(-5px); }
                75% { transform: translateX(5px); }
            }
            .error-icon::before {
                content: "✗";
                font-size: 50px;
                color: white;
                font-weight: bold;
            }
            .error-title {
                color: #1f2937;
                margin-bottom: 15px;
                font-size: 32px;
                font-weight: 700;
            }
            .error-message {
                color: #6b7280;
                line-height: 1.6;
                margin-bottom: 30px;
                font-size: 16px;
                padding: 20px;
                background: #fef2f2;
                border: 1px solid #fecaca;
                border-radius: 10px;
            }
            .help-section {
                background: #f0f9ff;
                border: 1px solid #bae6fd;
                border-radius: 12px;
                padding: 25px;
                margin: 25px 0;
                text-align: left;
            }
            .help-title {
                color: #0369a1;
                font-weight: 600;
                margin-bottom: 15px;
                text-align: center;
            }
            .help-list {
                color: #0369a1;
                font-size: 14px;
                line-height: 1.6;
            }
            .help-list li {
                margin-bottom: 8px;
            }
            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 15px;
                margin-top: 30px;
            }
            .btn-primary {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 16px 32px;
                text-decoration: none;
                border-radius: 10px;
                font-weight: 600;
                font-size: 16px;
                transition: all 0.3s ease;
                display: inline-block;
            }
            .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
            }
            .btn-secondary {
                background: transparent;
                color: #6b7280;
                padding: 12px 24px;
                text-decoration: none;
                border: 2px solid #d1d5db;
                border-radius: 10px;
                font-weight: 600;
                transition: all 0.3s ease;
                display: inline-block;
            }
            .btn-secondary:hover {
                background: #f3f4f6;
                border-color: #9ca3af;
            }
            @media (max-width: 600px) {
                .error-container {
                    padding: 30px 20px;
                    margin: 10px;
                }
                .error-title {
                    font-size: 28px;
                }
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon"></div>
            
            <h1 class="error-title">Verification Failed</h1>
            
            <div class="error-message">
                ' . htmlspecialchars($message) . '
            </div>
            
            <div class="help-section">
                <div class="help-title">💡 What you can do:</div>
                <ul class="help-list">
                    <li>Check if you clicked the most recent verification link</li>
                    <li>Make sure the link hasn\'t expired (valid for 24 hours)</li>
                    <li>Try registering again with the same email address</li>
                    <li>Contact our support team if the problem persists</li>
                </ul>
            </div>
            
            <div class="action-buttons">
                <a href="https://yourdomain.com/register" class="btn-primary">
                    🔄 Try Registering Again
                </a>
                <a href="mailto:support@gbarena.com" class="btn-secondary">
                    📧 Contact Support
                </a>
            </div>
        </div>
    </body>
    </html>';
}
?>