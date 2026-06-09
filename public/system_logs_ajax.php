<?php
session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/rbac.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check permissions
if (!has_permission('VIEW_USER_LOGS')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

// Get action
$action = $_POST['action'] ?? '';

// Get filter parameters
$from_date = $_POST['from_date'] ?? date('Y-m-d', strtotime('-7 days'));
$to_date = $_POST['to_date'] ?? date('Y-m-d');
$user_filter = $_POST['user_filter'] ?? '';
$module_filter = $_POST['module_filter'] ?? '';
$severity_filter = $_POST['severity_filter'] ?? '';
$keyword_search = $_POST['keyword_search'] ?? '';

// Helper function to build WHERE clause
function buildWhereClause($filters) {
    $where = [];
    $params = [];
    
    if (!empty($filters['from_date'])) {
        $where[] = "DATE(created_at) >= ?";
        $params[] = $filters['from_date'];
    }
    
    if (!empty($filters['to_date'])) {
        $where[] = "DATE(created_at) <= ?";
        $params[] = $filters['to_date'];
    }
    
    if (!empty($filters['user_filter'])) {
        $where[] = "user_id = ?";
        $params[] = $filters['user_filter'];
    }
    
    if (!empty($filters['module_filter'])) {
        $where[] = "module = ?";
        $params[] = $filters['module_filter'];
    }
    
    if (!empty($filters['severity_filter'])) {
        $where[] = "severity = ?";
        $params[] = $filters['severity_filter'];
    }
    
    if (!empty($filters['keyword_search'])) {
        $where[] = "(action LIKE ? OR details LIKE ? OR ip_address LIKE ?)";
        $searchTerm = '%' . $filters['keyword_search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    return [
        'where' => empty($where) ? '1=1' : implode(' AND ', $where),
        'params' => $params
    ];
}

// Handle different actions
switch ($action) {
    case 'load_user_activity':
        try {
            $filters = [
                'from_date' => $from_date,
                'to_date' => $to_date,
                'user_filter' => $user_filter,
                'module_filter' => $module_filter,
                'severity_filter' => $severity_filter,
                'keyword_search' => $keyword_search
            ];
            
            $whereClause = buildWhereClause($filters);
            
            $sql = "SELECT 
                        al.id,
                        u.username,
                        al.action,
                        al.module as entity,
                        al.created_at,
                        al.status,
                        al.details,
                        al.ip_address
                    FROM audit_log al
                    LEFT JOIN users u ON al.user_id = u.id
                    WHERE {$whereClause['where']}
                    ORDER BY al.created_at DESC
                    LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($whereClause['params']);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading user activity: ' . $e->getMessage()]);
        }
        break;
        
    case 'load_system_events':
        try {
            $filters = [
                'from_date' => $from_date,
                'to_date' => $to_date,
                'user_filter' => $user_filter,
                'module_filter' => $module_filter,
                'severity_filter' => $severity_filter,
                'keyword_search' => $keyword_search
            ];
            
            $whereClause = buildWhereClause($filters);
            
            $sql = "SELECT 
                        ee.id,
                        ee.error_type as event_type,
                        ee.module as category,
                        ee.severity,
                        ee.module,
                        ee.created_at,
                        ee.error_message as event_message,
                        ee.status,
                        CONCAT(u2.username, ' (', u2.full_name, ')') as assigned_to,
                        DATEDIFF(NOW(), ee.created_at) as days_open
                    FROM error_events ee
                    LEFT JOIN users u2 ON ee.assigned_to = u2.id
                    WHERE {$whereClause['where']}
                    ORDER BY ee.created_at DESC
                    LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($whereClause['params']);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading system events: ' . $e->getMessage()]);
        }
        break;
        
    case 'load_audit_trail':
        try {
            $filters = [
                'from_date' => $from_date,
                'to_date' => $to_date,
                'user_filter' => $user_filter,
                'module_filter' => $module_filter,
                'severity_filter' => $severity_filter,
                'keyword_search' => $keyword_search
            ];
            
            $whereClause = buildWhereClause($filters);
            
            $sql = "SELECT 
                        al.id,
                        u.username,
                        al.action,
                        al.table_name,
                        al.record_id,
                        al.old_values,
                        al.new_values,
                        al.created_at,
                        al.ip_address
                    FROM audit_log al
                    LEFT JOIN users u ON al.user_id = u.id
                    WHERE {$whereClause['where']} AND (al.old_values IS NOT NULL OR al.new_values IS NOT NULL)
                    ORDER BY al.created_at DESC
                    LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($whereClause['params']);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading audit trail: ' . $e->getMessage()]);
        }
        break;
        
    case 'load_error_tracking':
        try {
            $filters = [
                'from_date' => $from_date,
                'to_date' => $to_date,
                'user_filter' => $user_filter,
                'module_filter' => $module_filter,
                'severity_filter' => $severity_filter,
                'keyword_search' => $keyword_search
            ];
            
            $whereClause = buildWhereClause($filters);
            
            $sql = "SELECT 
                        ee.id,
                        ee.error_type,
                        ee.severity,
                        u.username,
                        ee.created_at,
                        ee.error_message,
                        ee.ip_address,
                        COUNT(*) as count,
                        ee.status
                    FROM error_events ee
                    LEFT JOIN users u ON ee.user_id = u.id
                    WHERE {$whereClause['where']}
                    GROUP BY ee.id, ee.error_type, ee.severity, u.username, ee.created_at, ee.error_message, ee.ip_address, ee.status
                    ORDER BY ee.created_at DESC
                    LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($whereClause['params']);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading error tracking: ' . $e->getMessage()]);
        }
        break;
        
    case 'load_system_alerts':
        try {
            $filters = [
                'from_date' => $from_date,
                'to_date' => $to_date,
                'user_filter' => $user_filter,
                'module_filter' => $module_filter,
                'severity_filter' => $severity_filter,
                'keyword_search' => $keyword_search
            ];
            
            $whereClause = buildWhereClause($filters);
            
            $sql = "SELECT 
                        sa.id,
                        sa.alert_type,
                        sa.severity,
                        sa.title,
                        sa.message,
                        sa.created_at,
                        s.station_name,
                        sa.status,
                        CONCAT(u.username, ' (', u.full_name, ')') as assigned_to
                    FROM system_alerts sa
                    LEFT JOIN users u ON sa.assigned_to = u.id
                    LEFT JOIN stations s ON sa.affected_stations LIKE CONCAT('%', s.id, '%')
                    WHERE {$whereClause['where']}
                    ORDER BY sa.created_at DESC
                    LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($whereClause['params']);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading system alerts: ' . $e->getMessage()]);
        }
        break;
        
    case 'get_statistics':
        try {
            $stats = [];
            
            // Total Activities
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) BETWEEN ? AND ?");
            $stmt->execute([$from_date, $to_date]);
            $stats['total_activities'] = $stmt->fetchColumn();
            
            // System Events
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM error_events WHERE DATE(created_at) BETWEEN ? AND ?");
            $stmt->execute([$from_date, $to_date]);
            $stats['system_events'] = $stmt->fetchColumn();
            
            // Error Count
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM error_events WHERE severity IN ('critical', 'high') AND DATE(created_at) BETWEEN ? AND ?");
            $stmt->execute([$from_date, $to_date]);
            $stats['error_count'] = $stmt->fetchColumn();
            
            // Active Alerts
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_alerts WHERE status IN ('active', 'acknowledged')");
            $stmt->execute();
            $stats['active_alerts'] = $stmt->fetchColumn();
            
            // Get modules for dropdown - using alert_type as module since system_alerts doesn't have module column
            $modules_query = "SELECT DISTINCT alert_type as module FROM system_alerts WHERE alert_type IS NOT NULL ORDER BY alert_type";
            $stmt = $pdo->prepare($modules_query);
            $stmt->execute();
            $stats['modules'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get users for dropdown
            $users_query = "SELECT DISTINCT id, username, name FROM users WHERE status = 'Active' ORDER BY username";
            $stmt = $pdo->prepare($users_query);
            $stmt->execute();
            $stats['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $stats]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading statistics: ' . $e->getMessage()]);
        }
        break;
        
    case 'export_user_activity':
    case 'export_system_events':
    case 'export_audit_trail':
    case 'export_error_tracking':
    case 'export_system_alerts':
        try {
            // Determine table and columns based on export type
            $tableMap = [
                'export_user_activity' => ['audit_log', 'id, username, action, module, created_at, status, details, ip_address'],
                'export_system_events' => ['error_events', 'id, error_type, severity, module, created_at, error_message, status'],
                'export_audit_trail' => ['audit_log', 'id, username, action, table_name, record_id, old_values, new_values, created_at, ip_address'],
                'export_error_tracking' => ['error_events', 'id, error_type, severity, username, created_at, error_message, ip_address, status'],
                'export_system_alerts' => ['system_alerts', 'id, alert_type, severity, title, message, created_at, station_name, status']
            ];
            
            $tableInfo = $tableMap[$action] ?? ['audit_log', '*'];
            
            $filters = [
                'from_date' => $from_date,
                'to_date' => $to_date,
                'keyword_search' => $keyword_search
            ];
            
            $whereClause = buildWhereClause($filters);
            
            $sql = "SELECT {$tableInfo[1]} FROM {$tableInfo[0]} WHERE {$whereClause['where']} ORDER BY created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($whereClause['params']);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate CSV
            $filename = str_replace('export_', '', $action) . '_export_' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            $output = fopen('php://output', 'w');
            
            // Write header
            if (!empty($data)) {
                fputcsv($output, array_keys($data[0]));
                
                // Write data
                foreach ($data as $row) {
                    fputcsv($output, $row);
                }
            }
            
            fclose($output);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}
?>
