<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json');

// Check if user is authorized
$u = current_user();
$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'admin', 'developer'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

try {
    global $pdo;
    
    // Start backup process
    $backup_name = 'system_backup_' . date('Ymd_His');
    $backup_path = __DIR__ . '/../../backups/' . $backup_name;
    
    // Create backup directory if it doesn't exist
    $backup_dir = __DIR__ . '/../../backups';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    // Log backup start
    $stmt = $pdo->prepare("
        INSERT INTO system_backups (backup_name, backup_type, status, created_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$backup_name, 'full', 'in_progress', $u['id']]);
    $backup_id = $pdo->lastInsertId();
    
    $backup_results = [
        'backup_name' => $backup_name,
        'backup_id' => $backup_id,
        'timestamp' => date('Y-m-d H:i:s'),
        'components' => [],
        'total_size' => 0,
        'status' => 'success'
    ];
    
    // 1. Database Backup
    $db_backup = [
        'name' => 'Database Backup',
        'status' => 'success',
        'message' => 'Database exported successfully',
        'size_mb' => 0
    ];
    
    try {
        // Get database name
        $stmt = $pdo->query("SELECT DATABASE()");
        $database_name = $stmt->fetchColumn();
        
        // Create database backup file
        $db_file = $backup_path . '_database.sql';
        $command = "mysqldump --single-transaction --routines --triggers --all-databases -u root -p '$database_name' > '$db_file'";
        
        // Try to create database backup (simplified version)
        $tables = [];
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        $sql_content = "-- Database backup: $database_name\n";
        $sql_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $create_table = $stmt->fetch(PDO::FETCH_NUM);
            $sql_content .= $create_table[1] . ";\n\n";
            
            $stmt = $pdo->query("SELECT * FROM `$table`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $values = array_map(function($val) use ($pdo) {
                    return $val === null ? 'NULL' : $pdo->quote($val);
                }, $row);
                $sql_content .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql_content .= "\n";
        }
        
        file_put_contents($db_file, $sql_content);
        $db_backup['size_mb'] = round(filesize($db_file) / 1024 / 1024, 2);
        $backup_results['total_size'] += $db_backup['size_mb'];
        
    } catch(Exception $e) {
        $db_backup['status'] = 'warning';
        $db_backup['message'] = 'Database backup incomplete: ' . $e->getMessage();
        $backup_results['status'] = 'warning';
    }
    $backup_results['components'][] = $db_backup;
    
    // 2. Configuration Backup
    $config_backup = [
        'name' => 'Configuration Backup',
        'status' => 'success',
        'message' => 'Configuration files backed up',
        'size_mb' => 0
    ];
    
    try {
        $config_data = [
            'system_configuration' => [],
            'timestamp' => date('Y-m-d H:i:s'),
            'backup_version' => '2.1.0'
        ];
        
        // Try to get configuration if table exists
        try {
            $stmt = $pdo->query("SELECT * FROM system_configuration");
            $config_data['system_configuration'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $config_data['system_configuration'] = [];
        }
        
        $config_file = $backup_path . '_config.json';
        file_put_contents($config_file, json_encode($config_data, JSON_PRETTY_PRINT));
        
        $config_backup['size_mb'] = round(filesize($config_file) / 1024 / 1024, 2);
        $backup_results['total_size'] += $config_backup['size_mb'];
        
    } catch(Exception $e) {
        $config_backup['status'] = 'warning';
        $config_backup['message'] = 'Configuration backup incomplete: ' . $e->getMessage();
    }
    $backup_results['components'][] = $config_backup;
    
    // 3. System Health Backup
    $health_backup = [
        'name' => 'System Health Backup',
        'status' => 'success',
        'message' => 'Health metrics backed up',
        'size_mb' => 0
    ];
    
    try {
        $health_data = [
            'health_metrics' => [],
            'system_alerts' => [],
            'maintenance_log' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Try to get health metrics if table exists
        try {
            $stmt = $pdo->query("SELECT * FROM system_health_metrics ORDER BY recorded_at DESC LIMIT 1000");
            $health_data['health_metrics'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $health_data['health_metrics'] = [];
        }
        
        // Try to get system alerts if table exists
        try {
            $stmt = $pdo->query("SELECT * FROM system_alerts ORDER BY created_at DESC LIMIT 500");
            $health_data['system_alerts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $health_data['system_alerts'] = [];
        }
        
        // Try to get maintenance log if table exists
        try {
            $stmt = $pdo->query("SELECT * FROM system_maintenance_log ORDER BY created_at DESC LIMIT 100");
            $health_data['maintenance_log'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $health_data['maintenance_log'] = [];
        }
        
        $health_file = $backup_path . '_health.json';
        file_put_contents($health_file, json_encode($health_data, JSON_PRETTY_PRINT));
        
        $health_backup['size_mb'] = round(filesize($health_file) / 1024 / 1024, 2);
        $backup_results['total_size'] += $health_backup['size_mb'];
        
    } catch(Exception $e) {
        $health_backup['status'] = 'warning';
        $health_backup['message'] = 'Health backup incomplete: ' . $e->getMessage();
    }
    $backup_results['components'][] = $health_backup;
    
    // Update backup record
    $stmt = $pdo->prepare("
        UPDATE system_backups 
        SET status = 'completed', 
            completed_at = NOW(),
            backup_path = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $backup_path,
        $backup_id
    ]);
    
    // Log maintenance if table exists
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_maintenance_log (maintenance_type, description, status, performed_by, details)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'backup',
            "System backup completed: $backup_name",
            'completed',
            $u['id'],
            json_encode([
                'backup_id' => $backup_id,
                'backup_name' => $backup_name,
                'total_size_mb' => $backup_results['total_size'],
                'components' => count($backup_results['components'])
            ])
        ]);
    } catch (Exception $e) {
        error_log("Failed to log maintenance: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'System backup completed successfully',
        'data' => $backup_results
    ]);
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'System backup failed: ' . $e->getMessage()
    ]);
}
?>
