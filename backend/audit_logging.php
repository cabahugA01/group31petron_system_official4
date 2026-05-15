<?php
/**
 * Audit Logging Functions
 * Provides functions to log system activity to the audit_logs table
 */

/**
 * Log Admin Unlock Action to Activity Logs
 * @param int $admin_id - Admin user ID
 * @param string $table - Table of unlocked record
 * @param int $record_id - ID of unlocked record
 * @param string $reason - Reason for unlock
 * @return bool - Success status
 */
function log_unlock_to_activity_log($admin_id, $table, $record_id, $reason) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs
            (user_id, action, details, ip_address)
            VALUES (?, 'Admin Unlock', ?, ?)
        ");
        $stmt->execute([
            $admin_id,
            sprintf('UNLOCKED %s #%d by Admin - Reason: %s', $table, $record_id, substr($reason, 0, 200)),
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);

        return true;
    } catch (Exception $e) {
        error_log("Failed to log unlock to activity logs: " . $e->getMessage());
        return false;
    }
}

/**
 * Log an action to the audit_logs table
 * @param int $user_id - The user performing the action (null for system)
 * @param string $log_type - Type of log: 'user', 'transaction', 'inventory', 'system'
 * @param string $action_type - Type of action: 'Login', 'Create', 'Update', 'Delete', 'View', etc
 * @param string $action_details - Detailed description of the action
 * @param string $entity_type - Type of entity affected: 'users', 'sales', 'inventory', etc
 * @param int $entity_id - ID of the entity affected
 * @param array $old_values - Previous values (for tracking changes)
 * @param array $new_values - New values (for tracking changes)
 * @param string $status - Result of action: 'Success', 'Failed', 'Pending'
 * @param string $error_message - Error message if status is Failed
 * @return bool - Whether the log was created successfully
 */
function log_audit_action($user_id = null, $log_type = 'system', $action_type = 'View', $action_details = '', $entity_type = null, $entity_id = null, $old_values = null, $new_values = null, $status = 'Success', $error_message = null) {
    global $pdo;
    
    try {
        // Get user ID from session if not provided
        if ($user_id === null && isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
        }
        
        // Get IP address
        $ip_address = get_client_ip();
        
        // Get user agent
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Prepare JSON data
        $old_values_json = $old_values ? json_encode($old_values) : null;
        $new_values_json = $new_values ? json_encode($new_values) : null;
        
        $sql = "INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, old_values, new_values, ip_address, user_agent, status, error_message, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id,
            $log_type,
            $action_type,
            $action_details,
            $entity_type,
            $entity_id,
            $old_values_json,
            $new_values_json,
            $ip_address,
            $user_agent,
            $status,
            $error_message
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to log audit action: " . $e->getMessage());
        return false;
    }
}

/**
 * Get client's IP address
 * @return string - Client IP address
 */
function get_client_ip() {
    $ip = '';
    
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    } else {
        $ip = '0.0.0.0';
    }
    
    return $ip;
}

/**
 * Log user login
 * @param int $user_id - User ID
 * @param string $status - 'Success' or 'Failed'
 * @param string $error_message - Error message if failed
 */
function log_user_login($user_id, $status = 'Success', $error_message = null) {
    $action_details = $status === 'Success' ? 'User logged in successfully' : 'Login attempt failed';
    log_audit_action($user_id, 'user', 'Login', $action_details, 'users', $user_id, null, ['login_time' => date('Y-m-d H:i:s')], $status, $error_message);
}

/**
 * Log user logout
 * @param int $user_id - User ID
 */
function log_user_logout($user_id) {
    log_audit_action($user_id, 'user', 'Logout', 'User logged out', 'users', $user_id, null, ['logout_time' => date('Y-m-d H:i:s')]);
}

/**
 * Log transaction creation
 * @param int $user_id - User ID (cashier/staff)
 * @param int $transaction_id - Transaction ID
 * @param float $amount - Transaction amount
 * @param string $transaction_type - Type of transaction
 * @param array $details - Transaction details
 */
function log_transaction($user_id, $transaction_id, $amount, $transaction_type = 'Sale', $details = []) {
    $action_details = sprintf('%s processed - Amount: ₱%s', $transaction_type, number_format($amount, 2));
    
    $new_values = array_merge($details, [
        'amount' => $amount,
        'type' => $transaction_type,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    log_audit_action($user_id, 'transaction', $transaction_type, $action_details, 'sales', $transaction_id, null, $new_values);
}

/**
 * Log inventory change
 * @param int $user_id - User ID
 * @param int $inventory_id - Inventory item ID
 * @param string $product_name - Product name
 * @param float $old_quantity - Previous quantity
 * @param float $new_quantity - New quantity
 * @param string $action - Action type (Stock In, Stock Out, Stock Adjustment)
 */
function log_inventory_change($user_id, $inventory_id, $product_name, $old_quantity, $new_quantity, $action = 'Stock Adjustment') {
    $difference = $new_quantity - $old_quantity;
    $action_details = sprintf('%s: %s - From %s to %s units', $action, $product_name, number_format($old_quantity, 2), number_format($new_quantity, 2));
    
    $old_values = ['product_name' => $product_name, 'quantity' => $old_quantity];
    $new_values = ['product_name' => $product_name, 'quantity' => $new_quantity, 'difference' => $difference];
    
    log_audit_action($user_id, 'inventory', $action, $action_details, 'inventory', $inventory_id, $old_values, $new_values);
}

/**
 * Log user management action
 * @param int $user_id - Admin user ID
 * @param int $target_user_id - Target user ID
 * @param string $action - Action type (Create, Update, Delete, Status Change)
 * @param string $details - Details of the action
 * @param array $old_values - Previous values
 * @param array $new_values - New values
 */
function log_user_management($user_id, $target_user_id, $action, $details, $old_values = null, $new_values = null) {
    log_audit_action($user_id, 'user', $action, $details, 'users', $target_user_id, $old_values, $new_values);
}

/**
 * Get audit logs with filters
 * @param array $filters - Filter parameters
 * @return array - Array of audit logs
 */
function get_audit_logs($filters = []) {
    global $pdo;
    
    $sql = "SELECT * FROM audit_logs WHERE 1=1";
    $params = [];
    
    // Date range filter
    if (!empty($filters['start_date'])) {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $filters['start_date'];
    }
    
    if (!empty($filters['end_date'])) {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $filters['end_date'];
    }
    
    // Log type filter
    if (!empty($filters['log_type'])) {
        $sql .= " AND log_type = ?";
        $params[] = $filters['log_type'];
    }
    
    // User filter
    if (!empty($filters['user_id'])) {
        $sql .= " AND user_id = ?";
        $params[] = $filters['user_id'];
    }
    
    // Action type filter
    if (!empty($filters['action_type'])) {
        $sql .= " AND action_type = ?";
        $params[] = $filters['action_type'];
    }
    
    // Status filter
    if (!empty($filters['status'])) {
        $sql .= " AND status = ?";
        $params[] = $filters['status'];
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    // Add limit if specified
    if (!empty($filters['limit'])) {
        $sql .= " LIMIT ?";
        $params[] = intval($filters['limit']);
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get audit logs count with filters
 * @param array $filters - Filter parameters
 * @return int - Count of audit logs
 */
function get_audit_logs_count($filters = []) {
    global $pdo;
    
    $sql = "SELECT COUNT(*) as count FROM audit_logs WHERE 1=1";
    $params = [];
    
    // Date range filter
    if (!empty($filters['start_date'])) {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $filters['start_date'];
    }
    
    if (!empty($filters['end_date'])) {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $filters['end_date'];
    }
    
    // Log type filter
    if (!empty($filters['log_type'])) {
        $sql .= " AND log_type = ?";
        $params[] = $filters['log_type'];
    }
    
    // User filter
    if (!empty($filters['user_id'])) {
        $sql .= " AND user_id = ?";
        $params[] = $filters['user_id'];
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['count'] ?? 0;
}

/**
 * Log Admin Unlock Operation
 * @param int $admin_id - Admin user ID
 * @param string $table - Table containing unlocked record
 * @param int $record_id - ID of unlocked record
 * @param string $reason - Reason for unlock
 * @return bool - Success status
 */
function log_admin_unlock($admin_id, $table, $record_id, $reason) {
    global $pdo;

    try {
        $sql = "INSERT INTO admin_unlocks (table_name, record_id, unlocked_by, unlock_reason, ip_address) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $table,
            $record_id,
            $admin_id,
            substr($reason, 0, 500),
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);

        return true;
    } catch (Exception $e) {
        error_log("Failed to log admin unlock: " . $e->getMessage());
        return false;
    }
}

/**
 * Clear old audit logs (older than specified days)
 * @param int $days - Number of days to keep
 * @return bool - Success status
 */
function cleanup_old_audit_logs($days = 90) {
    global $pdo;
    
    try {
        $sql = "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$days]);
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to cleanup audit logs: " . $e->getMessage());
        return false;
    }
}
