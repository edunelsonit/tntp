<?php
// Enforce strict runtime typing paradigms
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Enforce high-security cookie-based session constraints
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    
    // Automatically flag secure cookie transfers if context is running over HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    
    session_start();
}

// =======================================================
// CONFIGURATION DIRECTIVES
// =======================================================
define('DB_HOST', 'localhost');
define('DB_USER', 'manodedi_tntp');              // Modify dynamically to match your production hosting cPanel environment
define('DB_PASS', 'Lockedout80$');                  // Modify dynamically to match your production hosting cPanel environment
define('DB_NAME', 'manodedi_tntp');
define('APP_BASE_URL', '');
define('PROJECT_NAME', "The New Tomorrow's Project");
define('UPLOAD_BASE_DIR', __DIR__ . '/../uploads');

define('MONNIFY_API_KEY', getenv('MONNIFY_API_KEY') ?: '');
define('MONNIFY_SECRET_KEY', getenv('MONNIFY_SECRET_KEY') ?: '');
define('MONNIFY_BASE_URL', getenv('MONNIFY_BASE_URL') ?: 'https://sandbox.monnify.com');
define('MONNIFY_CONTRACT_CODE', getenv('MONNIFY_CONTRACT_CODE') ?: '');

define('FLUTTERWAVE_PUBLIC_KEY', getenv('FLUTTERWAVE_PUBLIC_KEY') ?: 'FLWPUBK_TEST-REPLACE_ME');
define('FLUTTERWAVE_SECRET_KEY', getenv('FLUTTERWAVE_SECRET_KEY') ?: 'FLWSECK_TEST-REPLACE_ME');
define('FLUTTERWAVE_SECRET_HASH', getenv('FLUTTERWAVE_SECRET_HASH') ?: 'REPLACE_ME');
define('FLUTTERWAVE_BASE_URL', getenv('FLUTTERWAVE_BASE_URL') ?: 'https://api.flutterwave.com/v3');

define('PAYSTACK_PUBLIC_KEY', getenv('PAYSTACK_PUBLIC_KEY') ?: 'pk_test_YOUR_PUBLIC_KEY_HERE');
define('PAYSTACK_SECRET_KEY', getenv('PAYSTACK_SECRET_KEY') ?: 'sk_test_YOUR_SECRET_KEY_HERE');

// =======================================================
// DATABASE INTERACTION (OPTIMIZED SINGLETON)
// =======================================================
function getDB(): PDO {
    static $pdo = null; 
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // Enforce native sql server server-side statement validation
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
        catch (PDOException $e) {
            // Silently intercept system context exceptions, reporting route metrics cleanly to system error logs
            error_log("Database Connection Failure Exception: " . $e->getMessage());
            die("Critical Failure: Database connection lost. Core engine execution halted.");
        }
    }
    return $pdo;
}

// =======================================================
// SECURITY & DATA SANITIZATION PRIMITIVES
// =======================================================
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function sanitizeText(?string $value): string {
    $text = trim((string) ($value ?? ''));
    // Strip control characters safely
    $text = preg_replace('/[\0\x08\x0B\x0C\x1A]/u', '', $text) ?? '';
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function sanitizeEmail(?string $value): string {
    $email = trim((string) ($value ?? ''));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? filter_var($email, FILTER_SANITIZE_EMAIL) : '';
}

function sanitizeInt(int $value): int {
    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : 0;
}

function sanitizeFloat(float $value): float {
    return filter_var($value, FILTER_VALIDATE_FLOAT) !== false ? (float) $value : 0.0;
}

function sanitizeFileName(string $name): string {
    $name = trim($name);
    $name = preg_replace('/[^A-Za-z0-9._-]/u', '_', $name);
    return substr($name, 0, 200);
}

function validateUploadedFile(array $file, array $allowedMimes): bool {
    if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if ($mime === false) {
        return false;
    }

    return in_array($mime, $allowedMimes, true);
}

function invalidFormRedirect(string $location): void {
    $cleanLocation = filter_var($location, FILTER_SANITIZE_URL);
    header('Location: ' . $cleanLocation . '?err=invalid');
    exit;
}

// =======================================================
// SYSTEMIC ROLE HIERARCHY ACCESS ENGINE
// =======================================================
function checkRouteAccess(string $required_role): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Consolidated role parsing utility supporting both cross-functional session structures
    $user_role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? null;

    if (!$user_role) {
        header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
        exit;
    }

    $user_role     = strtolower(trim((string)$user_role));
    $required_role = strtolower(trim($required_role));

    // Handle structural check cases cleanly inside unified execution architecture blocks
    switch ($required_role) {
        case 'super_admin':
            // Strict match constraint configuration boundary lock
            if ($user_role !== 'super_admin') {
                header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
                exit;
            }
            break;

        case 'admin':
            // Hierarchical pipeline logic: Super Administrators inherit normal Admin privileges cleanly
            if ($user_role !== 'admin' && $user_role !== 'super_admin') {
                header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
                exit;
            }
            break;

        default:
            // Absolute check condition fallback logic mapping matches (e.g. 'user', 'cluster_manager')
            if ($user_role !== $required_role) {
                header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
                exit;
            }
            break;
    }
}

function currentUserLabel(): string {
    return htmlspecialchars((string)($_SESSION['first_name'] ?? $_SESSION['user'] ?? $_SESSION['cluster_code'] ?? $_SESSION['nin'] ?? 'Guest'), ENT_QUOTES, 'UTF-8');
}