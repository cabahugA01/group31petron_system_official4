<?php
$page_id = 'manager_deliveries_management';
require_once __DIR__ . '/../backend/rbac.php';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = $me['station_id'] ?? 1;

// Check if user has permission
if (!has_permission('MANAGE_DELIVERIES', $me['role'])) {
    header('Location: dashboard.php');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed');
    }
    
    switch ($_POST['action']) {
        // Create Purchase Order
        case 'create_po':
            $po_type = $_POST['po_type'] ?? 'merchandise';
            $supplier = $_POST['supplier'] ?? '';
            $product = $_POST['product'] ?? '';
            $quantity = (float)($_POST['quantity'] ?? 0);
            $expected_date = $_POST['expected_date'] ?? date('Y-m-d');
            $notes = $_POST['notes'] ?? '';
            
            try {
                $pdo->beginTransaction();
                
                if ($po_type === 'fuel') {
                    // Try to find fuel_type_id
                    $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE name LIKE ? LIMIT 1");
                    $stmt->execute(['%' . $product . '%']);
                    $fuel_type_id = $stmt->fetchColumn() ?: 1; // Default to 1 if not found
                    
                    // Try to find supplier_id
                    $stmt = $pdo->prepare("SELECT id FROM fuel_suppliers WHERE supplier_name LIKE ? LIMIT 1");
                    $stmt->execute(['%' . $supplier . '%']);
                    $supplier_id = $stmt->fetchColumn() ?: 1; // Default to 1 if not found
                    
                    $po_number = 'FPO-' . date('Ymd') . '-' . rand(1000, 9999);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO fuel_purchase_orders 
                        (po_number, station_id, fuel_type_id, volume, unit_price, total_amount, supplier_id, expected_delivery_date, status, created_by, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'forwarded', ?, ?)
                    ");
                    // Using default price 0 as it's set by admin
                    $stmt->execute([$po_number, $station_id, $fuel_type_id, $quantity, 0, 0, $supplier_id, $expected_date, $me['id'], $notes]);
                    
                } else {
                    $po_number = 'MPO-' . date('Ymd') . '-' . rand(1000, 9999);
                    
                    // Try to find supplier_id (if we have a merchandise supplier table, otherwise NULL)
                    $supplier_id = NULL; 
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO purchase_orders 
                        (po_number, station_id, product_name, quantity, unit_price, total_amount, supplier_id, expected_delivery_date, status, created_by, type, remarks)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending Admin Validation', ?, 'merch', ?)
                    ");
                    $stmt->execute([$po_number, $station_id, $product, $quantity, 0, 0, $supplier_id, $expected_date, $me['id'], $notes]);
                    $po_id = $pdo->lastInsertId();

                    // Find product_id from inventory_products by name
                    $stmt_pid = $pdo->prepare("SELECT id, unit_price FROM inventory_products WHERE product_name = ? LIMIT 1");
                    $stmt_pid->execute([$product]);
                    $prod_info = $stmt_pid->fetch(PDO::FETCH_ASSOC);
                    $product_id = $prod_info['id'] ?? 0;
                    $unit_price = (float)($prod_info['unit_price'] ?? 0);
                    $total_amount = $quantity * $unit_price;

                    // Update total_amount on the purchase_orders record
                    $pdo->prepare("UPDATE purchase_orders SET total_amount = ? WHERE id = ?")->execute([$total_amount, $po_id]);

                    // Insert into purchase_order_items for data integrity
                    $pdo->prepare("
                        INSERT INTO purchase_order_items
                            (po_id, product_id, item_name, quantity, quantity_ordered, unit_price, total_price)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $po_id, $product_id, $product, $quantity, $quantity, $unit_price, $total_amount
                    ]);
                }
                
                log_activity($pdo, $me['id'], 'Create PO', 
                    "Created Purchase Order $po_number for $product", 'delivery_management');
                
                $pdo->commit();
                $_SESSION['success'] = "Purchase Order created and forwarded to Admin successfully!";
                
            } catch (Exception $e) {
                $pdo->rollback();
                $_SESSION['error'] = 'Error creating PO: ' . $e->getMessage();
            }
            header('Location: manager_deliveries_management.php');
            exit;
            
        // Encode Delivery Receipt
            $po_id = $_POST['po_id'] ?? '';
            $delivery_type = $_POST['delivery_type'] ?? '';
            $delivery_date = $_POST['delivery_date'] ?? date('Y-m-d');
            $supplier_invoice = $_POST['supplier_invoice'] ?? '';
            $delivery_receipt_number = $_POST['delivery_receipt_number'] ?? '';
            $delivery_notes = $_POST['delivery_notes'] ?? '';
            
            try {
                $pdo->beginTransaction();
                
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
                    $stmt->execute([$delivery_date, $supplier_invoice, $delivery_receipt_number, 
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
                                   $supplier_invoice, $delivery_receipt_number, $delivery_notes, $me['id']]);
                    
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
                
                log_activity($pdo, $me['id'], 'Encode Delivery', 
                    "Encoded delivery receipt for PO #$po_id ($delivery_type)", 'delivery_management');
                
                $pdo->commit();
                $_SESSION['success'] = "Delivery receipt encoded successfully!";
                
            } catch (Exception $e) {
                $pdo->rollback();
                $_SESSION['error'] = 'Error encoding delivery: ' . $e->getMessage();
            }
            header('Location: manager_deliveries_management.php');
            exit;
            
        // Confirm Delivery
        case 'confirm_delivery':
            $delivery_id = $_POST['delivery_id'] ?? '';
            $delivery_type = $_POST['delivery_type'] ?? '';
            $actual_quantities = $_POST['actual_quantities'] ?? [];
            $quality_status = $_POST['quality_status'] ?? 'good';
            $quality_notes = $_POST['quality_notes'] ?? '';
            $delivery_notes = $_POST['delivery_notes'] ?? '';
            
            try {
                $pdo->beginTransaction();
                
                if ($delivery_type === 'fuel') {
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
                
                log_activity($pdo, $me['id'], 'Confirm Delivery', 
                    "Confirmed delivery #$delivery_id ($delivery_type)", 'delivery_management');
                
                $pdo->commit();
                $_SESSION['success'] = "Delivery confirmed successfully!";
                
            } catch (Exception $e) {
                $pdo->rollback();
                $_SESSION['error'] = 'Error confirming delivery: ' . $e->getMessage();
            }
            header('Location: manager_deliveries_management.php');
            exit;
            
        // Update Inventory
        case 'update_inventory':
            $delivery_id = $_POST['delivery_id'] ?? '';
            $delivery_type = $_POST['delivery_type'] ?? '';
            
            try {
                $pdo->beginTransaction();
                
                if ($delivery_type === 'fuel') {
                    $stmt = $pdo->prepare("
                        UPDATE fuel_deliveries 
                        SET status = 'inventory_updated', 
                            inventory_updated_by = ?, inventory_updated_at = NOW()
                        WHERE id = ? AND station_id = ? AND status = 'confirmed'
                    ");
                    $stmt->execute([$me['id'], $delivery_id, $station_id]);
                    
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE deliveries 
                        SET status = 'inventory_updated', 
                            inventory_updated_by = ?, inventory_updated_at = NOW()
                        WHERE id = ? AND station_id = ? AND status = 'confirmed'
                    ");
                    $stmt->execute([$me['id'], $delivery_id, $station_id]);
                }
                
                log_activity($pdo, $me['id'], 'Update Inventory', 
                    "Updated inventory for delivery #$delivery_id ($delivery_type)", 'delivery_management');
                
                $pdo->commit();
                $_SESSION['success'] = "Inventory updated successfully!";
                
            } catch (Exception $e) {
                $pdo->rollback();
                $_SESSION['error'] = 'Error updating inventory: ' . $e->getMessage();
            }
            header('Location: manager_deliveries_management.php');
            exit;
            
        // Log Discrepancy
        case 'log_discrepancy':
            $delivery_id = $_POST['delivery_id'] ?? '';
            $delivery_type = $_POST['delivery_type'] ?? '';
            $discrepancy_type = $_POST['discrepancy_type'] ?? '';
            $discrepancy_notes = $_POST['discrepancy_notes'] ?? '';
            $severity = $_POST['severity'] ?? 'medium';
            
            try {
                $pdo->beginTransaction();
                
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
                
                log_activity($pdo, $me['id'], 'Log Discrepancy', 
                    "Logged discrepancy for delivery #$delivery_id ($delivery_type): $discrepancy_type", 
                    'delivery_management');
                
                $pdo->commit();
                $_SESSION['success'] = "Discrepancy logged successfully!";
                
            } catch (Exception $e) {
                $pdo->rollback();
                $_SESSION['error'] = 'Error logging discrepancy: ' . $e->getMessage();
            }
            header('Location: manager_deliveries_management.php');
            exit;
            
        // Close Delivery
        case 'close_delivery':
            $delivery_id = $_POST['delivery_id'] ?? '';
            $delivery_type = $_POST['delivery_type'] ?? '';
            $closing_notes = $_POST['closing_notes'] ?? '';
            
            try {
                $pdo->beginTransaction();
                
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
                
                log_activity($pdo, $me['id'], 'Close Delivery', 
                    "Closed delivery #$delivery_id ($delivery_type)", 'delivery_management');
                
                $pdo->commit();
                $_SESSION['success'] = "Delivery closed successfully!";
                
            } catch (Exception $e) {
                $pdo->rollback();
                $_SESSION['error'] = 'Error closing delivery: ' . $e->getMessage();
            }
            header('Location: manager_deliveries_management.php');
            exit;
    }
}

// Fetch data for deliveries management
$pending_deliveries = [];
$completed_deliveries = [];
$delivery_stats = [
    'pending' => 0,
    'encoded' => 0,
    'confirmed' => 0,
    'inventory_updated' => 0,
    'discrepancy_logged' => 0,
    'closed' => 0,
    'total' => 0
];

try {
    // Get pending deliveries from low stock alerts and approved stock requests
    // Fuel deliveries from low stock alerts
    $stmt = $pdo->prepare("
        SELECT 
            fi.id as alert_id,
            fi.fuel_type_id,
            ft.name as product_name,
            'Fuel' as category,
            fi.current_stock as current_quantity,
            fi.min_stock_level,
            fs.supplier_name,
            fi.last_updated as alert_date,
            'fuel' as delivery_type,
            fi.fuel_type_id as product_id,
            fs.id as supplier_id
        FROM fuel_inventory fi
        JOIN fuel_types ft ON fi.fuel_type_id = ft.id
        LEFT JOIN fuel_suppliers fs ON fs.id = (SELECT supplier_id FROM fuel_purchase_orders WHERE fuel_type_id = ft.id ORDER BY id DESC LIMIT 1)
        WHERE fi.station_id = ? AND fi.current_stock <= fi.min_stock_level
        ORDER BY fi.last_updated ASC
    ");
    $stmt->execute([$station_id]);
    $fuel_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merchandise deliveries from approved stock requests
    $stmt = $pdo->prepare("
        SELECT 
            sr.id,
            sr.product_name,
            ip.category,
            sr.quantity_requested as quantity_ordered,
            ip.unit_cost as unit_price,
            sr.quantity_requested * ip.unit_cost as total_amount,
            sr.approved_at,
            'Default Supplier' as supplier_name,
            sr.approved_at,
            'merchandise' as delivery_type,
            ip.id as product_id,
            NULL as supplier_id
        FROM stock_requests sr
        JOIN inventory_products ip ON sr.product_name = ip.product_name
        WHERE sr.station_id = ? AND sr.status = 'Approved' 
        ORDER BY sr.approved_at ASC
    ");
    $stmt->execute([$station_id]);
    $merchandise_pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine pending deliveries from alerts and stock requests
    $pending_deliveries = array_merge($fuel_alerts, $merchandise_pending);
    
    // Add urgency status based on alert dates and approval dates
    foreach ($pending_deliveries as &$delivery) {
        if ($delivery['delivery_type'] === 'fuel') {
            // For fuel alerts, check how old the alert is
            $alert_date = $delivery['alert_date'];
            if ($alert_date) {
                $days_diff = (strtotime(date('Y-m-d')) - strtotime($alert_date)) / (60 * 60 * 24);
                if ($days_diff > 3) {
                    $delivery['urgency_status'] = 'overdue';
                } elseif ($days_diff > 1) {
                    $delivery['urgency_status'] = 'due_soon';
                } else {
                    $delivery['urgency_status'] = 'due_today';
                }
            } else {
                $delivery['urgency_status'] = 'unknown';
            }
        } else {
            // For merchandise stock requests, check approval date
            $approval_date = $delivery['approved_at'];
            if ($approval_date) {
                $days_diff = (strtotime(date('Y-m-d')) - strtotime($approval_date)) / (60 * 60 * 24);
                if ($days_diff > 3) {
                    $delivery['urgency_status'] = 'overdue';
                } elseif ($days_diff > 1) {
                    $delivery['urgency_status'] = 'due_soon';
                } else {
                    $delivery['urgency_status'] = 'due_today';
                }
            } else {
                $delivery['urgency_status'] = 'unknown';
            }
        }
    }
    
    // Get completed deliveries - Fuel (from fuel_deliveries table)
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
            fd.delivery_receipt_number,
            fd.discrepancy_type,
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
    
    // Get completed merchandise deliveries (from deliveries table)
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
            d.delivery_receipt_number,
            d.discrepancy_type,
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
    
    // Combine completed deliveries
    $completed_deliveries = array_merge($fuel_completed, $merchandise_completed);
    
    // Sort by delivery date
    usort($completed_deliveries, function($a, $b) {
        return strtotime($b['delivery_date']) - strtotime($a['delivery_date']);
    });
    
    // Calculate stats
    foreach ($completed_deliveries as $delivery) {
        $delivery_stats['total']++;
        if (isset($delivery_stats[$delivery['status']])) {
            $delivery_stats[$delivery['status']]++;
        }
    }
    $delivery_stats['pending'] = count($pending_deliveries);
    
} catch (Exception $e) {
    error_log("Error fetching deliveries data: " . $e->getMessage());
    $pending_deliveries = [];
    $completed_deliveries = [];
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function to get status badge HTML
function getStatusBadgeHtml($status) {
    $badgeClasses = [
        'pending' => 'status-badge status-pending',
        'encoded' => 'status-badge status-encoded',
        'confirmed' => 'status-badge status-confirmed',
        'inventory_updated' => 'status-badge status-inventory-updated',
        'discrepancy_logged' => 'status-badge status-discrepancy',
        'discrepancy' => 'status-badge status-discrepancy',
        'closed' => 'status-badge status-closed'
    ];
    
    $statusTexts = [
        'pending' => 'Pending',
        'encoded' => 'Encoded',
        'confirmed' => 'Confirmed',
        'inventory_updated' => 'Inventory Updated',
        'discrepancy_logged' => 'Discrepancy',
        'discrepancy' => 'Discrepancy',
        'closed' => 'Closed'
    ];
    
    $class = $badgeClasses[$status] ?? 'status-badge status-pending';
    $text = $statusTexts[$status] ?? 'Unknown';
    
    return '<span class="' . $class . '">' . htmlspecialchars($text) . '</span>';
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.manager-deliveries-management {
    max-width: 1400px;
    margin: 0 auto;
    padding: 10px;
}



/* Delivery Receipts Section */
.delivery-receipts-section {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.delivery-id {
    color: #2c3e50;
    font-family: 'Courier New', monospace;
    font-size: 0.9rem;
}

.supplier-name {
    color: #3498db;
    font-weight: 600;
}

.product-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.product-name {
    font-weight: 600;
    color: #2c3e50;
}

.category-badge {
    display: inline-block;
    padding: 2px 8px;
    background: #e74c3c;
    color: white;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.quantity {
    font-weight: 600;
    color: #16a085;
}

.date-received {
    color: #7f8c8d;
    font-size: 0.9rem;
}

.action-buttons {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.no-deliveries {
    text-align: center;
    padding: 40px;
    color: #7f8c8d;
    font-size: 1.1rem;
}

.no-deliveries i {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.5;
    display: block;
}

/* Delivery Flow Styles */
.delivery-flow {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 32px;
    border-left: 6px solid <?php echo $colors['primary']; ?>;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.flow-header h2 {
    color: <?php echo $colors['primary']; ?>;
    margin: 0 0 24px 0;
    font-size: 1.8rem;
    font-weight: 700;
    text-align: center;
}

.flow-sections {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
}

.flow-section {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-top: 4px solid <?php echo $colors['primary']; ?>;
}

.section-header h3 {
    color: <?php echo $colors['primary']; ?>;
    margin: 0 0 20px 0;
    font-size: 1.3rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
}

.section-content {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.flow-item {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
    border-left: 3px solid <?php echo $colors['primary']; ?>;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.flow-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.item-icon {
    width: 40px;
    height: 40px;
    background: <?php echo $colors['primary']; ?>;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1.1rem;
}

.item-text {
    line-height: 1.6;
    font-size: 0.95rem;
}

.item-text strong {
    color: #333;
    font-weight: 600;
}

/* Tables */
.delivery-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.delivery-table th {
    background: linear-gradient(135deg, <?php echo $colors['primary']; ?> 0%, #4a5fc1 100%);
    color: white;
    padding: 16px;
    text-align: left;
    font-weight: 600;
    font-size: 0.9rem;
}

.delivery-table td {
    padding: 16px;
    border-bottom: 1px solid #f0f0f0;
    font-size: 0.9rem;
}

.delivery-table tr:hover {
    background: #f8f9fa;
}

/* Status Badges */
.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-pending { background: #ffc107; color: #333; }
.status-encoded { background: #17a2b8; color: white; }
.status-confirmed { background: #28a745; color: white; }
.status-inventory-updated { background: #6f42c1; color: white; }
.status-discrepancy { background: #dc3545; color: white; }
.status-closed { background: #6c757d; color: white; }

/* Buttons */
.action-btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.btn-primary { background: <?php echo $colors['primary']; ?>; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-warning { background: #ffc107; color: #333; }
.btn-danger { background: #dc3545; color: white; }
.btn-secondary { background: #6c757d; color: white; }

.action-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

/* Forms */
.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #333;
}

.form-control, .form-select, .form-textarea {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 0.9rem;
    transition: border-color 0.2s ease;
}

.form-control:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: <?php echo $colors['primary']; ?>;
}

.form-textarea {
    resize: vertical;
    min-height: 80px;
}

/* Enhanced Modal Styles */
.input-group {
    display: flex;
    gap: 8px;
}

.input-group .form-control {
    flex: 1;
}

.po-info-card, .variance-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 16px;
    margin: 16px 0;
    border-left: 4px solid <?php echo $colors['primary']; ?>;
}

.po-info-card h4, .variance-card h4 {
    margin: 0 0 12px 0;
    color: <?php echo $colors['primary']; ?>;
    font-size: 1.1rem;
    font-weight: 600;
}

.po-details-grid, .variance-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}

.po-detail-item, .variance-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #e9ecef;
}

.po-detail-item:last-child, .variance-item:last-child {
    border-bottom: none;
}

.po-detail-item label, .variance-item label {
    font-weight: 600;
    color: #666;
    margin: 0;
}

.po-detail-item span, .variance-item span {
    font-weight: 500;
    color: #333;
}

.variance-item span.text-danger {
    color: #dc3545 !important;
    font-weight: 600;
}

.variance-item span.text-success {
    color: #28a745 !important;
    font-weight: 600;
}

.transaction-id-display {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: #e9ecef;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-weight: 600;
    color: #495057;
}

.transaction-id-display span {
    flex: 1;
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 0;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    margin: 0;
    color: #333;
    font-size: 1.3rem;
    font-weight: 600;
}

.close {
    color: #aaa;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    line-height: 1;
}

.close:hover {
    color: #333;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e9ecef;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* Section Header */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 0 4px;
}

.section-header h3 {
    color: #333;
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
}

.table-controls {
    display: flex;
    gap: 12px;
    align-items: center;
}

/* Loading Spinner */
.loading-spinner {
    text-align: center;
    padding: 40px;
    color: #666;
    font-size: 1.1rem;
}

.loading-spinner i {
    margin-right: 10px;
    font-size: 1.5rem;
}

/* Variance Highlight */
.variance-negative {
    background-color: #ffebee !important;
    color: #c62828;
    font-weight: 600;
}

.variance-positive {
    background-color: #e8f5e8 !important;
    color: #2e7d32;
    font-weight: 600;
}

.variance-zero {
    background-color: #f5f5f5 !important;
    color: #666;
}

/* Responsive */
@media (max-width: 768px) {
    .manager-deliveries-management {
        padding: 5px;
    }
    
    .workflow-steps {
        flex-direction: column;
        align-items: center;
    }
    
    .step {
        }
    
    .step-arrow {
        transform: rotate(90deg);
        margin: 8px 0;
    }
    
    .delivery-table {
        font-size: 0.8rem;
    }
    
    .delivery-table th,
    .delivery-table td {
        padding: 8px;
    }
    
    .flow-sections {
        grid-template-columns: 1fr;
        gap: 20px;
    }
}
</style>

<div class="manager-deliveries-management">
    <div class="page-head">
        <div>
            <h1 class="h1">Manager Deliveries Management</h1>
        </div>
    </div>

<?php if(isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
</div>
<?php endif; ?>

<?php if(isset($_SESSION['error'])): ?>
<div class="alert alert-error">
    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
</div>
<?php endif; ?>


    
    <div style="margin-bottom: 24px;">
        <button class="action-btn btn-primary" onclick="openCreatePoModal()">
            <i class="fas fa-plus"></i> Create Purchase Order
        </button>
    </div>

    <!-- Inventory Alerts Section -->
    <div class="delivery-receipts-section" style="margin-bottom: 32px; border-top: 4px solid #dc3545;">
        <div class="section-header">
            <h2 style="color: #dc3545;"><i class="fas fa-exclamation-circle"></i> Inventory Alerts & Pending Requests</h2>
            <div class="table-controls">
                <span style="font-size: 0.9rem; color: #666;">Items requiring Purchase Orders</span>
            </div>
        </div>
        
        <?php if (!empty($pending_deliveries)): ?>
            <table class="delivery-table">
                <thead>
                    <tr>
                        <th>Product / Category</th>
                        <th>Current Stock / Requested</th>
                        <th>Alert Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_deliveries as $alert): ?>
                        <tr>
                            <td>
                                <div class="product-info">
                                    <div class="product-name"><?php echo htmlspecialchars($alert['product_name']); ?></div>
                                    <span class="category-badge" style="background: <?php echo $alert['delivery_type'] === 'fuel' ? '#f39c12' : '#3498db'; ?>">
                                        <?php echo htmlspecialchars($alert['category']); ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if ($alert['delivery_type'] === 'fuel'): ?>
                                    <span class="quantity text-danger" style="color: #dc3545;">
                                        <?php echo htmlspecialchars($alert['current_quantity']); ?> L 
                                        (Min: <?php echo htmlspecialchars($alert['min_stock_level']); ?> L)
                                    </span>
                                <?php else: ?>
                                    <span class="quantity">
                                        <?php echo htmlspecialchars($alert['quantity_ordered']); ?> units requested
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="date-received">
                                    <?php 
                                        $dateField = $alert['delivery_type'] === 'fuel' ? $alert['alert_date'] : $alert['approved_at'];
                                        echo $dateField ? date('M d, Y', strtotime($dateField)) : 'N/A'; 
                                    ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                    if (($alert['urgency_status'] ?? '') === 'overdue') echo '<span class="status-badge" style="background: #dc3545; color: white;">Critical</span>';
                                    elseif (($alert['urgency_status'] ?? '') === 'due_soon') echo '<span class="status-badge" style="background: #fd7e14; color: white;">Urgent</span>';
                                    else echo '<span class="status-badge status-pending">Pending PO</span>';
                                ?>
                            </td>
                            <td>
                                <button class="action-btn btn-primary" onclick="openCreatePoModal(null, '<?php echo addslashes(htmlspecialchars($alert['product_name'])); ?>', '<?php echo $alert['delivery_type']; ?>')">
                                    <i class="fas fa-file-invoice"></i> Create PO
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-deliveries">
                <i class="fas fa-check-circle" style="color: #28a745;"></i>
                <p>No pending inventory alerts. All stocks are at optimal levels.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Delivery Receipts Table -->
    <div class="delivery-receipts-section">
        <div class="section-header">
            <h2>Delivery Receipts & Processing</h2>
            <div class="table-controls">
                <select id="status-filter" class="form-select" onchange="filterDeliveryReceipts()">
                    <option value="all">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="encoded">Encoded</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="inventory_updated">Inventory Updated</option>
                    <option value="discrepancy">Discrepancy</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
        </div>
        
        <?php if (!empty($completed_deliveries)): ?>
            <table class="delivery-table">
                <thead>
                    <tr>
                        <th>Delivery ID</th>
                        <th>Supplier Name</th>
                        <th>Product Name / Category</th>
                        <th>Quantity Delivered</th>
                        <th>Date Received</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completed_deliveries as $delivery): ?>
                        <tr>
                            <td>
                                <strong class="delivery-id">
                                    #D-<?php echo date('Y-m-d', strtotime($delivery['delivery_date'] ?? 'now')); ?>-<?php echo str_pad($delivery['id'], 4, '0', STR_PAD_LEFT); ?>
                                </strong>
                            </td>
                            <td>
                                <span class="supplier-name"><?php echo htmlspecialchars($delivery['supplier_name'] ?? 'Default Supplier'); ?></span>
                            </td>
                            <td>
                                <div class="product-info">
                                    <div class="product-name"><?php echo htmlspecialchars($delivery['product_name']); ?></div>
                                    <span class="category-badge"><?php echo htmlspecialchars($delivery['category']); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="quantity">
                                    <?php echo htmlspecialchars($delivery['actual_volume'] ?? $delivery['quantity_ordered']); ?> 
                                    <?php echo $delivery['delivery_type'] === 'fuel' ? 'L' : 'units'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="date-received"><?php echo date('M d, Y', strtotime($delivery['delivery_date'] ?? 'now')); ?></span>
                            </td>
                            <td>
                                <?php echo getStatusBadgeHtml($delivery['status']); ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn btn-primary" onclick="viewDelivery(<?php echo $delivery['id']; ?>, '<?php echo $delivery['delivery_type']; ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    
                                    <?php if ($delivery['status'] === 'encoded'): ?>
                                        <button class="action-btn btn-success" onclick="confirmDelivery(<?php echo $delivery['id']; ?>, '<?php echo $delivery['delivery_type']; ?>')">
                                            <i class="fas fa-check"></i> Confirm
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($delivery['status'] === 'encoded' || $delivery['status'] === 'confirmed'): ?>
                                        <button class="action-btn btn-danger" onclick="rejectDelivery(<?php echo $delivery['id']; ?>, '<?php echo $delivery['delivery_type']; ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($delivery['status'] === 'confirmed' || $delivery['status'] === 'inventory_updated' || $delivery['status'] === 'discrepancy_logged'): ?>
                                        <button class="action-btn btn-secondary" onclick="closeDelivery(<?php echo $delivery['id']; ?>, '<?php echo $delivery['delivery_type']; ?>')">
                                            <i class="fas fa-archive"></i> Close
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-deliveries">
                <i class="fas fa-truck"></i>
                <p>No delivery receipts found. Staff encoded deliveries will appear here.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Log Discrepancy Modal -->
<div id="discrepancyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Log Delivery Discrepancy</h2>
            <span class="close" onclick="closeModal('discrepancyModal')">&times;</span>
        </div>
        <form method="post" action="manager_deliveries_management.php">
            <input type="hidden" name="action" value="log_discrepancy">
            <input type="hidden" name="delivery_id" id="discrepancy_delivery_id">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Discrepancy Type</label>
                    <select name="discrepancy_type" class="form-select" required>
                        <option value="">Select discrepancy type</option>
                        <option value="shortage">Shortage</option>
                        <option value="over_delivery">Over Delivery</option>
                        <option value="quality_issue">Quality Issue</option>
                        <option value="damaged">Damaged Goods</option>
                        <option value="wrong_product">Wrong Product</option>
                        <option value="documentation_error">Documentation Error</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Discrepancy Notes</label>
                    <textarea name="discrepancy_notes" class="form-textarea" placeholder="Describe the discrepancy in detail..." required></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="action-btn btn-secondary" onclick="closeModal('discrepancyModal')">Cancel</button>
                <button type="submit" class="action-btn btn-danger">
                    <i class="fas fa-exclamation-triangle"></i> Log Discrepancy
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Global variables
let currentFilter = 'all';

// Initialize the page
document.addEventListener('DOMContentLoaded', function() {
    // Page initialization complete
});

// Filter Delivery Receipts
function filterDeliveryReceipts() {
    const filter = document.getElementById('status-filter').value;
    currentFilter = filter;
    
    const rows = document.querySelectorAll('.delivery-table tbody tr');
    
    rows.forEach(row => {
        const statusCell = row.querySelector('td:nth-child(6)'); // Status column
        const statusBadge = statusCell.querySelector('.status-badge');
        
        if (statusBadge) {
            const statusText = statusBadge.textContent.toLowerCase();
            
            if (filter === 'all' || statusText.includes(filter.toLowerCase())) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        }
    });
}

// View Delivery Details
function viewDelivery(deliveryId, deliveryType) {
    // Create a simple view modal or redirect to details page
    alert(`View delivery details for ID: ${deliveryId}, Type: ${deliveryType}`);
    // In a real implementation, this would open a modal with delivery details
}

// Confirm Delivery
function confirmDelivery(deliveryId, deliveryType) {
    if (confirm('Are you sure you want to confirm this delivery?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'manager_deliveries_management.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'confirm_delivery';
        
        const deliveryIdInput = document.createElement('input');
        deliveryIdInput.type = 'hidden';
        deliveryIdInput.name = 'delivery_id';
        deliveryIdInput.value = deliveryId;
        
        const deliveryTypeInput = document.createElement('input');
        deliveryTypeInput.type = 'hidden';
        deliveryTypeInput.name = 'delivery_type';
        deliveryTypeInput.value = deliveryType;
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo $_SESSION['csrf_token']; ?>';
        
        form.appendChild(actionInput);
        form.appendChild(deliveryIdInput);
        form.appendChild(deliveryTypeInput);
        form.appendChild(csrfInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Reject Delivery
function rejectDelivery(deliveryId, deliveryType) {
    const reason = prompt('Please enter the reason for rejection:');
    
    if (reason) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'manager_deliveries_management.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'log_discrepancy';
        
        const deliveryIdInput = document.createElement('input');
        deliveryIdInput.type = 'hidden';
        deliveryIdInput.name = 'delivery_id';
        deliveryIdInput.value = deliveryId;
        
        const deliveryTypeInput = document.createElement('input');
        deliveryTypeInput.type = 'hidden';
        deliveryTypeInput.name = 'delivery_type';
        deliveryTypeInput.value = deliveryType;
        
        const discrepancyTypeInput = document.createElement('input');
        discrepancyTypeInput.type = 'hidden';
        discrepancyTypeInput.name = 'discrepancy_type';
        discrepancyTypeInput.value = 'rejected';
        
        const discrepancyNotesInput = document.createElement('input');
        discrepancyNotesInput.type = 'hidden';
        discrepancyNotesInput.name = 'discrepancy_notes';
        discrepancyNotesInput.value = reason;
        
        const severityInput = document.createElement('input');
        severityInput.type = 'hidden';
        severityInput.name = 'severity';
        severityInput.value = 'high';
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo $_SESSION['csrf_token']; ?>';
        
        form.appendChild(actionInput);
        form.appendChild(deliveryIdInput);
        form.appendChild(deliveryTypeInput);
        form.appendChild(discrepancyTypeInput);
        form.appendChild(discrepancyNotesInput);
        form.appendChild(severityInput);
        form.appendChild(csrfInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Close Delivery
function closeDelivery(deliveryId, deliveryType) {
    const notes = prompt('Please enter closing notes (optional):');
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'manager_deliveries_management.php';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'close_delivery';
    
    const deliveryIdInput = document.createElement('input');
    deliveryIdInput.type = 'hidden';
    deliveryIdInput.name = 'delivery_id';
    deliveryIdInput.value = deliveryId;
    
    const deliveryTypeInput = document.createElement('input');
    deliveryTypeInput.type = 'hidden';
    deliveryTypeInput.name = 'delivery_type';
    deliveryTypeInput.value = deliveryType;
    
    const closingNotesInput = document.createElement('input');
    closingNotesInput.type = 'hidden';
    closingNotesInput.name = 'closing_notes';
    closingNotesInput.value = notes || 'Delivery closed by manager';
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?php echo $_SESSION['csrf_token']; ?>';
    
    form.appendChild(actionInput);
    form.appendChild(deliveryIdInput);
    form.appendChild(deliveryTypeInput);
    form.appendChild(closingNotesInput);
    form.appendChild(csrfInput);
    
    document.body.appendChild(form);
    form.submit();
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = ['discrepancyModal', 'createPoModal'];
    
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    });
}

// Close modal function
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function openCreatePoModal(alertId = null, product = '', type = 'merchandise') {
    document.getElementById('createPoModal').style.display = 'block';
    if (product) {
        document.getElementById('po_product').value = product;
    }
    document.getElementById('po_type').value = type;
}
</script>

<!-- Create PO Modal -->
<div id="createPoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Create Purchase Order</h2>
            <span class="close" onclick="closeModal('createPoModal')">&times;</span>
        </div>
        <form method="post" action="manager_deliveries_management.php">
            <input type="hidden" name="action" value="create_po">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select name="po_type" id="po_type" class="form-select" required>
                        <option value="merchandise">Merchandise</option>
                        <option value="fuel">Fuel</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Supplier</label>
                    <input type="text" name="supplier" class="form-control" placeholder="e.g. Petron Corporation" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Product</label>
                    <input type="text" name="product" id="po_product" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="quantity" class="form-control" min="1" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Expected Delivery Date</label>
                    <input type="date" name="expected_date" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes (Optional)</label>
                    <textarea name="notes" class="form-textarea" placeholder="Add any special instructions..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="action-btn btn-secondary" onclick="closeModal('createPoModal')">Cancel</button>
                <button type="submit" class="action-btn btn-primary">
                    <i class="fas fa-save"></i> Create & Forward to Admin
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
