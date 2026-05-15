<?php
/**
 * Inventory Management Backend
 * Implements staff encoding → admin confirmation → manager verification
 * 
 * FLOW:
 * 1. Staff encodes received items
 * 2. Admin confirms deliveries
 * 3. Manager verifies reconciliation
 * 4. Fuel reconciliation formula: (Present − Previous − Calibration) × Price/L
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

class InventoryOperations {
    
    private $pdo;
    private $station_id;
    private $user;
    
    public function __construct($pdo, $user, $station_id) {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->station_id = $station_id;
    }
    
    /**
     * Staff Encodes Received Items
     * Records incoming fuel and merchandise
     */
    public function recordReceival($items, $supplier_id, $po_reference = null) {
        try {
            $this->pdo->beginTransaction();
            
            // RBAC: Staff only for encoding (Admin is read-only for hierarchy compliance)
            $role = role_key($this->user['role'] ?? '');
            if ($role !== 'staff') {
                throw new Exception('Only operations staff can encode received items');
            }
            
            if (empty($items)) {
                throw new Exception('No items to record');
            }
            
            $receipt_id = null;
            $total_amount = 0;
            
            // INSERT: Receipt record
            $stmt = $this->pdo->prepare("
                INSERT INTO supplier_receipts
                (station_id, supplier_id, po_reference, recorded_by, status, created_at)
                VALUES (?, ?, ?, ?, 'Pending Confirmation', NOW())
            ");
            $stmt->execute([$this->station_id, $supplier_id, $po_reference, $this->user['id']]);
            $receipt_id = $this->pdo->lastInsertId();
            
            // ENCODE: Each item
            foreach ($items as $item) {
                if (empty($item['product_id']) || empty($item['quantity'])) {
                    throw new Exception('Missing product_id or quantity');
                }
                
                $item_cost = $item['quantity'] * $item['unit_price'];
                $total_amount += $item_cost;
                
                // Record receipt line item
                $stmt = $this->pdo->prepare("
                    INSERT INTO receipt_items
                    (receipt_id, product_id, quantity, unit_price, total_price, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $receipt_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item_cost
                ]);
            }
            
            log_activity(
                $this->pdo,
                $this->user['id'],
                'Inventory Encoded',
                sprintf('Receipt %d: %d items, ₱%.2f total', $receipt_id, count($items), $total_amount)
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Items recorded. Awaiting admin confirmation.',
                'receipt_id' => $receipt_id,
                'total_amount' => $total_amount
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Admin Confirms Delivery
     * Validates and confirms received items
     */
    public function adminConfirmReceipt($receipt_id, $remarks = null) {
        try {
            $this->pdo->beginTransaction();
            
            // RBAC: Manager only (Admin is read-only for hierarchy compliance)
            $role = role_key($this->user['role'] ?? '');
            if ($role !== 'manager') {
                throw new Exception('Manager privileges required to confirm receipts');
            }
            
            $stmt = $this->pdo->prepare("
                SELECT * FROM supplier_receipts 
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$receipt_id, $this->station_id]);
            $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$receipt) {
                throw new Exception('Receipt not found');
            }
            
            if ($receipt['status'] !== 'Pending Confirmation') {
                throw new Exception('Receipt already processed');
            }
            
            // CONFIRM: Update inventory (fuel vs merchandise)
             $stmt = $this->pdo->prepare("
                 SELECT ri.product_id, ri.quantity, p.type_id
                 FROM receipt_items ri
                 JOIN products p ON ri.product_id = p.id
                 WHERE ri.receipt_id = ?
             ");
             $stmt->execute([$receipt_id]);
             $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
             
             foreach ($items as $item) {
                 $product_id = $item['product_id'];
                 $quantity = $item['quantity'];
                 $type_id = $item['type_id'];
                 
                 // Determine correct inventory table based on product type
                 if ($type_id == 1) {
                     // FUEL: Use fuel_inventory table
                     $stmt = $this->pdo->prepare("
                         SELECT id FROM fuel_inventory
                         WHERE station_id = ? AND product_id = ?
                     ");
                     $stmt->execute([$this->station_id, $product_id]);
                     $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                     
                     if ($inv) {
                         // Increment fuel stock (in liters)
                         $stmt = $this->pdo->prepare("
                             UPDATE fuel_inventory
                             SET stock_level = stock_level + ?
                             WHERE id = ?
                         ");
                         $stmt->execute([$quantity, $inv['id']]);
                     } else {
                         // Create new fuel inventory record
                         $stmt = $this->pdo->prepare("
                             INSERT INTO fuel_inventory
                             (station_id, product_id, stock_level, unit, status)
                             VALUES (?, ?, ?, 'liters', 'active')
                         ");
                         $stmt->execute([$this->station_id, $product_id, $quantity]);
                     }
                 } else {
                     // MERCHANDISE: Use station_inventory table
                     $stmt = $this->pdo->prepare("
                         SELECT id FROM station_inventory
                         WHERE station_id = ? AND product_id = ?
                     ");
                     $stmt->execute([$this->station_id, $product_id]);
                     $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                     
                     if ($inv) {
                         // Increment merchandise stock (in pieces)
                         $stmt = $this->pdo->prepare("
                             UPDATE station_inventory
                             SET stock_level = stock_level + ?
                             WHERE id = ?
                         ");
                         $stmt->execute([$quantity, $inv['id']]);
                     } else {
                         // Create new merchandise inventory record
                         $stmt = $this->pdo->prepare("
                             INSERT INTO station_inventory
                             (station_id, product_id, stock_level, unit, status)
                             VALUES (?, ?, ?, 'pieces', 'active')
                         ");
                         $stmt->execute([$this->station_id, $product_id, $quantity]);
                     }
                 }
                 
                 // Log transaction
                 $stmt = $this->pdo->prepare("
                     INSERT INTO inventory_transactions
                     (station_id, product_id, transaction_type, quantity, notes, created_by, created_at)
                     VALUES (?, ?, 'addition', ?, ?, ?, NOW())
                 ");
                 $stmt->execute([
                     $this->station_id,
                     $product_id,
                     $quantity,
                     'Supplier receipt confirmed',
                     $this->user['id']
                 ]);
             }
            
            // UPDATE: Receipt status
            $stmt = $this->pdo->prepare("
                UPDATE supplier_receipts
                SET status = 'Confirmed',
                    confirmed_by = ?,
                    confirmed_at = NOW(),
                    admin_remarks = ?
                WHERE id = ?
            ");
            $stmt->execute([$this->user['id'], $remarks, $receipt_id]);
            
            log_activity(
                $this->pdo,
                $this->user['id'],
                'Receipt Confirmed',
                sprintf('Receipt %d confirmed and inventory updated', $receipt_id)
            );
            
            $this->pdo->commit();
            
            return ['success' => true, 'message' => 'Receipt confirmed and inventory updated.'];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Manager Verifies Reconciliation
     * Manager verifies fuel and stock reconciliation
     */
    public function managerVerifyReconciliation($reconciliation_date, $remarks = null) {
        try {
            $this->pdo->beginTransaction();
            
            // RBAC: Manager only
            $role = role_key($this->user['role'] ?? '');
            if ($role !== 'manager') {
                throw new Exception('Manager privileges required');
            }
            
            // FUEL RECONCILIATION FORMULA: (Present − Previous − Calibration) × Price/L
            $stmt = $this->pdo->prepare("
                SELECT * FROM fuel_readings
                WHERE station_id = ? AND DATE(reading_time) = ?
                ORDER BY reading_time DESC
                LIMIT 2
            ");
            $stmt->execute([$this->station_id, $reconciliation_date]);
            $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($readings) < 2) {
                throw new Exception('Insufficient fuel readings for reconciliation');
            }
            
            $current_reading = (float)($readings[0]['reading_liters'] ?? 0);
            $previous_reading = (float)($readings[1]['reading_liters'] ?? 0);
            $calibration_adj = (float)($readings[0]['calibration_adjustment'] ?? 0);
            $price_per_liter = (float)($readings[0]['price_per_liter'] ?? 50);
            
            // Apply formula
            $liters_variance = ($current_reading - $previous_reading - $calibration_adj);
            $monetary_variance = $liters_variance * $price_per_liter;
            
            // Check if variance is acceptable (< 5% or < 100 liters)
            $variance_percentage = abs($liters_variance / $previous_reading) * 100;
            $is_acceptable = ($variance_percentage < 5 || abs($liters_variance) < 100);
            
            // INSERT: Reconciliation record
            $stmt = $this->pdo->prepare("
                INSERT INTO fuel_reconciliation
                (station_id, reconciliation_date, current_reading, previous_reading,
                 calibration_adjustment, liters_variance, monetary_variance, 
                 variance_percentage, is_acceptable, verified_by, manager_remarks, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->station_id,
                $reconciliation_date,
                $current_reading,
                $previous_reading,
                $calibration_adj,
                $liters_variance,
                $monetary_variance,
                $variance_percentage,
                $is_acceptable ? 1 : 0,
                $this->user['id'],
                $remarks
            ]);
            
            $reconciliation_id = $this->pdo->lastInsertId();
            
            log_activity(
                $this->pdo,
                $this->user['id'],
                'Reconciliation Verified',
                sprintf('Fuel reconciliation: Variance %.2f L (%.2f%%), Status: %s',
                    $liters_variance,
                    $variance_percentage,
                    $is_acceptable ? 'Acceptable' : 'Requires Investigation'
                )
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => $is_acceptable ? 'Reconciliation verified - within acceptable variance.' 
                                           : 'Reconciliation requires investigation.',
                'reconciliation_id' => $reconciliation_id,
                'variance_liters' => $liters_variance,
                'variance_percentage' => $variance_percentage,
                'is_acceptable' => $is_acceptable
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get Inventory Status (Role-filtered)
     */
    public function getInventoryStatus() {
        $stmt = $this->pdo->prepare("
            SELECT si.*, p.name as product_name, p.unit
            FROM station_inventory si
            JOIN products p ON p.id = si.product_id
            WHERE si.station_id = ?
            ORDER BY p.category, p.name
        ");
        $stmt->execute([$this->station_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// API Handler
if (basename($_SERVER['PHP_SELF']) === 'inventory_operations.php') {
    require_login();
    
    $user = current_user();
    $station_id = user_station_id();
    $inventoryOps = new InventoryOperations($pdo, $user, $station_id);
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'record_receivals':
                $items = json_decode($_POST['items'] ?? '[]', true);
                $result = $inventoryOps->recordReceival(
                    $items,
                    $_POST['supplier_id'],
                    $_POST['po_reference'] ?? null
                );
                break;
                
            case 'admin_confirm_receipt':
                $result = $inventoryOps->adminConfirmReceipt(
                    $_POST['receipt_id'],
                    $_POST['remarks'] ?? null
                );
                break;
                
            case 'manager_verify_reconciliation':
                $result = $inventoryOps->managerVerifyReconciliation(
                    $_POST['reconciliation_date'],
                    $_POST['remarks'] ?? null
                );
                break;
                
            case 'get_inventory_status':
                $result = [
                    'success' => true,
                    'data' => $inventoryOps->getInventoryStatus()
                ];
                break;
                
            default:
                $result = ['success' => false, 'message' => 'Invalid action'];
        }
        
        json_response($result);
        
    } catch (Exception $e) {
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}
