<?php
/**
 * Enhanced Audit Logging System for Fuel Module
 * Comprehensive audit trail for all fuel-related activities
 * Tracks user actions, timestamps, and system changes
 */

require_once __DIR__ . '/../../public/db_connect.php';

/**
 * Enhanced logging function for fuel module activities
 */
function log_fuel_activity($pdo, $user_id, $action, $details = '', $station_id = null, $metadata = []) {
    try {
        // Get station ID if not provided
        if (!$station_id) {
            $stmt = $pdo->prepare("SELECT station_id FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $station_id = $stmt->fetchColumn();
        }
        
        // Prepare metadata as JSON
        $metadata_json = !empty($metadata) ? json_encode($metadata) : null;
        
        // Insert into audit_log
        $stmt = $pdo->prepare("INSERT INTO audit_log 
            (user_id, station_id, action, details, metadata, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        
        $stmt->execute([
            $user_id,
            $station_id,
            $action,
            $details,
            $metadata_json,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        return $pdo->lastInsertId();
        
    } catch (Exception $e) {
        error_log("Audit logging failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log fuel reading encoding
 */
function log_fuel_reading_encoding($pdo, $user_id, $reading_data) {
    $details = "Encoded fuel reading for Pump {$reading_data['pump_number']} ({$reading_data['shift_period']} shift): " .
               "{$reading_data['present_reading']} - {$reading_data['previous_reading']} - " .
               "{$reading_data['calibration']} @ ₱{$reading_data['price_per_liter']}/L";
    
    $metadata = [
        'pump_id' => $reading_data['pump_id'],
        'fuel_type_id' => $reading_data['fuel_type_id'],
        'present_reading' => $reading_data['present_reading'],
        'previous_reading' => $reading_data['previous_reading'],
        'calibration' => $reading_data['calibration'],
        'price_per_liter' => $reading_data['price_per_liter'],
        'computed_liters' => $reading_data['liters_sold'],
        'computed_amount' => $reading_data['amount'],
        'reading_date' => $reading_data['reading_date'],
        'shift_period' => $reading_data['shift_period'],
        'validation_passed' => $reading_data['validation_passed'] ?? true,
        'user_role' => $reading_data['user_role'] ?? 'staff'
    ];
    
    return log_fuel_activity($pdo, $user_id, 'Fuel Reading Encoded', $details, null, $metadata);
}

/**
 * Log fuel reading verification
 */
function log_fuel_reading_verification($pdo, $user_id, $verification_data) {
    $action = $verification_data['action'] === 'approve' ? 'Fuel Reading Approved' : 'Fuel Reading Rejected';
    $details = "{$verification_data['action']} reading ID {$verification_data['reading_id']} for " .
               "Pump {$verification_data['pump_number']} ({$verification_data['fuel_type']})";
    
    if (!empty($verification_data['notes'])) {
        $details .= " - Notes: {$verification_data['notes']}";
    }
    
    $metadata = [
        'reading_id' => $verification_data['reading_id'],
        'pump_id' => $verification_data['pump_id'],
        'action' => $verification_data['action'],
        'verification_method' => $verification_data['verification_method'] ?? 'individual',
        'manager_password_used' => true
    ];
    
    return log_fuel_activity($pdo, $user_id, $action, $details, null, $metadata);
}

/**
 * Log bulk verification
 */
function log_bulk_verification($pdo, $user_id, $bulk_data) {
    $details = "Bulk {$bulk_data['action']} for {$bulk_data['count']} readings";
    
    $metadata = [
        'action' => $bulk_data['action'],
        'reading_count' => $bulk_data['count'],
        'reading_ids' => $bulk_data['reading_ids'],
        'verification_method' => 'bulk',
        'manager_password_used' => true
    ];
    
    return log_fuel_activity($pdo, $user_id, "Bulk Fuel Reading {$bulk_data['action']}", $details, null, $metadata);
}

/**
 * Log purchase order creation
 */
function log_purchase_order_creation($pdo, $user_id, $po_data) {
    $details = "Created PO {$po_data['po_number']}: {$po_data['volume']}L {$po_data['fuel_type']} " .
               "from Supplier {$po_data['supplier_name']} @ ₱{$po_data['unit_price']}/L";
    
    $metadata = [
        'po_id' => $po_data['po_id'],
        'po_number' => $po_data['po_number'],
        'fuel_type_id' => $po_data['fuel_type_id'],
        'fuel_type' => $po_data['fuel_type'],
        'volume' => $po_data['volume'],
        'unit_price' => $po_data['unit_price'],
        'total_amount' => $po_data['total_amount'],
        'supplier_id' => $po_data['supplier_id'],
        'supplier_name' => $po_data['supplier_name'],
        'expected_delivery_date' => $po_data['expected_delivery_date']
    ];
    
    return log_fuel_activity($pdo, $user_id, 'Fuel PO Created', $details, null, $metadata);
}

/**
 * Log delivery confirmation
 */
function log_delivery_confirmation($pdo, $user_id, $delivery_data) {
    $details = "Confirmed delivery for PO {$delivery_data['po_number']}: " .
               "{$delivery_data['actual_volume']}L {$delivery_data['fuel_type']} delivered";
    
    if ($delivery_data['actual_volume'] != $delivery_data['expected_volume']) {
        $details .= " (Expected: {$delivery_data['expected_volume']}L)";
    }
    
    $metadata = [
        'po_id' => $delivery_data['po_id'],
        'po_number' => $delivery_data['po_number'],
        'fuel_type_id' => $delivery_data['fuel_type_id'],
        'fuel_type' => $delivery_data['fuel_type'],
        'expected_volume' => $delivery_data['expected_volume'],
        'actual_volume' => $delivery_data['actual_volume'],
        'volume_variance' => $delivery_data['actual_volume'] - $delivery_data['expected_volume'],
        'delivery_notes' => $delivery_data['delivery_notes'] ?? null
    ];
    
    return log_fuel_activity($pdo, $user_id, 'Fuel Delivery Confirmed', $details, null, $metadata);
}

/**
 * Log inventory update
 */
function log_inventory_update($pdo, $user_id, $inventory_data) {
    $action = $inventory_data['action'] ?? 'update';
    $details = "Inventory {$action}: {$inventory_data['fuel_type']} stock " .
               ($inventory_data['action'] === 'deduct' ? 'decreased' : 'increased') . 
               " by {$inventory_data['volume_change']}L";
    
    $metadata = [
        'fuel_type_id' => $inventory_data['fuel_type_id'],
        'fuel_type' => $inventory_data['fuel_type'],
        'action' => $inventory_data['action'],
        'volume_change' => $inventory_data['volume_change'],
        'previous_stock' => $inventory_data['previous_stock'] ?? null,
        'new_stock' => $inventory_data['new_stock'] ?? null,
        'reason' => $inventory_data['reason'] ?? 'fuel_sales'
    ];
    
    return log_fuel_activity($pdo, $user_id, 'Fuel Inventory Updated', $details, null, $metadata);
}

/**
 * Log low stock alert
 */
function log_low_stock_alert($pdo, $station_id, $alert_data) {
    $details = "Low stock alert for {$alert_data['fuel_type']}: {$alert_data['current_stock']}L remaining";
    
    $metadata = [
        'fuel_type_id' => $alert_data['fuel_type_id'],
        'fuel_type' => $alert_data['fuel_type'],
        'current_stock' => $alert_data['current_stock'],
        'alert_level' => $alert_data['alert_level'],
        'threshold' => $alert_data['threshold'] ?? 500
    ];
    
    return log_fuel_activity($pdo, null, 'Low Stock Alert', $details, $station_id, $metadata);
}

/**
 * Log validation failure
 */
function log_validation_failure($pdo, $user_id, $validation_data) {
    $details = "Reading validation failed for Pump {$validation_data['pump_number']}: " .
               implode(', ', $validation_data['errors']);
    
    $metadata = [
        'pump_id' => $validation_data['pump_id'],
        'pump_number' => $validation_data['pump_number'],
        'present_reading' => $validation_data['present_reading'],
        'previous_reading' => $validation_data['previous_reading'],
        'calibration' => $validation_data['calibration'],
        'validation_errors' => $validation_data['errors'],
        'error_count' => count($validation_data['errors'])
    ];
    
    return log_fuel_activity($pdo, $user_id, 'Reading Validation Failed', $details, null, $metadata);
}

/**
 * Log RBAC violation
 */
function log_rbac_violation($pdo, $user_id, $violation_data) {
    $details = "RBAC violation: {$violation_data['violation_type']} - {$violation_data['description']}";
    
    $metadata = [
        'violation_type' => $violation_data['violation_type'],
        'user_role' => $violation_data['user_role'],
        'required_role' => $violation_data['required_role'] ?? null,
        'attempted_action' => $violation_data['attempted_action'],
        'resource' => $violation_data['resource'] ?? null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ];
    
    return log_fuel_activity($pdo, $user_id, 'RBAC Violation', $details, null, $metadata);
}

/**
 * Get comprehensive audit trail
 */
function get_fuel_audit_trail($pdo, $filters = []) {
    $where_conditions = [];
    $params = [];
    
    // Base query for fuel-related activities
    $sql = "SELECT al.*, u.name as user_name, u.role as user_role, 
        s.name as station_name, s.location
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.id
        LEFT JOIN stations s ON al.station_id = s.id
        WHERE (al.action LIKE '%Fuel%' OR al.action LIKE '%Reading%' OR 
               al.action LIKE '%PO%' OR al.action LIKE '%Inventory%' OR 
               al.action LIKE '%Alert%' OR al.action LIKE '%RBAC%')";
    
    // Apply filters
    if (!empty($filters['station_id'])) {
        $where_conditions[] = "al.station_id = ?";
        $params[] = $filters['station_id'];
    }
    
    if (!empty($filters['user_id'])) {
        $where_conditions[] = "al.user_id = ?";
        $params[] = $filters['user_id'];
    }
    
    if (!empty($filters['action_type'])) {
        $where_conditions[] = "al.action LIKE ?";
        $params[] = "%{$filters['action_type']}%";
    }
    
    if (!empty($filters['date_from'])) {
        $where_conditions[] = "DATE(al.created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $where_conditions[] = "DATE(al.created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    
    if (!empty($where_conditions)) {
        $sql .= " AND " . implode(" AND ", $where_conditions);
    }
    
    $sql .= " ORDER BY al.created_at DESC";
    
    if (!empty($filters['limit'])) {
        $sql .= " LIMIT ?";
        $params[] = intval($filters['limit']);
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Generate audit summary report
 */
function generate_audit_summary($pdo, $date_range = '7days') {
    $date_condition = getDateConditionForAudit($date_range);
    
    $summary_sql = "SELECT 
        action,
        COUNT(*) as count,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT station_id) as unique_stations,
        MAX(created_at) as last_occurrence
        FROM audit_log 
        WHERE (action LIKE '%Fuel%' OR action LIKE '%Reading%' OR action LIKE '%PO%' OR action LIKE '%Inventory%')
        $date_condition
        GROUP BY action
        ORDER BY count DESC";
    
    $stmt = $pdo->query($summary_sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get date condition for audit queries
 */
function getDateConditionForAudit($range) {
    switch ($range) {
        case 'today':
            return " AND DATE(created_at) = CURDATE()";
        case 'yesterday':
            return " AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        case '7days':
            return " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        case '30days':
            return " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        case '90days':
            return " AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
        default:
            return " AND DATE(created_at) = CURDATE()";
    }
}

/**
 * Create audit log table if not exists (for setup)
 */
function ensure_audit_table_exists($pdo) {
    $create_sql = "CREATE TABLE IF NOT EXISTS audit_log (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) DEFAULT NULL,
        station_id int(11) DEFAULT NULL,
        action varchar(255) NOT NULL,
        details text DEFAULT NULL,
        metadata json DEFAULT NULL,
        ip_address varchar(45) DEFAULT NULL,
        user_agent text DEFAULT NULL,
        created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_station_id (station_id),
        KEY idx_action (action),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $pdo->exec($create_sql);
}
?>
