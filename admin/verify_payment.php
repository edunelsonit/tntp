<?php
require_once '../config/config.php';

// Enforce explicit administration routing rules
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'], true)) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();
$msg = '';

$id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['id']) ? intval($_POST['id']) : null);
if (!$id) {
    header('Location: users.php');
    exit;
}

// Fetch transaction record linked with target user profile attributes
$stmt = $db->prepare('
    SELECT t.*, u.id AS user_table_id, u.first_name, u.surname, u.expected_remittance_amount, u.amount_paid 
    FROM transactions t
    LEFT JOIN users u ON t.nin = u.nin
    WHERE t.id = ?
');
$stmt->execute([$id]);
$tx = $stmt->fetch();

if (!$tx) {
    header('Location: users.php');
    exit;
}

// ==========================================
// PROCESS EXPLICIT VERIFICATION METHOD (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_verification'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !verifyCsrfToken($csrf_token)) {
        header("Location: verify_payment.php?id={$id}&err=invalid_token");
        exit;
    }

    // Safeguard: Prevent re-running verification calculations if already processed
    if (($tx['status'] ?? '') === 'VERIFIED') {
        $msg = '<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>System Bypass: This transaction has already been verified.</div>';
    } else {
        try {
            $db->beginTransaction();
            $admin_id = intval($_SESSION['admin_id'] ?? 0);

            // 1. Permanently update transaction state markers
            $upd = $db->prepare("UPDATE transactions SET status = 'VERIFIED', processed_by = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$admin_id, $id]);

            // 2. Re-fetch current user record inside the transaction lock context
            $u = $db->prepare('SELECT amount_paid, expected_remittance_amount FROM users WHERE nin = ?');
            $u->execute([$tx['nin']]);
            $user = $u->fetch();

            if ($user) {
                // Calculate incremental step balance changes securely
                $new_paid = floatval($user['amount_paid']) + floatval($tx['amount']);
                
                $status = 'PARTIAL';
                if ($new_paid >= floatval($user['expected_remittance_amount'])) {
                    $status = 'PAID';
                }

                $upd2 = $db->prepare('UPDATE users SET amount_paid = ?, payment_status = ? WHERE nin = ?');
                $upd2->execute([$new_paid, $status, $tx['nin']]);
            }

            // 3. Document structural verification action to system log indexes
            $log = $db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, target_table, target_id, action_details, created_at) VALUES (?, "VERIFY_PAYMENT", "transactions", ?, ?, CURRENT_TIMESTAMP)');
            $details = json_encode([
                'transaction_id' => $id, 
                'nin' => $tx['nin'], 
                'amount' => floatval($tx['amount']),
                'new_total_paid' => $new_paid ?? 0
            ]);
            $log->execute([$admin_id, $id, $details]);

            $db->commit();
            
            // Redirect smoothly back to users panel index upon successful write-back
            header('Location: users.php?success=payment_verified');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $msg = '<div class="alert alert-danger small"><i class="bi bi-shield-slash me-1"></i>Execution Fault: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

include_once '../partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-2 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold"><i class="bi bi-patch-check-fill text-success me-2"></i>Payment Reconciliation Audit</h1>
        <p class="text-muted small mb-0">Manually inspect external payment confirmations and reconcile system ledgers.</p>
    </div>
    <div class="mt-2 mt-md-0">
        <a href="users.php" class="btn btn-sm btn-outline-secondary fw-semibold">
            <i class="bi bi-arrow-left me-1"></i> Abort Reconciliation
        </a>
    </div>
</div>

<?php if (!empty($msg)) echo $msg; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card p-4 border-0 shadow-sm bg-white h-100" style="border-radius: 14px;">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-receipt text-secondary me-1"></i> Entry Details</h5>
            
            <div class="table-responsive">
                <table class="table table-sm table-borderless align-middle small mb-0">
                    <tbody>
                        <tr class="border-bottom border-light">
                            <td class="py-2.5 text-muted fw-semibold" style="width: 35%;">Transaction Reference:</td>
                            <td class="py-2.5 font-monospace fw-bold text-dark">TX-<?php echo str_pad($tx['id'], 6, '0', STR_PAD_LEFT); ?></td>
                        </tr>
                        <tr class="border-bottom border-light">
                            <td class="py-2.5 text-muted fw-semibold">Depositor Profile:</td>
                            <td class="py-2.5 text-dark fw-bold">
                                <?php echo htmlspecialchars(($tx['first_name'] ?? 'Unknown') . ' ' . ($tx['surname'] ?? 'User')); ?>
                                <span class="d-block text-muted font-monospace small fw-normal">NIN: <?php echo htmlspecialchars($tx['nin']); ?></span>
                            </td>
                        </tr>
                        <tr class="border-bottom border-light">
                            <td class="py-2.5 text-muted fw-semibold">Inflow Amount:</td>
                            <td class="py-2.5 font-monospace text-success fw-bold fs-6">₦<?php echo number_format(floatval($tx['amount']), 2); ?></td>
                        </tr>
                        <tr class="border-bottom border-light">
                            <td class="py-2.5 text-muted fw-semibold">Gateway Source Channel:</td>
                            <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($tx['payment_channel'] ?? 'BANK_TRANSFER'); ?></span></td>
                        </tr>
                        <tr class="border-bottom border-light">
                            <td class="py-2.5 text-muted fw-semibold">Channel Reference Key:</td>
                            <td class="py-2.5 font-monospace text-secondary small"><?php echo htmlspecialchars($tx['reference_no'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <td class="py-2.5 text-muted fw-semibold">Current Process State:</td>
                            <td class="py-2.5">
                                <?php if (($tx['status'] ?? 'PENDING') === 'VERIFIED'): ?>
                                    <span class="badge bg-success fw-bold"><i class="bi bi-check-circle-fill me-1"></i> VERIFIED</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark fw-bold"><i class="bi bi-clock-fill me-1"></i> PENDING VERIFICATION</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card p-4 border-0 shadow-sm bg-white h-100 d-flex flex-column justify-content-between" style="border-radius: 14px; border-top: 4px solid #16a34a !important;">
            <div>
                <h5 class="fw-bold text-dark mb-3"><i class="bi bi-calculator text-success me-1"></i> Ledger Impact Forecast</h5>
                <div class="p-3 bg-light rounded-3 mb-4 small text-muted">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Expected Remittance:</span>
                        <span class="fw-bold text-dark font-monospace">₦<?php echo number_format(floatval($tx['expected_remittance_amount'] ?? 0), 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Current Total Settled:</span>
                        <span class="fw-bold text-secondary font-monospace">₦<?php echo number_format(floatval($tx['amount_paid'] ?? 0), 2); ?></span>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between text-dark fw-semibold">
                        <span>Forecast Post-Verification:</span>
                        <span class="font-monospace text-success fw-bold">₦<?php echo number_format(floatval($tx['amount_paid'] ?? 0) + floatval($tx['amount']), 2); ?></span>
                    </div>
                </div>
            </div>

            <div>
                <?php if (($tx['status'] ?? '') === 'VERIFIED'): ?>
                    <button type="button" class="btn btn-secondary w-100 py-2.5 fw-bold" disabled>
                        <i class="bi bi-lock-fill me-1"></i> Reconciliation Complete
                    </button>
                <?php else: ?>
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                        <input type="hidden" name="confirm_verification" value="1">
                        
                        <div class="alert alert-warning border-0 small px-3 py-2.5 rounded-3 mb-3" style="font-size: 0.75rem;">
                            <i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i> 
                            <strong>Audit Checkpoint:</strong> Confirming this transaction applies the entry amount directly to the user's payment balances. This action cannot be reversed.
                        </div>

                        <button type="submit" class="btn btn-success w-100 py-2.5 fw-bold shadow-sm" onclick="return confirm('CONFIRM LEDGER AMENDMENT:\n\nAre you sure you want to verify this payment? This directly alters user accounting records.');">
                            <i class="bi bi-shield-check me-1"></i> Commit Verification Approval
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>