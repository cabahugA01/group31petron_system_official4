<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_stock_confirmation';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? ''));

// Manager, Admin, or Superadmin only
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$msg = '';
$view = $_GET['view'] ?? 'received';
$batch_id = $_GET['batch'] ?? null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'confirm_stock') {
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        $item_ids = $_POST['item_ids'] ?? []; // Array of item IDs
        $cost_prices = $_POST['cost_price'] ?? []; // Array of item_id => cost
        $selling_prices = $_POST['selling_price'] ?? []; // Array of item_id => price
        $notes = $_POST['notes'] ?? '';
        
        if (empty($item_ids)) {
            $msg = "❌ No items to confirm.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get batch
                $stmt = $pdo->prepare("SELECT * FROM receiving_batches WHERE id = ? AND status = 'received'");
                $stmt->execute([$batch_id]);
                $batch = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$batch) {
                    $msg = "❌ Batch not found or not ready for confirmation.";
                } else {
                    $items_confirmed = 0;
                    $total_quantity = 0;
                    
                    // Process each item
                    foreach ($item_ids as $item_id) {
                        $item_id = (int)$item_id;
                        $item_cost = isset($cost_prices[$item_id]) ? (float)$cost_prices[$item_id] : 0;
                        $item_price = isset($selling_prices[$item_id]) ? (float)$selling_prices[$item_id] : 0;
                        
                        // Get item details
                        $stmt_item = $pdo->prepare("SELECT * FROM received_items WHERE id = ? AND batch_id = ?");
                        $stmt_item->execute([$item_id, $batch_id]);
                        $item = $stmt_item->fetch(PDO::FETCH_ASSOC);
                        
                        if ($item && $item['status'] !== 'confirmed') {
                            $qty_to_add = (float)$item['quantity'];
                            
                            // Update item status to confirmed
                            $stmt_update = $pdo->prepare("UPDATE received_items SET status = 'confirmed' WHERE id = ?");
                            $stmt_update->execute([$item_id]);
                            $items_confirmed++;
                            
                            // Update product cost/price in products table
                            if ($item['product_id'] && ($item_cost > 0 || $item_price > 0)) {
                                $stmt_prod = $pdo->prepare("
                                    UPDATE products 
                                    SET cost = COALESCE(?, cost), price = COALESCE(?, price), updated_at = NOW()
                                    WHERE id = ?
                                ");
                                $stmt_prod->execute([
                                    $item_cost > 0 ? $item_cost : null,
                                    $item_price > 0 ? $item_price : null,
                                    $item['product_id']
                                ]);
                            }
                            
                            // Update or create station_inventory with cost/price
                            $stmt_inv = $pdo->prepare("
                                SELECT id, stock_level FROM station_inventory 
                                WHERE station_id = ? AND product_id = ?
                            ");
                            $stmt_inv->execute([$station_id, $item['product_id']]);
                            $inv_row = $stmt_inv->fetch(PDO::FETCH_ASSOC);
                            
                            $qty_before = $inv_row ? (float)$inv_row['stock_level'] : 0;
                            $qty_after = $qty_before + $qty_to_add;
                            
                            if ($inv_row) {
                                // Update existing - include cost/price if provided
                                $update_sql = "UPDATE station_inventory SET stock_level = stock_level + ?, last_updated = NOW()";
                                $update_params = [$qty_to_add];
                                
                                if ($item_cost > 0) {
                                    $update_sql .= ", cost = ?";
                                    $update_params[] = $item_cost;
                                }
                                if ($item_price > 0) {
                                    $update_sql .= ", price = ?";
                                    $update_params[] = $item_price;
                                }
                                
                                $update_sql .= " WHERE station_id = ? AND product_id = ?";
                                $update_params[] = $station_id;
                                $update_params[] = $item['product_id'];
                                
                                $stmt_upd = $pdo->prepare($update_sql);
                                $stmt_upd->execute($update_params);
                            } else {
                                // Create new with cost/price
                                $stmt_ins = $pdo->prepare("
                                    INSERT INTO station_inventory (station_id, product_id, stock_level, cost, price, unit, status, last_updated)
                                    VALUES (?, ?, ?, ?, ?, 'pieces', 'active', NOW())
                                ");
                                $stmt_ins->execute([
                                    $station_id, 
                                    $item['product_id'], 
                                    $qty_to_add,
                                    $item_cost > 0 ? $item_cost : null,
                                    $item_price > 0 ? $item_price : null
                                ]);
                            }
                            
                            // Log inventory change with cost/price info
                            $log_notes = "Batch: {$batch['batch_number']}, Item: {$item['item_name']}, Qty: $qty_to_add";
                            if ($item_cost > 0) $log_notes .= ", Cost: ₱" . number_format($item_cost, 2);
                            if ($item_price > 0) $log_notes .= ", Price: ₱" . number_format($item_price, 2);
                            if ($notes) $log_notes .= ". $notes";
                            
                            $stmt_log = $pdo->prepare("
                                INSERT INTO inventory_logs (station_id, product_id, user_id, action, quantity_before, quantity_after, quantity_change, reference_type, notes, created_at)
                                VALUES (?, ?, ?, 'stock_in', ?, ?, ?, 'receiving_batch', ?, NOW())
                            ");
                            $stmt_log->execute([
                                $station_id,
                                $item['product_id'],
                                $me['id'],
                                $qty_before,
                                $qty_after,
                                $qty_to_add,
                                $log_notes
                            ]);
                            
                            $total_quantity += $qty_to_add;
                        }
                    }
                    
                    // Check if all items in batch are confirmed
                    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM received_items WHERE batch_id = ? AND status != 'confirmed'");
                    $stmt_count->execute([$batch_id]);
                    $remaining_items = $stmt_count->fetchColumn();
                    
                    // Update batch status
                    if ($remaining_items == 0) {
                        // All items confirmed
                        $stmt_batch = $pdo->prepare("
                            UPDATE receiving_batches 
                            SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW(), updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt_batch->execute([$me['id'], $batch_id]);
                        
                        log_activity($pdo, $me['id'], 'Stock Confirmation', "Batch {$batch['batch_number']} fully confirmed. Added $items_confirmed items ($total_quantity pcs) to inventory with cost/price.", $_SERVER['REMOTE_ADDR']);
                        $msg = "✅ Batch {$batch['batch_number']} fully confirmed! All $items_confirmed items added to inventory.";
                    } else {
                        // Partially confirmed - keep as received
                        log_activity($pdo, $me['id'], 'Stock Confirmation', "Batch {$batch['batch_number']} partially confirmed. $items_confirmed items ($total_quantity pcs) added to inventory.", $_SERVER['REMOTE_ADDR']);
                        $msg = "✅ Partially confirmed $items_confirmed items ($total_quantity pcs). Batch remains for remaining items.";
                    }
                    
                    $pdo->commit();
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'return_to_pending') {
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        
        if (strlen($reason) < 10) {
            $msg = "❌ Reason must be at least 10 characters.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get batch
                $stmt = $pdo->prepare("SELECT * FROM receiving_batches WHERE id = ? AND status = 'received'");
                $stmt->execute([$batch_id]);
                $batch = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$batch) {
                    $msg = "❌ Batch not found.";
                } else {
                    // Update batch back to pending
                    $stmt_update = $pdo->prepare("
                        UPDATE receiving_batches 
                        SET status = 'pending', received_by_manager = NULL, received_at = NULL, notes = CONCAT(COALESCE(notes, ''), '\n--- Returned to Pending: ', ?), updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_update->execute([$reason, $batch_id]);
                    
                    // Update all items back to pending
                    $stmt_items = $pdo->prepare("
                        UPDATE received_items 
                        SET status = 'pending'
                        WHERE batch_id = ?
                    ");
                    $stmt_items->execute([$batch_id]);
                    
                    log_activity($pdo, $me['id'], 'Batch Returned to Pending', "Batch {$batch['batch_number']} returned. Reason: $reason", $_SERVER['REMOTE_ADDR']);
                    
                    $pdo->commit();
                    $msg = "✅ Batch {$batch['batch_number']} returned to pending status.";
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
}

// Fetch batches
$batches = [];
try {
    if ($role === 'superadmin') {
        $stmt = $pdo->query("
            SELECT rb.*, u.name as received_by_name, u2.name as received_by_manager_name, u3.name as confirmed_by_name
            FROM receiving_batches rb
            LEFT JOIN users u ON rb.received_by = u.id
            LEFT JOIN users u2 ON rb.received_by_manager = u2.id
            LEFT JOIN users u3 ON rb.confirmed_by = u3.id
            WHERE rb.status = 'received'
            ORDER BY rb.created_at DESC
        ");
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT rb.*, u.name as received_by_name, u2.name as received_by_manager_name, u3.name as confirmed_by_name
            FROM receiving_batches rb
            LEFT JOIN users u ON rb.received_by = u.id
            LEFT JOIN users u2 ON rb.received_by_manager = u2.id
            LEFT JOIN users u3 ON rb.confirmed_by = u3.id
            WHERE rb.station_id = ? AND rb.status = 'received'
            ORDER BY rb.created_at DESC
        ");
        $stmt->execute([$station_id]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $batches = [];
}

// Fetch batch details if viewing specific batch
$batch_details = null;
$batch_items = [];
if ($batch_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT rb.*, u.name as received_by_name, u2.name as received_by_manager_name
            FROM receiving_batches rb
            LEFT JOIN users u ON rb.received_by = u.id
            LEFT JOIN users u2 ON rb.received_by_manager = u2.id
            WHERE rb.id = ? AND rb.station_id = ?
        ");
        $stmt->execute([$batch_id, $station_id]);
        $batch_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($batch_details) {
            // Fetch items with current inventory AND product cost/price
            $stmt_items = $pdo->prepare("
                SELECT ri.*, 
                       COALESCE(si.stock_level, 0) as current_stock,
                       COALESCE(si.stock_level, 0) + ri.quantity as projected_stock,
                       COALESCE(p.cost, 0) as product_cost,
                       COALESCE(p.price, 0) as product_price
                FROM received_items ri
                LEFT JOIN station_inventory si ON ri.product_id = si.product_id AND si.station_id = ri.station_id
                LEFT JOIN products p ON ri.product_id = p.id
                WHERE ri.batch_id = ?
            ");
            $stmt_items->execute([$batch_id]);
            $batch_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $batch_details = null;
    }
}

include __DIR__ . '/../partials/header.php';
?>

<div style="max-width: 1400px; margin: 0 auto; padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h1 style="font-size: 32px; font-weight: 700; color: var(--petron-blue); margin: 0;">
                <i class="fas fa-lock"></i> Step 3: Stock Confirmation (Final Posting)
            </h1>
            <p style="color: var(--muted); margin-top: 4px; font-size: 14px;">
                <strong>Admin:</strong> Final review, confirm and post to inventory database with audit lock
            </p>
            <div style="margin-top: 8px; padding: 8px 12px; background: #dcfce7; border-left: 4px solid #16a34a; border-radius: 4px; font-size: 12px; color: #166534;">
                <i class="fas fa-shield-alt"></i> 
                <strong>Audit Lock:</strong> Prevents further edits • Automatic inventory updates • Confirmation report • Full traceability
            </div>
        </div>
    </div>
    
    <?php if ($msg): ?>
        <div style="padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; background: <?php echo strpos($msg, '✅') !== false ? '#e6f4ea' : '#fee2e2'; ?>; color: <?php echo strpos($msg, '✅') !== false ? '#065f46' : '#dc2626'; ?>; border: 1px solid <?php echo strpos($msg, '✅') !== false ? '#a7f3d0' : '#fecaca'; ?>; display: flex; align-items: center; gap: 10px;">
            <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($batch_details): ?>
        <!-- Batch Detail View -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 2px solid #f1f5f9;">
                <div>
                    <h2 style="font-size: 24px; font-weight: 700; color: #0f172a; margin: 0 0 8px;"><?php echo htmlspecialchars($batch_details['batch_number']); ?></h2>
                    <div style="display: flex; gap: 16px; flex-wrap: wrap; font-size: 14px; color: #64748b;">
                        <div><i class="fas fa-truck"></i> <strong>Supplier:</strong> <?php echo htmlspecialchars($batch_details['supplier']); ?></div>
                        <div><i class="fas fa-calendar"></i> <strong>Delivery Date:</strong> <?php echo date('M d, Y', strtotime($batch_details['delivery_date'])); ?></div>
                        <div><i class="fas fa-user"></i> <strong>Submitted By:</strong> <?php echo htmlspecialchars($batch_details['received_by_name']); ?></div>
                        <div><i class="fas fa-user-check"></i> <strong>Received By:</strong> <?php echo htmlspecialchars($batch_details['received_by_manager_name'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                <span style="background: #bfdbfe; color: #1e3a8a; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; text-transform: uppercase;">
                    Received - Ready for Confirmation
                </span>
            </div>
            
            <?php if ($batch_details['notes']): ?>
                <div style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 3px solid #eab308;">
                    <strong style="color: #0f172a; font-size: 13px;">Notes:</strong>
                    <p style="color: #64748b; font-size: 14px; margin: 4px 0 0 0;"><?php echo nl2br(htmlspecialchars($batch_details['notes'])); ?></p>
                </div>
            <?php endif; ?>
            
            <h3 style="font-size: 18px; font-weight: 600; color: #0f172a; margin: 0 0 16px;">Items to Add to Inventory</h3>
            
            <table style="width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #475569;">#</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #475569;">Item Name</th>
                        <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #475569;">Received</th>
                        <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #475569;">Current Stock</th>
                        <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #475569;">After Confirm</th>
                        <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #475569;">Cost</th>
                        <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #475569;">Price</th>
                        <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #475569;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $pending_items = []; ?>
                    <?php foreach ($batch_items as $index => $item): ?>
                        <?php if ($item['status'] !== 'confirmed') $pending_items[] = $item; ?>
                        <tr style="border-bottom: 1px solid #f1f5f9; <?php echo $item['status'] === 'confirmed' ? 'background: #f0fdf4;' : ''; ?>">
                            <td style="padding: 12px 16px; font-size: 13px;"><?php echo $index + 1; ?></td>
                            <td style="padding: 12px 16px; font-size: 13px; font-weight: 500; color: #0f172a;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td style="padding: 12px 16px; font-size: 13px; text-align: right; font-weight: 600; color: var(--petron-blue);"><?php echo number_format($item['quantity'], 0); ?></td>
                            <td style="padding: 12px 16px; font-size: 13px; text-align: right; color: #64748b;"><?php echo number_format($item['current_stock'], 0); ?></td>
                            <td style="padding: 12px 16px; font-size: 13px; text-align: right; font-weight: 600; color: #059669;"><?php echo number_format($item['projected_stock'], 0); ?></td>
                            <td style="padding: 12px 16px; font-size: 13px; text-align: right; color: #64748b;"><?php echo $item['product_cost'] > 0 ? '₱' . number_format($item['product_cost'], 2) : '-'; ?></td>
                            <td style="padding: 12px 16px; font-size: 13px; text-align: right; color: #64748b;"><?php echo $item['product_price'] > 0 ? '₱' . number_format($item['product_price'], 2) : '-'; ?></td>
                            <td style="padding: 12px 16px; text-align: center;">
                                <?php if ($item['status'] === 'confirmed'): ?>
                                    <span style="background: #dcfce7; color: #15803d; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 600;">CONFIRMED</span>
                                <?php else: ?>
                                    <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 600;">PENDING</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="background: #dbeafe; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 3px solid #2563eb;">
                <strong style="color: #1e3a8a; font-size: 13px;">How Stock Confirmation Works:</strong>
                <p style="color: #1e3a8a; font-size: 14px; margin: 4px 0 0 0;">Click "Confirm Stock" to open the cost/price modal. Set the cost and selling price for each item before confirming. This will add stock to inventory AND update product pricing.</p>
            </div>
            
            <!-- Actions -->
            <?php if (!empty($pending_items)): ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <button type="button" onclick="showCostPriceModal()" style="width: 100%; background: #22c55e; color: white; border: none; padding: 14px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <i class="fas fa-check-circle"></i> Confirm Stock (<?php echo count($pending_items); ?> items)
                </button>
                
                <button type="button" onclick="showReturnModal(<?php echo $batch_details['id']; ?>, '<?php echo htmlspecialchars($batch_details['batch_number']); ?>')" style="width: 100%; background: #f59e0b; color: white; border: none; padding: 14px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <i class="fas fa-undo"></i> Return to Pending
                </button>
            </div>
            <?php else: ?>
            <div style="background: #dcfce7; padding: 16px 20px; border-radius: 8px; text-align: center; color: #15803d;">
                <i class="fas fa-check-circle"></i> All items in this batch have been confirmed!
            </div>
            <?php endif; ?>
        </div>
        
        <a href="?view=received" class="btn ghost" style="display: inline-flex; align-items: center; gap: 8px;">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
    <?php else: ?>
        <!-- Batch List View -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px;">
            <?php if (empty($batches)): ?>
                <div style="grid-column: 1 / -1; background: white; border-radius: 12px; padding: 60px 20px; text-align: center; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                    <i class="fas fa-inbox" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px;"></i>
                    <div style="font-size: 18px; font-weight: 600; color: #0f172a; margin-bottom: 8px;">No Batches Ready for Confirmation</div>
                    <div style="color: #64748b; font-size: 14px;">There are no received batches waiting for stock confirmation.</div>
                </div>
            <?php else: ?>
                <?php foreach ($batches as $batch): ?>
                    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); cursor: pointer; transition: all 0.2s;" onclick="window.location.href='?view=received&batch=<?php echo $batch['id']; ?>'" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div>
                                <div style="font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 4px;"><?php echo htmlspecialchars($batch['batch_number']); ?></div>
                                <div style="font-size: 13px; color: #64748b;"><i class="fas fa-truck"></i> <?php echo htmlspecialchars($batch['supplier']); ?></div>
                            </div>
                            <span style="background: #bfdbfe; color: #1e3a8a; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                Received
                            </span>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px; color: #64748b;">
                            <div><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($batch['delivery_date'])); ?></div>
                            <div><i class="fas fa-user-check"></i> <?php echo htmlspecialchars($batch['received_by_manager_name'] ?? 'N/A'); ?></div>
                        </div>
                        
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #059669; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-check-circle"></i> Received on <?php echo date('M d, Y H:i', strtotime($batch['received_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Return Modal -->
<div id="returnModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; padding: 20px;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 500px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <h3 style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 20px;">Return Batch to Pending</h3>
        <p style="color: #64748b; font-size: 14px; margin-bottom: 16px;">Please provide a reason for returning this batch to pending status (minimum 10 characters).</p>
        
        <form method="post">
            <input type="hidden" name="action" value="return_to_pending">
            <input type="hidden" name="batch_id" id="returnBatchId">
            
            <div style="margin-bottom: 20px;">
                <label style="font-size: 13px; font-weight: 600; color: #475569; display: block; margin-bottom: 8px;">Reason for Return *</label>
                <textarea name="reason" id="returnReason" rows="4" placeholder="Explain why this batch is being returned..." required style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: none;"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <button type="button" onclick="closeReturnModal()" style="background: #f3f4f6; color: #64748b; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px;">
                    Cancel
                </button>
                <button type="submit" style="background: #f59e0b; color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px;">
                    Return to Pending
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Cost/Price Modal -->
<?php if ($batch_details && !empty($pending_items)): ?>
<div id="costPriceModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; padding: 20px; overflow-y: auto;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 900px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); margin: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9;">
            <div>
                <h3 style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0;">Set Cost & Price</h3>
                <p style="color: #64748b; font-size: 13px; margin: 4px 0 0;">Batch: <?php echo htmlspecialchars($batch_details['batch_number']); ?></p>
            </div>
            <button type="button" onclick="closeCostPriceModal()" style="background: none; border: none; font-size: 24px; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>
        
        <form method="post" id="confirmStockForm">
            <input type="hidden" name="action" value="confirm_stock">
            <input type="hidden" name="batch_id" value="<?php echo $batch_details['id']; ?>">
            
            <div style="background: #fef3c7; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 3px solid #f59e0b;">
                <strong style="color: #92400e; font-size: 13px;">Important:</strong>
                <span style="color: #92400e; font-size: 13px;"> Set cost and selling price for each item. These will update the product pricing and station inventory.</span>
            </div>
            
            <div style="max-height: 400px; overflow-y: auto; margin-bottom: 20px;">
                <table style="width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0;">
                    <thead style="position: sticky; top: 0; background: #f8fafc; z-index: 1;">
                        <tr style="border-bottom: 2px solid #e2e8f0;">
                            <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #475569;">Item Name</th>
                            <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #475569;">Qty</th>
                            <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #475569;">Cost (per unit)</th>
                            <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #475569;">Sell Price</th>
                            <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #475569;">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_items as $item): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px 16px;">
                                    <input type="hidden" name="item_ids[]" value="<?php echo $item['id']; ?>">
                                    <div style="font-size: 13px; font-weight: 500; color: #0f172a;"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                    <div style="font-size: 11px; color: #94a3b8;">Product ID: <?php echo $item['product_id'] ?? 'N/A'; ?></div>
                                </td>
                                <td style="padding: 12px 16px; text-align: right; font-size: 13px; font-weight: 600; color: var(--petron-blue);">
                                    <?php echo number_format($item['quantity'], 0); ?>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <div style="position: relative;">
                                        <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 13px;">₱</span>
                                        <input type="number" 
                                               name="cost_price[<?php echo $item['id']; ?>]" 
                                               id="cost_<?php echo $item['id']; ?>"
                                               value="<?php echo number_format($item['product_cost'], 2, '.', ''); ?>" 
                                               min="0" 
                                               step="0.01" 
                                               onchange="calcMargin(<?php echo $item['id']; ?>)"
                                               onkeyup="calcMargin(<?php echo $item['id']; ?>)"
                                               style="width: 100%; padding: 8px 10px 8px 24px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; text-align: right;">
                                    </div>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <div style="position: relative;">
                                        <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 13px;">₱</span>
                                        <input type="number" 
                                               name="selling_price[<?php echo $item['id']; ?>]" 
                                               id="price_<?php echo $item['id']; ?>"
                                               value="<?php echo number_format($item['product_price'], 2, '.', ''); ?>" 
                                               min="0" 
                                               step="0.01"
                                               onchange="calcMargin(<?php echo $item['id']; ?>)"
                                               onkeyup="calcMargin(<?php echo $item['id']; ?>)"
                                               style="width: 100%; padding: 8px 10px 8px 24px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; text-align: right;">
                                    </div>
                                </td>
                                <td style="padding: 12px 16px; text-align: right;">
                                    <span id="margin_<?php echo $item['id']; ?>" style="font-size: 13px; font-weight: 600; padding: 4px 8px; border-radius: 4px; background: #f1f5f9; color: #64748b;">-</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="font-size: 13px; font-weight: 600; color: #475569; display: block; margin-bottom: 8px;">Confirmation Notes (optional)</label>
                <textarea name="notes" rows="2" placeholder="Any additional notes about this confirmation..." style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: none;"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <button type="button" onclick="closeCostPriceModal()" style="background: #f3f4f6; color: #64748b; border: none; padding: 14px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px;">
                    Cancel
                </button>
                <button type="submit" style="background: #22c55e; color: white; border: none; padding: 14px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <i class="fas fa-check-circle"></i> Set & Confirm Stock
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function showReturnModal(batchId, batchNumber) {
    document.getElementById('returnBatchId').value = batchId;
    document.getElementById('returnModal').style.display = 'flex';
}

function closeReturnModal() {
    document.getElementById('returnModal').style.display = 'none';
    document.getElementById('returnReason').value = '';
}

function showCostPriceModal() {
    var modal = document.getElementById('costPriceModal');
    if (modal) {
        modal.style.display = 'flex';
        // Calculate margins on open
        <?php if ($batch_details && !empty($pending_items)): ?>
        <?php foreach ($pending_items as $item): ?>
        calcMargin(<?php echo $item['id']; ?>);
        <?php endforeach; ?>
        <?php endif; ?>
    }
}

function closeCostPriceModal() {
    var modal = document.getElementById('costPriceModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function calcMargin(itemId) {
    var costInput = document.getElementById('cost_' + itemId);
    var priceInput = document.getElementById('price_' + itemId);
    var marginSpan = document.getElementById('margin_' + itemId);
    
    if (!costInput || !priceInput || !marginSpan) return;
    
    var cost = parseFloat(costInput.value) || 0;
    var price = parseFloat(priceInput.value) || 0;
    
    if (cost > 0 && price > 0) {
        var margin = ((price - cost) / cost) * 100;
        marginSpan.textContent = margin.toFixed(1) + '%';
        
        if (margin >= 20) {
            marginSpan.style.background = '#dcfce7';
            marginSpan.style.color = '#15803d';
        } else if (margin >= 10) {
            marginSpan.style.background = '#fef3c7';
            marginSpan.style.color = '#92400e';
        } else if (margin > 0) {
            marginSpan.style.background = '#fee2e2';
            marginSpan.style.color = '#dc2626';
        } else {
            marginSpan.style.background = '#fee2e2';
            marginSpan.style.color = '#dc2626';
            marginSpan.textContent = 'LOSS';
        }
    } else {
        marginSpan.textContent = '-';
        marginSpan.style.background = '#f1f5f9';
        marginSpan.style.color = '#64748b';
    }
}

// Close modals on background click
document.getElementById('returnModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeReturnModal();
    }
});

document.getElementById('costPriceModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeCostPriceModal();
    }
});

// Calculate margins on page load (if modal items exist)
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($batch_details && !empty($pending_items)): ?>
    <?php foreach ($pending_items as $item): ?>
    calcMargin(<?php echo $item['id']; ?>);
    <?php endforeach; ?>
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
