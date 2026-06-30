<?php
require_once '../config/config.php';
checkRouteAccess('admin');
$db = getDB();

$cluster = $_GET['cluster'] ?? '';
$payment_status = $_GET['payment_status'] ?? '';
$approval_status = $_GET['approval_status'] ?? '';

// Fetch latest active cycle to ground analytics context if empty
$latest_cycle_stmt = $db->query("SELECT id, cycle_period FROM remittance_cycles ORDER BY cycle_period DESC LIMIT 1");
$latest_cycle = $latest_cycle_stmt->fetch();
$current_cycle_id = $latest_cycle['id'] ?? 0;

// Build filter conditions safely
$where = [];
$params = [];

if (!empty($cluster)) {
    $where[] = "u.cluster_code = ?";
    $params[] = $cluster;
}
if (!empty($payment_status)) {
    // Coerced safely against remittance table status structural types
    $where[] = "r.payment_status = ?";
    $params[] = $payment_status;
}
if (!empty($approval_status)) {
    $where[] = "u.approval_status = ?";
    $params[] = $approval_status;
}

// Bind to latest active collection cycle if tracking financial history
if ($current_cycle_id > 0) {
    $where[] = "r.cycle_id = ?";
    $params[] = $current_cycle_id;
}

$whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);

// Handle export before any HTML headers output
if (isset($_GET['export'])) {
    // Clear out any previous buffering structures to guarantee file integrity
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tntp_report_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['NIN', 'First Name', 'Surname', 'Cluster', 'Expected Amount', 'Amount Paid', 'Outstanding', 'Payment Status', 'Approval Status']);
    
    $export_sql = "SELECT u.nin, u.first_name, u.surname, c.cluster_name, 
                          COALESCE(r.expected_amount, 0.00) as expected_amount, 
                          COALESCE(r.amount_paid, 0.00) as amount_paid, 
                          (COALESCE(r.expected_amount, 0.00) - COALESCE(r.amount_paid, 0.00)) as outstanding, 
                          COALESCE(r.payment_status, 'UNPAID') as payment_status, u.approval_status 
                   FROM users u 
                   LEFT JOIN remittance r ON u.id = r.userid
                   LEFT JOIN clusters c ON u.cluster_code = c.cluster_code 
                   $whereClause 
                   ORDER BY u.first_name, u.surname";
                   
    $export_stmt = $db->prepare($export_sql);
    $export_stmt->execute($params);
    
    while ($row = $export_stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['nin'], 
            $row['first_name'], 
            $row['surname'], 
            $row['cluster_name'] ?? 'N/A', 
            number_format($row['expected_amount'], 2, '.', ''),
            number_format($row['amount_paid'], 2, '.', ''),
            number_format($row['outstanding'], 2, '.', ''),
            $row['payment_status'], 
            $row['approval_status']
        ]);
    }
    fclose($output);
    exit;
}

// 1. Overall Metrics (Using structural Left Joins to include defaulting accounts cleanly)
$metrics_sql = "SELECT COUNT(DISTINCT u.id) as total_users, 
                       SUM(COALESCE(r.expected_amount, 0.00)) as total_expected, 
                       SUM(COALESCE(r.amount_paid, 0.00)) as total_paid, 
                       COUNT(CASE WHEN r.payment_status='FULLY_PAID' THEN 1 END) as paid_users 
                FROM users u
                LEFT JOIN remittance r ON u.id = r.userid
                $whereClause";
$metrics = $db->prepare($metrics_sql);
$metrics->execute($params);
$m = $metrics->fetch();

// 2. Payment Status Breakdown
$status_sql = "SELECT COALESCE(r.payment_status, 'UNPAID') as payment_status, COUNT(*) as cnt, 
                      SUM(COALESCE(r.expected_amount, 0.00)) as expected, SUM(COALESCE(r.amount_paid, 0.00)) as paid 
               FROM users u
               LEFT JOIN remittance r ON u.id = r.userid
               $whereClause 
               GROUP BY COALESCE(r.payment_status, 'UNPAID')";
$status_stmt = $db->prepare($status_sql);
$status_stmt->execute($params);
$status_data = $status_stmt->fetchAll();

// 3. Approval Status Breakdown
$approval_sql = "SELECT u.approval_status, COUNT(DISTINCT u.id) as cnt 
                 FROM users u
                 LEFT JOIN remittance r ON u.id = r.userid
                 $whereClause 
                 GROUP BY u.approval_status";
$approval_stmt = $db->prepare($approval_sql);
$approval_stmt->execute($params);
$approval_data = $approval_stmt->fetchAll();

// 4. Top Defaulters
$defaulters_sql = "SELECT u.id, u.nin, u.first_name, u.surname, u.cluster_code, 
                          COALESCE(r.expected_amount, 0.00) as expected_amount, 
                          COALESCE(r.amount_paid, 0.00) as amount_paid, c.cluster_name 
                   FROM users u 
                   LEFT JOIN remittance r ON u.id = r.userid
                   LEFT JOIN clusters c ON u.cluster_code = c.cluster_code 
                   $whereClause AND (COALESCE(r.expected_amount, 0.00) - COALESCE(r.amount_paid, 0.00)) > 0 
                   ORDER BY (COALESCE(r.expected_amount, 0.00) - COALESCE(r.amount_paid, 0.00)) DESC LIMIT 15";
$defaulters_stmt = $db->prepare($defaulters_sql);
$defaulters_stmt->execute($params);
$defaulters = $defaulters_stmt->fetchAll();

// 5. Cluster Performance (Corrected join chain sequence layout tracking matrix)
$cluster_perf_sql = "SELECT c.cluster_code, c.cluster_name, COUNT(DISTINCT u.id) as users, 
                            SUM(COALESCE(r.expected_amount, 0.00)) as total_expected, 
                            SUM(COALESCE(r.amount_paid, 0.00)) as total_paid 
                     FROM clusters c 
                     LEFT JOIN users u ON u.cluster_code = c.cluster_code 
                     LEFT JOIN remittance r ON u.id = r.userid AND r.cycle_id = ?
                     GROUP BY c.cluster_code, c.cluster_name 
                     ORDER BY total_paid DESC";
$cluster_perf_stmt = $db->prepare($cluster_perf_sql);
$cluster_perf_stmt->execute([$current_cycle_id]);
$cluster_perf = $cluster_perf_stmt->fetchAll();

// Fixed Lookup ordered validation array
$clusters = $db->query("SELECT cluster_code, manager_name FROM clusters ORDER BY manager_name, cluster_code")->fetchAll();

include_once '../partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-2 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold">📊 Analytics & Reporting Dashboard</h1>
        <p class="text-muted small mb-0">System-wide collection metrics for Cycle: <strong class="text-primary"><?php echo htmlspecialchars($latest_cycle['cycle_period'] ?? 'No Active Cycle'); ?></strong></p>
    </div>
</div>

<div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius:12px;">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary">Cluster Nodes</label>
            <select name="cluster" class="form-select form-select-sm fw-medium">
                <option value="">All Clusters</option>
                <?php foreach ($clusters as $c): ?>
                    <?php $displayLabel = trim((string)($c['manager_name'] ?? '')) . ' - ' . htmlspecialchars($c['cluster_code']); ?>
                    <option value="<?php echo htmlspecialchars($c['cluster_code']); ?>" <?php echo $cluster === $c['cluster_code'] ? 'selected' : ''; ?>>
                        <?php echo $displayLabel; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary">Remittance Status</label>
            <select name="payment_status" class="form-select form-select-sm fw-medium">
                <option value="">All Statuses</option>
                <option value="FULLY_PAID" <?php echo $payment_status === 'FULLY_PAID' ? 'selected' : ''; ?>>Fully Paid</option>
                <option value="PARTIAL" <?php echo $payment_status === 'PARTIAL' ? 'selected' : ''; ?>>Partial</option>
                <option value="UNPAID" <?php echo $payment_status === 'UNPAID' ? 'selected' : ''; ?>>Unpaid</option>
                <option value="EXEMPTED" <?php echo $payment_status === 'EXEMPTED' ? 'selected' : ''; ?>>Exempted</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary">Verification Lifecycle</label>
            <select name="approval_status" class="form-select form-select-sm fw-medium">
                <option value="">All Alignments</option>
                <option value="APPROVED" <?php echo $approval_status === 'APPROVED' ? 'selected' : ''; ?>>Approved</option>
                <option value="PENDING" <?php echo $approval_status === 'PENDING' ? 'selected' : ''; ?>>Pending</option>
                <option value="REJECTED" <?php echo $approval_status === 'REJECTED' ? 'selected' : ''; ?>>Rejected</option>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold py-2 shadow-sm">
                <i class="bi bi-funnel me-1"></i> Apply Data Filters
            </button>
        </div>
    </form>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100 bg-primary text-white shadow-sm p-4 border-0 rounded-3">
            <div class="text-uppercase mb-1 small fw-bold text-white-50 tracking-wider">Target Active Users</div>
            <div class="h2 mb-0 fw-bold"><?php echo number_format($m['total_users'] ?? 0); ?></div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100 bg-success text-white shadow-sm p-4 border-0 rounded-3">
            <div class="text-uppercase mb-1 small fw-bold text-white-50 tracking-wider">Total Revenue Reconciled</div>
            <div class="h2 mb-0 fw-bold">₦<?php echo number_format(floatval($m['total_paid'] ?? 0), 2); ?></div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100 bg-info text-white shadow-sm p-4 border-0 rounded-3" style="background-color: #0dcaf0 !important;">
            <div class="text-uppercase mb-1 small fw-bold text-white-50 tracking-wider">Target Projections</div>
            <div class="h2 mb-0 fw-bold">₦<?php echo number_format(floatval($m['total_expected'] ?? 0), 2); ?></div>
        </div>
    </div>
    <div class="col">
        <div class="card h-100 bg-warning text-white shadow-sm p-4 border-0 rounded-3">
            <div class="text-uppercase mb-1 small fw-bold text-white-50 tracking-wider">Collection Velocity Ratio</div>
            <div class="h2 mb-0 fw-bold"><?php echo ($m['total_expected'] ?? 0) > 0 ? round((floatval($m['total_paid'] ?? 0) / floatval($m['total_expected'])) * 100) : 0; ?>%</div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card p-4 border-0 shadow-sm bg-white h-100" style="border-radius:14px;">
            <h5 class="fw-bold text-dark border-bottom pb-3 mb-3">Payment Ledger Summary Breakdown</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light fw-semibold text-secondary">
                        <tr>
                            <th class="py-2.5">Status Flag</th>
                            <th class="py-2.5">Record Count</th>
                            <th class="py-2.5">Projections</th>
                            <th class="py-2.5">Collected Ledger</th>
                        </tr>
                    </thead>
                    <tbody class="text-dark fw-medium">
                        <?php if (empty($status_data)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No matching payment matrices logs found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($status_data as $s): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        $p_status = strtoupper(trim((string)$s['payment_status']));
                                        if ($p_status === 'FULLY_PAID') echo '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">FULLY PAID</span>';
                                        elseif ($p_status === 'PARTIAL') echo '<span class="badge bg-warning-subtle text-warning-dark border border-warning-subtle px-2 py-1">PARTIAL</span>';
                                        elseif ($p_status === 'EXEMPTED') echo '<span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1">EXEMPTED</span>';
                                        else echo '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">UNPAID</span>';
                                        ?>
                                    </td>
                                    <td><?php echo $s['cnt']; ?> Accounts</td>
                                    <td>₦<?php echo number_format(floatval($s['expected']), 2); ?></td>
                                    <td class="text-success">₦<?php echo number_format(floatval($s['paid'] ?? 0), 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-4 border-0 shadow-sm bg-white h-100" style="border-radius:14px;">
            <h5 class="fw-bold text-dark border-bottom pb-3 mb-3">Approval Demographics & Verification Progress</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light fw-semibold text-secondary">
                        <tr>
                            <th class="py-2.5">Status Link</th>
                            <th class="py-2.5">Volume Size</th>
                            <th class="py-2.5">Distribution Ratio</th>
                        </tr>
                    </thead>
                    <tbody class="text-dark fw-medium">
                        <?php if (empty($approval_data)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No validation metrics logged inside scope.</td></tr>
                        <?php else: ?>
                            <?php foreach ($approval_data as $a): 
                                $total_base = $m['total_users'] ?? 0;
                                $pct = $total_base > 0 ? round(($a['cnt'] / $total_base) * 100) : 0;
                            ?>
                                <tr>
                                    <td>
                                        <?php 
                                        $a_status = strtoupper(trim((string)$a['approval_status']));
                                        if ($a_status === 'APPROVED') echo '<span class="badge bg-success-subtle text-success px-2 py-1"><i class="bi bi-check-circle me-1"></i>APPROVED</span>';
                                        elseif ($a_status === 'PENDING') echo '<span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="bi bi-hourglass-split me-1"></i>PENDING</span>';
                                        else echo '<span class="badge bg-danger-subtle text-danger px-2 py-1"><i class="bi bi-x-circle me-1"></i>REJECTED</span>';
                                        ?>
                                    </td>
                                    <td><?php echo $a['cnt']; ?> Active Records</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1 shadow-inner" style="height: 12px; border-radius: 4px;">
                                                <div class="progress-bar bg-primary rounded-pill" style="width: <?php echo $pct; ?>%"></div>
                                            </div>
                                            <span class="small fw-bold text-secondary font-monospace"><?php echo $pct; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius:14px;">
    <h5 class="fw-bold text-dark border-bottom pb-3 mb-3">Cluster Performance Analysis Matrix (Current Cycle)</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light fw-semibold text-secondary">
                <tr>
                    <th class="py-3">Cluster Grouping Vector</th>
                    <th class="py-3">Users Matrix</th>
                    <th class="py-3">Expected Valuation</th>
                    <th class="py-3">Collected Yield</th>
                    <th class="py-3">Outstanding Delta</th>
                    <th class="py-3">Collection Progress Ratio</th>
                </tr>
            </thead>
            <tbody class="text-dark fw-medium">
                <?php foreach ($cluster_perf as $cp): 
                    $outstanding = floatval($cp['total_expected'] ?? 0) - floatval($cp['total_paid'] ?? 0);
                    $collection_pct = floatval($cp['total_expected'] ?? 0) > 0 ? round((floatval($cp['total_paid'] ?? 0) / floatval($cp['total_expected'])) * 100) : 0;
                ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($cp['cluster_name'] ?? 'Unassigned'); ?></strong></td>
                        <td class="text-secondary font-monospace"><?php echo $cp['users']; ?> accounts</td>
                        <td>₦<?php echo number_format(floatval($cp['total_expected'] ?? 0), 2); ?></td>
                        <td class="text-success fw-bold">₦<?php echo number_format(floatval($cp['total_paid'] ?? 0), 2); ?></td>
                        <td class="text-danger fw-bold">₦<?php echo number_format($outstanding, 2); ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1 shadow-inner" style="height: 16px; border-radius: 6px;">
                                    <div class="progress-bar bg-<?php echo $collection_pct >= 75 ? 'success' : ($collection_pct >= 50 ? 'warning' : 'danger'); ?>" style="width: <?php echo $collection_pct; ?>%"></div>
                                </div>
                                <span class="small font-monospace fw-bold"><?php echo $collection_pct; ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius:14px;">
    <h5 class="fw-bold text-dark border-bottom pb-3 mb-3">⚠️ Top Outstanding Account Liabilities (Current Cycle)</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light fw-semibold text-secondary">
                <tr>
                    <th class="py-3">Identity Indexes</th>
                    <th class="py-3">User Profile Context</th>
                    <th class="py-3">Assigned Region Cluster</th>
                    <th class="py-3">Target Ledger</th>
                    <th class="py-3">Settled Portion</th>
                    <th class="py-3">Outstanding Debt Balance</th>
                </tr>
            </thead>
            <tbody class="text-dark">
                <?php if (empty($defaulters)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No outstanding system debt structures verified under scope.</td></tr>
                <?php else: ?>
                    <?php foreach ($defaulters as $d): 
                        $outstanding = floatval($d['expected_amount']) - floatval($d['amount_paid']);
                    ?>
                        <tr class="fw-medium">
                            <td class="font-monospace text-muted"><code><?php echo htmlspecialchars($d['nin']); ?></code></td>
                            <td><span class="fw-bold text-dark"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['surname']); ?></span></td>
                            <td><span class="badge bg-light text-dark border px-2 py-1"><i class="bi bi-tag me-1"></i><?php echo htmlspecialchars($d['cluster_name'] ?? $d['cluster_code']); ?></span></td>
                            <td>₦<?php echo number_format(floatval($d['expected_amount']), 2); ?></td>
                            <td class="text-success">₦<?php echo number_format(floatval($d['amount_paid']), 2); ?></td>
                            <td class="text-danger fw-bold">₦<?php echo number_format($outstanding, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card p-4 border-0 shadow-sm bg-white" style="border-radius:12px;">
    <h6 class="fw-bold text-dark mb-2"><i class="bi bi-cloud-arrow-down me-1 text-primary"></i> Export Consolidated Filtered Datasets</h6>
    <p class="text-muted small mb-3">Pulls downstream transactional records passing through filters into structured standard spreadsheet representations.</p>
    <form method="GET">
        <input type="hidden" name="cluster" value="<?php echo htmlspecialchars($cluster); ?>">
        <input type="hidden" name="payment_status" value="<?php echo htmlspecialchars($payment_status); ?>">
        <input type="hidden" name="approval_status" value="<?php echo htmlspecialchars($approval_status); ?>">
        <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-primary fw-bold px-4 rounded-pill shadow-sm">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Download Filtered View Spreadsheet (.CSV)
        </button>
    </form>
</div>

<?php include_once '../partials/footer.php'; ?>