<?php
/**
 * Admin Unlock Operations Class
 * Handles all admin unlock operations with proper audit trail
 * Uses the unlock_history table for comprehensive logging
 */

class AdminUnlockOperations {
    private $pdo;
    private $me;
    private $station_id;
    
    public function __construct($pdo, $me, $station_id) {
        $this->pdo = $pdo;
        $this->me = $me;
        $this->station_id = $station_id;
        $this->me['role'] = role_key($me['role'] ?? 'staff');
    }
    
    /**
     * Verify admin password
     */
    private function verifyPassword($password) {
        $stmt = $this->pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$this->me['id']]);
        $hash = $stmt->fetchColumn();
        return password_verify($password, $hash);
    }
    
    /**
     * Log unlock action to unlock_history table
     */
    private function logUnlock($record_type, $record_id, $reason, $description = null) {
        try {
            $sql = "INSERT INTO unlock_history (
                admin_id, 
                admin_name, 
                admin_role, 
                record_type, 
                record_id, 
                record_description, 
                reason, 
                station_id,
                ip_address,
                session_id,
                password_verified,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'success')";
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $session_id = session_id();
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $this->me['id'],
                $this->me['name'] ?? 'Unknown',
                $this->me['role'],
                $record_type,
                $record_id,
                $description,
                $reason,
                $this->station_id,
                $ip,
                $session_id
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to log unlock: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get record description for audit log
     */
    private function getRecordDescription($table, $record_id) {
        try {
            switch ($table) {
                case 'fuel_reconciliation':
                    $stmt = $this->pdo->prepare("SELECT CONCAT('Fuel reconciliation - ', DATE(reconciliation_date)) as desc FROM fuel_reconciliation WHERE id = ?");
                    break;
                case 'shift_reports':
                    $stmt = $this->pdo->prepare("SELECT CONCAT('Shift report - ', DATE(shift_date)) as desc FROM shift_reports WHERE id = ?");
                    break;
                case 'job_orders':
                    $stmt = $this->pdo->prepare("SELECT CONCAT('Job order #', id, ' - ', service_type) as desc FROM job_orders WHERE id = ?");
                    break;
                default:
                    return "Record #{$record_id} in {$table}";
            }
            
            $stmt->execute([$record_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['desc'] ?? "Record #{$record_id} in {$table}";
        } catch (Exception $e) {
            return "Record #{$record_id} in {$table}";
        }
    }
    
    /**
     * Unlock fuel reconciliation
     */
    public function unlockFuelReconciliation($id, $password, $reason) {
        // Verify password
        if (!$this->verifyPassword($password)) {
            return [
                'success' => false,
                'error' => 'Invalid password'
            ];
        }
        
        // Verify record exists and is finalized
        $stmt = $this->pdo->prepare("SELECT id, status FROM fuel_reconciliation WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record) {
            return [
                'success' => false,
                'error' => 'Record not found'
            ];
        }
        
        if ($record['status'] !== 'finalized') {
            return [
                'success' => false,
                'error' => 'Record is not finalized'
            ];
        }
        
        // Check station access (if not superadmin)
        if ($this->me['role'] !== 'superadmin') {
            $stmt = $this->pdo->prepare("SELECT station_id FROM fuel_reconciliation WHERE id = ?");
            $stmt->execute([$id]);
            $record_station = $stmt->fetchColumn();
            
            if ($record_station != $this->station_id) {
                return [
                    'success' => false,
                    'error' => 'Access denied. You can only unlock records from your station.'
                ];
            }
        }
        
        // Unlock the record
        $stmt = $this->pdo->prepare("UPDATE fuel_reconciliation SET status = 'active', finalized_by = NULL, finalized_at = NULL WHERE id = ?");
        $stmt->execute([$id]);
        
        // Log the unlock
        $description = $this->getRecordDescription('fuel_reconciliation', $id);
        $this->logUnlock('fuel_reconciliation', $id, $reason, $description);
        
        return [
            'success' => true,
            'message' => 'Fuel reconciliation record unlocked successfully'
        ];
    }
    
    /**
     * Unlock shift report
     */
    public function unlockShiftReport($id, $password, $reason) {
        // Verify password
        if (!$this->verifyPassword($password)) {
            return [
                'success' => false,
                'error' => 'Invalid password'
            ];
        }
        
        // Verify record exists and is finalized
        $stmt = $this->pdo->prepare("SELECT id, status FROM shift_reports WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record) {
            return [
                'success' => false,
                'error' => 'Record not found'
            ];
        }
        
        if ($record['status'] !== 'finalized') {
            return [
                'success' => false,
                'error' => 'Record is not finalized'
            ];
        }
        
        // Check station access (if not superadmin)
        if ($this->me['role'] !== 'superadmin') {
            $stmt = $this->pdo->prepare("SELECT station_id FROM shift_reports WHERE id = ?");
            $stmt->execute([$id]);
            $record_station = $stmt->fetchColumn();
            
            if ($record_station != $this->station_id) {
                return [
                    'success' => false,
                    'error' => 'Access denied. You can only unlock records from your station.'
                ];
            }
        }
        
        // Unlock the record
        $stmt = $this->pdo->prepare("UPDATE shift_reports SET status = 'active', finalized_by = NULL, finalized_at = NULL WHERE id = ?");
        $stmt->execute([$id]);
        
        // Log the unlock
        $description = $this->getRecordDescription('shift_reports', $id);
        $this->logUnlock('shift_reports', $id, $reason, $description);
        
        return [
            'success' => true,
            'message' => 'Shift report unlocked successfully'
        ];
    }
    
    /**
     * Unlock job order
     */
    public function unlockJobOrder($id, $password, $reason) {
        // Verify password
        if (!$this->verifyPassword($password)) {
            return [
                'success' => false,
                'error' => 'Invalid password'
            ];
        }
        
        // Verify record exists and is finalized
        $stmt = $this->pdo->prepare("SELECT id, status FROM job_orders WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record) {
            return [
                'success' => false,
                'error' => 'Record not found'
            ];
        }
        
        if ($record['status'] !== 'finalized') {
            return [
                'success' => false,
                'error' => 'Record is not finalized'
            ];
        }
        
        // Check station access (if not superadmin)
        if ($this->me['role'] !== 'superadmin') {
            $stmt = $this->pdo->prepare("SELECT station_id FROM job_orders WHERE id = ?");
            $stmt->execute([$id]);
            $record_station = $stmt->fetchColumn();
            
            if ($record_station != $this->station_id) {
                return [
                    'success' => false,
                    'error' => 'Access denied. You can only unlock records from your station.'
                ];
            }
        }
        
        // Unlock the record
        $stmt = $this->pdo->prepare("UPDATE job_orders SET status = 'active', finalized_by = NULL, finalized_at = NULL WHERE id = ?");
        $stmt->execute([$id]);
        
        // Log the unlock
        $description = $this->getRecordDescription('job_orders', $id);
        $this->logUnlock('job_orders', $id, $reason, $description);
        
        return [
            'success' => true,
            'message' => 'Job order unlocked successfully'
        ];
    }
    
    /**
     * Get unlock history for a specific record
     */
    public function getUnlockHistory($table, $record_id) {
        try {
            $sql = "SELECT 
                uh.*,
                u.username as admin_username
            FROM unlock_history uh
            LEFT JOIN users u ON uh.admin_id = u.id
            WHERE uh.record_type = ? AND uh.record_id = ?
            ORDER BY uh.unlock_date DESC
            LIMIT 50";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$table, $record_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get unlock history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get locked records for a specific table
     */
    public function getLockedRecords($table) {
        try {
            // Map table to appropriate query
            $queries = [
                'fuel_reconciliation' => "SELECT id, 'finalized' as lock_status FROM fuel_reconciliation WHERE status = 'finalized'",
                'shift_reports' => "SELECT id, 'finalized' as lock_status FROM shift_reports WHERE status = 'finalized'",
                'job_orders' => "SELECT id, 'finalized' as lock_status FROM job_orders WHERE status = 'finalized'"
            ];
            
            if (!isset($queries[$table])) {
                return [];
            }
            
            $sql = $queries[$table];
            
            // Add station filter for non-superadmins
            if ($this->me['role'] !== 'superadmin') {
                $sql .= " AND station_id = " . (int)$this->station_id;
            }
            
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get locked records: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all recent unlocks (Super Admin only)
     */
    public function getAllRecentUnlocks($limit = 50) {
        try {
            $sql = "SELECT 
                uh.*,
                u.username as admin_username
            FROM unlock_history uh
            LEFT JOIN users u ON uh.admin_id = u.id
            ORDER BY uh.unlock_date DESC
            LIMIT ?";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get all unlocks: " . $e->getMessage());
            return [];
        }
    }
}
?>
