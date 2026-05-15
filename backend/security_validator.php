<?php
/**
 * Security & Validation Enforcement Layer
 * Implements strict RBAC, soft delete policy, and audit logging
 */

require_once __DIR__ . '/lib.php';

class SecurityValidator {
    
    private $pdo;
    private $user;
    private $station_id;
    
    public function __construct($pdo, $user, $station_id) {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->station_id = $station_id;
    }
    
    /**
     * Enforce RBAC on Operation
     * Throws exception if user lacks required role
     */
    public function enforceRole($required_roles) {
        $user_role = role_key($this->user['role'] ?? 'staff');
        
        if (!is_array($required_roles)) {
            $required_roles = [$required_roles];
        }
        
        if (!in_array($user_role, $required_roles)) {
            throw new Exception(
                sprintf('Insufficient permissions. Required: %s, Your role: %s',
                    implode(' or ', $required_roles),
                    $user_role
                )
            );
        }
    }
    
    /**
     * Verify Password (for sensitive operations)
     * Used when admin needs to confirm with manager password
     */
    public function verifyPassword($password, $target_user_id = null) {
        $target_id = $target_user_id ?: $this->user['id'];
        
        $stmt = $this->pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$target_id]);
        $stored_hash = $stmt->fetchColumn();
        
        if (!$stored_hash) {
            throw new Exception('User not found');
        }
        
        if (!password_verify($password, $stored_hash)) {
            throw new Exception('Invalid password');
        }
        
        return true;
    }
    
    /**
     * Check Edit Permission on Resource
     * Staff cannot edit after manager approval
     * Nobody can edit finalized records
     */
    public function canEdit($resource_table, $resource_id) {
        $user_role = role_key($this->user['role'] ?? 'staff');
        
        // Query resource status
        $stmt = $this->pdo->prepare("
            SELECT status, staff_editable, billing_locked, is_locked 
            FROM {$resource_table}
            WHERE id = ? AND station_id = ?
        ");
        $stmt->execute([$resource_id, $this->station_id]);
        $resource = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$resource) {
            throw new Exception('Resource not found');
        }
        
        // Nobody edits locked/finalized records
        if ($resource['is_locked'] || $resource['billing_locked']) {
            throw new Exception('This record is finalized and cannot be edited');
        }
        
        // Staff cannot edit after approval
        if ($user_role === 'staff' && !$resource['staff_editable']) {
            throw new Exception('Staff cannot edit this record after manager approval');
        }
        
        return true;
    }
    
    /**
     * Soft Delete Record
     * Mark as deleted instead of permanent removal
     */
    public function softDelete($table, $resource_id) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE {$table}
                SET is_deleted = 1,
                    deleted_at = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$resource_id, $this->station_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Resource not found or already deleted');
            }
            
            log_activity(
                $this->pdo,
                $this->user['id'],
                'Resource Soft Deleted',
                sprintf('%s ID %d marked as deleted', $table, $resource_id)
            );
            
            return true;
            
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Check Inventory Sufficiency
     * Before deducting, verify stock is available (handles fuel and merchandise)
     */
    public function checkInventorySufficiency($product_id, $quantity) {
        // Get product type to determine correct inventory table
        $typeStmt = $this->pdo->prepare("SELECT type_id FROM products WHERE id = ?");
        $typeStmt->execute([$product_id]);
        $product = $typeStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        // Check appropriate inventory table based on product type
        if ($product['type_id'] == 1) {
            // Fuel - check fuel_inventory
            $stmt = $this->pdo->prepare("
                SELECT stock_level FROM fuel_inventory
                WHERE station_id = ? AND product_id = ?
            ");
        } else {
            // Merchandise - check station_inventory
            $stmt = $this->pdo->prepare("
                SELECT stock_level FROM station_inventory
                WHERE station_id = ? AND product_id = ?
            ");
        }
        
        $stmt->execute([$this->station_id, $product_id]);
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$inventory) {
            throw new Exception('Product not found in inventory');
        }
        
        if ($inventory['stock_level'] < $quantity) {
            throw new Exception(
                sprintf('Insufficient inventory. Need %d but only %.2f available',
                    $quantity,
                    $inventory['stock_level']
                )
            );
        }
        
        return true;
    }
    
    /**
     * Verify Station Access
     * User must belong to accessed station (unless Super Admin)
     */
    public function verifyStationAccess($target_station_id) {
        $user_role = role_key($this->user['role'] ?? 'staff');
        
        // Super Admin can access any station
        if ($user_role === 'superadmin') {
            return true;
        }
        
        // Others must belong to the station
        if ($this->user['station_id'] != $target_station_id) {
            throw new Exception('Access denied to this station');
        }
        
        return true;
    }
    
    /**
     * Validate Numeric Range
     */
    public function validateRange($value, $min, $max, $field_name) {
        if ($value < $min || $value > $max) {
            throw new Exception(
                sprintf('%s must be between %.2f and %.2f', $field_name, $min, $max)
            );
        }
    }
    
    /**
     * Validate Required Fields
     */
    public function validateRequired($data, $required_fields) {
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }
    }
    
    /**
     * Get Active Records (Exclude soft-deleted)
     */
    public function getActive($table, $where = [], $order_by = 'id DESC') {
        $sql = "SELECT * FROM {$table} WHERE is_deleted = 0";
        $params = [];
        
        foreach ($where as $field => $value) {
            $sql .= " AND {$field} = ?";
            $params[] = $value;
        }
        
        $sql .= " ORDER BY {$order_by}";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Admin Unlock Record
     * Admin (Owner) can unlock finalized records with password + reason
     * Creates full audit trail of unlock operation
     */
    public function adminUnlockRecord($table, $record_id, $password, $reason) {
        $user_role = role_key($this->user['role'] ?? 'staff');
        
        // Only Admin can unlock records
        if ($user_role !== 'admin' && $user_role !== 'superadmin') {
            throw new Exception('Only Admin can unlock finalized records');
        }
        
        // Verify admin password
        $this->verifyPassword($password, $this->user['id']);
        
        // Require reason
        if (empty(trim($reason))) {
            throw new Exception('Reason is required to unlock a record');
        }
        
        if (strlen(trim($reason)) < 10) {
            throw new Exception('Reason must be at least 10 characters long');
        }
        
        // Query resource status
        $stmt = $this->pdo->prepare("
            SELECT status, is_locked, finalized_by, finalized_at
            FROM {$table}
            WHERE id = ?
        ");
        $stmt->execute([$record_id]);
        $resource = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$resource) {
            throw new Exception('Record not found');
        }
        
        // Check if record is locked
        if (!$resource['is_locked']) {
            throw new Exception('This record is not locked');
        }
        
        try {
            $this->pdo->beginTransaction();
            
            // Unlock the record
            $update_stmt = $this->pdo->prepare("
                UPDATE {$table}
                SET is_locked = 0,
                    override_reason = ?,
                    override_by = ?,
                    override_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([$reason, $this->user['id'], $record_id]);
            
            // Log to admin_unlocks table
            $unlock_stmt = $this->pdo->prepare("
                INSERT INTO admin_unlocks
                (table_name, record_id, unlocked_by, unlock_reason, previous_status, password_verified, ip_address, unlocked_at)
                VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
            ");
            $unlock_stmt->execute([
                $table,
                $record_id,
                $this->user['id'],
                $reason,
                $resource['status'],
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
            
            // Log to activity_logs
            log_activity(
                $this->pdo,
                $this->user['id'],
                'Admin Unlock',
                sprintf('UNLOCKED %s #ID: %d | Reason: %s', $table, $record_id, substr($reason, 0, 100))
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Record unlocked successfully',
                'table' => $table,
                'record_id' => $record_id,
                'unlocked_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Audit Trail Entry
     * Log all critical operations
     */
    public function auditLog($action, $resource_type, $resource_id, $details = null) {
        try {
            $role = role_key($this->user['role'] ?? 'staff');
            
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_log
                (user_id, user_role, action, resource_type, resource_id, details, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->user['id'],
                $role,
                $action,
                $resource_type,
                $resource_id,
                $details
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Audit log failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check Workflow Status Progression
     * Ensure status transitions are valid
     */
    public function validateStatusTransition($current_status, $new_status, $allowed_transitions) {
        if (!isset($allowed_transitions[$current_status])) {
            throw new Exception("Invalid current status: {$current_status}");
        }
        
        if (!in_array($new_status, $allowed_transitions[$current_status])) {
            throw new Exception(
                sprintf("Cannot transition from %s to %s", $current_status, $new_status)
            );
        }
        
        return true;
    }
}

// Define valid workflow transitions
const JOB_ORDER_TRANSITIONS = [
    'Pending' => ['Approved', 'Rejected'],
    'Approved' => ['In Progress', 'Rejected'],
    'Rejected' => ['Pending'],
    'In Progress' => ['Completed'],
    'Completed' => ['Archived'],
    'Archived' => []
];

const REPORT_TRANSITIONS = [
    'Pending Verification' => ['Verified', 'Rejected'],
    'Verified' => ['Finalized'],
    'Rejected' => ['Pending Verification'],
    'Finalized' => ['Archived'],
    'Archived' => []
];

const RECEIPT_TRANSITIONS = [
    'Pending Confirmation' => ['Confirmed', 'Rejected'],
    'Confirmed' => ['Archived'],
    'Rejected' => ['Pending Confirmation'],
    'Archived' => []
];
