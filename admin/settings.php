<?php
require_once '../config/config.php';
checkRouteAccess('admin');
$db = getDB();

// Fetch the currently logged-in administrator's active session data
// Assumes $_SESSION['admin_id'] is set during login
$admin_id = $_SESSION['admin_id'] ?? 0;

if (!$admin_id) {
    die("Unauthorized access. Please log in again.");
}

$success_msg = "";
$error_msg = "";

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ACTION 1: UPDATE PROFILE DETAILS
    if ($action === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $surname    = trim($_POST['surname'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');

        if (empty($first_name) || empty($surname) || empty($email)) {
            $error_msg = "First Name, Surname, and Email fields are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Please provide a valid email address.";
        } else {
            try {
                // Check if email is already taken by another admin
                $check_email = $db->prepare("SELECT id FROM admin_settings WHERE email = ? AND id != ?");
                $check_email->execute([$email, $admin_id]);
                
                if ($check_email->fetch()) {
                    $error_msg = "This email address is already in use by another administrator.";
                } else {
                    // Update Profile info
                    $update_stmt = $db->prepare("UPDATE admin_settings SET first_name = ?, surname = ?, email = ?, phone = ? WHERE id = ?");
                    $update_stmt->execute([$first_name, $surname, $email, $phone, $admin_id]);
                    
                    // Log the administrative action to audit trails
                    $log_stmt = $db->prepare("INSERT INTO admin_action_logs (admin_id, action_type, details) VALUES (?, 'PROFILE_UPDATE', 'Admin updated personal profile details.')");
                    $log_stmt->execute([$admin_id]);

                    $success_msg = "Profile updated successfully!";
                }
            } catch (PDOException $e) {
                $error_msg = "Database Error: " . $e->getMessage();
            }
        }
    }

    // ACTION 2: SECURE PASSWORD RESET
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg = "All password tracking fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = "New password and confirmation password do not match.";
        } elseif (strlen($new_password) < 8) {
            $error_msg = "Your new password must be at least 8 characters long.";
        } else {
            try {
                // Pull old password hash from DB to verify identity
                $pwd_stmt = $db->prepare("SELECT password_hash FROM admin_settings WHERE id = ?");
                $pwd_stmt->execute([$admin_id]);
                $admin_user = $pwd_stmt->fetch();

                if ($admin_user && password_verify($current_password, $admin_user['password_hash'])) {
                    // Securely re-hash new password
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    $update_pwd = $db->prepare("UPDATE admin_settings SET password_hash = ? WHERE id = ?");
                    $update_pwd->execute([$new_hash, $admin_id]);

                    // Log password modification event
                    $log_stmt = $db->prepare("INSERT INTO admin_action_logs (admin_id, action_type, details) VALUES (?, 'PASSWORD_CHANGE', 'Admin modified account authentication password.')");
                    $log_stmt->execute([$admin_id]);

                    $success_msg = "Password reset successfully completed!";
                } else {
                    $error_msg = "The current password you provided is incorrect.";
                }
            } catch (PDOException $e) {
                $error_msg = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// Always fetch fresh account state information for inputs
$admin_data_stmt = $db->prepare("SELECT username, first_name, surname, email, phone, admin_role FROM admin_settings WHERE id = ?");
$admin_data_stmt->execute([$admin_id]);
$admin = $admin_data_stmt->fetch();

if (!$admin) {
    die("Account metadata lookup failure.");
}

include_once '../partials/header.php';
?>

<div class="mb-4">
    <h1 class="h3 mb-1 text-gray-800 fw-bold">⚙️ Account Settings</h1>
    <p class="text-muted small">Manage your profile metadata, secure password parameters, and permissions context.</p>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success border-0 shadow-sm small mb-4" role="alert">
        🎉 <?php echo htmlspecialchars($success_msg); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger border-0 shadow-sm small mb-4" role="alert">
        ⚠️ <?php echo htmlspecialchars($error_msg); ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm bg-white p-4">
            <h5 class="fw-bold text-dark mb-1">Personal Profile Context</h5>
            <p class="text-muted small mb-4">Modify account metadata directly tracked across reporting structures.</p>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Username (Read-Only)</label>
                        <input type="text" class="form-control form-control-sm bg-light" value="<?php echo htmlspecialchars($admin['username']); ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">System Privilege Tier</label>
                        <input type="text" class="form-control form-control-sm bg-light text-uppercase fw-bold text-primary" value="<?php echo htmlspecialchars($admin['admin_role']); ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">First Name</label>
                        <input type="text" name="first_name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($admin['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Surname</label>
                        <input type="text" name="surname" class="form-control form-control-sm" value="<?php echo htmlspecialchars($admin['surname'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Direct Email Address</label>
                        <input type="email" name="email" class="form-control form-control-sm" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Phone Number</label>
                        <input type="text" name="phone" class="form-control form-control-sm" value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>">
                    </div>
                    <div class="col-12 mt-4">
                        <button type="submit" class="btn btn-primary btn-sm px-4">💾 Save Metadata Profile</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm bg-white p-4">
            <h5 class="fw-bold text-dark mb-1">Reset Password Authentication</h5>
            <p class="text-muted small mb-4">Regularly update your system access keys to secure collection registries.</p>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Current Account Password</label>
                    <input type="password" name="current_password" class="form-control form-control-sm" placeholder="••••••••" required>
                </div>
                
                <hr class="my-3 text-muted opacity-25">
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">New Hashed Password String</label>
                    <input type="password" name="new_password" class="form-control form-control-sm" placeholder="Minimum 8 characters" required>
                </div>
                
                <div class="mb-4">
                    <label class="form-label small fw-bold">Confirm New Passphrase Match</label>
                    <input type="password" name="confirm_password" class="form-control form-control-sm" placeholder="Confirm character sequence" required>
                </div>
                
                <button type="submit" class="btn btn-danger btn-sm w-100">🔒 Reset Authentication Password</button>
            </form>
        </div>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>