<?php
require_once 'config/config.php';

echo "=== Monnify API Endpoint Explorer ===\n\n";

// Get access token
$ch = curl_init(MONNIFY_BASE_URL . '/api/v1/auth/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode(MONNIFY_API_KEY . ':' . MONNIFY_SECRET_KEY)],
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 30
]);

$body = curl_exec($ch);
$response = json_decode($body, true);
curl_close($ch);

if (!isset($response['responseBody']['accessToken'])) {
    echo "❌ Authentication failed\n";
    exit(1);
}

$token = $response['responseBody']['accessToken'];
echo "✅ Authenticated\n\n";

// Try different endpoints
$endpoints = [
    '/api/v1/merchant/contracts' => 'List contracts (v1)',
    '/api/v2/merchant/contracts' => 'List contracts (v2)',
    '/api/v1/contracts' => 'Contracts root (v1)',
    '/api/v2/contracts' => 'Contracts root (v2)',
    '/api/v1/bank-transfer/reserved-accounts' => 'Accounts endpoint (v1)',
    '/api/v2/bank-transfer/reserved-accounts/banks' => 'Banks list (v2)',
];

foreach ($endpoints as $endpoint => $desc) {
    echo "[TEST] $desc\n";
    echo "       Endpoint: $endpoint\n";
    
    $ch = curl_init(MONNIFY_BASE_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", "Content-Type: application/json"],
        CURLOPT_TIMEOUT => 10
    ]);
    
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($status >= 200 && $status < 300) {
        echo "       ✅ HTTP $status (OK)\n";
        $response = json_decode($body, true);
        if (isset($response['responseBody'])) {
            if (is_array($response['responseBody']) && !empty($response['responseBody'])) {
                echo "       Found " . count($response['responseBody']) . " item(s)\n";
                // Show first item
                $first = array_values($response['responseBody'])[0];
                if (is_array($first)) {
                    foreach (array_slice(array_keys($first), 0, 3) as $key) {
                        echo "         - $key: " . substr(print_r($first[$key], true), 0, 50) . "\n";
                    }
                }
            }
        }
    } else {
        echo "       ❌ HTTP $status\n";
    }
    echo "\n";
}

echo "=== Endpoint Explorer Complete ===\n";
?>
