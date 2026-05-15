<?php
/**
 * FUEL DELIVERY OPERATIONS
 * 
 * Handles the complete delivery workflow:
 * 1. Recording deliveries (Staff)
 * 2. Manager verification
 * 3. Admin finalization
 * 4. Automatic stock updates
 * 5. Audit trail logging
 */

// Include the fuel audit logging module
require_once __DIR__ . '/fuel_audit_logging.php';

class FuelDeliveryOperations {
    private $pdo;
    private $user;
    
    public function __construct($pdo, $user) {
        $this->pdo = $pdo;
        $this->user = $user;
    }
    
    /**
     * STEP 1: Record a new fuel delivery (Staff action)
     * Status: Encoded
     */
    public function record_delivery($station_id, $supplier_id, $delivery_date, $fuel_type, $invoice_no, $delivery_liters, $tanker_number, $notes) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO fuel_deliveries (
                    station_id, supplier_id, delivery_date, fuel_type, 
                    invoice_no, delivery_liters, tanker_number, 
                    received_by, notes, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Encoded', NOW())
            ");
            
            $stmt->execute([
                $station_id,
                $supplier_id,
                $delivery_date,
                $fuel_type,
                $invoice_no,
                $delivery_liters,
                $tanker_number,
                $this->user['id'],
                $notes
            ]);
            
            $delivery_id = $this->pdo->lastInsertId();
            
            // Log to activity logs
            log_activity(
                $this->pdo,
                $this->user['id'],
                'Delivery Recorded',
                "Recorded fuel delivery: {$delivery_liters}L of {$fuel_type} (Invoice: {$invoice_no})",
                'fuel_management'
            );
            
            // Log to fuel_inventory_logs via audit logging module
            log_fuel_inventory_action(
                $this->pdo,
                $this->user['id'],
                'delivery_recorded',
                'fuel_delivery',
                $delivery_id,
                $station_id,
                null, // product_id unknown at this stage
                [
                    'fuel_type' => $fuel_type,
                    'delivery_liters' => $delivery_liters,
                    'supplier_id' => $supplier_id,
                    'invoice_no' => $invoice_no,
                    'tanker_number' => $tanker_number,
                    'notes' => $notes
                ]
            );
            
            return [
                'success' => true,
                'delivery_id' => $delivery_id,
                'message' => "✓ Fuel delivery recorded successfully. ID: {$delivery_id}"
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "✗ Error recording delivery: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * STEP 2: Manager verifies delivery
     * Status: Encoded → Verified
     * Manager reviews delivery details and confirms receipt
     */
    public function verify_delivery($delivery_id, $manager_id, $verification_notes) {
        try {
            // Fetch delivery
            $stmt = $this->pdo->prepare("SELECT * FROM fuel_deliveries WHERE id = ?");
            $stmt->execute([$delivery_id]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$delivery) {
                return ['success' => false, 'message' => '✗ Delivery not found'];
            }
            
            if ($delivery['status'] !== 'Encoded') {
                return ['success' => false, 'message' => "✗ Delivery status is {$delivery['status']}, cannot verify"];
            }
            
            // Update delivery status to Verified
            $stmt = $this->pdo->prepare("
                UPDATE fuel_deliveries 
                SET status = 'Verified', 
                    verified_by = ?, 
                    verified_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), '\n[Manager Verification: ', ?, ']')
                WHERE id = ?
            ");
            
            $stmt->execute([$manager_id, $verification_notes, $delivery_id]);
            
            // Log to activity logs
            log_activity(
                $this->pdo,
                $manager_id,
                'Delivery Verified',
                "Verified fuel delivery ID {$delivery_id}: {$delivery['delivery_liters']}L of {$delivery['fuel_type']}",
                'fuel_management'
            );
            
            // Log to fuel_inventory_logs via audit logging module
            log_fuel_inventory_action(
                $this->pdo,
                $manager_id,
                'delivery_verified',
                'fuel_delivery',
                $delivery_id,
                $delivery['station_id'],
                null, // product_id still unknown at verification stage
                [
                    'fuel_type' => $delivery['fuel_type'],
                    'delivery_liters' => $delivery['delivery_liters'],
                    'supplier_id' => $delivery['supplier_id'],
                    'invoice_no' => $delivery['invoice_no'],
                    'verification_notes' => $verification_notes,
                    'status' => 'Verified'
                ]
            );
            
            return [
                'success' => true,
                'message' => "✓ Delivery verified successfully and awaiting admin finalization"
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "✗ Error verifying delivery: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * STEP 3: Admin finalizes delivery
     * Status: Verified → Finalized
     * Also triggers automatic stock update to fuel_inventory
     */
    public function finalize_delivery($delivery_id, $admin_id, $finalization_remarks) {
        try {
            // Fetch delivery
            $stmt = $this->pdo->prepare("SELECT * FROM fuel_deliveries WHERE id = ?");
            $stmt->execute([$delivery_id]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$delivery) {
                return ['success' => false, 'message' => '✗ Delivery not found'];
            }
            
            if ($delivery['status'] !== 'Verified') {
                return ['success' => false, 'message' => "✗ Delivery status is {$delivery['status']}, cannot finalize"];
            }
            
            // Find fuel product by type (assuming fuel_type matches product name or sku)
            $stmt = $this->pdo->prepare("
                SELECT id FROM products 
                WHERE type_id = 1 
                AND (name LIKE ? OR sku LIKE ?)
                LIMIT 1
            ");
            $stmt->execute(["%{$delivery['fuel_type']}%", "%{$delivery['fuel_type']}%"]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                return [
                    'success' => false,
                    'message' => "✗ Fuel product not found for type: {$delivery['fuel_type']}"
                ];
            }
            
            $product_id = $product['id'];
            
            // Get current stock before update
            $stmt = $this->pdo->prepare("
                SELECT stock_level FROM station_inventory 
                WHERE station_id = ? AND product_id = ?
            ");
            $stmt->execute([$delivery['station_id'], $product_id]);
            $current_inv = $stmt->fetch(PDO::FETCH_ASSOC);
            $quantity_before = $current_inv['stock_level'] ?? 0;
            
            // BEGIN TRANSACTION
            $this->pdo->beginTransaction();
            
            try {
                // Update station_inventory - ADD delivery liters to stock
                $stmt = $this->pdo->prepare("
                    UPDATE station_inventory 
                    SET stock_level = stock_level + ? 
                    WHERE station_id = ? AND product_id = ?
                ");
                
                $stmt->execute([
                    $delivery['delivery_liters'],
                    $delivery['station_id'],
                    $product_id
                ]);
                
                // Get new stock level
                $stmt = $this->pdo->prepare("
                    SELECT stock_level FROM station_inventory 
                    WHERE station_id = ? AND product_id = ?
                ");
                $stmt->execute([$delivery['station_id'], $product_id]);
                $updated_inv = $stmt->fetch(PDO::FETCH_ASSOC);
                $quantity_after = $updated_inv['stock_level'] ?? 0;
                
                // Update delivery status to Finalized
                $stmt = $this->pdo->prepare("
                    UPDATE fuel_deliveries 
                    SET status = 'Finalized', 
                        finalized_by = ?, 
                        finalized_at = NOW(),
                        notes = CONCAT(COALESCE(notes, ''), '\n[Admin Finalized: ', ?, ']')
                    WHERE id = ?
                ");
                
                $stmt->execute([$admin_id, $finalization_remarks, $delivery_id]);
                
                // Log to fuel_inventory_logs via audit logging module with stock details
                log_fuel_inventory_action(
                    $this->pdo,
                    $admin_id,
                    'delivery_finalized',
                    'fuel_delivery',
                    $delivery_id,
                    $delivery['station_id'],
                    $product_id,
                    [
                        'fuel_type' => $delivery['fuel_type'],
                        'delivery_liters' => $delivery['delivery_liters'],
                        'supplier_id' => $delivery['supplier_id'],
                        'invoice_no' => $delivery['invoice_no'],
                        'finalization_remarks' => $finalization_remarks,
                        'quantity_before' => $quantity_before,
                        'quantity_after' => $quantity_after,
                        'quantity_change' => $delivery['delivery_liters'],
                        'status' => 'Finalized'
                    ]
                );
                
                // Log to activity logs
                log_activity(
                    $this->pdo,
                    $admin_id,
                    'Delivery Finalized',
                    "Finalized fuel delivery ID {$delivery_id}: Added {$delivery['delivery_liters']}L. Stock: {$quantity_before}L → {$quantity_after}L",
                    'fuel_management'
                );
                
                $this->pdo->commit();
                
                return [
                    'success' => true,
                    'message' => "✓ Delivery finalized successfully. Stock updated: {$quantity_before}L → {$quantity_after}L",
                    'quantity_before' => $quantity_before,
                    'quantity_after' => $quantity_after
                ];
                
            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "✗ Error finalizing delivery: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Reject a delivery (Staff or Manager action)
     * Status: Encoded/Verified → Rejected
     */
    public function reject_delivery($delivery_id, $user_id, $rejection_reason) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM fuel_deliveries WHERE id = ?
            ");
            $stmt->execute([$delivery_id]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$delivery) {
                return ['success' => false, 'message' => '✗ Delivery not found'];
            }
            
            if (!in_array($delivery['status'], ['Encoded', 'Verified'])) {
                return ['success' => false, 'message' => "✗ Cannot reject delivery with status: {$delivery['status']}"];
            }
            
            $stmt = $this->pdo->prepare("
                UPDATE fuel_deliveries 
                SET status = 'Rejected', 
                    verified_by = ?, 
                    verified_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$user_id, $rejection_reason, $delivery_id]);
            
            log_activity(
                $this->pdo,
                $user_id,
                'Delivery Rejected',
                "Rejected fuel delivery ID {$delivery_id}: {$rejection_reason}",
                'fuel_management'
            );
            
            // Log to fuel_inventory_logs via audit logging module
            log_fuel_inventory_action(
                $this->pdo,
                $user_id,
                'delivery_rejected',
                'fuel_delivery',
                $delivery_id,
                $delivery['station_id'],
                null, // product_id not applicable for rejection
                [
                    'fuel_type' => $delivery['fuel_type'],
                    'delivery_liters' => $delivery['delivery_liters'],
                    'rejection_reason' => $rejection_reason,
                    'status' => 'Rejected'
                ]
            );
            
            return [
                'success' => true,
                'message' => "✓ Delivery rejected successfully"
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "✗ Error rejecting delivery: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Fetch deliveries by status for workflow views
     */
    public function get_deliveries_by_status($station_id, $status) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    fd.*, 
                    s.name as supplier_name,
                    u_received.name as received_by_name,
                    u_verified.name as verified_by_name,
                    u_finalized.name as finalized_by_name
                FROM fuel_deliveries fd
                LEFT JOIN suppliers s ON fd.supplier_id = s.id
                LEFT JOIN users u_received ON fd.received_by = u_received.id
                LEFT JOIN users u_verified ON fd.verified_by = u_verified.id
                LEFT JOIN users u_finalized ON fd.finalized_by = u_finalized.id
                WHERE fd.station_id = ? AND fd.status = ?
                ORDER BY fd.delivery_date DESC, fd.created_at DESC
            ");
            
            $stmt->execute([$station_id, $status]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}
?>
