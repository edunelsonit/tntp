<?php
require_once '../config/config.php';

// Authorizes both 'admin' and 'super_admin' roles seamlessly via your updated config engine
checkRouteAccess('admin'); 

$db = getDB();

// Handle One-Click Inline Approval Form Processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_approve') {
    // Basic CSRF token fallback validation check
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!empty($csrf_token) && validateCsrfToken($csrf_token)) {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $processed_by_id = intval($_SESSION['admin_id'] ?? ($_SESSION['manager_id'] ?? 1));
        
        if ($target_user_id > 0) {
            try {
                $db->beginTransaction();
                
                // Ensure profile row identity exists in data storage layer
                $check = $db->prepare("SELECT id, first_name, surname FROM users WHERE id = ? FOR UPDATE");
                $check->execute([$target_user_id]);
                $target_user = $check->fetch();
                
                if ($target_user) {
                    // Update core master table structure
                    $update = $db->prepare("UPDATE users SET approval_status = 'APPROVED' WHERE id = ?");
                    $update->execute([$target_user_id]);
                    
                    // Create tracking entry log matching staging system definitions
                    $audit_data = json_encode([
                        'first_name' => $target_user['first_name'],
                        'surname' => $target_user['surname'],
                        'approval_status' => 'APPROVED'
                    ], JSON_THROW_ON_ERROR);
                    
                    $stmt = $db->prepare("
                        INSERT INTO user_change_requests (user_id, requested_by_id, change_type, proposed_data, status, processed_by, processed_at) 
                        VALUES (?, ?, 'PROFILE_UPDATE', ?, 'APPROVED', ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$target_user_id, $processed_by_id, $audit_data, $processed_by_id]);
                    
                    $db->commit();
                    header("Location: index.php?msg=quick_approved");
                    exit;
                }
                $db->rollBack();
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("Quick Inline Approval Failure: " . $e->getMessage());
                header("Location: index.php?err=quick_approval_fault");
                exit;
            }
        }
    }
}

// Fetch Core Financial Aggregations from remittance ledger
$metrics = $db->query("SELECT SUM(expected_amount) as exp, SUM(amount_paid) as paid FROM remittance")->fetch();
$total_expected = floatval($metrics['exp'] ?? 0);
$total_paid     = floatval($metrics['paid'] ?? 0);
$balance_due    = $total_expected - $total_paid;
$completion     = $total_expected > 0 ? ($total_paid / $total_expected) * 100 : 0;

// SELECT statement properties explicitly aligned to match downstream PHP loop definitions
$defaulters = $db->query("
    SELECT 
        u.*, 
        c.cluster_name, 
        r.expected_amount AS remittance_expected, 
        r.amount_paid AS remittance_paid,
        (r.expected_amount - r.amount_paid) AS balance_due 
    FROM remittance r 
    JOIN users u ON u.id = r.userid 
    LEFT JOIN clusters c ON c.cluster_code = u.cluster_code 
    WHERE (r.expected_amount - r.amount_paid) > 0 
    ORDER BY r.id DESC 
    LIMIT 10
")->fetchAll();

// Fetch ALL System Users for the Master Management Grid
$all_users = $db->query("
    SELECT u.*, c.cluster_name 
    FROM users u 
    LEFT JOIN clusters c ON c.cluster_code = u.cluster_code 
    ORDER BY u.id DESC
")->fetchAll();

// Fetch System Verification Event Badges Counts
$pending_approvals_count = (int) $db->query("SELECT COUNT(*) FROM users WHERE approval_status = 'PENDING'")->fetchColumn();
$pending_receipts_count  = 0; // pending receipt tracking is not available in the current schema
$active_disputes_count   = (int) $db->query("SELECT COUNT(*) FROM disputes WHERE dispute_status = 'PENDING'")->fetchColumn();

include_once '../partials/header.php';
?>

<?php if (($page_msg = $_GET['msg'] ?? '') === 'quick_approved'): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4 fw-semibold small" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> User account has been successfully approved and recorded into governance timelines.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php  endif; ?>

<div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between mb-4 pb-3 border-bottom gap-3">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold">Platform Master Admin Dashboard</h1>
        <p class="text-muted small mb-0">System core parameters overview and settlement tracking workspace.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo APP_BASE_URL; ?>/admin/clusters.php" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm fw-medium">
            <i class="bi bi-hdd-network me-1"></i> Manage Clusters
        </a>
        <a href="<?php echo APP_BASE_URL; ?>/admin/users.php" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm fw-medium">
            <i class="bi bi-people me-1"></i> Manage Users
        </a>
        <a href="<?php echo APP_BASE_URL; ?>/admin/reports.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 shadow-sm fw-medium">
            <i class="bi bi-graph-up-arrow me-1"></i> Reports
        </a>
        <a href="<?php echo APP_BASE_URL; ?>/admin/register.php" class="btn btn-sm btn-outline-dark rounded-pill px-3 shadow-sm fw-medium">
            <i class="bi bi-person-plus me-1"></i> Add Admin
        </a>
    </div>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100 bg-white shadow-sm p-4 border-0 border-start border-primary border-4 rounded-end">
            <div class="text-uppercase mb-1 small fw-bold text-primary tracking-wider" style="font-size: 0.75rem;">System Expected Revenue</div>
            <div class="h3 mb-0 fw-bold text-dark">₦<?php echo number_format($total_expected, 2); ?></div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100 bg-white shadow-sm p-4 border-0 border-start border-success border-4 rounded-end">
            <div class="text-uppercase mb-1 small fw-bold text-success tracking-wider" style="font-size: 0.75rem;">Reconciled Settlements</div>
            <div class="h3 mb-0 fw-bold text-dark">₦<?php echo number_format($total_paid, 2); ?></div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100 bg-white shadow-sm p-4 border-0 border-start border-danger border-4 rounded-end">
            <div class="text-uppercase mb-1 small fw-bold text-danger tracking-wider" style="font-size: 0.75rem;">Defaulters Liability</div>
            <div class="h3 mb-0 fw-bold text-dark">₦<?php echo number_format($balance_due, 2); ?></div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100 bg-white shadow-sm p-4 border-0 border-start border-info border-4 rounded-end">
            <div class="text-uppercase mb-1 small fw-bold text-info tracking-wider" style="font-size: 0.75rem;">Payment Completion Ratio</div>
            <div class="h3 mb-0 fw-bold text-dark"><?php echo number_format($completion, 1); ?>%</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm p-4 bg-white text-center d-flex flex-column justify-content-between" style="border-radius:12px;">
            <div>
                <h6 class="text-secondary small fw-bold text-uppercase tracking-wide mb-2">Awaiting Account Approval</h6>
                <div class="display-6 my-2 fw-bold <?php echo $pending_approvals_count > 0 ? 'text-warning' : 'text-muted'; ?>">
                    <?php echo $pending_approvals_count; ?>
                </div>
            </div>
            <a href="approve_requests.php" class="btn btn-sm btn-light border w-100 fw-bold mt-2 py-2">
                <i class="bi bi-clipboard-check me-1"></i> Review Registrations
            </a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm p-4 bg-white text-center d-flex flex-column justify-content-between" style="border-radius:12px;">
            <div>
                <h6 class="text-secondary small fw-bold text-uppercase tracking-wide mb-2">Unverified Bank Receipts</h6>
                <div class="display-6 my-2 fw-bold <?php echo $pending_receipts_count > 0 ? 'text-danger' : 'text-muted'; ?>">
                    <?php echo $pending_receipts_count; ?>
                </div>
            </div>
            <a href="users.php#receipts-section" class="btn btn-sm btn-light border w-100 fw-bold mt-2 py-2">
                <i class="bi bi-cash-coin me-1"></i> Verify Receipts
            </a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm p-4 bg-white text-center d-flex flex-column justify-content-between" style="border-radius:12px;">
            <div>
                <h6 class="text-secondary small fw-bold text-uppercase tracking-wide mb-2">Active System Disputes</h6>
                <div class="display-6 my-2 fw-bold <?php echo $active_disputes_count > 0 ? 'text-danger' : 'text-muted'; ?>">
                    <?php echo $active_disputes_count; ?>
                </div>
            </div>
            <a href="users.php#disputes-section" class="btn btn-sm btn-light border w-100 fw-bold mt-2 py-2">
                <i class="bi bi-exclamation-octagon me-1"></i> Resolve Conflicts
            </a>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm bg-white p-4 mb-4" style="border-radius:14px;">
    <div class="border-bottom pb-3 mb-3">
        <h5 class="fw-bold text-dark mb-1">Outstanding Accounts Registry</h5>
        <p class="text-muted small mb-0">Displaying top 10 profiles carrying active balance liabilities ordered by latest allocation records.</p>
    </div>
    
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light text-secondary fw-semibold">
                <tr>
                    <th class="py-3">User Profile Identity</th>
                    <th class="py-3">Assigned Cluster Group</th>
                    <th class="py-3">Expected (₦)</th>
                    <th class="py-3">Paid (₦)</th>
                    <th class="py-3">Outstanding Balance</th>
                    <th class="py-3">Cycle Initiation Date</th>
                </tr>
            </thead>
            <tbody class="text-dark">
                <?php if (empty($defaulters)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-check2-all fs-3 text-success d-block mb-2"></i>
                            No outstanding liabilities discovered inside current workspace profiles.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($defaulters as $u): 
                        $expected_val = (float)$u['remittance_expected'];
                        $paid_val     = (float)$u['remittance_paid'];
                        $outstanding  = $expected_val - $paid_val; 
                    ?>
                        <tr>
                            <td>
                                <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['surname']); ?></span>
                                <small class="text-muted font-monospace d-block mt-0.5"><?php echo htmlspecialchars($u['nin']); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border px-2 py-1 fw-medium">
                                    <i class="bi bi-tag me-1 text-muted"></i>
                                    <?php echo htmlspecialchars($u['cluster_name'] ?? $u['cluster_code'] ?? 'Unassigned Axis'); ?>
                                </span>
                            </td>
                            <td class="fw-medium">₦<?php echo number_format($expected_val, 2); ?></td>
                            <td class="text-success fw-medium">₦<?php echo number_format($paid_val, 2); ?></td>
                            <td class="fw-bold text-danger">₦<?php echo number_format($outstanding, 2); ?></td>
                            <td class="text-muted">
                                <i class="bi bi-calendar3 me-1 small"></i>
                                <?php echo !empty($u['payment_start_date']) ? htmlspecialchars($u['payment_start_date']) : 'Not Initiated'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm bg-white p-4 mb-4" style="border-radius:14px;">
    <div class="border-bottom pb-3 mb-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-1">Global Platform Users Directory</h5>
            <p class="text-muted small mb-0">Overview of all active accounts registered across deployment networks.</p>
        </div>
        <div>
            <span class="badge bg-primary px-3 py-2 rounded-pill fw-semibold shadow-sm">Total: <?php echo count($all_users); ?> Accounts</span>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light text-secondary fw-semibold">
                <tr>
                    <th class="py-3">Account User</th>
                    <th class="py-3">Contact Email / Phone</th>
                    <th class="py-3">Deployment Cluster</th>
                    <th class="py-3 text-center">Approval Status</th>
                    <th class="py-3 text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody class="text-dark">
                <?php if (empty($all_users)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <i class="bi bi-people-fill fs-3 text-black-50 d-block mb-2"></i>
                            No platform accounts currently recognized in the relational data storage engine.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($all_users as $user): 
                        $app_status = strtoupper(trim((string)($user['approval_status'] ?? 'PENDING')));
                    ?>
                        <tr>
                            <td>
                                <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['surname']); ?></span>
                                <small class="text-muted font-monospace d-block mt-0.5">NIN: <?php echo htmlspecialchars($user['nin'] ?? 'N/A'); ?></small>
                            </td>
                            <td>
                                <span class="d-block text-secondary fw-medium"><?php echo htmlspecialchars($user['email']); ?></span>
                                <small class="text-muted d-block mt-0.5"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border px-2 py-1 fw-medium">
                                    <i class="bi bi-geo-alt me-1 text-primary"></i>
                                    <?php echo htmlspecialchars($user['cluster_name'] ?? $user['cluster_code'] ?? 'Unassigned Node'); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($app_status === 'APPROVED'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 rounded fw-bold small">
                                        <i class="bi bi-check-circle me-1"></i>Approved
                                    </span>
                                <?php elseif ($app_status === 'PENDING'): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2.5 py-1 rounded fw-bold small">
                                        <i class="bi bi-hourglass-split me-1"></i>Pending
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1 rounded fw-bold small">
                                        <i class="bi bi-x-circle me-1"></i>Rejected
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex gap-1 align-items-center">
                                    <?php if ($app_status === 'PENDING'): ?>
                                        <form method="POST" action="" class="m-0" onsubmit="return confirm('Are you sure you want to approve this user profile instantly?');">
                                            <input type="hidden" name="action" value="quick_approve">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                                            <button type="submit" class="btn btn-sm btn-success px-2.5 fw-bold shadow-sm d-flex align-items-center text-nowrap" style="font-size:0.75rem; padding-top: 0.35rem; padding-bottom: 0.35rem;">
                                                <i class="bi bi-person-check-fill me-1"></i> Approve
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <a href="<?php echo APP_BASE_URL; ?>/admin/user_edit.php?id=<?php echo urlencode((string)$user['id']); ?>" class="btn btn-sm btn-outline-primary px-3 fw-semibold shadow-sm text-nowrap" style="font-size:0.75rem; padding-top: 0.35rem; padding-bottom: 0.35rem;">
                                        <i class="bi bi-pencil-square me-1"></i> Edit User
                                    </a>
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