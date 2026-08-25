<?php
// API for Database Maintenance Operations
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

require_once '../../backend/rbac.php';
require_once '../public/db_connect.php';

// Start session for authentication
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Database connection is available as global $pdo from db_connect.php

// Check role-based access control (SuperAdmin/Developer only for maintenance)
if ($_SESSION['role'] !== 'superadmin' && $_SESSION['role'] !== 'developer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Maintenance operations require SuperAdmin/Developer privileges.']);
    exit();
}

// Get action from request
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'execute':
            handleExecuteScript($pdo);
            break;
        case 'list':
            handleListScripts($pdo);
            break;
        case 'status':
            handleScriptStatus($pdo);
            break;
        case 'logs':
            handleMaintenanceLogs($pdo);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

function handleExecuteScript($pdo) {
    $scriptKey = $_GET['script'] ?? '';
    
    if (empty($scriptKey)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Script key is required']);
        return;
    }
    
    // Get script details from database
    $stmt = $pdo->prepare("SELECT * FROM db_maintenance_scripts WHERE script_key = ? AND is_active = TRUE");
    $stmt->execute([$scriptKey]);
    $script = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$script) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Script not found or inactive']);
        return;
    }
    
    // Check if user has permission for dangerous operations
    if ($script['is_dangerous'] && $_SESSION['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Dangerous operations require SuperAdmin privileges']);
        return;
    }
    
    // Execute the script
    $startTime = microtime(true);
    $output = '';
    $success = false;
    $errorMessage = '';
    
    try {
        // Dynamic script execution based on database configuration
        $result = executeScriptDynamically($script, $pdo);
        
        $success = $result['success'];
        $output = $result['output'];
        $errorMessage = $result['error'] ?? '';
        
    } catch (Exception $e) {
        $success = false;
        $errorMessage = $e->getMessage();
        $output = $e->getTraceAsString();
    }
    
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    // Log the execution
    logMaintenanceExecution($pdo, $_SESSION['user_id'], $scriptKey, $success, $duration, $output, $errorMessage);
    
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Script executed successfully' : $errorMessage,
        'output' => $output,
        'duration' => $duration . ' seconds',
        'script_name' => $script['script_name']
    ]);
}

function executeScriptDynamically($script, $pdo) {
    $scriptType = $script['script_type'];
    $executionCommand = $script['execution_command'];
    
    // Replace placeholders in execution command
    $command = str_replace([
        '{timestamp}',
        '{backup_file}',
        '{database}'
    ], [
        date('Y-m-d_H-i-s'),
        '../../backups/database/backup_' . date('Y-m-d_H-i-s') . '.sql',
        'petron_pos_db_secure
'
    ], $executionCommand);
    
    // Execute the command
    $startTime = microtime(true);
    $output = shell_exec($command . ' 2>&1');
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    // Check execution result based on script type
    $success = false;
    $resultMessage = '';
    
    switch ($scriptType) {
        case 'backup':
            $backupFile = '../../backups/database/backup_' . date('Y-m-d_H-i-s') . '.sql';
            $success = file_exists($backupFile) && filesize($backupFile) > 0;
            if ($success) {
                $resultMessage = "Backup created successfully: " . basename($backupFile) . "\nSize: " . formatBytes(filesize($backupFile));
            } else {
                $resultMessage = 'Backup failed. Check database credentials and permissions.';
            }
            break;
            
        case 'restore':
            // Restore operations should be verified by checking if the command executed without errors
            $success = strpos($output, 'ERROR') === false && strpos($output, 'Warning') === false;
            $resultMessage = $success ? 'Database restore completed successfully' : 'Database restore failed. Check backup file integrity.';
            break;
            
        case 'indexing':
        case 'optimization':
        case 'cleanup':
        case 'repair':
            // For maintenance operations, check if command executed without critical errors
            $success = strpos($output, 'ERROR') === false;
            $resultMessage = $success ? ucfirst($scriptType) . ' operations completed successfully' : ucfirst($scriptType) . ' operations encountered errors.';
            break;
            
        default:
            $success = true; // Assume success for unknown script types if no errors
            $resultMessage = 'Script executed successfully';
    }
    
    return [
        'success' => $success,
        'output' => $resultMessage . "\n\nExecution time: {$duration} seconds\nCommand output:\n" . $output,
        'error' => $success ? '' : $resultMessage
    ];
}

function handleListScripts($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM db_maintenance_scripts WHERE is_active = TRUE ORDER BY script_type, sort_order");
    $stmt->execute();
    $scripts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'scripts' => $scripts
    ]);
}

function handleScriptStatus($pdo) {
    // Get recent maintenance executions
    $stmt = $pdo->prepare("
        SELECT * FROM db_maintenance_log 
        ORDER BY executed_at DESC 
        LIMIT 20
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'logs' => $logs
    ]);
}

function handleMaintenanceLogs($pdo) {
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    $stmt = $pdo->prepare("
        SELECT * FROM db_maintenance_log 
        ORDER BY executed_at DESC 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM db_maintenance_log");
    $countStmt->execute();
    $totalCount = $countStmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'total_count' => $totalCount,
        'page' => $page,
        'limit' => $limit
    ]);
}

function logMaintenanceExecution($pdo, $userId, $scriptKey, $success, $duration, $output, $errorMessage) {
    try {
        // Create maintenance log table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS db_maintenance_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                script_key VARCHAR(50) NOT NULL,
                success BOOLEAN NOT NULL,
                duration DECIMAL(10,2) NOT NULL,
                output TEXT,
                error_message TEXT,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");
        
        $stmt = $pdo->prepare("
            INSERT INTO db_maintenance_log (user_id, script_key, success, duration, output, error_message) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $scriptKey, $success, $duration, $output, $errorMessage]);
        
        // Also log to main audit log
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, details, ip_address, user_agent, created_at) 
            VALUES (?, 'database_maintenance', ?, ?, ?, NOW())
        ");
        $details = "Executed maintenance script: $scriptKey - " . ($success ? 'SUCCESS' : 'FAILED');
        $stmt->execute([$userId, $details, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown']);
        
    } catch (Exception $e) {
        error_log("Failed to log maintenance execution: " . $e->getMessage());
    }
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}
?>
