<?php
$page_id = 'stock_receiving_confirmation';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Staff or Admin can receive
if (!in_array($role, ['staff', 'admin', 'manager', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// Handle receiving confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'confirm_receipt') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $received_qty = (float)($_POST['received_qty'] ?? 0);
        $receiving_notes = trim($_POST['receiving_notes'] ?? '');
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=?");
            $stmt->execute([$po_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                $msg = "Purchase Order not found.";
            } elseif ($po['status'] === 'received') {
                $msg = "This order has already been received.";
            } elseif ($received_qty <= 0) {
                $msg = "Please enter valid received quantity.";
            } else {
                $pdo->beginTransaction();
                
                // Update PO status to received
                $update_po = $pdo->prepare("UPDATE purchase_orders SET status='received', received_qty=?, received_date=NOW(), received_by=?, receiving_notes=?, updated_at=NOW() WHERE id=?");
                $update_po->execute([$received_qty, (int)$me['id'], $receiving_notes, $po_id]);
                
                // Get product ID from stock request
                $stmt_sr = $pdo->prepare("SELECT id FROM stock_requests WHERE id=?");
                $stmt_sr->execute([$po['request_id']]);
                $stock_req = $stmt_sr->fetch(PDO::FETCH_ASSOC);
                
                // Update stock request status
                $update_sr = $pdo->prepare("UPDATE stock_requests SET status='received', received_qty=?, received_date=NOW() WHERE id=?");
                $update_sr->execute([$received_qty, $po['request_id']]);
                
                // Update inventory - add to station stock
                $stmt_inv = $pdo->prepare("
                    SELECT id FROM products WHERE name = ? LIMIT 1
                ");
                $stmt_inv->execute([$po['product_name']]);
                $product = $stmt_inv->fetch(PDO::FETCH_ASSOC);
                
                if ($product) {
                    $product_id = $product['id'];
                    
                    // Update or create station inventory
                    $stmt_update_inv = $pdo->prepare("UPDATE station_inventory SET stock_level = stock_level + ? WHERE station_id = ? AND product_id = ?");
                    $stmt_update_inv->execute([$received_qty, $po['station_id'], $product_id]);
                    
                    if ($stmt_update_inv->rowCount() === 0) {
                        $stmt_create_inv = $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, unit) VALUES (?, ?, ?, 'pieces')");
                        $stmt_create_inv->execute([$po['station_id'], $product_id, $received_qty]);
                    }
                    
                    // Log inventory transaction
                    $stmt_log = $pdo->prepare("
                        INSERT INTO inventory_logs (station_id, product_id, user_id, action, quantity_change, reference_type, notes, created_at) 
                        VALUES (?, ?, ?, 'stock_in', ?, 'purchase_order', ?, NOW())
                    ");
                    $stmt_log->execute([$po['station_id'], $product_id, (int)$me['id'], $received_qty, "PO #{$po['po_number']} received. " . $receiving_notes]);
                }
                
                log_activity($pdo, $me['id'], 'Receive Stock', "PO #{$po['po_number']} | Product: {$po['product_name']} | Qty Received: $received_qty | Inventory Updated");
                
                $pdo->commit();
                $msg = "Stock received and inventory updated successfully!";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Error: " . $e->getMessage();
        }
    }
}

// Fetch delivered purchase orders ready for receiving
$pos = [];
try {
    if ($role === 'superadmin') {
        $stmt = $pdo->query("
            SELECT po.*, sr.qty, s.name as station_name, u.name as created_by_name
            FROM purchase_orders po
            LEFT JOIN stock_requests sr ON po.request_id = sr.id
            LEFT JOIN stations s ON po.station_id = s.id
            LEFT JOIN users u ON po.created_by = u.id
            WHERE po.status IN ('delivered', 'in_transit')
            ORDER BY po.delivery_date ASC, po.created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT po.*, sr.qty, s.name as station_name, u.name as created_by_name
            FROM purchase_orders po
            LEFT JOIN stock_requests sr ON po.request_id = sr.id
            LEFT JOIN stations s ON po.station_id = s.id
            LEFT JOIN users u ON po.created_by = u.id
            WHERE po.station_id = ? AND po.status IN ('delivered', 'in_transit')
            ORDER BY po.delivery_date ASC, po.created_at DESC
        ");
        $stmt->execute([$station_id]);
    }
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pos = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f8fafc; }
  
  .rc-wrapper { max-width: 1200px; margin: 0 auto; padding: 24px; }
  
  .rc-header { 
    background: linear-gradient(135deg, #003d7a 0%, #002d5c 100%); 
    color: white; padding: 40px 32px; border-radius: 16px; margin-bottom: 32px; 
    box-shadow: 0 8px 24px rgba(0,61,122,0.3); 
  }
  .rc-header-content { display: flex; align-items: center; gap: 16px; }
  .rc-header-icon { font-size: 42px; }
  .rc-header h1 { font-size: 32px; font-weight: 700; margin-bottom: 6px; color: white !important; }
  .rc-header p { font-size: 14px; opacity: 0.85; color: white !important; }
  
  .rc-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
  .rc-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .rc-alert-error { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
  
  .rc-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 20px; }
  
  .rc-card { 
    background: white; border-radius: 12px; padding: 24px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-top: 4px solid #003d7a; 
  }
  .rc-card-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px; }
  .rc-card-title { font-size: 18px; font-weight: 700; color: #0f172a; }
  .rc-card-badge { 
    background: #e8f1f8; color: #003d7a; padding: 4px 12px; 
    border-radius: 20px; font-size: 12px; font-weight: 600; 
  }
  
  .rc-card-body { display: grid; gap: 12px; }
  .rc-info { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
  .rc-info-label { color: #64748b; font-size: 13px; font-weight: 600; }
  .rc-info-value { color: #0f172a; font-weight: 500; }
  
  .rc-form { display: grid; gap: 16px; margin-top: 16px; padding-top: 16px; border-top: 2px solid #f1f5f9; }
  .rc-form label { font-size: 13px; font-weight: 600; color: #334155; display: block; margin-bottom: 6px; }
  .rc-form input, .rc-form textarea { 
    width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; 
    border-radius: 8px; font-size: 13px; font-family: inherit;
  }
  .rc-form textarea { resize: none; height: 70px; }
  .rc-form input:focus, .rc-form textarea:focus { outline: none; border-color: #003d7a; box-shadow: 0 0 0 3px rgba(0,61,122,0.1); }
  
  .rc-btn-submit { 
    background: #003d7a; color: white; padding: 10px 16px; border: 0; 
    border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; width: 100%;
  }
  .rc-btn-submit:hover { background: #002d5c; }
  
  .rc-empty { text-align: center; padding: 60px 20px; color: #94a3b8; }
</style>

<div class="rc-wrapper">
  <div class="rc-header">
    <div class="rc-header-content">
      <div class="rc-header-icon"><i class="fas fa-check"></i></div>
      <div>
        <h1>Receive & Confirm Stock</h1>
        <p>Staff/Admin - Confirm delivery and update inventory</p>
      </div>
    </div>
  </div>
  
  <?php if($msg): ?>
    <div class="rc-alert <?php echo strpos($msg, 'Stock received') !== false ? 'rc-alert-success' : 'rc-alert-error'; ?>">
      <i class="fas <?php echo strpos($msg, 'Stock received') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
      <?php echo htmlspecialchars($msg); ?>
    </div>
  <?php endif; ?>
  
  <?php if(empty($pos)): ?>
    <div class="rc-empty">
      <div style="font-size: 48px; margin-bottom: 12px;"><i class="fas fa-box-open" style="font-size:48px;color:#cbd5e1;"></i></div>
      <div style="font-size: 16px; font-weight: 500;">No pending deliveries</div>
      <div style="font-size: 13px; margin-top: 6px; opacity: 0.7;">All deliveries have been received. Check with Admin if expecting items.</div>
    </div>
  <?php else: ?>
    <div class="rc-cards">
      <?php foreach($pos as $po): ?>
        <div class="rc-card">
          <div class="rc-card-header">
            <div>
              <div class="rc-card-title"><?php echo htmlspecialchars($po['product_name']); ?></div>
              <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">PO: <?php echo htmlspecialchars($po['po_number']); ?></div>
            </div>
            <div class="rc-card-badge">
              <?php echo $po['status'] === 'delivered' ? 'DELIVERED' : 'IN TRANSIT'; ?>
            </div>
          </div>
          
          <div class="rc-card-body">
            <div class="rc-info">
              <div class="rc-info-label">Ordered Qty</div>
              <div class="rc-info-value"><?php echo number_format($po['qty'], 0); ?> units</div>
            </div>
            <div class="rc-info">
              <div class="rc-info-label">Expected Delivery</div>
              <div class="rc-info-value"><?php echo $po['delivery_date'] ? date('M d, Y', strtotime($po['delivery_date'])) : 'Not set'; ?></div>
            </div>
            <?php if($po['supplier_notes']): ?>
            <div style="background: #e8f1f8; border-left: 3px solid #003d7a; padding: 10px; border-radius: 4px; font-size: 12px;">
              <strong style="color: #003d7a;">Supplier Notes:</strong> <?php echo htmlspecialchars($po['supplier_notes']); ?>
            </div>
            <?php endif; ?>
            
            <form method="post" class="rc-form">
              <input type="hidden" name="action" value="confirm_receipt">
              <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
              
              <div>
                <label>Actual Qty Received (units) *</label>
                <input type="number" name="received_qty" placeholder="<?php echo number_format($po['qty'], 0); ?>" step="0.01" min="0" required>
              </div>
              
              <div>
                <label>Receiving Notes</label>
                <textarea name="receiving_notes" placeholder="e.g., Received in good condition, verified count"></textarea>
              </div>
              
              <button type="submit" class="rc-btn-submit">
                <i class="fas fa-check"></i> Confirm Receipt & Update Inventory
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  
  <div style="margin-top: 40px; padding: 20px; background: #e8f1f8; border-left: 4px solid #003d7a; border-radius: 8px;">
    <strong style="color: #003d7a;">✓ Receiving Flow:</strong>
    <ul style="margin-top: 8px; margin-left: 20px; color: #003d7a; font-size: 13px; line-height: 1.8;">
      <li>Wait for Admin to mark PO as "Delivered"</li>
      <li>Enter actual quantity received (may differ from ordered)</li>
      <li>Add notes about condition, discrepancies, etc.</li>
      <li>Submit → Inventory automatically updates</li>
      <li>All transactions logged in audit trail</li>
    </ul>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
