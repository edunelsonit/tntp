<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/RemittanceManager.php';

class DisputeManager {
    private PDO $db;
    private RemittanceManager $remittanceManager;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->remittanceManager = new RemittanceManager($db);
    }

    /**
     * Submit a new transaction or structural salary dispute parameter log.
     */
    public function submitDispute(int $userId, int $remittanceId, string $disputeType, string $proofPath, string $message): int {
        $allowed = ['NO_SALARY', 'WEBHOOK_FAILED'];
        if (!in_array($disputeType, $allowed, true)) {
            throw new InvalidArgumentException('Invalid dispute type.');
        }

        $graceExpiry = null;
        if ($disputeType === 'NO_SALARY') {
            $graceExpiry = date('Y-m-d', strtotime('+7 days'));
        }

        // Fixed placeholder parameters to map directly to the execute block array sequence
        $stmt = $this->db->prepare(
            'INSERT INTO disputes (userid, remittance_id, dispute_type, proof_receipt_path, dispute_status, dispute_time, admin_notes, grace_period_expiry)
             VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)' 
        );
        
        $stmt->execute([
            $userId, 
            $remittanceId, 
            $disputeType, 
            $proofPath, 
            'PENDING', 
            $message, 
            $graceExpiry
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Set the decision state for an open user dispute.
     */
    public function decideDispute(int $disputeId, string $decision, int $adminId, string $notes = ''): bool {
        $allowed = ['APPROVED_ADJUSTED', 'REJECTED'];
        if (!in_array($decision, $allowed, true)) {
            return false;
        }

        $dispute = $this->getDisputeById($disputeId);
        if (!$dispute) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare(
                'UPDATE disputes SET dispute_status = ?, admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?'
            );
            $update->execute([$decision, $notes, $adminId, $disputeId]);

            if ($decision === 'APPROVED_ADJUSTED') {
                // If a "No Salary" variance window has explicitly run its course, apply balances right away
                if (!empty($dispute['grace_period_expiry']) && date('Y-m-d') >= $dispute['grace_period_expiry']) {
                    $this->applyNoSalaryAdjustment($disputeId);
                }
            }

            if ($decision === 'REJECTED') {
                $this->db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, details, created_at) VALUES (?, ?, ?, NOW())')
                    ->execute([$adminId, 'REJECT_DISPUTE', "Dispute ID $disputeId rejected. Notes: $notes"]);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Batch process expired grace items from user collections via cron engine / system events.
     */
    public function applyPendingNoSalaryAdjustments(): int {
        $stmt = $this->db->query(
            "SELECT d.id FROM disputes d
             JOIN remittance r ON r.id = d.remittance_id
             WHERE d.dispute_status = 'APPROVED_ADJUSTED'
               AND d.grace_period_expiry <= CURDATE()
               AND r.expected_amount > 0"
        );
        $pending = $stmt->fetchAll();
        $count = 0;
        foreach ($pending as $item) {
            if ($this->applyNoSalaryAdjustment((int)$item['id'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Fallback processor to log manual banking overrides safely.
     */
    public function recordManualAdminPayment(int $remittanceId, float $amount, int $adminId, string $txReference): bool {
        $remittance = $this->remittanceManager->getRemittanceById($remittanceId);
        if (!$remittance) {
            return false;
        }

        $txReference = substr($txReference, 0, 90);
        $insert = $this->db->prepare(
            'INSERT INTO payment_history (remittance_id, tx_reference, amount_paid, payment_method, processed_by_admin_id, paid_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );

        $this->db->beginTransaction();
        try {
            try {
                $insert->execute([$remittanceId, $txReference, $amount, 'MANUAL_ADMIN', $adminId]);
            } catch (PDOException $e) {
                // Clean handling for duplicated payload attempts
                if ($e->errorInfo[1] === 1062) {
                    $txReference = substr($txReference . '_' . time(), 0, 100);
                    $insert->execute([$remittanceId, $txReference, $amount, 'MANUAL_ADMIN', $adminId]);
                } else {
                    throw $e;
                }
            }

            $updated = $this->remittanceManager->updateRemittancePayment($remittanceId, $amount);
            $this->db->commit();
            return $updated;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Utility method to query direct dispute entry variables.
     */
    public function getDisputeById(int $disputeId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM disputes WHERE id = ?');
        $stmt->execute([$disputeId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Evaluate and write adjustment actions down into database tables.
     */
    private function applyNoSalaryAdjustment(int $disputeId): bool {
        $dispute = $this->getDisputeById($disputeId);
        if (!$dispute || (string)$dispute['dispute_status'] !== 'APPROVED_ADJUSTED') {
            return false;
        }

        $remittance = $this->remittanceManager->getRemittanceById((int)$dispute['remittance_id']);
        if (!$remittance) {
            return false;
        }

        // Keep database operations completely synchronized under structural rollback rules
        $isAlreadyInTransaction = $this->db->inTransaction();
        if (!$isAlreadyInTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $update = $this->db->prepare('UPDATE remittance SET expected_amount = 0.00, payment_status = ? WHERE id = ?');
            $status = $this->remittanceManager->evaluatePaymentStatus(0.0, (float)$remittance['amount_paid']);
            $update->execute([$status, (int)$remittance['id']]);

            $adminId = (int)($dispute['processed_by'] ?? 0);
            
            $this->db->prepare('INSERT INTO admin_action_logs (admin_id, action_type, details, created_at) VALUES (?, ?, ?, NOW())')
                ->execute([$adminId, 'APPLY_NO_SALARY_ADJUSTMENT', "Adjusted remittance ID {$remittance['id']} for dispute ID $disputeId"]);

            if (!$isAlreadyInTransaction) {
                $this->db->commit();
            }
            return true;
        } catch (Exception $e) {
            if (!$isAlreadyInTransaction) {
                $this->db->rollBack();
            }
            return false;
        }
    }
}