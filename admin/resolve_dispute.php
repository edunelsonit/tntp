<?php
require_once '../config/config.php';

// Enforce strict administrative boundary controls
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'], true)) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();
$msg = '';
$dispute_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['dispute_id']) ? intval($_POST['dispute_id']) : 0);

// Fetch target dispute details along with contextual user profile attributes
$stmt = $db->prepare("
    SELECT d.*, u.first_name, u.surname, u.email, u.cluster_code 
    FROM disputes d 
    JOIN users u ON d.user_id = u.id 
    WHERE d.id = ?
");
$stmt->execute([$dispute_id]);
$dispute = $stmt->fetch();

if (!$dispute) {
    include_once '../partials/header.php';
    echo '<div class="alert alert-danger my-4"><i class="bi bi-exclamation-octagon me-2"></i>Target dispute record reference token missing or invalid.</div>';
    echo '<a href="manage_disputes.php" class="btn btn-sm btn-secondary">Return to Ledger</a>';
    include_once '../partials/footer.php';
    exit;
}

// ==========================================
// PROCESS STATE MUTATION (POST ROUTE)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_dispute'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !verifyCsrfToken($csrf_token)) {
        header("Location: resolve_dispute.php?id={$dispute_id}&err=invalid_token");
        exit;
    }

    $resolution_status = $_POST['resolution_status'] ?? ''; // 'RESOLVED_PAID' or 'REJECTED'
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    if (!in_array($resolution_status, ['RESOLVED_PAID', 'REJECTED'], true)) {
        $msg = '<div class="alert alert-danger small"><i class="bi bi-x-circle me-1"></i>Invalid resolution status parameter selected.</div>';
    } elseif (empty($admin_notes)) {
        $msg = '<div class="alert alert-danger small"><i class="bi bi-x-circle me-1"></i>Administrative justification and investigation audit notes are required.</div>';
    } else {
        try {
            // Begin atomic transaction to isolate structural table changes
            $db->beginTransaction();

            // 1. Update the primary dispute tracker card entry
            $updateDispute = $db->prepare("
                UPDATE disputes 
                SET status = ?, admin_notes = ?, resolved_at = CURRENT_TIMESTAMP, resolved_by = ? 
                WHERE id = ?
            ");
            $updateDispute->execute([$resolution_status, $admin_notes, $_SESSION['admin_id'] ?? 0, $dispute_id]);

            // 2. If resolution is confirmed as paid, adjust user parameters in the core registry table
            if ($resolution_status === 'RESOLVED_PAID') {
                $updateUser = $db->prepare("
                    UPDATE users 
                    SET payment_status = 'PAID', working_status = 'ACTIVE' 
                    WHERE id = ?
                ");
                $updateUser->execute([$dispute['user_id']]);
            }

            // 3. Write changes down to the immutable administrative audit logging engine
            $logAction = $db->prepare("
                INSERT INTO admin_action_logs (admin_id, action_type, target_table, target_id, action_details, created_at) 
                VALUES (?, 'RESOLVE_DISPUTE', 'disputes', ?, ?, CURRENT_TIMESTAMP)
            ");
            $details_string = "Dispute ID: {$dispute_id} updated to status: {$resolution_status}. Notes: {$admin_notes}";
            $logAction->execute([$_SESSION['admin_id'] ?? 0, $dispute_id, $details_string]);

            $db->commit();
            
            header("Location: manage_disputes.php?success=dispute_resolved");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $msg = '<div class="alert alert-danger small"><i class="bi bi-exclamation-triangle-fill me-1"></i>Transaction Aborted: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

include_once '../partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold"><i class="bi bi-file-earmark-medical text-primary me-2"></i>Dispute Investigation Terminal</h1>
        <p class="text-muted small mb-0">Review claims, check external verification references, and commit structural adjustments.</p>
    </div>
    <a href="manage_disputes.php" class="btn btn-sm btn-outline-secondary fw-semibold">
        <i class="bi bi-arrow-left me-1"></i> Back to Ledger
    </a>
</div>

<?php if (!empty($msg)) echo $msg; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card p-4 border-0 shadow-sm bg-white h-100" style="border-radius: 14px;">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-person-lines-fill text-secondary me-1"></i> Case Profile Information</h5>
            
            <table class="table table-sm table-borderless align-middle small mb-0">
                <tbody>
                    <tr class="border-bottom border-light">
                        <td class="py-2 text-muted fw-semibold" style="width: 35%;">Claimant Name:</td>
                        <td class="py-2 text-dark fw-bold"><?php echo htmlspecialchars($dispute['first_name'] . ' ' . $dispute['surname']); ?></td>
                    </tr>
                    <tr class="border-bottom border-light">
                        <td class="py-2 text-muted fw-semibold">Email Target:</td>
                        <td class="py-2 font-monospace text-secondary"><?php echo htmlspecialchars($dispute['email']); ?></td>
                    </tr>
                    <tr class="border-bottom border-light">
                        <td class="py-2 text-muted fw-semibold">Cluster Assignment:</td>
                        <td><span class="badge bg-primary-subtle text-primary fw-bold"><?php echo htmlspecialchars($dispute['cluster_code']); ?></span></td>
                    </tr>
                    <tr class="border-bottom border-light">
                        <td class="py-2 text-muted fw-semibold">Claim Subject:</td>
                        <td class="py-2 text-dark fw-semibold"><?php echo htmlspecialchars($dispute['claim_type'] ?? 'Payment Deficit'); ?></td>
                    </tr>
                    <tr class="border-bottom border-light">
                        <td class="py-2 text-muted fw-semibold">Filing Date:</td>
                        <td class="py-2 font-monospace text-muted"><?php echo htmlspecialchars($dispute['created_at']); ?></td>
                    </tr>
                    <tr>
                        <td class="py-2 text-muted fw-semibold" valign="top">Claimant Statement:</td>
                        <td class="py-2 text-secondary bg-light p-2.5 rounded border mt-1 d-block font-italic">
                            "<?php echo htmlspecialchars($dispute['user_statement'] ?? 'No text description supplied.'); ?>"
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-4 border-0 shadow-sm bg-white h-100" style="border-radius: 14px; border-top: 4px solid #2563eb !important;">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-gavel text-primary me-1"></i> Resolution Control Processing</h5>
            
            <?php if ($dispute['status'] !== 'PENDING'): ?>
                <div class="alert alert-secondary p-3 text-center my-auto rounded-3">
                    <i class="bi bi-lock-fill text-secondary fs-3 d-block mb-2"></i>
                    <h6 class="fw-bold mb-1 text-dark">Case File Permanently Closed</h6>
                    <p class="small text-muted mb-0">This issue has been archived with code: <code><?php echo htmlspecialchars($dispute['status']); ?></code>.</p>
                </div>
            <?php else: ?>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="dispute_id" value="<?php echo $dispute_id; ?>">

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Select Resolution Operational Directive</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="resolution_status" id="status_approve" value="RESOLVED_PAID" required>
                                <label class="btn btn-outline-success btn-sm w-100 py-2.5 fw-bold d-flex flex-column align-items-center justify-content-center" for="status_approve">
                                    <i class="bi bi-check-circle-fill mb-1 fs-5"></i> Approve Claim & Force Pay
                                </label>
                            </div>
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="resolution_status" id="status_reject" value="REJECTED" required>
                                <label class="btn btn-outline-danger btn-sm w-100 py-2.5 fw-bold d-flex flex-column align-items-center justify-content-center" for="status_reject">
                                    <i class="bi bi-x-circle-fill mb-1 fs-5"></i> Dismiss & Void Dispute
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="admin_notes" class="form-label small fw-bold text-secondary">Investigation Findings & Audit Tracking Notes</label>
                        <textarea class="form-textarea form-control small" id="admin_notes" name="admin_notes" rows="4" placeholder="Detail verified transaction sequence numbers or the rationale supporting your processing decision..." required></textarea>
                        <div class="form-text text-muted small" style="font-size:0.725rem;">This information will be logged inside secure transaction journals.</div>
                    </div>

                    <button type="submit" name="resolve_dispute" class="btn btn-primary btn-sm w-100 py-2.5 fw-bold shadow-sm" onclick="return confirm('CONFIRM ADMINISTRATIVE TRANSACTION ACTION:\n\nAre you sure you want to write this resolution decision? This structural adjustment updates ledger records permanently.');">
                        <i class="bi bi-shield-lock-fill me-1"></i> Commit Resolution Directive
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>