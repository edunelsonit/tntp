<?php
require_once '../config/config.php';

// Enforce explicit administration routing rules seamlessly via configuration engine
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'], true)) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();

$id = isset($_GET['id']) ? intval($_GET['id']) : null;
if (!$id) { 
    header('Location: users.php'); 
    exit; 
}

// Fetch complete user data matrix layout row matching database schemas
$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$u = $stmt->fetch();

if (!$u) { 
    header('Location: index.php'); 
    exit; 
}

// Fetch operational cluster groups to safely map dropdown choices
$clusters = $db->query("SELECT * FROM clusters ORDER BY cluster_name ASC")->fetchAll();

include_once '../partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-2 border-bottom">
    <div>
        <h3 class="fw-bold text-dark mb-1">
            <i class="bi bi-person-gear text-primary me-2"></i>Modify Profile: <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['surname']); ?>
        </h3>
        <p class="small text-muted mb-0">Submitting this form records explicit alteration sets onto the change routing gateway for system tracking.</p>
    </div>
    <div class="mt-2 mt-md-0">
        <a href="users.php" class="btn btn-sm btn-outline-secondary fw-semibold">
            <i class="bi bi-arrow-left me-1"></i> Back to User Registries
        </a>
    </div>
</div>

<div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius: 14px;">
    <form method="POST" action="request_user_change.php">
        <input type="hidden" name="user_id" value="<?php echo $id; ?>">
        <input type="hidden" name="change_type" value="PROFILE_UPDATE">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">

        <div class="row g-3 mb-4">
            <div class="col-12 border-bottom pb-1 mb-2">
                <span class="badge bg-primary-subtle text-primary fw-bold text-uppercase tracking-wider px-2.5 py-1.5" style="font-size: 0.75rem;">
                    <i class="bi bi-card-id me-1"></i> Identity Profiles
                </span>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">National Identification Number (NIN)</label>
                <input type="text" name="nin" value="<?php echo htmlspecialchars($u['nin'] ?? ''); ?>" class="form-control form-control-sm font-monospace text-dark fw-bold" placeholder="11-digit national identity index" pattern="[0-9]{11}" required readonly>
                <div class="form-text text-muted extra-small" style="font-size: 0.65rem;">System unique indices (NIN) cannot be mutated dynamically.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">First Name</label>
                <input type="text" name="first_name" value="<?php echo htmlspecialchars($u['first_name'] ?? ''); ?>" class="form-control form-control-sm fw-semibold" required>
            </div>
            
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Surname</label>
                <input type="text" name="surname" value="<?php echo htmlspecialchars($u['surname'] ?? ''); ?>" class="form-control form-control-sm fw-semibold" required>
            </div>
            
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Other Name</label>
                <input type="text" name="other_name" value="<?php echo htmlspecialchars($u['other_name'] ?? ''); ?>" class="form-control form-control-sm fw-semibold">
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Mobile Contact Phone Link</label>
                <input type="text" name="phone" value="<?php echo htmlspecialchars($u['phone'] ?? ''); ?>" class="form-control form-control-sm font-monospace" required>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Email Address</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>" class="form-control form-control-sm" required>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 border-bottom pb-1 mb-2">
                <span class="badge bg-secondary-subtle text-secondary fw-bold text-uppercase tracking-wider px-2.5 py-1.5" style="font-size: 0.75rem;">
                    <i class="bi bi-geo-alt me-1"></i> Demographic Parameters
                </span>
            </div>

            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary">Gender Designation</label>
                <select name="gender" class="form-select form-select-sm">
                    <option value="Male" <?php echo (ucfirst(strtolower($u['gender'] ?? '')) === 'Male') ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo (ucfirst(strtolower($u['gender'] ?? '')) === 'Female') ? 'selected' : ''; ?>>Female</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary">Date of Birth</label>
                <input type="date" name="dob" value="<?php echo htmlspecialchars($u['dob'] ?? ''); ?>" class="form-control form-control-sm font-monospace">
            </div>

            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary">State of Origin</label>
                <input type="text" name="state_of_origin" value="<?php echo htmlspecialchars($u['state_of_origin'] ?? ''); ?>" class="form-control form-control-sm">
            </div>

            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary">LGA Subdivision</label>
                <input type="text" name="lga" value="<?php echo htmlspecialchars($u['lga'] ?? ''); ?>" class="form-control form-control-sm">
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 border-bottom pb-1 mb-2">
                <span class="badge bg-dark-subtle text-dark fw-bold text-uppercase tracking-wider px-2.5 py-1.5" style="font-size: 0.75rem; background-color:#e2e3e5;">
                    <i class="bi bi-shield-lock me-1"></i> Inbound Collection Coordinates (Read-Only)
                </span>
            </div>
            
            <div class="col-md-6">
                <label class="form-label small fw-bold text-muted">Virtual Assignment Inflow Account Number</label>
                <input type="text" value="<?php echo htmlspecialchars($u['virtual_account'] ?? 'Not Provisioned Yet'); ?>" class="form-control form-control-sm font-monospace bg-light text-muted" readonly>
            </div>

            <div class="col-md-6">
                <label class="form-label small fw-bold text-muted">Inbound Collection Bank Name</label>
                <input type="text" value="<?php echo htmlspecialchars($u['bank_name'] ?? 'N/A'); ?>" class="form-control form-control-sm bg-light text-muted" readonly>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 border-bottom pb-1 mb-2">
                <span class="badge bg-warning-subtle text-warning-dark fw-bold text-uppercase tracking-wider px-2.5 py-1.5" style="font-size: 0.75rem; color: #854d0e;">
                    <i class="bi bi-sliders me-1"></i> System Status & Governance Lifecycle Controls
                </span>
            </div>

            <div class="col-md-6">
                <label class="form-label small fw-bold text-dark">System Verification Approval Status</label>
                <select name="approval_status" class="form-select form-select-sm fw-bold text-secondary border-primary" required>
                    <option value="PENDING" <?php echo (($u['approval_status'] ?? 'PENDING') === 'PENDING') ? 'selected' : ''; ?>>⏳ PENDING (Awaiting Verification Processing)</option>
                    <option value="APPROVED" <?php echo (($u['approval_status'] ?? '') === 'APPROVED') ? 'selected' : ''; ?>>✅ APPROVED (Authorized for Cycle Allocation Drops)</option>
                    <option value="REJECTED" <?php echo (($u['approval_status'] ?? '') === 'REJECTED') ? 'selected' : ''; ?>>❌ REJECTED (Suspended from Allocation Pipelines)</option>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label small fw-bold text-secondary">Resumption Date</label>
                <input type="date" name="resumption_date" value="<?php echo htmlspecialchars($u['resumption_date'] ?? ''); ?>" class="form-control form-control-sm font-monospace">
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 border-bottom pb-1 mb-2">
                <span class="badge bg-success-subtle text-success fw-bold text-uppercase tracking-wider px-2.5 py-1.5" style="font-size: 0.75rem;">
                    <i class="bi bi-cash-coin me-1"></i> Financial Core & Placement Configurations
                </span>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Assigned Network Cluster Group</label>
                <select name="cluster_code" class="form-select form-select-sm fw-semibold" required>
                    <?php foreach ($clusters as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['cluster_code']); ?>" <?php echo ($c['cluster_code'] === $u['cluster_code']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['cluster_code'] . ' - ' . $c['cluster_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Expected Base Remittance (₦)</label>
                <input type="number" step="0.01" name="expected_remittance_amount" value="<?php echo htmlspecialchars($u['expected_remittance_amount'] ?? '0.00'); ?>" class="form-control form-control-sm font-monospace fw-bold text-dark" required>
            </div>

            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary">Host Corporate Entity Name</label>
                <input type="text" name="host_organization" value="<?php echo htmlspecialchars($u['host_organization'] ?? ''); ?>" class="form-control form-control-sm">
            </div>
        </div>

        <div class="border-top pt-3 text-end">
            <a href="users.php" class="btn btn-sm btn-outline-secondary fw-semibold px-4 me-1">Cancel Changes</a>
            <button type="submit" class="btn btn-sm btn-primary fw-bold px-4 shadow-sm">
                <i class="bi bi-cloud-arrow-up-fill me-1"></i> Apply System Changes
            </button>
        </div>
    </form>
</div>

<?php include_once '../partials/footer.php'; ?>