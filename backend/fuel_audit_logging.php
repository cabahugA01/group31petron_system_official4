<?php
/**
 * FUEL INVENTORY AUDIT LOGGING
 * 
 * Provides comprehensive audit trail functions for fuel inventory operations
 * Integrates with existing activity_logs and fuel_inventory_logs
 * Ensures immutability and complete traceability
 */

/**
 * Log a fuel inventory action to both activity_logs and audit_logs
 * This ensures dual logging for complete audit trail
 * 
 * @param PDO $pdo Database connection
 * @param int $user_id User performing the action
 * @param string $action_type Type of action (delivery_recorded, delivery_verified, etc.)
 * @param string $reference_type fuel_delivery, fuel_daily_reading, or fuel_adjustment
 * @param int $reference_id ID of the source transaction
 * @param int $station_id Station ID
 * @param int $product_id Fuel product ID (nullable)
 * @param array $details Additional details
 */
function log_fuel_inventory_action($pdo, $user_id, $action_type, $reference_type, $reference_id, $station_id, $product_id, $details = []) {
    try {
        // Build activity log entry
        $action_label = str_replace('_', ' ', ucwords($action_type, '_'));
        $details_str = json_encode($details);
        
        // Log to activity_logs
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (
                user_id, action, details, reference, 
                ip_address, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $reference_str = "{$reference_type}#{$reference_id}";
        
        $stmt->execute([
            $user_id,
            $action_label,
            "Fuel {$action_label}: {$reference_type} #{$reference_id} - " . json_encode($details),
            $reference_str,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        // Log to audit_logs for comprehensive tracking
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                user_id, log_type, action_type, action_details,
                entity_type, entity_id, new_values, 
                ip_address, user_agent, status, created_at
            ) VALUES (?, 'inventory', ?, ?, ?, ?, ?, ?, ?, 'Success', NOW())
        ");
        
        $audit_details = array_merge($details, [
            'station_id' => $station_id,
            'product_id' => $product_id,
            'reference_type' => $reference_type,
            'reference_id' => $reference_id
        ]);
        
        $stmt->execute([
            $user_id,
            $action_type,
            "Fuel inventory {$action_type} for {$reference_type} #{$reference_id}",
            'fuel_inventory',
            $reference_id,
            json_encode($audit_details),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Error logging fuel inventory action: " . $e->getMessage());
        return false;
    }
}

/**
 * Get complete audit trail for a fuel inventory transaction
 * Shows all steps from initial recording to final approval
 * 
 * @param PDO $pdo Database connection
 * @param string $reference_type fuel_delivery, fuel_daily_reading, or fuel_adjustment
 * @param int $reference_id ID of the source transaction
 * @return array Complete audit trail with all actions
 */
function get_fuel_audit_trail($pdo, $reference_type, $reference_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                al.id,
                al.action_type,
                al.action_details,
                al.new_values,
                al.status,
                u.name as user_name,
                al.created_at,
                al.ip_address
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.log_type = 'inventory' 
            AND al.entity_type = 'fuel_inventory'
            AND JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.reference_type')) = ?
            AND JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.reference_id')) = ?
            ORDER BY al.created_at ASC
        ");
        
        $stmt->execute([$reference_type, $reference_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting fuel audit trail: " . $e->getMessage());
        return [];
    }
}

/**
 * Get fuel inventory modification report
 * Shows all stock changes for a station in a date range
 * Useful for reconciliation and variance analysis
 * 
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID
 * @param string $start_date YYYY-MM-DD
 * @param string $end_date YYYY-MM-DD
 * @return array All stock modifications in date range
 */
function get_fuel_stock_modifications($pdo, $station_id, $start_date, $end_date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                al.id,
                al.action_type,
                al.action_details,
                al.new_values,
                al.status,
                u.name as user_name,
                al.created_at,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.fuel_type')) as fuel_type,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.quantity_before')) as quantity_before,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.quantity_after')) as quantity_after,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.quantity_change')) as quantity_change,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.reference_type')) as reference_type,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.reference_id')) as reference_id
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.log_type = 'inventory' 
            AND al.entity_type = 'fuel_inventory'
            AND JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.station_id')) = ?
            AND DATE(al.created_at) BETWEEN ? AND ?
            ORDER BY al.created_at DESC
        ");
        
        $stmt->execute([$station_id, $start_date, $end_date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting fuel stock modifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user actions for fuel operations
 * Shows what actions a specific user has performed
 * Useful for user activity tracking and compliance
 * 
 * @param PDO $pdo Database connection
 * @param int $user_id User ID
 * @param string $action_type Optional: filter by specific action type
 * @param int $limit Number of records to return
 * @return array User's fuel-related actions
 */
function get_user_fuel_actions($pdo, $user_id, $action_type = null, $limit = 50) {
    try {
        $query = "
            SELECT 
                al.id,
                al.action_type,
                al.action_details,
                al.new_values,
                al.status,
                al.created_at,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.fuel_type')) as fuel_type,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.quantity_change')) as quantity_change,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.reference_type')) as reference_type,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.reference_id')) as reference_id,
                s.name as station_name
            FROM audit_logs al
            LEFT JOIN stations s ON s.id = JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.station_id'))
            WHERE al.user_id = ? 
            AND al.log_type = 'inventory' 
            AND al.entity_type = 'fuel_inventory'
        ";
        
        $params = [$user_id];
        
        if ($action_type) {
            $query .= " AND al.action_type = ?";
            $params[] = $action_type;
        }
        
        $query .= " ORDER BY al.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting user fuel actions: " . $e->getMessage());
        return [];
    }
}

/**
 * Generate fuel inventory audit report
 * Comprehensive report for compliance and verification
 * 
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID
 * @param string $date YYYY-MM-DD
 * @return array Audit report with summary and details
 */
function generate_fuel_audit_report($pdo, $station_id, $date) {
    try {
        // Get all modifications for the date
        $stmt = $pdo->prepare("
            SELECT 
                al.action_type,
                COUNT(*) as count,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.quantity_change')) AS DECIMAL(10,2))) as total_change,
                MIN(al.created_at) as first_action,
                MAX(al.created_at) as last_action
            FROM audit_logs al
            WHERE al.log_type = 'inventory' 
            AND al.entity_type = 'fuel_inventory'
            AND JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.station_id')) = ?
            AND DATE(al.created_at) = ?
            GROUP BY al.action_type
        ");
        
        $stmt->execute([$station_id, $date]);
        $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get detailed modifications
        $stmt = $pdo->prepare("
            SELECT 
                al.*,
                u.name as user_name,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.fuel_type')) as fuel_type,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.quantity_before')) as quantity_before,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.quantity_after')) as quantity_after,
                JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.quantity_change')) as quantity_change
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.log_type = 'inventory' 
            AND al.entity_type = 'fuel_inventory'
            AND JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.station_id')) = ?
            AND DATE(al.created_at) = ?
            ORDER BY al.created_at ASC
        ");
        
        $stmt->execute([$station_id, $date]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'station_id' => $station_id,
            'date' => $date,
            'summary' => $summary,
            'details' => $details,
            'total_records' => count($details)
        ];
    } catch (Exception $e) {
        error_log("Error generating fuel audit report: " . $e->getMessage());
        return [];
    }
}

/**
 * Verify audit trail integrity
 * Checks that all stock changes are properly logged
 * Can be run periodically to ensure no unauthorized changes
 * 
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID
 * @return array Integrity check results
 */
function verify_fuel_audit_integrity($pdo, $station_id) {
    try {
        $results = [];
        
        // Check 1: All finalized deliveries should have audit logs
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM fuel_deliveries fd
            WHERE fd.station_id = ? AND fd.status IN ('Finalized', 'Manager_Direct')
        ");
        
        $stmt->execute([$station_id]);
        $total_deliveries = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Check 2: Count delivery audit logs
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM audit_logs al
            WHERE al.log_type = 'inventory' 
            AND al.entity_type = 'fuel_inventory'
            AND JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.station_id')) = ?
            AND al.action_type LIKE '%delivery%'
        ");
        
        $stmt->execute([$station_id]);
        $logged_deliveries = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $results['total_deliveries'] = $total_deliveries;
        $results['logged_deliveries'] = $logged_deliveries;
        $results['missing_delivery_logs'] = max(0, $total_deliveries - $logged_deliveries);
        
        // Overall integrity status
        $results['integrity_ok'] = ($results['missing_delivery_logs'] == 0);
        
        return $results;
    } catch (Exception $e) {
        error_log("Error verifying audit integrity: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}
?>
