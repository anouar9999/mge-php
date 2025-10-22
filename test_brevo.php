<?php
/**
 * BREVO EMAIL TEST SCRIPT
 * Test your Brevo configuration directly
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Brevo Email Test</h2>";
echo "<pre>";

// Load your config
$db_config = require __DIR__ . '/db_config.php';

// Test Configuration
$test_config = [
    'api_key' => $db_config['api']['api_key'] ?? 'NOT_FOUND',
    'sender_email' => 'No-reply@gamiusgroup.com', // ← Change this
    'sender_name' => 'Gamius Test',
    'recipient_email' => 'host5.genius@gmail.com', // ← Your test email
    'recipient_name' => 'Test User'
];

echo "=== CONFIGURATION CHECK ===\n";
echo "API Key Length: " . strlen($test_config['api_key']) . "\n";
echo "API Key Preview: " . substr($test_config['api_key'], 0, 20) . "...\n";
echo "Sender Email: " . $test_config['sender_email'] . "\n";
echo "Recipient: " . $test_config['recipient_email'] . "\n\n";

// Prepare email data
$emailData = [
    'sender' => [
        'name' => $test_config['sender_name'],
        'email' => $test_config['sender_email']
    ],
    'to' => [
        [
            'email' => $test_config['recipient_email'],
            'name' => $test_config['recipient_name']
        ]
    ],
    'subject' => 'Brevo Test Email - ' . date('Y-m-d H:i:s'),
    'htmlContent' => '<html><body><h1>Test Email</h1><p>If you receive this, Brevo is working!</p><p>Sent at: ' . date('Y-m-d H:i:s') . '</p></body></html>',
    'textContent' => 'Test Email - If you receive this, Brevo is working! Sent at: ' . date('Y-m-d H:i:s')
];

echo "=== SENDING EMAIL ===\n";
echo "Payload:\n" . json_encode($emailData, JSON_PRETTY_PRINT) . "\n\n";

// Send via Brevo API
$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($emailData),
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . $test_config['api_key']
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_VERBOSE => true
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
$curl_info = curl_getinfo($ch);
curl_close($ch);

echo "=== RESPONSE ===\n";
echo "HTTP Code: $http_code\n";
echo "cURL Error: " . ($curl_error ?: 'None') . "\n";
echo "Response Body:\n" . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

echo "=== DIAGNOSIS ===\n";

if ($http_code === 201) {
    echo "✅ SUCCESS! Email was accepted by Brevo.\n";
    echo "Message ID: " . (json_decode($response)->messageId ?? 'N/A') . "\n";
    echo "\nNext steps:\n";
    echo "1. Check spam folder in " . $test_config['recipient_email'] . "\n";
    echo "2. Wait 2-3 minutes for delivery\n";
    echo "3. Check Brevo dashboard: https://app.brevo.com/campaign/transactional\n";
    echo "4. Verify sender domain SPF/DKIM records\n";
} elseif ($http_code === 401) {
    echo "❌ ERROR: Invalid API Key\n";
    echo "Fix: Check your API key in db_config.php\n";
} elseif ($http_code === 400) {
    $error = json_decode($response);
    echo "❌ ERROR: Bad Request\n";
    echo "Message: " . ($error->message ?? 'Unknown') . "\n";
    echo "Code: " . ($error->code ?? 'N/A') . "\n";
    echo "\nPossible causes:\n";
    echo "- Sender email not verified in Brevo\n";
    echo "- Invalid email format\n";
    echo "- Missing required fields\n";
} elseif ($http_code === 402) {
    echo "❌ ERROR: Payment Required\n";
    echo "Your Brevo account may have exceeded limits or needs payment\n";
} else {
    echo "❌ ERROR: Unexpected response\n";
    echo "HTTP Code: $http_code\n";
    echo "Check Brevo API status: https://status.brevo.com/\n";
}

echo "\n=== FULL cURL INFO ===\n";
print_r($curl_info);

echo "</pre>";
?>
