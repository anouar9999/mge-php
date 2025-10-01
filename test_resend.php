<?php
// test_resend.php - A simple script to test Resend API connectivity
$db_config = require 'db_config.php';

// Enable error display for easier debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Log file for detailed diagnostics
$logFile = __DIR__ . '/resend_test.log';
file_put_contents($logFile, "=== Resend API Test " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

// Function to log messages to both screen and file
function logMessage($message) {
    global $logFile;
    echo $message . "<br>";
    file_put_contents($logFile, $message . "\n", FILE_APPEND);
}

logMessage("Starting Resend API test...");

// Check if Resend config file exists
$configFile = __DIR__ . '/resend_config.php';
if (!file_exists($configFile)) {
    logMessage("ERROR: Config file not found: $configFile");
    
    // Create a sample config file
    logMessage("Creating sample config file. PLEASE EDIT WITH YOUR REAL API KEY!");
    file_put_contents($configFile, "<?php\nreturn [\n    'api_key' => 'your_resend_api_key_here' // Replace with your actual API key\n];\n?>");
    logMessage("Sample config file created. Please edit it with your real API key and run this test again.");
    exit;
}

// Load API key from config
try {
    $resend_config = require $configFile;
    if (empty($resend_config['api_key']) || $resend_config['api_key'] === 'your_resend_api_key_here') {
        logMessage("ERROR: Please edit the config file with your real Resend API key");
        exit;
    }
    $apiKey = $resend_config['api_key'];
    logMessage("API key loaded from config: " . substr($apiKey, 0, 5) . '***' . substr($apiKey, -3));
} catch (Exception $e) {
    logMessage("ERROR loading config: " . $e->getMessage());
    exit;
}

// Determine environment
$isDevEnvironment = ($_SERVER['HTTP_HOST'] === $db_config['api']['host'] || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
logMessage("Environment detected as: " . ($isDevEnvironment ? "Development" : "Production"));

// Set test email recipient (use your own email for testing)
$testEmail = "sabiranouar00@gmail.com"; // CHANGE THIS TO YOUR EMAIL
logMessage("Test email will be sent to: $testEmail");

// Prepare test email data
$data = [
    'from' => 'Test User <onboarding@resend.dev>', // Using Resend's shared domain for testing
    'to' => [$testEmail],
    'subject' => 'Resend API Test ' . date('Y-m-d H:i:s'),
    'html' => '<h1>Resend Test Email</h1><p>This is a test email sent from the Resend API test script.</p><p>If you receive this, your Resend API integration is working!</p><p>Time: ' . date('Y-m-d H:i:s') . '</p>',
    'text' => "Resend Test Email\n\nThis is a test email sent from the Resend API test script.\n\nIf you receive this, your Resend API integration is working!\n\nTime: " . date('Y-m-d H:i:s')
];

logMessage("Prepared email payload:");
logMessage(json_encode($data, JSON_PRETTY_PRINT));

// Initialize cURL
$ch = curl_init('https://api.resend.com/emails');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json'
]);

// For development environments, disable SSL verification
if ($isDevEnvironment) {
    logMessage("WARNING: Disabling SSL verification for development environment");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

// Execute request
logMessage("Sending request to Resend API...");
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

// Log response details
logMessage("HTTP Response Code: " . $httpCode);

if ($error) {
    logMessage("cURL Error: " . $error);
} else {
    logMessage("Response: " . $response);
    
    // Process response
    $responseData = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        logMessage("SUCCESS! Email was sent successfully");
        if (isset($responseData['id'])) {
            logMessage("Email ID: " . $responseData['id']);
        }
    } else {
        logMessage("ERROR: Failed to send email");
        
        if (isset($responseData['error'])) {
            $errorDetails = is_array($responseData['error']) 
                ? json_encode($responseData['error']) 
                : $responseData['error'];
            
            logMessage("Error details: " . $errorDetails);
            
            // Common error troubleshooting
            if ($httpCode == 403) {
                logMessage("HTTP 403 Forbidden error suggests an API key issue.");
                logMessage("Please check that:");
                logMessage("1. Your API key is correct");
                logMessage("2. Your API key has permission to send emails");
                logMessage("3. Your Resend account is active");
                logMessage("4. You're using a valid 'from' address (onboarding@resend.dev or your verified domain)");
            }
        }
    }
}

// Log verbose curl information to help diagnose issues
logMessage("\nDetailed cURL Information:");
logMessage(json_encode($info, JSON_PRETTY_PRINT));

logMessage("\nTest completed. Check your email inbox and this log for results.");
?>

<html>
<head>
    <title>Resend API Test</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { color: #333; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow: auto; }
        .note { background: #ffffd0; padding: 10px; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <h1>Resend API Test Results</h1>
    <p>See the output above for test results.</p>
    
    <div class="note">
        <p><strong>Note:</strong> A log file has been created at <code><?php echo $logFile; ?></code> with detailed test results.</p>
    </div>
    
    <h2>Next Steps</h2>
    <p>If the test was successful, you should:</p>
    <ol>
        <li>Receive a test email at <strong><?php echo htmlspecialchars($testEmail); ?></strong></li>
        <li>See "SUCCESS!" in the output above</li>
    </ol>
    
    <p>If the test failed, check:</p>
    <ol>
        <li>Your API key is correct in the <code>resend_config.php</code> file</li>
        <li>You're using a valid "from" address (onboarding@resend.dev or your verified domain)</li>
        <li>Your Resend account is active and has permission to send emails</li>
        <li>The error messages above for specific issues</li>
    </ol>
</body>
</html>