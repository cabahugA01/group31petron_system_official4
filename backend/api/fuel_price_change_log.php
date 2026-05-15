<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Get current user and station
$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Only managers and above can access fuel price change log API
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Manager privileges required.']);
    exit;
}

// Handle different request methods
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetRequest();
        break;
    case 'POST':
        handlePostRequest();
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}

function handleGetRequest() {
    global $pdo, $station_id;
    
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_price_changes':
            getPriceChanges();
            break;
        case 'get_summary_stats':
            getSummaryStats();
            break;
        case 'get_fuel_types':
            getFuelTypes();
            break;
        default:
            getPriceChanges();
            break;
    }
}

function handlePostRequest() {
    global $pdo, $station_id, $me;
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_price_change':
            addPriceChange();
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
}

function getPriceChanges() {
    global $pdo, $station_id;
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = min(100, max(1, intval($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $per_page;
    
    $fuel_type = $_GET['fuel_type'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    
    try {
        // Build WHERE clause
        $where_conditions = ["station_id = ?"];
        $params = [$station_id];
        
        if (!empty($fuel_type)) {
            $where_conditions[] = "fuel_type = ?";
            $params[] = $fuel_type;
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = "DATE(change_timestamp) >= ?";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = "DATE(change_timestamp) <= ?";
            $params[] = $date_to;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM fuel_price_log $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
        
        // Get paginated results
        $sql = "
            SELECT 
                id, change_timestamp, fuel_type, old_price, new_price,
                price_difference, changed_by_name, reason_for_change,
                ip_address, user_agent
            FROM fuel_price_log 
            $where_clause
            ORDER BY change_timestamp DESC
            LIMIT ? OFFSET ?
        ";
        $params[] = $per_page;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format data
        foreach ($changes as &$change) {
            $change['change_timestamp'] = date('Y-m-d H:i:s', strtotime($change['change_timestamp']));
            $change['old_price'] = floatval($change['old_price']);
            $change['new_price'] = floatval($change['new_price']);
            $change['price_difference'] = floatval($change['price_difference']);
            $change['reason_short'] = substr($change['reason_for_change'], 0, 100);
        }
        
        echo json_encode([
            'success' => true,
            'data' => $changes,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => ceil($total / $per_page)
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getSummaryStats() {
    global $pdo, $station_id;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                fuel_type,
                COUNT(*) as change_count,
                MIN(old_price) as lowest_price,
                MAX(new_price) as highest_price,
                AVG(price_difference) as avg_change,
                SUM(price_difference) as total_change,
                MAX(change_timestamp) as last_change
            FROM fuel_price_log 
            WHERE station_id = ?
            GROUP BY fuel_type
            ORDER BY change_count DESC
        ");
        $stmt->execute([$station_id]);
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format data
        foreach ($stats as &$stat) {
            $stat['change_count'] = intval($stat['change_count']);
            $stat['lowest_price'] = floatval($stat['lowest_price']);
            $stat['highest_price'] = floatval($stat['highest_price']);
            $stat['avg_change'] = floatval($stat['avg_change']);
            $stat['total_change'] = floatval($stat['total_change']);
            $stat['last_change'] = date('Y-m-d H:i:s', strtotime($stat['last_change']));
        }
        
        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function getFuelTypes() {
    global $pdo, $station_id;
    
    try {
        $stmt = $pdo->prepare("
            SELECT fuel_type, price_per_liter 
            FROM fuel_inventory 
            WHERE station_id = ? 
            ORDER BY fuel_type
        ");
        $stmt->execute([$station_id]);
        $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format data
        foreach ($fuel_types as &$fuel) {
            $fuel['price_per_liter'] = floatval($fuel['price_per_liter']);
        }
        
        echo json_encode([
            'success' => true,
            'data' => $fuel_types
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function addPriceChange() {
    global $pdo, $station_id, $me;
    
    $fuel_type = $_POST['fuel_type'] ?? '';
    $old_price = floatval($_POST['old_price'] ?? 0);
    $new_price = floatval($_POST['new_price'] ?? 0);
    $reason = $_POST['reason'] ?? '';
    
    try {
        // Validate inputs
        if (empty($fuel_type)) {
            throw new Exception('Fuel type is required');
        }
        if ($old_price <= 0) {
            throw new Exception('Old price must be greater than 0');
        }
        if ($new_price <= 0) {
            throw new Exception('New price must be greater than 0');
        }
        if (empty($reason)) {
            throw new Exception('Reason for change is required');
        }
        
        // Get current fuel inventory price
        $stmt = $pdo->prepare("
            SELECT price_per_liter 
            FROM fuel_inventory 
            WHERE station_id = ? AND fuel_type = ?
        ");
        $stmt->execute([$station_id, $fuel_type]);
        $current_price = $stmt->fetchColumn();
        
        if ($current_price === false) {
            throw new Exception('Fuel type not found in inventory');
        }
        
        // Verify old price matches current price (allow small rounding differences)
        if (abs($current_price - $old_price) > 0.01) {
            throw new Exception('Old price does not match current inventory price');
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        try {
            // Log the price change
            $stmt = $pdo->prepare("
                INSERT INTO fuel_price_log (
                    station_id, fuel_type, old_price, new_price,
                    changed_by, changed_by_name, reason_for_change,
                    ip_address, user_agent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $station_id,
                $fuel_type,
                $old_price,
                $new_price,
                $me['id'],
                $me['name'],
                $reason,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            // Update fuel inventory price
            $stmt = $pdo->prepare("
                UPDATE fuel_inventory 
                SET price_per_liter = ?, updated_at = NOW()
                WHERE station_id = ? AND fuel_type = ?
            ");
            $stmt->execute([$new_price, $station_id, $fuel_type]);
            
            // Log to audit trail
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (action, details, user_id, station_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $audit_details = "Manager {$me['name']} (ID: {$me['id']}) changed {$fuel_type} price from ₱{$old_price} to ₱{$new_price}. Reason: {$reason}";
            $stmt->execute(['FUEL_PRICE_CHANGE', $audit_details, $me['id'], $station_id]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Fuel price change logged successfully',
                'data' => [
                    'fuel_type' => $fuel_type,
                    'old_price' => $old_price,
                    'new_price' => $new_price,
                    'price_difference' => $new_price - $old_price
                ]
            ]);
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
