<?php
require_once 'config/config.php';

echo "=== Monnify API Test Suite ===\n\n";

// Test 1: Verify API Credentials
echo "[1] Verifying Monnify API credentials...\n";
$ch = curl_init(MONNIFY_BASE_URL . '/api/v1/auth/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode(MONNIFY_API_KEY . ':' . MONNIFY_SECRET_KEY)],
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 30
]);

$body = curl_exec($ch);
$curl_error = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curl_error) {
    echo "❌ CURL Error: $curl_error\n";
    exit(1);
}

$response = json_decode($body, true);
if (!isset($response['responseBody']['accessToken'])) {
    echo "❌ Auth Failed (HTTP $status): " . ($response['responseMessage'] ?? 'Unknown error') . "\n";
    exit(1);
}

$access_token = $response['responseBody']['accessToken'];
echo "✅ Authentication successful\n";
echo "   Token: " . substr($access_token, 0, 20) . "...\n";
echo "   Expires in: " . ($response['responseBody']['expiresIn'] ?? 'N/A') . " seconds\n\n";

// Test 2: Get Contract Details
echo "[2] Retrieving contract details...\n";
$ch = curl_init(MONNIFY_BASE_URL . '/api/v1/merchant/contracts');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $access_token"],
    CURLOPT_TIMEOUT => 30
]);

$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response = json_decode($body, true);
if (!isset($response['responseBody'][0])) {
    echo "❌ Failed to fetch contracts (HTTP $status)\n";
} else {
    $contract = $response['responseBody'][0];
    echo "✅ Contract found: " . htmlspecialchars($contract['name']) . "\n";
    echo "   Code: " . htmlspecialchars($contract['code']) . "\n";
    echo "   Currency: " . htmlspecialchars($contract['currencyCode']) . "\n";
    echo "   Status: " . htmlspecialchars($contract['status']) . "\n\n";
}

// Test 3: Get Available Banks
echo "[3] Fetching available banks...\n";
$ch = curl_init(MONNIFY_BASE_URL . '/api/v2/bank-transfer/reserved-accounts/banks');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $access_token"],
    CURLOPT_TIMEOUT => 30
]);

$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response = json_decode($body, true);
if (!isset($response['responseBody']) || count($response['responseBody']) === 0) {
    echo "❌ No banks available (HTTP $status)\n";
} else {
    $banks = array_slice($response['responseBody'], 0, 5);
    echo "✅ Available banks (" . count($response['responseBody']) . " total):\n";
    foreach ($banks as $bank) {
        echo "   - " . htmlspecialchars($bank['bankName']) . " (" . htmlspecialchars($bank['code']) . ")\n";
    }
    echo "\n";
}

// Test 4: Create Test Reserved Account
echo "[4] Creating test reserved account...\n";
$test_reference = 'PAYCLUST_TEST_' . time();
$payload = json_encode([
    'accountReference' => $test_reference,
    'accountName' => 'Test User - TNTP',
    'currencyCode' => 'NGN',
    'contractCode' => MONNIFY_CONTRACT_CODE,
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
if (!isset($response['requestSuccessful']) || $response['requestSuccessful'] !== true) {
    echo "❌ Account creation failed (HTTP $status)\n";
    echo "   Error: " . ($response['responseMessage'] ?? 'Unknown') . "\n";
} else {
    $account = $response['responseBody']['accounts'][0] ?? null;
    if (!$account) {
        echo "❌ No account in response\n";
    } else {
        echo "✅ Test account created successfully\n";
        echo "   Reference: " . htmlspecialchars($test_reference) . "\n";
        echo "   Account Number: " . htmlspecialchars($account['accountNumber']) . "\n";
        echo "   Bank: " . htmlspecialchars($account['bankName']) . "\n";
        echo "   Account Name: " . htmlspecialchars($account['accountName']) . "\n";
        echo "   Status: " . htmlspecialchars($account['status']) ?? 'ACTIVE' . "\n";
        
        if (isset($account['incomeSplitConfig'])) {
            echo "   Income Split Enabled: Yes\n";
        }
    }
}

echo "\n=== Test Suite Complete ===\n";
?>
