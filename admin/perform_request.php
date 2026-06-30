<?php
require_once '../config/config.php';
checkRouteAccess('super_admin');
$db = getDB();
$id = intval($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';
if (!$id || !$action) { header('Location: approve_requests.php'); exit; }

$stmt = $db->prepare('SELECT * FROM user_change_requests WHERE id = ?');
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) { header('Location: approve_requests.php'); exit; }

if ($action === 'approve') {
    if ($r['request_type'] === 'UPDATE') {
        $payload = json_decode($r['payload'], true);
        if (!empty($payload)) {
            $set = [];
            $vals = [];
            foreach ($payload as $k => $v) {
                $set[] = "$k = ?";
                $vals[] = $v;
            }
            if (!empty($set)) {
                $vals[] = $r['user_id'];
                $sql = 'UPDATE users SET ' . implode(',', $set) . ' WHERE id = ?';
                $db->prepare($sql)->execute($vals);
            }
        }
    } elseif ($r['request_type'] === 'DELETE') {
        // perform delete (soft-delete if desired) - we'll hard delete here
        $userStmt = $db->prepare('SELECT nin FROM users WHERE id = ?');
        $userStmt->execute([$r['user_id']]);
        $userRow = $userStmt->fetch();
        if ($userRow) {
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$r['user_id']]);
            // transactions are deleted automatically via foreign key on users(nin)
        }
    }
    $db->prepare('UPDATE user_change_requests SET status = ?, processed_by = ?, processed_at = NOW() WHERE id = ?')
       ->execute(['APPROVED', $_SESSION['admin_id'] ?? 0, $id]);
} else {
    $db->prepare('UPDATE user_change_requests SET status = ?, processed_by = ?, processed_at = NOW() WHERE id = ?')
       ->execute(['REJECTED', $_SESSION['admin_id'] ?? 0, $id]);
}

header('Location: approve_requests.php');
exit;
