<?php
require_once '../config/config.php';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrf_token)) {
        header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
        exit;
    }

    $login_type = trim($_POST['login_type'] ?? '');
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? ''; 

    if (!in_array($login_type, ['admin', 'cluster_manager', 'user'], true)) {
        header("Location: " . APP_BASE_URL . "/index.php?err=failed");
        exit;
    }

    // ==========================================
    // SYSTEM ADMINISTRATOR AUTHENTICATION ROUTE
    // ==========================================
    if ($login_type === 'admin') {
        $stmt = $db->prepare("SELECT * FROM admin_settings WHERE username = ? OR email = ?");
        $stmt->execute([$identifier, $identifier]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['role'] = $admin['admin_role']; 
            $_SESSION['user'] = $admin['username'];
            $_SESSION['admin_id'] = $admin['id'];
            
            $computed_name = trim(($admin['first_name'] ?? '') . ' ' . ($admin['surname'] ?? ''));
            $_SESSION['full_name'] = !empty($computed_name) ? $computed_name : $admin['username'];
            
            header("Location: " . APP_BASE_URL . "/admin/index.php");
            exit;
        }
    } 
    // ==========================================
    // CLUSTER MANAGER AUTHENTICATION ROUTE
    // ==========================================
    elseif ($login_type === 'cluster_manager') {
        $stmt = $db->prepare("SELECT * FROM clusters WHERE manager_email = ? OR cluster_code = ?");
        $stmt->execute([$identifier, $identifier]);
        $cluster = $stmt->fetch();
        
        if ($cluster && password_verify($password, $cluster['manager_password'])) {
            session_regenerate_id(true);
            $_SESSION['role'] = 'cluster_manager';
            $_SESSION['cluster_code'] = $cluster['cluster_code'];
            $_SESSION['manager_name'] = $cluster['manager_name'];
            
            header("Location: " . APP_BASE_URL . "/manager/index.php");
            exit;
        }
    } 
    // ==========================================
    // OPERATIONAL USER (NIN) ONLY ROUTE
    // ==========================================
    elseif ($login_type === 'user') {
        // Strip out any accidental spaces or dashes from the input string
        $identifier = preg_replace('/[^0-9]/', '', $identifier);

        if (!preg_match('/^[0-9]{11}$/', $identifier)) {
            header("Location: " . APP_BASE_URL . "/index.php?err=failed&type=user&identity=" . urlencode($identifier));
            exit;
        }

        // Search strictly for the matching record in the database
        $stmt = $db->prepare("SELECT * FROM users WHERE nin = ?");
        $stmt->execute([$identifier]);
        $user = $stmt->fetch();
        
        // If the user exists, immediately skip password validation and check status
        if ($user) {
            $status = strtoupper(trim($user['approval_status'] ?? 'PENDING'));
            
            if ($status === 'APPROVED') {
                session_regenerate_id(true);
                $_SESSION['role'] = 'user';
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['nin'] = $user['nin'];
                $_SESSION['full_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['surname'] ?? ''));
                
                header("Location: " . APP_BASE_URL . "/users/index.php");
                exit;
            } elseif ($status === 'PENDING') {
                header("Location: " . APP_BASE_URL . "/index.php?err=pending&type=user&identity=" . urlencode($identifier));
                exit;
            } else {
                header("Location: " . APP_BASE_URL . "/index.php?err=rejected&type=user");
                exit;
            }
        }
    }

    // Default Fallback: If code reaches here, authentication metrics were invalid
    header("Location: " . APP_BASE_URL . "/index.php?err=failed&type=" . urlencode($login_type) . "&identity=" . urlencode($identifier));
    exit;
} else {
    header("Location: " . APP_BASE_URL . "/index.php");
    exit;
}