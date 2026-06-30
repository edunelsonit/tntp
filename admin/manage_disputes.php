<?php
require_once '../config/config.php';
require_once '../core/DisputeManager.php';

// Enforce access control barriers
checkRouteAccess('admin');

$db = getDB();
$manager = new DisputeManager($db);
$msg = '';

// ==========================================
// PROCESS DISPUTE DECISION SUBMISSIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        header('Location: manage_disputes.php?err=invalid_token');
        exit;
    }

    $disputeId = intval($_POST['dispute_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes = sanitizeText($_POST['notes'] ?? '');

    if ($disputeId && in_array($action, ['approve', 'reject'], true)) {
        if (empty($notes)) {
            $msg = '<div class="alert alert-danger small"><i class="bi bi-x-circle me-1"></i>You must provide internal audit notes to justify this action.</div>';
        } else {
            if ($action === 'approve') {
                $manager->decideDispute($disputeId, 'APPROVED_ADJUSTED', intval($_SESSION['admin_id'] ?? 0), $notes);
                $msg = '<div class="alert alert-success small"><i class="bi bi-check-circle-fill me-1"></i>Dispute #' . $disputeId . ' approved. Eligible adjustments will apply post grace-expiry.</div>';
            } else {
                $manager->decideDispute($disputeId, 'REJECTED', intval($_SESSION['admin_id'] ?? 0), $notes);
                $msg = '<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle-fill me-1"></i>Dispute #' . $disputeId . ' rejected. Cluster manager audit trail logged.</div>';
            }
        }
    }
}

// Automatically process routine system transitions
$adjusted = $manager->applyPendingNoSalaryAdjustments();
if ($adjusted > 0) {
    $msg .= '<div class="alert alert-info small"><i class="bi bi-gear-fill me-1"></i>System automatically processed ' . intval($adjusted) . ' pending adjustments.</div>';
}

// Fetch pending records matching your schema topology
$pendingDisputes = $db->query(
    'SELECT d.*, u.first_name, u.surname, u.nin, c.cycle_period
     FROM disputes d
     JOIN users u ON u.id = d.userid
     JOIN remittance r ON r.id = d.remittance_id
     JOIN remittance_cycles c ON c.id = r.cycle_id
     WHERE d.dispute_status = "PENDING"
     ORDER BY d.dispute_time DESC'
)->fetchAll();

include_once '../partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold"><i class="bi bi-shield-exclamation text-primary me-2"></i>Dispute Management Ledger</h1>
        <p class="text-muted small mb-0">Review active employee payment issues, check associated validation payloads, and commit adjustments.</p>
    </div>
    <a href="<?php echo APP_BASE_URL; ?>/admin/index.php" class="btn btn-sm btn-outline-secondary fw-semibold">
        <i class="bi bi-speedometer2 me-1"></i> Back to Dashboard
    </a>
</div>

<?php if (!empty($msg)) echo $msg; ?>

<div class="card shadow-sm border-0 p-4 bg-white rounded-4">
    <div class="table-responsive">
        <table class="table table-hover small align-middle mb-0">
            <thead class="table-light text-secondary">
                <tr>
                    <th style="width: 8%;">ID</th>
                    <th style="width: 22%;">User Details</th>
                    <th style="width: 15%;">Cycle</th>
                    <th style="width: 15%;">Type</th>
                    <th style="width: 15%;">Submitted</th>
                    <th style="width: 10%;">Status</th>
                    <th style="width: 15%;" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pendingDisputes)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-2 text-light d-block mb-2"></i>
                            No pending dispute records found. All systems balanced.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pendingDisputes as $dispute): 
                        $dId = intval($dispute['id']); 
                    ?>
                        <tr>
                            <td class="fw-bold font-monospace text-secondary">#<?php echo $dId; ?></td>
                            <td>
                                <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($dispute['first_name'] . ' ' . $dispute['surname']); ?></span>
                                <small class="text-muted font-monospace d-block" style="font-size: 0.75rem;">NIN: <?php echo htmlspecialchars($dispute['nin']); ?></small>
                            </td>
                            <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($dispute['cycle_period']); ?></span></td>
                            <td class="text-secondary fw-semibold"><?php echo htmlspecialchars($dispute['dispute_type']); ?></td>
                            <td class="text-muted font-monospace"><?php echo htmlspecialchars($dispute['dispute_time']); ?></td>
                            <td><span class="badge bg-warning-subtle text-warning fw-bold">PENDING</span></td>
                            <td>
                                <div class="d-flex gap-1 justify-content-center">
                                    <a href="<?php echo htmlspecialchars($dispute['proof_receipt_path']); ?>" class="btn btn-xs btn-outline-primary" target="_blank" title="View Evidence Document">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <button class="btn btn-xs btn-success" data-bs-toggle="modal" data-bs-target="#approveModal<?php echo $dId; ?>" title="Approve Claim">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <button class="btn btn-xs btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $dId; ?>" title="Reject Claim">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>

                                <!-- APPROVAL MODAL WORKSPACE -->
                                <div class="modal fade" id="approveModal<?php echo $dId; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content border-0 shadow-lg">
                                            <div class="modal-header bg-success text-white">
                                                <h5 class="modal-title h6 fw-bold"><i class="bi bi-check-circle me-2"></i>Approve Dispute Application #<?php echo $dId; ?></h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body py-3">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                                                    <input type="hidden" name="dispute_id" value="<?php echo $dId; ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    
                                                    <div class="mb-2 bg-light p-2.5 rounded text-secondary mb-3" style="font-size:0.8rem;">
                                                        <strong>Notice:</strong> Approving this adjustments ledger authorizes remediation recalculations for <strong><?php echo htmlspecialchars($dispute['first_name'] . ' ' . $dispute['surname']); ?></strong>.
                                                    </div>
                                                    <div class="mb-1">
                                                        <label class="form-label small fw-bold text-dark">Administrative Rationale & Approval Notes</label>
                                                        <textarea name="notes" class="form-control form-control-sm" rows="3" placeholder="Reference clearing authorization codes or evidence tracking parameters..." required></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer bg-light border-0 py-2">
                                                    <button type="button" class="btn btn-secondary btn-xs fw-semibold" data-bs-dismiss="modal">Dismiss</button>
                                                    <button type="submit" class="btn btn-success btn-xs fw-bold">Commit Approval</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- REJECTION MODAL WORKSPACE -->
                                <div class="modal fade" id="rejectModal<?php echo $dId; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content border-0 shadow-lg">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title h6 fw-bold"><i class="bi bi-shield-x me-2"></i>Reject Dispute Application #<?php echo $dId; ?></h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body py-3">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                                                    <input type="hidden" name="dispute_id" value="<?php echo $dId; ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    
                                                    <div class="mb-1">
                                                        <label class="form-label small fw-bold text-dark">Justification & Rejection Explanatory Notes</label>
                                                        <textarea name="notes" class="form-control form-control-sm" rows="3" placeholder="Provide details explaining why this dispute verification request is being rejected..." required></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer bg-light border-0 py-2">
                                                    <button type="button" class="btn btn-secondary btn-xs fw-semibold" data-bs-dismiss="modal">Dismiss</button>
                                                    <button type="submit" class="btn btn-danger btn-xs fw-bold">Reject Dispute</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>