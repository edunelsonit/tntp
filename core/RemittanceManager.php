<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * Handles core remittance bookkeeping logic, database state synchronization,
 * payment evaluations, and fiscal collection loops.
 */
class RemittanceManager {
    private PDO $db;

    /**
     * Dependency injection initialization mapping an active database connection context.
     */
    public function __construct(PDO $db) {
        $this->db = $db;
        // Ensure PDO triggers exceptions to handle transaction failures cleanly
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Spawns user records within active database configurations applying multi-level access rules.
     * Aligned explicitly with the current `users` schema restrictions, ENUM constraints, and banking fields.
     */
    public function createUserWithApproval(array $data, string $createdByRole = 'ADMIN'): int {
        // Enforce fallback to 'ADMIN' if an invalid role is provided to respect the database ENUM constraint
        $roleNormalized = strtoupper(trim($createdByRole));
        if (!in_array($roleNormalized, ['ADMIN', 'SUPER_ADMIN', 'CLUSTER_MANAGER'], true)) {
            $roleNormalized = 'ADMIN';
        }

        // Map the approval status based on the finalized normalized role
        $approvalStatus = $this->mapApprovalStatus($roleNormalized);

        // SQL prepared query built entirely using existing schema columns (including registration salary setup)
        $stmt = $this->db->prepare(
            'INSERT INTO users (
                nin, first_name, surname, other_name, phone, email, 
                gender, dob, salary_account_number, salary_bank_name,
                cluster_code, host_organization, resumption_date, 
                state_of_origin, lga, created_by, expected_remittance_amount, 
                approval_status, monnify_reference, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );

        // Normalize data values and incoming dates safely
        $resumptionDate = !empty($data['resumption_date']) ? $data['resumption_date'] : null;
        $dob = !empty($data['dob']) ? $data['dob'] : null;

        $stmt->execute([
            trim((string)$data['nin']),
            trim((string)$data['first_name']),
            trim((string)$data['surname']),
            !empty($data['other_name']) ? trim((string)$data['other_name']) : null,
            trim((string)$data['phone']),
            trim((string)$data['email']),
            $data['gender'] ?? null,
            $dob,
            !empty($data['salary_account_number']) ? trim((string)$data['salary_account_number']) : null,
            !empty($data['salary_bank_name']) ? trim((string)$data['salary_bank_name']) : null,
            !empty($data['cluster_code']) ? trim((string)$data['cluster_code']) : null,
            !empty($data['host_organization']) ? trim((string)$data['host_organization']) : null,
            $resumptionDate,
            $data['state_of_origin'] ?? null,
            $data['lga'] ?? null,
            $roleNormalized,
            floatval($data['expected_remittance_amount'] ?? 0.00),
            $approvalStatus,
            !empty($data['monnify_reference']) ? trim((string)$data['monnify_reference']) : null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Binds API-generated virtual account parameters provided by Monnify to an existing user record.
     */
    public function attachGeneratedVirtualAccount(int $userId, string $virtualAccount, string $bankName, string $monnifyReference): bool {
        $stmt = $this->db->prepare('
            UPDATE users 
            SET virtual_account = ?, 
                bank_name = ?, 
                monnify_reference = ?,
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ');

        return $stmt->execute([
            trim($virtualAccount),
            trim($bankName), // Maps Monnify provider bank (e.g., "Wema Bank") to your 'bank_name' column
            trim($monnifyReference),
            $userId
        ]);
    }

    /**
     * Resolves and determines member routing rules based on administrative scope weights.
     */
    public function mapApprovalStatus(string $createdByRole): string {
        return 'PENDING';
    }

    /**
     * Builds standard billing rows across target payment cycle indices.
     */
    public function ensureSalaryCycle(string $cyclePeriod, int $declaredById): int {
        $cyclePeriod = strtoupper(trim($cyclePeriod));
        
        $stmt = $this->db->prepare('SELECT id FROM remittance_cycles WHERE cycle_period = ?');
        $stmt->execute([$cyclePeriod]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return (int)$existing['id'];
        }

        $approvedUsers = $this->db->query(
            "SELECT id, expected_remittance_amount FROM users WHERE approval_status = 'APPROVED'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->db->beginTransaction();
        try {
            $insertCycle = $this->db->prepare('INSERT INTO remittance_cycles (cycle_period, declared_by_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
            $insertCycle->execute([$cyclePeriod, $declaredById]);
            $cycleId = (int)$this->db->lastInsertId();

            if (!empty($approvedUsers)) {
                $insertRemit = $this->db->prepare(
                    'INSERT INTO remittance (cycle_id, userid, expected_amount, amount_paid, payment_status, last_updated) 
                     VALUES (?, ?, ?, 0.0, "UNPAID", CURRENT_TIMESTAMP)'
                );

                foreach ($approvedUsers as $user) {
                    try {
                        $insertRemit->execute([
                            $cycleId, 
                            (int)$user['id'], 
                            floatval($user['expected_remittance_amount'])
                        ]);
                    } catch (PDOException $e) {
                        if ($e->errorInfo[1] === 1062) {
                            continue;
                        }
                        throw $e;
                    }
                }
            }

            $this->db->commit();
            return $cycleId;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Quantifies delta deviations across system calculation structures.
     */
    public function calculateBalance(float $expectedAmount, float $amountPaid): float {
        return round($expectedAmount - $amountPaid, 2);
    }

    /**
     * Evaluates transaction statuses for both schema environments dynamically to satisfy ENUM definitions.
     */
    public function evaluateContextualStatus(float $expectedAmount, float $amountPaid, string $context = 'remittance'): string {
        if ($expectedAmount <= 0.0) {
            return 'EXEMPTED';
        }
        if ($amountPaid >= $expectedAmount) {
            // Accounts for remittance context expecting 'FULLY_PAID' and user context expecting 'PAID'
            return ($context === 'remittance') ? 'FULLY_PAID' : 'PAID';
        }
        if ($amountPaid > 0.0) {
            return 'PARTIAL';
        }
        return 'UNPAID';
    }

    /**
     * Increments internal collection records safely inside standard tracking tables.
     */
    public function updateRemittancePayment(int $remittanceId, float $amountToAdd): bool {
        if ($amountToAdd <= 0.0) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            // Lock active rows to safely handle incoming payload spikes without concurrent balance shifts
            $stmt = $this->db->prepare('SELECT amount_paid, expected_amount FROM remittance WHERE id = ? FOR UPDATE');
            $stmt->execute([$remittanceId]);
            $remittance = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$remittance) {
                $this->db->rollBack();
                return false;
            }

            $currentPaid = (float)$remittance['amount_paid'];
            $expected = (float)$remittance['expected_amount'];
            $newPaid = round($currentPaid + $amountToAdd, 2);
            
            // Build separate target statuses depending on the schema definitions
            $remittanceStatus = $this->evaluateContextualStatus($expected, $newPaid, 'remittance');
            $userStatus       = $this->evaluateContextualStatus($expected, $newPaid, 'users');

            $update = $this->db->prepare(
                'UPDATE remittance SET amount_paid = ?, payment_status = ?, last_updated = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $success = $update->execute([$newPaid, $remittanceStatus, $remittanceId]);

            if ($success) {
                $getUserId = $this->db->prepare('SELECT userid FROM remittance WHERE id = ?');
                $getUserId->execute([$remittanceId]);
                $uid = $getUserId->fetchColumn();
                if ($uid) {
                    $updateUser = $this->db->prepare('UPDATE users SET amount_paid = ?, payment_status = ? WHERE id = ?');
                    $updateUser->execute([$newPaid, $userStatus, $uid]);
                }
            }

            $this->db->commit();
            return $success;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Pulls the most current historical ledger line for a specific member index identifier.
     */
    public function getLatestRemittanceForUser(string $nin): ?array {
        $stmt = $this->db->prepare(
            'SELECT r.*, c.cycle_period
             FROM remittance r
             JOIN users u ON u.id = r.userid
             JOIN remittance_cycles c ON c.id = r.cycle_id
             WHERE u.nin = ?
             ORDER BY c.cycle_period DESC
             LIMIT 1'
        );
        $stmt->execute([trim($nin)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Resolves matching structural records inside data schemas via key lookups.
     */
    public function getRemittanceById(int $remittanceId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM remittance WHERE id = ?');
        $stmt->execute([$remittanceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Compiles detailed balance logs across historical timeline sequences.
     */
    public function fetchBalancesForUser(int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT r.*, c.cycle_period,
                    (r.expected_amount - r.amount_paid) AS balance_due
             FROM remittance r
             JOIN remittance_cycles c ON c.id = r.cycle_id
             WHERE r.userid = ?
             ORDER BY c.cycle_period DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}