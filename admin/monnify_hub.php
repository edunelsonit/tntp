<?php
require_once '../config/config.php';
checkRouteAccess('admin');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monnify Hub - The New Tomorrow's Project</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include_once '../partials/header.php'; ?>

<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1 text-gray-800 fw-bold">Monnify Integration Hub</h1>
        <p class="text-muted small">Manage virtual accounts, payment processing, and testing</p>
    </div>

    <div class="row g-3">
        <!-- Account Management -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">🏦 Account Management</h6>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">Generate and manage virtual accounts for users</p>
                    <div class="d-flex flex-column gap-2">
                        <a href="monnify_accounts.php" class="btn btn-sm btn-outline-primary">
                            Real Monnify Accounts
                        </a>
                        <a href="demo_accounts.php" class="btn btn-sm btn-outline-secondary">
                            Demo Accounts (Testing)
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Testing & Debugging -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">🧪 Testing & Debugging</h6>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">Test API connections and payment workflows</p>
                    <div class="d-flex flex-column gap-2">
                        <a href="test_monnify_api.php" class="btn btn-sm btn-outline-info" target="_blank">
                            Test API Connection
                        </a>
                        <a href="webhook_test.php" class="btn btn-sm btn-outline-info">
                            Simulate Payments
                        </a>
                        <a href="discover_monnify_config.php" class="btn btn-sm btn-outline-info" target="_blank">
                            Find Contract Code
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documentation -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0">📚 Documentation</h6>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">Setup guides and implementation details</p>
                    <div class="d-flex flex-column gap-2">
                        <a href="monnify_documentation.html" class="btn btn-sm btn-outline-success" target="_blank">
                            Integration Guide
                        </a>
                        <a href="<?php echo APP_BASE_URL; ?>/admin/index.php" class="btn btn-sm btn-outline-secondary">
                            Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Current Status -->
    <div class="row mt-4">
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0">📊 Current Status</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php
                        $db = getDB();
                        $total = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
                        $with_accounts = (int) $db->query("SELECT COUNT(*) FROM users WHERE virtual_account IS NOT NULL AND virtual_account != ''")->fetchColumn();
                        $without = $total - $with_accounts;
                        ?>
                        <div class="col-md-4">
                            <p class="small text-muted mb-1">Total Users</p>
                            <h4 class="fw-bold"><?php echo $total; ?></h4>
                        </div>
                        <div class="col-md-4">
                            <p class="small text-muted mb-1">With Virtual Accounts</p>
                            <h4 class="fw-bold text-success"><?php echo $with_accounts; ?></h4>
                        </div>
                        <div class="col-md-4">
                            <p class="small text-muted mb-1">Pending Accounts</p>
                            <h4 class="fw-bold text-warning"><?php echo $without; ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="row mt-4">
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm bg-light">
                <div class="card-header bg-light">
                    <h6 class="mb-0">🔗 Quick Links</h6>
                </div>
                <div class="card-body small">
                    <ul class="list-unstyled mb-0 columns" style="column-count: 2;">
                        <li><a href="<?php echo APP_BASE_URL; ?>/admin/index.php">Dashboard</a></li>
                        <li><a href="<?php echo APP_BASE_URL; ?>/admin/users.php">User Management</a></li>
                        <li><a href="<?php echo APP_BASE_URL; ?>/admin/transactions.php">Transactions</a></li>
                        <li><a href="<?php echo APP_BASE_URL; ?>/admin/verify_proofs.php">Verify Proofs</a></li>
                        <li><a href="<?php echo APP_BASE_URL; ?>/admin/analytics.php">Analytics</a></li>
                        <li><a href="<?php echo APP_BASE_URL; ?>/admin/admin_action_logs.php">Logs</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>
</body>
</html>
