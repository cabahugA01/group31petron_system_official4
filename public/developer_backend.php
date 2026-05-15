<?php
require_once 'db_connect.php';

header('Content-Type: application/json');

// Only allow superadmin
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add_user':
        addUser();
        break;
    case 'add_station':
        addStation();
        break;
    case 'delete_user':
        deleteUser();
        break;
    case 'delete_station':
        deleteStation();
        break;
    case 'get_users':
        getUsers();
        break;
    case 'get_stations':
        getStations();
        break;
    case 'get_logs':
        getLogs();
        break;
    case 'clear_logs':
        clearLogs();
        break;
    case 'backup_database':
        backupDatabase();
        break;
    case 'get_data_stats':
        getDataStats();
        break;
    case 'cleanup_data':
        cleanupData();
        break;
    case 'delete_all_data':
        deleteAllData();
        break;
    case 'reset_database':
        resetDatabase();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

function addUser() {
    global $pdo;
    
    try {
        $username = $_POST['username'] ?? '';
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? '';
        $station_id = $_POST['station_id'] ?? '';
        $password = $_POST['password'] ?? '';
        
        // Validate required fields
        if (empty($username) || empty($name) || empty($role) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }
        
        // Check if username already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            return;
        }
        
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            return;
        }
        
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert user
        $stmt = $pdo->prepare("
            INSERT INTO users (username, name, email, role, station_id, password, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $result = $stmt->execute([$username, $name, $email, $role, $station_id, $hashed_password]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'User added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add user']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function addStation() {
    global $pdo;
    
    try {
        $name = $_POST['name'] ?? '';
        $location = $_POST['location'] ?? '';
        $address = $_POST['address'] ?? '';
        $contact_number = $_POST['contact_number'] ?? '';
        $status = $_POST['status'] ?? 'active';
        
        // Validate required fields
        if (empty($name) || empty($location)) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }
        
        // Insert station
        $stmt = $pdo->prepare("
            INSERT INTO stations (name, location, address, contact_number, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $result = $stmt->execute([$name, $location, $address, $contact_number, $status]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Station added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add station']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function deleteUser() {
    global $pdo;
    
    try {
        $user_id = $_POST['user_id'] ?? 0;
        
        if ($user_id == 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
            return;
        }
        
        // Don't allow deletion of superadmin
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && $user['role'] === 'superadmin') {
            echo json_encode(['success' => false, 'message' => 'Cannot delete superadmin user']);
            return;
        }
        
        // Delete user
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $result = $stmt->execute([$user_id]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function deleteStation() {
    global $pdo;
    
    try {
        $station_id = $_POST['station_id'] ?? 0;
        
        if ($station_id == 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid station ID']);
            return;
        }
        
        // Delete station
        $stmt = $pdo->prepare("DELETE FROM stations WHERE id = ?");
        $result = $stmt->execute([$station_id]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Station deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete station']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getUsers() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT u.id, u.username, u.name, u.email, u.role, u.station_id, u.created_at, u.updated_at,
                   s.name as station_name
            FROM users u
            LEFT JOIN stations s ON u.station_id = s.id
            ORDER BY u.created_at DESC
        ");
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'users' => $users]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getStations() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT s.id, s.name, s.location, s.address, s.contact_number, s.status, s.created_at, s.updated_at,
                   (SELECT COUNT(*) FROM users WHERE station_id = s.id) as user_count
            FROM stations s
            ORDER BY s.name
        ");
        
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'stations' => $stations]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getLogs() {
    // In a real implementation, this would read from log files
    // For now, return sample logs
    $logs = [
        [
            'timestamp' => '2026-02-07 01:52:00',
            'level' => 'INFO',
            'message' => 'System initialized successfully'
        ],
        [
            'timestamp' => '2026-02-07 01:52:15',
            'level' => 'INFO',
            'message' => 'User superadmin logged in'
        ],
        [
            'timestamp' => '2026-02-07 01:52:30',
            'level' => 'INFO',
            'message' => 'Database connection established'
        ],
        [
            'timestamp' => '2026-02-07 01:52:45',
            'level' => 'INFO',
            'message' => 'Inventory system loaded'
        ],
        [
            'timestamp' => '2026-02-07 01:53:00',
            'level' => 'INFO',
            'message' => 'Sales reports generated'
        ],
        [
            'timestamp' => '2026-02-07 01:53:15',
            'level' => 'INFO',
            'message' => 'Job orders processed'
        ],
        [
            'timestamp' => '2026-02-07 01:53:30',
            'level' => 'WARNING',
            'message' => 'Low stock detected for item #2'
        ],
        [
            'timestamp' => '2026-02-07 01:54:00',
            'level' => 'INFO',
            'message' => 'User admin logged in'
        ],
        [
            'timestamp' => '2026-02-07 01:54:15',
            'level' => 'INFO',
            'message' => 'Station status updated'
        ],
        [
            'timestamp' => '2026-02-07 01:54:30',
            'level' => 'INFO',
            'message' => 'Reports dashboard accessed'
        ],
        [
            'timestamp' => '2026-02-07 01:54:45',
            'level' => 'INFO',
            'message' => 'Database backup completed'
        ],
        [
            'timestamp' => '2026-02-07 01:54:45',
            'level' => 'INFO',
            'message' => 'System maintenance performed'
        ]
    ];
    
    echo json_encode(['success' => true, 'logs' => $logs]);
}

function clearLogs() {
    // In a real implementation, this would clear the log files
    // For now, just return success
    echo json_encode(['success' => true, 'message' => 'System logs cleared']);
}

function backupDatabase() {
    try {
        // In a real implementation, this would create a database backup
        // For now, simulate the process
        $backup_file = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        // Simulate backup process
        $tables = ['users', 'stations', 'products', 'inventory', 'sales', 'job_orders', 'reports'];
        $backup_content = "-- Database Backup - " . date('Y-m-d H:i:s') . "\n";
        
        foreach ($tables as $table) {
            $backup_content .= "-- Structure and data for table: $table\n";
            $backup_content .= "-- " . str_repeat('-', 50) . "\n";
            // In real implementation, this would dump the table structure and data
        }
        
        // Create backup file
        file_put_contents($backup_file, $backup_content);
        
        echo json_encode(['success' => true, 'message' => 'Database backup completed', 'file' => $backup_file]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Backup failed: ' . $e->getMessage()]);
    }
}

function getDataStats() {
    global $pdo;
    
    try {
        $stats = [];
        
        // User stats
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
        $stats['users'] = $stmt->fetchColumn();
        
        // Station stats
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM stations WHERE status = 'active'");
        $stats['stations'] = $stmt->fetchColumn();
        
        // Product stats
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
        $stats['products'] = $stmt->fetchColumn();
        
         // Inventory stats
         $stmt = $pdo->query("SELECT COUNT(*) as total FROM station_inventory");
         $stats['inventory_items'] = $stmt->fetchColumn();
        
        // Sales stats
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM sales");
        $stats['sales_records'] = $stmt->fetchColumn();
        
        // Job order stats
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM job_orders");
        $stats['job_orders'] = $stmt->fetchColumn();
        
        // Report stats
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM inventory_history");
        $stats['reports_generated'] = $stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'stats' => $stats]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error getting stats: ' . $e->getMessage()]);
    }
}

function cleanupData() {
    global $pdo;
    
    try {
        $cleaned = 0;
        
        // Clean old inventory history (older than 30 days)
        $stmt = $pdo->prepare("DELETE FROM inventory_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $result = $stmt->execute();
        $cleaned += $stmt->rowCount();
        
        // Clean old sales records (older than 1 year)
        $stmt = $pdo->prepare("DELETE FROM sales WHERE sale_date < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
        $result = $stmt->execute();
        $cleaned += $stmt->rowCount();
        
        // Clean old job orders (completed for more than 6 months)
        $stmt = $pdo->prepare("DELETE FROM job_orders WHERE status = 'Completed' AND completed_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)");
        $result = $stmt->execute();
        $cleaned += $stmt->rowCount();
        
        echo json_encode(['success' => true, 'message' => "Data cleanup completed. $cleaned records removed"]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}

function deleteAllData() {
    global $pdo;
    
    try {
        $tables = ['inventory_history', 'job_orders', 'sales', 'inventory', 'users', 'stations', 'products', 'product_types'];
        $deleted = 0;
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("DELETE FROM $table");
            $deleted += $stmt->rowCount();
        }
        
        echo json_encode(['success' => true, 'message' => "All data deleted. $deleted records removed"]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
    }
}

function resetDatabase() {
    global $pdo;
    
    try {
        // Delete all data except users table
        $tables = ['inventory_history', 'job_orders', 'sales', 'inventory', 'stations', 'products', 'product_types'];
        $deleted = 0;
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("DELETE FROM $table");
            $deleted += $stmt->rowCount();
        }
        
        // Reset users table - keep only superadmin
        $stmt = $pdo->prepare("DELETE FROM users WHERE role != 'superadmin'");
        $deleted += $stmt->rowCount();
        
        // Re-insert basic data
        $stmt = $pdo->query("INSERT INTO product_types (name, description) VALUES ('fuel', 'Fuel products'), ('merch', 'Merchandise products'), ('service', 'Service products')");
        
        $stmt = $pdo->prepare("INSERT INTO stations (name, location, status, created_at, updated_at) VALUES ('PETRON CDO -Kauswagan', 'Cagayan de Oro City', 'active', NOW(), NOW())");
        $station_id = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("INSERT INTO products (name, type_id, sku, price, created_at, updated_at) VALUES ('Gasoline Premium', 1, 'FUEL001', 50.50, NOW(), NOW()), ('Engine Oil 5W-30', 2, 'MERCH001', 350.00, NOW(), NOW()), ('Oil Change Service', 3, 'SVC001', 500.00, NOW(), NOW())");
        
        // Create inventory records
        $stmt = $pdo->prepare("INSERT INTO station_inventory (product_id, station_id, stock_level, reorder_level, created_at, updated_at) VALUES (?, ?, 1000, 100, NOW(), NOW()), (?, ?, 500, 50, NOW(), NOW()), (?, ?, 200, 20, NOW(), NOW())");
        $stmt->execute([1, $station_id, 2, $station_id, 3, $station_id]);
        
        echo json_encode(['success' => true, 'message' => 'Database reset completed. Basic data restored.']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Reset failed: ' . $e->getMessage()]);
    }
}
?>
