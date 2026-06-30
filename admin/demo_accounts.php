<?php
require_once '../config/config.php';
checkRouteAccess('admin');
$db = getDB();

$msg = '';

// Demo bank and account number generation
function generateDemoAccount($user) {
    $banks = [
        'GTBank' => ['prefix' => '0737', 'code' => '737'],
        'Zenith Bank' => ['prefix' => '1016', 'code' => '076'],
        'Access Bank' => ['prefix' => '1106', 'code' => '044'],
        'First Bank' => ['prefix' => '1110', 'code' => '011'],
        'UBA' => ['prefix' => '1121', 'code' => '033'],
        'Diamond Bank' => ['prefix' => '1146', 'code' => '063'],
    ];
    
    $banks_array = array_values($banks);
    $selected_bank = $banks_array[array_rand($banks_array)];
    
    // Generate realistic account number: prefix + user_id padded
    $acct_num = $selected_bank['prefix'] . str_pad($user['id'], 10, '0', STR_PAD_LEFT);
    $bank_name = array_search($selected_bank, $banks);
    
    return [
        'number' => $acct_num,
        'bank' => $bank_name,
        'reference' => 'DEMO_' . $user['nin'] . '_' . time()
    ];
}

// Handle demo account generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_demo'])) {
        $user_id = intval($_POST['user_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user) {
            $msg = "<div class='alert alert-danger'>User not found</div>";
        } elseif (!empty($user['virtual_account'])) {
            $msg = "<div class='alert alert-warning'>User already has an account: " . htmlspecialchars($user['virtual_account']) . "</div>";
        } else {
            $account = generateDemoAccount($user);
            $stmt = $db->prepare("UPDATE users SET virtual_account = ?, bank_name = ?, monnify_reference = ? WHERE id = ?");
            $stmt->execute([$account['number'], $account['bank'], $account['reference'], $user_id]);
            $msg = "<div class='alert alert-success'>✓ Demo account generated for " . htmlspecialchars($user['full_name']) . "</div>";
        }
    } elseif (isset($_POST['generate_all_demo'])) {
        $pending = $db->query("SELECT * FROM users WHERE virtual_account IS NULL OR virtual_account = '' ORDER BY id ASC")->fetchAll();
        
        if (empty($pending)) {
            $msg = "<div class='alert alert-info'>All users already have accounts</div>";
        } else {
            $generated = 0;
            foreach ($pending as $user) {
                $account = generateDemoAccount($user);
                $stmt = $db->prepare("UPDATE users SET virtual_account = ?, bank_name = ?, monnify_reference = ? WHERE id = ?");
                $stmt->execute([$account['number'], $account['bank'], $account['reference'], $user['id']]);
                $generated++;
            }
            $msg = "<div class='alert alert-success'>✓ Generated demo accounts for $generated user(s)</div>";
        }
    }
}

$without_accounts = $db->query("SELECT id, nin, full_name, email, cluster_code FROM users WHERE virtual_account IS NULL OR virtual_account = '' ORDER BY id ASC")->fetchAll();
$with_accounts = (int) $db->query("SELECT COUNT(*) FROM users WHERE virtual_account IS NOT NULL AND virtual_account != ''")->fetchColumn();
$total_users = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

include_once '../partials/header.php';
?>
<div class="mb-4">
    <h1 class="h3 mb-1 text-gray-800 fw-bold">Demo Account Generator</h1>
    <p class="text-muted small">Generate demo virtual accounts for testing (no Monnify API required)</p>
</div>

<?php if (!empty($msg)): ?>
    <?php echo $msg; ?>
<?php endif; ?>

<div class="alert alert-info alert-dismissible fade show" role="alert">
    <strong>ℹ️ Demo Mode:</strong> This generates realistic-looking demo bank accounts for testing purposes. Use the Monnify Account Generator for production accounts.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card p-4 bg-light border-0 shadow-sm">
            <h6 class="text-muted small fw-bold mb-1">TOTAL USERS</h6>
            <h2 class="fw-bold text-dark"><?php echo $total_users; ?></h2>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-4 bg-light border-0 shadow-sm">
            <h6 class="text-muted small fw-bold mb-1">WITH ACCOUNTS</h6>
            <h2 class="fw-bold text-success"><?php echo $with_accounts; ?></h2>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-4 bg-light border-0 shadow-sm">
            <h6 class="text-muted small fw-bold mb-1">WITHOUT ACCOUNTS</h6>
            <h2 class="fw-bold text-warning"><?php echo count($without_accounts); ?></h2>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-4 bg-light border-0 shadow-sm">
            <h6 class="text-muted small fw-bold mb-1">COVERAGE</h6>
            <h2 class="fw-bold text-info"><?php echo $total_users > 0 ? round(($with_accounts / $total_users) * 100) : 0; ?>%</h2>
        </div>
    </div>
</div>

<div class="card p-4 border-0 shadow-sm bg-white mb-4">
    <h5 class="fw-bold text-dark mb-3">Quick Actions</h5>
    <form method="POST" class="row g-2 align-items-end">
        <div class="col-auto">
            <button type="submit" name="generate_all_demo" class="btn btn-primary btn-sm" onclick="return confirm('Generate demo accounts for all <?php echo count($without_accounts); ?> users?')">
                Generate All (<?php echo count($without_accounts); ?>)
            </button>
        </div>
        <div class="col-auto">
            <a href="<?php echo APP_BASE_URL; ?>/admin/monnify_accounts.php" class="btn btn-outline-secondary btn-sm">Use Monnify API</a>
        </div>
    </form>
</div>

<div class="card p-4 border-0 shadow-sm bg-white">
    <h5 class="fw-bold text-dark mb-3">Users Pending Accounts (<?php echo count($without_accounts); ?>)</h5>
    <?php if (empty($without_accounts)): ?>
        <p class="text-muted small mb-0">All users have accounts!</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover small">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>NIN</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Cluster</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($without_accounts as $u): ?>
                    <tr>
                        <td><?php echo $u['id']; ?></td>
                        <td><code><?php echo htmlspecialchars($u['nin']); ?></code></td>
                        <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($u['cluster_code']); ?></span></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="user_id" value="<?php echo intval($u['id']); ?>">
                                <button type="submit" name="generate_demo" class="btn btn-sm btn-success">Generate</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include_once '../partials/footer.php'; ?>
