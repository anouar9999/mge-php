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
    // Redirect to registration page on error
    header('Location: https://user.gnews.ma/register');
    exit();
}

function logVerificationEvent($pdo, $userId, $status) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO verification_logs (user_id, status, verified_at, ip_address) 
            VALUES (?, ?, NOW(), ?)
        ");
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
        <title>Email Verified Successfully - GAMIUS</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: "Saira", sans-serif;
            }
            body {
                font-family: "Saira", sans-serif;
                background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .success-container {
                background: white;
                border-radius: 20px;
                padding: 0;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 600px;
                width: 100%;
                animation: slideUp 0.6s ease-out;
                overflow: hidden;
                border: 1px solid #333;
            }
            .top-bar {
                height: 8px;
                background: linear-gradient(90deg, #F43620 0%, #ff6b4a 50%, #F43620 100%);
            }
            .header-section {
                background: #1a1a1a;
                background-image: radial-gradient(circle at 20% 50%, rgba(244, 54, 32, 0.15) 0%, transparent 50%), radial-gradient(circle at 80% 50%, rgba(244, 54, 32, 0.1) 0%, transparent 50%), repeating-linear-gradient(45deg, transparent, transparent 35px, rgba(255, 255, 255, 0.02) 35px, rgba(255, 255, 255, 0.02) 70px);
                padding: 40px 30px;
                border-bottom: 3px solid #F43620;
            }
            .content-section {
                padding: 50px 40px;
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
                background: radial-gradient(circle, #10b981 0%, #059669 100%);
                border-radius: 50%;
                margin: 0 auto;
                display: flex;
                align-items: center;
                justify-content: center;
                animation: checkmark 0.8s ease-in-out 0.3s both;
                box-shadow: 0 0 30px rgba(16, 185, 129, 0.5), 0 0 60px rgba(16, 185, 129, 0.3);
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
            .success-icon svg {
                width: 50px;
                height: 50px;
            }
            .success-title {
                color: #1a1a1a;
                margin-bottom: 20px;
                font-size: 42px;
                font-weight: 900;
                letter-spacing: 1px;
                text-transform: uppercase;
            }
            .success-subtitle {
                color: #6b7280;
                line-height: 1.6;
                margin-bottom: 30px;
                font-size: 18px;
            }
            .username-highlight {
                color: #F43620;
                font-weight: 800;
            }
            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 15px;
                margin-top: 30px;
            }
            .btn-primary {
                background: linear-gradient(135deg, #F43620 0%, #ff4520 100%);
                color: white;
                padding: 16px 35px;
                text-decoration: none;
                border-radius: 50px;
                font-weight: 700;
                font-size: 16px;
                transition: all 0.3s ease;
                box-shadow: 0 6px 20px rgba(244, 54, 32, 0.4);
                display: inline-block;
                text-transform: uppercase;
                letter-spacing: 1px;
                border: 2px solid #F43620;
            }
            .btn-primary:hover {
                transform: translateY(-3px);
                box-shadow: 0 10px 30px rgba(244, 54, 32, 0.6);
            }
            .features-preview {
                background: #f9f9f9;
                border-radius: 15px;
                padding: 30px 25px;
                margin: 40px 0;
                text-align: left;
                border: 2px solid #e0e0e0;
            }
            .features-title {
                text-align: center;
                color: #1a1a1a;
                font-weight: 800;
                margin-bottom: 25px;
                font-size: 20px;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .feature-list {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
            }
            .feature-item {
                background: white;
                padding: 18px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                gap: 12px;
                font-size: 14px;
                color: #1a1a1a;
                font-weight: 700;
                border-left: 4px solid #F43620;
                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            }
            .feature-icon {
                font-size: 28px;
            }
            @media (max-width: 600px) {
                .content-section {
                    padding: 30px 20px;
                }
                .success-title {
                    font-size: 32px;
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
            <div class="top-bar"></div>
            
            <div class="header-section">
                <div class="success-icon">
                    <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="30" cy="30" r="28" stroke="white" stroke-width="3"/>
                        <path d="M15 30 L25 40 L45 20" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
            
            <div class="content-section">
                <h1 class="success-title">Account Verified</h1>
                
                <p class="success-subtitle">
                    Welcome, <span class="username-highlight">' . htmlspecialchars($username) . '</span>.<br>
                    Your email address has been successfully verified and your account is now active.
                </p>
                
                <div class="action-buttons">
                    <a href="https://user.gnews.ma/login" class="btn-primary">
                        <span style="display: inline-flex; align-items: center; gap: 10px;">
                            <svg width="18" height="18" viewBox="0 0 20 20" fill="white">
                                <path d="M10 2 L15 7 L12 7 L12 12 L8 12 L8 7 L5 7 Z M3 15 L17 15 L17 18 L3 18 Z" fill="white"/>
                            </svg>
                            Start Gaming
                        </span>
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>';
}
?>