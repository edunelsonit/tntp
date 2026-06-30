<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
require_once '../core/RemittanceManager.php';
checkRouteAccess('user');

$db = getDB();
$my_nin = $_SESSION['nin'] ?? '';

if (empty($my_nin)) {
    header('Location: ' . APP_BASE_URL . '/index.php');
    exit;
}

// -------------------------------------------------------------------------
// DIRECT SALARY ACCOUNT PROCESSING HANDLER (BYPASSES CHANGE REQUESTS TICKET)
// -------------------------------------------------------------------------
$alert_message = '';
$alert_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'direct_save_salary') {
    $account_number = trim($_POST['salary_account_number'] ?? '');
    $bank_name = trim($_POST['salary_bank_name'] ?? '');

    // 1. Validate inputs via server side regex checks
    if (!preg_match('/^[0-9]{10}$/', $account_number) || empty($bank_name)) {
        $alert_message = "Invalid submission. Account number must be exactly 10 digits and bank name is required.";
        $alert_type = "danger";
    } else {
        // 2. Fetch current record status to enforce the absolute strict once-only lockout
        $stmt_check = $db->prepare("SELECT id, salary_account_number FROM users WHERE nin = ?");
        $stmt_check->execute([$my_nin]);
        $user_row = $stmt_check->fetch();

        if (!$user_row) {
            header('Location: ' . APP_BASE_URL . '/index.php?err=unauthorized');
            exit;
        }

        if (!empty(trim($user_row['salary_account_number'] ?? ''))) {
            $alert_message = "Security Lockout: Your salary payroll profile details have already been locked permanently.";
            $alert_type = "danger";
        } else {
            // 3. Update the data directly in the master database row
            $stmt_update = $db->prepare("UPDATE users SET salary_account_number = ?, salary_bank_name = ? WHERE id = ?");
            if ($stmt_update->execute([$account_number, $bank_name, (int)$user_row['id']])) {
                $alert_message = "Success! Your salary account details have been logged and permanently locked.";
                $alert_type = "success";
            } else {
                $alert_message = "A system matrix execution error occurred. Please try again.";
                $alert_type = "danger";
            }
        }
    }
}

// -------------------------------------------------------------------------
// METADATA PROFILE INGESTION SHEET
// -------------------------------------------------------------------------
$stmt_u = $db->prepare("
    SELECT u.*, c.cluster_name, c.manager_name, c.manager_email, c.cluster_location 
    FROM users u 
    LEFT JOIN clusters c ON u.cluster_code = c.cluster_code 
    WHERE u.nin = ?
");
$stmt_u->execute([$my_nin]);
$me = $stmt_u->fetch();

if (!$me || strtoupper(trim($me['approval_status'] ?? '')) !== 'APPROVED') {
    session_destroy();
    header('Location: ' . APP_BASE_URL . '/index.php?err=unauthorized');
    exit;
}

$user_id = (int)$me['id'];
$display_name = trim(($me['first_name'] ?? '') . ' ' . ($me['surname'] ?? ''));

// Toggle lock configuration metrics based on presence of database strings
$has_salary_account = !empty(trim($me['salary_account_number'] ?? ''));

// Top 20 Nigerian Commercial Banks Dropdown Array Framework
$nigerian_banks = [
    "Access Bank", "Citibank Nigeria", "Ecobank Nigeria", "Fidelity Bank", 
    "First Bank of Nigeria", "First City Monument Bank (FCMB)", "Globus Bank", 
    "Guaranty Trust Bank (GTBank)", "Heritage Bank", "Keystone Bank", 
    "Optimus Bank", "Parallex Bank", "PremiumTrust Bank", "Providus Bank", 
    "Signature Bank", "Stanbic IBTC Bank", "Standard Chartered Bank", 
    "Sterling Bank", "Titan Trust Bank", "United Bank for Africa (UBA)", 
    "Unity Bank", "Wema Bank", "Zenith Bank"
];

// 2. Pull active system remittance logs
$stmt_r = $db->prepare("
    SELECT r.*, rc.cycle_period 
    FROM remittance r
    INNER JOIN remittance_cycles rc ON r.cycle_id = rc.id
    WHERE r.userid = ? 
    ORDER BY r.id DESC LIMIT 1
");
$stmt_r->execute([$user_id]);
$current_remittance = $stmt_r->fetch();

$cycle_active = !empty($current_remittance);
$remittance_id = $cycle_active ? (int)$current_remittance['id'] : 0;
$expected_amt = $cycle_active ? (float)$current_remittance['expected_amount'] : 0.00;
$paid_amt     = $cycle_active ? (float)$current_remittance['amount_paid'] : 0.00;
$my_bal        = max(0, $expected_amt - $paid_amt);

// 3. Fetch past logged disputes tracking
$stmt_d = $db->prepare("
    SELECT id, dispute_type, dispute_status, dispute_time, admin_notes 
    FROM disputes 
    WHERE userid = ? 
    ORDER BY dispute_time DESC
");
$stmt_d->execute([$user_id]);
$disputes = $stmt_d->fetchAll();

// 4. Fetch historical profile change requests
$stmt_cr = $db->prepare("
    SELECT id, change_type, status, rejection_reason, created_at 
    FROM user_change_requests 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt_cr->execute([$user_id]);
$change_requests = $stmt_cr->fetchAll();

$reconciliation_percentage = 0;
if ($expected_amt > 0) {
    $reconciliation_percentage = min(100, round(($paid_amt / $expected_amt) * 100));
}

include_once '../partials/header.php';
?>

<?php if (!empty($alert_message)): ?>
    <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show shadow-sm border-0 mb-4" role="alert" style="border-radius: 12px;">
        <i class="bi <?php echo $alert_type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
        <?php echo $alert_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-3 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold">User Portal</h1>
        <p class="text-muted small mb-0">
            Welcome, <span class="text-dark fw-semibold"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></span> 
            <span class="mx-1 text-muted">•</span> Portal Status: <span class="badge bg-success-subtle text-success border px-2">Approved</span>
        </p>
    </div>
    <div class="mt-2 mt-md-0 d-flex gap-2 align-items-center">
        <?php 
        $p_status = strtoupper($me['payment_status'] ?? 'UNPAID');
        $p_badge = 'bg-secondary';
        if ($p_status === 'PAID') $p_badge = 'bg-success';
        if ($p_status === 'PARTIAL') $p_badge = 'bg-info text-dark';
        if ($p_status === 'DEFAULTING') $p_badge = 'bg-danger';
        if ($p_status === 'EXEMPTED') $p_badge = 'bg-dark';
        ?>
        <span class="badge py-2 px-3 rounded <?php echo $p_badge; ?> text-white fw-bold shadow-sm small">
            Cycle Status: <?php echo $p_status; ?>
        </span>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm p-3 bg-white h-100" style="border-radius: 12px; border: 1px solid #f1f5f9 !important;">
            <div class="d-flex align-items-center mb-2 text-primary">
                <i class="bi bi-wallet2 fs-5 me-2"></i>
                <span class="fw-bold text-uppercase small text-muted tracking-wider">Pay Now</span>
            </div>
            <?php if ($cycle_active && $my_bal > 0): ?>
                <h6 class="fw-bold text-dark mb-2">Outstanding balance due: ₦<?php echo number_format($my_bal, 2); ?></h6>
                <button type="button"
                        class="btn btn-sm btn-success fw-bold px-3 py-2 shadow-sm"
                        data-email="<?php echo htmlspecialchars($me['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        data-amount="<?php echo (int) round($my_bal * 100); ?>"
                        data-user-id="<?php echo (int)$user_id; ?>"
                        data-remittance-id="<?php echo (int)$remittance_id; ?>"
                        data-cycle-id="<?php echo (int)($current_remittance['cycle_id'] ?? 0); ?>"
                        data-redirect-url="<?php echo APP_BASE_URL; ?>/manager/verify_payment.php"
                        onclick="initializePaystackCheckout(this)">
                    <i class="bi bi-credit-card-fill me-1"></i> Pay Now
                </button>
            <?php else: ?>
                <p class="text-muted small mb-0">No outstanding payment is currently due for your active cycle.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card border-0 shadow-sm p-3 bg-white h-100" style="border-radius: 12px; border: 1px solid #f1f5f9 !important;">
            <div class="d-flex align-items-center mb-2 text-success">
                <i class="bi bi-bank fs-5 me-2"></i>
                <span class="fw-bold text-uppercase small text-muted tracking-wider">Salary Payroll Bank</span>
            </div>
            <h6 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($me['salary_bank_name'] ?? 'Not Specified', ENT_QUOTES, 'UTF-8'); ?></h6>
            <small class="text-muted d-block">Acct: <code class="text-dark fw-bold"><?php echo htmlspecialchars($me['salary_account_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></code></small>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm p-3 bg-white h-100" style="border-radius: 12px; border: 1px solid #f1f5f9 !important;">
            <div class="d-flex align-items-center mb-2 text-warning">
                <i class="bi bi-geo-alt fs-5 me-2"></i>
                <span class="fw-bold text-uppercase small text-muted tracking-wider">Assigned Group Cluster</span>
            </div>
            <h6 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($me['cluster_name'] ?? 'General Pool Layout', ENT_QUOTES, 'UTF-8'); ?></h6>
            <small class="text-muted d-block">Location: <?php echo htmlspecialchars($me['cluster_location'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
    </div>
</div>

<?php if ($cycle_active): ?>
    <div class="card border-0 shadow-sm p-4 mb-4 bg-white" style="border-radius: 16px; border: 1px solid #f1f5f9 !important;">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <span class="text-muted small fw-bold text-uppercase d-block">Current Cycle Progress (Period: <?php echo htmlspecialchars($current_remittance['cycle_period'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                <h5 class="fw-bold text-dark mb-0"><?php echo $reconciliation_percentage; ?>% Account Settled</h5>
            </div>
            <span class="text-primary font-monospace fw-bold small">₦<?php echo number_format($paid_amt, 2); ?> / ₦<?php echo number_format($expected_amt, 2); ?></span>
        </div>
        <div class="progress" style="height: 10px; border-radius: 50px; background-color: #f1f5f9;">
            <div class="progress-bar progress-bar-striped progress-bar-animated <?php echo $reconciliation_percentage >= 100 ? 'bg-success' : 'bg-primary'; ?>" 
                 role="progressbar" 
                 style="width: <?php echo $reconciliation_percentage; ?>%;" 
                 aria-valuenow="<?php echo $reconciliation_percentage; ?>" 
                 aria-valuemin="0" 
                 aria-valuemax="100"></div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 text-white bg-dark p-4 shadow-sm h-100 d-flex flex-column justify-content-between" style="border-radius:16px; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-white-50 text-uppercase tracking-wider fw-bold small">Direct Payment</span>
                        <i class="bi bi-credit-card-2-front fs-4 text-white-50"></i>
                    </div>
                    <?php if ($cycle_active && $my_bal > 0): ?>
                        <div class="my-2">
                            <h5 class="text-white fw-semibold fs-6 mb-2"><i class="bi bi-cash-stack me-1"></i> Pay your current cycle balance instantly</h5>
                            <button type="button"
                                    class="btn btn-sm btn-primary fw-bold px-3 py-2 shadow"
                                    data-email="<?php echo htmlspecialchars($me['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-amount="<?php echo (int) round($my_bal * 100); ?>"
                                    data-user-id="<?php echo (int)$user_id; ?>"
                                    data-remittance-id="<?php echo (int)$remittance_id; ?>"
                                    data-cycle-id="<?php echo (int)($current_remittance['cycle_id'] ?? 0); ?>"
                                    data-redirect-url="<?php echo APP_BASE_URL; ?>/manager/verify_payment.php"
                                    onclick="initializePaystackCheckout(this)">
                                <i class="bi bi-lightning-charge-fill me-1"></i> Pay Now
                            </button>
                        </div>
                    <?php else: ?>
                        <p class="fs-6 text-white-50 mb-0">No outstanding payment is due for your current cycle.</p>
                    <?php endif; ?>
                </div>
                <div class="mt-4 pt-3 border-top border-secondary">
                    <button class="btn btn-sm btn-outline-light w-100 fw-bold" data-bs-toggle="collapse" data-bs-target="#paymentDisputeForm">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Transfer Completed but Not Reflected? Dispute
                    </button>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 p-4 shadow-sm bg-white h-100 d-flex flex-column justify-content-between" style="border-radius:16px; border: 1px solid #f1f5f9 !important;">
                <div>
                    <span class="text-muted text-uppercase fw-bold small d-block mb-3">Cycle Balance Matrix Sheet</span>
                    <div class="row g-3">
                        <div class="col-6 border-end">
                            <span class="text-muted small d-block mb-1">Expected Remittance</span>
                            <h3 class="fw-bold text-dark mb-0">₦<?php echo number_format($expected_amt, 2); ?></h3>
                        </div>
                        <div class="col-6 ps-3">
                            <span class="text-muted small d-block mb-1">Outstanding Balance Due</span>
                            <h3 class="fw-bold <?php echo $my_bal > 0 ? 'text-danger' : 'text-success'; ?> mb-0">₦<?php echo number_format($my_bal, 2); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-top d-flex gap-2 justify-content-md-end">
                    <button class="btn btn-sm btn-outline-secondary fw-bold shadow-sm" data-bs-toggle="collapse" data-bs-target="#salaryAccountSetupForm">
                        <i class="bi bi-bank fs-6 me-1"></i> <?php echo $has_salary_account ? 'View Salary Account' : 'Configure Salary Account'; ?>
                    </button>
                    <button class="btn btn-sm btn-outline-primary fw-bold shadow-sm" data-bs-toggle="collapse" data-bs-target="#salaryDisputeForm">
                        <i class="bi bi-chat-left-dots-fill me-1"></i> Salary Not Received? File Dispute
                    </button>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="alert alert-neutral border shadow-sm p-4 mb-4 bg-white" style="border-radius: 12px;">
        <div class="d-flex align-items-start">
            <i class="bi bi-lock-fill fs-4 me-3 text-secondary"></i>
            <div class="w-100">
                <span class="fw-bold text-dark d-block mb-1">No Active Remittance Cycle</span>
                <span class="small text-muted d-block mb-3">There are no operational collection cycles active against your account group framework at this moment. No layout payments are required.</span>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary fw-bold" data-bs-toggle="collapse" data-bs-target="#salaryAccountSetupForm">
                        <i class="bi bi-bank fs-6 me-1"></i> <?php echo $has_salary_account ? 'View Salary Account' : 'Configure Salary Account'; ?>
                    </button>
                    <button class="btn btn-sm btn-outline-primary fw-bold" data-bs-toggle="collapse" data-bs-target="#salaryDisputeForm">
                        <i class="bi bi-cash me-1"></i> Salary Not Received? Log Dispute
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="collapse mb-4" id="salaryAccountSetupForm">
    <div class="card card-body border-0 shadow-sm p-4 bg-light" style="border-radius: 14px;">
        <h5 class="fw-bold text-dark mb-1">
            <i class="bi bi-shield-lock text-secondary me-2"></i> 
            <?php echo $has_salary_account ? 'Verified Salary Account Information' : 'Link Your Salary Payroll Account'; ?>
        </h5>
        <p class="text-muted small mb-3">
            <?php echo $has_salary_account ? 'Your payout metrics have been permanently logged. Edits are locked out.' : 'Provide your settlement account layout details below. This configuration is done <strong>once and cannot be changed</strong>.'; ?>
        </p>
        
        <form method="POST" action="">
            <input type="hidden" name="action" value="direct_save_salary">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Salary Account Number</label>
                    <input type="text" 
                           class="form-control form-control-sm font-monospace" 
                           name="salary_account_number" 
                           maxlength="10" 
                           pattern="[0-9]{10}"
                           title="Please input exactly 10 digits"
                           value="<?php echo htmlspecialchars($me['salary_account_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                           placeholder="e.g. 0123456789" 
                           required 
                           <?php echo $has_salary_account ? 'readonly style="background-color: #e2e8f0; border-color: #cbd5e1;"' : ''; ?>>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Salary Bank Name</label>
                    <?php if ($has_salary_account): ?>
                        <input type="text" 
                               class="form-control form-control-sm" 
                               name="salary_bank_name" 
                               value="<?php echo htmlspecialchars($me['salary_bank_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                               readonly 
                               style="background-color: #e2e8f0; border-color: #cbd5e1;">
                    <?php else: ?>
                        <select class="form-select form-select-sm" name="salary_bank_name" required>
                            <option value="" disabled selected>Select Bank...</option>
                            <?php foreach ($get_banks = $nigerian_banks as $bank): ?>
                                <option value="<?php echo htmlspecialchars($bank, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($bank, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!$has_salary_account): ?>
                <button type="submit" class="btn btn-sm btn-success fw-bold px-4">Confirm and Save Permanently</button>
            <?php else: ?>
                <div class="text-muted small"><i class="bi bi-check-circle-fill text-success me-1"></i> Data row locked. To request future changes, contact system administrator directly.</div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="collapse mb-4" id="salaryDisputeForm">
    <div class="card card-body border-0 shadow-sm p-4 bg-light" style="border-radius: 14px;">
        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-bank2 text-primary me-2"></i> Report Missing Salary Payment</h5>
        <p class="text-muted small mb-3">Submit this layout if administrators cleared payroll calculations but funds failed to transfer to your banking layout profiles.</p>
        <form method="POST" action="<?php echo APP_BASE_URL; ?>/core/submit_dispute.php">
            <input type="hidden" name="remittance_id" value="<?php echo $remittance_id; ?>">
            <input type="hidden" name="dispute_type" value="NO_SALARY">
            <div class="mb-3">
                <label class="form-label small fw-bold">Transaction Reference Code</label>
                <input type="text" class="form-control mb-2" name="proof_path" value="<?php echo htmlspecialchars($me['monnify_reference'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. PAY-12345-67890" required>
                <label class="form-label small fw-bold">Explanation Remarks</label>
                <textarea class="form-control text-sm" name="admin_notes" rows="3" placeholder="Provide context regarding dates and missing values..." required></textarea>
            </div>
            <button type="submit" class="btn btn-sm btn-primary fw-bold px-3">File Dispute Entry</button>
        </form>
    </div>
</div>

<?php if ($cycle_active): ?>
<div class="collapse mb-4" id="paymentDisputeForm">
    <div class="card card-body border-0 shadow-sm p-4 bg-light" style="border-radius: 14px;">
        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-receipt text-danger me-2"></i> Report Unverified Remittance Transaction</h5>
        <p class="text-muted small mb-3">Submit this layout if you transferred funds to your virtual account but the transaction logs have not reconciled.</p>
        <form method="POST" action="<?php echo APP_BASE_URL; ?>/core/submit_dispute.php">
            <input type="hidden" name="remittance_id" value="<?php echo $remittance_id; ?>">
            <input type="hidden" name="dispute_type" value="WEBHOOK_FAILED">
            <div class="mb-3">
                <label class="form-label small fw-bold">Transaction Reference Code</label>
                <input type="text" class="form-control mb-2" name="proof_path" value="<?php echo htmlspecialchars($me['monnify_reference'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. PAY-12345-67890" required>
                <label class="form-label small fw-bold">Additional Comments / Context</label>
                <textarea class="form-control text-sm" name="admin_notes" rows="2" placeholder="Include precise time of payment and bank terminal source utilized..."></textarea>
            </div>
            <button type="submit" class="btn btn-sm btn-danger fw-bold px-3">Log Remittance Audit Request</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm bg-white p-4 h-100" style="border-radius:14px; border: 1px solid #f1f5f9 !important;">
            <h5 class="fw-bold text-dark mb-3">Your Historical Dispute Log Requests</h5>
            <?php if (empty($disputes)): ?>
                <p class="text-muted small mb-0">No historical system disputes found matching your user account ID record.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-borderless align-middle mb-0">
                        <thead>
                            <tr class="text-muted small border-bottom">
                                <th>Date Submitted</th>
                                <th>Classification Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody class="small">
                            <?php foreach ($disputes as $disp): ?>
                                <tr class="border-bottom-subtle">
                                    <td><?php echo date('M d, Y', strtotime($disp['dispute_time'])); ?></td>
                                    <td><span class="badge bg-secondary-subtle text-dark border"><?php echo htmlspecialchars($disp['dispute_type'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td>
                                        <?php 
                                        $status = $disp['dispute_status'];
                                        $badge_class = 'bg-warning text-dark';
                                        if ($status === 'APPROVED_ADJUSTED') $badge_class = 'bg-success text-white';
                                        if ($status === 'REJECTED') $badge_class = 'bg-danger text-white';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm bg-white p-4 h-100" style="border-radius:14px; border: 1px solid #f1f5f9 !important;">
            <h5 class="fw-bold text-dark mb-3">Profile Data Amendments</h5>
            <?php if (empty($change_requests)): ?>
                <p class="text-muted small mb-0">No profile change modification tickets filed.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-borderless align-middle mb-0">
                        <thead>
                            <tr class="text-muted small border-bottom">
                                <th>Date Requested</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody class="small">
                            <?php foreach ($change_requests as $cr): ?>
                                <tr class="border-bottom-subtle">
                                    <td><?php echo date('M d, Y', strtotime($cr['created_at'])); ?></td>
                                    <td>
                                        <?php 
                                        $c_status = $cr['status'];
                                        $c_badge = 'bg-warning text-dark';
                                        if ($c_status === 'APPROVED') $c_badge = 'bg-success text-white';
                                        if ($c_status === 'REJECTED') $c_badge = 'bg-danger text-white';
                                        ?>
                                        <span class="badge <?php echo $c_badge; ?>" title="<?php echo htmlspecialchars($cr['rejection_reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($c_status, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
function initializePaystackCheckout(element) {
    const emailAddress = element.getAttribute('data-email');
    const amountInKobo = element.getAttribute('data-amount');
    const userId = element.getAttribute('data-user-id');
    const remittanceId = element.getAttribute('data-remittance-id');
    const cycleId = element.getAttribute('data-cycle-id');
    const redirectUrl = element.getAttribute('data-redirect-url') || 'verify_payment.php';
    const generatedReference = 'REMIT_PS_' + userId + '_' + cycleId + '_' + Math.floor(Math.random() * 10000000);

    const checkoutHandler = PaystackPop.setup({
        key: '<?php echo PAYSTACK_PUBLIC_KEY; ?>',
        email: emailAddress,
        amount: amountInKobo,
        currency: 'NGN',
        ref: generatedReference,
        metadata: {
            custom_fields: [
                { display_name: 'Participant ID', variable_name: 'user_id', value: userId },
                { display_name: 'Remittance Record ID', variable_name: 'remittance_id', value: remittanceId },
                { display_name: 'Accounting Cycle ID', variable_name: 'cycle_id', value: cycleId }
            ]
        },
        callback: function(response) {
            window.location.href = redirectUrl + '?reference=' + encodeURIComponent(response.reference) +
                '&user_id=' + userId +
                '&remittance_id=' + remittanceId +
                '&cycle_id=' + cycleId;
        },
        onClose: function() {
            alert('Gateway session closed. Your payment was not completed.');
        }
    });

    checkoutHandler.openIframe();
}
</script>

<?php 
include_once '../partials/footer.php'; 
?>