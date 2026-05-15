<?php
/**
 * Developer Operations API
 * Handles all developer panel operations via REST API
 * Only accessible by superadmin
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

// Ensure user is logged in
require_login();

// Only allow superadmin access
$me = current_user();
$role = strtolower(trim($me['role'] ?? 'staff'));

if ($role !== 'superadmin') {
    echo json_encode(['success' => false, 'error' => 'Access denied. Super Admin only.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// Database constants for backup
define('DB_HOST', 'localhost');
define('DB_NAME', 'petron_pos_db_secure');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    switch ($action) {
        // ==================== SYSTEM LOGS ====================
        
        case 'get_system_logs':
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            $log_type = $_GET['log_type'] ?? 'all';
            $search = $_GET['search'] ?? '';
            
            // Check if activity_logs table exists
            try {
                $checkTable = $pdo->query("SHOW TABLES LIKE 'activity_logs'");
                if ($checkTable->rowCount() == 0) {
                    echo json_encode(['success' => true, 'data' => [], 'message' => 'No activity_logs table found']);
                    break;
                }
            } catch (Exception $e) {
                echo json_encode(['success' => true, 'data' => [], 'message' => 'Error checking logs table']);
                break;
            }
            
            $sql = "SELECT * FROM activity_logs WHERE 1=1";
            $params = [];
            
            if ($log_type !== 'all' && $log_type) {
                $sql .= " AND action_type = ?";
                $params[] = $log_type;
            }
            
            if ($search) {
                $sql .= " AND (description LIKE ? OR user_name LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countSql = "SELECT COUNT(*) FROM activity_logs WHERE 1=1";
            $countParams = [];
            if ($log_type !== 'all' && $log_type) {
                $countSql .= " AND action_type = ?";
                $countParams[] = $log_type;
            }
            if ($search) {
                $countSql .= " AND (description LIKE ? OR user_name LIKE ?)";
                $countParams[] = "%$search%";
                $countParams[] = "%$search%";
            }
            $total = $pdo->prepare($countSql);
            $total->execute($countParams);
            $totalCount = $total->fetchColumn();
            
            echo json_encode([
                'success' => true, 
                'data' => $logs,
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;
            
        case 'clear_system_logs':
            $older_than_days = intval($_POST['older_than_days'] ?? 30);
            
            try {
                $checkTable = $pdo->query("SHOW TABLES LIKE 'activity_logs'");
                if ($checkTable->rowCount() == 0) {
                    echo json_encode(['success' => false, 'error' => 'No activity_logs table found']);
                    break;
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Error checking logs table']);
                break;
            }
            
            // Delete logs older than specified days
            $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$older_than_days]);
            $deletedCount = $stmt->rowCount();
            
            log_user_action('Clear System Logs', "Cleared $deletedCount logs older than $older_than_days days");
            
            echo json_encode([
                'success' => true, 
                'message' => "Cleared $deletedCount log entries older than $older_than_days days",
                'deleted_count' => $deletedCount
            ]);
            break;
            
        case 'get_log_types':
            try {
                $checkTable = $pdo->query("SHOW TABLES LIKE 'activity_logs'");
                if ($checkTable->rowCount() == 0) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
            } catch (Exception $e) {
                echo json_encode(['success' => true, 'data' => []]);
                break;
            }
            
            $stmt = $pdo->query("SELECT DISTINCT action_type FROM activity_logs ORDER BY action_type");
            $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            echo json_encode(['success' => true, 'data' => $types]);
            break;
            
        // ==================== DATABASE MANAGEMENT ====================
        
        case 'get_database_status':
            $status = [
                'database' => DB_NAME,
                'connected' => true,
                'tables' => [],
                'size_mb' => 0,
                'last_backup' => 'Never',
                'backup_count' => 0
            ];
            
            // Get table counts
            $tables = ['users', 'stations', 'products', 'inventory', 'sales', 'job_orders', 'activity_logs', 'fuel_inventory', 'fuel_pumps', 'fuel_types'];
            foreach ($tables as $table) {
                try {
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
                    $status['tables'][$table] = [
                        'count' => $countStmt->fetchColumn(),
                        'status' => 'ok'
                    ];
                } catch (Exception $e) {
                    $status['tables'][$table] = [
                        'count' => 0,
                        'status' => 'not_found'
                    ];
                }
            }
            
            // Get database size
            try {
                $sizeStmt = $pdo->query("
                    SELECT SUM(data_length + index_length) as size 
                    FROM information_schema.tables 
                    WHERE table_schema = DATABASE()
                ");
                $size = $sizeStmt->fetchColumn();
                $status['size_mb'] = $size ? round($size / 1024 / 1024, 2) : 0;
            } catch (Exception $e) {
                $status['size_mb'] = 0;
            }
            
            // Check backups directory
            $backupDir = __DIR__ . '/../../backups/';
            if (is_dir($backupDir)) {
                $backups = glob($backupDir . '*.sql');
                $status['backup_count'] = count($backups);
                if (!empty($backups)) {
                    $latestBackup = end($backups);
                    $status['last_backup'] = date('Y-m-d H:i:s', filemtime($latestBackup));
                }
            }
            
            // Get MySQL version
            try {
                $versionStmt = $pdo->query("SELECT VERSION()");
                $status['mysql_version'] = $versionStmt->fetchColumn();
            } catch (Exception $e) {
                $status['mysql_version'] = 'Unknown';
            }
            
            echo json_encode(['success' => true, 'data' => $status]);
            break;
            
        case 'backup_database':
            $backupDir = __DIR__ . '/../../backups/';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            $filename = 'backup_' . date('Ymd_His') . '.sql';
            $filepath = $backupDir . $filename;
            
            // Use mysql.sock for XAMPP
            $socket = '/opt/lampp/var/mysql/mysql.sock';
            $command = sprintf(
                'mysqldump --socket=%s -u%s %s > %s 2>&1',
                escapeshellarg($socket),
                escapeshellarg(DB_USER),
                escapeshellarg(DB_NAME),
                escapeshellarg($filepath)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($filepath)) {
                $fileSize = round(filesize($filepath) / 1024, 2);
                log_user_action('Database Backup', "Created backup: $filename ($fileSize KB)");
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Backup created successfully',
                    'file' => $filename,
                    'size_kb' => $fileSize
                ]);
            } else {
                $errorOutput = implode("\n", $output);
                echo json_encode([
                    'success' => false, 
                    'error' => 'Backup failed. Check if mysqldump is available.',
                    'details' => $errorOutput
                ]);
            }
            break;
            
        case 'list_backups':
            $backupDir = __DIR__ . '/../../backups/';
            $backups = [];
            
            if (is_dir($backupDir)) {
                $files = glob($backupDir . '*.sql');
                foreach ($files as $file) {
                    $backups[] = [
                        'filename' => basename($file),
                        'size_kb' => round(filesize($file) / 1024, 2),
                        'created_at' => date('Y-m-d H:i:s', filemtime($file))
                    ];
                }
            }
            
            // Sort by created_at descending
            usort($backups, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            
            echo json_encode(['success' => true, 'data' => $backups]);
            break;
            
        case 'delete_backup':
            $filename = $_POST['filename'] ?? '';
            
            if (empty($filename)) {
                echo json_encode(['success' => false, 'error' => 'Filename is required']);
                break;
            }
            
            // Security: only allow .sql files in backups directory
            if (!preg_match('/^backup_\d+_\d+\.sql$/', $filename)) {
                echo json_encode(['success' => false, 'error' => 'Invalid filename format']);
                break;
            }
            
            $filepath = __DIR__ . '/../../backups/' . $filename;
            
            if (file_exists($filepath)) {
                unlink($filepath);
                log_user_action('Delete Backup', "Deleted backup: $filename");
                echo json_encode(['success' => true, 'message' => 'Backup deleted']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Backup file not found']);
            }
            break;
            
        // ==================== DATA STATISTICS ====================
        
        case 'get_data_stats':
            $stats = [
                'users' => ['total' => 0, 'active' => 0, 'by_role' => []],
                'stations' => ['total' => 0, 'active' => 0],
                'products' => ['total' => 0],
                'inventory' => ['total' => 0, 'low_stock' => 0],
                'sales' => ['total' => 0, 'today' => 0, 'this_month' => 0],
                'job_orders' => ['total' => 0, 'pending' => 0, 'completed' => 0],
                'activity_logs' => ['total' => 0],
                'fuel' => ['types' => 0, 'pumps' => 0]
            ];
            
            // Users stats
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM users");
                $stats['users']['total'] = $stmt->fetchColumn();
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'");
                $stats['users']['active'] = $stmt->fetchColumn();
                
                $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
                $stats['users']['by_role'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Exception $e) {}
            
            // Stations stats
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM stations");
                $stats['stations']['total'] = $stmt->fetchColumn();
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM stations WHERE status = 'active'");
                $stats['stations']['active'] = $stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // Products stats
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM products");
                $stats['products']['total'] = $stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // Inventory stats
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM inventory");
                $stats['inventory']['total'] = $stmt->fetchColumn();
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level");
                $stats['inventory']['low_stock'] = $stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // Sales stats
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM sales");
                $stats['sales']['total'] = $stmt->fetchColumn();
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM sales WHERE DATE(created_at) = CURDATE()");
                $stats['sales']['today'] = $stmt->fetchColumn();
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM sales WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
                $stats['sales']['this_month'] = $stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // Job orders stats
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM job_orders");
                $stats['job_orders']['total'] = $stmt->fetchColumn();
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM job_orders WHERE status = 'pending' OR status = 'in_progress'");
                $stats['job_orders']['pending'] = $stmt->fetchColumn();
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM job_orders WHERE status = 'completed'");
                $stats['job_orders']['completed'] = $stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // Activity logs
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM activity_logs");
                $stats['activity_logs']['total'] = $stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // Fuel stats
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM fuel_types");
                $stats['fuel']['types'] = $stmt->fetchColumn();
            } catch (Exception $e) {}
            
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM fuel_pumps");
                $stats['fuel']['pumps'] = $stmt->fetchColumn();
            } catch (Exception $e) {}
            
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        case 'cleanup_old_data':
            $days = intval($_POST['days'] ?? 90);
            $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] === 'true';
            
            $results = [
                'dry_run' => $dry_run,
                'older_than_days' => $days,
                'tables_cleaned' => []
            ];
            
            // Tables to clean with their date columns
            $tablesToClean = [
                'activity_logs' => 'created_at'
            ];
            
            foreach ($tablesToClean as $table => $dateColumn) {
                try {
                    // Check if table exists
                    $checkTable = $pdo->query("SHOW TABLES LIKE '$table'");
                    if ($checkTable->rowCount() == 0) {
                        continue;
                    }
                    
                    // Count records to be deleted
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$dateColumn` < DATE_SUB(NOW(), INTERVAL ? DAY)");
                    $countStmt->execute([$days]);
                    $count = $countStmt->fetchColumn();
                    
                    if ($count > 0) {
                        if ($dry_run) {
                            $results['tables_cleaned'][$table] = [
                                'records_found' => $count,
                                'action' => 'would_delete'
                            ];
                        } else {
                            $deleteStmt = $pdo->prepare("DELETE FROM `$table` WHERE `$dateColumn` < DATE_SUB(NOW(), INTERVAL ? DAY)");
                            $deleteStmt->execute([$days]);
                            $results['tables_cleaned'][$table] = [
                                'records_deleted' => $deleteStmt->rowCount(),
                                'action' => 'deleted'
                            ];
                        }
                    }
                } catch (Exception $e) {
                    $results['tables_cleaned'][$table] = [
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            if (!$dry_run) {
                log_user_action('Data Cleanup', "Cleaned data older than $days days");
            }
            
            echo json_encode(['success' => true, 'data' => $results]);
            break;
            
        // ==================== SYSTEM CONFIGURATION ====================
        
        case 'get_system_config':
            $config = [
                'system_version' => '2.0.1',
                'php_version' => PHP_VERSION,
                'mysql_version' => 'Unknown',
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
                'environment' => 'Production',
                'debug_mode' => 'Disabled',
                'max_upload_size' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'session_timeout' => ini_get('session.gc_maxlifetime') / 60 . ' minutes',
                'timezone' => date_default_timezone_get(),
                'current_time' => date('Y-m-d H:i:s')
            ];
            
            // Get MySQL version
            try {
                $stmt = $pdo->query("SELECT VERSION()");
                $config['mysql_version'] = $stmt->fetchColumn();
            } catch (Exception $e) {}
            
            // Database info
            $config['database'] = [
                'name' => DB_NAME,
                'host' => DB_HOST
            ];
            
            echo json_encode(['success' => true, 'data' => $config]);
            break;
            
        case 'export_config':
            // Gather all configuration
            $config = [
                'exported_at' => date('Y-m-d H:i:s'),
                'exported_by' => $me['username'] ?? 'unknown',
                'database' => [
                    'name' => DB_NAME,
                    'host' => DB_HOST
                ],
                'php' => [
                    'version' => PHP_VERSION,
                    'extensions' => get_loaded_extensions()
                ],
                'server' => [
                    'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'
                ],
                'ini_settings' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time')
                ]
            ];
            
            // Get table structures (without data)
            try {
                $stmt = $pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $config['database']['tables'] = [];
                
                foreach ($tables as $table) {
                    $createStmt = $pdo->query("SHOW CREATE TABLE `$table`");
                    $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
                    $config['database']['tables'][$table] = $createRow['Create Table'] ?? '';
                }
            } catch (Exception $e) {
                $config['database']['tables_error'] = $e->getMessage();
            }
            
            // Save to file
            $exportDir = __DIR__ . '/../../backups/';
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0755, true);
            }
            
            $filename = 'config_export_' . date('Ymd_His') . '.json';
            $filepath = $exportDir . $filename;
            
            file_put_contents($filepath, json_encode($config, JSON_PRETTY_PRINT));
            
            log_user_action('Export Config', "Exported system configuration to $filename");
            
            echo json_encode([
                'success' => true, 
                'message' => 'Configuration exported successfully',
                'file' => $filename,
                'download_url' => '../backups/' . $filename
            ]);
            break;
            
        // ==================== USER CREATION ====================
            
        case 'create_user':
            $username = trim($_POST['username'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? 'staff';
            $station_id = $_POST['station_id'] ?? null;
            $password = $_POST['password'] ?? '';
            
            if (empty($username) || empty($name) || empty($email)) {
                echo json_encode(['success' => false, 'error' => 'Username, name, and email are required']);
                break;
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => 'Invalid email address']);
                break;
            }
            
            // Check if username exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $checkStmt->execute([$username]);
            if ($checkStmt->rowCount() > 0) {
                echo json_encode(['success' => false, 'error' => 'Username already exists']);
                break;
            }
            
            // Check if email exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            if ($checkStmt->rowCount() > 0) {
                echo json_encode(['success' => false, 'error' => 'Email already exists']);
                break;
            }
            
            // Generate password if not provided
            if (empty($password)) {
                $password = 'User123!';
            }
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password, name, email, role, station_id, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
            ");
            $stmt->execute([$username, $hashedPassword, $name, $email, $role, $station_id ?: null]);
            
            $userId = $pdo->lastInsertId();
            
            log_user_action('Create User', "Created user '$username' with role '$role'");
            
            echo json_encode([
                'success' => true, 
                'message' => "User created successfully. Password: $password",
                'user_id' => $userId,
                'default_password' => $password
            ]);
            break;
            
        // ==================== STATION OPERATIONS ====================
            
        case 'reset_station_data':
            $station_id = intval($_POST['station_id'] ?? 0);
            
            if ($station_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Valid station ID is required']);
                break;
            }
            
            // Get station name
            $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
            $stmt->execute([$station_id]);
            $stationName = $stmt->fetchColumn();
            
            if (!$stationName) {
                echo json_encode(['success' => false, 'error' => 'Station not found']);
                break;
            }
            
            $results = [];
            
            // Reset station-specific data (be careful here!)
            // This is a soft reset - we don't delete users, just reset operational data
            
            // Reset inventory for this station
            try {
                $stmt = $pdo->prepare("UPDATE inventory SET quantity = 0 WHERE station_id = ?");
                $stmt->execute([$station_id]);
                $results['inventory_reset'] = $stmt->rowCount();
            } catch (Exception $e) {
                $results['inventory_error'] = $e->getMessage();
            }
            
            // Reset fuel readings for this station
            try {
                $stmt = $pdo->prepare("UPDATE fuel_pump_readings SET current_reading = 0 WHERE station_id = ?");
                $stmt->execute([$station_id]);
                $results['fuel_readings_reset'] = $stmt->rowCount();
            } catch (Exception $e) {
                $results['fuel_readings_error'] = $e->getMessage();
            }
            
            log_user_action('Reset Station Data', "Reset data for station '$stationName' (ID: $station_id)");
            
            echo json_encode([
                'success' => true, 
                'message' => "Station '$stationName' data reset successfully",
                'results' => $results
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}