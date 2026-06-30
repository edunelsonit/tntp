<?php
require_once '../config/config.php';

// Enforce explicit administration routing rules
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'], true)) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();
$msg = '';
$rawPayloadOutput = '';

// ==========================================
// PROCESS PAYLOAD SIMULATION (POST ROUTE)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simulate_webhook'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !verifyCsrfToken($csrf_token)) {
        header('Location: webhook_test.php?err=invalid_token');
        exit;
    }

    $target_nin = trim($_POST['nin'] ?? '');
    $payment_amount = floatval($_POST['amount'] ?? 0);
    $payment_channel = $_POST['payment_channel'] ?? 'BANK_TRANSFER';
    $gateway_status = $_POST['gateway_status'] ?? 'successful'; // successful, failed

    // Basic data sanity validation check
    if (empty($target_nin) || strlen($target_nin) !== 11 || !is_numeric($target_nin)) {
        $msg = '<div class="alert alert-danger small"><i class="bi bi-x-circle me-1"></i>Simulation Fault: A valid 11-digit NIN parameter is required.</div>';
    } elseif ($payment_amount <= 0) {
        $msg = '<div class="alert alert-danger small"><i class="bi bi-x-circle me-1"></i>Simulation Fault: Transaction input amount must be greater than zero.</div>';
    } else {
        // Build mock structured gateway callback parameters matching production payload structures
        $mockPayload = [
            'event' => 'charge.success',
            'data' => [
                'id' => rand(100000, 999999),
                'domain' => 'test',
                'status' => $gateway_status,
                'reference' => 'MOCK_REF_' . strtoupper(bin2hex(random_bytes(6))),
                'amount' => $payment_amount * 100, // Normalized gateway minor subunits (kobo)
                'currency' => 'NGN',
                'channel' => strtolower($payment_channel),
                'paid_at' => date('c'),
                'metadata' => [
                    'nin' => $target_nin
                ],
                'customer' => [
                    'email' => 'simulated_customer@example.com'
                ]
            ]
        ];

        $rawPayloadOutput = json_encode($mockPayload, JSON_PRETTY_PRINT);

        // Execute local loopback network request calling your system's webhook processing route
        try {
            $webhookUrl = APP_BASE_URL . '/webhooks/payment_handler.php';
            
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mockPayload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Webhook-Signature: ' . hash_hmac('sha512', json_encode($mockPayload), 'TEST_SIGNING_KEY')
            ]);
            // Prevent execution drops during local self-signed testing environments
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $response = curl_exec($ch);
            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $msg = '
            <div class="alert alert-info border-0 shadow-sm mb-4 small">
                <h6 class="fw-bold mb-1"><i class="bi bi-cpu-fill me-1"></i>Loopback Transmission Complete:</h6>
                <p class="mb-1">Target Endpoint Address: <code>' . htmlspecialchars($webhookUrl) . '</code></p>
                <p class="mb-0">HTTP Endpoint Code Response: <strong>' . $httpStatusCode . '</strong> | Server Message Payload: <code>' . htmlspecialchars($response) . '</code></p>
            </div>';
        } catch (Exception $e) {
            $msg = '<div class="alert alert-danger small"><i class="bi bi-exclamation-octagon me-1"></i>Transmission Drop Exception: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

include_once '../partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-2 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold"><i class="bi bi-terminal-split text-secondary me-2"></i>Gateway Webhook Simulation Terminal</h1>
        <p class="text-muted small mb-0">Emulate third-party payment settlement responses to check ledger logic behavior.</p>
    </div>
    <div class="mt-2 mt-md-0">
        <a href="<?php echo APP_BASE_URL; ?>/admin/index.php" class="btn btn-sm btn-outline-secondary fw-semibold">
            <i class="bi bi-speedometer2 me-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if (!empty($msg)) echo $msg; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card p-4 border-0 shadow-sm bg-white h-100" style="border-radius: 14px;">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-sliders me-1"></i> Mock Parameter Configuration</h5>
            
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Target System Member (NIN Reference)</label>
                    <input type="text" name="nin" class="form-control form-control-sm font-monospace text-dark fw-bold" placeholder="e.g., 12345678901" maxlength="11" required>
                    <div class="form-text small text-muted" style="font-size: 0.7rem;">Matches a valid 11-digit NIN entry within the core identity metrics tables.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Settlement Inflow Amount (₦)</label>
                    <input type="number" step="0.01" name="amount" class="form-control form-control-sm font-monospace" placeholder="100000.00" required>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold text-secondary">Payment Channel</label>
                        <select name="payment_channel" class="form-select form-select-sm">
                            <option value="BANK_TRANSFER">Bank Transfer</option>
                            <option value="CARD">Credit/Debit Card</option>
                            <option value="USSD">USSD Code Stream</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold text-secondary">Gateway Return Event</label>
                        <select name="gateway_status" class="form-select form-select-sm fw-bold border-secondary">
                            <option value="successful" class="text-success">SUCCESSFUL (200)</option>
                            <option value="failed" class="text-danger">FAILED (400)</option>
                        </select>
                    </div>
                </div>

                <div class="bg-light p-3 rounded-3 mb-4 small text-secondary">
                    <i class="bi bi-info-circle-fill me-1 text-primary"></i>
                    <strong>Integration Note:</strong> Transmitting this test injects a virtual API payload into your local <code>payment_handler.php</code> handler script, running your database parsing rules automatically.
                </div>

                <button type="submit" name="simulate_webhook" class="btn btn-primary w-100 py-2.5 fw-bold shadow-sm">
                    <i class="bi bi-lightning-charge-fill me-1"></i> Fire Webhook Simulation
                </button>
            </form>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card p-4 border-0 shadow-sm bg-dark text-light h-100" style="border-radius: 14px; font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;">
            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary">
                <h5 class="fw-bold mb-0 text-white h6"><i class="bi bi-code-square me-1 text-warning"></i> Serialized JSON Struct Monitor</h5>
                <span class="badge bg-secondary text-uppercase tracking-wide px-2 py-1" style="font-size:0.65rem;">Application Output</span>
            </div>
            
            <?php if (!empty($rawPayloadOutput)): ?>
                <pre class="small text-warning-emphasis mb-0 overflow-auto p-2 bg-black rounded" style="max-height: 380px; font-size:0.8rem;"><?php echo htmlspecialchars($rawPayloadOutput); ?></pre>
            <?php else: ?>
                <div class="text-center text-muted my-auto py-5">
                    <i class="bi bi-hdd-stack fs-1 d-block mb-2 text-secondary opacity-50"></i>
                    Awaiting simulated post event iteration loop to process logging telemetry details.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>