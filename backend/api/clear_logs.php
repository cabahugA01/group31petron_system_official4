<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json');

// Check if user is authorized
$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'admin', 'developer'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);
$days = $input['days'] ?? 30;
$log_type = $input['log_type'] ?? 'error';

try {
    global $pdo;
    
    // Start maintenance logging
    $stmt = $pdo->prepare("
        INSERT INTO system_maintenance_log (maintenance_type, description, status, performed_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        'log_cleanup',
        "Clear $log_type logs older than $days days",
        'in_progress',
        $u['id']
    ]);
    $maintenance_id = $pdo->lastInsertId();
    
    $cleared_count = 0;
    
    if ($log_type === 'error') {
        // Clear error logs from audit_log table
        if ($days == 0) {
            // Clear all error logs
            $stmt = $pdo->prepare("
                DELETE FROM audit_log 
                WHERE status = 'Failed' OR action LIKE '%Error%' OR action LIKE '%Failed%'
            ");
            $stmt->execute();
            $cleared_count = $stmt->rowCount();
        } else {
            // Clear error logs older than specified days
            $stmt = $pdo->prepare("
                DELETE FROM audit_log 
                WHERE (status = 'Failed' OR action LIKE '%Error%' OR action LIKE '%Failed%')
                AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            $cleared_count = $stmt->rowCount();
        }
        
        // Also clear system alerts if clearing all
        if ($days == 0) {
            $stmt = $pdo->prepare("DELETE FROM system_alerts");
            $stmt->execute();
            $cleared_count += $stmt->rowCount();
        } else {
            $stmt = $pdo->prepare("
                DELETE FROM system_alerts 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            $cleared_count += $stmt->rowCount();
        }
    } elseif ($log_type === 'all') {
        // Clear all logs
        if ($days == 0) {
            $stmt = $pdo->prepare("DELETE FROM audit_log");
            $stmt->execute();
            $cleared_count = $stmt->rowCount();
            
            $stmt = $pdo->prepare("DELETE FROM system_health_metrics");
            $stmt->execute();
            $cleared_count += $stmt->rowCount();
            
            $stmt = $pdo->prepare("DELETE FROM system_alerts");
            $stmt->execute();
            $cleared_count += $stmt->rowCount();
        } else {
            $stmt = $pdo->prepare("
                DELETE FROM audit_log 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            $cleared_count = $stmt->rowCount();
            
            $stmt = $pdo->prepare("
                DELETE FROM system_health_metrics 
                WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            $cleared_count += $stmt->rowCount();
            
            $stmt = $pdo->prepare("
                DELETE FROM system_alerts 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            $cleared_count += $stmt->rowCount();
        }
    }
    
    // Update maintenance log
    $stmt = $pdo->prepare("
        UPDATE system_maintenance_log 
        SET status = 'completed', 
            completed_at = NOW(),
            details = ?
        WHERE id = ?
    ");
    $stmt->execute([
        json_encode([
            'log_type' => $log_type,
            'days' => $days,
            'records_cleared' => $cleared_count
        ]),
        $maintenance_id
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => "Cleared $cleared_count $log_type log entries",
        'cleared_count' => $cleared_count
    ]);
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to clear logs: ' . $e->getMessage()
    ]);
}
?>
