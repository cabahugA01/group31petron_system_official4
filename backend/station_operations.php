<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/../public/db_connect.php';

// Ensure user is logged in and has proper permissions
require_login();
require_permission(VIEW_ALL_STATIONS);

$me = current_user();
$isSuper = ($me['role'] === 'superadmin');

// Set content type to JSON
header('Content-Type: application/json');

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    // Get the action from the request
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_station_details':
            $station_id = $_GET['station_id'] ?? 0;
            if (!$station_id) {
                throw new Exception('Station ID is required');
            }
            
            $stmt = $pdo->prepare("
                 SELECT s.*, 
                        (SELECT u.name FROM users u WHERE u.station_id = s.id AND u.role = 'admin' LIMIT 1) as admin_name,
                        (SELECT COUNT(*) FROM users u WHERE u.station_id = s.id AND u.status = 'active') as active_users,
                        (SELECT SUM(stock_level) FROM fuel_inventory fi WHERE fi.station_id = s.id) as fuel_level
                 FROM stations s 
                 WHERE s.id = ?
             ");
            $stmt->execute([$station_id]);
            $station = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$station) {
                throw new Exception('Station not found');
            }
            
            // Add mock profile data for demonstration
            $station['phone'] = '+63 912 345 6789';
            $station['email'] = 'station' . $station_id . '@petron.com';
            $station['opening_hours'] = '24/7';
            $station['fuel_types'] = 'Diesel, Gasoline, Premium, XCS';
            $station['notes'] = 'Strategic location with high traffic volume. Modern facilities with convenience store.';
            
            $response['success'] = true;
            $response['data'] = $station;
            break;
            
        case 'update_station':
            if (!$isSuper) {
                throw new Exception('Only Super Admin can modify stations');
            }
            
            $station_id = $_POST['station_id'] ?? 0;
            $name = trim($_POST['name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $status = $_POST['status'] ?? 'active';
            
            if (empty($name)) {
                throw new Exception('Station name is required');
            }
            
            $stmt = $pdo->prepare("UPDATE stations SET name = ?, location = ?, status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $location, $status, $station_id]);
            
            log_user_action('Station Update', "Updated station '$name' (ID: $station_id)");
            
            $response['success'] = true;
            $response['message'] = 'Station updated successfully';
            break;
            
        case 'update_station_profile':
            if (!$isSuper) {
                throw new Exception('Only Super Admin can modify station profiles');
            }
            
            $station_id = $_POST['station_id'] ?? 0;
            $name = trim($_POST['name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $opening_hours = trim($_POST['opening_hours'] ?? '');
            $fuel_types = trim($_POST['fuel_types'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($name)) {
                throw new Exception('Station name is required');
            }
            
            $stmt = $pdo->prepare("UPDATE stations SET name = ?, location = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $location, $station_id]);
            
            // In a real implementation, you would save profile data to a separate table
            // For now, we'll just log the action
            log_user_action('Station Profile Update', "Updated profile for station '$name' (ID: $station_id)");
            
            $response['success'] = true;
            $response['message'] = 'Station profile updated successfully';
            break;
            
        case 'update_station_status':
            if (!$isSuper) {
                throw new Exception('Only Super Admin can modify station status');
            }
            
            $station_id = $_POST['station_id'] ?? 0;
            $new_status = $_POST['new_status'] ?? 'active';
            $reason = trim($_POST['reason'] ?? '');
            
            if (empty($reason)) {
                throw new Exception('Reason is required for status change');
            }
            
            // Get station name for logging
            $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
            $stmt->execute([$station_id]);
            $station_name = $stmt->fetchColumn();
            
            if (!$station_name) {
                throw new Exception('Station not found');
            }
            
            $stmt = $pdo->prepare("UPDATE stations SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $station_id]);
            
            log_user_action('Station Status Change', "Changed station '$station_name' status to $new_status. Reason: $reason");
            
            $response['success'] = true;
            $response['message'] = 'Station status updated successfully';
            break;
            
        case 'get_all_stations':
            $status_filter = $_GET['status_filter'] ?? '';
            $location_filter = $_GET['location_filter'] ?? '';
            $manager_filter = $_GET['manager_filter'] ?? '';
            $search = $_GET['search'] ?? '';
            
            $sql = "
                 SELECT s.*, 
                        (SELECT u.name FROM users u WHERE u.station_id = s.id AND u.role = 'admin' LIMIT 1) as admin_name,
                        (SELECT COUNT(*) FROM users u WHERE u.station_id = s.id AND u.status = 'active') as active_users,
                        (SELECT SUM(stock_level) FROM fuel_inventory fi WHERE fi.station_id = s.id) as fuel_level
                 FROM stations s 
                 WHERE 1=1
             ";
            $params = [];
            
            if ($status_filter) {
                $sql .= " AND s.status = ?";
                $params[] = $status_filter;
            }
            
            if ($location_filter) {
                $sql .= " AND s.location = ?";
                $params[] = $location_filter;
            }
            
            if ($search) {
                $sql .= " AND s.name LIKE ?";
                $params[] = "%$search%";
            }
            
            $sql .= " ORDER BY s.name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Filter by manager if specified (PHP side filtering since it's a subquery)
            if ($manager_filter) {
                $stations = array_filter($stations, function($station) use ($manager_filter) {
                    return $station['admin_name'] === $manager_filter;
                });
            }
            
            $response['success'] = true;
            $response['data'] = array_values($stations);
            break;
            
        case 'create_station':
            if (!$isSuper) {
                throw new Exception('Only Super Admin can create stations');
            }
            
            $name = trim($_POST['name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $status = $_POST['status'] ?? 'active';
            
            if (empty($name)) {
                throw new Exception('Station name is required');
            }
            
            $stmt = $pdo->prepare("INSERT INTO stations (name, location, status, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
            $stmt->execute([$name, $location, $status]);
            
            $station_id = $pdo->lastInsertId();

             // Seed default fuel inventory rows for the new station
             // Note: Fuel products are now in the fuel_inventory table (separate domain)
             // Fuel products should already exist in the products table with type_id=1
             // This seeding is optional as fuel inventory is managed by fuel operations
             try {
                 // Get all fuel products (type_id = 1)
                 $fuelStmt = $pdo->prepare("SELECT id FROM products WHERE type_id = 1");
                 $fuelStmt->execute();
                 $fuelProducts = $fuelStmt->fetchAll(PDO::FETCH_ASSOC);
                 
                 // Create inventory records for each fuel product at this station
                 if (!empty($fuelProducts)) {
                     $ins = $pdo->prepare("
                         INSERT INTO fuel_inventory (station_id, product_id, stock_level, unit, status) 
                         VALUES (?, ?, 0, 'liters', 'active')
                         ON DUPLICATE KEY UPDATE status = 'active'
                     ");
                     foreach ($fuelProducts as $fuel) {
                         $ins->execute([$station_id, $fuel['id']]);
                     }
                 }
             } catch (Exception $e) {
                 // Don't block station creation if seeding fails
             }
            log_user_action('Station Creation', "Created station '$name' (ID: $station_id)");
            
            $response['success'] = true;
            $response['message'] = 'Station created successfully';
            $response['data'] = ['station_id' => $station_id];
            break;
            
        case 'delete_station':
            if (!$isSuper) {
                throw new Exception('Only Super Admin can delete stations');
            }
            
            $station_id = $_POST['station_id'] ?? 0;
            
            // Check if station has users
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE station_id = ?");
            $stmt->execute([$station_id]);
            $user_count = $stmt->fetchColumn();
            
            if ($user_count > 0) {
                throw new Exception('Cannot delete station with assigned users. Please reassign users first.');
            }
            
            // Get station name for logging
            $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
            $stmt->execute([$station_id]);
            $station_name = $stmt->fetchColumn();
            
            if (!$station_name) {
                throw new Exception('Station not found');
            }
            
            $stmt = $pdo->prepare("DELETE FROM stations WHERE id = ?");
            $stmt->execute([$station_id]);
            
            log_user_action('Station Deletion', "Deleted station '$station_name' (ID: $station_id)");
            
            $response['success'] = true;
            $response['message'] = 'Station deleted successfully';
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    $response['success'] = false;
}

// Send JSON response
echo json_encode($response);
?>
