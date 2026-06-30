<?php
require_once '../config/config.php';
require_once __DIR__ . '/../core/RemittanceManager.php';

// Set production standard API signature headers
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed. Webhook expects POST iterations."]);
    exit;
}

$signature   = $_SERVER['HTTP_VERIF_HASH'] ?? $_SERVER['HTTP_X_FLW_SIGNATURE'] ?? '';
$raw_payload = file_get_contents('php://input');

if (empty($raw_payload)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing webhook payload."]);
    exit;
}

if (!empty($signature) && !empty(FLUTTERWAVE_SECRET_HASH) && FLUTTERWAVE_SECRET_HASH !== 'REPLACE_ME') {
    $computed_signature = hash_hmac('sha256', $raw_payload, FLUTTERWAVE_SECRET_HASH);
    if (!hash_equals($computed_signature, $signature)) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Security verification fault. Signature mismatch."]);
        exit;
    }
}

$payload = json_decode($raw_payload, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON serialization format."]);
    exit;
}

$event_type = $payload['event']['type'] ?? $payload['event']['name'] ?? $payload['event'] ?? '';
$event_data = $payload['data'] ?? $payload;
$is_successful = false;

if ($event_type === 'charge.completed' || ($event_data['status'] ?? '') === 'successful' || ($payload['status'] ?? '') === 'successful') {
    $is_successful = true;
}

if (!$is_successful) {
    http_response_code(200);
    echo json_encode(["status" => "ignored", "message" => "Event type bypassed. Ledger write skipped."]);
    exit;
}

$tx_ref      = trim((string)($event_data['tx_ref'] ?? $event_data['transaction_reference'] ?? $event_data['id'] ?? ''));
$amount      = floatval($event_data['amount'] ?? $event_data['charged_amount'] ?? $event_data['amount_paid'] ?? 0);
$product_ref = trim((string)($event_data['tx_ref'] ?? $event_data['meta']['reference'] ?? $event_data['order_ref'] ?? ''));

if (empty($tx_ref) || empty($product_ref) || $amount <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Incomplete transaction payload telemetry parameters."]);
    exit;
}

try {
    $db = getDB();
    
    // Begin database transaction to maintain strict multi-table balancing locks
    $db->beginTransaction();

    // 1. Idempotency Check: Verify this event hasn't already been processed (use payment_history)
    $dup_stmt = $db->prepare("SELECT id FROM payment_history WHERE tx_reference = ? FOR UPDATE");
    $dup_stmt->execute([$tx_ref]);
    if ($dup_stmt->fetch()) {
        $db->rollBack();
        http_response_code(200);
        echo json_encode(["status" => "ignored", "message" => "Event already processed. Balance preservation skip applied."]);
        exit;
    }

    // 2. Fetch target user via Flutterwave reference link within an isolated row lock context
    $stmt = $db->prepare("SELECT id, nin, expected_remittance_amount FROM users WHERE monnify_reference = ? OR email = ? FOR UPDATE");
    $customer_email = $event_data['customer']['email'] ?? $event_data['email'] ?? '';
    $stmt->execute([$product_ref, $customer_email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        error_log("Flutterwave Webhook Warning: Unmapped transaction reference dropped safely. Ref: $tx_ref");
        $db->rollBack();
        http_response_code(200);
        echo json_encode(["status" => "unmapped", "message" => "Transaction parsed but profile association could not be matched."]);
        exit;
    }

    $nin = $user['nin'];
    $receipt_number = 'RCT-' . strtoupper(substr(hash('sha256', $tx_ref), 0, 12));

    // 3. Identify latest remittance for the user
    $remittanceManager = new RemittanceManager($db);
    $latest = $remittanceManager->getLatestRemittanceForUser($nin);
    if (!$latest) {
        error_log("Flutterwave Webhook Warning: No remittance record for user NIN $nin. Ref: $tx_ref");
        $db->rollBack();
        http_response_code(200);
        echo json_encode(["status" => "unmapped_remittance", "message" => "No remittance record for user."]);
        exit;
    }

    // 4. Insert payment record into payment_history
    $channel = strtoupper($event_data['payment_method'] ?? $event_data['paymentMethod'] ?? 'FLUTTERWAVE');
    $payment_date = !empty($event_data['paidOn']) ? date('Y-m-d H:i:s', strtotime($event_data['paidOn'])) : date('Y-m-d H:i:s');
    $ins = $db->prepare("INSERT INTO payment_history (remittance_id, tx_reference, amount_paid, payment_method, receipt_number, processed_by_admin_id, paid_at) VALUES (?, ?, ?, ?, ?, NULL, ?)");
    $ins->execute([(int)$latest['id'], $tx_ref, $amount, $channel, $receipt_number, $payment_date]);

    // 5. Update remittance ledger via RemittanceManager
    $updated = $remittanceManager->updateRemittancePayment((int)$latest['id'], $amount);
    if (!$updated) {
        throw new Exception('Failed to update remittance payment status');
    }

    // 6. Record lightweight admin action log
    $log = $db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, details, created_at) VALUES (0, "WEBHOOK_AUTO_SETTLE", ?, CURRENT_TIMESTAMP)');
    $details = json_encode([
        'tx_reference' => $tx_ref,
        'nin' => $nin,
        'amount_credited' => $amount,
        'remittance_id' => (int)$latest['id']
    ]);
    $log->execute([$details]);

    $db->commit();

    http_response_code(200);
    echo json_encode(["status" => "success", "message" => "Ledger updated cleanly.", "receipt" => $receipt_number]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    // Fail closed under a 500 error code to trigger Monnify's automated query retry queues
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Internal processing pipeline breakdown.", "detail" => $e->getMessage()]);
}
?>