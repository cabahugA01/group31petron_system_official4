<?php
/**
 * Backend: Inventory Automation - Real-Time Fuel Stock Tracking
 * 
 * Provides automatic stock updates and transaction logging for all fuel movements
 * 
 * Transaction Types:
 * - pump_reading: Staff records shift reading (deducted at verification time)
 * - delivery_finalized: Manager finalizes delivery
 * - adjustment_approved: Manager approves adjustment
 * - pos_sale: Fuel sold at POS
 * - reconciliation_sync: Reconciliation synced to POS
 * - manual_adjustment: Direct inventory change
 *
 * LINKAGE: fuel_types.name must match products.name for fuel products.
 * The JOIN uses: products.name = fuel_types.name AND products.type_id = (fuel product_type)
 */

/**
 * recordStockMovement()
 * 
 * Records a stock movement and updates inventory in real-time
 * 
 * @param PDO $pdo - Database connection
 * @param int $station_id - Station ID
 * @param int $fuel_type_id - Fuel type ID from fuel_types table
 * @param float $quantity - Quantity to add (positive) or deduct (negative)
 * @param string $transaction_type - Type of transaction (pump_reading, delivery_finalized, etc.)
 * @param string $reference_type - Reference table name (fuel_daily_readings, fuel_deliveries, etc.)
 * @param int|null $reference_id - ID of the referenced record
 * @param int $user_id - User ID performing the action
 * @param string $notes - Optional notes about the transaction
 * 
 * @return array [
 *   'success' => bool,
 *   'message' => string,
 *   'stock_before' => float,
 *   'stock_after' => float,
 *   'transaction_id' => int|null
 * ]
 */
function recordStockMovement($pdo, $station_id, $fuel_type_id, $quantity, $transaction_type, $reference_type, $reference_id, $user_id, $notes = '') {
    try {
        // Validate inputs
        if (!$station_id || !$fuel_type_id || !is_numeric($quantity) || !$transaction_type || !$user_id) {
            return [
                'success' => false,
                'message' => 'Invalid parameters for stock movement'
            ];
        }

        // Validate fuel type exists
        $stmt = $pdo->prepare("SELECT id, name FROM fuel_types WHERE id = ?");
        $stmt->execute([$fuel_type_id]);
        $fuel_type = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fuel_type) {
            return [
                'success' => false,
                'message' => 'Fuel type not found'
            ];
        }

        $source_reference_type = $reference_type;
        $source_reference_id = $reference_id;
        $transaction_reference_id = null;
        if ($reference_type && $reference_id && in_array(strtolower((string)$reference_type), ['purchase_order', 'purchase_orders'], true)) {
            $stmt = $pdo->prepare("SELECT id FROM purchase_orders WHERE id = ? LIMIT 1");
            $stmt->execute([$reference_id]);
            $transaction_reference_id = $stmt->fetchColumn() ? (int)$reference_id : null;
        }

        $transaction_notes = $notes;
        if ($source_reference_type && $source_reference_id && $transaction_reference_id === null) {
            $source_note = "Source reference: {$source_reference_type}#{$source_reference_id}";
            $transaction_notes = trim($transaction_notes ? "{$transaction_notes} | {$source_note}" : $source_note);
        }

        // inventory_transactions.product_id references inventory_products.id.
        $stmt = $pdo->prepare("
            SELECT id
            FROM inventory_products
            WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))
              AND LOWER(COALESCE(category, '')) = 'fuel'
            ORDER BY CASE WHEN station_id = ? THEN 0 ELSE 1 END, id
            LIMIT 1
        ");
        $stmt->execute([$fuel_type['name'], $station_id]);
        $product_id = $stmt->fetchColumn();

        if (!$product_id) {
            $sku_base = preg_replace('/[^A-Z0-9]+/', '-', strtoupper($fuel_type['name']));
            $sku = 'FUEL-' . trim($sku_base, '-') . '-' . $station_id . '-' . $fuel_type_id;
            $stmt = $pdo->prepare("
                INSERT INTO inventory_products
                    (category, product_name, sku, unit, unit_cost, unit_price, stock_quantity, stock,
                     created_at, status, min_stock, max_stock, updated_at, station_id)
                VALUES ('Fuel', ?, ?, 'liters', 0, 0, 0, 0, NOW(), 'active', 0, 0, NOW(), ?)
            ");
            $stmt->execute([$fuel_type['name'], $sku, $station_id]);
            $product_id = $pdo->lastInsertId();
        }

        // Check for duplicate transaction (prevent double-deducting same reading)
        if ($source_reference_type && $source_reference_id) {
            if ($transaction_reference_id !== null) {
                $stmt = $pdo->prepare("
                    SELECT id FROM inventory_transactions
                    WHERE reference_type = ? AND reference_id = ? AND transaction_type = ?
                ");
                $stmt->execute([$reference_type, $transaction_reference_id, $transaction_type]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT id FROM inventory_transactions
                    WHERE reference_type = ? AND transaction_type = ? AND notes LIKE ?
                ");
                $stmt->execute([$reference_type, $transaction_type, "%Source reference: {$source_reference_type}#{$source_reference_id}%"]);
            }
            if ($stmt->fetch()) {
                return [
                    'success' => false,
                    'message' => 'Stock already ' . ($quantity < 0 ? 'deducted' : 'added') . ' for this ' . str_replace('_', ' ', $reference_type)
                ];
            }
        }

        // Fuel inventory is the source of truth for fuel reports and dashboards.
        $stmt = $pdo->prepare("
            SELECT id, current_level, current_stock
            FROM fuel_inventory
            WHERE station_id = ?
              AND (fuel_type_id = ? OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)))
            LIMIT 1
        ");
        $stmt->execute([$station_id, $fuel_type_id, $fuel_type['name']]);
        $fuel_inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fuel_inventory) {
            $stmt = $pdo->prepare("
                INSERT INTO fuel_inventory
                    (station_id, fuel_type_id, current_stock, fuel_type, current_level, capacity,
                     reorder_level, critical_level, price_per_liter, latest_calibration, status, last_updated, updated_by)
                VALUES (?, ?, 0, ?, 0, 0, 500, 200, 0, 0, 'Normal', NOW(), ?)
            ");
            $stmt->execute([$station_id, $fuel_type_id, $fuel_type['name'], $user_id]);
            $fuel_inventory_id = $pdo->lastInsertId();
            $stock_before = 0.0;
        } else {
            $fuel_inventory_id = (int)$fuel_inventory['id'];
            $stock_before = (float)($fuel_inventory['current_level'] ?? $fuel_inventory['current_stock'] ?? 0);
        }

        // Keep station_inventory in sync for legacy modules that still read it.
        $stmt = $pdo->prepare("
            SELECT id
            FROM station_inventory
            WHERE station_id = ? AND product_id = ?
        ");
        $stmt->execute([$station_id, $product_id]);
        $inventory_id = $stmt->fetchColumn();

        if (!$inventory_id) {
            $stmt = $pdo->prepare("
                INSERT INTO station_inventory (station_id, product_id, stock_level, unit, status, last_updated)
                VALUES (?, ?, ?, 'liters', 'active', NOW())
            ");
            $stmt->execute([$station_id, $product_id, $stock_before]);
            $inventory_id = $pdo->lastInsertId();
        }
        
        // Calculate new stock
        $stock_after = $stock_before + $quantity;
        
        // Validate stock doesn't go negative
        if ($stock_after < 0 && $transaction_type !== 'reconciliation_sync') {
            return [
                'success' => false,
                'message' => 'Insufficient stock. Cannot deduct more than available.',
                'stock_before' => $stock_before,
                'requested_deduction' => abs($quantity),
                'available' => $stock_before
            ];
        }
        
        $started_transaction = !$pdo->inTransaction();
        if ($started_transaction) {
            $pdo->beginTransaction();
        }
        
        try {
            // Update fuel_inventory first; this table feeds fuel reports and dashboards.
            $stmt = $pdo->prepare("
                UPDATE fuel_inventory
                SET current_level = ?,
                    current_stock = ?,
                    status = CASE
                        WHEN ? <= 0 THEN 'Low Stock'
                        WHEN reorder_level > 0 AND ? <= reorder_level THEN 'Low Stock'
                        ELSE 'Normal'
                    END,
                    last_updated = NOW(),
                    updated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$stock_after, $stock_after, $stock_after, $stock_after, $user_id, $fuel_inventory_id]);

            // Keep legacy station inventory stock in sync.
            $stmt = $pdo->prepare("
                UPDATE station_inventory 
                SET stock_level = ?,
                    last_updated = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$stock_after, $inventory_id]);
            
            // Record transaction in inventory_transactions
            $stmt = $pdo->prepare("
                INSERT INTO inventory_transactions 
                (station_id, product_id, transaction_type, quantity, reference_type, reference_id, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $station_id,
                $product_id,
                $transaction_type,
                $quantity,
                $reference_type,
                $transaction_reference_id,
                $transaction_notes,
                $user_id
            ]);
            
            $transaction_id = $pdo->lastInsertId();

            try {
                $pdo->prepare("
                    INSERT INTO audit_logs
                        (user_id, log_type, action_type, action_details, entity_type, entity_id,
                         old_values, new_values, status, ip_address, user_agent, created_at)
                    VALUES (?, 'inventory', ?, ?, 'inventory_transactions', ?, ?, ?, 'Success', ?, ?, NOW())
                ")->execute([
                    $user_id,
                    $transaction_type,
                    "{$transaction_type}: {$fuel_type['name']} {$quantity} L. Stock {$stock_before}L -> {$stock_after}L",
                    $transaction_id,
                    json_encode(['stock_level' => $stock_before, 'fuel_type' => $fuel_type['name']]),
                    json_encode([
                        'stock_level' => $stock_after,
                        'fuel_type' => $fuel_type['name'],
                        'quantity' => $quantity,
                        'reference_type' => $reference_type,
                        'reference_id' => $transaction_reference_id,
                        'source_reference_type' => $source_reference_type,
                        'source_reference_id' => $source_reference_id,
                        'notes' => $transaction_notes
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'CLI'
                ]);
            } catch (Exception $e) {
                error_log('recordStockMovement audit_logs warning: ' . $e->getMessage());
            }
            
            // Generate activity description
            $action_descriptions = [
                'pump_reading' => 'Recorded pump reading',
                'delivery_finalized' => 'Finalized fuel delivery',
                'adjustment_approved' => 'Approved fuel adjustment',
                'pos_sale' => 'Sold fuel at POS',
                'reconciliation_sync' => 'Synced reconciliation to POS',
                'manual_adjustment' => 'Manual inventory adjustment',
                'job_order_fuel' => 'Deducted fuel for job order'
            ];
            
            $action = $action_descriptions[$transaction_type] ?? 'Stock movement';
            $quantity_text = ($quantity > 0) ? "+{$quantity}" : "{$quantity}";
            
            log_activity(
                $pdo,
                $user_id,
                $action,
                "{$action}: {$fuel_type['name']} {$quantity_text} L. Stock: {$stock_before}L -> {$stock_after}L" . 
                ($transaction_notes ? ". Notes: {$transaction_notes}" : ''),
                'fuel_management'
            );
            
            if ($started_transaction) {
                $pdo->commit();
            }
            
            return [
                'success' => true,
                'message' => 'Stock movement recorded successfully',
                'fuel_type' => $fuel_type['name'],
                'stock_before' => $stock_before,
                'stock_after' => $stock_after,
                'quantity' => $quantity,
                'transaction_id' => $transaction_id,
                'transaction_type' => $transaction_type
            ];
            
        } catch (PDOException $e) {
            if ($started_transaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
 * getCurrentStock()
 * 
 * Gets current stock level for a fuel type at a station
 * 
 * @param PDO $pdo - Database connection
 * @param int $station_id - Station ID
 * @param int $fuel_type_id - Fuel type ID
 * 
 * @return array [
 *   'stock_level' => float,
 *   'fuel_type_name' => string,
 *   'inventory_id' => int|null
 * ]
 */
function getCurrentStock($pdo, $station_id, $fuel_type_id) {
    try {
        // Get fuel type name
        $stmt = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
        $stmt->execute([$fuel_type_id]);
        $fuel_type_name = $stmt->fetchColumn();
        
        if (!$fuel_type_name) {
            return [
                'stock_level' => 0,
                'fuel_type_name' => 'Unknown',
                'inventory_id' => null
            ];
        }
        
        // Fuel inventory is the reporting source of truth for fuel levels.
        $stmt = $pdo->prepare("
            SELECT id AS inventory_id, COALESCE(current_level, current_stock, 0) AS stock_level
            FROM fuel_inventory
            WHERE station_id = ?
              AND (fuel_type_id = ? OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)))
            LIMIT 1
        ");
        $stmt->execute([$station_id, $fuel_type_id, $fuel_type_name]);
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($inventory) {
            return [
                'stock_level' => (float)$inventory['stock_level'],
                'fuel_type_name' => $fuel_type_name,
                'inventory_id' => $inventory['inventory_id']
            ];
        } else {
            return [
                'stock_level' => 0,
                'fuel_type_name' => $fuel_type_name,
                'inventory_id' => null
            ];
        }
        
    } catch (Exception $e) {
        return [
            'stock_level' => 0,
            'fuel_type_name' => 'Unknown',
            'inventory_id' => null,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * recordDailyClosingStock()
 * 
 * Records closing stock for a shift to be used as opening stock for next day
 * 
 * @param PDO $pdo - Database connection
 * @param int $station_id - Station ID
 * @param int $fuel_type_id - Fuel type ID
 * @param float $closing_stock - Closing stock amount
 * @param string $shift - Shift name (Morning, Afternoon, Evening)
 * @param string $closing_date - Date of closing
 * @param int $user_id - User ID performing the action
 * 
 * @return array [
 *   'success' => bool,
 *   'message' => string
 * ]
 */
function recordDailyClosingStock($pdo, $station_id, $fuel_type_id, $closing_stock, $shift, $closing_date, $user_id) {
    try {
        // Get fuel type name
        $stmt = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
        $stmt->execute([$fuel_type_id]);
        $fuel_type_name = $stmt->fetchColumn();
        
        if (!$fuel_type_name) {
            return [
                'success' => false,
                'message' => 'Fuel type not found'
            ];
        }
        
        // Find the station_inventory mirror row used for shift closing metadata.
        $stmt = $pdo->prepare("
            SELECT si.id as inventory_id
            FROM station_inventory si
            INNER JOIN inventory_products ip ON si.product_id = ip.id
            WHERE si.station_id = ?
              AND LOWER(TRIM(ip.product_name)) = LOWER(TRIM(?))
              AND LOWER(COALESCE(ip.category, '')) = 'fuel'
            LIMIT 1
        ");
        $stmt->execute([$station_id, $fuel_type_name]);
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$inventory) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM inventory_products
                WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))
                  AND LOWER(COALESCE(category, '')) = 'fuel'
                ORDER BY CASE WHEN station_id = ? THEN 0 ELSE 1 END, id
                LIMIT 1
            ");
            $stmt->execute([$fuel_type_name, $station_id]);
            $product_id = $stmt->fetchColumn();

            if (!$product_id) {
                $sku_base = preg_replace('/[^A-Z0-9]+/', '-', strtoupper($fuel_type_name));
                $sku = 'FUEL-' . trim($sku_base, '-') . '-' . $station_id . '-' . $fuel_type_id;
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_products
                        (category, product_name, sku, unit, unit_cost, unit_price, stock_quantity, stock,
                         created_at, status, min_stock, max_stock, updated_at, station_id)
                    VALUES ('Fuel', ?, ?, 'liters', 0, 0, 0, 0, NOW(), 'active', 0, 0, NOW(), ?)
                ");
                $stmt->execute([$fuel_type_name, $sku, $station_id]);
                $product_id = $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("
                SELECT COALESCE(current_level, current_stock, 0)
                FROM fuel_inventory
                WHERE station_id = ?
                  AND (fuel_type_id = ? OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)))
                LIMIT 1
            ");
            $stmt->execute([$station_id, $fuel_type_id, $fuel_type_name]);
            $current_level = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO station_inventory (station_id, product_id, stock_level, unit, status, last_updated)
                VALUES (?, ?, ?, 'liters', 'active', NOW())
            ");
            $stmt->execute([$station_id, $product_id, $current_level]);
            $inventory = ['inventory_id' => $pdo->lastInsertId()];
        }
        
        // Update closing stock
        $stmt = $pdo->prepare("
            UPDATE station_inventory 
            SET closing_stock = ?,
                closing_date = ?,
                closing_shift = ?
            WHERE id = ?
        ");
        $stmt->execute([$closing_stock, $closing_date, $shift, $inventory['inventory_id']]);
        
        // Log activity
        log_activity(
            $pdo,
            $user_id,
            'Record Daily Closing Stock',
            "Recorded closing stock for {$fuel_type_name} ({$shift} shift): {$closing_stock}L on {$closing_date}",
            'fuel_management'
        );
        
        return [
            'success' => true,
            'message' => 'Daily closing stock recorded successfully'
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

/**
 * getOpeningStockForDay()
 * 
 * Calculates opening stock for a given day
 * Formula: Previous Day Ending Balance + Finalized Deliveries (Petron Corporation only)
 * 
 * @param PDO $pdo - Database connection
 * @param int $station_id - Station ID
 * @param int $fuel_type_id - Fuel type ID
 * @param string $date - Date to calculate opening stock for
 * 
 * @return array [
 *   'opening_stock' => float,
 *   'previous_day_closing' => float,
 *   'finalized_deliveries' => float,
 *   'calculation_details' => string
 * ]
 */
function getOpeningStockForDay($pdo, $station_id, $fuel_type_id, $date) {
    try {
        // Get fuel type name first
        $stmt = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
        $stmt->execute([$fuel_type_id]);
        $fuel_type_name = $stmt->fetchColumn();
        
        if (!$fuel_type_name) {
            return [
                'opening_stock' => 0,
                'error' => 'Fuel type not found'
            ];
        }
        
        // Get previous day's closing stock
        $previous_day = date('Y-m-d', strtotime($date . ' -1 day'));
        
        $stmt = $pdo->prepare("
            SELECT si.closing_stock, si.closing_shift
            FROM station_inventory si
            INNER JOIN inventory_products ip ON si.product_id = ip.id
            WHERE si.station_id = ? 
              AND LOWER(TRIM(ip.product_name)) = LOWER(TRIM(?))
              AND LOWER(COALESCE(ip.category, '')) = 'fuel'
              AND si.closing_date = ?
            LIMIT 1
        ");
        $stmt->execute([$station_id, $fuel_type_name, $previous_day]);
        $previous_closing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $previous_day_closing = $previous_closing ? (float)$previous_closing['closing_stock'] : 0.0;
        $previous_shift = $previous_closing ? $previous_closing['closing_shift'] : '';
        
        // Get finalized deliveries for the target date (from Petron Corporation only)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(delivery_liters), 0) as total_deliveries
            FROM fuel_deliveries
            WHERE station_id = ? 
                AND fuel_type = ?
                AND delivery_date = ? 
                AND supplier = 'Petron Corporation'
                AND status = 'Finalized'
        ");
        $stmt->execute([$station_id, $fuel_type_name, $date]);
        $finalized_deliveries = (float)$stmt->fetchColumn();
        
        // Calculate opening stock
        $opening_stock = $previous_day_closing + $finalized_deliveries;
        
        // Build calculation details
        $calculation_details = "Opening Stock = Previous Day Closing ({$previous_day_closing}L" . 
                               ($previous_shift ? " from {$previous_shift} shift" : "") . 
                               ") + Finalized Deliveries ({$finalized_deliveries}L) = {$opening_stock}L";
        
        return [
            'opening_stock' => $opening_stock,
            'previous_day_closing' => $previous_day_closing,
            'previous_day' => $previous_day,
            'previous_shift' => $previous_shift,
            'finalized_deliveries' => $finalized_deliveries,
            'calculation_details' => $calculation_details
        ];
        
    } catch (PDOException $e) {
        return [
            'opening_stock' => 0,
            'error' => $e->getMessage()
        ];
    }
}
?>
