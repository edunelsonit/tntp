<?php
require_once '../config/config.php';

// Check for authorized route access
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'manager'], true)) {
    header("Location: " . APP_BASE_URL . "/index.php?err=unauthorized");
    exit;
}

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || !validateCsrfToken($csrf_token)) {
        header('Location: user.php?err=invalid_security_token');
        exit;
    }

    $user_id = intval($_POST['user_id'] ?? 0);
    $change_type = strtoupper(trim($_POST['change_type'] ?? 'PROFILE_UPDATE'));
    $requested_by_id = intval($_SESSION['admin_id'] ?? ($_SESSION['manager_id'] ?? 1));

    if ($user_id <= 0) {
        header('Location: user.php?err=missing_target_profile');
        exit;
    }

    // 1. Gather all submitted data for the comprehensive audit log payload
    $audit_payload = [];
    
    if (isset($_POST['first_name'])) $audit_payload['first_name'] = trim($_POST['first_name']);
    if (isset($_POST['surname'])) $audit_payload['surname'] = trim($_POST['surname']);
    if (isset($_POST['phone'])) $audit_payload['phone'] = trim($_POST['phone']);
    if (isset($_POST['email'])) $audit_payload['email'] = trim($_POST['email']);
    if (isset($_POST['gender'])) $audit_payload['gender'] = trim($_POST['gender']);
    if (isset($_POST['dob'])) $audit_payload['dob'] = empty($_POST['dob']) ? null : trim($_POST['dob']);
    if (isset($_POST['cluster_code'])) $audit_payload['cluster_code'] = trim($_POST['cluster_code']);
    if (isset($_POST['host_organization'])) $audit_payload['host_organization'] = trim($_POST['host_organization']);
    if (isset($_POST['state_of_origin'])) $audit_payload['state_of_origin'] = trim($_POST['state_of_origin']);
    if (isset($_POST['lga'])) $audit_payload['lga'] = trim($_POST['lga']);
    if (isset($_POST['approval_status'])) $audit_payload['approval_status'] = trim($_POST['approval_status']);
    if (isset($_POST['expected_remittance_amount'])) $audit_payload['expected_remittance_amount'] = floatval($_POST['expected_remittance_amount']);

    // Non-schema extended parameters captured safely for the JSON tracking metadata
    if (isset($_POST['host_organization_address'])) $audit_payload['host_organization_address'] = trim($_POST['host_organization_address']);
    if (isset($_POST['working_status'])) $audit_payload['working_status'] = trim($_POST['working_status']);
    if (isset($_POST['salary_account_number'])) {
        $audit_payload['salary_account_number'] = empty($_POST['salary_account_number']) ? null : preg_replace('/[^0-9]/', '', $_POST['salary_account_number']);
    }
    if (isset($_POST['salary_bank_name'])) {
        $audit_payload['salary_bank_name'] = empty($_POST['salary_bank_name']) ? null : trim($_POST['salary_bank_name']);
    }

    if (empty($audit_payload)) {
        header('Location: user.php?err=no_changes_submitted');
        exit;
    }

    // 2. Separate strictly valid master database columns for the operational query array
    $valid_db_columns = [
        'first_name', 'surname', 'phone', 'email', 'gender', 'dob', 
        'cluster_code', 'host_organization', 'state_of_origin', 'lga', 
        'approval_status', 'expected_remittance_amount'
    ];

    $db_update_fields = [];
    foreach ($audit_payload as $key => $value) {
        if (in_array($key, $valid_db_columns, true)) {
            $db_update_fields[$key] = $value;
        }
    }

    try {
        $db->beginTransaction();

        $checkUser = $db->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        $checkUser->execute([$user_id]);
        if (!$checkUser->fetch()) {
            $db->rollBack();
            header("Location: user_edit.php?id={$user_id}&err=profile_not_found");
            exit;
        }

        // 3. Conditionally execute dynamic update queries only if true schema columns changed
        if (!empty($db_update_fields)) {
            $fieldsToUpdate = [];
            $executionParams = [];
            foreach ($db_update_fields as $column => $value) {
                $fieldsToUpdate[] = "`{$column}` = ?";
                $executionParams[] = $value;
            }
            $executionParams[] = $user_id;
            
            $updateQuery = "UPDATE users SET " . implode(', ', $fieldsToUpdate) . " WHERE id = ?";
            $applyUpdate = $db->prepare($updateQuery);
            $applyUpdate->execute($executionParams);
        }

        // 4. Log the full payload dataset tracking audit record seamlessly
        $json_payload = json_encode($audit_payload, JSON_THROW_ON_ERROR);
        $stmt = $db->prepare("
            INSERT INTO user_change_requests (user_id, requested_by_id, change_type, proposed_data, status, processed_by, processed_at) 
            VALUES (?, ?, ?, ?, 'APPROVED', ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$user_id, $requested_by_id, $change_type, $json_payload, $requested_by_id]);

        $db->commit();
        header("Location: user_edit.php?id={$user_id}&msg=profile_updated_successfully");
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Direct Change Execution Failure: " . $e->getMessage());
        header("Location: user_edit.php?id={$user_id}&err=queue_processing_fault");
        exit;
    }
}