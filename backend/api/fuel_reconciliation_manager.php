<?php
/**
 * Fuel Reconciliation Manager
 * Handles managerial verification workflow for fuel readings
 * Manager reviews reconciliation reports and verifies with password
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

// Check authentication
require_login();

$action = $_GET['action'] ?? $_POST['action'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$current_user = current_user();
$user_role = strtolower($current_user['role'] ?? 'staff');
$station_id = $current_user['station_id'] ?? null;

try {
    switch ($action) {
        case 'dashboard':
            // Manager dashboard for pending verifications
            if (!in_array($user_role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'error' => 'Managerial access required']);
                exit;
            }
            
            $date = $_GET['date'] ?? date('Y-m-d');
            
            // Get pending readings count
            $pending_sql = "SELECT COUNT(*) as pending_count FROM fuel_daily_readings 
                WHERE station_id = ? AND reading_date = ? AND status = 'pending'";
            $stmt = $pdo->prepare($pending_sql);
            $stmt->execute([$station_id, $date]);
            $pending_count = $stmt->fetch()['pending_count'];
            
            // Get today's summary
            $summary_sql = "SELECT 
                COUNT(*) as total_readings,
                SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(liters_sold) as total_liters,
                SUM(amount) as total_amount
                FROM fuel_daily_readings 
                WHERE station_id = ? AND reading_date = ?";
            $stmt = $pdo->prepare($summary_sql);
            $stmt->execute([$station_id, $date]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get recent exceptions
            $exception_sql = "SELECT fdr.*, fdr.current_reading as present_reading, p.pump_number, ft.name as fuel_type, u.name as encoded_by_name
                FROM fuel_daily_readings fdr
                LEFT JOIN fuel_pumps p ON fdr.pump_id = p.id
                LEFT JOIN fuel_types ft ON p.fuel_type_id = ft.id
                LEFT JOIN users u ON fdr.encoded_by = u.id
                WHERE fdr.station_id = ? AND fdr.reading_date = ?
                AND (fdr.liters_sold < 0 OR fdr.liters_sold > 1000 OR fdr.calibration > (fdr.current_reading - fdr.previous_reading))
                ORDER BY fdr.created_at DESC
                LIMIT 5";
            $stmt = $pdo->prepare($exception_sql);
            $stmt->execute([$station_id, $date]);
            $exceptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'pending_count' => $pending_count,
                    'summary' => $summary,
                    'exceptions' => $exceptions,
                    'date' => $date
                ]
            ]);
            break;
            
        case 'get_pending_readings':
            // Get detailed pending readings for verification
            if (!in_array($user_role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'error' => 'Managerial access required']);
                exit;
            }
            
            $date = $_GET['date'] ?? date('Y-m-d');
            
            $sql = "SELECT fdr.*, fdr.current_reading as present_reading, p.pump_number, ft.name as fuel_type, u.name as encoded_by_name,
                (fdr.current_reading - fdr.previous_reading) as raw_difference
                FROM fuel_daily_readings fdr
                LEFT JOIN fuel_pumps p ON fdr.pump_id = p.id
                LEFT JOIN fuel_types ft ON p.fuel_type_id = ft.id
                LEFT JOIN users u ON fdr.encoded_by = u.id
                WHERE fdr.station_id = ? AND fdr.reading_date = ? AND fdr.status = 'pending'
                ORDER BY p.pump_number, fdr.shift_period, fdr.created_at";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $date]);
            $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $readings]);
            break;
            
        case 'verify_reading':
            // Manager verification with password confirmation
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            if (!in_array($user_role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'error' => 'Managerial access required']);
                exit;
            }
            
            $reading_id = $_POST['reading_id'] ?? 0;
            $manager_password = $_POST['manager_password'] ?? '';
            $action_type = $_POST['verification_action'] ?? 'approve'; // approve or reject
            $notes = $_POST['notes'] ?? '';
            
            if (empty($reading_id) || empty($manager_password)) {
                echo json_encode(['success' => false, 'error' => 'Reading ID and password required']);
                exit;
            }
            
            // Verify manager password
            if (!password_verify($manager_password, $current_user['password'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid manager password']);
                exit;
            }
            
            // Get reading details
            $reading_sql = "SELECT fdr.*, p.pump_number, ft.name as fuel_type
                FROM fuel_daily_readings fdr
                LEFT JOIN fuel_pumps p ON fdr.pump_id = p.id
                LEFT JOIN fuel_types ft ON p.fuel_type_id = ft.id
                WHERE fdr.id = ? AND fdr.station_id = ?";
            $stmt = $pdo->prepare($reading_sql);
            $stmt->execute([$reading_id, $station_id]);
            $reading = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reading) {
                echo json_encode(['success' => false, 'error' => 'Reading not found']);
                exit;
            }
            
            if ($reading['status'] !== 'pending') {
                echo json_encode(['success' => false, 'error' => 'Reading already processed']);
                exit;
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Update reading status
                $new_status = $action_type === 'approve' ? 'verified' : 'rejected';
                $update_sql = "UPDATE fuel_daily_readings SET 
                    status = ?, 
                    verified_by = ?, 
                    verified_at = NOW(),
                    manager_notes = ?
                    WHERE id = ?";
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([$new_status, $current_user['id'], $notes, $reading_id]);
                
                // Log verification activity
                log_activity($pdo, $current_user['id'], 'Fuel Reading Verification', 
                    "$action_type reading ID $reading_id for Pump {$reading['pump_number']}");
                
                // If approved, update inventory and create reconciliation record
                if ($action_type === 'approve') {
                    // Update fuel inventory
                    update_fuel_inventory($pdo, $reading['pump_id'], $reading['liters_sold']);
                    
                    // Create reconciliation record
                    $reconcile_sql = "INSERT INTO fuel_reconciliation 
                        (station_id, pump_id, reconciliation_date, present_reading, previous_reading, 
                         calibration, liters_sold, amount, verified_by, verified_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $stmt = $pdo->prepare($reconcile_sql);
                    $stmt->execute([$station_id, $reading['pump_id'], $reading['reading_date'], 
                                  $reading['present_reading'], $reading['previous_reading'], 
                                  $reading['calibration'], $reading['liters_sold'], $reading['amount'], 
                                  $current_user['id']]);
                }
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Reading $new_status successfully",
                    'reading' => [
                        'id' => $reading_id,
                        'pump_number' => $reading['pump_number'],
                        'fuel_type' => $reading['fuel_type'],
                        'liters_sold' => $reading['liters_sold'],
                        'amount' => $reading['amount']
                    ]
                ]);
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        case 'bulk_verify':
            // Bulk verification for multiple readings
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            if (!in_array($user_role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'error' => 'Managerial access required']);
                exit;
            }
            
            $reading_ids = $_POST['reading_ids'] ?? [];
            $manager_password = $_POST['manager_password'] ?? '';
            $action_type = $_POST['verification_action'] ?? 'approve';
            
            if (empty($reading_ids) || empty($manager_password)) {
                echo json_encode(['success' => false, 'error' => 'Reading IDs and password required']);
                exit;
            }
            
            // Verify manager password
            if (!password_verify($manager_password, $current_user['password'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid manager password']);
                exit;
            }
            
            $pdo->beginTransaction();
            $processed = 0;
            $errors = [];
            
            try {
                foreach ($reading_ids as $reading_id) {
                    // Get reading details
                    $reading_sql = "SELECT * FROM fuel_daily_readings WHERE id = ? AND station_id = ? AND status = 'pending'";
                    $stmt = $pdo->prepare($reading_sql);
                    $stmt->execute([$reading_id, $station_id]);
                    $reading = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$reading) {
                        $errors[] = "Reading ID $reading_id not found or already processed";
                        continue;
                    }
                    
                    // Update reading status
                    $new_status = $action_type === 'approve' ? 'verified' : 'rejected';
                    $update_sql = "UPDATE fuel_daily_readings SET status = ?, verified_by = ?, verified_at = NOW() WHERE id = ?";
                    $stmt = $pdo->prepare($update_sql);
                    $stmt->execute([$new_status, $current_user['id'], $reading_id]);
                    
                    // If approved, update inventory
                    if ($action_type === 'approve') {
                        update_fuel_inventory($pdo, $reading['pump_id'], $reading['liters_sold']);
                    }
                    
                    $processed++;
                }
                
                $pdo->commit();
                
                log_activity($pdo, $current_user['id'], 'Bulk Fuel Reading Verification', 
                    "Processed $processed readings with action: $action_type");
                
                echo json_encode([
                    'success' => true,
                    'message' => "Bulk verification completed",
                    'processed' => $processed,
                    'errors' => $errors
                ]);
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        case 'adjust_reading':
            // Manager adjustment of fuel reading
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            if (!in_array($user_role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'error' => 'Managerial access required']);
                exit;
            }
            
            $reading_id = $_POST['reading_id'] ?? 0;
            $present_reading = $_POST['present_reading'] ?? 0;
            $calibration = $_POST['calibration'] ?? 0;
            $price_per_liter = $_POST['price_per_liter'] ?? 0;
            $liters_sold = $_POST['liters_sold'] ?? 0;
            $adjustment_reason = $_POST['adjustment_reason'] ?? '';
            $manager_password = $_POST['manager_password'] ?? '';
            
            if (empty($reading_id) || empty($manager_password) || empty($adjustment_reason)) {
                echo json_encode(['success' => false, 'error' => 'Reading ID, password, and reason required']);
                exit;
            }
            
            // Verify manager password
            if (!password_verify($manager_password, $current_user['password'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid manager password']);
                exit;
            }
            
            // Get reading details
            $reading_sql = "SELECT fdr.*, p.pump_number, ft.name as fuel_type
                FROM fuel_daily_readings fdr
                LEFT JOIN fuel_pumps p ON fdr.pump_id = p.id
                LEFT JOIN fuel_types ft ON p.fuel_type_id = ft.id
                WHERE fdr.id = ? AND fdr.station_id = ?";
            $stmt = $pdo->prepare($reading_sql);
            $stmt->execute([$reading_id, $station_id]);
            $reading = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reading) {
                echo json_encode(['success' => false, 'error' => 'Reading not found']);
                exit;
            }
            
            if ($reading['status'] !== 'pending') {
                echo json_encode(['success' => false, 'error' => 'Reading already processed']);
                exit;
            }
            
            // Calculate new amount
            $new_amount = $liters_sold * $price_per_liter;
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Update reading with adjusted values
                $update_sql = "UPDATE fuel_daily_readings SET 
                    current_reading = ?, 
                    calibration = ?, 
                    price_per_liter = ?, 
                    liters_sold = ?, 
                    amount = ?,
                    status = 'adjusted',
                    adjusted_by = ?, 
                    adjusted_at = NOW(),
                    adjustment_reason = ?
                    WHERE id = ?";
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([$present_reading, $calibration, $price_per_liter, $liters_sold, $new_amount, 
                              $current_user['id'], $adjustment_reason, $reading_id]);
                
                // Log adjustment activity
                log_activity($pdo, $current_user['id'], 'Fuel Reading Adjustment', 
                    "Adjusted reading ID $reading_id for Pump {$reading['pump_number']}: Present=$present_reading, Calibration=$calibration, Liters=$liters_sold");
                
                // Create variance report for investigation
                $variance_sql = "INSERT INTO fuel_variance_reports 
                    (station_id, pump_id, fuel_type, variance_liters, variance_amount, status, user_id, created_at, notes) 
                    VALUES (?, ?, ?, ?, ?, 'investigating', ?, NOW(), ?)";
                $stmt = $pdo->prepare($variance_sql);
                $stmt->execute([$station_id, $reading['pump_id'], $reading['fuel_type'], 
                              $liters_sold - $reading['liters_sold'], $new_amount - $reading['amount'], 
                              $current_user['id'], $adjustment_reason]);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Reading adjusted successfully",
                    'reading' => [
                        'id' => $reading_id,
                        'pump_number' => $reading['pump_number'],
                        'fuel_type' => $reading['fuel_type'],
                        'old_liters' => $reading['liters_sold'],
                        'new_liters' => $liters_sold,
                        'old_amount' => $reading['amount'],
                        'new_amount' => $new_amount
                    ]
                ]);
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        case 'get_reconciliation_report':
            // Generate detailed reconciliation report
            if (!in_array($user_role, ['manager', 'admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'error' => 'Managerial access required']);
                exit;
            }
            
            $date = $_GET['date'] ?? date('Y-m-d');
            
            // Per pump breakdown
            $pump_sql = "SELECT p.pump_number, ft.name as fuel_type,
                COUNT(*) as readings_count,
                SUM(CASE WHEN fdr.status = 'verified' THEN fdr.liters_sold ELSE 0 END) as verified_liters,
                SUM(CASE WHEN fdr.status = 'verified' THEN fdr.amount ELSE 0 END) as verified_amount,
                SUM(CASE WHEN fdr.status = 'pending' THEN fdr.liters_sold ELSE 0 END) as pending_liters,
                SUM(CASE WHEN fdr.status = 'rejected' THEN fdr.liters_sold ELSE 0 END) as rejected_liters
                FROM fuel_daily_readings fdr
                LEFT JOIN fuel_pumps p ON fdr.pump_id = p.id
                LEFT JOIN fuel_types ft ON p.fuel_type_id = ft.id
                WHERE fdr.station_id = ? AND fdr.reading_date = ?
                GROUP BY fdr.pump_id, p.pump_number, ft.name
                ORDER BY p.pump_number";
            
            $stmt = $pdo->prepare($pump_sql);
            $stmt->execute([$station_id, $date]);
            $pump_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Summary statistics
            $summary_sql = "SELECT 
                COUNT(*) as total_readings,
                SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = 'verified' THEN liters_sold ELSE 0 END) as total_liters,
                SUM(CASE WHEN status = 'verified' THEN amount ELSE 0 END) as total_amount,
                AVG(CASE WHEN status = 'verified' THEN liters_sold ELSE NULL END) as avg_liters_per_reading
                FROM fuel_daily_readings 
                WHERE station_id = ? AND reading_date = ?";
            $stmt = $pdo->prepare($summary_sql);
            $stmt->execute([$station_id, $date]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'date' => $date,
                    'pump_breakdown' => $pump_breakdown,
                    'summary' => $summary
                ]
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Update fuel inventory after verification
 */
function update_fuel_inventory($pdo, $pump_id, $liters_sold) {
    // Get fuel type and station from pump
    $pump_sql = "SELECT fuel_type_id, station_id FROM fuel_pumps WHERE id = ?";
    $stmt = $pdo->prepare($pump_sql);
    $stmt->execute([$pump_id]);
    $pump = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($pump && $pump['fuel_type_id']) {
        $fuel_type_id = $pump['fuel_type_id'];
        $station_id = $pump['station_id'];
        
        // Get current inventory
        $inventory_sql = "SELECT current_stock FROM fuel_inventory 
            WHERE station_id = ? AND fuel_type_id = ?";
        $stmt = $pdo->prepare($inventory_sql);
        $stmt->execute([$station_id, $fuel_type_id]);
        $current_stock = $stmt->fetchColumn();
        
        if ($current_stock !== null && $current_stock >= $liters_sold) {
            // Deduct from inventory
            $update_sql = "UPDATE fuel_inventory SET current_stock = current_stock - ?, 
                          last_updated = NOW() 
                          WHERE station_id = ? AND fuel_type_id = ?";
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([$liters_sold, $station_id, $fuel_type_id]);
            
            // Check for low stock alert
            if ($current_stock - $liters_sold < 500) { // 500L threshold
                create_low_stock_alert($pdo, $station_id, $fuel_type_id, $current_stock - $liters_sold);
            }
        }
    }
}

/**
 * Create low stock alert
 */
function create_low_stock_alert($pdo, $station_id, $fuel_type_id, $remaining_stock) {
    
    $alert_sql = "INSERT INTO low_stock_alerts 
        (station_id, fuel_type_id, current_stock, alert_level, created_at) 
        VALUES (?, ?, ?, 'critical', NOW())";
    $stmt = $pdo->prepare($alert_sql);
    $stmt->execute([$station_id, $fuel_type_id, $remaining_stock]);
}
?>
