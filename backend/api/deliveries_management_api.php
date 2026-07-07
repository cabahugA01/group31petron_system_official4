<?php
// Deliveries Management API
// Handles all delivery management operations with database-driven approach

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get current user and station
session_start();
$me = current_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$station_id = $me['station_id'] ?? 1;
$role = $me['role'] ?? '';

// Check permissions
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Manager privileges required.']);
    exit;
}

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        // Get pending deliveries (approved POs)
        case 'get_pending_deliveries':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed');
            }
            
            // Get fuel deliveries from approved POs
            $stmt = $pdo->prepare("
                SELECT 
                    fpo.id,
                    fpo.po_number,
                    ft.name as product_name,
                    'Fuel' as category,
                    fpo.volume as quantity_ordered,
                    fpo.unit_price,
                    fpo.total_amount,
                    fpo.expected_delivery_date,
                    fs.supplier_name,
                    fpo.approved_at,
                    'fuel' as delivery_type,
                    fpo.fuel_type_id,
                    fpo.supplier_id
                FROM fuel_purchase_orders fpo
                JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                LEFT JOIN fuel_suppliers fs ON fpo.supplier_id = fs.id
                WHERE fpo.station_id = ? AND fpo.status = 'approved'
                ORDER BY fpo.expected_delivery_date ASC
            ");
            $stmt->execute([$station_id]);
            $fuel_pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get merchandise deliveries from approved stock requests
            $stmt = $pdo->prepare("
                SELECT 
                    sr.id,
                    CONCAT('MPO-', DATE_FORMAT(sr.created_at, '%Y-%m-%d'), '-', LPAD(sr.id, 4, '0')) as po_number,
                    sr.product_name,
                    ip.category,
                    sr.quantity_requested as quantity_ordered,
                    ip.unit_cost as unit_price,
                    sr.quantity_requested * ip.unit_cost as total_amount,
                    sr.approved_at as expected_delivery_date,
                    'Default Supplier' as supplier_name,
                    sr.approved_at,
                    'merchandise' as delivery_type,
                    ip.id as fuel_type_id,
                    NULL as supplier_id
                FROM stock_requests sr
                JOIN inventory_products ip ON sr.product_name = ip.product_name
                WHERE sr.station_id = ? AND sr.status = 'Approved'
                ORDER BY sr.approved_at ASC
            ");
            $stmt->execute([$station_id]);
            $merchandise_pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Combine and format results
            $pending_deliveries = array_merge($fuel_pending, $merchandise_pending);
            
            // Add urgency status
            foreach ($pending_deliveries as &$delivery) {
                $expected_date = $delivery['expected_delivery_date'];
                if ($expected_date) {
                    $days_diff = (strtotime(date('Y-m-d')) - strtotime($expected_date)) / (60 * 60 * 24);
                    if ($days_diff > 0) {
                        $delivery['urgency_status'] = 'overdue';
                    } elseif ($days_diff == 0) {
                        $delivery['urgency_status'] = 'due_today';
                    } elseif ($days_diff >= -3) {
                        $delivery['urgency_status'] = 'due_soon';
                    } else {
                        $delivery['urgency_status'] = 'on_schedule';
                    }
                } else {
                    $delivery['urgency_status'] = 'unknown';
                }
            }
            
            echo json_encode(['success' => true, 'data' => $pending_deliveries]);
            break;
            
        // Get completed deliveries
        case 'get_completed_deliveries':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed');
            }
            
            // Get completed fuel deliveries
            $stmt = $pdo->prepare("
                SELECT 
                    fd.id,
                    fpo.po_number,
                    ft.name as product_name,
                    'Fuel' as category,
                    fd.ordered_volume as quantity_ordered,
                    fd.actual_volume,
                    fd.variance,
                    fd.variance_percentage,
                    fd.delivery_date,
                    fd.status,
                    fd.quality_status,
                    fs.supplier_name,
                    u1.name as encoded_by_name,
                    u2.name as confirmed_by_name,
                    fd.encoded_at,
                    fd.confirmed_at,
                    fd.closed_at,
                    'fuel' as delivery_type
                FROM fuel_deliveries fd
                JOIN fuel_purchase_orders fpo ON fd.po_id = fpo.id
                JOIN fuel_types ft ON fd.fuel_type_id = ft.id
                LEFT JOIN fuel_suppliers fs ON fd.supplier_id = fs.id
                LEFT JOIN users u1 ON fd.encoded_by = u1.id
                LEFT JOIN users u2 ON fd.confirmed_by = u2.id
                WHERE fd.station_id = ?
                ORDER BY fd.delivery_date DESC
            ");
            $stmt->execute([$station_id]);
            $fuel_completed = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get completed merchandise deliveries
            $stmt = $pdo->prepare("
                SELECT 
                    d.id,
                    CONCAT('MPO-', DATE_FORMAT(sr.created_at, '%Y-%m-%d'), '-', LPAD(sr.id, 4, '0')) as po_number,
                    sr.product_name,
                    ip.category,
                    sr.quantity_requested as quantity_ordered,
                    sr.actual_quantity as actual_volume,
                    sr.variance,
                    sr.variance_percentage,
                    d.delivery_date,
                    d.status,
                    'good' as quality_status,
                    'Default Supplier' as supplier_name,
                    u1.name as encoded_by_name,
                    u2.name as confirmed_by_name,
                    d.encoded_at,
                    d.confirmed_at,
                    d.closed_at,
                    'merchandise' as delivery_type
                FROM deliveries d
                JOIN stock_requests sr ON d.stock_request_id = sr.id
                JOIN inventory_products ip ON sr.product_name = ip.product_name
                LEFT JOIN users u1 ON d.encoded_by = u1.id
                LEFT JOIN users u2 ON d.confirmed_by = u2.id
                WHERE d.station_id = ? AND d.status IN ('confirmed', 'inventory_updated', 'closed')
                ORDER BY d.delivery_date DESC
            ");
            $stmt->execute([$station_id]);
            $merchandise_completed = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Combine results
            $completed_deliveries = array_merge($fuel_completed, $merchandise_completed);
            
            // Sort by delivery date
            usort($completed_deliveries, function($a, $b) {
                return strtotime($b['delivery_date']) - strtotime($a['delivery_date']);
            });
            
            echo json_encode(['success' => true, 'data' => $completed_deliveries]);
            break;
            
        // Encode delivery receipt
        case 'encode_delivery':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $po_id = $input['po_id'] ?? '';
            $delivery_type = $input['delivery_type'] ?? '';
            $delivery_date = $input['delivery_date'] ?? date('Y-m-d');
            $supplier_invoice = $input['supplier_invoice'] ?? '';
            $delivery_receipt = $input['delivery_receipt_number'] ?? '';
            $delivery_notes = $input['delivery_notes'] ?? '';
            
            if (!$po_id || !$delivery_type) {
                throw new Exception('PO ID and delivery type are required');
            }
            
            $pdo->beginTransaction();
            
            try {
                if ($delivery_type === 'fuel') {
                    // Create fuel delivery record
                    $stmt = $pdo->prepare("
                        INSERT INTO fuel_deliveries 
                        (po_id, station_id, fuel_type_id, supplier_id, delivery_date, 
                         supplier_invoice, delivery_receipt_number, delivery_notes, 
                         ordered_volume, status, encoded_by, encoded_at)
                        SELECT 
                            fpo.id, fpo.station_id, fpo.fuel_type_id, fpo.supplier_id, 
                            ?, ?, ?, ?, fpo.volume, 'encoded', ?, NOW()
                        FROM fuel_purchase_orders fpo
                        WHERE fpo.id = ? AND fpo.station_id = ?
                    ");
                    $stmt->execute([$delivery_date, $supplier_invoice, $delivery_receipt, 
                                   $delivery_notes, $me['id'], $po_id, $station_id]);
                    
                    $delivery_id = $pdo->lastInsertId();
                    
                } else {
                    // Create merchandise delivery record
                    $stmt = $pdo->prepare("
                        INSERT INTO deliveries 
                        (stock_request_id, station_id, delivery_date, 
                         supplier_invoice, delivery_receipt_number, delivery_notes, 
                         status, encoded_by, encoded_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'encoded', ?, NOW())
                    ");
                    $stmt->execute([$po_id, $station_id, $delivery_date, 
                                   $supplier_invoice, $delivery_receipt, $delivery_notes, $me['id']]);
                    
                    $delivery_id = $pdo->lastInsertId();
                    
                    // Create delivery items from stock request
                    $stmt = $pdo->prepare("
                        INSERT INTO delivery_items 
                        (delivery_id, stock_request_id, product_id, product_name, category,
                         quantity_ordered, unit_cost)
                        SELECT 
                            ?, sr.id, ip.id, sr.product_name, ip.category,
                            sr.quantity_requested, ip.unit_cost
                        FROM stock_requests sr
                        JOIN inventory_products ip ON sr.product_name = ip.product_name
                        WHERE sr.id = ?
                    ");
                    $stmt->execute([$delivery_id, $po_id]);
                }
                
                // Log activity
                log_activity($pdo, $me['id'], 'Encode Delivery', 
                    "Encoded delivery receipt for PO #$po_id ($delivery_type)", 'delivery_management');
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Delivery receipt encoded successfully',
                    'delivery_id' => $delivery_id
                ]);
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        // Confirm delivery
        case 'confirm_delivery':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $delivery_id = $input['delivery_id'] ?? '';
            $delivery_type = $input['delivery_type'] ?? '';
            $actual_quantities = $input['actual_quantities'] ?? [];
            $quality_status = $input['quality_status'] ?? 'good';
            $quality_notes = $input['quality_notes'] ?? '';
            $delivery_notes = $input['delivery_notes'] ?? '';
            
            if (!$delivery_id || !$delivery_type || empty($actual_quantities)) {
                throw new Exception('Delivery ID, type, and actual quantities are required');
            }
            
            $pdo->beginTransaction();
            
            try {
                if ($delivery_type === 'fuel') {
                    // Update fuel delivery
                    $actual_volume = $actual_quantities[0]['quantity'] ?? 0;
                    
                    $stmt = $pdo->prepare("
                        UPDATE fuel_deliveries 
                        SET actual_volume = ?, quality_status = ?, quality_notes = ?, 
                            delivery_notes = ?, status = 'confirmed', 
                            confirmed_by = ?, confirmed_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ");
                    $stmt->execute([$actual_volume, $quality_status, $quality_notes, 
                                   $delivery_notes, $me['id'], $delivery_id, $station_id]);
                    
                } else {
                    // Update merchandise delivery and items
                    foreach ($actual_quantities as $item) {
                        $stmt = $pdo->prepare("
                            UPDATE delivery_items 
                            SET quantity_actual = ?, quality_status = ?, quality_notes = ?
                            WHERE delivery_id = ? AND product_name = ?
                        ");
                        $stmt->execute([$item['quantity'], $quality_status, $quality_notes, 
                                       $delivery_id, $item['product_name']]);
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE deliveries 
                        SET delivery_notes = ?, status = 'confirmed', 
                            confirmed_by = ?, confirmed_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ");
                    $stmt->execute([$delivery_notes, $me['id'], $delivery_id, $station_id]);
                }
                
                // Log activity
                log_activity($pdo, $me['id'], 'Confirm Delivery', 
                    "Confirmed delivery #$delivery_id ($delivery_type)", 'delivery_management');
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Delivery confirmed successfully'
                ]);
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        // Update inventory
        case 'update_inventory':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $delivery_id = $input['delivery_id'] ?? '';
            $delivery_type = $input['delivery_type'] ?? '';
            
            if (!$delivery_id || !$delivery_type) {
                throw new Exception('Delivery ID and type are required');
            }
            
            $pdo->beginTransaction();
            
            try {
                if ($delivery_type === 'fuel') {
                    // Update fuel delivery status to trigger inventory update
                    $stmt = $pdo->prepare("
                        UPDATE fuel_deliveries 
                        SET status = 'inventory_updated', 
                            inventory_updated_by = ?, inventory_updated_at = NOW()
                        WHERE id = ? AND station_id = ? AND status = 'confirmed'
                    ");
                    $stmt->execute([$me['id'], $delivery_id, $station_id]);
                    
                } else {
                    // Update merchandise delivery status to trigger inventory update
                    $stmt = $pdo->prepare("
                        UPDATE deliveries 
                        SET status = 'inventory_updated', 
                            inventory_updated_by = ?, inventory_updated_at = NOW()
                        WHERE id = ? AND station_id = ? AND status = 'confirmed'
                    ");
                    $stmt->execute([$me['id'], $delivery_id, $station_id]);
                }
                
                // Log activity
                log_activity($pdo, $me['id'], 'Update Inventory', 
                    "Updated inventory for delivery #$delivery_id ($delivery_type)", 'delivery_management');
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Inventory updated successfully'
                ]);
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        // Log discrepancy
        case 'log_discrepancy':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $delivery_id = $input['delivery_id'] ?? '';
            $delivery_type = $input['delivery_type'] ?? '';
            $discrepancy_type = $input['discrepancy_type'] ?? '';
            $discrepancy_notes = $input['discrepancy_notes'] ?? '';
            $severity = $input['severity'] ?? 'medium';
            
            if (!$delivery_id || !$delivery_type || !$discrepancy_type) {
                throw new Exception('Delivery ID, type, and discrepancy type are required');
            }
            
            $pdo->beginTransaction();
            
            try {
                // Create discrepancy record
                $stmt = $pdo->prepare("
                    INSERT INTO delivery_discrepancies 
                    (delivery_id, fuel_delivery_id, station_id, discrepancy_type, 
                     severity, notes, reported_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                if ($delivery_type === 'fuel') {
                    $stmt->execute([null, $delivery_id, $station_id, $discrepancy_type, 
                                   $severity, $discrepancy_notes, $me['id']]);
                } else {
                    $stmt->execute([$delivery_id, null, $station_id, $discrepancy_type, 
                                   $severity, $discrepancy_notes, $me['id']]);
                }
                
                // Update delivery status
                if ($delivery_type === 'fuel') {
                    $stmt = $pdo->prepare("
                        UPDATE fuel_deliveries 
                        SET status = 'discrepancy_logged', 
                            discrepancy_type = ?, discrepancy_notes = ?,
                            discrepancy_logged_by = ?, discrepancy_logged_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ");
                    $stmt->execute([$discrepancy_type, $discrepancy_notes, 
                                   $me['id'], $delivery_id, $station_id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE deliveries 
                        SET status = 'discrepancy_logged', 
                            discrepancy_type = ?, discrepancy_notes = ?,
                            discrepancy_logged_by = ?, discrepancy_logged_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ");
                    $stmt->execute([$discrepancy_type, $discrepancy_notes, 
                                   $me['id'], $delivery_id, $station_id]);
                }
                
                // Log activity
                log_activity($pdo, $me['id'], 'Log Discrepancy', 
                    "Logged discrepancy for delivery #$delivery_id ($delivery_type): $discrepancy_type", 
                    'delivery_management');
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Discrepancy logged successfully'
                ]);
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        // Close delivery
        case 'close_delivery':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $delivery_id = $input['delivery_id'] ?? '';
            $delivery_type = $input['delivery_type'] ?? '';
            $closing_notes = $input['closing_notes'] ?? '';
            
            if (!$delivery_id || !$delivery_type) {
                throw new Exception('Delivery ID and type are required');
            }
            
            $pdo->beginTransaction();
            
            try {
                if ($delivery_type === 'fuel') {
                    $stmt = $pdo->prepare("
                        UPDATE fuel_deliveries 
                        SET status = 'closed', closing_notes = ?, 
                            closed_by = ?, closed_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ");
                    $stmt->execute([$closing_notes, $me['id'], $delivery_id, $station_id]);
                    
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE deliveries 
                        SET status = 'closed', closing_notes = ?, 
                            closed_by = ?, closed_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ");
                    $stmt->execute([$closing_notes, $me['id'], $delivery_id, $station_id]);
                }
                
                // Log activity
                log_activity($pdo, $me['id'], 'Close Delivery', 
                    "Closed delivery #$delivery_id ($delivery_type)", 'delivery_management');
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Delivery closed successfully'
                ]);
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        // Get delivery statistics
        case 'get_delivery_stats':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed');
            }
            
            $stats = [
                'pending' => 0,
                'encoded' => 0,
                'confirmed' => 0,
                'inventory_updated' => 0,
                'discrepancy_logged' => 0,
                'closed' => 0,
                'total' => 0
            ];
            
            // Get fuel delivery stats
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) as count
                FROM fuel_deliveries 
                WHERE station_id = ?
                GROUP BY status
            ");
            $stmt->execute([$station_id]);
            $fuel_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Get merchandise delivery stats
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) as count
                FROM deliveries 
                WHERE station_id = ?
                GROUP BY status
            ");
            $stmt->execute([$station_id]);
            $merch_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Combine stats
            foreach ($fuel_stats as $status => $count) {
                $stats[$status] = ($stats[$status] ?? 0) + $count;
                $stats['total'] += $count;
            }
            
            foreach ($merch_stats as $status => $count) {
                $stats[$status] = ($stats[$status] ?? 0) + $count;
                $stats['total'] += $count;
            }
            
            // Get pending count from approved POs
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM fuel_purchase_orders 
                WHERE station_id = ? AND status = 'approved'
            ");
            $stmt->execute([$station_id]);
            $fuel_pending = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM stock_requests 
                WHERE station_id = ? AND status = 'Approved'
            ");
            $stmt->execute([$station_id]);
            $merch_pending = $stmt->fetchColumn();
            
            $stats['pending'] = $fuel_pending + $merch_pending;
            
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    error_log("Deliveries API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>
