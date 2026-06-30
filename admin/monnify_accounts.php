<?php
require_once '../config/config.php';
checkRouteAccess('admin');
$db = getDB();

// Helper functions
function monnifyAccessToken(&$error) {
    $error = 'Flutterwave secret key is not configured.';
    if (empty(FLUTTERWAVE_SECRET_KEY) || FLUTTERWAVE_SECRET_KEY === 'FLWSECK_TEST-REPLACE_ME') {
        return null;
    }

    return FLUTTERWAVE_SECRET_KEY;
}

function createReservedAccount($token, $user, &$error) {
    $reference = $user['monnify_reference'] ?: ('PAYCLUST_' . $user['nin'] . '_' . time());
    $payload = [
        'email' => !empty($user['email']) ? $user['email'] : 'user_' . $user['id'] . '@payclust.system',
        'is_permanent' => true,
        'tx_ref' => $reference,
        'narration' => 'Salary account for ' . trim($user['first_name'] . ' ' . $user['surname']),
        'amount' => 0,
        'currency' => 'NGN'
    ];

    if (!empty($user['phone'])) {
        $payload['phonenumber'] = $user['phone'];
    }

    $ch = curl_init(FLUTTERWAVE_BASE_URL . '/virtual-account-numbers');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30
    ]);

    $body = curl_exec($ch);
    $curl_error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $curl_error) {
        $error = 'Flutterwave request failed: ' . $curl_error;
        return null;
    }

    $response = json_decode($body, true);
    if (($response['status'] ?? '') !== 'success') {
        $error = 'Flutterwave rejected account generation: ' . ($response['message'] ?? 'HTTP ' . $status);
        return null;
    }

    $account = $response['data'] ?? [];
    $accountNumber = $account['account_number'] ?? $account['accountNumber'] ?? null;
    if (!$accountNumber) {
        $error = 'No account number in response';
        return null;
    }

    return [
        'reference' => $reference,
        'number' => $accountNumber,
        'bank' => $account['bank_name'] ?? $account['bankName'] ?? 'Flutterwave'
    ];
}

$action = $_GET['action'] ?? '';
$results = [];

// Handle single user account generation
if ($action === 'generate_one' && isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        $results['error'] = 'User not found';
    } else if (!empty($user['virtual_account'])) {
        $results['info'] = 'User already has a virtual account: ' . htmlspecialchars($user['virtual_account']);
    } else {
        $error = '';
        $token = monnifyAccessToken($error);
        if (!$token) {
            $results['error'] = $error;
        } else {
            $account = createReservedAccount($token, $user, $error);
            if (!$account) {
                $results['error'] = $error;
            } else {
                $stmt = $db->prepare("UPDATE users SET virtual_account = ?, bank_name = ?, monnify_reference = ? WHERE id = ?");
                $stmt->execute([$account['number'], $account['bank'], $account['reference'], $user_id]);
                $results['success'] = true;
                $results['account'] = $account;
            }
        }
    }
}

// Handle bulk generation
elseif ($action === 'generate_all') {
    $pending = $db->query("SELECT * FROM users WHERE (virtual_account IS NULL OR virtual_account = '') ORDER BY id ASC")->fetchAll();
    
    if (empty($pending)) {
        $results['info'] = 'All users already have virtual accounts';
    } else {
        $error = '';
        $token = monnifyAccessToken($error);
        if (!$token) {
            $results['error'] = $error;
        } else {
            $success = 0;
            $failed = 0;
            $failed_users = [];

            foreach ($pending as $user) {
                $account = createReservedAccount($token, $user, $error);
                if (!$account) {
                    $failed++;
                    // FIXED: Merged first_name and surname variables to avoid property index errors
                    $display_name = trim($user['first_name'] . ' ' . $user['surname']);
                    $failed_users[] = ['nin' => $user['nin'], 'name' => $display_name, 'error' => $error];
                    continue;
                }

                $stmt = $db->prepare("UPDATE users SET virtual_account = ?, bank_name = ?, monnify_reference = ? WHERE id = ?");
                $stmt->execute([$account['number'], $account['bank'], $account['reference'], $user['id']]);
                $success++;
            }

            $results['success'] = true;
            $results['generated'] = $success;
            $results['failed'] = $failed;
            if (!empty($failed_users)) {
                $results['failed_users'] = $failed_users;
            }
        }
    }
}

// Get users without accounts
$without_accounts = $db->query("SELECT id, nin, first_name, surname, email, cluster_code FROM users WHERE virtual_account IS NULL OR virtual_account = '' ORDER BY id ASC")->fetchAll();
$with_accounts = (int) $db->query("SELECT COUNT(*) FROM users WHERE virtual_account IS NOT NULL AND virtual_account != ''")->fetchColumn();
$total_users = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

include_once '../partials/header.php';
?>

<!-- Section Header -->
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 pb-3 border-bottom gap-3">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold">Monnify Account Generator</h1>
        <p class="text-muted small mb-0">Provision secure unique virtual inbound collection account parameters for platform nodes.</p>
    </div>
    <div>
        <a href="<?php echo APP_BASE_URL; ?>/admin/index.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-medium">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<!-- API Callback Messages -->
<?php if (!empty($results)): ?>
    <?php if (isset($results['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 border-start border-danger border-4 rounded-3 p-3 mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i>
                <div>
                    <strong class="d-block text-dark">Operation Disrupted</strong>
                    <span class="small text-secondary"><?php echo htmlspecialchars($results['error']); ?></span>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif (isset($results['info'])): ?>
        <div class="alert alert-info alert-dismissible fade show shadow-sm border-0 border-start border-info border-4 rounded-3 p-3 mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-info-circle-fill fs-4 me-3 text-info"></i>
                <div>
                    <strong class="d-block text-dark">Workspace Notification</strong>
                    <span class="small text-secondary"><?php echo htmlspecialchars($results['info']); ?></span>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif (isset($results['success']) && $results['success']): ?>
        <?php if (isset($results['account'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 border-start border-success border-4 rounded-3 p-3 mb-4" role="alert">
                <div class="d-flex align-items-center mb-2">
                    <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i>
                    <div>
                        <strong class="d-block text-dark">Virtual Account Allocated Successfully</strong>
                        <span class="small text-muted">Parameters successfully registered to cloud ledger profiles.</span>
                    </div>
                </div>
                <div class="bg-white border rounded p-3 mt-2 font-monospace small shadow-inner">
                    <div class="row g-2">
                        <div class="col-sm-4"><strong>Account Number:</strong> <span class="text-primary fw-bold"><?php echo htmlspecialchars($results['account']['number']); ?></span></div>
                        <div class="col-sm-4"><strong>Settle Bank:</strong> <span class="text-dark"><?php echo htmlspecialchars($results['account']['bank']); ?></span></div>
                        <div class="col-sm-4 text-truncate"><strong>Reference Tag:</strong> <span class="text-muted"><?php echo htmlspecialchars($results['account']['reference']); ?></span></div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php else: ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 border-start border-success border-4 rounded-3 p-3 mb-4" role="alert">
                <div class="d-flex align-items-center mb-2">
                    <i class="bi bi-layers-half fs-4 me-3 text-success"></i>
                    <div>
                        <strong class="d-block text-dark">Bulk Execution Cycle Finished</strong>
                        <span class="small text-muted">Cloud batch tracking allocation status processing report overview:</span>
                    </div>
                </div>
                <div class="fw-bold small text-dark mb-1">
                    🎉 Allocation Success: <span class="text-success"><?php echo $results['generated']; ?> accounts</span> 
                    | ❌ Allocation Errors: <span class="text-danger"><?php echo $results['failed']; ?></span>
                </div>
                <?php if (isset($results['failed_users']) && !empty($results['failed_users'])): ?>
                    <div class="mt-3 bg-white p-3 rounded border">
                        <span class="text-danger small fw-bold d-block mb-1">Detailed Exception Log Matrix:</span>
                        <div class="overflow-auto" style="max-height: 150px;">
                            <ul class="mb-0 small text-muted ps-3 font-monospace">
                                <?php foreach ($results['failed_users'] as $fu): ?>
                                    <li>[NIN: <?php echo htmlspecialchars($fu['nin']); ?>] - <?php echo htmlspecialchars($fu['name']); ?>: <span class="text-danger"><?php echo htmlspecialchars($fu['error']); ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Summary Cards Data Metrics Deck -->
<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="card h-100 p-4 bg-white border-0 shadow-sm rounded-3">
            <h6 class="text-secondary small fw-bold text-uppercase tracking-wider mb-1" style="font-size:0.75rem;">Total Node Profiles</h6>
            <h2 class="fw-bold text-dark mb-0"><?php echo $total_users; ?></h2>
        </div>
    </div>
    <div class="col">
        <div class="card h-100 p-4 bg-white border-0 shadow-sm rounded-3 border-start border-success border-4">
            <h6 class="text-success small fw-bold text-uppercase tracking-wider mb-1" style="font-size:0.75rem;">Allocated Accounts</h6>
            <h2 class="fw-bold text-success mb-0"><?php echo $with_accounts; ?></h2>
        </div>
    </div>
    <div class="col">
        <div class="card h-100 p-4 bg-white border-0 shadow-sm rounded-3 border-start border-warning border-4">
            <h6 class="text-warning small fw-bold text-uppercase tracking-wider mb-1" style="font-size:0.75rem;">Awaiting Allocation</h6>
            <h2 class="fw-bold text-warning mb-0"><?php echo count($without_accounts); ?></h2>
        </div>
    </div>
    <div class="col">
        <div class="card h-100 p-4 bg-white border-0 shadow-sm rounded-3 border-start border-info border-4">
            <h6 class="text-info small fw-bold text-uppercase tracking-wider mb-1" style="font-size:0.75rem;">Ledger Protection Coverage</h6>
            <h2 class="fw-bold text-info mb-0"><?php echo $total_users > 0 ? round(($with_accounts / $total_users) * 100) : 0; ?>%</h2>
        </div>
    </div>
</div>

<!-- System Work Actions Control Deck -->
<div class="card p-4 border-0 shadow-sm bg-white mb-4" style="border-radius:12px;">
    <h5 class="fw-bold text-dark mb-2">🚀 Batch Process Workspaces</h5>
    <p class="text-muted small mb-3">Trigger background ledger assignments across network systems using targeted cURL routines.</p>
    <div class="d-flex flex-wrap gap-2">
        <a href="?action=generate_all" class="btn btn-primary btn-sm px-4 py-2 rounded-pill fw-medium shadow-sm <?php echo empty($without_accounts) ? 'disabled btn-secondary' : ''; ?>" onclick="return confirm('Generate accounts for all <?php echo count($without_accounts); ?> users without accounts?')">
            <i class="bi bi-lightning-charge me-1"></i> Bulk Generate (<?php echo count($without_accounts); ?> Items)
        </a>
        <a href="test_monnify_api.php" class="btn btn-outline-secondary btn-sm px-4 py-2 rounded-pill fw-medium shadow-sm" target="_blank">
            <i class="bi bi-cpu me-1"></i> Diagnostics / Test Endpoints
        </a>
    </div>
</div>

<!-- Users Pending Account Generation Registry -->
<div class="card border-0 shadow-sm bg-white p-4" style="border-radius:14px;">
    <div class="border-bottom pb-3 mb-3">
        <h5 class="fw-bold text-dark mb-1">Pending Allocation Queue Registry</h5>
        <p class="text-muted small mb-0">Showing system nodes requiring inbound virtual routing profiles to interact with payment gateway structures.</p>
    </div>
    
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light text-secondary fw-semibold">
                <tr>
                    <th class="py-3" style="width: 80px;">System ID</th>
                    <th class="py-3">User Profile Identity</th>
                    <th class="py-3">Contact Communication Node</th>
                    <th class="py-3 text-center">Axis Node Code</th>
                    <th class="py-3 text-end pe-3">Action Operations</th>
                </tr>
            </thead>
            <tbody class="text-dark">
                <?php if (empty($without_accounts)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <i class="bi bi-shield-check fs-2 text-success d-block mb-2"></i>
                            Excellent! All profiles synchronized with live assigned settlement profiles.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($without_accounts as $u): ?>
                        <tr>
                            <td class="font-monospace text-secondary fw-medium">#<?php echo $u['id']; ?></td>
                            <td>
                                <!-- FIXED: Joined distinct columns together safely -->
                                <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['surname']); ?></span>
                                <small class="text-muted font-monospace d-block mt-0.5">NIN: <?php echo htmlspecialchars($u['nin']); ?></small>
                            </td>
                            <td>
                                <span class="text-secondary fw-medium"><?php echo htmlspecialchars($u['email']); ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-secondary border px-2.5 py-1.5 fw-semibold font-monospace">
                                    <?php echo htmlspecialchars($u['cluster_code'] ?: 'Global Node'); ?>
                                </span>
                            </td>
                            <td class="text-end pe-3">
                                <a href="?action=generate_one&user_id=<?php echo intval($u['id']); ?>" class="btn btn-sm btn-success px-3 fw-semibold shadow-sm">
                                    <i class="bi bi-plus-circle me-1"></i> Provision Account
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