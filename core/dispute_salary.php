<?php
require_once '../config/config.php';
require_once '../core/RemittanceManager.php';
require_once '../core/DisputeManager.php';
checkRouteAccess('user');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE_URL . '/users/index.php');
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($csrf_token)) {
    header('Location: ' . APP_BASE_URL . '/users/index.php?err=invalid');
    exit;
}

$my_nin = sanitizeText($_SESSION['nin'] ?? '');
$disputeType = strtoupper(sanitizeText($_POST['dispute_type'] ?? 'NO_SALARY'));
$message = sanitizeText($_POST['dispute_message'] ?? '');

// Base-level structural validation parameter checking
if (empty($message) || !isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK || !in_array($disputeType, ['NO_SALARY', 'WEBHOOK_FAILED'], true)) {
    header('Location: ' . APP_BASE_URL . '/users/index.php?err=invalid');
    exit;
}

$file = $_FILES['proof'];

// Inline mitigation verification routine replacing native dependency assumptions
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
$allowedExt   = ['jpg', 'jpeg', 'png', 'pdf'];

// Resolve and sanitize extension constraints securely
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    header('Location: ' . APP_BASE_URL . '/users/index.php?err=invalid');
    exit;
}

// Verify actual file content signatures safely using finfo engine extensions
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedMimes, true)) {
    header('Location: ' . APP_BASE_URL . '/users/index.php?err=invalid');
    exit;
}

// Map and verify the destination upload folders relative to application structure
$targetDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}

// Generate an un-guessable, clean filename destination format signature
$clean_nin = preg_replace('/[^0-9]/', '', $my_nin);
$filename = $clean_nin . '_' . strtolower($disputeType) . '_' . time() . '.' . $ext;
$dest = $targetDir . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    header('Location: ' . APP_BASE_URL . '/users/index.php?err=upload');
    exit;
}

// Re-map asset url pointer reference back safely for global static layout engines
$proofPath = APP_BASE_URL . '/uploads/' . $filename;

// Locate associated profile data pointers across system mappings
$userStmt = $db->prepare('SELECT id FROM users WHERE nin = ?');
$userStmt->execute([$my_nin]);
$user = $userStmt->fetch();

if (!$user) {
    header('Location: ' . APP_BASE_URL . '/users/index.php?err=invalid');
    exit;
}

// Fetch corresponding collection log contextual reference links using Core Handlers
$remittanceManager = new RemittanceManager($db);
$latestRemittance = $remittanceManager->getLatestRemittanceForUser($my_nin);

if (!$latestRemittance) {
    header('Location: ' . APP_BASE_URL . '/users/index.php?err=invalid');
    exit;
}

// Process data variables directly over into tracking ledger tables
$disputeManager = new DisputeManager($db);
$disputeManager->submitDispute(
    (int)$user['id'], 
    (int)$latestRemittance['id'], 
    $disputeType, 
    $proofPath, 
    $message
);

header('Location: ' . APP_BASE_URL . '/users/index.php?msg=disputed');
exit;