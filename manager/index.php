<?php
// FIXED: Adjusted relative path targets to load root core scripts safely
require_once '../config/config.php';
require_once '../core/RemittanceManager.php';

// Enforce strict cluster manager operational boundaries
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cluster_manager') {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();
$my_code = $_SESSION['cluster_code'] ?? '';

// Fetch the profile properties for the current logged-in cluster node
$stmt_c = $db->prepare("SELECT * FROM clusters WHERE cluster_code = ?");
$stmt_c->execute([$my_code]);
$cluster = $stmt_c->fetch();

if (!$cluster) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

// Track if the manager explicitly requested to add a user
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add_user');

// Extract properties for the current open tracking ledger timeline window
$cycle = $db->query("SELECT id, cycle_period FROM remittance_cycles ORDER BY cycle_period DESC LIMIT 1")->fetch();
$currentCycleId = $cycle['id'] ?? null;
$cyclePeriod = $cycle['cycle_period'] ?? 'No active accounting cycle open';

$registrationMsg = '';

// Helper Functions for CSRF verification fallback compatibility
if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// ----------------------------------------------------------------------
// CENTRAL NIGERIAN CENTRAL BANK (CBN) PROFILE DATA MAP
// ----------------------------------------------------------------------
$nigerian_banks = [
    "Access Bank",
    "Access Bank (Diamond)",
    "ALAT by WEMA",
    "Carbon",
    "Citibank Nigeria",
    "Ecobank Nigeria",
    "Fidelity Bank",
    "First Bank of Nigeria",
    "First City Monument Bank (FCMB)",
    "Globus Bank",
    "Guaranty Trust Bank (GTBank)",
    "Heritage Bank",
    "Jaiz Bank",
    "Keystone Bank",
    "Kuda Bank",
    "Moniepoint MFB",
    "Opay",
    "Optimus Bank",
    "Palmpay",
    "Parallex Bank",
    "PremiumTrust Bank",
    "Providus Bank",
    "Rubies MFB",
    "Stanbic IBK Bank",
    "Standard Chartered Bank",
    "Sterling Bank",
    "SunTrust Bank",
    "TAJBank",
    "Titan Trust Bank",
    "Union Bank of Nigeria",
    "United Bank for Africa (UBA)",
    "Unity Bank",
    "VFD Microfinance Bank",
    "Wema Bank",
    "Zenith Bank"
];

// ==========================================
// CORE POST HANDLING LOGIC WRAPPER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_member'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !verifyCsrfToken($csrf_token)) {
        $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-shield-x me-1"></i> Security validation token failed or expired.</div>';
    } else {
        // Sanitize and extract form data exactly matching the DB schema names
        $nin        = filter_input(INPUT_POST, 'nin', FILTER_SANITIZE_NUMBER_INT);
        $first_name = trim($_POST['first_name'] ?? '');
        $surname    = trim($_POST['surname'] ?? '');
        $other_name = trim($_POST['other_name'] ?? '') ?: null; 
        $phone      = trim($_POST['phone'] ?? '');
        $email      = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        
        $host_organization          = trim($_POST['host_organization'] ?? '') ?: null;
        $expected                   = filter_input(INPUT_POST, 'expected_remittance_amount', FILTER_VALIDATE_FLOAT);
        $resumption_date            = trim($_POST['resumption_date'] ?? '') ?: null;
        $gender                     = trim($_POST['gender'] ?? '') ?: null;
        $dob                        = trim($_POST['dob'] ?? '') ?: null;
        $state_of_origin            = trim($_POST['state_of_origin'] ?? '') ?: null;
        $lga                        = trim($_POST['lga'] ?? '') ?: null;

        // Clean account data explicitly protecting database unique fields from blank collisions
        $salary_account_number = trim($_POST['salary_account_number'] ?? '');
        $salary_bank_name      = trim($_POST['salary_bank_name'] ?? '');
        $salary_account_number = empty($salary_account_number) ? null : preg_replace('/[^0-9]/', '', $salary_account_number);
        $salary_bank_name      = empty($salary_bank_name) ? null : $salary_bank_name;

        // Server-Side Field Validation Engine
        if (strlen((string)$nin) !== 11) {
            $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-exclamation-triangle-fill me-1"></i> The National Identification Number (NIN) must be exactly 11 digits.</div>';
            $showForm = true;
        } elseif (empty($first_name) || empty($surname)) {
            $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-exclamation-triangle-fill me-1"></i> Legal identity component names are required.</div>';
            $showForm = true;
        } elseif (empty($phone)) {
            $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-exclamation-triangle-fill me-1"></i> Communication mobile routing number is required.</div>';
            $showForm = true;
        } elseif (!$email) {
            $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-exclamation-triangle-fill me-1"></i> Provided authorization email structural context is invalid.</div>';
            $showForm = true;
        } elseif ($expected === false || $expected <= 0) {
            $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-exclamation-triangle-fill me-1"></i> Ledger accounting cycle value allocation target must be positive.</div>';
            $showForm = true;
        } elseif (!empty($salary_account_number) && strlen($salary_account_number) !== 10) {
            $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-exclamation-triangle-fill me-1"></i> Payout bank account reference must be 10 digits (NUBAN layout).</div>';
            $showForm = true;
        } elseif (empty($salary_bank_name)) {
            $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-exclamation-triangle-fill me-1"></i> Please choose a layout profile from the valid bank listing.</div>';
            $showForm = true;
        } else {
            // Execution payload processing
            $remittanceManager = new RemittanceManager($db);
            try {
                $remittanceManager->createUserWithApproval([
                    'nin'                        => $nin,
                    'first_name'                 => $first_name,
                    'surname'                    => $surname,
                    'other_name'                 => $other_name, 
                    'phone'                      => $phone,
                    'email'                      => $email,
                    'gender'                     => $gender,
                    'dob'                        => $dob,
                    'salary_account_number'      => $salary_account_number, 
                    'salary_bank_name'           => $salary_bank_name,
                    'cluster_code'               => $my_code,
                    'host_organization'          => $host_organization,
                    'resumption_date'            => $resumption_date, 
                    'state_of_origin'            => $state_of_origin,
                    'lga'                        => $lga,
                    'expected_remittance_amount' => $expected
                ], 'CLUSTER_MANAGER');
                
                header('Location: ' . APP_BASE_URL . '/manager/index.php?success=1');
                exit;
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                // Parsing detailed unique index key violations dynamically
                if (str_contains($errorMessage, '23000') || str_contains(strtolower($errorMessage), 'duplicate')) {
                    if (str_contains($errorMessage, 'nin')) {
                        $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-bug me-1"></i> Duplicate Conflict: This NIN is already registered in the system.</div>';
                    } elseif (str_contains($errorMessage, 'phone')) {
                        $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-bug me-1"></i> Duplicate Conflict: This Phone Number is already registered in the system.</div>';
                    } elseif (str_contains($errorMessage, 'email')) {
                        $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-bug me-1"></i> Duplicate Conflict: This Email Address is already registered in the system.</div>';
                    } else {
                        $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-bug me-1"></i> System Conflict: A unique profile parameter matches an existing participant record.</div>';
                    }
                } else {
                    $registrationMsg = '<div class="alert alert-danger py-2 small fw-semibold text-center"><i class="bi bi-bug me-1"></i> Processing Exception: ' . htmlspecialchars($errorMessage) . '</div>';
                }
                $showForm = true; 
            }
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $registrationMsg = '<div class="alert alert-success py-2 small fw-semibold text-center"><i class="bi bi-check-circle-fill me-1"></i> Operations updated successfully. Record state synchronized.</div>';
}

// Load each member with the current cycle remittance summary when available.
$query = "SELECT u.*, r.id AS remittance_record_id, r.expected_amount AS cycle_expected, r.amount_paid AS cycle_paid, r.payment_status
          FROM users u
          LEFT JOIN remittance r ON r.userid = u.id" . ($currentCycleId ? " AND r.cycle_id = ?" : " AND 1=0") . "
          WHERE u.cluster_code = ?
          ORDER BY u.id DESC";

$params = [];
if ($currentCycleId) {
    $params[] = $currentCycleId;
}
$params[] = $my_code;

$stmt_u = $db->prepare($query);
$stmt_u->execute($params);
$my_users = $stmt_u->fetchAll();

// Dynamic computation for ledger summary calculations
$balanceSummary = $db->prepare(
    "SELECT SUM(r.expected_amount) AS expected, SUM(r.amount_paid) AS paid
     FROM remittance r
     JOIN users u ON u.id = r.userid
     WHERE u.cluster_code = ?" . ($currentCycleId ? " AND r.cycle_id = ?" : "")
);
$balanceParams = [$my_code];
if ($currentCycleId) {
    $balanceParams[] = $currentCycleId;
}
$balanceSummary->execute($balanceParams);
$totals = $balanceSummary->fetch();

$totalExpected = floatval($totals['expected'] ?? 0);
$totalPaid = floatval($totals['paid'] ?? 0);
$totalOutstanding = max(0.0, $totalExpected - $totalPaid);

include_once '../partials/header.php';
?>

<!-- Load Paystack Inline JavaScript SDK Platform Core -->
<script src="https://js.paystack.co/v1/inline.js"></script>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 border-bottom pb-3">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold"><i class="bi bi-grid-1x2-fill text-primary me-2"></i>Cluster Operational Insights</h1>
        <p class="text-muted small mb-0">
            Node Allocation Scope: <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold fs-6 px-3 py-1 rounded-pill mt-1"><?php echo htmlspecialchars($cluster['manager_name'] .'-'. " [".$my_code."]"); ?></span>
        </p>
    </div>
    <div class="d-flex align-items-center gap-2 mt-2 mt-md-0">
        <?php if (!$showForm): ?>
            <a href="?action=add_user" class="btn btn-sm btn-success fw-bold px-3 py-2 shadow-xs">
                <i class="bi bi-person-plus-fill me-1"></i> Add New User
            </a>
        <?php else: ?>
            <a href="index.php" class="btn btn-sm btn-outline-secondary fw-semibold px-3 py-2 shadow-xs">
                <i class="bi bi-arrow-left me-1"></i> View Registry Table
            </a>
        <?php endif; ?>
        <div class="bg-light border rounded px-3 py-2 text-md-end shadow-xs">
            <span class="text-muted small fw-semibold d-block" style="font-size:0.75rem;">Active Tracking Window</span>
            <strong class="text-dark font-monospace small"><i class="bi bi-calendar-event text-secondary me-1"></i><?php echo htmlspecialchars($cyclePeriod); ?></strong>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card bg-white shadow-sm p-4 border-0 border-start border-primary border-4 rounded-3 h-100">
            <span class="text-xs text-uppercase mb-1 small fw-bold text-secondary tracking-wider">Expected Node Collection</span>
            <div class="h3 mb-0 fw-bold text-dark font-monospace">₦<?php echo number_format($totalExpected, 2); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-white shadow-sm p-4 border-0 border-start border-success border-4 rounded-3 h-100">
            <span class="text-xs text-uppercase mb-1 small fw-bold text-secondary tracking-wider">Settled Remittances Total</span>
            <div class="h3 mb-0 fw-bold text-success font-monospace">₦<?php echo number_format($totalPaid, 2); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-white shadow-sm p-4 border-0 border-start border-danger border-4 rounded-3 h-100">
            <span class="text-xs text-uppercase mb-1 small fw-bold text-secondary tracking-wider">Outstanding Sector Arrears</span>
            <div class="h3 mb-0 fw-bold text-danger font-monospace">₦<?php echo number_format($totalOutstanding, 2); ?></div>
        </div>
    </div>
</div>

<?php if (!empty($registrationMsg)) echo $registrationMsg; ?>

<?php if ($showForm): ?>
<div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius: 14px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="fw-bold text-dark mb-1"><i class="bi bi-person-plus-fill text-success me-1"></i> Provision New Operational Target</h5>
            <p class="text-muted small mb-0">Onboard a tracking profile line container directly into this cluster allocation bucket.</p>
        </div>
        <a href="index.php" class="btn-close" aria-label="Close"></a>
    </div>
    
    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . ($showForm ? '?action=add_user' : '')); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
        <div class="row g-2">
            <div class="col-md-3"><input type="text" name="nin" placeholder="11-Digit NIN Identification" class="form-control form-control-sm font-monospace" required maxlength="11" minlength="11"></div>
            <div class="col-md-3"><input type="text" name="first_name" placeholder="First Name" class="form-control form-control-sm" required></div>
            <div class="col-md-3"><input type="text" name="surname" placeholder="Surname" class="form-control form-control-sm" required></div>
            <div class="col-md-3"><input type="text" name="other_name" placeholder="Other Name (Optional)" class="form-control form-control-sm"></div>
            
            <div class="col-md-4"><input type="text" name="phone" placeholder="Active Phone Number" class="form-control form-control-sm" required></div>
            <div class="col-md-4"><input type="email" name="email" placeholder="Email Address (name@domain.com)" class="form-control form-control-sm" required></div>
            <div class="col-md-4"><input type="text" name="host_organization" placeholder="Employer / Host Organization" class="form-control form-control-sm"></div>
            
            <div class="col-md-4"><input type="text" name="salary_account_number" placeholder="Salary Payout Account (10 Digits)" class="form-control form-control-sm font-monospace" maxlength="10" required></div>
            
            <div class="col-md-4">
                <select name="salary_bank_name" class="form-select form-select-sm" required>
                    <option value="" selected disabled>Choose Salary Bank...</option>
                    <?php foreach($nigerian_banks as $bank): ?>
                        <option value="<?php echo htmlspecialchars($bank); ?>"><?php echo htmlspecialchars($bank); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light text-muted">₦</span>
                    <input type="number" step="0.01" name="expected_remittance_amount" placeholder="Expected Cycle Value Balance" class="form-control fw-bold text-dark" required>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light text-muted small" style="font-size:0.75rem;">Resumption Date</span>
                    <input type="date" name="resumption_date" class="form-control">
                </div>
            </div>
            <div class="col-md-4">
                <select name="gender" class="form-select form-select-sm text-muted">
                    <option value="" selected disabled>Select Gender Identity</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light text-muted small" style="font-size:0.75rem;">Date of Birth</span>
                    <input type="date" name="dob" class="form-control">
                </div>
            </div>
            <div class="col-md-6"><input type="text" name="state_of_origin" placeholder="State of Origin Location" class="form-control form-control-sm"></div>
            <div class="col-md-6"><input type="text" name="lga" placeholder="Local Government Area (LGA)" class="form-control form-control-sm"></div>
        </div>
        <div class="mt-3 text-end d-flex justify-content-end gap-2">
            <a href="index.php" class="btn btn-light btn-sm fw-bold px-3 py-2 border">Cancel</a>
            <button type="submit" name="register_member" class="btn btn-success btn-sm fw-bold px-4 py-2 shadow-sm">
                <i class="bi bi-cloud-arrow-up-fill me-1"></i> Register Participant
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius: 14px;">
    <div class="mb-3">
        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-hdd-network-fill text-secondary me-1"></i> Segment Registry Context Maps</h5>
        <p class="text-muted small mb-0">Real-time status tracking logs across all system profile slots inside this tracking node location.</p>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle small mb-0">
            <thead class="table-light text-secondary uppercase-header">
                <tr>
                    <th>NIN Matrix Ref</th>
                    <th>Legal Identity Name</th>
                    <th>Host Node</th>
                    <th>Cycle Target</th>
                    <th>Total Settled</th>
                    <th>Outstanding Balance</th>
                    <th class="text-center">Billing State</th>
                    <th class="text-center">Approval Access</th>
                    <th class="text-center">Gateway Gateway Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($my_users)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4 fw-semibold"><i class="bi bi-folder-x fs-4 d-block mb-1"></i>No tracked identities bound to this cluster group partition index.</td></tr>
                <?php else: ?>
                    <?php foreach($my_users as $u): ?>
                        <?php
                            $cyclePaid = floatval($u['cycle_paid'] ?? 0);
                            $expected = floatval($u['cycle_expected'] ?? $u['expected_remittance_amount'] ?? 0);
                            $outstanding = max(0.0, $expected - $cyclePaid);
                            $statusBadge = $u['payment_status'] ?? 'UNPAID';
                            $badgeClass = $statusBadge === 'FULLY_PAID' || $statusBadge === 'PAID' ? 'success' : ($statusBadge === 'PARTIAL' ? 'warning' : 'danger');
                            $fullName = trim($u['first_name'] . ' ' . (!empty($u['other_name']) ? $u['other_name'] . ' ' : '') . $u['surname']);
                        ?>
                        <tr>
                            <td><code class="text-dark fw-bold"><?php echo htmlspecialchars((string)$u['nin']); ?></code></td>
                            <td class="fw-medium text-dark"><?php echo htmlspecialchars($fullName); ?></td>
                            <td><span class="text-muted small"><?php echo htmlspecialchars((string)($u['host_organization'] ?? '— Unassigned Entity')); ?></span></td>
                            <td class="fw-semibold text-dark font-monospace">₦<?php echo number_format($expected, 2); ?></td>
                            <td class="text-success fw-bold font-monospace">₦<?php echo number_format($cyclePaid, 2); ?></td>
                            <td class="<?php echo $outstanding > 0 ? 'text-danger' : 'text-muted'; ?> fw-bold font-monospace">₦<?php echo number_format($outstanding, 2); ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $badgeClass; ?>-subtle text-<?php echo $badgeClass; ?> border border-<?php echo $badgeClass; ?>-subtle rounded-pill px-2" style="font-size:0.7rem;">
                                    <?php echo htmlspecialchars($statusBadge); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo ($u['approval_status'] === 'APPROVED' ? 'success' : ($u['approval_status'] === 'REJECTED' ? 'danger' : 'secondary')); ?>-subtle text-<?php echo ($u['approval_status'] === 'APPROVED' ? 'success' : ($u['approval_status'] === 'REJECTED' ? 'danger' : 'secondary')); ?> border border-<?php echo ($u['approval_status'] === 'APPROVED' ? 'success' : ($u['approval_status'] === 'REJECTED' ? 'danger' : 'secondary')); ?>-subtle px-2" style="font-size:0.7rem;">
                                    <?php echo htmlspecialchars((string)$u['approval_status']); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($outstanding > 0 && $u['approval_status'] === 'APPROVED' && $currentCycleId): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-primary fw-bold px-2 py-1 shadow-xs" 
                                            style="font-size: 0.72rem; --bs-btn-padding-y: .15rem; --bs-btn-padding-x: .4rem;"
                                            data-email="<?php echo htmlspecialchars($u['email']); ?>"
                                            data-amount="<?php echo ($outstanding * 100); ?>" 
                                            data-user-id="<?php echo htmlspecialchars((string)$u['id']); ?>"
                                            data-remittance-id="<?php echo htmlspecialchars((string)($u['remittance_record_id'] ?? '')); ?>"
                                            data-cycle-id="<?php echo htmlspecialchars((string)$currentCycleId); ?>"
                                            onclick="initializePaystackCheckout(this)">
                                        <i class="bi bi-credit-card-2-front-fill me-1"></i> Pay ₦<?php echo number_format($outstanding, 0); ?>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted font-monospace small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function initializePaystackCheckout(element) {
    const emailAddress = element.getAttribute('data-email');
    const amountInKobo = element.getAttribute('data-amount');
    const userId       = element.getAttribute('data-user-id');
    const remittanceId = element.getAttribute('data-remittance-id');
    const cycleId      = element.getAttribute('data-cycle-id');
    const redirectUrl  = element.getAttribute('data-redirect-url') || 'verify_payment.php';
    
    // Generate an immutable unique transaction tracking token reference
    const generatedReference = 'REMIT_PS_' + userId + '_' + cycleId + '_' + Math.floor(Math.random() * 10000000);

    let checkoutHandler = PaystackPop.setup({
        key: '<?php echo PAYSTACK_PUBLIC_KEY; ?>',
        email: emailAddress,
        amount: amountInKobo,
        currency: 'NGN',
        ref: generatedReference,
        metadata: {
            custom_fields: [
                { display_name: "Participant ID", variable_name: "user_id", value: userId },
                { display_name: "Remittance Record ID", variable_name: "remittance_id", value: remittanceId },
                { display_name: "Accounting Cycle ID", variable_name: "cycle_id", value: cycleId }
            ]
        },
        callback: function(response) {
            // Forward transaction telemetry token properties directly to background capture routine
            window.location.href = redirectUrl + '?reference=' + encodeURIComponent(response.reference) + 
                                   '&user_id=' + userId + 
                                   '&remittance_id=' + remittanceId + 
                                   '&cycle_id=' + cycleId;
        },
        onClose: function() {
            alert('Gateway Session Closed: Transaction tracking suspended by cluster coordinator.');
        }
    });

    checkoutHandler.openIframe();
}
</script>

<?php include_once '../partials/footer.php'; ?>