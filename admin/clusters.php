<?php
require_once '../config/config.php';
checkRouteAccess('admin');

$db = getDB();
$msg = '';

// Intercept routing session flash flags
if (isset($_GET['success'])) {
    $msg = "<div class='alert alert-success border-0 shadow-sm py-2 px-3 small fw-semibold text-center'><i class='bi bi-check-circle-fill me-1'></i> Cluster operational path provisioned successfully into core directory.</div>";
} elseif (isset($_GET['deleted'])) {
    $msg = "<div class='alert alert-success border-0 shadow-sm py-2 px-3 small fw-semibold text-center'><i class='bi bi-trash-fill me-1'></i> Cluster group removed from operational ecosystem records.</div>";
} elseif (isset($_GET['err'])) {
    if ($_GET['err'] === 'collision') {
        $msg = "<div class='alert alert-danger border-0 shadow-sm py-2 px-3 small fw-semibold text-center'><i class='bi bi-exclamation-triangle-fill me-1'></i> Constraint violation: Group cluster identification code collision detected.</div>";
    } elseif ($_GET['err'] === 'csrf') {
        $msg = "<div class='alert alert-danger border-0 shadow-sm py-2 px-3 small fw-semibold text-center'><i class='bi bi-shield-slash-fill me-1'></i> Invalid security verification signature sequence context token.</div>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_cluster'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrf_token)) {
        header('Location: clusters.php?err=csrf');
        exit;
    }

    $code          = strtoupper(trim($_POST['cluster_code'] ?? ''));
    $manager_name  = strtoupper(trim($_POST['cluster_manager_name'] ?? ''));
    $manager_email = strtolower(trim($_POST['cluster_manager_email'] ?? ''));
    $name          = trim($_POST['cluster_name'] ?? '');
    $location      = trim($_POST['cluster_location'] ?? '');
    $pass          = password_hash($_POST['cluster_password'] ?? '', PASSWORD_BCRYPT);

    try {
        $stmt = $db->prepare("INSERT INTO clusters (cluster_code, cluster_name, cluster_location, manager_name, manager_email, manager_password) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$code, $name, $location, $manager_name, $manager_email, $pass]);
        
        header('Location: clusters.php?success=1');
        exit;
    } catch (Exception $e) {
        header('Location: clusters.php?err=collision');
        exit;
    }
}

// Generate an initial random cluster verification tracker mock string identifier
$generatedCode = 'TNTP' . rand(100000, 999999);
$clusters = $db->query("SELECT * FROM clusters ORDER BY id DESC")->fetchAll();

include_once '../partials/header.php';

// Generate security payload token instance strictly once per runtime cycle
$page_csrf_token = generateCsrfToken();
?>

<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-4 pb-3 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold">Cluster Resource Provisioning</h1>
        <p class="text-muted small mb-0">Initialize, audit, and distribute decentralized regional monitoring zone clusters.</p>
    </div>
    <div class="mt-2 mt-sm-0">
        <a href="<?php echo APP_BASE_URL; ?>/admin/index.php" class="btn btn-sm btn-outline-secondary px-3 fw-medium">
            <i class="bi bi-arrow-left me-1"></i> Dashboard Home
        </a>
    </div>
</div>

<?php echo $msg; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card p-4 bg-white border-0 shadow-sm" style="border-radius: 14px; border: 1px solid #f1f5f9 !important;">
            <div class="mb-3">
                <h5 class="fw-bold text-dark mb-1">Create Cluster Node</h5>
                <p class="text-muted small mb-0">Provision an individual structural node unit.</p>
            </div>
            
            <form method="POST" action="clusters.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Generated Target Node Code</label>
                    <input type="text" name="cluster_code" value="<?php echo $generatedCode; ?>" class="form-control form-control-sm fw-mono font-monospace bg-light text-primary border-0 py-2" readonly />
                </div>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Assigned Operations Manager Name</label>
                    <input type="text" name="cluster_manager_name" placeholder="Full name of cluster manager" class="form-control form-control-sm py-2" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Manager Official Contact Email</label>
                    <input type="email" name="cluster_manager_email" placeholder="manager@domain.com" class="form-control form-control-sm py-2" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Cluster State</label>
                    <input type="text" name="cluster_name" placeholder="e.g. Abuja" class="form-control form-control-sm py-2" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Local Government</label>
                    <input type="text" name="cluster_location" placeholder="e.g Jabi" class="form-control form-control-sm py-2" required>
                </div>
                
                <div class="mb-4">
                    <label class="form-label small fw-bold text-secondary">Secure Access Authentication Pass</label>
                    <input type="password" name="cluster_password" placeholder="••••••••••••" class="form-control form-control-sm py-2" required>
                    <div class="form-text text-muted" style="font-size: 0.725rem;">This credential authorizes remote bulk CSV uploads on behalf of this cluster.</div>
                </div>
                
                <button type="submit" name="submit_cluster" class="btn btn-primary btn-sm w-100 fw-bold py-2.5 shadow-sm">
                    <i class="bi bi-plus-circle me-1"></i> Deploy Cluster Infrastructure
                </button>
            </form>
        </div>
    </div>
    
    <div class="col-lg-8">
        <div class="card p-0 bg-white border-0 shadow-sm" style="border-radius: 14px; border: 1px solid #f1f5f9 !important;">
            <div class="p-4 border-bottom border-light">
                <h5 class="fw-bold text-dark mb-1">Network Operational Directory</h5>
                <p class="text-muted small mb-0">Active nodes currently routing transactions and onboarding targets.</p>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light text-secondary uppercase fw-semibold" style="font-size: 0.825rem;">
                        <tr>
                            <th class="ps-4 py-3">Code</th>
                            <th class="py-3">State</th>
                            <th class="py-3">Assigned Manager</th>
                            <th class="py-3">Contact Axis</th>
                            <th class="py-3">LGA</th>
                            <th class="pe-4 py-3 text-end">Action Interface</th>
                        </tr>
                    </thead>
                    <tbody class="text-dark">
                        <?php if (empty($clusters)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="bi bi-hdd-network fs-2 d-block text-black-50 mb-2"></i>
                                    No active data cluster instances recognized inside this system scope registry.
                                </td>
                            </tr>
                        <?php else: foreach($clusters as $c): ?>
                            <tr>
                                <td class="ps-4">
                                    <code class="text-primary fw-bold font-monospace"><?php echo htmlspecialchars($c['cluster_code']); ?></code>
                                </td>
                                <td class="fw-semibold">
                                    <?php echo htmlspecialchars($c['cluster_name']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($c['manager_name']); ?>
                                </td>
                                <td class="text-secondary">
                                    <?php echo htmlspecialchars($c['manager_email']); ?>
                                </td>
                                <td class="text-muted">
                                    <?php echo htmlspecialchars($c['cluster_location']); ?>
                                </td>
                                <td class="pe-4 text-end">
                                    <form method="POST" action="delete_cluster.php" onsubmit="return confirm('Are you sure you want to terminate this operational cluster configuration? All associated records will lose explicit root mapping links.');" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                                        <input type="hidden" name="cluster_code" value="<?php echo htmlspecialchars($c['cluster_code']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-1" style="font-size: 0.775rem;">
                                            <i class="bi bi-trash3 me-1"></i> Decommission
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>