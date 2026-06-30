<?php
// FIXED: Adjusted path routes to match structural layout configuration limits
require_once '../config/config.php';

// Enforce strict operational boundary tracking checks for cluster managers
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cluster_manager') {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();
$my_code = $_SESSION['cluster_code'] ?? '';

// Fetch the current cluster entity profile data properties
$stmt_c = $db->prepare("SELECT * FROM clusters WHERE cluster_code = ?");
$stmt_c->execute([$my_code]);
$cluster = $stmt_c->fetch();

if (!$cluster) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

include_once '../partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold"><i class="bi bi-file-earmark-spreadsheet-fill text-success me-2"></i>Bulk User Import Engine</h1>
        <p class="text-muted small mb-0">
            Batch-upload ledger target records into mapping pool partition: 
            <span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold ms-1"><?php echo htmlspecialchars($cluster['cluster_name'] . " [" . $my_code . "]"); ?></span>
        </p>
    </div>
    <a href="<?php echo APP_BASE_URL; ?>/cluster/index.php" class="btn btn-sm btn-outline-secondary fw-semibold">
        <i class="bi bi-arrow-left me-1"></i> Back to Cluster Dash
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius: 14px;">
            <div class="mb-3">
                <h5 class="fw-bold text-dark mb-1"><i class="bi bi-table text-secondary me-1"></i> CSV Column Data Schema Map</h5>
                <p class="small text-muted mb-0">Source CSV document matrices must exactly match the header schema names mapped below:</p>
            </div>

            <div class="table-responsive mb-3 border rounded">
                <table class="table table-striped table-hover align-middle small mb-0 font-monospace">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th>nin</th>
                            <th>full_name</th>
                            <th>phone</th>
                            <th>email</th>
                            <th>expected_amount</th>
                            <th>due_date</th>
                            <th>salary_account_number</th>
                            <th>salary_bank_name</th>
                            <th>gender</th>
                            <th>dob</th>
                            <th>state</th>
                            <th>lga</th>
                            <th>host_organization</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="text-muted text-nowrap">
                            <td><code>12345678901</code></td>
                            <td>John Doe</td>
                            <td>08012345678</td>
                            <td>john@domain.ng</td>
                            <td>100000.00</td>
                            <td>2026-07-03</td>
                            <td><code>0123456789</code></td>
                            <td>Zenith Bank</td>
                            <td>Male</td>
                            <td>1990-01-15</td>
                            <td>Lagos</td>
                            <td>Ikoyi</td>
                            <td>ABC Enterprise</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="text-start">
                <a href="<?php echo APP_BASE_URL; ?>/cluster/template.csv" class="btn btn-sm btn-outline-primary fw-bold" download>
                    <i class="bi bi-download me-1"></i> Download Clean CSV Structural Template
                </a>
            </div>
        </div>

        <div class="card p-4 border-0 shadow-sm bg-white" style="border-radius: 14px;">
            <div class="mb-3">
                <h5 class="fw-bold text-dark mb-1"><i class="bi bi-cloud-arrow-up text-primary me-1"></i> Stream Binary CSV Stream</h5>
                <p class="small text-muted mb-0">Push localized dataset array parameters to pipeline evaluation memory slots.</p>
            </div>

            <form method="POST" enctype="multipart/form-data" action="bulk_import_handler.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                
                <div class="mb-4">
                    <label class="form-label small fw-bold text-secondary mb-1">Select File System Target Asset (Max 2MB)</label>
                    <div class="input-group">
                        <input type="file" name="csv_file" accept=".csv" class="form-control form-control-sm" required>
                        <label class="input-group-text bg-light small text-muted font-monospace"><i class="bi bi-filetype-csv me-1"></i>.csv format only</label>
                    </div>
                    <div class="form-text small text-muted" style="font-size:0.75rem;">System drops duplicate entries gracefully during verification parse steps.</div>
                </div>
                
                <div class="d-flex align-items-center gap-2">
                    <button type="submit" name="preview" class="btn btn-primary btn-sm fw-bold px-3">
                        <i class="bi bi-eye-fill me-1"></i> Stage & Preview Dataset
                    </button>
                    <span class="text-muted small font-italic" style="font-size:0.75rem;"><i class="bi bi-info-circle me-1"></i>You will verify array items before system writes occur.</span>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-4 border-0 shadow-sm bg-white h-100" style="border-radius: 14px; border-left: 4px solid #3b82f6 !important;">
            <h6 class="fw-bold text-dark mb-3 d-flex align-items-center">
                <i class="bi bi-clipboard-check-fill text-primary me-2 fs-5"></i> System Compliance Checklist
            </h6>
            
            <ul class="small text-secondary list-unstyled ps-0">
                <li class="d-flex align-items-start mb-3">
                    <i class="bi bi-patch-check-fill text-success me-2 mt-1"></i>
                    <div><strong>NIN Uniqueness:</strong> 11-digit character string keys are checked against global indexes. Duplicates skip automatically.</div>
                </li>
                <li class="d-flex align-items-start mb-3">
                    <i class="bi bi-patch-check-fill text-success me-2 mt-1"></i>
                    <div><strong>Disbursement Routing:</strong> 10-digit NUBAN codes provided via <code>salary_account_number</code> will be sanitized into core ledger storage tables automatically.</div>
                </li>
                <li class="d-flex align-items-start mb-3">
                    <i class="bi bi-patch-check-fill text-success me-2 mt-1"></i>
                    <div><strong>Email Integrity:</strong> Values pass validation syntax checks before account lines deploy.</div>
                </li>
                <li class="d-flex align-items-start mb-3">
                    <i class="bi bi-patch-check-fill text-success me-2 mt-1"></i>
                    <div><strong>Phone Syntax:</strong> Strings must adopt uniform international or regional layout contexts (`+234...` or `08...`).</div>
                </li>
                <li class="d-flex align-items-start mb-3">
                    <i class="bi bi-patch-check-fill text-success me-2 mt-1"></i>
                    <div><strong>ISO Date Formats:</strong> Ensure calendar fields employ the strict database format index pattern: `YYYY-MM-DD`.</div>
                </li>
                <li class="d-flex align-items-start mb-3">
                    <i class="bi bi-patch-check-fill text-success me-2 mt-1"></i>
                    <div><strong>Authorization Hooks:</strong> Batched users require administrative board approval before core platform access occurs.</div>
                </li>
            </ul>
            
            <div class="mt-auto p-2 bg-light border rounded small text-muted text-center font-monospace" style="font-size: 0.75rem;">
                <i class="bi bi-shield-lock-fill me-1"></i> Secure Data Stream Pipeline active.
            </div>
        </div>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>