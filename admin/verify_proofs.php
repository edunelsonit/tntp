<?php
require_once '../config/config.php';
require_once '../core/RemittanceManager.php';
require_once '../core/DisputeManager.php';

// Enforce access control barriers
checkRouteAccess('admin');

$db = getDB();
$msg = '';

// ==========================================
// PROCESS PROOF VERIFICATION DIRECTIVES (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        header('Location: verify_proofs.php?err=invalid_token');
        exit;
    }

    $tx_id = intval($_POST['tx_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($tx_id && in_array($action, ['verify', 'reject'], true)) {
        try {
            $db->beginTransaction();
            $admin_id = intval($_SESSION['admin_id'] ?? 0);

            if ($action === 'verify') {
                // 1. Update the primary transaction status to VERIFIED
                $stmt = $db->prepare('UPDATE transactions SET status = ?, processed_by = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = "PENDING"');
                $stmt->execute(['VERIFIED', $admin_id, $tx_id]);

                if ($stmt->rowCount() > 0) {
                    // 2. Fetch linked details to safely update account ledgers
                    $txStmt = $db->prepare('SELECT nin, amount, tx_reference FROM transactions WHERE id = ?');
                    $txStmt->execute([$tx_id]);
                    $txdata = $txStmt->fetch();

                    if ($txdata) {
                        $remittanceManager = new RemittanceManager($db);
                        $disputeManager = new DisputeManager($db);
                        $remittance = $remittanceManager->getLatestRemittanceForUser($txdata['nin']);
                        
                        if ($remittance) {
                            $reference = substr($txdata['tx_reference'] . '_ADM_' . time(), 0, 100);
                            $disputeManager->recordManualAdminPayment((int)$remittance['id'], (float)$txdata['amount'], $admin_id, $reference);
                        }
                    }

                    // 3. Document action to system log indexes
                    $log = $db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, target_table, target_id, action_details, created_at) VALUES (?, "VERIFY_PAYMENT_PROOF", "transactions", ?, ?, CURRENT_TIMESTAMP)');
                    $log->execute([$admin_id, $tx_id, "Verified transaction ID: $tx_id | Ref: " . ($txdata['tx_reference'] ?? 'N/A')]);
                }
                
                $db->commit();
                header('Location: verify_proofs.php?success=proof_verified');
                exit;

            } elseif ($action === 'reject') {
                $reason = trim($_POST['reason'] ?? '');
                if (empty($reason)) {
                    throw new Exception("Rejection requires a structural audit note reason.");
                }

                // 1. Update the transaction status to REJECTED
                $stmt = $db->prepare('UPDATE transactions SET status = ?, processed_by = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = "PENDING"');
                $stmt->execute(['REJECTED', $admin_id, $tx_id]);

                if ($stmt->rowCount() > 0) {
                    // 2. Document rejection to system log indexes
                    $log = $db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, target_table, target_id, action_details, created_at) VALUES (?, "REJECT_PAYMENT_PROOF", "transactions", ?, ?, CURRENT_TIMESTAMP)');
                    $log->execute([$admin_id, $tx_id, "Rejected transaction ID: $tx_id. Reason: $reason"]);
                }

                $db->commit();
                header('Location: verify_proofs.php?success=proof_rejected');
                exit;
            }

        } catch (Exception $e) {
            $db->rollBack();
            $msg = '<div class="alert alert-danger small"><i class="bi bi-exclamation-octagon-fill me-1"></i>Transaction Aborted: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Fetch lists matching layout expectations
$pending = $db->query("
    SELECT t.*, u.first_name, u.surname, u.nin, u.cluster_code, c.cluster_name 
    FROM transactions t 
    JOIN users u ON u.nin = t.nin 
    LEFT JOIN clusters c ON c.cluster_code = u.cluster_code 
    WHERE t.status = 'PENDING' 
    ORDER BY t.payment_date DESC
")->fetchAll();

$verified = $db->query("
    SELECT t.*, u.first_name, u.surname, u.nin, u.cluster_code, c.cluster_name 
    FROM transactions t 
    JOIN users u ON u.nin = t.nin 
    LEFT JOIN clusters c ON c.cluster_code = u.cluster_code 
    WHERE t.status = 'VERIFIED' 
    ORDER BY t.processed_at DESC LIMIT 20
")->fetchAll();

include_once '../partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-2 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold"><i class="bi bi-shield-check text-primary me-2"></i>Payment Proof Verification</h1>
        <p class="text-muted small mb-0">Audit and verify manual payment receipts submitted by network members against external accounts.</p>
    </div>
    <div class="mt-2 mt-md-0">
        <a href="<?php echo APP_BASE_URL; ?>/admin/index.php" class="btn btn-sm btn-outline-secondary fw-semibold">
            <i class="bi bi-speedometer2 me-1"></i> Dashboard Base
        </a>
    </div>
</div>

<?php if (!empty($msg)) echo $msg; ?>

<div class="card shadow-sm border-0 p-4 bg-white mb-4" style="border-radius: 14px;">
    <h5 class="fw-bold text-dark mb-3 d-flex align-items-center">
        <i class="bi bi-hourglass-split text-warning me-2"></i> Pending Verification Queue 
        <span class="badge bg-warning text-dark ms-2 rounded-pill fs-6" style="padding: 0.25rem 0.6rem;"><?php echo count($pending); ?></span>
    </h5>
    
    <?php if (empty($pending)): ?>
        <div class="text-center py-4 text-muted small">
            <i class="bi bi-check-circle fs-3 text-light d-block mb-2"></i>
            No pending validation proofs require immediate attention.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle small mb-0">
                <thead class="table-light text-secondary">
                    <tr>
                        <th>Reference</th>
                        <th>Identity Index (NIN)</th>
                        <th>User Name</th>
                        <th>Cluster Assign</th>
                        <th>Value Amount</th>
                        <th>Date Submitted</th>
                        <th class="text-center">Evidence Proof</th>
                        <th class="text-center">Action Framework</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $p): $pId = intval($p['id']); ?>
                        <tr>
                            <td class="font-monospace fw-bold text-secondary">
                                <span title="<?php echo htmlspecialchars($p['tx_reference']); ?>">
                                    <?php echo htmlspecialchars(substr($p['tx_reference'], 0, 12)); ?>...
                                </span>
                            </td>
                            <td class="font-monospace text-dark"><?php echo htmlspecialchars($p['nin']); ?></td>
                            <td class="fw-semibold text-dark"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['surname']); ?></td>
                            <td><span class="badge bg-light text-secondary border"><?php echo htmlspecialchars($p['cluster_name'] ?? $p['cluster_code']); ?></span></td>
                            <td class="fw-bold text-dark font-monospace">₦<?php echo number_format($p['amount'], 2); ?></td>
                            <td class="text-muted font-monospace"><?php echo htmlspecialchars(substr($p['payment_date'], 0, 10)); ?></td>
                            <td class="text-center">
                                <?php if (!empty($p['receipt_path'])): ?>
                                    <a href="<?php echo htmlspecialchars($p['receipt_path']); ?>" class="btn btn-xs btn-outline-primary fw-semibold" target="_blank">
                                        <i class="bi bi-file-earmark-pdf me-1"></i> View Receipt
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small italic">Missing Asset</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-inline-flex gap-1">
                                    <button class="btn btn-xs btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#verifyModal" onclick="setVerifyId(<?php echo $pId; ?>)">
                                        <i class="bi bi-check-lg"></i> Verify
                                    </button>
                                    <button class="btn btn-xs btn-outline-danger fw-bold" data-bs-toggle="modal" data-bs-target="#rejectModal" onclick="setRejectId(<?php echo $pId; ?>)">
                                        <i class="bi bi-x-lg"></i> Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0 p-4 bg-white" style="border-radius: 14px;">
    <h5 class="fw-bold text-dark mb-3 d-flex align-items-center">
        <i class="bi bi-bookmark-check text-success me-2"></i> Recently Approved Ledger History 
        <span class="badge bg-success-subtle text-success ms-2 rounded-pill fs-6" style="padding: 0.25rem 0.6rem;"><?php echo count($verified); ?></span>
    </h5>
    
    <?php if (empty($verified)): ?>
        <p class="text-muted small mb-0 py-2"><i class="bi bi-info-circle me-1"></i>No recently verified payment entries recorded inside this window.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle small mb-0">
                <thead class="table-light text-secondary">
                    <tr>
                        <th>Reference Token</th>
                        <th>User Profile Node</th>
                        <th>Value Settled</th>
                        <th>Verification Timestamp</th>
                        <th>Authorized Operator ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($verified as $v): ?>
                        <tr>
                            <td class="font-monospace text-secondary"><code><?php echo htmlspecialchars(substr($v['tx_reference'], 0, 16)); ?>...</code></td>
                            <td class="fw-semibold text-dark"><?php echo htmlspecialchars($v['first_name'] . ' ' . $v['surname']); ?> <small class="text-muted font-monospace d-block" style="font-size:0.7rem;">NIN: <?php echo htmlspecialchars($v['nin']); ?></small></td>
                            <td class="font-monospace fw-bold text-success">₦<?php echo number_format($v['amount'], 2); ?></td>
                            <td class="text-muted font-monospace"><?php echo htmlspecialchars($v['processed_at']); ?></td>
                            <td><span class="badge bg-light text-dark border font-monospace">UID-<?php echo str_pad($v['processed_by'] ?? 0, 4, '0', STR_PAD_LEFT); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="verifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title h6 fw-bold"><i class="bi bi-check-circle me-2"></i>Authorize Inflow Verification</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="modal-body py-3 text-center">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="tx_id" id="verifyTxId">
                    <input type="hidden" name="action" value="verify">
                    <p class="text-secondary small mb-0">
                        Are you sure you want to approve this transaction token entry? This action processes ledger balances and automatically posts credits to the associated allocation month cycle.
                    </p>
                </div>
                <div class="modal-footer bg-light border-0 py-2">
                    <button type="button" class="btn btn-secondary btn-xs fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-xs fw-bold">Commit Verification</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title h6 fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Void Payment Entry Reference</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="modal-body py-3">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="tx_id" id="rejectTxId">
                    <input type="hidden" name="action" value="reject">
                    
                    <div class="mb-1">
                        <label class="form-label small fw-bold text-dark">Audit Justification & Rejection Notes</label>
                        <textarea name="reason" class="form-control form-control-sm" rows="3" required placeholder="Detail specific discrepancies (e.g., Image illegible, transaction value mismatch, invalid reference sequence)..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-2">
                    <button type="button" class="btn btn-secondary btn-xs fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-xs fw-bold">Dismiss Receipt Proof</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/**
 * Isolates dynamic tracking assignments across modals to prevent DOM state collision leaks
 */
function setVerifyId(tx_id) {
    document.getElementById('verifyTxId').value = parseInt(tx_id, 10);
}

function setRejectId(tx_id) {
    document.getElementById('rejectTxId').value = parseInt(tx_id, 10);
}
</script>

<?php include_once '../partials/footer.php'; ?>