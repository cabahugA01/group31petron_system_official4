<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'receiving_batches';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? ''));

// Staff, Manager, Admin, and Superadmin can access receiving workflow
if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$edit_batch_id = $_GET['edit'] ?? null;
$edit_items = [];

// Fetch Petron Corporation as exclusive supplier
$suppliers = [];
try {
    $suppliers = $pdo->query("SELECT * FROM suppliers WHERE name = 'Petron Corporation' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $suppliers = [];
}

// Set Petron Corporation as default supplier
$default_supplier = 'Petron Corporation';
$default_supplier_id = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE name = 'Petron Corporation' LIMIT 1");
    $stmt->execute();
    $default_supplier_id = $stmt->fetchColumn();
} catch (Exception $e) {
    // Table might not exist yet
}

// Fetch products for autocomplete from inventory_products
$products = [];
try {
    $products = $pdo->query("SELECT DISTINCT product_name as name, category FROM inventory_products ORDER BY category, product_name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
}

// Load edit data
if ($edit_batch_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT rb.*, u.name as received_by_name
            FROM receiving_batches rb
            LEFT JOIN users u ON rb.received_by = u.id
            WHERE rb.id = ? AND rb.status = 'pending' AND rb.station_id = ?
        ");
        $stmt->execute([$edit_batch_id, $station_id]);
        $edit_batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($edit_batch) {
            // Fetch items for this batch
            $stmt_items = $pdo->prepare("SELECT * FROM received_items WHERE batch_id = ?");
            $stmt_items->execute([$edit_batch_id]);
            $edit_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $edit_batch = null;
    }
}

// Handle form submission
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'submit_batch') {
        $batch_id = $_POST['batch_id'] ?? '';
        $supplier = 'Petron Corporation'; // Always Petron Corporation
        $delivery_date = $_POST['delivery_date'] ?? date('Y-m-d');
        $notes = $_POST['notes'] ?? '';
        $items = $_POST['items'] ?? [];
        
        if (empty($items) || empty(array_filter($items, function($item) { return !empty($item['name']) && !empty($item['quantity']); }))) {
            $msg = '❌ At least one item is required.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Generate batch number: REC-{station_id}-{date}-{sequence}
                $date_str = str_replace('-', '', $delivery_date);
                $stmt_seq = $pdo->prepare("SELECT COUNT(*) + 1 as seq FROM receiving_batches WHERE station_id = ? AND DATE(created_at) = CURDATE()");
                $stmt_seq->execute([$station_id]);
                $sequence = str_pad($stmt_seq->fetchColumn(), 3, '0', STR_PAD_LEFT);
                $batch_number = "REC-{$station_id}-{$date_str}-{$sequence}";
                
                if ($batch_id) {
                    // Update existing batch
                    $stmt_update_batch = $pdo->prepare("
                        UPDATE receiving_batches 
                        SET supplier = ?, delivery_date = ?, notes = ?, updated_at = NOW()
                        WHERE id = ? AND status = 'pending'
                    ");
                    $stmt_update_batch->execute([$supplier, $delivery_date, $notes, $batch_id]);
                } else {
                    // Create new batch
                    $stmt_batch = $pdo->prepare("
                        INSERT INTO receiving_batches (batch_number, station_id, supplier, delivery_date, notes, received_by, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt_batch->execute([$batch_number, $station_id, $supplier, $delivery_date, $notes, $me['id']]);
                    $batch_id = $pdo->lastInsertId();
                }
                
                // Delete existing items for this batch (if editing)
                if ($batch_id) {
                    $stmt_delete = $pdo->prepare("DELETE FROM received_items WHERE batch_id = ?");
                    $stmt_delete->execute([$batch_id]);
                }
                
                // Insert items
                $stmt_item = $pdo->prepare("
                    INSERT INTO received_items (batch_id, station_id, product_id, item_name, quantity, supplier, delivery_date, received_by, notes, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                
                foreach ($items as $item) {
                    $item_name = trim($item['name'] ?? '');
                    $quantity = (float)($item['quantity'] ?? 0);
                    
                    if ($item_name && $quantity > 0) {
                        // Check if product exists in inventory_products
                        $stmt_product = $pdo->prepare("SELECT product_name, category FROM inventory_products WHERE product_name = ? LIMIT 1");
                        $stmt_product->execute([$item_name]);
                        $product = $stmt_product->fetch(PDO::FETCH_ASSOC);
                        
                        $product_id = crc32($item_name); // Generate consistent ID
                        $unit = 'pieces';
                        
                        if (!$product) {
                            // Add to inventory_products if not exists
                            $stmt_add = $pdo->prepare("INSERT IGNORE INTO inventory_products (product_name, category, unit_cost, size) VALUES (?, 'Others', 0.00, '')");
                            $stmt_add->execute([$item_name]);
                        }
                        
                        $stmt_item->execute([
                            $batch_id,
                            $station_id,
                            $product_id,
                            $item_name,
                            $quantity,
                            $supplier,
                            $delivery_date,
                            $me['id'],
                            $notes
                        ]);
                    }
                }
                
                $pdo->commit();
                
                if ($edit_batch_id) {
                    $msg = "✅ Batch $batch_number updated successfully!";
                    $edit_batch_id = null;
                    $edit_items = [];
                } else {
                    $msg = "✅ Batch $batch_number created and submitted for review!";
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../partials/header.php';
?>

<div style="max-width: 1400px; margin: 0 auto; padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h1 style="font-size: 32px; font-weight: 700; color: var(--petron-blue); margin: 0;">
                <i class="fas fa-edit"></i> Step 1: Receive Inventory (Encoding)
            </h1>
            <p style="color: var(--muted); margin-top: 4px; font-size: 14px;">
                <strong>Admin:</strong> Encode delivery details (supplier, delivery receipt, quantity, batch/lot numbers) for review
            </p>
            <div style="margin-top: 8px; padding: 8px 12px; background: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px; font-size: 12px; color: #0369a1;">
                <i class="fas fa-info-circle"></i> 
                <strong>Workflow:</strong> Step 1 (Encoding) → Step 2 (Review) → Step 3 (Confirmation)
            </div>
        </div>
    </div>
    
    <?php if ($msg): ?>
        <div style="padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; background: #e6f4ea; color: #065f46; border: 1px solid #a7f3d0; display: flex; align-items: center; gap: 10px;">
            <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>
    
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
        <!-- Main Form -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <form method="post" id="receivingBatchForm">
                <input type="hidden" name="action" value="submit_batch">
                <input type="hidden" name="batch_id" id="batch_id" value="<?php echo $edit_batch_id ?? ''; ?>">
                
                <!-- Batch Info -->
                <div style="margin-bottom: 24px; padding-bottom: 24px; border-bottom: 2px solid #f1f5f9;">
                    <h3 style="font-size: 18px; font-weight: 600; color: #0f172a; margin: 0 0 16px;">Batch Information</h3>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <div>
                            <label style="font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">Supplier</label>
                            <select name="supplier" id="supplier" style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                                <?php foreach ($suppliers as $supp): ?>
                                    <option value="<?php echo htmlspecialchars($supp['name']); ?>" selected>
                                        <?php echo htmlspecialchars($supp['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color: #666; font-size: 12px;">All supplies are sourced from Petron Corporation</small>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">Delivery Date *</label>
                            <input type="date" name="delivery_date" id="delivery_date" value="<?php echo $edit_batch['delivery_date'] ?? date('Y-m-d'); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                        </div>
                    </div>
                    
                    <div>
                        <label style="font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 6px;">Delivery Notes</label>
                        <textarea name="notes" id="notes" rows="3" placeholder="Any additional notes about this delivery..." style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: none;"><?php echo htmlspecialchars($edit_batch['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <!-- Items Section -->
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3 style="font-size: 18px; font-weight: 600; color: #0f172a; margin: 0;">Items</h3>
                        <button type="button" onclick="addItemRow()" style="background: var(--petron-blue); color: white; border: none; padding: 10px 16px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </div>
                    
                    <div id="itemsContainer" style="display: flex; flex-direction: column; gap: 12px;">
                        <?php if (!empty($edit_items)): ?>
                            <?php foreach ($edit_items as $index => $item): ?>
                                <div class="item-row" data-index="<?php echo $index; ?>" style="display: grid; grid-template-columns: 3fr 1fr 100px 40px; gap: 12px; padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                                    <div>
                                        <label style="font-size: 11px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Item Name *</label>
                                        <input type="text" name="items[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($item['item_name']); ?>" list="productList" placeholder="Search or enter item name" style="width: 100%; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px;">
                                    </div>
                                    <div>
                                        <label style="font-size: 11px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Quantity *</label>
                                        <input type="number" name="items[<?php echo $index; ?>][quantity]" value="<?php echo number_format($item['quantity'], 0); ?>" min="0.01" step="0.01" style="width: 100%; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px;">
                                    </div>
                                    <div style="display: flex; align-items: center; padding-top: 20px;">
                                        <input type="hidden" name="items[<?php echo $index; ?>][unit]" value="pieces">
                                        <span style="font-size: 12px; color: #64748b; font-weight: 500;">pcs</span>
                                    </div>
                                    <div style="display: flex; align-items: flex-end; padding-bottom: 4px;">
                                        <button type="button" onclick="removeItemRow(<?php echo $index; ?>)" style="background: #fee2e2; color: #dc2626; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 12px;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Empty item row template -->
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Actions -->
                <div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid #f1f5f9; display: flex; gap: 12px;">
                    <button type="submit" style="flex: 1; background: var(--petron-blue); color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="fas fa-paper-plane"></i> Submit Batch for Review
                    </button>
                    <?php if ($edit_batch_id): ?>
                        <button type="button" onclick="cancelEdit()" style="background: #f3f4f6; color: #64748b; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px;">
                            Cancel Edit
                        </button>
                    <?php endif; ?>
                </div>
            </form>
            
            <datalist id="productList">
                <?php foreach ($products as $product): ?>
                    <option value="<?php echo htmlspecialchars($product['name']); ?>">
                        <?php echo htmlspecialchars($product['name']); ?>
                    </option>
                <?php endforeach; ?>
            </datalist>
        </div>
        
        <!-- Tips -->
        <div style="background: #f8fafc; border-radius: 12px; padding: 24px; border: 1px dashed #cbd5e1; height: fit-content;">
            <h4 style="font-size: 16px; font-weight: 600; color: #0f172a; margin: 0 0 16px;">Quick Tips</h4>
            
            <div style="margin-bottom: 16px;">
                <div style="font-size: 14px; font-weight: 600; color: #0f172a; margin-bottom: 6px;">Before You Submit:</div>
                <ul style="margin: 0; padding-left: 20px; color: #64748b; font-size: 13px; line-height: 1.8;">
                    <li>Use the product search to find existing items</li>
                    <li>Double-check quantities and item names</li>
                    <li>Select correct supplier from dropdown</li>
                    <li>Add notes for any special delivery conditions</li>
                </ul>
            </div>
            
            <div style="margin-bottom: 16px;">
                <div style="font-size: 14px; font-weight: 600; color: #0f172a; margin-bottom: 6px;">After Submission:</div>
                <ul style="margin: 0; padding-left: 20px; color: #64748b; font-size: 13px; line-height: 1.8;">
                    <li>Batch goes to Manager/Admin for review</li>
                    <li>You can edit pending batches if needed</li>
                    <li>Manager must receive before stock is added</li>
                    <li>Check "My Pending Batches" for status</li>
                </ul>
            </div>
            
            <div>
                <div style="font-size: 14px; font-weight: 600; color: #0f172a; margin-bottom: 6px;">Workflow:</div>
                <div style="font-size: 12px; color: #64748b; line-height: 1.6;">
                    <div style="display: flex; gap: 8px; margin-bottom: 4px;">
                        <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px; font-weight: 600;">1. Encode</span>
                        <span>Staff creates batch</span>
                    </div>
                    <div style="display: flex; gap: 8px; margin-bottom: 4px;">
                        <span style="background: #bfdbfe; color: #1e3a8a; padding: 4px 8px; border-radius: 4px; font-weight: 600;">2. Receive</span>
                        <span>Manager approves batch</span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-weight: 600;">3. Confirm</span>
                        <span>Manager adds to inventory</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let itemCount = <?php echo count($edit_items); ?>;

function addItemRow() {
    const container = document.getElementById('itemsContainer');
    const index = itemCount;
    
    const row = document.createElement('div');
    row.className = 'item-row';
    row.dataset.index = index;
    row.style.cssText = 'display: grid; grid-template-columns: 3fr 1fr 100px 40px; gap: 12px; padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;';
    row.innerHTML = `
        <div>
            <label style="font-size: 11px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Item Name *</label>
            <input type="text" name="items[${index}][name]" list="productList" placeholder="Search or enter item name" style="width: 100%; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px;" required>
        </div>
        <div>
            <label style="font-size: 11px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Quantity *</label>
            <input type="number" name="items[${index}][quantity]" min="0.01" step="0.01" placeholder="0.00" style="width: 100%; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px;" required>
        </div>
        <div style="display: flex; align-items: center; padding-top: 20px;">
            <input type="hidden" name="items[${index}][unit]" value="pieces">
            <span style="font-size: 12px; color: #64748b; font-weight: 500;">pcs</span>
        </div>
        <div style="display: flex; align-items: flex-end; padding-bottom: 4px;">
            <button type="button" onclick="removeItemRow(${index})" style="background: #fee2e2; color: #dc2626; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 12px;">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(row);
    itemCount++;
}

function removeItemRow(index) {
    const row = document.querySelector(`.item-row[data-index="${index}"]`);
    if (row) {
        row.remove();
    }
}

function cancelEdit() {
    window.location.href = window.location.pathname;
}

<?php if (empty($edit_items)): ?>
// Add first empty row if not editing
document.addEventListener('DOMContentLoaded', function() {
    addItemRow();
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
