<?php
require_once '../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Boundary Gate Check
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['cluster_manager', 'user'], true)) {
    header('HTTP/1.1 403 Forbidden');
    die("Access Forbidden: Operations unauthorized inside current cluster node environment authorization context.");
}

$reference = filter_input(INPUT_GET, 'reference', FILTER_DEFAULT);
$user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
$remittance_id = filter_input(INPUT_GET, 'remittance_id', FILTER_VALIDATE_INT);
$cycle_id = filter_input(INPUT_GET, 'cycle_id', FILTER_VALIDATE_INT);

if (empty($reference) || empty($user_id) || empty($cycle_id)) {
    die("Error: Defective telemetry request configuration payload variables missing.");
}

$db = getDB();

// 1. Perform cryptographic signature verification handshake via Paystack Secure Endpoint API Gateway
$paystackSecretKey = PAYSTACK_SECRET_KEY;
$verifyUrl = "https://api.paystack.co/transaction/verify/" . rawurlencode($reference);

$curlSession = curl_init();
curl_setopt($curlSession, CURLOPT_URL, $verifyUrl);
curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curlSession, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $paystackSecretKey,
    "Cache-Control: no-cache"
]);

$rawResponse = curl_exec($curlSession);
$httpStatusCode = curl_getinfo($curlSession, CURLINFO_HTTP_CODE);
curl_close($curlSession);

if ($httpStatusCode !== 200 || !$rawResponse) {
    die("Gateway Connection Fault: Paystack server-side confirmation reference lookup connection broke down.");
}

$payload = json_decode($rawResponse, true);

if ($payload && isset($payload['data']) && $payload['data']['status'] === 'success') {
    
    // Convert payment value units out from standard kobo notation tracking values back to base fractional decimals
    $amountPaidInNaira = floatval($payload['data']['amount'] / 100);
    
    try {
        $db->beginTransaction();

        if (empty($remittance_id)) {
            $stmt = $db->prepare("SELECT id, expected_amount, amount_paid FROM remittance WHERE cycle_id = ? AND userid = ?");
            $stmt->execute([$cycle_id, $user_id]);
            $remitRecord = $stmt->fetch();

            if (!$remitRecord) {
                $uStmt = $db->prepare("SELECT expected_remittance_amount FROM users WHERE id = ?");
                $uStmt->execute([$user_id]);
                $userFallbackAmount = floatval($uStmt->fetchColumn() ?: 0);

                $insertRemit = $db->prepare("INSERT INTO remittance (cycle_id, userid, expected_amount, amount_paid, payment_status) VALUES (?, ?, ?, 0.00, 'UNPAID')");
                $insertRemit->execute([$cycle_id, $user_id, $userFallbackAmount]);
                $remittance_id = $db->lastInsertId();
                $expectedAmount = $userFallbackAmount;
                $currentPaid = 0.00;
            } else {
                $remittance_id = $remitRecord['id'];
                $expectedAmount = floatval($remitRecord['expected_amount']);
                $currentPaid = floatval($remitRecord['amount_paid']);
            }
        } else {
            $stmt = $db->prepare("SELECT expected_amount, amount_paid FROM remittance WHERE id = ?");
            $stmt->execute([$remittance_id]);
            $remitRecord = $stmt->fetch();
            $expectedAmount = floatval($remitRecord['expected_amount'] ?? 0);
            $currentPaid = floatval($remitRecord['amount_paid'] ?? 0);
        }

        $checkTx = $db->prepare("SELECT id FROM payment_history WHERE tx_reference = ?");
        $checkTx->execute([$reference]);
        if ($checkTx->fetch()) {
            $db->rollBack();
            $redirectTarget = ($_SESSION['role'] === 'user') ? APP_BASE_URL . '/users/index.php?success=1' : APP_BASE_URL . '/manager/index.php?success=1';
            header("Location: $redirectTarget");
            exit;
        }

        $newPaidTotal = $currentPaid + $amountPaidInNaira;
        $newRemittanceStatus = ($newPaidTotal >= $expectedAmount) ? 'FULLY_PAID' : 'PARTIAL';

        $updateRemit = $db->prepare("UPDATE remittance SET amount_paid = ?, payment_status = ? WHERE id = ?");
        $updateRemit->execute([$newPaidTotal, $newRemittanceStatus, $remittance_id]);

        $newUserPaymentStatus = ($newPaidTotal >= $expectedAmount) ? 'PAID' : 'PARTIAL';
        $updateUser = $db->prepare("UPDATE users SET amount_paid = amount_paid + ?, payment_status = ? WHERE id = ?");
        $updateUser->execute([$amountPaidInNaira, $newUserPaymentStatus, $user_id]);

        $logPayment = $db->prepare("INSERT INTO payment_history (remittance_id, tx_reference, amount_paid, payment_method, paid_at) VALUES (?, ?, ?, 'PAYSTACK', NOW())");
        $logPayment->execute([$remittance_id, $reference, $amountPaidInNaira]);

        $db->commit();
        $redirectTarget = ($_SESSION['role'] === 'user') ? APP_BASE_URL . '/users/index.php?success=1' : APP_BASE_URL . '/manager/index.php?success=1';
        header("Location: $redirectTarget");
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        die("Critical Storage Error: Database operation failed during updating transaction state pipeline. " . $e->getMessage());
    }
} else {
    die("Transaction Processing Terminated: External settlement declaration trace invalid or marked unsuccessful by processor gateway engine.");
}