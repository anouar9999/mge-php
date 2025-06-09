<?php
// Error handling and logging setup
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/verification.log');
$db_config = require 'db_config.php';

// Check if we're in development mode
$isDevEnvironment = ($_SERVER['HTTP_HOST'] === $db_config['api']['host'] || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
if ($isDevEnvironment) {
    // Enable detailed error display in development
    error_log("Verification running in DEVELOPMENT mode");
}

try {
    // Get token from URL
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    
    if (empty($token)) {
        throw new Exception('Invalid verification link');
    }
    
    error_log("Processing verification request for token: " . substr($token, 0, 8) . "...");
    
    // Database connection
    $db_config = require 'db_config.php';
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Find user with this token that hasn't expired
    $stmt = $pdo->prepare('
        SELECT id, email, username 
        FROM users 
        WHERE verification_token = ? 
        AND is_verified = 0 
        AND token_expires_at > NOW()
    ');
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Check if token exists but is expired
        $stmt = $pdo->prepare('
            SELECT id, email, token_expires_at 
            FROM users 
            WHERE verification_token = ? 
            AND is_verified = 0
        ');
        $stmt->execute([$token]);
        $expiredUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($expiredUser) {
            error_log("Expired token found for user: " . $expiredUser['email']);
            throw new Exception('Verification link has expired. Please request a new one.');
        } else {
            error_log("No user found with token: " . substr($token, 0, 8) . "...");
            throw new Exception('Invalid verification link');
        }
    }
    
    // Update user to verified status
    $updateStmt = $pdo->prepare('
        UPDATE users 
        SET is_verified = 1, 
            verification_token = NULL, 
            token_expires_at = NULL 
        WHERE id = ?
    ');
    $updateStmt->execute([$user['id']]);
    
    error_log("User {$user['email']} verified successfully");
    
    // Display success message
    $successMessage = "Your account has been successfully verified. You can now login.";
    $redirectUrl = "/login"; // Adjust to your login page URL
    
} catch (Exception $e) {
    error_log("Verification error: " . $e->getMessage());
    $errorMessage = $e->getMessage();
    $redirectUrl = "/login?verification_error=" . urlencode($e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Account Verification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: Arial, sans-serif; 
            background-color: #f5f5f5; 
            margin: 0; 
            padding: 20px; 
            color: #333;
        }
        .container { 
            max-width: 600px; 
            margin: 40px auto; 
            padding: 30px; 
            background-color: #fff; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
            text-align: center;
        }
        .logo {
            margin-bottom: 20px;
        }
        .success { 
            color: #28a745; 
            font-size: 24px;
            margin-bottom: 15px;
        }
        .error { 
            color: #dc3545; 
            font-size: 24px;
            margin-bottom: 15px;
        }
        p {
            line-height: 1.6;
            margin-bottom: 25px;
        }
        .button { 
            display: inline-block; 
            background-color: #007bff; 
            color: white; 
            padding: 12px 25px; 
            text-decoration: none; 
            border-radius: 5px; 
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .button:hover {
            background-color: #0056b3;
        }
        .redirect-message {
            margin-top: 20px;
            font-size: 14px;
            color: #666;
        }
    </style>
    <?php if (isset($redirectUrl) && !$isDevEnvironment): ?>
    <meta http-equiv="refresh" content="5;url=<?php echo htmlspecialchars($redirectUrl); ?>">
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <div class="logo">
            <!-- You can add your logo here -->
            <h1>Gamius</h1>
        </div>
        
        <?php if (isset($successMessage)): ?>
            <h2 class="success">Account Verified!</h2>
            <p><?php echo htmlspecialchars($successMessage); ?></p>
            <a href="/login" class="button">Login Now</a>
            
            <?php if (!$isDevEnvironment): ?>
            <p class="redirect-message">You will be redirected to the login page in 5 seconds.</p>
            <?php endif; ?>
            
        <?php elseif (isset($errorMessage)): ?>
            <h2 class="error">Verification Failed</h2>
            <p><?php echo htmlspecialchars($errorMessage); ?></p>
            <a href="/login" class="button">Go to Login</a>
            
            <?php if (!$isDevEnvironment): ?>
            <p class="redirect-message">You will be redirected to the login page in 5 seconds.</p>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($isDevEnvironment): ?>
        <div style="margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px; text-align: left;">
            <p style="font-weight: bold; margin-bottom: 10px;">Development Mode Information:</p>
            <ul style="margin-left: 20px; padding-left: 0;">
                <li>Running in development environment</li>
                <li>Auto-redirect disabled</li>
                <?php if (isset($user)): ?>
                <li>User ID: <?php echo $user['id']; ?></li>
                <li>Email: <?php echo htmlspecialchars($user['email']); ?></li>
                <li>Username: <?php echo htmlspecialchars($user['username']); ?></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>