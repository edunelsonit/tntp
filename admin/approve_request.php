<?php
require_once '../config/config.php';

// Enforce explicit administrative access parameters
checkRouteAccess('admin');
$db = getDB();

$action = trim($_POST['action'] ?? '');
$request_id = intval($_POST['request_id'] ?? 0);
$admin_id = intval($_SESSION['admin_id'] ?? 0);
$rejection_reason = trim($_POST['rejection_reason'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $request_id > 0) {
    
    // 1. FIXED: Synchronized validation check to match system core naming conventions
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !validateCsrfToken($csrf_token)) {
        header('Location: approve_request.php?err=security_fault');
        exit;
    }

    try {
        $db->beginTransaction();

        // 2. Fetch the change request context inside an isolated rows update lock frame
        $stmt = $db->prepare("SELECT * FROM user_change_requests WHERE id = ? AND status = 'PENDING' FOR UPDATE");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();

        if (!$request) {
            $db->rollBack();
            header('Location: approve_request.php?err=request_not_found_or_processed');
            exit;
        }

        $user_id = intval($request['user_id']);

        // =====================================================================
        // EXECUTION PATHWAY BRANCH: ADMINISTRATIVE APPROVAL 
        // =====================================================================
        if ($action === 'approve') {
            // Decode the structured JSON staging sequence data parameters directly on the fly
            $updateData = json_decode($request['proposed_data'], true);
            
            if (!is_array($updateData) || json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Malformed structural data payload serialization within configuration files.");
            }

            // Build dynamic atomic update parameters statement matching target users parameters
            $fieldsToUpdate = [];
            $executionParams = [];
            
            foreach ($updateData as $column => $value) {
                $fieldsToUpdate[] = "`{$column}` = ?";
                $executionParams[] = $value;
            }

            if (!empty($fieldsToUpdate)) {
                // Attach user conditional targets onto tracking dynamic query parameters
                $executionParams[] = $user_id;
                $updateQuery = "UPDATE users SET " . implode(', ', $fieldsToUpdate) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                
                $applyUpdate = $db->prepare($updateQuery);
                $applyUpdate->execute($executionParams);
            }

            // Update state metrics mapping criteria inside staging parameters layout
            $closeRequest = $db->prepare("
                UPDATE user_change_requests 
                SET status = 'APPROVED', processed_by = ?, processed_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $closeRequest->execute([$admin_id, $request_id]);

            // Document modification sequence footprints directly inside transaction audit logs
            $log = $db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, target_table, target_id, action_details, created_at) VALUES (?, "APPROVE_USER_CHANGE", "users", ?, ?, CURRENT_TIMESTAMP)');
            $details = json_encode([
                'request_id' => $request_id,
                'user_id' => $user_id,
                'applied_payload' => $updateData
            ]);
            $log->execute([$admin_id, $user_id, $details]);

            $db->commit();
            header('Location: approve_request.php?msg=changes_applied_cleanly');
            exit;

        // =====================================================================
        // EXECUTION PATHWAY BRANCH: ADMINISTRATIVE REJECTION
        // =====================================================================
        } elseif ($action === 'reject') {
            $closeRequest = $db->prepare("
                UPDATE user_change_requests 
                SET status = 'REJECTED', rejection_reason = ?, processed_by = ?, processed_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $closeRequest->execute([$rejection_reason, $admin_id, $request_id]);

            $log = $db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, target_table, target_id, action_details, created_at) VALUES (?, "REJECT_USER_CHANGE", "user_change_requests", ?, ?, CURRENT_TIMESTAMP)');
            $details = json_encode([
                'request_id' => $request_id,
                'user_id' => $user_id,
                'reason' => $rejection_reason
            ]);
            $log->execute([$admin_id, $request_id, $details]);

            $db->commit();
            header('Location: approve_request.php?msg=request_rejected');
            exit;
        }

        // Fallback catch parameters optimization step
        $db->rollBack();
        header('Location: approve_request.php?err=unknown_action');
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Critical operational structural breakdown inside approve_requests: " . $e->getMessage());
        header('Location: approve_request.php?err=transaction_execution_fault');
        exit;
    }
}

// =============================================================================
// VIEW LAYER RENDERING LOGIC (GET REQUESTS)
// =============================================================================
$pendingRequests = $db->query("
    SELECT r.*, u.nin, u.first_name, u.surname, a.username AS requester_name 
    FROM user_change_requests r
    JOIN users u ON u.id = r.user_id
    LEFT JOIN admin_settings a ON a.id = r.requested_by_id
    WHERE r.status = 'PENDING'
    ORDER BY r.created_at ASC
")->fetchAll();

include_once '../partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="fw-bold text-dark mb-1"><i class="bi bi-clipboard-check text-primary me-2"></i>Profile Update Approvals Portal</h2>
        <p class="text-muted small mb-0">Evaluate, approve, or reject structural data adjustment logs pending system synchronization actions.</p>
    </div>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'changes_applied_cleanly'): ?>
    <div class="alert alert-success border-0 shadow-sm mb-4 small fw-bold"><i class="bi bi-check-circle-fill me-1"></i> Data patch executed cleanly! Target profile records synchronized.</div>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'request_rejected'): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4 small fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> Profile modification ticket marked explicitly as rejected.</div>
<?php elseif (isset($_GET['err'])): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4 small fw-bold"><i class="bi bi-shield-x me-1"></i> Pipeline termination block triggered: <?php echo htmlspecialchars($_GET['err']); ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm bg-white p-4" style="border-radius: 14px;">
    <div class="table-responsive">
        <table class="table table-hover align-middle small mb-0">
            <thead class="table-light">
                <tr>
                    <th>Target Member Profile</th>
                    <th>Request Type</th>
                    <th>Proposed Parameter Delta Mapping Changes</th>
                    <th>Submitted By</th>
                    <th class="text-end">Management Actions Control Matrix</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pendingRequests)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <i class="bi bi-check2-circle text-success fs-1 d-block mb-2"></i>
                            Staging review queue clear! No modifications are currently awaiting authorization.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($pendingRequests as $req): ?>
                    <tr>
                        <td>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['surname']); ?></div>
                            <span class="font-monospace text-muted" style="font-size: 0.75rem;">NIN: <?php echo htmlspecialchars($req['nin']); ?></span>
                        </td>
                        <td>
                            <span class="badge bg-light text-primary border border-primary-subtle text-uppercase font-monospace px-2 py-1">
                                <?php echo htmlspecialchars($req['change_type']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="bg-light p-2 rounded border border-light-subtle font-monospace" style="font-size: 0.75rem; max-width: 420px;">
                                <?php 
                                $decoded = json_decode($req['proposed_data'], true);
                                if (is_array($decoded)) {
                                    foreach ($decoded as $key => $val) {
                                        echo "<div class='mb-1'><strong class='text-secondary'>" . htmlspecialchars($key) . ":</strong> <span class='text-dark'>" . htmlspecialchars((string)$val) . "</span></div>";
                                    }
                                }
                                ?>
                            </div>
                        </td>
                        <td class="text-secondary">
                            <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($req['requester_name'] ?? 'SYSTEM_NODE_ID #' . $req['requested_by_id']); ?>
                            <div class="small text-muted font-monospace" style="font-size: 0.7rem;"><?php echo htmlspecialchars($req['created_at']); ?></div>
                        </td>
                        <td>
                            <div class="d-flex gap-2 justify-content-end">
                                <form method="POST" action="approve_request.php" onsubmit="return confirm('Confirm application framework write updates for this parameter block profile?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                                    <input type="hidden" name="request_id" value="<?php echo intval($req['id']); ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn btn-sm btn-success fw-semibold px-3"><i class="bi bi-check-lg me-1"></i> Approve</button>
                                </form>

                                <button class="btn btn-sm btn-outline-danger fw-semibold px-3" data-bs-toggle="collapse" data-bs-target="#rejectForm_<?php echo $req['id']; ?>"><i class="bi bi-x-lg me-1"></i> Reject</button>
                            </div>

                            <div class="collapse mt-2 text-end" id="rejectForm_<?php echo $req['id']; ?>">
                                <form method="POST" action="approve_request.php" class="bg-light p-2 rounded border text-start">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                                    <input type="hidden" name="request_id" value="<?php echo intval($req['id']); ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <label class="form-label small fw-bold text-muted mb-1">Reason for Rejection:</label>
                                    <input type="text" name="rejection_reason" class="form-control form-control-sm mb-2" required placeholder="e.g., Typo detected, wrong allocation structure.">
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-danger btn-sm px-2 py-1" style="font-size: 0.75rem;">Confirm Deny</button>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include_once '../partials/footer.php'; ?>