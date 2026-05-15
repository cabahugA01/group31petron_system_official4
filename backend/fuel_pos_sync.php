<?php
/**
 * Backend: POS Fuel Inventory Sync Functions
 * Handles synchronization of fuel reconciliation to POS inventory
 * 
 * Simple approach: Only sync reconciliation closing stock to POS
 * No variance calculations, no delivery/adjustment handling
 */

/**
 * syncReconciliationToPOS()
 * 
 * Syncs fuel reconciliation closing stock to POS inventory
 * Updates station_inventory.stock_level to match pump closing_stock
 * 
 * @param PDO $pdo - Database connection
 * @param int $reconciliation_id - ID from fuel_reconciliation table
 * @param int $synced_by_user_id - User ID performing the sync
 * 
 * @return array [
 *   'success' => bool,
 *   'message' => string (user-friendly message),
 *   'previous_stock' => float (POS stock before sync),
 *   'new_stock' => float (POS stock after sync = closing_stock),
 *   'closing_stock' => float (pump closing stock),
 *   'sync_id' => int (reconciliation_id if successful)
 * ]
 */
function syncReconciliationToPOS($pdo, $reconciliation_id, $synced_by_user_id) {
    try {
        // 1. Validate user has permission (Manager+)
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$synced_by_user_id]);
        $user = $stmt->fetch();
        
        if (!$user || !in_array($user['role'], ['manager', 'admin', 'superadmin'])) {
            return [
                'success' => false,
                'message' => 'Only Manager+ can sync inventory to POS'
            ];
        }
        
         // 2. Fetch reconciliation details with fuel type info
         $stmt = $pdo->prepare("
             SELECT fr.*, ft.id as fuel_type_id, ft.name as fuel_type_name,
                    fr.physical_stock as closing_stock
             FROM fuel_reconciliation fr
             JOIN fuel_types ft ON fr.fuel_type_id = ft.id
             WHERE fr.id = ? AND (fr.status = 'finalized' OR fr.status = 'Finalized')
         ");
         $stmt->execute([$reconciliation_id]);
         $reconciliation = $stmt->fetch();
         
         if (!$reconciliation) {
             return [
                 'success' => false,
                 'message' => 'Reconciliation not found or not finalized'
             ];
         }
         
         // 3. Get the fuel product for this fuel type and get current POS inventory
         $stmt = $pdo->prepare("
             SELECT si.id as inventory_id, si.stock_level, si.station_id, p.id as product_id
             FROM station_inventory si
             INNER JOIN products p ON si.product_id = p.id
             INNER JOIN product_types pt ON p.type_id = pt.id
             WHERE si.station_id = ? AND pt.id = ?
             LIMIT 1
         ");
         $stmt->execute([$reconciliation['station_id'], $reconciliation['fuel_type_id']]);
         $inventory = $stmt->fetch();
         
         if (!$inventory) {
             return [
                 'success' => false,
                 'message' => 'Fuel product not configured in station inventory'
             ];
         }
         
         $previous_stock = (float) $inventory['stock_level'];
         $new_stock = (float) $reconciliation['closing_stock'];
        
        // 4. Begin transaction
        $pdo->beginTransaction();
        
        try {
            // 5. Update POS inventory to match closing stock
            $stmt = $pdo->prepare("
                UPDATE station_inventory 
                SET stock_level = ?,
                    last_synced_at = NOW(),
                    last_synced_by = ?,
                    last_sync_type = 'reconciliation',
                    last_sync_reference_id = ?,
                    in_sync = TRUE
                WHERE id = ?
            ");
            $stmt->execute([
                $new_stock,
                $synced_by_user_id,
                $reconciliation_id,
                $inventory['inventory_id']
            ]);
            
            // 6. Mark reconciliation as synced
            $stmt = $pdo->prepare("
                UPDATE fuel_reconciliation 
                SET synced_to_pos = TRUE, 
                    synced_at = NOW(), 
                    synced_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$synced_by_user_id, $reconciliation_id]);
            
            // 7. Log activity
            $change_amount = $new_stock - $previous_stock;
            $change_text = ($change_amount > 0) ? "+{$change_amount}" : "{$change_amount}";
            
            log_activity(
                $pdo,
                $synced_by_user_id,
                'Sync Reconciliation to POS',
                "Synced {$reconciliation['fuel_type_name']} reconciliation (ID: {$reconciliation_id}) to POS inventory. " .
                "Closing stock: {$new_stock}L. Change: {$change_text}L (was {$previous_stock}L)",
                'fuel_management'
            );
            
            // 8. Commit transaction
            $pdo->commit();
            
            return [
                'success' => true,
                'message' => "✅ Synced {$reconciliation['fuel_type_name']} reconciliation ({$new_stock}L) to POS inventory",
                'previous_stock' => $previous_stock,
                'new_stock' => $new_stock,
                'closing_stock' => $new_stock,
                'fuel_type' => $reconciliation['fuel_type_name'],
                'sync_id' => $reconciliation_id,
                'synced_at' => date('Y-m-d H:i:s'),
                'change_amount' => $change_amount
            ];
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

/**
 * getLastSyncStatus()
 * 
 * Returns when POS inventory was last synced for a specific fuel type
 * 
 * @param PDO $pdo
 * @param int $station_id
 * @param int $fuel_type_id
 * 
 * @return array [
 *   'last_synced_at' => timestamp string or NULL,
 *   'last_synced_by' => user name or NULL,
 *   'hours_since_sync' => integer or NULL,
 *   'is_out_of_sync' => boolean (TRUE if >24 hours),
 *   'in_sync' => boolean
 * ]
 */
function getLastSyncStatus($pdo, $station_id, $fuel_type_id) {
     try {
         // Join with products table to find the product with this fuel_type_id
         $stmt = $pdo->prepare("
             SELECT si.last_synced_at, si.last_synced_by, si.in_sync, u.name as synced_by_name
             FROM station_inventory si
             INNER JOIN products p ON si.product_id = p.id
             LEFT JOIN users u ON si.last_synced_by = u.id
             WHERE si.station_id = ? AND p.type_id = ?
         ");
         $stmt->execute([$station_id, $fuel_type_id]);
         $row = $stmt->fetch();
         
         if (!$row) {
             return [
                 'last_synced_at' => null,
                 'last_synced_by' => null,
                 'hours_since_sync' => null,
                 'is_out_of_sync' => true,
                 'in_sync' => true
             ];
         }
         
         $hours_since = null;
         $is_out_of_sync = false;
         
         if ($row['last_synced_at']) {
             $last_sync = new DateTime($row['last_synced_at']);
             $now = new DateTime();
             $interval = $now->diff($last_sync);
             $hours_since = ($interval->days * 24) + $interval->h;
             $is_out_of_sync = ($hours_since > 24);
         }
         
         return [
             'last_synced_at' => $row['last_synced_at'],
             'last_synced_by' => $row['synced_by_name'] ?? 'Unknown',
             'hours_since_sync' => $hours_since,
             'is_out_of_sync' => $is_out_of_sync,
             'in_sync' => (bool) $row['in_sync']
         ];
         
     } catch (PDOException $e) {
         return [
             'error' => $e->getMessage(),
             'last_synced_at' => null,
             'last_synced_by' => null
         ];
     }
}

/**
 * getUnSyncedReconciliations()
 * 
 * Returns all finalized reconciliations not yet synced to POS
 * 
 * @param PDO $pdo
 * @param int $station_id
 * 
 * @return array [
 *   'reconciliations' => [
 *     [
 *       'id' => reconciliation ID,
 *       'fuel_type' => fuel type name,
 *       'closing_stock' => liters to sync,
 *       'pos_current_stock' => current stock in POS,
 *       'difference' => closing_stock - pos_current_stock,
 *       'date' => reconciliation date
 *     ]
 *   ]
 * ]
 */
function getUnSyncedReconciliations($pdo, $station_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT fr.id, 
                   ft.name as fuel_type,
                   fr.physical_stock as closing_stock,
                   si.stock_level as pos_current_stock,
                   (fr.physical_stock - si.stock_level) as difference,
                   fr.reconciliation_date as date
            FROM fuel_reconciliation fr
            JOIN fuel_types ft ON fr.fuel_type_id = ft.id
            LEFT JOIN station_inventory si ON (
                si.station_id = ? 
                AND si.product_id IN (
                    SELECT p.id FROM products p 
                    WHERE p.type_id = ft.id
                    LIMIT 1
                )
            )
            WHERE fr.station_id = ? AND (fr.status = 'finalized' OR fr.status = 'Finalized') AND fr.synced_to_pos = FALSE
            ORDER BY fr.reconciliation_date DESC, ft.name ASC
        ");
        $stmt->execute([$station_id, $station_id]);
        $reconciliations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'reconciliations' => $reconciliations
        ];
        
     } catch (PDOException $e) {
         return [
             'success' => false,
             'error' => $e->getMessage(),
             'reconciliations' => []
         ];
     }
}

?>

?>
