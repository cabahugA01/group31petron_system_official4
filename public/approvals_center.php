<?php
/**
 * APPROVALS CENTER - MANAGER
 * 
 * Centralized approval dashboard for Manager role with separate tabs for each approval type.
 * 
 * Tabs:
 * 1. Dashboard - Overview with counts of pending approvals
 * 2. Receiving - Merchandise receiving batches requiring approval
 * 3. Fuel Readings - Staff-submitted fuel readings requiring verification
 * 4. Job Orders - Pending job orders requiring approval
 * 5. Inventory Adjustments - Stock change requests
 * 6. Delivery Verifications - Petron Corporation deliveries
 * 7. Stock Requests - Staff stock requests
 * 8. Price Approvals - Price change proposals
 * 
 * Security: Manager must enter password before accessing approvals
 * Audit: All approvals are logged to activity_logs table
 */
$page_id = 'approvals_center';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');

if ($role !== 'manager' && $role !== 'superadmin') {
    header('Location: dashboard.php');
    exit;
}

$verified = false;
$error = '';
$msg = '';
$active_tab = $_GET['tab'] ?? 'dashboard';
$station_id = user_station_id();

// Check if already verified in this session
if (isset($_SESSION['approvals_verified']) && $_SESSION['approvals_verified'] && (time() - $_SESSION['approvals_verified_time'] < 600)) {
    $verified = true;
    $_SESSION['approvals_verified_time'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_password'])) {
        $password = $_POST['password_hash'] ?? '';

        if ($role === 'manager') {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$me['id']]);
            $hash = $stmt->fetchColumn();

            if (password_verify($password, $hash)) {
                $verified = true;
                $_SESSION['approvals_verified'] = true;
                $_SESSION['approvals_verified_time'] = time();
            } else {
                $error = 'Incorrect password.';
            }
        } else {
            $verified = true;
            $_SESSION['approvals_verified'] = true;
            $_SESSION['approvals_verified_time'] = time();
        }
    }
    
    // Handle batch approval actions (only if verified)
    if ($verified && isset($_POST['action'])) {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'receive_batch') {
            $batch_id = (int)($_POST['batch_id'] ?? 0);
            
            try {
                $pdo->beginTransaction();
                
                // Get batch
                $stmt = $pdo->prepare("SELECT * FROM receiving_batches WHERE id = ? AND status = 'pending'");
                $stmt->execute([$batch_id]);
                $batch = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$batch) {
                    $msg = "❌ Batch not found or already processed.";
                } else {
                    // NOTE: Do NOT change batch status to 'received' yet
                    // Keep it 'pending' until pricing is confirmed
                    // Just record that manager verified it
                    $stmt_update = $pdo->prepare("
                        UPDATE receiving_batches 
                        SET received_by_manager = ?, received_at = NOW(), updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_update->execute([$me['id'], $batch_id]);
                    
                    // Get all items in the batch that are still pending
                    $stmt_items = $pdo->prepare("
                        SELECT * FROM received_items 
                        WHERE batch_id = ? AND status = 'pending'
                    ");
                    $stmt_items->execute([$batch_id]);
                    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Update inventory for each item
                    foreach ($items as $item) {
                        $product_id = $item['product_id'];
                        $qty = $item['quantity'];
                        
                        // Check if inventory record exists
                        $stmt_check = $pdo->prepare("
                            SELECT * FROM station_inventory 
                            WHERE station_id = ? AND product_id = ?
                        ");
                        $stmt_check->execute([$station_id, $product_id]);
                        $inv_record = $stmt_check->fetch(PDO::FETCH_ASSOC);
                        
                        if ($inv_record) {
                            // Item exists - add qty only
                            $qty_before = $inv_record['stock_level'];
                            $qty_after = $qty_before + $qty;
                            
                            $stmt_update_inv = $pdo->prepare("
                                UPDATE station_inventory 
                                SET stock_level = stock_level + ?, last_updated = NOW()
                                WHERE station_id = ? AND product_id = ?
                            ");
                            $stmt_update_inv->execute([$qty, $station_id, $product_id]);
                        } else {
                            // New item - create inventory record
                            $qty_before = 0;
                            $qty_after = $qty;
                            
                            $stmt_insert_inv = $pdo->prepare("
                                INSERT INTO station_inventory 
                                (station_id, product_id, stock_level, reorder_level, capacity, unit, status, last_updated)
                                VALUES (?, ?, ?, 0, 10000, 'pieces', 'active', NOW())
                            ");
                            $stmt_insert_inv->execute([$station_id, $product_id, $qty]);
                        }
                        
                        // Create inventory log entry for audit trail
                        $stmt_log = $pdo->prepare("
                            INSERT INTO inventory_logs 
                            (station_id, product_id, user_id, action, quantity_before, quantity_after, quantity_change, reference_type, notes, created_at)
                            VALUES (?, ?, ?, 'receiving_batch', ?, ?, ?, 'receiving_batch', ?, NOW())
                        ");
                        $stmt_log->execute([
                            $station_id,
                            $product_id,
                            $me['id'],
                            $qty_before,
                            $qty_after,
                            $qty,
                            "Batch {$batch['batch_number']} verified by {$me['name']}"
                        ]);
                        
                        // NOTE: Do NOT change item status yet - wait for pricing confirmation
                    }
                    
                    log_activity($pdo, $me['id'], 'Receiving Batch Verified', "Batch {$batch['batch_number']} verified and inventory updated by {$me['name']}", $_SERVER['REMOTE_ADDR']);
                    
                    $pdo->commit();
                    $msg = "✅ Batch {$batch['batch_number']} verified! Inventory updated. Now proceed to pricing.";
                    header("Location: pricing_received_items.php?batch={$batch_id}");
                    exit;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
        
        elseif ($action === 'reject_batch') {
            $batch_id = (int)($_POST['batch_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            
            if (strlen($reason) < 10) {
                $msg = "❌ Rejection reason must be at least 10 characters.";
            } else {
                try {
                    $pdo->beginTransaction();
                    
                    // Get batch
                    $stmt = $pdo->prepare("SELECT * FROM receiving_batches WHERE id = ? AND status = 'pending'");
                    $stmt->execute([$batch_id]);
                    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$batch) {
                        $msg = "❌ Batch not found or already processed.";
                    } else {
                        // Update batch to rejected
                        $stmt_update = $pdo->prepare("
                            UPDATE receiving_batches 
                            SET status = 'rejected', notes = CONCAT(COALESCE(notes, ''), '\n--- Rejected: ', ?), rejected_at = NOW(), updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt_update->execute([$reason, $batch_id]);
                        
                        // Update all items to rejected
                        $stmt_items = $pdo->prepare("
                            UPDATE received_items 
                            SET status = 'rejected'
                            WHERE batch_id = ?
                        ");
                        $stmt_items->execute([$batch_id]);
                        
                        log_activity($pdo, $me['id'], 'Receiving Batch Rejected', "Batch {$batch['batch_number']} rejected: {$reason}", $_SERVER['REMOTE_ADDR']);
                        
                        $pdo->commit();
                        $msg = "✅ Batch {$batch['batch_number']} rejected.";
                        header("Location: approvals_center.php?tab=receiving");
                        exit;
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $msg = "❌ Error: " . $e->getMessage();
                }
            }
        }
    }
}

$pending_fuel = 0;
$pending_jobs = 0;
$pending_inventory = 0;
$pending_deliveries = 0;
$pending_stock = 0;
$pending_prices = 0;
$pending_receiving = 0;

if ($verified && $station_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM receiving_batches WHERE station_id = ? AND status = 'pending'");
    $stmt->execute([$station_id]);
    $pending_receiving = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_daily_readings WHERE station_id = ? AND status = 'pending'");
    $stmt->execute([$station_id]);
    $pending_fuel = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND status = 'pending'");
    $stmt->execute([$station_id]);
    $pending_jobs = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_adjustments WHERE station_id = ? AND status = 'pending'");
    $stmt->execute([$station_id]);
    $pending_inventory = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries WHERE station_id = ? AND status = 'pending'");
    $stmt->execute([$station_id]);
    $pending_deliveries = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status = 'pending'");
    $stmt->execute([$station_id]);
    $pending_stock = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM price_changes WHERE station_id = ? AND status = 'pending'");
    $stmt->execute([$station_id]);
    $pending_prices = $stmt->fetchColumn();
}

$total_pending = $pending_receiving + $pending_fuel + $pending_jobs + $pending_inventory + $pending_deliveries + $pending_stock + $pending_prices;

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Approvals Center</h1>
        <div class="sub">Centralized dashboard for all approval workflows</div>
    </div>
    <?php if ($total_pending > 0): ?>
        <div style="background: #E30613; color: white; padding: 10px 20px; border-radius: 20px; font-weight: bold;">
            <?php echo $total_pending; ?> Pending Approval<?php echo $total_pending > 1 ? 's' : ''; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($msg)): ?>
<div style="margin: 20px auto; max-width: 1200px; padding: 15px; border-radius: 8px; <?php echo strpos($msg, '✅') !== false ? 'background: #d4edda; color: #155724; border: 1px solid #c3e6cb;' : 'background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;'; ?>">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<?php if (!$verified): ?>
<div class="card" style="max-width: 400px; margin: 40px auto; padding: 30px;">
    <h3 class="h3" style="text-align: center; margin-bottom: 20px;"><i class="fas fa-lock"></i> Security Check</h3>
    <p style="text-align: center; color: #666; margin-bottom: 20px;">
        Please enter your password to access the Approvals Center.
    </p>
    
    <?php if ($error): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; text-align: center;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <form method="post">
        <div style="margin-bottom: 20px;">
            <input type="password_hash" name="password_hash" class="inp" style="width: 100%; padding: 10px;" placeholder="Enter Password" required autofocus>
        </div>
        <button type="submit" name="verify_password" class="btn primary" style="width: 100%;">Verify Identity</button>
    </form>
</div>
<?php else: ?>

<div style="margin-bottom: 20px;">
    <div style="border-bottom: 2px solid #ddd; display: flex; gap: 5px;">
        <a href="approvals_center.php?tab=dashboard" class="btn <?php echo $active_tab === 'dashboard' ? 'primary' : 'secondary'; ?>" style="border-radius: 8px 8px 0 0;">
            <i class="fas fa-chart-pie"></i> Dashboard
        </a>
        <a href="approvals_center.php?tab=receiving" class="btn <?php echo $active_tab === 'receiving' ? 'primary' : 'secondary'; ?>" style="border-radius: 8px 8px 0 0; position: relative;">
            <i class="fas fa-boxes"></i> Receiving
            <?php if ($pending_receiving > 0): ?>
                <span style="background: #E30613; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;"><?php echo $pending_receiving; ?></span>
            <?php endif; ?>
        </a>
        <a href="approvals_center.php?tab=fuel" class="btn <?php echo $active_tab === 'fuel' ? 'primary' : 'secondary'; ?>" style="border-radius: 8px 8px 0 0; position: relative;">
            <i class="fas fa-gas-pump"></i> Fuel
            <?php if ($pending_fuel > 0): ?>
                <span style="background: #E30613; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;"><?php echo $pending_fuel; ?></span>
            <?php endif; ?>
        </a>
        <a href="approvals_center.php?tab=jobs" class="btn <?php echo $active_tab === 'jobs' ? 'primary' : 'secondary'; ?>" style="border-radius: 8px 8px 0 0; position: relative;">
            <i class="fas fa-wrench"></i> Job Orders
            <?php if ($pending_jobs > 0): ?>
                <span style="background: #E30613; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;"><?php echo $pending_jobs; ?></span>
            <?php endif; ?>
        </a>
        <a href="approvals_center.php?tab=inventory" class="btn <?php echo $active_tab === 'inventory' ? 'primary' : 'secondary'; ?>" style="border-radius: 8px 8px 0 0; position: relative;">
            <i class="fas fa-box"></i> Inventory
            <?php if ($pending_inventory > 0): ?>
                <span style="background: #E30613; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;"><?php echo $pending_inventory; ?></span>
            <?php endif; ?>
        </a>
        <a href="approvals_center.php?tab=deliveries" class="btn <?php echo $active_tab === 'deliveries' ? 'primary' : 'secondary'; ?>" style="border-radius: 8px 8px 0 0; position: relative;">
            <i class="fas fa-truck"></i> Deliveries
            <?php if ($pending_deliveries > 0): ?>
                <span style="background: #E30613; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;"><?php echo $pending_deliveries; ?></span>
            <?php endif; ?>
        </a>
        <a href="approvals_center.php?tab=stock" class="btn <?php echo $active_tab === 'stock' ? 'primary' : 'secondary'; ?>" style="border-radius: 8px 8px 0 0; position: relative;">
            <i class="fas fa-clipboard-list"></i> Stock Requests
            <?php if ($pending_stock > 0): ?>
                <span style="background: #E30613; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;"><?php echo $pending_stock; ?></span>
            <?php endif; ?>
        </a>
        <a href="approvals_center.php?tab=prices" class="btn <?php echo $active_tab === 'prices' ? 'primary' : 'secondary'; ?>" style="border-radius: 8px 8px 0 0; position: relative;">
            <i class="fas fa-tag"></i> Prices
            <?php if ($pending_prices > 0): ?>
                <span style="background: #E30613; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;"><?php echo $pending_prices; ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>

<?php if ($active_tab === 'dashboard'): ?>
<div class="card" style="padding: 30px;">
    <h3 class="h3" style="margin-bottom: 30px;">Approval Overview</h3>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center;">
            <div style="font-size: 48px; color: #002F6C; font-weight: bold;"><?php echo $total_pending; ?></div>
            <div style="color: #666; font-size: 14px;">Total Pending</div>
        </div>
        <div style="background: <?php echo $pending_receiving > 0 ? '#fff3cd' : '#f8f9fa'; ?>; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;" onclick="location.href='approvals_center.php?tab=receiving'">
            <div style="font-size: 36px; color: #002F6C; font-weight: bold;"><?php echo $pending_receiving; ?></div>
            <div style="color: #666; font-size: 14px;"><i class="fas fa-boxes"></i> Receiving Batches</div>
        </div>
        <div style="background: <?php echo $pending_fuel > 0 ? '#fff3cd' : '#f8f9fa'; ?>; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;" onclick="location.href='approvals_center.php?tab=fuel'">
            <div style="font-size: 36px; color: #002F6C; font-weight: bold;"><?php echo $pending_fuel; ?></div>
            <div style="color: #666; font-size: 14px;"><i class="fas fa-gas-pump"></i> Fuel Readings</div>
        </div>
        <div style="background: <?php echo $pending_jobs > 0 ? '#fff3cd' : '#f8f9fa'; ?>; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;" onclick="location.href='approvals_center.php?tab=jobs'">
            <div style="font-size: 36px; color: #002F6C; font-weight: bold;"><?php echo $pending_jobs; ?></div>
            <div style="color: #666; font-size: 14px;"><i class="fas fa-wrench"></i> Job Orders</div>
        </div>
        <div style="background: <?php echo $pending_inventory > 0 ? '#fff3cd' : '#f8f9fa'; ?>; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;" onclick="location.href='approvals_center.php?tab=inventory'">
            <div style="font-size: 36px; color: #002F6C; font-weight: bold;"><?php echo $pending_inventory; ?></div>
            <div style="color: #666; font-size: 14px;"><i class="fas fa-box"></i> Inventory Adjustments</div>
        </div>
        <div style="background: <?php echo $pending_deliveries > 0 ? '#fff3cd' : '#f8f9fa'; ?>; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;" onclick="location.href='approvals_center.php?tab=deliveries'">
            <div style="font-size: 36px; color: #002F6C; font-weight: bold;"><?php echo $pending_deliveries; ?></div>
            <div style="color: #666; font-size: 14px;"><i class="fas fa-truck"></i> Delivery Verifications</div>
        </div>
        <div style="background: <?php echo $pending_stock > 0 ? '#fff3cd' : '#f8f9fa'; ?>; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;" onclick="location.href='approvals_center.php?tab=stock'">
            <div style="font-size: 36px; color: #002F6C; font-weight: bold;"><?php echo $pending_stock; ?></div>
            <div style="color: #666; font-size: 14px;"><i class="fas fa-clipboard-list"></i> Stock Requests</div>
        </div>
        <div style="background: <?php echo $pending_prices > 0 ? '#fff3cd' : '#f8f9fa'; ?>; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;" onclick="location.href='approvals_center.php?tab=prices'">
            <div style="font-size: 36px; color: #002F6C; font-weight: bold;"><?php echo $pending_prices; ?></div>
            <div style="color: #666; font-size: 14px;"><i class="fas fa-tag"></i> Price Approvals</div>
        </div>
    </div>

    <?php if ($total_pending === 0): ?>
        <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; text-align: center;">
            <i class="fas fa-check-circle"></i> All caught up! No pending approvals at this time.
        </div>
    <?php endif; ?>
</div>

<?php elseif ($active_tab === 'receiving'): ?>
<div class="card" style="padding: 20px;">
    <h3 class="h3"><i class="fas fa-boxes"></i> Merchandise Receiving Approvals</h3>
    <p class="muted">Review and approve staff-encoded receiving batches</p>
    <hr style="margin: 20px 0;">
    
    <?php if ($pending_receiving > 0): ?>
        <p style="color: #155724; background: #d4edda; padding: 10px; border-radius: 4px;">
            <?php echo $pending_receiving; ?> batch<?php echo $pending_receiving > 1 ? 'es' : ''; ?> awaiting your review
        </p>
        
        <div style="margin-top: 20px;">
        <?php
        $stmt = $pdo->prepare("
            SELECT rb.*, u.name as staff_name, COUNT(ri.id) as item_count
            FROM receiving_batches rb
            LEFT JOIN users u ON rb.received_by = u.id
            LEFT JOIN received_items ri ON rb.id = ri.batch_id
            WHERE rb.station_id = ? AND rb.status = 'pending'
            GROUP BY rb.id
            ORDER BY rb.created_at DESC
        ");
        $stmt->execute([$station_id]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($batches as $batch):
        ?>
            <div style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                    <div>
                        <strong style="font-size: 16px;"><?php echo htmlspecialchars($batch['batch_number']); ?></strong>
                        <br><small class="muted">Encoded by: <?php echo htmlspecialchars($batch['staff_name'] ?? 'Unknown'); ?> on <?php echo date('M d, Y H:i', strtotime($batch['created_at'])); ?></small>
                        <br><small class="muted">Supplier: <?php echo htmlspecialchars($batch['supplier']); ?></small>
                        <br><small class="muted">Delivery Date: <?php echo date('M d, Y', strtotime($batch['delivery_date'])); ?></small>
                    </div>
                    <span style="background: #fff3cd; color: #856404; padding: 5px 10px; border-radius: 4px; font-size: 12px;">
                        <strong><?php echo $batch['item_count']; ?> Item<?php echo $batch['item_count'] != 1 ? 's' : ''; ?></strong>
                    </span>
                </div>
                
                <?php if ($batch['notes']): ?>
                <div style="background: white; padding: 10px; border-radius: 4px; margin-bottom: 10px; font-size: 13px; border-left: 3px solid #002F6C;">
                    <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($batch['notes'])); ?>
                </div>
                <?php endif; ?>
                
                <!-- Items List -->
                <details style="margin-bottom: 10px;">
                    <summary style="cursor: pointer; color: #002F6C; font-weight: bold;">View Items (<?php echo $batch['item_count']; ?>)</summary>
                    <div style="margin-top: 10px; padding: 10px; background: white; border-radius: 4px;">
                        <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f0f0f0; border-bottom: 1px solid #ddd;">
                                    <th style="padding: 8px; text-align: left;">Product</th>
                                    <th style="padding: 8px; text-align: right;">Qty</th>
                                    <th style="padding: 8px; text-align: right;">Unit Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt_items = $pdo->prepare("
                                    SELECT ri.*, p.name as product_name
                                    FROM received_items ri
                                    LEFT JOIN products p ON ri.product_id = p.id
                                    WHERE ri.batch_id = ?
                                    ORDER BY ri.id
                                ");
                                $stmt_items->execute([$batch['id']]);
                                $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($items as $item):
                                ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 8px;"><?php echo htmlspecialchars($item['product_name'] ?? 'Unknown'); ?></td>
                                    <td style="padding: 8px; text-align: right;"><?php echo number_format($item['quantity'], 2); ?></td>
                                    <td style="padding: 8px; text-align: right;"><?php echo number_format($item['unit_cost'] ?? 0, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
                
                <!-- Action Buttons -->
                <div style="display: flex; gap: 10px;">
                    <form method="post" style="flex: 1;">
                        <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                        <input type="hidden" name="action" value="receive_batch">
                        <button type="submit" class="btn primary" style="width: 100%;">
                            <i class="fas fa-check-circle"></i> Receive Batch
                        </button>
                    </form>
                    <button type="button" class="btn secondary" onclick="document.getElementById('reject-form-<?php echo $batch['id']; ?>').style.display = document.getElementById('reject-form-<?php echo $batch['id']; ?>').style.display === 'none' ? 'block' : 'none';" style="flex: 1;">
                        <i class="fas fa-times-circle"></i> Reject
                    </button>
                </div>
                
                <!-- Rejection Form (Hidden) -->
                <form method="post" id="reject-form-<?php echo $batch['id']; ?>" style="display: none; margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 4px;">
                    <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                    <input type="hidden" name="action" value="reject_batch">
                    <textarea name="reason" class="inp" placeholder="Rejection reason (min 10 characters)" required style="width: 100%; padding: 8px; margin-bottom: 10px;"></textarea>
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn danger" style="flex: 1;">Confirm Rejection</button>
                        <button type="button" class="btn secondary" onclick="document.getElementById('reject-form-<?php echo $batch['id']; ?>').style.display = 'none';" style="flex: 1;">Cancel</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="muted">No pending receiving batches</p>
    <?php endif; ?>
</div>

<?php elseif ($active_tab === 'fuel'): ?>
<div class="card" style="padding: 20px;">
    <h3 class="h3"><i class="fas fa-gas-pump"></i> Fuel Reading Approvals</h3>
    <p class="muted">Review and approve staff-submitted fuel readings</p>
    <hr style="margin: 20px 0;">
    
    <?php if ($pending_fuel > 0): ?>
        <p style="color: #155724; background: #d4edda; padding: 10px; border-radius: 4px;">
            <?php echo $pending_fuel; ?> reading<?php echo $pending_fuel > 1 ? 's' : ''; ?> awaiting your review
        </p>
        <p><a href="manager_fuel_transaction_validation.php" class="btn primary"><i class="fas fa-external-link-alt"></i> Go to Fuel Validation</a></p>
    <?php else: ?>
        <p class="muted">No pending fuel readings</p>
    <?php endif; ?>
</div>

<?php elseif ($active_tab === 'jobs'): ?>
<div class="card" style="padding: 20px;">
    <h3 class="h3"><i class="fas fa-wrench"></i> Job Order Approvals</h3>
    <p class="muted">Review and approve pending job orders</p>
    <hr style="margin: 20px 0;">
    
    <?php if ($pending_jobs > 0): ?>
        <p style="color: #155724; background: #d4edda; padding: 10px; border-radius: 4px;">
            <?php echo $pending_jobs; ?> job order<?php echo $pending_jobs > 1 ? 's' : ''; ?> awaiting your review
        </p>
        <p><a href="joborder.php?tab=pending" class="btn primary"><i class="fas fa-external-link-alt"></i> Go to Job Orders</a></p>
    <?php else: ?>
        <p class="muted">No pending job orders</p>
    <?php endif; ?>
</div>

<?php elseif ($active_tab === 'inventory'): ?>
<div class="card" style="padding: 20px;">
    <h3 class="h3"><i class="fas fa-box"></i> Inventory Adjustment Approvals</h3>
    <p class="muted">Review and approve stock adjustment requests</p>
    <hr style="margin: 20px 0;">
    
    <?php if ($pending_inventory > 0): ?>
        <p style="color: #155724; background: #d4edda; padding: 10px; border-radius: 4px;">
            <?php echo $pending_inventory; ?> adjustment<?php echo $pending_inventory > 1 ? 's' : ''; ?> awaiting your review
        </p>
        <p><a href="approvals.php?view=inventory" class="btn primary">Go to Inventory Approvals</a></p>
    <?php else: ?>
        <p class="muted">No pending inventory adjustments</p>
    <?php endif; ?>
</div>

<?php elseif ($active_tab === 'deliveries'): ?>
<div class="card" style="padding: 20px;">
    <h3 class="h3"><i class="fas fa-truck"></i> Delivery Verifications</h3>
    <p class="muted">Verify deliveries from Petron Corporation</p>
    <hr style="margin: 20px 0;">
    
    <?php if ($pending_deliveries > 0): ?>
        <p style="color: #155724; background: #d4edda; padding: 10px; border-radius: 4px;">
            <?php echo $pending_deliveries; ?> deliver<?php echo $pending_deliveries > 1 ? 'ies' : 'y'; ?> awaiting verification
        </p>
        <p><a href="approvals.php?view=deliveries" class="btn primary"><i class="fas fa-external-link-alt"></i> Go to Deliveries</a></p>
    <?php else: ?>
        <p class="muted">No pending deliveries</p>
    <?php endif; ?>
</div>

<?php elseif ($active_tab === 'stock'): ?>
<div class="card" style="padding: 20px;">
    <h3 class="h3">Stock Request Approvals</h3>
    <p class="muted">Review and approve staff stock requests</p>
    <hr style="margin: 20px 0;">
    
    <?php if ($pending_stock > 0): ?>
        <p style="color: #155724; background: #d4edda; padding: 10px; border-radius: 4px;">
            <?php echo $pending_stock; ?> request<?php echo $pending_stock > 1 ? 's' : ''; ?> awaiting your review
        </p>
        <p><a href="manager_review_stock_requests.php" class="btn primary"><i class="fas fa-external-link-alt"></i> Go to Stock Requests</a></p>
    <?php else: ?>
        <p class="muted">No pending stock requests</p>
    <?php endif; ?>
</div>

<?php elseif ($active_tab === 'prices'): ?>
<div class="card" style="padding: 20px;">
    <h3 class="h3"><i class="fas fa-tag"></i> Price Approvals</h3>
    <p class="muted">Verify and approve price changes</p>
    <hr style="margin: 20px 0;">
    
    <?php if ($pending_prices > 0): ?>
        <p style="color: #155724; background: #d4edda; padding: 10px; border-radius: 4px;">
            <?php echo $pending_prices; ?> price change<?php echo $pending_prices > 1 ? 's' : ''; ?> awaiting your review
        </p>
        <p><a href="manager_approve_prices.php" class="btn primary"><i class="fas fa-external-link-alt"></i> Go to Price Approvals</a></p>
    <?php else: ?>
        <p class="muted">No pending price changes</p>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
