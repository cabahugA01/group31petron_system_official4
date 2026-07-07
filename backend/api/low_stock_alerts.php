<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../public/db_connect.php';

header('Content-Type: application/json');

$pdo = getDbConnection();
$action = $_GET['action'] ?? '';
$station_id = $_GET['station_id'] ?? 0;
$category = $_GET['category'] ?? '';
$severity = $_GET['severity'] ?? '';

// Get current user and role
$user = current_user();
$role = strtolower($user['role'] ?? 'staff');

try {
    switch ($action) {
        case 'get_alerts':
            echo json_encode(getLowStockAlerts($pdo, $role, $station_id, $category, $severity));
            break;
            
        case 'get_alert_counts':
            echo json_encode(getAlertCounts($pdo, $role, $station_id));
            break;
            
        case 'update_alerts':
            echo json_encode(updateLowStockAlerts($pdo));
            break;
            
        case 'resolve_alert':
            $alert_id = $_POST['alert_id'] ?? 0;
            $notes = $_POST['notes'] ?? '';
            echo json_encode(resolveAlert($pdo, $alert_id, $user['id'], $notes));
            break;
            
        case 'create_stock_request':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(createStockRequest($pdo, $data, $user));
            break;
            
        case 'get_stock_requests':
            echo json_encode(getStockRequests($pdo, $role, $station_id));
            break;
            
        case 'update_stock_request':
            $request_id = $_POST['request_id'] ?? 0;
            $new_status = $_POST['status'] ?? '';
            $notes = $_POST['notes'] ?? '';
            echo json_encode(updateStockRequest($pdo, $request_id, $new_status, $user, $notes));
            break;
            
        case 'get_dashboard_data':
            echo json_encode(getDashboardData($pdo, $role, $station_id));
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Get low stock alerts with role-based filtering
 */
function getLowStockAlerts($pdo, $role, $station_id = 0, $category = '', $severity = '') {
    $sql = "SELECT * FROM v_low_stock_dashboard WHERE 1=1";
    $params = [];
    
    // Station filter based on role
    if (!in_array($role, ['admin', 'superadmin'])) {
        $station_id = user_station_id();
        if ($station_id) {
            $sql .= " AND station_id = ?";
            $params[] = $station_id;
        }
    } elseif ($station_id > 0) {
        $sql .= " AND station_id = ?";
        $params[] = $station_id;
    }
    
    // Category filter
    if ($category && $category !== 'all') {
        $sql .= " AND category = ?";
        $params[] = $category;
    }
    
    // Severity filter
    if ($severity) {
        $sql .= " AND severity = ?";
        $params[] = $severity;
    }
    
    // Role-based severity filtering
    $severity_filter = getSeverityFilter($role);
    if ($severity_filter) {
        $placeholders = str_repeat('?,', count($severity_filter) - 1) . '?';
        $sql .= " AND severity IN ($placeholders)";
        $params = array_merge($params, $severity_filter);
    }
    
    $sql .= " ORDER BY severity ASC, current_stock ASC LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return [
        'success' => true,
        'alerts' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => count($stmt->fetchAll(PDO::FETCH_ASSOC))
    ];
}

/**
 * Get alert counts by category and severity
 */
function getAlertCounts($pdo, $role, $station_id = 0) {
    $sql = "SELECT 
        category,
        severity,
        COUNT(*) as count
    FROM v_low_stock_dashboard 
    WHERE 1=1";
    
    $params = [];
    
    // Station filter based on role
    if (!in_array($role, ['admin', 'superadmin'])) {
        $user_station_id = user_station_id();
        if ($user_station_id) {
            $sql .= " AND station_id = ?";
            $params[] = $user_station_id;
        }
    } elseif ($station_id > 0) {
        $sql .= " AND station_id = ?";
        $params[] = $station_id;
    }
    
    // Role-based severity filtering
    $severity_filter = getSeverityFilter($role);
    if ($severity_filter) {
        $placeholders = str_repeat('?,', count($severity_filter) - 1) . '?';
        $sql .= " AND severity IN ($placeholders)";
        $params = array_merge($params, $severity_filter);
    }
    
    $sql .= " GROUP BY category, severity";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format counts
    $counts = [
        'total' => 0,
        'critical' => 0,
        'warning' => 0,
        'fuel' => ['critical' => 0, 'warning' => 0, 'total' => 0],
        'merchandise' => ['critical' => 0, 'warning' => 0, 'total' => 0],
        'parts' => ['critical' => 0, 'warning' => 0, 'total' => 0]
    ];
    
    foreach ($results as $row) {
        $counts['total'] += $row['count'];
        $counts[$row['severity']] += $row['count'];
        $counts[$row['category']][$row['severity']] += $row['count'];
        $counts[$row['category']]['total'] += $row['count'];
    }
    
    return [
        'success' => true,
        'counts' => $counts
    ];
}

/**
 * Update low stock alerts by running the stored procedure
 */
function updateLowStockAlerts($pdo) {
    try {
        $stmt = $pdo->query("CALL update_low_stock_alerts()");
        return ['success' => true, 'message' => 'Low stock alerts updated successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Resolve a low stock alert
 */
function resolveAlert($pdo, $alert_id, $user_id, $notes) {
    if (!$alert_id) {
        return ['success' => false, 'error' => 'Alert ID is required'];
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE low_stock_alerts 
            SET status = 'resolved', 
                alert_resolved_at = NOW(), 
                resolved_by = ?, 
                notes = ?
            WHERE id = ? AND status = 'Active'
        ");
        $stmt->execute([$user_id, $notes, $alert_id]);
        
        if ($stmt->rowCount() > 0) {
            return ['success' => true, 'message' => 'Alert resolved successfully'];
        } else {
            return ['success' => false, 'error' => 'Alert not found or already resolved'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Create a new stock request
 */
function createStockRequest($pdo, $data, $user) {
    $required_fields = ['station_id', 'product_id', 'category', 'requested_quantity', 'urgency'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['success' => false, 'error' => "Field '$field' is required"];
        }
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO stock_requests 
            (station_id, product_id, category, requested_by, requested_quantity, unit, urgency, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['station_id'],
            $data['product_id'],
            $data['category'],
            $user['id'],
            $data['requested_quantity'],
            $data['unit'] ?? 'pcs',
            $data['urgency'],
            $data['reason'] ?? ''
        ]);
        
        $request_id = $pdo->lastInsertId();
        
        // Get the request number
        $stmt = $pdo->prepare("SELECT request_number FROM stock_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $request_number = $stmt->fetchColumn();
        
        return [
            'success' => true,
            'request_id' => $request_id,
            'request_number' => $request_number,
            'message' => 'Stock request created successfully'
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get stock requests with role-based filtering
 */
function getStockRequests($pdo, $role, $station_id = 0) {
    $sql = "SELECT * FROM v_stock_requests_dashboard WHERE 1=1";
    $params = [];
    
    // Station filter based on role
    if (!in_array($role, ['admin', 'superadmin'])) {
        $user_station_id = user_station_id();
        if ($user_station_id) {
            $sql .= " AND station_id = ?";
            $params[] = $user_station_id;
        }
    } elseif ($station_id > 0) {
        $sql .= " AND station_id = ?";
        $params[] = $station_id;
    }
    
    // Role-based status filtering
    $status_filter = getStatusFilter($role);
    if ($status_filter) {
        $placeholders = str_repeat('?,', count($status_filter) - 1) . '?';
        $sql .= " AND status IN ($placeholders)";
        $params = array_merge($params, $status_filter);
    }
    
    $sql .= " ORDER BY days_pending DESC, urgency DESC LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return [
        'success' => true,
        'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ];
}

/**
 * Update stock request status
 */
function updateStockRequest($pdo, $request_id, $new_status, $user, $notes) {
    if (!$request_id || !$new_status) {
        return ['success' => false, 'error' => 'Request ID and status are required'];
    }
    
    // Validate status transition based on role
    $valid_transitions = getValidStatusTransitions(strtolower($user['role']));
    if (!isset($valid_transitions[$new_status])) {
        return ['success' => false, 'error' => 'Invalid status transition for your role'];
    }
    
    try {
        $update_fields = [
            'status' => $new_status,
            'updated_at' => 'NOW()'
        ];
        
        // Add role-specific fields
        switch ($new_status) {
            case 'approved':
                $update_fields['approved_by'] = $user['id'];
                $update_fields['approved_at'] = 'NOW()';
                $update_fields['approval_notes'] = $notes;
                break;
            case 'rejected':
                $update_fields['approved_by'] = $user['id'];
                $update_fields['approved_at'] = 'NOW()';
                $update_fields['approval_notes'] = $notes;
                break;
            case 'ordered':
                $update_fields['ordered_by'] = $user['id'];
                $update_fields['ordered_at'] = 'NOW()';
                break;
            case 'received':
                $update_fields['received_by'] = $user['id'];
                $update_fields['received_at'] = 'NOW()';
                break;
        }
        
        // Build dynamic update query
        $set_clause = [];
        $values = [];
        foreach ($update_fields as $field => $value) {
            $set_clause[] = "$field = " . ($value === 'NOW()' ? 'NOW()' : '?');
            if ($value !== 'NOW()') {
                $values[] = $value;
            }
        }
        $values[] = $request_id;
        
        $sql = "UPDATE stock_requests SET " . implode(', ', $set_clause) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        
        if ($stmt->rowCount() > 0) {
            return ['success' => true, 'message' => "Stock request $new_status successfully"];
        } else {
            return ['success' => false, 'error' => 'Request not found or status not updated'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get comprehensive dashboard data
 */
function getDashboardData($pdo, $role, $station_id = 0) {
    $data = [
        'alerts' => [],
        'alerts_counts' => [],
        'requests' => [],
        'summary' => []
    ];
    
    // Get alerts
    $alerts_result = getLowStockAlerts($pdo, $role, $station_id);
    if ($alerts_result['success']) {
        $data['alerts'] = $alerts_result['alerts'];
    }
    
    // Get alert counts
    $counts_result = getAlertCounts($pdo, $role, $station_id);
    if ($counts_result['success']) {
        $data['alerts_counts'] = $counts_result['counts'];
    }
    
    // Get stock requests
    $requests_result = getStockRequests($pdo, $role, $station_id);
    if ($requests_result['success']) {
        $data['requests'] = $requests_result['requests'];
    }
    
    // Get summary metrics
    $data['summary'] = getDashboardSummary($pdo, $role, $station_id);
    
    return ['success' => true, 'data' => $data];
}

/**
 * Get dashboard summary metrics
 */
function getDashboardSummary($pdo, $role, $station_id = 0) {
    $sql = "SELECT 
        COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_alerts,
        COUNT(CASE WHEN severity = 'warning' THEN 1 END) as warning_alerts,
        COUNT(CASE WHEN category = 'fuel' THEN 1 END) as fuel_alerts,
        COUNT(CASE WHEN category = 'merchandise' THEN 1 END) as merchandise_alerts,
        COUNT(CASE WHEN category = 'parts' THEN 1 END) as parts_alerts
    FROM v_low_stock_dashboard 
    WHERE 1=1";
    
    $params = [];
    
    // Station filter based on role
    if (!in_array($role, ['admin', 'superadmin'])) {
        $user_station_id = user_station_id();
        if ($user_station_id) {
            $sql .= " AND station_id = ?";
            $params[] = $user_station_id;
        }
    } elseif ($station_id > 0) {
        $sql .= " AND station_id = ?";
        $params[] = $station_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $alert_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get stock request summary
    $sql = "SELECT 
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_requests,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_requests,
        COUNT(CASE WHEN urgency = 'critical' AND status IN ('pending', 'approved') THEN 1 END) as critical_requests
    FROM v_stock_requests_dashboard 
    WHERE 1=1";
    
    $params = [];
    
    // Station filter based on role
    if (!in_array($role, ['admin', 'superadmin'])) {
        $user_station_id = user_station_id();
        if ($user_station_id) {
            $sql .= " AND station_id = ?";
            $params[] = $user_station_id;
        }
    } elseif ($station_id > 0) {
        $sql .= " AND station_id = ?";
        $params[] = $station_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $request_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return array_merge($alert_summary, $request_summary);
}

/**
 * Get severity filter based on user role
 */
function getSeverityFilter($role) {
    switch ($role) {
        case 'staff':
            return ['critical', 'warning'];
        case 'manager':
            return ['critical', 'warning', 'info'];
        case 'admin':
        case 'superadmin':
            return ['critical', 'warning', 'info'];
        default:
            return ['critical'];
    }
}

/**
 * Get status filter based on user role
 */
function getStatusFilter($role) {
    switch ($role) {
        case 'staff':
            return ['pending', 'approved'];
        case 'manager':
            return ['pending', 'approved', 'ordered'];
        case 'admin':
        case 'superadmin':
            return ['pending', 'approved', 'rejected', 'ordered', 'received'];
        default:
            return ['pending'];
    }
}

/**
 * Get valid status transitions for each role
 */
function getValidStatusTransitions($role) {
    switch ($role) {
        case 'staff':
            return [];
        case 'manager':
            return [
                'approved' => true,
                'rejected' => true
            ];
        case 'admin':
        case 'superadmin':
            return [
                'approved' => true,
                'rejected' => true,
                'ordered' => true,
                'received' => true
            ];
        default:
            return [];
    }
}
?>
