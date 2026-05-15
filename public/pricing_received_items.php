<?php
/**
 * PRICING FOR RECEIVED ITEMS
 * 
 * After a manager approves a receiving batch, they are redirected here to set
 * the cost price and selling price for all items in the batch.
 * 
 * This ensures:
 * 1. Items have accurate pricing before POS transactions
 * 2. Latest cost and selling prices are recorded
 * 3. Pricing changes are audited
 * 4. Stock count is updated with the received quantity
 */

$page_id = 'pricing_received_items';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

// Prevent caching of sensitive pricing pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

$me = current_user();
$role = role_key($me['role'] ?? 'staff');

// Only manager and admin can price items
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

$station_id = user_station_id();
$batch_id = $_GET['batch'] ?? null;
$msg = '';
$error = '';

if (!$batch_id) {
    header('Location: approvals_center.php');
    exit;
}

// Get batch details
// Allow access if manager is in the same station OR is a superadmin/admin
if ($station_id) {
    $stmt = $pdo->prepare("SELECT * FROM receiving_batches WHERE id = ? AND station_id = ?");
    $stmt->execute([$batch_id, $station_id]);
} else {
    // Superadmin/admin with no station can access any batch
    $stmt = $pdo->prepare("SELECT * FROM receiving_batches WHERE id = ?");
    $stmt->execute([$batch_id]);
}
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    header('Location: approvals_center.php');
    exit;
}

// Get all items in batch that haven't been priced yet
$stmt = $pdo->prepare("
    SELECT ri.id, ri.batch_id, ri.station_id, ri.product_id, ri.item_name, ri.quantity, ri.supplier, ri.delivery_date, ri.received_by, ri.notes, ri.created_at, ri.status,
           p.name, p.cost, p.price, p.sku
    FROM received_items ri
    LEFT JOIN products p ON ri.product_id = p.id
    WHERE ri.batch_id = ? AND ri.status = 'pending'
    ORDER BY ri.id
");
$stmt->execute([$batch_id]);
$received_items = $stmt->fetchAll(PDO::FETCH_ASSOC);



// Handle pricing submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_pricing') {
        try {
            $pdo->beginTransaction();
            
            // Get pricing data from form
            $pricing_data = $_POST['pricing'] ?? [];
            
            if (empty($pricing_data)) {
                $error = "❌ No pricing data submitted.";
            } else {
                $updated_count = 0;
                $validation_errors = [];
                
                foreach ($pricing_data as $item_id => $pricing) {
                    $cost_price = (float)($pricing['cost'] ?? 0);
                    $selling_price = (float)($pricing['price'] ?? 0);
                    
                    // Get item details
                    $stmt_item = $pdo->prepare("
                        SELECT ri.id, ri.batch_id, ri.station_id, ri.product_id, ri.item_name, ri.quantity, ri.supplier, ri.delivery_date, ri.received_by, ri.notes, ri.created_at, ri.status,
                               p.cost, p.price, p.name
                        FROM received_items ri
                        LEFT JOIN products p ON ri.product_id = p.id
                        WHERE ri.id = ? AND ri.batch_id = ? AND ri.status = 'pending'
                    ");
                    $stmt_item->execute([$item_id, $batch_id]);
                    $item = $stmt_item->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$item) {
                        continue;
                    }
                    
                    $product_id = $item['product_id'];
                    $product_name = $item['name'] ?? 'Unknown Product';
                    
                    // Validate pricing
                    if ($cost_price < 0 || $selling_price < 0) {
                        $validation_errors[] = "Invalid pricing for {$product_name}: prices cannot be negative.";
                        continue;
                    }
                    
                    if ($selling_price < $cost_price) {
                        $validation_errors[] = "Invalid pricing for {$product_name}: selling price (₱{$selling_price}) cannot be less than cost price (₱{$cost_price}).";
                        continue;
                    }
                    
                    if ($cost_price == 0 && $selling_price == 0) {
                        $validation_errors[] = "Missing pricing for {$product_name}: both cost and selling prices are required.";
                        continue;
                    }
                    
                    // Update product pricing
                    $old_cost = $item['cost'] ?? 0;
                    $old_price = $item['price'] ?? 0;
                    
                    $stmt_update = $pdo->prepare("
                        UPDATE products
                        SET cost = ?, price = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_update->execute([$cost_price, $selling_price, $product_id]);
                    
                    // Log price change for audit trail
                    $stmt_log = $pdo->prepare("
                        INSERT INTO price_change_logs
                        (product_id, old_cost, old_price, new_cost, new_price, action, user_id, batch_id, timestamp, notes)
                        VALUES (?, ?, ?, ?, ?, 'batch_pricing', ?, ?, NOW(), ?)
                    ");
                    $stmt_log->execute([
                        $product_id,
                        $old_cost,
                        $old_price,
                        $cost_price,
                        $selling_price,
                        $me['id'],
                        $batch_id,
                        "Priced from batch {$batch['batch_number']}"
                    ]);
                    
                    // Mark item as confirmed
                    $stmt_item_update = $pdo->prepare("
                        UPDATE received_items
                        SET status = 'confirmed'
                        WHERE id = ?
                    ");
                    $stmt_item_update->execute([$item_id]);
                    
                    $updated_count++;
                }
                
                if (!empty($validation_errors)) {
                    $pdo->rollBack();
                    $error = "❌ Pricing validation failed:\n" . implode("\n", $validation_errors);
                } elseif ($updated_count > 0) {
                    // Update batch status to confirmed
                    $stmt_batch = $pdo->prepare("
                        UPDATE receiving_batches
                        SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW(), updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_batch->execute([$me['id'], $batch_id]);
                    
                    log_activity($pdo, $me['id'], 'Pricing Confirmed', "Priced {$updated_count} items from batch {$batch['batch_number']}", $_SERVER['REMOTE_ADDR']);
                    
                    $pdo->commit();
                    $msg = "✅ Pricing saved successfully! {$updated_count} item(s) priced and batch confirmed.";
                    
                    // Redirect after success
                    sleep(1);
                    header("Location: approvals_center.php?tab=receiving");
                    exit;
                } else {
                    $pdo->rollBack();
                    $error = "❌ No items were updated. Please check the pricing data.";
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "❌ Error: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Set Item Pricing</h1>
        <div class="sub">Configure cost and selling prices for batch <?php echo htmlspecialchars($batch['batch_number']); ?></div>
    </div>
</div>

<?php if (!empty($msg)): ?>
<div style="margin: 20px auto; max-width: 1200px; padding: 15px; border-radius: 8px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<?php if (!empty($error)): ?>
<div style="margin: 20px auto; max-width: 1200px; padding: 15px; border-radius: 8px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;">
    <?php echo $error; ?>
</div>
<?php endif; ?>

<div class="card" style="margin: 20px auto; max-width: 1200px;">
    <div style="padding: 20px;">
        <h3 class="h3" style="margin-bottom: 20px;">Batch Details</h3>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div style="background: #f9f9f9; padding: 15px; border-radius: 8px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Batch Number</div>
                <div style="font-size: 18px; font-weight: bold; color: #002F6C;"><?php echo htmlspecialchars($batch['batch_number']); ?></div>
            </div>
            <div style="background: #f9f9f9; padding: 15px; border-radius: 8px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Supplier</div>
                <div style="font-size: 18px; font-weight: bold; color: #002F6C;"><?php echo htmlspecialchars($batch['supplier']); ?></div>
            </div>
            <div style="background: #f9f9f9; padding: 15px; border-radius: 8px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Delivery Date</div>
                <div style="font-size: 18px; font-weight: bold; color: #002F6C;"><?php echo date('M d, Y', strtotime($batch['delivery_date'])); ?></div>
            </div>
            <div style="background: #f9f9f9; padding: 15px; border-radius: 8px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Total Items</div>
                <div style="font-size: 18px; font-weight: bold; color: #002F6C;"><?php echo count($received_items); ?></div>
            </div>
        </div>

        <?php if (count($received_items) > 0): ?>
        <form method="post" style="margin-top: 30px;">
            <input type="hidden" name="action" value="save_pricing">
            
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #002F6C; color: white;">
                            <th style="padding: 12px; text-align: left;">Product Name</th>
                            <th style="padding: 12px; text-align: right;">Qty Received</th>
                            <th style="padding: 12px; text-align: right;">Cost Price</th>
                            <th style="padding: 12px; text-align: right;">Selling Price</th>
                            <th style="padding: 12px; text-align: right;">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($received_items as $item): ?>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 12px;">
                                <div style="font-weight: bold;"><?php echo htmlspecialchars($item['name'] ?? 'Unknown'); ?></div>
                                <div style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($item['sku'] ?? ''); ?></div>
                            </td>
                            <td style="padding: 12px; text-align: right;">
                                <strong><?php echo number_format($item['quantity'], 2); ?></strong>
                            </td>
                            <td style="padding: 12px;">
                                <input type="number" 
                                    name="pricing[<?php echo $item['id']; ?>][cost]" 
                                    class="inp" 
                                    step="0.01" 
                                    min="0" 
                                    value="<?php echo $item['cost'] ?? 0; ?>"
                                    style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                    onchange="calculateMargin(this)"
                                    required>
                            </td>
                            <td style="padding: 12px;">
                                <input type="number" 
                                    name="pricing[<?php echo $item['id']; ?>][price]" 
                                    class="inp" 
                                    step="0.01" 
                                    min="0" 
                                    value="<?php echo $item['price'] ?? 0; ?>"
                                    style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                                    onchange="calculateMargin(this)"
                                    required>
                            </td>
                            <td style="padding: 12px; text-align: right;">
                                <div class="margin-display" style="font-weight: bold; color: #002F6C;">
                                    <?php 
                                    $cost = (float)($item['cost'] ?? 0);
                                    $price = (float)($item['price'] ?? 0);
                                    if ($cost > 0) {
                                        $margin = (($price - $cost) / $cost) * 100;
                                        echo number_format($margin, 1) . '%';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="margin-top: 30px; display: flex; gap: 10px;">
                <button type="submit" class="btn primary" style="padding: 12px 30px; font-size: 16px;">
                    <i class="fas fa-save"></i> Save Pricing & Confirm Batch
                </button>
                <a href="approvals_center.php?tab=receiving" class="btn secondary" style="padding: 12px 30px; font-size: 16px;">
                    <i class="fas fa-arrow-left"></i> Cancel
                </a>
            </div>
        </form>
        
        <?php else: ?>
        <div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; text-align: center;">
            <i class="fas fa-info-circle"></i> No items to price in this batch.
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function calculateMargin(input) {
    const row = input.closest('tr');
    const costInput = row.querySelector('input[name*="[cost]"]');
    const priceInput = row.querySelector('input[name*="[price]"]');
    const marginDisplay = row.querySelector('.margin-display');
    
    const cost = parseFloat(costInput.value) || 0;
    const price = parseFloat(priceInput.value) || 0;
    
    if (cost > 0) {
        const margin = ((price - cost) / cost) * 100;
        marginDisplay.textContent = margin.toFixed(1) + '%';
        
        // Change color based on margin
        if (margin < 0) {
            marginDisplay.style.color = '#dc3545'; // Red - loss
        } else if (margin < 10) {
            marginDisplay.style.color = '#ff9800'; // Orange - low margin
        } else {
            marginDisplay.style.color = '#28a745'; // Green - healthy margin
        }
    } else {
        marginDisplay.textContent = '-';
    }
}

// Validate selling price >= cost price
document.addEventListener('submit', function(e) {
    const form = e.target;
    if (form.querySelector('input[name="action"]')?.value === 'save_pricing') {
        const rows = form.querySelectorAll('tbody tr');
        let hasError = false;
        
        rows.forEach(row => {
            const costInput = row.querySelector('input[name*="[cost]"]');
            const priceInput = row.querySelector('input[name*="[price]"]');
            
            const cost = parseFloat(costInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            
            if (price < cost) {
                priceInput.style.borderColor = '#dc3545';
                hasError = true;
            } else {
                priceInput.style.borderColor = '#ddd';
            }
        });
        
        if (hasError) {
            e.preventDefault();
            alert('❌ Selling price cannot be less than cost price!');
        }
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
