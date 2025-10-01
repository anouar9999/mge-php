<?php
// RESEND VERIFICATION EMAIL ENDPOINT - Save as resend-verification.php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    // Database connection
    $db_config = require 'db_config.php';
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    $email = $input['email'] ?? '';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Valid email address is required');
    }

    error_log("Resend verification request for: " . $email);

    // Find unverified user
    $stmt = $pdo->prepare('
        SELECT id, username, email, verification_token, created_at 
        FROM users 
        WHERE email = ? AND is_verified = 0
    ');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Check if user exists and is already verified
        $verifiedStmt = $pdo->prepare('SELECT is_verified FROM users WHERE email = ?');
        $verifiedStmt->execute([$email]);
        $verifiedUser = $verifiedStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($verifiedUser && $verifiedUser['is_verified']) {
            throw new Exception('This email address is already verified. You can log in to your account.');
        } else {
            throw new Exception('No unverified account found with this email address.');
        }
    }

    // Check rate limiting (max 3 resends per hour)
    $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $rateLimitStmt = $pdo->prepare('
        SELECT COUNT(*) as resend_count 
        FROM verification_resends 
        WHERE user_id = ? AND created_at > ?
    ');
    $rateLimitStmt->execute([$user['id'], $oneHourAgo]);
    $resendCount = $rateLimitStmt->fetchColumn();

    if ($resendCount >= 3) {
        throw new Exception('Too many verification emails sent. Please wait before requesting another one.');
    }

    // Generate new verification token
    $newToken = bin2hex(random_bytes(32));
    
    // Update user with new token
    $updateStmt = $pdo->prepare('UPDATE users SET verification_token = ? WHERE id = ?');
    $updateStmt->execute([$newToken, $user['id']]);

    // Log resend attempt
    $logStmt = $pdo->prepare('
        INSERT INTO verification_resends (user_id, email, created_at) 
        VALUES (?, ?, NOW())
    ');
    $logStmt->execute([$user['id'], $email]);

    // Send verification email
    $emailResult = sendVerificationEmail($user['email'], $user['username'], $newToken);

    if ($emailResult['success']) {
        error_log("Verification email resent successfully to: " . $email);
        echo json_encode([
            'success' => true,
            'message' => 'Verification email sent successfully. Please check your inbox and spam folder.'
        ]);
    } else {
        throw new Exception('Failed to send verification email: ' . $emailResult['message']);
    }

} catch (Exception $e) {
    error_log("Resend verification error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Include the sendVerificationEmail function here or require the registration file
?>