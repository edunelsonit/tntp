<?php
require_once '../config/config.php';

// Enforce explicit administration routing rules
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'], true)) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token to block cross-site execution vectors
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !verifyCsrfToken($csrf_token)) {
        header('Location: clusters.php?err=invalid_token');
        exit;
    }

    // 2. Extract and format the specific cluster unique identifier code
    $code = strtoupper(trim($_POST['cluster_code'] ?? ''));

    if (!empty($code)) {
        try {
            // 3. Data Integrity Check: Verify no active user profiles are still mapped to this cluster group
            $checkUsers = $db->prepare("SELECT COUNT(*) FROM users WHERE cluster_code = ? AND working_status != 'TERMINATED'");
            $checkUsers->execute([$code]);
            $assignedUsersCount = intval($checkUsers->fetchColumn());

            if ($assignedUsersCount > 0) {
                // Deny execution if deleting this cluster group leaves user profiles orphaned
                header('Location: clusters.php?err=cluster_has_users&count=' . $assignedUsersCount);
                exit;
            }

            $db->beginTransaction();
            $admin_id = intval($_SESSION['admin_id'] ?? 0);

            // 4. Run the core cluster exclusion statement
            $stmt = $db->prepare("DELETE FROM clusters WHERE cluster_code = ?");
            $stmt->execute([$code]);
            
            if ($stmt->rowCount() > 0) {
                // 5. Document action directly into administrative audit trail indexes
                $log = $db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, target_table, target_id, action_details, created_at) VALUES (?, "DELETE_CLUSTER", "clusters", 0, ?, CURRENT_TIMESTAMP)');
                $details = json_encode([
                    'deleted_cluster_code' => $code,
                    'context' => 'Manual structural deletion completed via cluster dashboard UI interface node.'
                ]);
                $log->execute([$admin_id, $details]);
                
                $db->commit();
                header('Location: clusters.php?deleted=1');
                exit;
            } else {
                $db->rollBack();
                header('Location: clusters.php?err=not_found');
                exit;
            }

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Fallback system catch handler for database exceptions (e.g., active structural database engine blocks)
            header('Location: clusters.php?err=execution_fault');
            exit;
        }
    } else {
        header('Location: clusters.php?err=missing_code');
        exit;
    }
}

// Automatically push standard GET interaction streams straight back to UI core overview
header('Location: clusters.php');
exit;