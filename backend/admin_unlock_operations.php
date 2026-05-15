<?php
/**
 * Admin Unlock Operations
 * Handles Admin (Owner) override capability for unlocking finalized records
 * 100% Hierarchy Compliance: Admin can unlock but not modify operational data
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/security_validator.php';

class AdminUnlockOperations {
    
    private $pdo;
    private $user;
    private $station_id;
    private $security_validator;
    
    public function __construct($pdo, $user, $station_id) {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->station_id = $station_id;
        $this->security_validator = new SecurityValidator($pdo, $user, $station_id);
    }
    
    /**
     * Unlock Fuel Reconciliation Record
     */
    public function unlockFuelReconciliation($id, $password, $reason) {
        $role = role_key($this->user['role'] ?? 'staff');
        
        if (!in_array($role, ['admin', 'superadmin'])) {
            throw new Exception('Only Admin can unlock fuel reconciliation records');
        }
        
        try {
            $result = $this->security_validator->adminUnlockRecord(
                'fuel_reconciliation', 
                $id, 
                $password, 
                $reason
            );
            
            return $result;
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Unlock Shift Report Record
     */
    public function unlockShiftReport($id, $password, $reason) {
        $role = role_key($this->user['role'] ?? 'staff');
        
        if (!in_array($role, ['admin', 'superadmin'])) {
            throw new Exception('Only Admin can unlock shift report records');
        }
        
        try {
            $result = $this->security_validator->adminUnlockRecord(
                'shift_reports', 
                $id, 
                $password, 
                $reason
            );
            
            return $result;
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Unlock Job Order Record
     */
    public function unlockJobOrder($id, $password, $reason) {
        $role = role_key($this->user['role'] ?? 'staff');
        
        if (!in_array($role, ['admin', 'superadmin'])) {
            throw new Exception('Only Admin can unlock job order records');
        }
        
        try {
            $result = $this->security_validator->adminUnlockRecord(
                'job_orders', 
                $id, 
                $password, 
                $reason
            );
            
            return $result;
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get Unlock History for a Record
     */
    public function getUnlockHistory($table, $record_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT au.*, u.name as admin_name, u.username as admin_username
                FROM admin_unlocks au
                LEFT JOIN users u ON au.unlocked_by = u.id
                WHERE au.table_name = ? AND au.record_id = ?
                ORDER BY au.unlocked_at DESC
            ");
            $stmt->execute([$table, $record_id]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get All Recent Unlocks (Admin Dashboard)
     */
    public function getAllRecentUnlocks($limit = 50) {
        $role = role_key($this->user['role'] ?? 'staff');
        
        if ($role !== 'superadmin') {
            throw new Exception('Only Super Admin can view all unlock history');
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT au.*, u.name as admin_name, u.username as admin_username, s.name as station_name
                FROM admin_unlocks au
                LEFT JOIN users u ON au.unlocked_by = u.id
                LEFT JOIN users u2 ON au.record_id IN (
                    SELECT id FROM fuel_reconciliation UNION
                    SELECT id FROM shift_reports UNION
                    SELECT id FROM job_orders
                ) AND u2.id = au.record_id
                LEFT JOIN stations s ON u2.station_id = s.id
                ORDER BY au.unlocked_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get Locked Records (Admin can see what can be unlocked)
     */
    public function getLockedRecords($table) {
        $role = role_key($this->user['role'] ?? 'staff');
        
        if (!in_array($role, ['admin', 'superadmin'])) {
            throw new Exception('Unauthorized');
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM {$table}
                WHERE is_locked = 1
                AND station_id = ?
                ORDER BY finalized_at DESC
            ");
            $stmt->execute([$this->station_id]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
}
