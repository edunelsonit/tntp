<?php
require_once '../config/config.php';
checkRouteAccess('admin');
$db = getDB();

$type = $_GET['type'] ?? 'daily';
$date = $_GET['date'] ?? date('Y-m-d');
$month = $_GET['month'] ?? date('Y-m');
$cluster = $_GET['cluster'] ?? '';

// Fetch all available clusters for the filter dropdown
$clusters = $db->query("SELECT cluster_code, cluster_name FROM clusters ORDER BY cluster_name")->fetchAll();

// Determine date ranges based on chosen report granularity
if ($type === 'monthly') {
    $start = $month . '-01 00:00:00';
    $end = date('Y-m-t 23:59:59', strtotime($month . '-01'));
} elseif ($type === 'cluster' || $type === 'outstanding') {
    $start = '1970-01-01 00:00:00';
    $end = date('Y-m-d 23:59:59');
} else { // 'daily' reporting baseline context
    $start = $date . ' 00:00:00';
    $end = $date . ' 23:59:59';
}

// Build dynamic WHERE filtering engine for transaction history
$where = ["p.paid_at BETWEEN ? AND ?"];
$params = [$start, $end];
if ($cluster !== '') {
    $where[] = "u.cluster_code = ?";
    $params[] = $cluster;
}

// 1. REWRITTEN QUERY: Pull logs out of payment_history instead of non-existent transactions table
$tx_sql = "SELECT p.tx_reference, p.amount_paid as amount, p.paid_at, p.payment_method, 
                  u.first_name, u.surname, u.cluster_code 
           FROM payment_history p 
           INNER JOIN remittance r ON p.remittance_id = r.id
           INNER JOIN users u ON r.userid = u.id 
           WHERE " . implode(' AND ', $where) . " 
           ORDER BY p.paid_at DESC";
$stmt = $db->prepare($tx_sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// 2. QUERY METRICS: Pull snapshot summaries out of the remittance matrix 
$user_where = [];
$user_params = [];
if ($cluster !== '') {
    $user_where[] = "u.cluster_code = ?";
    $user_params[] = $cluster;
}

// Ground current operational visibility strictly around the latest active collection cycle
$latest_cycle = $db->query("SELECT id FROM remittance_cycles ORDER BY cycle_period DESC LIMIT 1")->fetch();
if ($latest_cycle) {
    $user_where[] = "r.cycle_id = ?";
    $user_params[] = $latest_cycle['id'];
}

$user_where_str = $user_where ? " WHERE " . implode(' AND ', $user_where) : "";

$user_sql = "SELECT u.nin, u.first_name, u.surname, u.cluster_code, 
                    COALESCE(r.expected_amount, 0.00) as expected_amount, 
                    COALESCE(r.amount_paid, 0.00) as amount_paid, 
                    r.payment_status 
             FROM users u
             LEFT JOIN remittance r ON u.id = r.userid
             $user_where_str";
$stmt = $db->prepare($user_sql);
$stmt->execute($user_params);
$users = $stmt->fetchAll();

// Dynamic computation arrays
$expected = array_sum(array_map(fn($u) => (float) $u['expected_amount'], $users));
$paid = array_sum(array_map(fn($u) => (float) $u['amount_paid'], $users));
$outstanding = $expected - $paid;
$completion = $expected > 0 ? ($paid / $expected) * 100 : 0;
$tx_total = array_sum(array_map(fn($tx) => (float) $tx['amount'], $transactions));

// Handle immediate execution of CSV file export downstreams
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="tntp-financial-report.csv"');
    $out = fopen('php://output', 'w');
    
    fputcsv($out, ['Report Engine Run Type', strtoupper($type)]);
    fputcsv($out, ['Total Expected Valuation', $expected]);
    fputcsv($out, ['Total Settled Inflows', $paid]);
    fputcsv($out, ['Total Outstanding Debt', $outstanding]);
    fputcsv($out, []);
    fputcsv($out, ['Transaction Reference', 'Target Member User', 'Cluster Code', 'Amount Remitted', 'Processing Timestamp']);
    
    foreach ($transactions as $tx) {
        fputcsv($out, [
            $tx['tx_reference'], 
            $tx['first_name'] . ' ' . $tx['surname'], 
            $tx['cluster_code'] ?? 'N/A', 
            $tx['amount'], 
            $tx['paid_at']
        ]);
    }
    fclose($out);
    exit;
}

include_once '../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold text-gray-800">📋 Financial Audits & Reports</h2>
    <a class="btn btn-sm btn-outline-secondary shadow-sm" href="<?php echo APP_BASE_URL; ?>/admin/analytics.php">Back to Dashboard</a>
</div>

<div class="card border-0 shadow-sm bg-white p-4 mb-4">
    <form class="row g-2 align-items-end" method="GET">
        <div class="col-md-2">
            <label class="form-label small fw-bold">Report Target</label>
            <select name="type" class="form-select form-select-sm">
                <?php foreach (['daily' => 'Daily Activity', 'monthly' => 'Monthly Cycle', 'cluster' => 'Cluster Base', 'outstanding' => 'Outstanding Arrears'] as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $type === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold">Daily Date Picker</label>
            <input type="date" name="date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold">Monthly Selector</label>
            <input type="month" name="month" class="form-control form-control-sm" value="<?php echo htmlspecialchars($month); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Assigned Cluster Group</label>
            <select name="cluster" class="form-select form-select-sm">
                <option value="">All clusters</option>
                <?php foreach ($clusters as $c): ?>
                    <option value="<?php echo htmlspecialchars($c['cluster_code']); ?>" <?php echo $cluster === $c['cluster_code'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['cluster_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary btn-sm w-100">Run Engine Report</button>
            <a class="btn btn-outline-success btn-sm" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>">CSV</a>
        </div>
    </form>
</div>

<div class="row mb-4">
    <div class="col-md-3 mb-3"><div class="card border-0 shadow-sm p-4 bg-light"><div class="small text-muted text-uppercase fw-bold mb-1">Expected Revenue</div><div class="h4 fw-bold">₦<?php echo number_format($expected, 2); ?></div></div></div>
    <div class="col-md-3 mb-3"><div class="card border-0 shadow-sm p-4 bg-light"><div class="small text-muted text-uppercase fw-bold mb-1">Settled Funds</div><div class="h4 fw-bold text-success">₦<?php echo number_format($paid, 2); ?></div></div></div>
    <div class="col-md-3 mb-3"><div class="card border-0 shadow-sm p-4 bg-light"><div class="small text-muted text-uppercase fw-bold mb-1">Outstanding Arrears</div><div class="h4 fw-bold text-danger">₦<?php echo number_format($outstanding, 2); ?></div></div></div>
    <div class="col-md-3 mb-3"><div class="card border-0 shadow-sm p-4 bg-light"><div class="small text-muted text-uppercase fw-bold mb-1">Collection Progress</div><div class="h4 fw-bold text-info"><?php echo number_format($completion, 1); ?>%</div></div></div>
</div>

<?php if ($type === 'outstanding'): ?>
<div class="card border-0 shadow-sm bg-white p-4">
    <h5 class="fw-bold mb-3 text-dark">⚠️ Active Arrears Defaulters Matrix <span class="text-muted small">(Current Cycle)</span></h5>
    <div class="table-responsive">
        <table class="table table-hover small align-middle">
            <thead class="table-light">
                <tr><th>Target Member Profile</th><th>Cluster Association</th><th>Assigned Expected</th><th>Total Paid</th><th>Outstanding Gap</th><th>Collection Status</th></tr>
            </thead>
            <tbody>
                <?php 
                $has_defaulters = false;
                foreach ($users as $u): 
                    $balance = (float) $u['expected_amount'] - (float) $u['amount_paid']; 
                    if ($balance <= 0) continue;
                    $has_defaulters = true;
                ?>
                    <tr>
                        <td>
                            <strong class="text-dark"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['surname']); ?></strong>
                            <small class="d-block text-muted">NIN: <?php echo htmlspecialchars($u['nin']); ?></small>
                        </td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($u['cluster_code'] ?? 'Unassigned'); ?></span></td>
                        <td>₦<?php echo number_format($u['expected_amount'], 2); ?></td>
                        <td>₦<?php echo number_format($u['amount_paid'], 2); ?></td>
                        <td class="text-danger fw-bold">₦<?php echo number_format($balance, 2); ?></td>
                        <td><span class="badge bg-danger"><?php echo htmlspecialchars($u['payment_status'] ?? 'UNPAID'); ?></span></td>
                    </tr>
                <?php endforeach; 
                if (!$has_defaulters): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No structural accounts are defaulting inside this tracking context.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm bg-white p-4">
    <h5 class="fw-bold mb-3 text-dark">📜 Verified Ledger Payment Inflows <span class="text-muted small">(Period Yield Total: ₦<?php echo number_format($tx_total, 2); ?>)</span></h5>
    <div class="table-responsive">
        <table class="table table-hover small align-middle">
            <thead class="table-light">
                <tr><th>Tx Reference String</th><th>Source User Member</th><th>Cluster Code</th><th>Settled Sum</th><th>Collection Channel</th><th>Posting Date</th></tr>
            </thead>
            <tbody>
                <?php if (!$transactions): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No verified transactional records found matching specified parameters.</td></tr>
                <?php endif; ?>
                <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($tx['tx_reference']); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($tx['first_name'] . ' ' . $tx['surname']); ?></strong></td>
                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($tx['cluster_code'] ?? 'N/A'); ?></span></td>
                        <td class="text-success fw-bold">₦<?php echo number_format($tx['amount'], 2); ?></td>
                        <td><small class="fw-bold text-uppercase"><?php echo htmlspecialchars($tx['payment_method']); ?></small></td>
                        <td><span class="text-muted"><?php echo htmlspecialchars($tx['paid_at']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include_once '../partials/footer.php'; ?>