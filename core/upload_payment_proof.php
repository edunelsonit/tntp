<?php
require_once '../config/config.php';
checkRouteAccess('user');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /users/index.php');
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($csrf_token)) {
    header('Location: /users/index.php?err=invalid');
    exit;
}

$my_nin = sanitizeText($_SESSION['nin'] ?? '');
$amount = sanitizeFloat($_POST['amount'] ?? 0);

if ($amount <= 0 || empty($_FILES['proof'])) {
    header('Location: /users/index.php?err=invalid');
    exit;
}

$file = $_FILES['proof'];
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
if (!validateUploadedFile($file, $allowedMimes)) {
    header('Location: /users/index.php?err=invalid');
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExt = ['jpg','jpeg','png','pdf'];
if (!in_array($ext, $allowedExt, true)) {
    header('Location: /users/index.php?err=invalid');
    exit;
}

$targetDir = UPLOAD_BASE_DIR;
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}
$baseName = sanitizeFileName($my_nin . '_PAYPROOF_' . time());
$filename = $baseName . '.' . $ext;
$dest = $targetDir . DIRECTORY_SEPARATOR . $filename;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    header('Location: /users/index.php?err=upload');
    exit;
}

$webPath = APP_BASE_URL . '/uploads/' . $filename;

$txref = 'PROOF_' . $my_nin . '_' . time();
$ins = $db->prepare('INSERT INTO transactions (tx_reference, nin, amount, receipt_path, status, payment_date, created_at) VALUES (?, ?, ?, ?, ?, NOW(), CURRENT_TIMESTAMP)');
$ins->execute([$txref, $my_nin, $amount, $webPath, 'PENDING']);

header('Location: /users/index.php?msg=uploaded');
exit;
