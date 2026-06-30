<?php
// FIXED: Adjusted relative path targets to check system root directories safely
require_once '../config/config.php';

if (!isset($_SESSION['role'])) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();
$msg = '';
$role = $_SESSION['role'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // CSRF Guardrail Validation Implementation
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !verifyCsrfToken($token)) {
        $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'><i class='bi bi-shield-x me-1'></i> Security verification failed. Session expired.</div>";
    } else {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($new) < 6) {
            $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'><i class='bi bi-exclamation-circle me-1'></i> New password must be at least 6 characters.</div>";
        } elseif ($new !== $confirm) {
            $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'><i class='bi bi-exclamation-circle me-1'></i> Password confirmation does not match.</div>";
        } elseif (in_array($role, ['admin', 'super_admin'], true)) {
            $stmt = $db->prepare("SELECT * FROM admin_settings WHERE id = ?");
            $stmt->execute([$_SESSION['admin_id'] ?? 0]);
            $admin = $stmt->fetch();
            if (!$admin || !password_verify($current, $admin['password_hash'])) {
                $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'><i class='bi bi-lock-fill me-1'></i> Current password is incorrect.</div>";
            } else {
                $stmt = $db->prepare("UPDATE admin_settings SET password_hash = ? WHERE id = ?");
                $stmt->execute([password_hash($new, PASSWORD_BCRYPT), $admin['id']]);
                $msg = "<div class='alert alert-success py-2 small fw-semibold text-center'><i class='bi bi-check-circle-fill me-1'></i> Administrative password changed successfully.</div>";
            }
        } elseif ($role === 'cluster_manager') {
            $stmt = $db->prepare("SELECT * FROM clusters WHERE cluster_code = ?");
            $stmt->execute([$_SESSION['cluster_code'] ?? '']);
            $cluster = $stmt->fetch();
            if (!$cluster || !password_verify($current, $cluster['manager_password'])) {
                $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'><i class='bi bi-lock-fill me-1'></i> Current password is incorrect.</div>";
            } else {
                $stmt = $db->prepare("UPDATE clusters SET manager_password = ? WHERE cluster_code = ?");
                $stmt->execute([password_hash($new, PASSWORD_BCRYPT), $cluster['cluster_code']]);
                $msg = "<div class='alert alert-success py-2 small fw-semibold text-center'><i class='bi bi-check-circle-fill me-1'></i> Manager account credentials updated successfully.</div>";
            }
        } else {
            $msg = "<div class='alert alert-info py-2 small fw-semibold text-center'><i class='bi bi-info-circle-fill me-1'></i> Password modifications are disabled for standard portal identities.</div>";
        }
    }
}

include_once '../partials/header.php';
?>

<div class="row justify-content-center align-items-center" style="min-height: 70vh;">
    <div class="col-md-8 col-lg-6">
        <div class="card border-0 shadow-sm bg-white p-4 mb-4" style="border-radius: 14px; border: 1px solid #f1f5f9 !important;">
            <div class="d-flex align-items-center">
                <div class="bg-primary-subtle text-primary rounded-circle p-3 me-3">
                    <i class="bi bi-person-gear fs-3"></i>
                </div>
                <div>
                    <h2 class="fw-bold text-dark mb-1 h4">Profile Settings</h2>
                    <p class="text-muted small mb-0">
                        Signed in as: <strong class="text-dark"><?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['nin'] ?? 'Authenticated Identity'); ?></strong>
                        <span class="mx-1 text-muted">•</span> Access Scope: <span class="badge bg-secondary-subtle text-secondary font-monospace text-uppercase" style="font-size: 0.725rem;"><?php echo htmlspecialchars($role); ?></span>
                    </p>
                </div>
            </div>
        </div>

        <?php if (!empty($msg)) echo $msg; ?>

        <div class="card border-0 shadow-sm bg-white" style="border-radius: 16px; border: 1px solid #f1f5f9 !important;">
            <div class="card-body p-4 p-md-5">
                <div class="mb-4">
                    <h5 class="fw-bold text-dark mb-1"><i class="bi bi-shield-lock me-1 text-primary"></i> Change Access Credentials</h5>
                    <p class="text-muted small mb-0">Update your security token to maintain safe framework access restrictions.</p>
                </div>
                
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Current Account Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted"><i class="bi bi-lock"></i></span>
                            <input type="password" name="current_password" class="form-control" required placeholder="Enter current system password">
                        </div>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label small fw-bold text-secondary">New Security Token</label>
                            <input type="password" name="new_password" class="form-control" required minlength="6" placeholder="Min. 6 characters">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label small fw-bold text-secondary">Confirm New Token</label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="6" placeholder="Repeat token entry">
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-2">
                        <button class="btn btn-primary w-100 py-2.5 fw-bold shadow-sm mb-2" type="submit" name="change_password">
                            <i class="bi bi-check2-circle me-1"></i> Update Access Credentials
                        </button>
                        <a href="<?php echo APP_BASE_URL; ?>/index.php" class="btn btn-link w-100 text-decoration-none small text-muted">
                            <i class="bi bi-arrow-left"></i> Discard and Return to Dashboard
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>