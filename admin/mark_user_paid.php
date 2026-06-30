<?php
require_once '../config/config.php';

// 1. Enforce strict administrative authorization context
checkRouteAccess('admin');
$db = getDB();

// State-changing logic strictly barred from standard GET parameters to mitigate systemic vulnerabilities
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

// 2. Cryptographic Security Token Verification
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($csrf_token) || !verifyCsrfToken($csrf_token)) {
    header('Location: users.php?err=invalid_security_token');
    exit;
}

$id = intval($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? 'mark');

if (!$id) {
    header('Location: users.php?err=missing_profile_identifier');
    exit;
}

try {
    // Begin transactional lock to guarantee strict data block balance consistency
    $db->beginTransaction();

    // Fetch snapshot profile record state from users master table
    $stmt = $db->prepare('SELECT id, nin FROM users WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        $db->rollBack();
        header('Location: users.php?err=profile_not_found');
        exit;
    }

    $admin_id = intval($_SESSION['admin_id'] ?? 0);
    $nin = $user['nin'];

    // Fetch matching financial row from remittance matrix table using row-level exclusive updates lock
    $remStmt = $db->prepare('SELECT id, expected_amount, amount_paid FROM remittance WHERE userid = ? FOR UPDATE');
    $remStmt->execute([$id]);
    $remittance = $remStmt->fetch();

    // If no record exists inside ledger context, dynamically instantiate an empty matrix block placeholder
    if (!$remittance) {
        $insRem = $db->prepare('INSERT INTO remittance (userid, expected_amount, amount_paid) VALUES (?, 0.00, 0.00)');
        $insRem->execute([$id]);
        
        $remStmt->execute([$id]);
        $remittance = $remStmt->fetch();
    }

    // =========================================================================
    // EXECUTION BRANCH: PIPELINE BALANCE REVERSALS
    // =========================================================================
    if ($action === 'revert') {
        // Find the last admin-created manual tracking settlement record for this user node
        $tstmt = $db->prepare("SELECT id, tx_reference, amount FROM transactions WHERE nin = ? AND tx_reference LIKE 'ADMIN_MARK_%' AND status = 'VERIFIED' ORDER BY payment_date DESC LIMIT 1 FOR UPDATE");
        $tstmt->execute([$nin]);
        $tx = $tstmt->fetch();

        if ($tx) {
            $amountToRevert = floatval($tx['amount']);
            
            // Invalidate entry row status parameters inside the transactions record pipeline
            $updTx = $db->prepare('UPDATE transactions SET status = "REVERTED", processed_by = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?');
            $updTx->execute([$admin_id, $tx['id']]);

            // Recompute balance matrices safely preventing value overflows below zero
            $newPaid = max(0.0, floatval($remittance['amount_paid']) - $amountToRevert);
            
            // Sync structural parameters back onto remittance entry row
            $updRem = $db->prepare('UPDATE remittance SET amount_paid = ? WHERE userid = ?');
            $updRem->execute([$newPaid, $id]);

            // Clear dispute flags off user master row context safely
            $updUser = $db->prepare('UPDATE users SET dispute_status = "NONE", dispute_message = NULL, dispute_proof_path = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $updUser->execute([$id]);

            // Document modification footprints tracking target parameters metadata
            $log = $db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, target_table, target_id, action_details, created_at) VALUES (?, "REVERT_MARK_PAID", "users", ?, ?, CURRENT_TIMESTAMP)');
            $details = json_encode([
                'user_id' => $id,
                'nin' => $nin,
                'tx_id' => $tx['id'],
                'tx_reference' => $tx['tx_reference'],
                'reverted_amount' => $amountToRevert,
                'revised_balance_settled' => $newPaid
            ], JSON_THROW_ON_ERROR);
            $log->execute([$admin_id, $id, $details]);
            
            $db->commit();
            header('Location: users.php?msg=reversal_successful');
            exit;
        }

        $db->rollBack();
        header('Location: users.php?err=no_revertible_transaction_found');
        exit;

    // =========================================================================
    // EXECUTION BRANCH: SETTLE OUTSTANDING BALANCES MANUALLY
    // =========================================================================
    } else {
        $currentPaid = floatval($remittance['amount_paid']);
        $expected = floatval($remittance['expected_amount']);
        $amountToAdd = max(0.0, $expected - $currentPaid);
        $newPaid = $currentPaid + $amountToAdd;
        
        // Apply global ledger update tracking states directly to remittance data block 
        $updRem = $db->prepare('UPDATE remittance SET amount_paid = ? WHERE userid = ?');
        $updRem->execute([$newPaid, $id]);

        // Clean user master metadata row states
        $updUser = $db->prepare('UPDATE users SET dispute_status = "NONE", dispute_message = NULL, dispute_proof_path = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $updUser->execute([$id]);

        // Auto-verify legacy paper trailing documents flagged outstanding inside background records
        $txVerify = $db->prepare('UPDATE transactions SET status = "VERIFIED", processed_by = ?, processed_at = CURRENT_TIMESTAMP WHERE nin = ? AND status = "PENDING"');
        $txVerify->execute([$admin_id, $nin]);

        // Only inject ledger tracking entry fragments if an explicit delta gap value exists
        if ($amountToAdd > 0.0) {
            $txref = 'ADMIN_MARK_' . $nin . '_' . time();
            $insTx = $db->prepare('INSERT INTO transactions (tx_reference, nin, amount, status, payment_channel, payment_date, processed_by, processed_at, created_at) VALUES (?, ?, ?, "VERIFIED", "MANUAL_ADMIN_OVERRIDE", CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            $insTx->execute([$txref, $nin, $amountToAdd, $admin_id]);

            $log = $db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, target_table, target_id, action_details, created_at) VALUES (?, "MARK_PAID", "users", ?, ?, CURRENT_TIMESTAMP)');
            $details = json_encode([
                'user_id' => $id,
                'nin' => $nin,
                'tx_reference' => $txref,
                'amount_credited' => $amountToAdd,
                'new_running_total' => $newPaid
            ], JSON_THROW_ON_ERROR);
            $log->execute([$admin_id, $id, $details]);
        }

        $db->commit();
        header('Location: users.php?msg=settlement_recorded_cleanly');
        exit;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Critical operational block fault inside mark_user_paid: " . $e->getMessage());
    header('Location: users.php?err=transactional_execution_failure');
    exit;
}