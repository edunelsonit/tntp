<?php
require_once '../config/config.php';

// Enforce strict cluster manager operational boundaries
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cluster_manager') {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();
$my_code = $_SESSION['cluster_code'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: bulk_import.php');
    exit;
}

$rows = [];
$errors = [];
$line_num = 0;

// ==========================================
// PHASE 1: CSV FILE PREVIEW & VALIDATION STAGE
// ==========================================
if (isset($_POST['preview'])) {
    // Validate initial CSRF safety state
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !verifyCsrfToken($csrf_token)) {
        die("Security validation signature failed or expired.");
    }

    if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        header('Location: bulk_import.php?err=upload_failed');
        exit;
    }

    $file = $_FILES['csv_file']['tmp_name'];

    if (($handle = fopen($file, 'r')) !== FALSE) {
        // Read raw header tokens and sanitize string array keys
        $headers = fgetcsv($handle, 1000, ",");
        if ($headers !== FALSE) {
            $headers = array_map(function($h) { return trim(strtolower($h)); }, $headers);
        }

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $line_num++;
            
            // Skip trailing empty spacing inputs cleanly
            if (count(array_filter($data)) === 0) continue;

            if (count($data) !== count($headers)) {
                $errors[] = "Line $line_num: Structural imbalance. Column count mismatch.";
                continue;
            }

            $row = array_combine($headers, $data);
            $row = array_map('trim', $row);

            // Dynamic Form Field Inversions & Basic Quality Validations
            if (empty($row['nin']) || !preg_match('/^[0-9]{11}$/', $row['nin'])) {
                $errors[] = "Line $line_num: Field [nin] is required and must span exactly 11 digits.";
                continue;
            }
            if (empty($row['full_name'])) {
                $errors[] = "Line $line_num: Field [full_name] is structural dependency requirement.";
                continue;
            }
            if (empty($row['phone'])) {
                $errors[] = "Line $line_num: Field [phone] reference tracking variable missing.";
                continue;
            }
            if (empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Line $line_num: Field [email] missing or contains broken syntax format.";
                continue;
            }
            
            $expected_amount = floatval($row['expected_amount'] ?? 0);
            if ($expected_amount <= 0) {
                $errors[] = "Line $line_num: Target [expected_amount] calculation must rate greater than 0.";
                continue;
            }

            if (!empty($row['due_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['due_date'])) {
                $errors[] = "Line $line_num: Field [due_date] must follow strict ISO standard format (YYYY-MM-DD).";
                continue;
            }

            // CLEANING AND INJECTING ALTERNATE SETTLEMENT COLUMNS INTO THE ROW MATRIX
            $salary_account = $row['salary_account_number'] ?? '';
            $row['salary_account_number'] = empty($salary_account) ? null : preg_replace('/[^0-9]/', '', $salary_account);
            $row['salary_bank_name'] = !empty($row['salary_bank_name']) ? trim($row['salary_bank_name']) : null;

            // Cross-verify record system duplicates inside current persistence engine context
            $chk = $db->prepare("SELECT id FROM users WHERE nin = ?");
            $chk->execute([$row['nin']]);
            if ($chk->fetch()) {
                $errors[] = "Line $line_num: Entity key match collision. NIN [{$row['nin']}] already exists.";
                continue;
            }

            $rows[] = $row;
        }
        fclose($handle);
    }

    include_once '../partials/header.php';
    ?>
    <div class="mb-4 border-bottom pb-2">
        <h1 class="h3 mb-1 text-dark fw-bold"><i class="bi bi-eye-fill text-primary me-2"></i>Dataset Processing Review Workspace</h1>
        <p class="text-muted small">Verify transactional items extracted from file metrics before scheduling database writes.</p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-warning shadow-sm border-start border-warning border-4 p-3 rounded-3 mb-4">
            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i> Structural Discrepancies Encountered (Skipped Rows)</h6>
            <ul class="mb-0 small font-monospace list-unstyled text-secondary ps-0" style="max-height: 200px; overflow-y: auto;">
                <?php foreach ($errors as $err): ?>
                    <li class="py-1 border-bottom border-light"><i class="bi bi-dash-circle text-danger me-1"></i><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($rows)): ?>
        <div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius: 14px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <p class="mb-0 text-secondary small">
                    Staged Capacity Status: <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-bold fs-6"><?php echo count($rows); ?> Verified Clean Record Lines</span>
                </p>
                <span class="text-muted small">Skipped Faulty Rows: <strong><?php echo count($errors); ?></strong></span>
            </div>

            <div class="table-responsive border rounded mb-3">
                <table class="table table-hover table-striped align-middle small mb-0">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th>NIN Identifier</th>
                            <th>Resolved Name Reference</th>
                            <th>Email Target</th>
                            <th>Payout Account</th>
                            <th class="text-end">Billing Standard</th>
                            <th>Start Point</th>
                            <th>Host Target Node</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><code class="text-dark fw-bold"><?php echo htmlspecialchars($r['nin']); ?></code></td>
                                <td class="fw-semibold text-dark"><?php echo htmlspecialchars($r['full_name']); ?></td>
                                <td><span class="text-muted"><?php echo htmlspecialchars($r['email']); ?></span></td>
                                <td>
                                    <?php if (!empty($r['salary_account_number'])): ?>
                                        <small class="text-dark font-monospace fw-medium d-block"><?php echo htmlspecialchars($r['salary_account_number']); ?></small>
                                        <span class="text-muted d-block" style="font-size: 0.725rem;"><?php echo htmlspecialchars($r['salary_bank_name'] ?? 'Generic Bank Link'); ?></span>
                                    <?php else: ?>
                                        <em class="text-muted small">— Unassigned Settlement</em>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold font-monospace text-dark">₦<?php echo number_format(floatval($r['expected_amount']), 2); ?></td>
                                <td class="font-monospace text-secondary"><?php echo htmlspecialchars($r['due_date'] ?? 'Immediate'); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($r['host_organization'] ?? '—'); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="d-flex justify-content-between align-items-center mt-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                <input type="hidden" name="confirm" value="1">
                <input type="hidden" name="csv_data" value="<?php echo htmlspecialchars(json_encode($rows)); ?>">
                
                <a href="bulk_import.php" class="btn btn-outline-secondary btn-sm fw-bold px-3">
                    <i class="bi bi-x-circle me-1"></i> Cancel & Clear Buffer
                </a>
                <button type="submit" class="btn btn-success btn-sm fw-bold px-4 py-2 shadow-sm">
                    <i class="bi bi-cloud-check-fill me-1"></i> Authorize & Write Records
                </button>
            </form>
        </div>
    <?php else: ?>
        <div class="alert alert-danger shadow-sm text-center py-4">
            <i class="bi bi-file-earmark-x fs-1 d-block mb-2 text-danger"></i>
            <h5 class="fw-bold">Array Parsing Failure</h5>
            <p class="small text-muted mb-3">No valid structured data lines could be processed out of the uploaded file document.</p>
            <a href="bulk_import.php" class="btn btn-secondary btn-sm px-3 fw-bold">Return to File Drop</a>
        </div>
    <?php endif; ?>
    <?php include_once '../partials/footer.php'; ?>

<?php
// ==========================================
// PHASE 2: PERSISTENCE WRITE BACK EXECUTION ENGINE STAGE
// ==========================================
} elseif (isset($_POST['confirm'])) {
    // Validate final structural CSRF safety token array state
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !verifyCsrfToken($csrf_token)) {
        die("Transaction lifecycle context confirmation signature expired.");
    }

    $csv_data = json_decode($_POST['csv_data'], true);
    if (!is_array($csv_data)) {
        header('Location: bulk_import.php?err=invalid_payload');
        exit;
    }

    $imported = 0;
    $failed = 0;

    // Secure operational tracking states via transaction contexts
    $db->beginTransaction();

    foreach ($csv_data as $row) {
        try {
            // Unpack full_name string context to fit standard layout parameters safely
            $name_parts = explode(' ', $row['full_name'], 2);
            $first_name = trim($name_parts[0] ?? 'Imported');
            $surname = trim($name_parts[1] ?? 'Member');

            $stmt = $db->prepare('
                INSERT INTO users (
                    nin, first_name, surname, phone, email, cluster_code, 
                    host_organization, host_organization_address, salary_account_number, salary_bank_name,
                    gender, dob, state_of_origin, lga, expected_remittance_amount, can_start_payment, 
                    payment_start_date, working_status, approval_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, "ACTIVE", "PENDING")
            ');

            $stmt->execute([
                $row['nin'],
                $first_name,
                $surname,
                $row['phone'],
                $row['email'],
                $my_code,
                $row['host_organization'] ?? null,
                $row['host_organization_address'] ?? null,
                $row['salary_account_number'] ?? null, // Injected bank element parameters
                $row['salary_bank_name'] ?? null,      // Injected bank element parameters
                $row['gender'] ?? null,
                !empty($row['dob']) ? $row['dob'] : null,
                $row['state'] ?? null,
                $row['lga'] ?? null,
                floatval($row['expected_amount']),
                !empty($row['due_date']) ? $row['due_date'] : null
            ]);
            
            $imported++;
        } catch (Exception $e) {
            $failed++;
        }
    }

    // Safely write tracking changes over system tables
    $db->commit();

    include_once '../partials/header.php';
    ?>
    <div class="card p-5 text-center border-0 shadow-sm bg-white" style="border-radius: 14px;">
        <div class="mb-4">
            <div class="bg-success-subtle text-success mx-auto d-flex align-items-center justify-content-center rounded-circle" style="width: 70px; height: 70px;">
                <i class="bi bi-cloud-check fs-1"></i>
            </div>
        </div>
        <h3 class="fw-bold text-dark">Data Import Pipeline Concluded</h3>
        <p class="text-muted small mx-auto" style="max-width: 500px;">
            Staged record blocks have been organized and deployed inside validation holding memory slots awaiting supervisory board verification clearance routines.
        </p>

        <div class="row g-2 justify-content-center my-3" style="max-width: 400px; margin: 0 auto;">
            <div class="col-6">
                <div class="p-3 bg-light border rounded-3">
                    <span class="text-muted text-uppercase d-block small fw-bold" style="font-size:0.7rem;">Writings Successful</span>
                    <strong class="h4 text-success font-monospace fw-bold"><?php echo $imported; ?></strong>
                </div>
            </div>
            <div class="col-6">
                <div class="p-3 bg-light border rounded-3">
                    <span class="text-muted text-uppercase d-block small fw-bold" style="font-size:0.7rem;">Exceptions Encountered</span>
                    <strong class="h4 text-danger font-monospace fw-bold"><?php echo $failed; ?></strong>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <a href="index.php" class="btn btn-primary btn-sm fw-bold px-4 py-2 shadow-sm">
                <i class="bi bi-house-door me-1"></i> Return to Terminal Dashboard
            </a>
        </div>
    </div>
    <?php include_once '../partials/footer.php'; ?>

<?php
} else {
    header('Location: bulk_import.php');
    exit;
}
?>