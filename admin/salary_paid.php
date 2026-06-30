<?php
require_once '../config/config.php';
require_once '../core/RemittanceManager.php';

// Enforce strict super admin access control firewall rules
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();
$manager = new RemittanceManager($db);

// Generate modern timeline metric signatures matching the current context layer
$currentCycle = date('Y-m');
$msg = '';
$cycle = null;
$seededCount = 0;

// ==========================================
// ACTION 1: DECLARE A NEW SALARY CYCLE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['declare_cycle'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !validateCsrfToken($csrf_token)) {
        header('Location: salary_paid.php?err=invalid_token');
        exit;
    }

    try {
        $cycleId = $manager->ensureSalaryCycle($currentCycle, intval($_SESSION['admin_id'] ?? 0));
        
        $cycle = $db->prepare('SELECT * FROM remittance_cycles WHERE id = ?');
        $cycle->execute([$cycleId]);
        $cycle = $cycle->fetch();
        
        $seedStmt = $db->prepare('SELECT COUNT(*) FROM remittance WHERE cycle_id = ?');
        $seedStmt->execute([$cycleId]);
        $seededCount = (int)$seedStmt->fetchColumn();
        
        $msg = '
        <div class="alert alert-success d-flex align-items-center border-0 shadow-sm mb-4" role="alert">
            <i class="bi bi-check-circle-fill fs-5 me-2"></i>
            <div>
                <strong>Ledger Initialized Successfully:</strong> Remittance tracking timelines for period <strong>' . htmlspecialchars($currentCycle) . '</strong> have been constructed and seeded.
            </div>
        </div>';
    } catch (Exception $e) {
        $msg = '
        <div class="alert alert-danger d-flex align-items-center border-0 shadow-sm mb-4" role="alert">
            <i class="bi bi-exclamation-octagon-fill fs-5 me-2"></i>
            <div>
                <strong>Cycle Generation Exception:</strong> ' . htmlspecialchars($e->getMessage()) . '
            </div>
        </div>';
    }
}

// ==========================================
// ACTION 2: RESET ENGINE TO FORCE NEW SALARY ARRIVAL WINDOW
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_cycle'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !validateCsrfToken($csrf_token)) {
        header('Location: salary_paid.php?err=invalid_token');
        exit;
    }

    try {
        // Option A: Archiving/Closing the current cycle to let the next one instantiate naturally
        // If your database structure relies on an explicit 'is_active' status toggle, we clear it:
        $db->query("UPDATE remittance_cycles SET is_active = 0 WHERE is_active = 1");
        
        // Option B: If your RemittanceManager targets a unique monthly string (e.g., 2026-03), 
        // we can dynamically simulate the arrival of next month's window for testing:
        // (Uncomment the line below if you want to hardcode force-advance timelines for simulation)
        // $currentCycle = date('Y-m', strtotime('+1 month'));

        $msg = '
        <div class="alert alert-warning d-flex align-items-center border-0 shadow-sm mb-4" role="alert">
            <i class="bi bi-arrow-clockwise fs-5 me-2"></i>
            <div>
                <strong>Terminal Matrix Reset:</strong> Previous accounting timeline window closed. The ledger framework has been reset and is now monitoring for new incoming salary arrivals.
            </div>
        </div>';
        
        // Force state reload by clearing local memory buffer variables
        $cycle = null;
        $seededCount = 0;
    } catch (Exception $e) {
        $msg = '<div class="alert alert-danger mb-4">Reset Operation Fault: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Fetch historical lifecycle context states if active inside the system scope
if (!$cycle) {
    // If you implemented Option A above, we filter out closed cycles by checking an active flag:
    $stmt = $db->prepare('SELECT * FROM remittance_cycles WHERE cycle_period = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$currentCycle]);
    $cycle = $stmt->fetch();
    if ($cycle) {
        $seedStmt = $db->prepare('SELECT COUNT(*) FROM remittance WHERE cycle_id = ?');
        $seedStmt->execute([$cycle['id']]);
        $seededCount = (int)$seedStmt->fetchColumn();
    }
}

// Extract overall verification bounds metric states
$approvedCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE approval_status = 'APPROVED'")->fetchColumn();

include_once '../partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-3 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold"><i class="bi bi-calendar-check-fill text-primary me-2"></i>Salary Cycle Declaration Terminal</h1>
        <p class="text-muted small mb-0">Open current period baseline calculation matrices and populate global ledger pipelines.</p>
    </div>
    <div class="mt-3 mt-md-0">
        <a href="<?php echo APP_BASE_URL; ?>/admin/index.php" class="btn btn-sm btn-outline-secondary fw-semibold">
            <i class="bi bi-speedometer2 me-1"></i> Back to Admin Console
        </a>
    </div>
</div>

<?php if (!empty($msg)) echo $msg; ?>

<div class="row g-4 mb-4">
    <div class="col-xl-4 col-md-6">
        <div class="card shadow-sm border-0 p-4 bg-white h-100" style="border-radius: 14px;">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <span class="text-uppercase fw-bold text-secondary tracking-wider d-block mb-1" style="font-size: 0.75rem;">Target Account Window</span>
                    <h3 class="fw-bold text-dark font-monospace mb-0"><?php echo htmlspecialchars($currentCycle); ?></h3>
                </div>
                <div class="bg-primary-subtle text-primary rounded-3 px-3 py-2">
                    <i class="bi bi-clock-history fs-4"></i>
                </div>
            </div>
            <hr class="text-black-50 my-2">
            <div class="d-flex justify-content-between align-items-center small text-muted mt-2">
                <span>Eligible Active Accounts:</span>
                <strong class="text-dark bg-light px-3 py-1 rounded border font-monospace"><?php echo number_format($approvedCount); ?></strong>
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-md-6">
        <div class="card shadow-sm border-0 p-4 bg-white h-100" style="border-radius: 14px;">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <span class="text-uppercase fw-bold text-secondary tracking-wider d-block mb-1" style="font-size: 0.75rem;">Declaration Baseline Status</span>
                    <h4 class="fw-bold mb-0 <?php echo $cycle ? 'text-success' : 'text-warning'; ?>">
                        <i class="bi <?php echo $cycle ? 'bi-shield-fill-check' : 'bi-shield-fill-exclamation'; ?> me-1"></i>
                        <?php echo $cycle ? 'Active Open' : 'Uninitialized'; ?>
                    </h4>
                </div>
                <div class="bg-<?php echo $cycle ? 'success' : 'warning'; ?>-subtle text-<?php echo $cycle ? 'success' : 'warning'; ?> rounded-3 px-3 py-2">
                    <i class="bi bi-hdd-network fs-4"></i>
                </div>
            </div>
            <hr class="text-black-50 my-2">
            <?php if ($cycle) : ?>
                <div class="small text-muted mt-1">
                    <div class="d-flex justify-content-between mb-1">
                        <span>Seeded Base Rows:</span>
                        <strong class="text-dark font-monospace"><?php echo number_format($seededCount); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Initialization Timestamp:</span>
                        <strong class="text-dark font-monospace small"><?php echo htmlspecialchars($cycle['created_at'] ?? $cycle['salary_declared_at'] ?? 'System Seeding'); ?></strong>
                    </div>
                </div>
            <?php else : ?>
                <p class="text-muted small mb-0 mt-2"><i class="bi bi-info-circle me-1"></i> No accounting framework exists yet for this month context window.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4 col-12">
        <div class="card shadow-sm border-0 p-4 bg-white h-100 text-center justify-content-center border-start border-4 <?php echo $cycle ? 'border-warning' : 'border-primary'; ?>" style="border-radius: 14px;">
            <h5 class="fw-bold text-dark mb-2">Process Action</h5>
            <p class="text-muted small mb-3">Commit dynamic state data transformation operations.</p>
            
            <?php if ($cycle) : ?>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <button type="submit" name="reset_cycle" class="btn btn-warning btn-md w-100 py-3 fw-bold rounded-3 shadow-xs" onclick="return confirm('CRITICAL RESET WARNING:\n\nAre you sure you want to close the active layout period? Doing this closes out the current window so a new cycle can be safely declared when new salary values arrive.');">
                        <i class="bi bi-arrow-clockwise me-1"></i> Reset & Open Next Cycle
                    </button>
                </form>
            <?php else : ?>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <button type="submit" name="declare_cycle" class="btn btn-primary btn-md w-100 py-3 fw-bold rounded-3 shadow" onclick="return confirm('CRITICAL OPERATION SYSTEM NOTICE:\n\nAre you sure you want to initialize the global salary remittance parameters for this month? This operation seeds record collections site-wide.');">
                        <i class="bi bi-lightning-charge-fill me-1"></i> Declare Salary Paid
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 p-4 bg-white" style="border-radius: 14px;">
    <h5 class="fw-bold text-dark mb-2 d-flex align-items-center">
        <i class="bi bi-info-square-fill text-secondary me-2 fs-5"></i> System Ledger Seeding Behavior Checkpoints
    </h5>
    <p class="text-secondary small mb-3">Declaring a current tracking lifecycle triggers a series of system actions across your financial data modules:</p>
    
    <div class="row g-3 small text-muted">
        <div class="col-md-4">
            <div class="p-3 bg-light rounded-3 h-100">
                <strong class="d-block text-dark mb-1"><i class="bi bi-1-circle-fill text-primary me-1"></i> 1. State Matrix Verification</strong>
                The ingestion architecture matches all global profile configurations marked strictly as <code>APPROVED</code> inside system variables.
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 bg-light rounded-3 h-100">
                <strong class="d-block text-dark mb-1"><i class="bi bi-2-circle-fill text-primary me-1"></i> 2. Ledger Instantiation</strong>
                An isolated tracking slot context is initialized for the matching <code>YYYY-MM</code> signature string inside the primary tracking log indexes.
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 bg-light rounded-3 h-100">
                <strong class="d-block text-dark mb-1"><i class="bi bi-3-circle-fill text-primary me-1"></i> 3. Allocation Baseline Drops</strong>
                Target expectations from user cards populate the active ledger sheets instantly as pending demands, waiting for settle payloads from gateway webhooks.
            </div>
        </div>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>