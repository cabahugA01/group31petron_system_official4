<?php
// ============================================================
// Database Management API
// backend/api/database_api.php
// Handles backup, restore, schema, replication, and logs
// ============================================================

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$me = current_user();
$role = role_key($me['role'] ?? '');

// Only SuperAdmin access
if ($role !== 'superadmin') {
    echo json_encode(['ok' => false, 'error' => 'Access denied. SuperAdmin role required.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// CSRF validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

try {
    switch ($action) {
        
        // ══════════════════════════════════════════════════════════════
        // 1. BACKUP OPERATIONS
        // ══════════════════════════════════════════════════════════════
        case 'backup':
            $backup_dir = __DIR__ . '/../../backups/';
            if (!is_dir($backup_dir)) {
                mkdir($backup_dir, 0755, true);
            }
            
            $filename = 'backup_' . date('Y_m_d_His') . '.sql';
            $filepath = $backup_dir . $filename;
            
            // Get database name from PDO
            $db_name = $pdo->query('SELECT DATABASE()')->fetchColumn();
            
            // Execute mysqldump
            $command = sprintf(
                'C:\\xampp\\mysql\\bin\\mysqldump.exe -u root %s > %s 2>&1',
                escapeshellarg($db_name),
                escapeshellarg($filepath)
            );
            
            exec($command, $output, $return_code);
            
            if ($return_code === 0 && file_exists($filepath)) {
                // Log backup
                $stmt = $pdo->prepare("
                    INSERT INTO database_backups (filename, file_size, created_by, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$filename, filesize($filepath), $me['id']]);
                
                echo json_encode([
                    'ok' => true, 
                    'message' => 'Backup created successfully',
                    'filename' => $filename,
                    'size' => round(filesize($filepath) / 1024 / 1024, 2) . ' MB'
                ]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Backup failed: ' . implode("\n", $output)]);
            }
            break;

        case 'save_backup_config':
            $frequency = $_POST['frequency'] ?? 'manual';
            $storage = $_POST['storage'] ?? 'local';
            $retention = (int)($_POST['retention'] ?? 30);
            
            // Validate inputs
            if (!in_array($frequency, ['manual', 'daily', 'weekly', 'monthly'])) {
                echo json_encode(['ok' => false, 'error' => 'Invalid frequency']);
                exit;
            }
            if (!in_array($storage, ['local', 'cloud', 'both'])) {
                echo json_encode(['ok' => false, 'error' => 'Invalid storage location']);
                exit;
            }
            if ($retention < 1 || $retention > 365) {
                echo json_encode(['ok' => false, 'error' => 'Retention must be 1-365 days']);
                exit;
            }
            
            // Save to config table
            $configs = [
                ['backup_frequency', $frequency],
                ['backup_storage', $storage],
                ['backup_retention_days', (string)$retention]
            ];
            
            foreach ($configs as [$key, $value]) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_config (config_key, config_value, updated_by, updated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        config_value = VALUES(config_value),
                        updated_by = VALUES(updated_by),
                        updated_at = NOW()
                ");
                $stmt->execute([$key, $value, $me['id']]);
            }
            
            echo json_encode(['ok' => true, 'message' => 'Backup configuration saved successfully']);
            break;
            
        case 'get_backups':
            $backup_dir = __DIR__ . '/../../backups/';
            $backups = [];
            
            if (is_dir($backup_dir)) {
                $files = array_diff(scandir($backup_dir), ['.', '..']);
                foreach ($files as $file) {
                    if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                        $filepath = $backup_dir . $file;
                        $backups[] = [
                            'filename' => $file,
                            'size' => round(filesize($filepath) / 1024 / 1024, 2) . ' MB',
                            'date' => date('Y-m-d H:i:s', filemtime($filepath))
                        ];
                    }
                }
            }
            
            // Sort by date descending
            usort($backups, function($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });
            
            echo json_encode(['ok' => true, 'backups' => $backups]);
            break;
            
        case 'delete_backup':
            $filename = basename($_POST['filename'] ?? '');
            if (empty($filename)) {
                echo json_encode(['ok' => false, 'error' => 'No filename provided']);
                exit;
            }
            
            $backup_dir = __DIR__ . '/../../backups/';
            $filepath = $backup_dir . $filename;
            
            if (!file_exists($filepath)) {
                echo json_encode(['ok' => false, 'error' => 'Backup file not found']);
                exit;
            }
            
            if (unlink($filepath)) {
                // Log deletion
                $stmt = $pdo->prepare("
                    DELETE FROM database_backups WHERE filename = ?
                ");
                $stmt->execute([$filename]);
                
                echo json_encode(['ok' => true, 'message' => 'Backup deleted successfully']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Failed to delete backup file']);
            }
            break;
            
        // ══════════════════════════════════════════════════════════════
        // 2. RESTORE OPERATIONS
        // ══════════════════════════════════════════════════════════════
        case 'restore':
            $backup_file = $_POST['backup_file'] ?? '';
            $scope = $_POST['scope'] ?? 'full';
            
            if (empty($backup_file)) {
                echo json_encode(['ok' => false, 'error' => 'No backup file specified']);
                exit;
            }
            
            $backup_path = __DIR__ . '/../../backups/' . basename($backup_file);
            
            if (!file_exists($backup_path)) {
                echo json_encode(['ok' => false, 'error' => 'Backup file not found']);
                exit;
            }
            
            // Get database name
            $db_name = $pdo->query('SELECT DATABASE()')->fetchColumn();
            
            // Execute mysql restore
            $command = sprintf(
                'C:\\xampp\\mysql\\bin\\mysql.exe -u root %s < %s 2>&1',
                escapeshellarg($db_name),
                escapeshellarg($backup_path)
            );
            
            exec($command, $output, $return_code);
            
            if ($return_code === 0) {
                // Log restore
                $stmt = $pdo->prepare("
                    INSERT INTO database_restores (backup_filename, restored_by, restored_at) 
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute([$backup_file, $me['id']]);
                
                echo json_encode(['ok' => true, 'message' => 'Database restored successfully']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Restore failed: ' . implode("\n", $output)]);
            }
            break;

        // ══════════════════════════════════════════════════════════════
        // 3. SCHEMA OPERATIONS
        // ══════════════════════════════════════════════════════════════
        case 'get_tables':
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['ok' => true, 'tables' => $tables]);
            break;
            
        case 'get_table_structure':
            $table = $_GET['table'] ?? '';
            if (empty($table)) {
                echo json_encode(['ok' => false, 'error' => 'No table specified']);
                exit;
            }
            
            // Validate table exists
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($table, $tables)) {
                echo json_encode(['ok' => false, 'error' => 'Table not found']);
                exit;
            }
            
            // Get table structure
            $stmt = $pdo->prepare("DESCRIBE `$table`");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['ok' => true, 'columns' => $columns]);
            break;
            
        case 'optimize':
            $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE()");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $optimized = 0;
            foreach ($tables as $table) {
                try {
                    $pdo->exec("OPTIMIZE TABLE `$table`");
                    $optimized++;
                } catch (Exception $e) {
                    // Skip tables that can't be optimized
                }
            }
            
            echo json_encode(['ok' => true, 'message' => "Optimized $optimized tables successfully"]);
            break;
            
        case 'add_column':
            $table = $_POST['table'] ?? '';
            $columnName = $_POST['column_name'] ?? '';
            $columnType = $_POST['column_type'] ?? '';
            $columnLength = $_POST['column_length'] ?? '';
            $allowNull = $_POST['allow_null'] ?? '0';
            
            if (empty($table) || empty($columnName) || empty($columnType)) {
                echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
                exit;
            }
            
            // Build ALTER TABLE query
            $typeDefinition = $columnType;
            if ($columnLength && in_array($columnType, ['VARCHAR', 'CHAR'])) {
                $typeDefinition .= "($columnLength)";
            }
            
            $nullClause = $allowNull === '1' ? 'NULL' : 'NOT NULL';
            
            $sql = "ALTER TABLE `$table` ADD COLUMN `$columnName` $typeDefinition $nullClause";
            
            try {
                $pdo->exec($sql);
                
                // Log migration
                $stmt = $pdo->prepare("
                    INSERT INTO schema_migrations (migration_name, executed_by, executed_at)
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute(["ADD COLUMN $columnName TO $table", $me['id']]);
                
                echo json_encode(['ok' => true, 'message' => 'Column added successfully']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'error' => 'Failed to add column: ' . $e->getMessage()]);
            }
            break;
            
        case 'modify_column':
            $table = $_POST['table'] ?? '';
            $oldName = $_POST['old_name'] ?? '';
            $newName = $_POST['new_name'] ?? '';
            $columnType = $_POST['column_type'] ?? '';
            $columnLength = $_POST['column_length'] ?? '';
            $allowNull = $_POST['allow_null'] ?? '0';
            
            if (empty($table) || empty($oldName)) {
                echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
                exit;
            }
            
            // Get current column definition if type not specified
            if (empty($columnType)) {
                $stmt = $pdo->prepare("DESCRIBE `$table` `$oldName`");
                $stmt->execute();
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) {
                    echo json_encode(['ok' => false, 'error' => 'Column not found']);
                    exit;
                }
                $typeDefinition = $current['Type'];
                $nullClause = $current['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
            } else {
                $typeDefinition = $columnType;
                if ($columnLength && in_array($columnType, ['VARCHAR', 'CHAR'])) {
                    $typeDefinition .= "($columnLength)";
                }
                $nullClause = $allowNull === '1' ? 'NULL' : 'NOT NULL';
            }
            
            $sql = "ALTER TABLE `$table` CHANGE COLUMN `$oldName` `$newName` $typeDefinition $nullClause";
            
            try {
                $pdo->exec($sql);
                
                // Log migration
                $stmt = $pdo->prepare("
                    INSERT INTO schema_migrations (migration_name, executed_by, executed_at)
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute(["MODIFY COLUMN $oldName TO $newName IN $table", $me['id']]);
                
                echo json_encode(['ok' => true, 'message' => 'Column modified successfully']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'error' => 'Failed to modify column: ' . $e->getMessage()]);
            }
            break;
            
        case 'remove_column':
            $table = $_POST['table'] ?? '';
            $columnName = $_POST['column_name'] ?? '';
            
            if (empty($table) || empty($columnName)) {
                echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
                exit;
            }
            
            $sql = "ALTER TABLE `$table` DROP COLUMN `$columnName`";
            
            try {
                $pdo->exec($sql);
                
                // Log migration
                $stmt = $pdo->prepare("
                    INSERT INTO schema_migrations (migration_name, executed_by, executed_at)
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute(["DROP COLUMN $columnName FROM $table", $me['id']]);
                
                echo json_encode(['ok' => true, 'message' => 'Column removed successfully']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'error' => 'Failed to remove column: ' . $e->getMessage()]);
            }
            break;
            
        // ══════════════════════════════════════════════════════════════
        // 4. REPLICATION OPERATIONS
        // ══════════════════════════════════════════════════════════════
        case 'get_schema_history':
            $stmt = $pdo->query("
                SELECT 
                    sm.migration_name,
                    CONCAT(u.first_name, ' ', u.last_name) as executed_by,
                    DATE_FORMAT(sm.executed_at, '%Y-%m-%d %H:%i:%s') as executed_at
                FROM schema_migrations sm
                LEFT JOIN users u ON u.id = sm.executed_by
                ORDER BY sm.executed_at DESC
                LIMIT 100
            ");
            $migrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'migrations' => $migrations]);
            break;
            
        case 'get_restore_history':
            $stmt = $pdo->query("
                SELECT 
                    dr.backup_filename,
                    CONCAT(u.first_name, ' ', u.last_name) as restored_by,
                    DATE_FORMAT(dr.restored_at, '%Y-%m-%d %H:%i:%s') as restored_at
                FROM database_restores dr
                LEFT JOIN users u ON u.id = dr.restored_by
                ORDER BY dr.restored_at DESC
                LIMIT 100
            ");
            $restores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'restores' => $restores]);
            break;
            
        // ══════════════════════════════════════════════════════════════
        // 5. REPLICATION OPERATIONS
        // ══════════════════════════════════════════════════════════════
        case 'enable_replication':
            $stmt = $pdo->prepare("
                INSERT INTO system_config (config_key, config_value, updated_by, updated_at)
                VALUES ('replication_enabled', '1', ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    config_value = '1',
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
            $stmt->execute([$me['id']]);
            
            echo json_encode(['ok' => true, 'message' => 'Replication enabled']);
            break;
            
        case 'disable_replication':
            $stmt = $pdo->prepare("
                INSERT INTO system_config (config_key, config_value, updated_by, updated_at)
                VALUES ('replication_enabled', '0', ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    config_value = '0',
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
            $stmt->execute([$me['id']]);
            
            echo json_encode(['ok' => true, 'message' => 'Replication disabled']);
            break;
            
        case 'save_replication_config':
            $station = $_POST['station'] ?? '';
            $frequency = $_POST['frequency'] ?? 'realtime';
            $resolution = $_POST['resolution'] ?? 'overwrite';
            
            $stmt = $pdo->prepare("
                INSERT INTO system_config (config_key, config_value, updated_by, updated_at)
                VALUES ('replication_station', ?, ?, NOW()),
                       ('replication_frequency', ?, ?, NOW()),
                       ('conflict_resolution', ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    config_value = VALUES(config_value),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
            $stmt->execute([
                $station, $me['id'],
                $frequency, $me['id'],
                $resolution, $me['id']
            ]);
            
            echo json_encode(['ok' => true, 'message' => 'Replication settings saved']);
            break;
            
        case 'get_sync_status':
            $config = [];
            $stmt = $pdo->query("
                SELECT config_key, config_value 
                FROM system_config 
                WHERE config_key IN ('replication_enabled', 'replication_station', 'replication_frequency', 'conflict_resolution')
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $config[$row['config_key']] = $row['config_value'];
            }
            
            echo json_encode([
                'ok' => true,
                'status' => [
                    'enabled' => $config['replication_enabled'] ?? '0',
                    'station' => $config['replication_station'] ?? '',
                    'frequency' => ucfirst($config['replication_frequency'] ?? ''),
                    'resolution' => ucfirst($config['conflict_resolution'] ?? ''),
                    'last_sync' => 'Never' // TODO: Implement last sync tracking
                ]
            ]);
            break;

        // ══════════════════════════════════════════════════════════════
        // 5. SECURITY LOGS
        // ══════════════════════════════════════════════════════════════
        case 'get_security_logs':
            $stmt = $pdo->query("
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as timestamp,
                    CONCAT(u.first_name, ' ', u.last_name) as user,
                    action,
                    ip_address as ip,
                    CASE WHEN status = 1 THEN 'success' ELSE 'failed' END as status
                FROM activity_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE action IN ('Login', 'Logout', 'Database Access', 'Configuration Change')
                ORDER BY al.created_at DESC
                LIMIT 100
            ");
            
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'logs' => $logs]);
            break;
            
        case 'export_logs':
            // Export logs to Excel
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename="security_logs_' . date('Y-m-d') . '.xls"');
            
            $stmt = $pdo->query("
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as timestamp,
                    CONCAT(u.first_name, ' ', u.last_name) as user,
                    action,
                    ip_address,
                    status
                FROM activity_logs al
                LEFT JOIN users u ON u.id = al.user_id
                ORDER BY al.created_at DESC
                LIMIT 1000
            ");
            
            // Output as Excel
            echo "<table border='1'>";
            echo "<tr><th>Timestamp</th><th>User</th><th>Action</th><th>IP Address</th><th>Status</th></tr>";
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['timestamp']) . "</td>";
                echo "<td>" . htmlspecialchars($row['user']) . "</td>";
                echo "<td>" . htmlspecialchars($row['action']) . "</td>";
                echo "<td>" . htmlspecialchars($row['ip_address']) . "</td>";
                echo "<td>" . ($row['status'] ? 'Success' : 'Failed') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            exit;
            
        case 'get_alert_settings':
            $settings = [];
            $stmt = $pdo->query("
                SELECT config_key, config_value 
                FROM system_config 
                WHERE config_key LIKE 'alert_%'
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = str_replace('alert_', '', $row['config_key']);
                $settings[$key] = $row['config_value'];
            }
            
            echo json_encode(['ok' => true, 'settings' => $settings]);
            break;
            
        case 'save_alert_settings':
            $settings = [
                ['alert_failed_logins', $_POST['failed_logins'] ?? '0'],
                ['alert_unauthorized_access', $_POST['unauthorized_access'] ?? '0'],
                ['alert_schema_changes', $_POST['schema_changes'] ?? '0'],
                ['alert_data_deletion', $_POST['data_deletion'] ?? '0'],
                ['alert_backup_failure', $_POST['backup_failure'] ?? '0'],
                ['alert_emails', $_POST['emails'] ?? ''],
                ['alert_frequency', $_POST['frequency'] ?? 'immediate']
            ];
            
            foreach ($settings as [$key, $value]) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_config (config_key, config_value, updated_by, updated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        config_value = VALUES(config_value),
                        updated_by = VALUES(updated_by),
                        updated_at = NOW()
                ");
                $stmt->execute([$key, $value, $me['id']]);
            }
            
            echo json_encode(['ok' => true, 'message' => 'Alert settings saved successfully']);
            break;
            
        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
