<?php
require_once 'config/config.php';

echo "=== Monnify Contract Discovery ===\n\n";

// Step 1: Get access token
echo "[1] Authenticating...\n";
$ch = curl_init(MONNIFY_BASE_URL . '/api/v1/auth/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode(MONNIFY_API_KEY . ':' . MONNIFY_SECRET_KEY)],
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 30
]);

$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response = json_decode($body, true);
if (!isset($response['responseBody']['accessToken'])) {
    echo "❌ Authentication failed (HTTP $status)\n";
    echo "Response: " . print_r($response, true);
    exit(1);
}

$access_token = $response['responseBody']['accessToken'];
echo "✅ Authenticated\n\n";

// Step 2: List all merchants/contracts
echo "[2] Fetching merchant contracts...\n";
$ch = curl_init(MONNIFY_BASE_URL . '/api/v1/merchant');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $access_token", "Content-Type: application/json"],
    CURLOPT_TIMEOUT => 30
]);

$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response = json_decode($body, true);
echo "HTTP Status: $status\n";

if (isset($response['responseBody'])) {
    $merchant = $response['responseBody'];
    echo "✅ Merchant Details Found\n\n";
    
    echo "Merchant ID: " . htmlspecialchars($merchant['id'] ?? 'N/A') . "\n";
    echo "Business Name: " . htmlspecialchars($merchant['businessName'] ?? 'N/A') . "\n";
    echo "Email: " . htmlspecialchars($merchant['email'] ?? 'N/A') . "\n\n";
    
    if (isset($merchant['contracts']) && !empty($merchant['contracts'])) {
        echo "Available Contracts:\n";
        foreach ($merchant['contracts'] as $contract) {
            echo "  - Code: " . htmlspecialchars($contract['code']) . "\n";
            echo "    Name: " . htmlspecialchars($contract['name']) . "\n";
            echo "    Currency: " . htmlspecialchars($contract['currencyCode'] ?? 'NGN') . "\n";
            echo "    Status: " . htmlspecialchars($contract['status'] ?? 'ACTIVE') . "\n";
            echo "\n";
        }
    }
} else {
    echo "Response: " . print_r($response, true) . "\n";
}

// Step 3: Test account creation with the correct contract
if (isset($merchant['contracts'][0])) {
    $contract = $merchant['contracts'][0];
    echo "[3] Testing account creation with contract: " . htmlspecialchars($contract['code']) . "\n";
    
    $test_ref = 'PAYTEST_' . time();
    $payload = json_encode([
        'accountReference' => $test_ref,
        'accountName' => 'The New Tomorrow Project Test Account',
        'currencyCode' => 'NGN',
        'contractCode' => $contract['code'],
        'customerEmail' => 'test@tntp.com.ng',
        'customerName' => 'Test User',
        'getAllAvailableBanks' => true
    ]);
    
    $ch = curl_init(MONNIFY_BASE_URL . '/api/v2/bank-transfer/reserved-accounts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $access_token", "Content-Type: application/json"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($body, true);
    
    if (isset($response['requestSuccessful']) && $response['requestSuccessful']) {
        echo "✅ Account Creation Successful!\n";
        $account = $response['responseBody']['accounts'][0] ?? null;
        if ($account) {
            echo "   Account Number: " . htmlspecialchars($account['accountNumber']) . "\n";
            echo "   Bank: " . htmlspecialchars($account['bankName'] ?? 'Monnify') . "\n";
            echo "   Reference: " . htmlspecialchars($test_ref) . "\n";
        }
    } else {
        echo "❌ Account Creation Failed (HTTP $status)\n";
        echo "   Error: " . htmlspecialchars($response['responseMessage'] ?? 'Unknown') . "\n";
    }
}

echo "\n=== Discovery Complete ===\n";
?>
