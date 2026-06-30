<?php
require_once '../config/config.php';
checkRouteAccess('admin');
$db = getDB();

$id = intval($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';
if (!$id || !in_array($action, ['approve', 'reject'], true)) {
    header('Location: users.php');
    exit;
}

$status = $action === 'approve' ? 'APPROVED' : 'REJECTED';
$stmt = $db->prepare('UPDATE users SET approval_status = ? WHERE id = ?');
$stmt->execute([$status, $id]);

$admin_id = $_SESSION['admin_id'] ?? 0;
$log = $db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, details) VALUES (?,?,?)');
$details = json_encode(['user_id' => $id, 'action' => $action]);
$log->execute([$admin_id, 'USER_APPROVAL_' . strtoupper($action), $details]);

header('Location: users.php');
exit;
