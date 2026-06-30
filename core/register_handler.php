<?php
require_once '../config/config.php';
require_once '../core/RemittanceManager.php';
$db = getDB();

// Target return destination pipeline
$registration_page = APP_BASE_URL . '/users/register.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $registration_page);
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($csrf_token)) {
    header('Location: ' . $registration_page . '?err=invalid');
    exit;
}

$creatorRole = 'CLUSTER_MANAGER';
$creatorId = 0;
if (!empty($_SESSION['admin_role'])) {
    $creatorRole = strtolower(trim((string)$_SESSION['admin_role'])) === 'super_admin' ? 'SUPER_ADMIN' : 'ADMIN';
    $creatorId = intval($_SESSION['admin_id'] ?? 0);
} elseif (!empty($_SESSION['role']) && $_SESSION['role'] === 'cluster_manager') {
    $creatorRole = 'CLUSTER_MANAGER';
    $creatorId = intval($_SESSION['cluster_id'] ?? 0);
}

// Map parameters securely using system sanitizers
$nin              = sanitizeText($_POST['nin'] ?? '');
$first_name       = sanitizeText($_POST['first_name'] ?? '');
$surname          = sanitizeText($_POST['surname'] ?? '');
$phone            = sanitizeText($_POST['phone'] ?? '');
$email            = sanitizeEmail($_POST['email'] ?? '');
$cluster_code     = sanitizeText($_POST['cluster_code'] ?? '');
$expected_amount  = sanitizeFloat($_POST['expected_remittance_amount'] ?? '100000');
$gender           = sanitizeText($_POST['gender'] ?? '') ?: null;
$dob              = sanitizeText($_POST['dob'] ?? '') ?: null;
$state            = sanitizeText($_POST['state'] ?? '') ?: null;
$lga              = sanitizeText($_POST['lga'] ?? '') ?: null;
$address          = sanitizeText($_POST['host_organization_address'] ?? '') ?: null;

// NEWLY INTEGRATED CORE SALARY DISBURSEMENT SANITIZERS
$salary_account   = sanitizeText($_POST['salary_account_number'] ?? '');
$salary_bank      = sanitizeText($_POST['salary_bank_name'] ?? '');

// Format account parameter node into strict numeric sequence strings if passed
$salary_account   = empty($salary_account) ? null : preg_replace('/[^0-9]/', '', $salary_account);
$salary_bank      = empty($salary_bank) ? null : $salary_bank;

// Validation constraints check
if (!preg_match('/^[0-9]{11}$/', $nin)
    || empty($first_name)
    || empty($surname)
    || empty($phone)
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || empty($cluster_code)
    || $expected_amount <= 0
) {
    header('Location: ' . $registration_page . '?err=invalid');
    exit;
}

// Verify target operational cluster code group configuration status
$stmt_c = $db->prepare('SELECT id FROM clusters WHERE cluster_code = ?');
$stmt_c->execute([$cluster_code]);
if (!$stmt_c->fetch()) {
    header('Location: ' . $registration_page . '?err=invalid_cluster');
    exit;
}

// Verify uniqueness metrics constraints
$stmt = $db->prepare('SELECT id FROM users WHERE nin = ? OR email = ?');
$stmt->execute([$nin, $email]);
if ($stmt->fetch()) {
    header('Location: ' . $registration_page . '?err=exists');
    exit;
}

$manager = new RemittanceManager($db);
try {
    // Array updated to safely append target settlement fields to the constructor
    $manager->createUserWithApproval([
        'nin' => $nin,
        'first_name' => $first_name,
        'surname' => $surname,
        'phone' => $phone,
        'email' => $email,
        'cluster_code' => $cluster_code,
        'gender' => $gender,
        'dob' => $dob,
        'state_of_origin' => $state,
        'lga' => $lga,
        'host_organization_address' => $address,
        'expected_remittance_amount' => $expected_amount,
        'salary_account_number' => $salary_account,
        'salary_bank_name' => $salary_bank,
        'can_start_payment' => 0,
        'payment_start_date' => null,
        'working_status' => 'ACTIVE'
    ], $creatorRole, $creatorId);

    header('Location: ' . $registration_page . '?err=success');
    exit;
} catch (PDOException $e) {
    error_log("Remittance Management Registration Fault: " . $e->getMessage());
    header('Location: ' . $registration_page . '?err=db_error');
    exit;
}