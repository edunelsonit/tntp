<?php
require_once '../config/config.php';
checkRouteAccess('admin');
$db = getDB();
$msg = '';

// Array of Nigerian States for the Dropdown Menu (Schema Type: ENUM/VARCHAR State Validation)
$nigerian_states = [
    "Abuja (FCT)", "Abia", "Adamawa", "Akwa Ibom", "Anambra", "Bauchi", "Bayelsa", "Benue", "Borno", 
    "Cross River", "Delta", "Ebonyi", "Edo", "Ekiti", "Enugu", "Gombe", "Imo", "Jigawa", 
    "Kaduna", "Kano", "Katsina", "Kebbi", "Kogi", "Kwara", "Lagos", "Nasarawa", "Niger", 
    "Ogun", "Ondo", "Osun", "Oyo", "Plateau", "Rivers", "Taraba", "Yobe", "Zamfara"
];

// CENTRAL NIGERIAN CENTRAL BANK (CBN) PROFILE DATA MAP
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
    "Stanbic IBTC Bank",
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

function monnifyAccessToken(&$error) {
    $error = 'Flutterwave secret key is not configured.';
    if (empty(FLUTTERWAVE_SECRET_KEY) || FLUTTERWAVE_SECRET_KEY === 'FLWSECK_TEST-REPLACE_ME') {
        return null;
    }

    return FLUTTERWAVE_SECRET_KEY;
}

function createReservedAccount($token, $user, &$error) {
    if (!empty($user['monnify_reference'])) {
        $reference = $user['monnify_reference'];
    } else {
        $clean_first_name = preg_replace('/[^A-Za-z0-9]/', '', $user['first_name']);
        $reference = strtoupper($clean_first_name) . $user['nin'];
    }

    $payload = [
        'email' => !empty($user['email']) ? $user['email'] : 'user_' . $user['id'] . '@payclust.system',
        'is_permanent' => true,
        'tx_ref' => $reference,
        'narration' => 'Salary account for ' . trim($user['first_name'] . ' ' . $user['surname']),
        'amount' => 0,
        'currency' => 'NGN'
    ];

    if (!empty($user['phone'])) {
        $payload['phonenumber'] = $user['phone'];
    }

    $ch = curl_init(FLUTTERWAVE_BASE_URL . '/virtual-account-numbers');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30
    ]);

    $body = curl_exec($ch);
    $curl_error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $curl_error) {
        $error = 'Reserved account request failed: ' . $curl_error;
        return null;
    }

    $payload = json_decode($body, true);
    if (($payload['status'] ?? '') !== 'success') {
        $error = 'Flutterwave account generation rejected. HTTP ' . $status;
        return null;
    }

    $account = $payload['data'] ?? [];
    $accountNumber = $account['account_number'] ?? $account['accountNumber'] ?? null;
    if (!$accountNumber) {
        $error = 'Flutterwave did not yield account information.';
        return null;
    }

    return [
        'reference' => $reference,
        'number' => $accountNumber,
        'bank' => $account['bank_name'] ?? $account['bankName'] ?? 'Flutterwave'
    ];
}

// Global CSRF Barrier Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf_token)) {
        header('Location: users.php?err=unauthorized');
        exit;
    }
}

// 1. POST ACTION: SUBMIT USER (FOLLOWING ACTUAL SCHEMA TARGET DIRECTIVES)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_user'])) {
    $nin         = trim($_POST['nin'] ?? '');
    $firstname   = trim($_POST['first_name'] ?? '');
    $surname     = trim($_POST['surname'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $email       = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $gender      = trim($_POST['gender'] ?? '') ?: null;
    $dob         = trim($_POST['dob'] ?? '') ?: null;
    $state       = trim($_POST['state_of_origin'] ?? '') ?: null;
    $lga         = trim($_POST['lga'] ?? '') ?: null;
    
    // Schema Enforcement: Uniform ALL CAPS string processing logic
    $host        = strtoupper(trim($_POST['host_organization'] ?? '')) ?: null; 
    $cluster     = trim($_POST['cluster_code'] ?? '');
    $expected    = filter_input(INPUT_POST, 'expected_amount', FILTER_VALIDATE_FLOAT);
    
    // Mapped directly into the available schema destination paths
    $virtual_acct = trim($_POST['salary_account_number'] ?? '') ?: null;
    $bank_name    = trim($_POST['salary_bank_name'] ?? '') ?: null;

    // Schema Enforcement: Dynamic generation mapping key 
    $clean_first_name = preg_replace('/[^A-Za-z0-9]/', '', $firstname);
    $mon_ref          = strtoupper($clean_first_name) . $nin; 

    if (!empty($virtual_acct)) {
        $virtual_acct = preg_replace('/[^0-9]/', '', $virtual_acct);
    }

    $admin_role = isset($_SESSION['role']) ? strtoupper($_SESSION['role']) : 'ADMIN';
    if (!in_array($admin_role, ['ADMIN', 'SUPER_ADMIN', 'CLUSTER_MANAGER'], true)) {
        $admin_role = 'ADMIN';
    }

    if (!preg_match('/^[0-9]{11}$/', $nin) || empty($firstname) || empty($surname) || empty($phone) || empty($cluster) || $expected === false || $expected <= 0 || !$email) {
        $msg = "<div class='alert alert-danger small'>Unable to register user. Invalid data parameters detected.</div>";
    } else {
        try {
            // Strict Schema Mapping Execution Sequence
            $stmt = $db->prepare("
                INSERT INTO users (
                    nin, first_name, surname, phone, email, cluster_code, 
                    gender, dob, state_of_origin, lga, host_organization, 
                    expected_remittance_amount, virtual_account, bank_name, monnify_reference,
                    approval_status, created_by, amount_paid, payment_status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'APPROVED', ?, 0.00, 'UNPAID', CURRENT_TIMESTAMP)
            ");
            
            $stmt->execute([
                $nin, $firstname, $surname, $phone, $email, $cluster, 
                $gender, $dob, $state, $lga, $host, 
                $expected, $virtual_acct, $bank_name, $mon_ref,
                $admin_role
            ]);
            
            $msg = "<div class='alert alert-success small'>User profile containing mapped core salary payout directives registered and approved successfully.</div>";
        } catch (PDOException $e) {
            $msg = "<div class='alert alert-danger small'>Database write exception code executed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// 2. POST ACTION: GENERATE ACCOUNTS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_accounts'])) {
    $pending = $db->query("SELECT * FROM users WHERE virtual_account IS NULL OR virtual_account = '' ORDER BY id ASC")->fetchAll();

    if (empty($pending)) {
        $msg = "<div class='alert alert-info small'>All tracking records have clear virtual account mappings assigned.</div>";
    } else {
        $error = '';
        $token = monnifyAccessToken($error);

        if (!$token) {
            $msg = "<div class='alert alert-danger small'>" . htmlspecialchars($error) . "</div>";
        } else {
            $success = 0; $failed = 0; $last_error = '';

            foreach ($pending as $user) {
                $account = createReservedAccount($token, $user, $last_error);
                if (!$account) { $failed++; continue; }

                $stmt = $db->prepare("UPDATE users SET virtual_account = ?, bank_name = ?, monnify_reference = ? WHERE id = ?");
                $stmt->execute([$account['number'], $account['bank'], $account['reference'], $user['id']]);
                $success++;
            }
            $msg = "<div class='alert alert-" . ($failed > 0 ? "warning" : "success") . " small'>Generated $success account(s). Failed: $failed.</div>";
        }
    }
}

// 3. POST ACTION: MARK SALARY CYCLE PAID
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_salary_paid'])) {
    $due_amount = filter_input(INPUT_POST, 'due_amount', FILTER_VALIDATE_FLOAT);
    $selected = $_POST['selected_users'] ?? [];

    if ($due_amount === false || $due_amount <= 0) {
        $msg = "<div class='alert alert-danger small'>Invalid numeric payment distribution parameters passed.</div>";
    } else {
        if (!empty($selected) && is_array($selected)) {
            $ids = array_map('intval', $selected);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE users SET expected_remittance_amount = ?, amount_paid = 0.00, payment_status = 'UNPAID' WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([$due_amount], $ids));
            $msg = "<div class='alert alert-success small'>Reset remittance values for selected profiles.</div>";
        } else {
            $stmt = $db->prepare("UPDATE users SET expected_remittance_amount = ?, amount_paid = 0.00, payment_status = 'UNPAID'");
            $stmt->execute([$due_amount]);
            $msg = "<div class='alert alert-success small'>Reset remittance target matrices across all targets globally.</div>";
        }
    }
}

// =======================================================
// READ/QUERY DASHBOARD POOLS 
// =======================================================
$clusters = $db->query("SELECT * FROM clusters ORDER BY cluster_name ASC")->fetchAll();
$without_accounts = (int) $db->query("SELECT COUNT(*) FROM users WHERE virtual_account IS NULL OR virtual_account = ''")->fetchColumn();

$pending_tx = $db->query("
    SELECT r.id AS remittance_id, r.expected_amount, r.amount_paid, r.payment_status, u.first_name, u.surname 
    FROM remittance r 
    JOIN users u ON u.id = r.userid 
    WHERE r.payment_status IN ('UNPAID','PARTIAL') 
    ORDER BY r.id DESC
")->fetchAll();

include_once '../partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold">User Registries Modification Panel</h2>
    <div class="d-flex gap-2">
        <?php if ($without_accounts > 0): ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                <button type="submit" name="generate_accounts" class="btn btn-sm btn-primary fw-bold">
                    <i class="bi bi-gear-fill me-1"></i> Provision Virtual Accounts (<?php echo $without_accounts; ?>)
                </button>
            </form>
        <?php endif; ?>
        <a href="<?php echo APP_BASE_URL; ?>/admin/index.php" class="btn btn-sm btn-outline-secondary">Back to Admin Dash</a>
    </div>
</div>

<?php echo $msg; ?>

<div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius: 14px;">
    <div class="border-bottom pb-2 mb-4">
        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-person-plus text-success me-2"></i>Register User Explicitly</h5>
        <p class="text-muted small mb-0">Onboard a new tracking profile with localized settlement vectors attached.</p>
    </div>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
        
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">NIN Identification</label>
                <input type="text" name="nin" class="form-control form-control-sm font-monospace" placeholder="11-digit numeric pattern" pattern="[0-9]{11}" required maxlength="11" minlength="11">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">First Name</label>
                <input type="text" name="first_name" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Surname</label>
                <input type="text" name="surname" class="form-control form-control-sm" required>
            </div>
            
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Email Address</label>
                <input type="email" name="email" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Active Phone Number</label>
                <input type="text" name="phone" class="form-control form-control-sm font-monospace" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Operational Cluster Group</label>
                <select name="cluster_code" class="form-select form-select-sm" required>
                    <option value="">Map network tracking group cluster...</option>
                    <?php foreach($clusters as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['cluster_code']); ?>">
                            <?php echo htmlspecialchars($c['cluster_code'] . ' - ' . $c['cluster_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Expected Base Remittance (₦)</label>
                <input type="number" step="0.01" name="expected_amount" value="100000.00" class="form-control form-control-sm font-monospace" required>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-bold text-dark"><i class="bi bi-credit-card-2-front text-primary me-1"></i> Pre-assigned Virtual Account #</label>
                <input type="text" name="salary_account_number" class="form-control form-control-sm font-monospace border-primary" placeholder="Optional 10-digit NUBAN" maxlength="10" pattern="[0-9]*">
            </div>
            
            <div class="col-md-4">
                <label class="form-label small fw-bold text-dark"><i class="bi bi-bank text-primary me-1"></i> Pre-assigned Bank Name</label>
                <select name="salary_bank_name" class="form-select form-select-sm border-primary">
                    <option value="" selected>Optional (Choose Settlement Bank...)</option>
                    <?php foreach($nigerian_banks as $bank): ?>
                        <option value="<?php echo htmlspecialchars($bank); ?>"><?php echo htmlspecialchars($bank); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Gender Designation</label>
                <select name="gender" class="form-select form-select-sm">
                    <option value="">Unspecified</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Date of Birth</label>
                <input type="date" name="dob" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">State of Origin</label>
                <select name="state_of_origin" class="form-select form-select-sm">
                    <option value="" selected disabled>-- Select State --</option>
                    <?php foreach ($nigerian_states as $state_name): ?>
                        <option value="<?php echo htmlspecialchars($state_name); ?>">
                            <?php echo htmlspecialchars($state_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">LGA Subdivision</label>
                <input type="text" name="lga" class="form-control form-control-sm">
            </div>
            <div class="col-md-8">
                <label class="form-label small fw-bold text-secondary">Host Organization</label>
                <input type="text" name="host_organization" class="form-control form-control-sm text-uppercase" placeholder="FULL HOST NAME">
            </div>
        </div>

        <div class="border-top pt-3 mt-4 text-end">
            <button type="submit" name="submit_user" class="btn btn-success btn-sm fw-bold px-4 py-2 shadow-sm">
                <i class="bi bi-person-check-fill me-1"></i> Register & Approve User Profile
            </button>
        </div>
    </form>
</div>

<div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius: 14px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-wallet2 text-warning me-2"></i>Pending Remittance Entries</h5>
        <span class="badge bg-secondary-subtle text-secondary fw-bold"><?php echo count($pending_tx); ?> Items</span>
    </div>
    
    <?php if (empty($pending_tx)): ?>
        <div class="small text-muted py-3 text-center">
            <i class="bi bi-cloud-check text-success fs-3 d-block mb-1"></i> All tracking records clear.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle small mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Remittance Reference Row</th>
                        <th>Target Member User Profile</th>
                        <th>Outstanding Remaining Balance</th>
                        <th>Verification Status Log</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($pending_tx as $pt): ?>
                        <tr>
                            <td><code class="font-monospace text-primary">REM_#<?php echo htmlspecialchars((string)$pt['remittance_id']); ?></code></td>
                            <td class="fw-semibold text-dark"><?php echo htmlspecialchars($pt['first_name'] . ' ' . $pt['surname']); ?></td>
                            <td class="font-monospace fw-bold text-dark">₦<?php echo number_format(max(0, floatval($pt['expected_amount']) - floatval($pt['amount_paid'])), 2); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $pt['payment_status'] === 'PARTIAL' ? 'warning' : 'danger'; ?>-subtle text-<?php echo $pt['payment_status'] === 'PARTIAL' ? 'warning' : 'danger'; ?> px-2.5 py-1.5 fw-bold" style="font-size: 0.7rem;">
                                    <?php echo htmlspecialchars((string)$pt['payment_status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include_once '../partials/footer.php'; ?>