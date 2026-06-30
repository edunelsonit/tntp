<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Start session at the very top for CSRF tracking
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
require_once '../core/RemittanceManager.php';

$db = getDB();
$err = $_GET['err'] ?? '';

// Array of Nigerian States for the Dropdown Menu
$nigerian_states = [
    "Abuja (FCT)", "Abia", "Adamawa", "Akwa Ibom", "Anambra", "Bauchi", "Bayelsa", "Benue", "Borno", 
    "Cross River", "Delta", "Ebonyi", "Edo", "Ekiti", "Enugu", "Gombe", "Imo", "Jigawa", 
    "Kaduna", "Kano", "Katsina", "Kebbi", "Kogi", "Kwara", "Lagos", "Nasarawa", "Niger", 
    "Ogun", "Ondo", "Osun", "Oyo", "Plateau", "Rivers", "Taraba", "Yobe", "Zamfara"
];

// Helper Functions (Fallback declarations if not defined in config)
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// ==========================================
// IN-LINE ROUTING WORKFLOW FOR SELF-REGISTRATION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Anti-Forgery Check
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrf_token)) {
        header("Location: " . APP_BASE_URL . "/users/register.php?err=invalid");
        exit;
    }

    // 2. Map & Sanitize Input Properties
    $nin              = sanitizeText($_POST['nin'] ?? '');
    $first_name       = sanitizeText($_POST['first_name'] ?? '');
    $surname          = sanitizeText($_POST['surname'] ?? '');
    $othername        = sanitizeText($_POST['other_name'] ?? '') ?: null;
    $phone            = sanitizeText($_POST['phone'] ?? '');
    $email            = sanitizeEmail($_POST['email'] ?? '');
    $cluster_code     = sanitizeText($_POST['cluster_code'] ?? '');
    $expected_amount  = sanitizeFloat($_POST['expected_remittance_amount'] ?? '100000');
    $gender           = sanitizeText($_POST['gender'] ?? '') ?: null;
    $dob              = sanitizeText($_POST['dob'] ?? '') ?: null;
    $resumption       = sanitizeText($_POST['resumption_date'] ?? '');
    $state            = sanitizeText($_POST['state'] ?? '') ?: null;
    $lga              = sanitizeText($_POST['lga'] ?? '') ?: null;
    
    // Backend Enforcement: Sanitize and convert Host Organization to strictly ALL CAPS
    $host             = strtoupper(sanitizeText($_POST['host_organization'] ?? '')); 
    
    $salary_account   = sanitizeText($_POST['salary_account_number'] ?? '');
    $salary_bank      = sanitizeText($_POST['salary_bank_name'] ?? '');

    // Automatic Generation: Clean special characters/spaces out of first name and merge with NIN
    $clean_first_name = preg_replace('/[^A-Za-z0-9]/', '', $first_name);
    $mon_ref          = strtoupper($clean_first_name) . $nin; 

    // Formatting Strings & Stripping White Spaces/Dashes from Phone/Account numbers
    $phone            = preg_replace('/[^0-9]/', '', $phone);
    $salary_account   = empty($salary_account) ? null : preg_replace('/[^0-9]/', '', $salary_account);
    $salary_bank      = empty($salary_bank) ? null : $salary_bank;

    // 3. Structural Validation Checks
    if (!preg_match('/^[0-9]{11}$/', $nin) || empty($first_name) || empty($surname) || empty($phone) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($cluster_code) || $expected_amount <= 0) {
        header("Location: " . APP_BASE_URL . "/users/register.php?err=invalid");
        exit;
    }

    try {
        // 4. Verify Target Cluster Code Status
        $stmt_c = $db->prepare('SELECT id FROM clusters WHERE cluster_code = ?');
        $stmt_c->execute([$cluster_code]);
        if (!$stmt_c->fetch()) {
            header("Location: " . APP_BASE_URL . "/users/register.php?err=invalid_cluster");
            exit;
        }

        // 5. Prevent Duplicate Accounts
        $stmt = $db->prepare('SELECT id FROM users WHERE nin = ? OR email = ? OR phone = ?');
        $stmt->execute([$nin, $email, $phone]);
        if ($stmt->fetch()) {
            header("Location: " . APP_BASE_URL . "/users/register.php?err=exists");
            exit;
        }

        // 6. Execute Registration via Core Manager
        $manager = new RemittanceManager($db);
        $manager->createUserWithApproval([
            'nin' => $nin,
            'first_name' => $first_name,
            'surname' => $surname,
            'other_name' => $othername,
            'phone' => $phone,
            'email' => $email,
            'cluster_code' => $cluster_code,
            'gender' => $gender,
            'dob' => $dob,
            'state_of_origin' => $state,
            'lga' => $lga,
            'host_organization' => $host,
            'expected_remittance_amount' => $expected_amount,
            'salary_account_number' => $salary_account,
            'salary_bank_name' => $salary_bank,
            'resumption_date' => $resumption,
            'monnify_reference' => $mon_ref,
            'created_by'=>'SELF'
        ]);

        header("Location: " . APP_BASE_URL . "/users/register.php?err=success");
        exit;

    } catch (PDOException $e) {
        error_log("Inline Self-Registration Fault: " . $e->getMessage());
        header("Location: " . APP_BASE_URL . "/users/register.php?err=db_error");
        exit;
    }
}

// Dynamic cluster compilation for the dropdown menu UI
try {
     $clusters = $db->query("SELECT cluster_code, cluster_name, manager_name FROM clusters ORDER BY cluster_name ASC")->fetchAll();
} catch (PDOException $e) {
    $clusters = [];
}

include_once '../partials/header.php';
?>
<div class="row justify-content-center align-items-center my-5" style="min-height: 75vh;">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow border-0" style="border-radius: 16px;">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <h2 class="fw-bold text-primary mb-1">Create Account</h2>
                    <p class="text-muted small">Register to access your TNTP statement</p>
                </div> 
                
                <?php if ($err === 'exists'): ?>
                    <div class="alert alert-danger py-2 small text-center fw-semibold">NIN, Phone, or Email parameter is already registered inside our registry.</div>
                <?php elseif ($err === 'invalid'): ?>
                    <div class="alert alert-warning py-2 small text-center fw-semibold">Missing or structurally invalid input metrics provided.</div>
                <?php elseif ($err === 'invalid_cluster'): ?>
                    <div class="alert alert-danger py-2 small text-center fw-semibold">The specified Cluster Code does not match any active group.</div>
                <?php elseif ($err === 'db_error'): ?>
                    <div class="alert alert-danger py-2 small text-center fw-semibold">Internal storage registry engine compilation failure.</div>
                <?php elseif ($err === 'success'): ?>
                    <div class="alert alert-success py-2 small text-center fw-semibold">Registration successful! You can now access your profile.</div>
                <?php endif; ?>

                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Assigned Cluster Association</label>
                        <select name="cluster_code" class="form-select text-uppercase" required>
                            <option value="" disabled selected>-- Select your tracking cluster group --</option>
                            <?php foreach ($clusters as $c): ?>
                                <option value="<?php echo htmlspecialchars($c['cluster_code']); ?>">
                                    <?php echo htmlspecialchars($c['manager_name'] . ' (' . $c['cluster_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">National Identification Number (NIN)</label>
                        <input type="text" name="nin" class="form-control" required maxlength="11" minlength="11" pattern="\d{11}" title="NIN must be exactly 11 numeric digits." placeholder="Enter valid 11-digit NIN">
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-md-4 mb-3">
                            <label class="form-label small fw-bold text-secondary">First Name</label>
                            <input type="text" name="first_name" class="form-control" required placeholder="First Name">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label small fw-bold text-secondary">Surname</label>
                            <input type="text" name="surname" class="form-control" required placeholder="Last Name">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label small fw-bold text-secondary">Middle Name</label>
                            <input type="text" name="other_name" class="form-control" placeholder="Optional"> 
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Phone / WhatsApp Number</label>
                        <input type="tel" name="phone" class="form-control" required placeholder="e.g. 08030000000">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">NJFP Registered Email Address</label>
                        <input type="email" name="email" class="form-control" required placeholder="yourname@tntp.com.ng">
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-bold text-secondary">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="" selected disabled>Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-bold text-secondary">Date of Birth</label>
                            <input type="date" name="dob" class="form-control">
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-bold text-secondary">State of Residence</label>
                            <select name="state" class="form-select" required>
                                <option value="" selected disabled>-- Select State --</option>
                                <?php foreach ($nigerian_states as $state_name): ?>
                                    <option value="<?php echo htmlspecialchars($state_name); ?>">
                                        <?php echo htmlspecialchars($state_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-bold text-secondary">Local Government Area (LGA)</label>
                            <input type="text" name="lga" class="form-control" placeholder="LGA">
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-7 mb-3">
                            <label class="form-label small fw-bold text-secondary">Host Organization</label>
                            <input type="text" name="host_organization" class="form-control text-uppercase" placeholder="FULL HOST NAME">
                        </div>
                        <div class="col-md-5 mb-3">
                            <label class="form-label small fw-bold text-secondary">Date of Resumption</label>
                            <input type="date" name="resumption_date" class="form-control">
                        </div>
                    </div>

                    <div class="row g-2 p-3 bg-light rounded border mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Salary Account Number (NUBAN)</label>
                            <input type="text" name="salary_account_number" class="form-control font-monospace" maxlength="10" minlength="10" pattern="\d{10}" title="Account number must be exactly 10 digits." placeholder="e.g. 0123456789">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Salary Bank Name</label>
                            <input type="text" name="salary_bank_name" class="form-control" placeholder="e.g. Zenith Bank">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-secondary">Expected Remittance Base Amount</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light fw-semibold text-muted">₦</span>
                            <input type="text" class="form-control bg-light" required value="100,000.00" readonly>
                            <input type="hidden" name="expected_remittance_amount" value="100000">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm mb-3">Register Profile</button>
                    
                    <div class="text-center">
                        <p class="small text-muted mb-0">Already have an active account? <a href="<?php echo APP_BASE_URL; ?>/index.php" class="text-decoration-none fw-bold text-primary">Login here</a></p>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include_once '../partials/footer.php'; ?>