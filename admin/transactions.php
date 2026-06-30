<?php
require_once '../config/config.php';
checkRouteAccess('admin' OR 'super_admin');
$db = getDB();

$search    = trim($_GET['search'] ?? '');
$cluster   = trim($_GET['cluster'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    // ADAPTED: Uses CONCAT for user lookup and evaluates the correct remitance field references
    $where[] = "(t.remitance_reference LIKE ? OR t.tx_reference LIKE ? OR CONCAT(u.first_name, ' ', u.surname) LIKE ? OR u.nin LIKE ?)";
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
if ($cluster !== '') {
    $where[] = "u.cluster_code = ?";
    $params[] = $cluster;
}
if ($date_from !== '') {
    $where[] = "DATE(t.payment_date) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = "DATE(t.payment_date) <= ?";
    $params[] = $date_to;
}

// Query transactions table joined with users and clusters
$sql = "SELECT t.*, u.first_name, u.surname, u.cluster_code, u.expected_remittance_amount, u.amount_paid as user_total_paid
    FROM transactions t
    JOIN users u ON u.nin = t.nin";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY t.id DESC"; // Safe fallback order index

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// CSV Export Engine Pipeline Intercept
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (ob_get_length()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tntp-remittances-' . date('Y-m-d') . '.csv"');
    
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // Excel UTF-8 BOM compatibility protection
    
    fputcsv($out, ['Reference ID', 'User Identity', 'NIN Vector', 'Cluster Code', 'Remitted Amount (NGN)', 'Confirmation Status', 'Date Processing']);
    
    foreach ($transactions as $tx) {
        $fullName = trim($tx['first_name'] . ' ' . $tx['surname']);
        // ADAPTED: Dynamic fallback extraction parameters protecting against column variants
        $reference = $tx['remitance_reference'] ?? $tx['tx_reference'] ?? ('REF-' . $tx['id']);
        $amount = $tx['amount'] ?? $tx['amount_paid'] ?? 0.00;
        $status = $tx['confirm_status'] ?? $tx['status'] ?? 'SUCCESS';
        $date = $tx['payment_date'] ?? $tx['created_at'] ?? 'N/A';

        fputcsv($out, [$reference, $fullName, $tx['nin'], $tx['cluster_code'], $amount, $status, $date]);
    }
    fclose($out);
    exit;
}

$clusters = $db->query("SELECT cluster_code, cluster_name FROM clusters ORDER BY cluster_name")->fetchAll();

// ADAPTED: Safe calculation array evaluation
$total = array_sum(array_map(fn($tx) => (float)($tx['amount'] ?? $tx['amount_paid'] ?? 0.00), $transactions));

include_once '../partials/header.php';
?>

<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-4 pb-3 border-bottom gap-3">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold">Remittance Collections Ledger</h1>
        <p class="text-muted small mb-0">Audit, parse, and review real-time database inbound remittance files dynamically.</p>
    </div>
    <div>
        <a class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-medium shadow-sm" href="<?php echo APP_BASE_URL; ?>/admin/index.php">
            <i class="bi bi-arrow-left me-1"></i> Dashboard View
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm bg-white p-4 mb-4" style="border-radius: 12px运行;">
    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-funnel me-1 text-primary"></i> Filter Query Parameters</h6>
    <form class="row g-3" method="GET">
        <div class="col-md-3">
            <label class="form-label small text-muted fw-semibold">Search Criteria</label>
            <input class="form-control form-control-sm py-2" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Identity, NIN string, or Ref code...">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted fw-semibold">Cluster Domain</label>
            <select class="form-select form-select-sm py-2" name="cluster">
                <option value="">All system clusters assigned</option>
                <?php foreach ($clusters as $c): ?>
                    <option value="<?php echo htmlspecialchars($c['cluster_code']); ?>" <?php echo $cluster === $c['cluster_code'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['cluster_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted fw-semibold">Date From</label>
            <input class="form-control form-control-sm py-2" type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted fw-semibold">Date To</label>
            <input class="form-control form-control-sm py-2" type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end gap-2">
            <button class="btn btn-primary btn-sm w-100 py-2 fw-semibold" type="submit">
                <i class="bi bi-search me-1"></i> Apply
            </button>
            <a class="btn btn-outline-success btn-sm w-100 py-2 fw-semibold text-nowrap" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>">
                <i class="bi bi-filetype-csv me-1"></i> Export
            </a>
        </div>
    </form>
</div>

<div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
    <div class="col">
        <div class="card border-0 bg-white shadow-sm p-4 border-start border-primary border-4 rounded-end">
            <div class="small text-muted text-uppercase fw-bold tracking-wider" style="font-size:0.75rem;">Calculated Remittance Volume</div>
            <div class="h3 fw-bold text-dark mb-0 mt-1">₦<?php echo number_format($total, 2); ?></div>
        </div>
    </div>
    <div class="col">
        <div class="card border-0 bg-white shadow-sm p-4 border-start border-secondary border-4 rounded-end">
            <div class="small text-muted text-uppercase fw-bold tracking-wider" style="font-size:0.75rem;">Filtered Records Found</div>
            <div class="h3 fw-bold text-dark mb-0 mt-1"><?php echo count($transactions); ?> Rows</div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm bg-white p-4" style="border-radius:14px;">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light text-secondary fw-semibold">
                <tr>
                    <th class="py-3">Reference String</th>
                    <th class="py-3">User Profile Identity</th>
                    <th class="py-3 text-center">Assigned Cluster</th>
                    <th class="py-3">Remitted Amount</th>
                    <th class="py-3 text-center">Verification Status</th>
                    <th class="py-3">Settlement Date</th>
                    <th class="py-3 text-end pe-3">Action Operations</th>
                </tr>
            </thead>
            <tbody class="text-dark">
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-folder-x fs-3 text-black-50 d-block mb-2"></i>
                            No remittance tracking rows found matching your current filter definitions.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): 
                        // ADAPTED: Dynamic fallback properties mapping safeguards
                        $reference = $tx['remitance_reference'] ?? $tx['tx_reference'] ?? ('REF-' . $tx['id']);
                        $amount = $tx['amount'] ?? $tx['amount_paid'] ?? 0.00;
                        $status = strtoupper(trim((string)($tx['confirm_status'] ?? $tx['status'] ?? 'SUCCESS')));
                        $date = $tx['payment_date'] ?? $tx['created_at'] ?? 'N/A';
                    ?>
                        <tr>
                            <td>
                                <span class="font-monospace fw-bold text-dark bg-light px-2 py-1 rounded border small">
                                    <?php echo htmlspecialchars($reference); ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars(trim($tx['first_name'] . ' ' . $tx['surname'])); ?></span>
                                <small class="text-muted font-monospace d-block mt-0.5">NIN: <?php echo htmlspecialchars($tx['nin']); ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-secondary border px-2.5 py-1.5 fw-semibold font-monospace">
                                    <?php echo htmlspecialchars($tx['cluster_code']); ?>
                                </span>
                            </td>
                            <td class="fw-bold text-success">
                                ₦<?php echo number_format($amount, 2); ?>
                            </td>
                            <td class="text-center">
                                <?php 
                                if ($status === 'SUCCESS' || $status === 'APPROVED' || $status === 'CONFIRMED') {
                                    echo '<span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 rounded fw-bold small"><i class="bi bi-check-circle me-1"></i>Verified</span>';
                                } elseif ($status === 'PENDING') {
                                    echo '<span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2.5 py-1 rounded fw-bold small"><i class="bi bi-hourglass-split me-1"></i>Pending</span>';
                                } else {
                                    echo '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1 rounded fw-bold small"><i class="bi bi-x-circle me-1"></i>Rejected</span>';
                                }
                                ?>
                            </td>
                            <td class="text-muted fw-medium">
                                <i class="bi bi-calendar3 me-1 small"></i>
                                <?php echo htmlspecialchars($date); ?>
                            </td>
                            <td class="text-end pe-3">
                                <a class="btn btn-sm btn-outline-primary px-3 fw-semibold shadow-sm" target="_blank" href="<?php echo APP_BASE_URL; ?>/receipt.php?ref=<?php echo urlencode($reference); ?>">
                                    <i class="bi bi-printer me-1"></i> View Invoice
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>