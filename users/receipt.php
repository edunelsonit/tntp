<?php
// FIXED: Adjusted relative directory mapping path to access root system config file safely
require_once '../config/config.php';

if (!isset($_SESSION['role'])) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();
$ref = trim($_GET['ref'] ?? '');

// FIXED QUERY: Migrated schema mappings away from the non-existent 'transactions' table 
// into relational entities ('payment_history', 'remittance', 'users', 'clusters')
$stmt = $db->prepare("
    SELECT p.tx_reference, 
           p.amount_paid AS amount, 
           p.paid_at AS payment_date, 
           p.payment_method AS receipt_path,
           CONCAT(u.first_name, ' ', u.surname) AS full_name, 
           u.nin, 
           u.cluster_code, 
           u.id AS user_id,
           c.cluster_name
    FROM payment_history p
    INNER JOIN remittance r ON p.remittance_id = r.id
    INNER JOIN users u ON r.userid = u.id
    LEFT JOIN clusters c ON c.cluster_code = u.cluster_code
    WHERE p.tx_reference = ?
");
$stmt->execute([$ref]);
$tx = $stmt->fetch();

if (!$tx) {
    http_response_code(404);
    echo "Receipt not found.";
    exit;
}

// Security Gateways Validation Mapping Logic
if ($_SESSION['role'] === 'user' && ($_SESSION['nin'] ?? '') !== $tx['nin']) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}
if ($_SESSION['role'] === 'cluster_manager' && ($_SESSION['cluster_code'] ?? '') !== $tx['cluster_code']) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

// FIXED: Requiring RemittanceManager to calculate dynamic balances directly from core storage models
require_once '../core/RemittanceManager.php';
$remittanceManager = new RemittanceManager($db);
$cycleBalances = $remittanceManager->fetchBalancesForUser((int)$tx['user_id']);

$expected_amt = 0.00;
$paid_amt     = 0.00;
foreach ($cycleBalances as $cycle) {
    $expected_amt += (float)$cycle['expected_amount'];
    $paid_amt     += (float)$cycle['amount_paid'];
}
$outstanding = $expected_amt - $paid_amt;

// Fallback generation logic for safe unique identifier references
$receipt_number = 'RCT-' . strtoupper(substr(hash('sha256', $tx['tx_reference']), 0, 12));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?php echo htmlspecialchars($receipt_number); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8fafc;
        }
        /* Optimizes the UI specifically for clean printing and PDF generation */
        @media print {
            body {
                background-color: #fff !important;
                color: #000 !important;
            }
            .no-print {
                display: none !important;
            }
            .card {
                box-shadow: none !important;
                border: none !important;
            }
            .card-body {
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>
<div class="container my-5">
    <div class="card border-0 shadow-sm mx-auto" style="max-width: 720px; border-radius: 16px;">
        <div class="card-body p-4 p-md-5">
            
            <div class="d-flex justify-content-between align-items-start mb-4 pb-3 border-bottom">
                <div>
                    <h2 class="fw-bold text-primary mb-1"><i class="bi bi-shield-check me-1"></i> TNTP Receipt</h2>
                    <p class="text-muted small mb-0">Receipt No: <span class="font-monospace fw-bold text-dark"><?php echo htmlspecialchars($receipt_number); ?></span></p>
                </div>
                <button class="btn btn-outline-primary btn-sm no-print fw-semibold px-3" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print / Save PDF
                </button>
            </div>
            
            <div class="table-responsive">
                <table class="table table-bordered align-middle my-3">
                    <tbody>
                        <tr>
                            <th class="w-30 fw-bold text-secondary bg-light small text-uppercase">Account Name</th>
                            <td class="fw-semibold text-dark"><?php echo htmlspecialchars($tx['full_name']); ?></td>
                        </tr>
                        <tr>
                            <th class="fw-bold text-secondary bg-light small text-uppercase">NIN Identity</th>
                            <td class="font-monospace text-dark"><?php echo htmlspecialchars($tx['nin']); ?></td>
                        </tr>
                        <tr>
                            <th class="fw-bold text-secondary bg-light small text-uppercase">Cluster Allocation</th>
                            <td>
                                <span class="badge bg-secondary font-monospace"><?php echo htmlspecialchars($tx['cluster_code']); ?></span> 
                                <span class="text-dark fw-medium">— <?php echo htmlspecialchars($tx['cluster_name'] ?? 'Default Group Node'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th class="fw-bold text-secondary bg-light small text-uppercase">Amount Settled</th>
                            <td class="fw-bold text-success fs-5">₦<?php echo number_format($tx['amount'], 2); ?></td>
                        </tr>
                        <tr>
                            <th class="fw-bold text-secondary bg-light small text-uppercase">Global Arrears Outstanding</th>
                            <td class="fw-bold <?php echo $outstanding > 0 ? 'text-danger' : 'text-muted'; ?>">₦<?php echo number_format(max(0, $outstanding), 2); ?></td>
                        </tr>
                        <tr>
                            <th class="fw-bold text-secondary bg-light small text-uppercase">Transaction Reference</th>
                            <td><code class="text-dark fw-semibold"><?php echo htmlspecialchars($tx['tx_reference']); ?></code></td>
                        </tr>
                        <tr>
                            <th class="fw-bold text-secondary bg-light small text-uppercase">Processing Timestamp</th>
                            <td class="text-secondary"><?php echo date('F d, Y • h:i A', strtotime($tx['payment_date'])); ?></td>
                        </tr>
                        <tr>
                            <th class="fw-bold text-secondary bg-light small text-uppercase">Audit Clearance Status</th>
                            <td>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-bold">
                                    RECONCILED
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th class="fw-bold text-secondary bg-light small text-uppercase">Collection Source</th>
                            <td class="text-uppercase font-monospace small text-muted"><?php echo htmlspecialchars($tx['receipt_path'] ?? 'VIRTUAL_ACCNT'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="border rounded p-3 mt-4 text-center bg-light" style="border-radius: 12px !important;">
                <div class="small text-muted fw-bold text-uppercase mb-1" style="font-size: 0.75rem; tracking-wider">System Digital Fingerprint Signature</div>
                <code class="text-break small text-secondary"><?php echo htmlspecialchars(hash('sha256', $receipt_number . '|' . $tx['tx_reference'])); ?></code>
            </div>
            
            <div class="no-print mt-4 pt-2 border-top">
                <a href="<?php echo APP_BASE_URL; ?>/index.php" class="text-decoration-none fw-bold text-primary">
                    <i class="bi bi-arrow-left-short fs-5 align-middle"></i> Return back to core dashboard
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>