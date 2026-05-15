<?php
$page_id = 'supplier_po_confirmation';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');

// Supplier/Admin can confirm - using simple auth for now
// In production, would need supplier portal with proper auth
if (!in_array($role, ['admin', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// Handle PO confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'confirm_po') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $estimated_delivery = $_POST['estimated_delivery'] ?? date('Y-m-d', strtotime('+3 days'));
        $supplier_contact = trim($_POST['supplier_contact'] ?? '');
        $supplier_notes = trim($_POST['supplier_notes'] ?? '');
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=?");
            $stmt->execute([$po_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                $msg = "❌ Purchase Order not found.";
            } elseif ($po['status'] !== 'pending_supplier') {
                $msg = "❌ This PO has already been processed.";
            } else {
                $update_stmt = $pdo->prepare("
                    UPDATE purchase_orders 
                    SET status='confirmed', supplier_name=?, estimated_delivery_date=?, 
                        supplier_contact=?, supplier_notes=?, confirmed_at=NOW(), updated_at=NOW() 
                    WHERE id=?
                ");
                $update_stmt->execute([$supplier_name, $estimated_delivery, $supplier_contact, $supplier_notes, $po_id]);
                
                log_activity($pdo, $me['id'], 'Confirm PO', "PO #{$po['po_number']} | Supplier: $supplier_name | Est. Delivery: $estimated_delivery | Status: Confirmed");
                $msg = "✅ Purchase Order confirmed! Supplier will deliver by " . date('M d, Y', strtotime($estimated_delivery));
            }
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    }
}

// Fetch pending POs awaiting supplier confirmation
$pos = [];
try {
    $stmt = $pdo->query("
        SELECT po.*, sr.product_name, sr.qty, sr.type, s.name as station_name
        FROM purchase_orders po
        LEFT JOIN stock_requests sr ON po.request_id = sr.id
        LEFT JOIN stations s ON po.station_id = s.id
        WHERE po.status = 'pending_supplier'
        ORDER BY po.created_at ASC
    ");
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pos = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f8fafc; }
  
  .sc-wrapper { max-width: 1200px; margin: 0 auto; padding: 24px; }
  
  .sc-header { 
    background: linear-gradient(135deg, #003d7a 0%, #002d5c 100%); 
    color: white; padding: 40px 32px; border-radius: 16px; margin-bottom: 32px; 
    box-shadow: 0 8px 24px rgba(0,61,122,0.3); 
  }
  .sc-header-content { display: flex; align-items: center; gap: 16px; }
  .sc-header-icon { font-size: 42px; }
  .sc-header h1 { font-size: 32px; font-weight: 700; margin-bottom: 6px; color: white !important; }
  .sc-header p { font-size: 14px; opacity: 0.85; color: white !important; }
  
  .sc-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
  .sc-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .sc-alert-error { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
  
  .sc-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 20px; }
  
  .sc-card { 
    background: white; border-radius: 12px; padding: 24px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-top: 4px solid #003d7a; 
  }
  .sc-card-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px; }
  .sc-card-title { font-size: 18px; font-weight: 700; color: #0f172a; }
  .sc-card-badge { 
    background: #e8f1f8; color: #003d7a; padding: 4px 12px; 
    border-radius: 20px; font-size: 12px; font-weight: 600; 
  }
  
  .sc-card-body { display: grid; gap: 12px; }
  .sc-info { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
  .sc-info-label { color: #64748b; font-size: 13px; font-weight: 600; }
  .sc-info-value { color: #0f172a; font-weight: 500; }
  
  .sc-form { display: grid; gap: 16px; margin-top: 16px; padding-top: 16px; border-top: 2px solid #f1f5f9; }
  .sc-form label { font-size: 13px; font-weight: 600; color: #334155; display: block; margin-bottom: 6px; }
  .sc-form input, .sc-form textarea { 
    width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; 
    border-radius: 8px; font-size: 13px; font-family: inherit;
  }
  .sc-form textarea { resize: none; height: 70px; }
  .sc-form input:focus, .sc-form textarea:focus { outline: none; border-color: #003d7a; box-shadow: 0 0 0 3px rgba(0,61,122,0.1); }
  
  .sc-btn-submit { 
    background: #003d7a; color: white; padding: 10px 16px; border: 0; 
    border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; width: 100%;
  }
  .sc-btn-submit:hover { background: #002d5c; }
  
  .sc-empty { text-align: center; padding: 60px 20px; color: #94a3b8; }
</style>

<div class="sc-wrapper">
  <div class="sc-header">
    <div class="sc-header-content">
      <div class="sc-header-icon">📋</div>
      <div>
        <h1>Supplier PO Confirmation</h1>
        <p>Admin - Confirm Purchase Orders with supplier details</p>
      </div>
    </div>
  </div>
  
  <?php if($msg): ?>
    <div class="sc-alert <?php echo strpos($msg, '✅') !== false ? 'sc-alert-success' : 'sc-alert-error'; ?>">
      <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
      <?php echo htmlspecialchars($msg); ?>
    </div>
  <?php endif; ?>
  
  <?php if(empty($pos)): ?>
    <div class="sc-empty">
      <div style="font-size: 48px; margin-bottom: 12px;">✓</div>
      <div style="font-size: 16px; font-weight: 500;">All POs confirmed</div>
      <div style="font-size: 13px; margin-top: 6px; opacity: 0.7;">No pending supplier confirmations at this time.</div>
    </div>
  <?php else: ?>
    <div class="sc-cards">
      <?php foreach($pos as $po): ?>
        <div class="sc-card">
          <div class="sc-card-header">
            <div>
              <div class="sc-card-title"><?php echo htmlspecialchars($po['product_name']); ?></div>
              <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">PO: <?php echo htmlspecialchars($po['po_number']); ?></div>
            </div>
            <div class="sc-card-badge">PENDING</div>
          </div>
          
          <div class="sc-card-body">
            <div class="sc-info">
              <div class="sc-info-label">Type</div>
              <div class="sc-info-value"><?php echo ucfirst($po['type']); ?></div>
            </div>
            <div class="sc-info">
              <div class="sc-info-label">Quantity</div>
              <div class="sc-info-value"><?php echo number_format($po['qty'], 2); ?> units</div>
            </div>
            <div class="sc-info">
              <div class="sc-info-label">Requested For</div>
              <div class="sc-info-value"><?php echo htmlspecialchars($po['station_name'] ?? 'Main Station'); ?></div>
            </div>
            <div class="sc-info">
              <div class="sc-info-label">Created</div>
              <div class="sc-info-value"><?php echo date('M d, Y', strtotime($po['created_at'])); ?></div>
            </div>
            
            <form method="post" class="sc-form">
              <input type="hidden" name="action" value="confirm_po">
              <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
              
              <div>
                <label>Supplier Name *</label>
                <input type="text" name="supplier_name" placeholder="e.g., Petron Fuel Supplier Co." required>
              </div>
              
              <div>
                <label>Estimated Delivery Date *</label>
                <input type="date" name="estimated_delivery" value="<?php echo date('Y-m-d', strtotime('+3 days')); ?>" required>
              </div>
              
              <div>
                <label>Supplier Contact</label>
                <input type="text" name="supplier_contact" placeholder="e.g., +63-2-8888-8888 / contact@supplier.com">
              </div>
              
              <div>
                <label>Delivery Notes</label>
                <textarea name="supplier_notes" placeholder="e.g., Expected delivery time, special instructions"></textarea>
              </div>
              
              <button type="submit" class="sc-btn-submit">
                <i class="fas fa-check"></i> Confirm PO with Supplier
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  
  <div style="margin-top: 40px; padding: 20px; background: #e8f1f8; border-left: 4px solid #003d7a; border-radius: 8px;">
    <strong style="color: #003d7a;">📋 Supplier Confirmation Flow:</strong>
    <ul style="margin-top: 8px; margin-left: 20px; color: #003d7a; font-size: 13px; line-height: 1.8;">
      <li>Admin reviews generated POs</li>
      <li>Enters supplier details and contact information</li>
      <li>Sets estimated delivery date</li>
      <li>Confirms PO → Status changes to "Confirmed"</li>
      <li>Supplier receives confirmation and begins processing</li>
      <li>Next: Track delivery status and receive items</li>
    </ul>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
