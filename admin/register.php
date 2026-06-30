<?php
require_once '../config/config.php';

$db = getDB();
$msg = '';

// 1. Check if ANY super administrator accounts exist in the administrative table yet
try {
    $check_stmt = $db->query("SELECT COUNT(*) FROM admin_settings WHERE admin_role = 'super_admin'");
    $admin_count = intval($check_stmt->fetchColumn());
} catch (PDOException $e) {
    // Fallback contingency if checking against a different structural configuration table layout
    $admin_count = 0;
}

// 2. ONLY enforce route authentication if a system administrator already exists
if ($admin_count > 0) {
    checkRouteAccess('super_admin');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify structural CSRF token authenticity
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrf_token)) {
        header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
        exit;
    }

    // Leverage centralized sanitization primitives
    $username   = sanitizeText($_POST['username'] ?? '');
    $first_name = sanitizeText($_POST['first_name'] ?? '');
    $surname    = sanitizeText($_POST['surname'] ?? '');
    $email      = sanitizeEmail($_POST['email'] ?? '');
    $phone      = sanitizeText($_POST['phone'] ?? '');
    $role       = $_POST['role'] ?? 'admin';
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    if ($username === '' || $first_name === '' || $surname === '' || $email === '' || $phone === '' || $password === '' || $confirm === '') {
        $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'>All registration fields are strictly required.</div>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'>Enter a structurally valid email address string.</div>";
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'>Username must be 3-50 alphanumeric characters.</div>";
    } elseif (!in_array($role, ['admin', 'super_admin'], true)) {
        $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'>Invalid authorization scale tier selected.</div>";
    } elseif (strlen($password) < 6) {
        $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'>Security password must be at least 6 characters long.</div>";
    } elseif ($password !== $confirm) {
        $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'>Password confirmation parameters do not match.</div>";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            $stmt = $db->prepare("INSERT INTO admin_settings (username, first_name, surname, email, phone, admin_role, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $first_name, $surname, $email, $phone, $role, $hash]);
            
            header("Location: " . APP_BASE_URL . "/index.php?registered=admin");
            exit;
        } catch (PDOException $e) {
            $msg = "<div class='alert alert-danger py-2 small fw-semibold text-center'>That username or email already exists in the administrative registry.</div>";
        }
    }
}

// FIXED: Removed duplicate header call, rendering here cleanly once per execution cycle
include_once '../partials/header.php';
?>
<div class="row justify-content-center align-items-center" style="min-height: 75vh;">
    <div class="col-md-5">
        <div class="card shadow border-0 my-4" style="border-radius: 16px;">
            <div class="card-body p-5">
                <div class="text-center mb-4">
                    <h2 class="fw-bold text-primary mb-1">Register Admin</h2>
                    <p class="text-muted small">Create a system administrator account</p>
                    <?php if ($admin_count === 0): ?>
                        <span class="badge bg-warning text-dark fw-bold px-2 py-1 small mt-1"><i class="bi bi-shield-exclamation me-1"></i> Initial Provisioning Mode Active</span>
                    <?php endif; ?>
                </div>

                <?php echo $msg; ?>

                <form method="POST" action="<?php echo htmlspecialchars(APP_BASE_URL . '/admin/register.php'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo isset($first_name) ? htmlspecialchars($first_name) : ''; ?>" placeholder="Enter First Name as on NIN" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Surname</label>
                        <input type="text" name="surname" class="form-control" value="<?php echo isset($surname) ? htmlspecialchars($surname) : ''; ?>" placeholder="Enter Surname as on NIN" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" placeholder="username@sample.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Phone Number</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo isset($phone) ? htmlspecialchars($phone) : ''; ?>" placeholder="090********" required maxlength="11" minlength="11" pattern="\d{11}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Admin Username</label>
                        <input type="text" name="username" class="form-control" value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>" placeholder="Enter Admin Username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Target System Role Access Level</label>
                        <select name="role" class="form-select" required>
                            <option value="admin">Standard Administrator (Admin)</option>
                            <option value="super_admin" <?php echo $admin_count === 0 ? 'selected' : ''; ?>>Root System Administrator (Super Admin)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-secondary">Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">Initialize Management Profile</button>
                </form>

                <div class="text-center mt-4">
                    <a href="<?php echo APP_BASE_URL; ?>/index.php" class="small text-decoration-none fw-semibold">Return to Gateway Login</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include_once '../partials/footer.php'; ?>