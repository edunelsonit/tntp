<?php
require_once 'config/config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: ' . APP_BASE_URL . '/admin/index.php');
            exit;
        case 'cluster_manager':
            header('Location: ' . APP_BASE_URL . '/manager/index.php');
            exit;
        case 'user':
            header('Location: ' . APP_BASE_URL . '/users/index.php');
            exit;
    }
}

include_once 'partials/header.php';

$err = $_GET['err'] ?? '';
$registered = $_GET['registered'] ?? '';

// Retain state parameters to prevent UI reset conditions on login failure
$selected_type = $_GET['type'] ?? 'user';
$saved_identity = $_GET['identity'] ?? '';
?>
<div class="container py-5">
    <div class="row justify-content-center align-items-center" style="min-height: 70vh;">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow border-0 mb-4" style="border-radius: 16px;">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <h2 class="fw-bold text-primary mb-1"><?php echo htmlspecialchars(PROJECT_NAME, ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p class="text-muted small">Authentication Router Gateway</p>
                    </div>
                    
                    <?php if ($err === 'failed'): ?>
                        <div class="alert alert-danger py-2 small text-center fw-semibold">Invalid parameters matching profile credentials.</div>
                    <?php elseif ($err === 'unauthorized'): ?>
                        <div class="alert alert-warning py-2 small text-center fw-semibold">Unauthorized route. Security access token denied.</div>
                    <?php endif; ?>
                    
                    <?php if ($registered === 'admin'): ?>
                        <div class="alert alert-success py-2 small text-center fw-semibold">Admin account created. You can now log in.</div>
                    <?php endif; ?>

                    <form method="POST" action="<?php echo htmlspecialchars(APP_BASE_URL . '/core/auth_handler.php'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Target Authorization Level</label>
                            <select name="login_type" id="login_type" class="form-select" onchange="adaptLoginFormFields(this.value)" required>
                                <option value="user" <?php echo $selected_type === 'user' ? 'selected' : ''; ?>>Operational User (NIN Only)</option>
                                <option value="cluster_manager" <?php echo $selected_type === 'cluster_manager' ? 'selected' : ''; ?>>Cluster Manager (Code & Password)</option>
                                <option value="admin" <?php echo $selected_type === 'admin' ? 'selected' : ''; ?>>System Administrator (Full Access)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label id="identity_label" class="form-label small fw-bold text-secondary">National Identification Number (NIN)</label>
                            <input type="text" name="identifier" id="identifier_input" value="<?php echo htmlspecialchars($saved_identity); ?>" class="form-control" placeholder="Provide tracking reference parameter" required>
                        </div>
                        
                        <div class="mb-4" id="password_area" style="display: none;">
                            <label class="form-label small fw-bold text-secondary">Security Password</label>
                            <input type="password" name="password" id="password_input" class="form-control" placeholder="••••••••">
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">Process Verification</button>
                    </form>
                    
                    <div class="text-center mt-4">
                        <div class="d-flex justify-content-center flex-wrap gap-2">
                            <a href="<?php echo APP_BASE_URL; ?>/users/register.php" class="small text-decoration-none">New user? Register here</a>
                            <span class="text-muted small d-none d-sm-inline">|</span>
                            <a href="<?php echo APP_BASE_URL; ?>/admin/register.php" class="small text-decoration-none text-secondary">Register admin account</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function adaptLoginFormFields(roleValue) {
        const label = document.getElementById('identity_label');
        const identityInput = document.getElementById('identifier_input');
        const frame = document.getElementById('password_area');
        const input = document.getElementById('password_input');
        
        if (!label || !identityInput || !frame || !input) return;

        if (roleValue === 'user') {
            label.innerText = "National Identification Number (NIN)";
            identityInput.placeholder = "Enter your 11-digit NIN";
            identityInput.type = "text";
            identityInput.setAttribute('maxlength', '11');
            identityInput.setAttribute('pattern', '\\d{11}');
            identityInput.setAttribute('title', 'NIN must be exactly 11 numeric digits.');
            frame.style.display = "none";
            input.removeAttribute('required');
        } else if (roleValue === 'cluster_manager') {
            label.innerText = "Assigned Cluster Code Identifier";
            identityInput.placeholder = "Enter cluster prefix identifier";
            identityInput.type = "text";
            identityInput.removeAttribute('maxlength');
            identityInput.removeAttribute('pattern');
            identityInput.removeAttribute('title');
            frame.style.display = "block";
            input.setAttribute('required', 'required');
        } else if (roleValue === 'admin') {
            label.innerText = "Administrator Account Username";
            identityInput.placeholder = "Enter master account login handle";
            identityInput.type = "text";
            identityInput.removeAttribute('maxlength');
            identityInput.removeAttribute('pattern');
            identityInput.removeAttribute('title');
            frame.style.display = "block";
            input.setAttribute('required', 'required');
        }
    }

    // Explicitly initialize state rules based on matching PHP runtime server-side parameters
    window.addEventListener('DOMContentLoaded', () => {
        const loginSelect = document.getElementById('login_type');
        if (loginSelect) {
            adaptLoginFormFields(loginSelect.value);
        }
    });
</script>
<?php include_once 'partials/footer.php'; ?>