<?php
$page_id = 'supplier_delivery_tracking';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Manager only (not Admin - Admin is read-only for hierarchy compliance)
if (!in_array($role, ['manager', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// Handle delivery status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_delivery') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $delivery_date = $_POST['delivery_date'] ?? date('Y-m-d');
        $supplier_notes = trim($_POST['supplier_notes'] ?? '');
        $status = $_POST['delivery_status'] ?? 'in_transit';
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=?");
            $stmt->execute([$po_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                $msg = "❌ Purchase Order not found.";
            } else {
                $update_stmt = $pdo->prepare("UPDATE purchase_orders SET status=?, delivery_date=?, supplier_notes=?, updated_at=NOW() WHERE id=?");
                $update_stmt->execute([$status, $delivery_date, $supplier_notes, $po_id]);
                
                log_activity($pdo, $me['id'], 'Update PO Delivery Status', "PO #{$po['po_number']} | Status: $status | Delivery Date: $delivery_date | Notes: $supplier_notes");
                $msg = "✅ Delivery status updated! Ready for receiving confirmation.";
            }
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    }
}

// Fetch all purchase orders
$pos = [];
try {
    if ($role === 'superadmin') {
        $stmt = $pdo->query("
            SELECT po.*, sr.product_name, sr.qty, s.name as station_name, u.name as created_by_name
            FROM purchase_orders po
            LEFT JOIN stock_requests sr ON po.request_id = sr.id
            LEFT JOIN stations s ON po.station_id = s.id
            LEFT JOIN users u ON po.created_by = u.id
            ORDER BY po.created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT po.*, sr.product_name, sr.qty, s.name as station_name, u.name as created_by_name
            FROM purchase_orders po
            LEFT JOIN stock_requests sr ON po.request_id = sr.id
            LEFT JOIN stations s ON po.station_id = s.id
            LEFT JOIN users u ON po.created_by = u.id
            WHERE po.station_id = ?
            ORDER BY po.created_at DESC
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
  
  .st-wrapper { max-width: 1200px; margin: 0 auto; padding: 24px; }
  
  .st-header {
    background: linear-gradient(135deg, #003d7a 0%, #002d5c 100%);
    color: white; padding: 40px 32px; border-radius: 16px; margin-bottom: 32px; 
    box-shadow: 0 8px 24px rgba(0,61,122,0.3); 
  }
  .st-header-content { display: flex; align-items: center; gap: 16px; }
  .st-header-icon { font-size: 42px; }
  .st-header h1 { font-size: 32px; font-weight: 700; margin-bottom: 6px; color: white !important; }
  .st-header p { font-size: 14px; opacity: 0.85; color: white !important; }
  
  .st-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
  .st-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .st-alert-error { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
  
  .st-table-wrap { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
  .st-table { width: 100%; border-collapse: collapse; }
  .st-table thead { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
  .st-table th { padding: 16px; text-align: left; font-size: 13px; font-weight: 600; color: #334155; }
  .st-table td { padding: 14px 16px; border-bottom: 1px solid #e2e8f0; }
  .st-table tbody tr:hover { background: #f8fafc; }
  
  .st-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
  .st-badge-pending { background: #fef3c7; color: #92400e; }
  .st-badge-in_transit { background: #bfdbfe; color: #1e3a8a; }
  .st-badge-delivered { background: #d1fae5; color: #065f46; }
  .st-badge-received { background: #c6f6d5; color: #22543d; }
  
  .st-btn { 
    padding: 8px 16px; border: 0; border-radius: 6px; 
    cursor: pointer; font-weight: 600; font-size: 13px; 
    background: #003d7a; color: white;
  }
  .st-btn:hover { background: #002d5c; }
  
  .st-empty { text-align: center; padding: 60px 20px; color: #94a3b8; }
  
  .st-modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
  .st-modal.active { display: flex; }
  .st-modal-content { background: white; border-radius: 12px; padding: 28px; max-width: 500px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
  .st-modal-title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 20px; }
  .st-modal-form { display: grid; gap: 16px; }
  .st-modal-form label { font-size: 13px; font-weight: 600; color: #334155; display: block; margin-bottom: 6px; }
  .st-modal-form input, .st-modal-form select, .st-modal-form textarea { 
    width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; 
    border-radius: 8px; font-size: 13px; font-family: inherit;
  }
  .st-modal-form textarea { resize: none; height: 80px; }
  .st-modal-form input:focus, .st-modal-form select:focus, .st-modal-form textarea:focus { 
    outline: none; border-color: #003d7a; box-shadow: 0 0 0 3px rgba(0,61,122,0.1);
  }
  .st-modal-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 20px; }
  .st-modal-btn { padding: 10px 16px; border: 0; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; }
  .st-modal-btn-submit { background: #003d7a; color: white; }
  .st-modal-btn-cancel { background: #f3f4f6; color: #374151; }
</style>

<div class="st-wrapper">
  <div class="st-header">
    <div class="st-header-content">
      <div class="st-header-icon">🚚</div>
      <div>
        <h1>Supplier Delivery Tracking</h1>
        <p>Admin - Track Purchase Order delivery status from suppliers</p>
      </div>
    </div>
  </div>
  
  <?php if($msg): ?>
    <div class="st-alert <?php echo strpos($msg, '✅') !== false ? 'st-alert-success' : 'st-alert-error'; ?>">
      <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
      <?php echo htmlspecialchars($msg); ?>
    </div>
  <?php endif; ?>
  
  <?php if(empty($pos)): ?>
    <div class="st-empty">
      <div style="font-size: 48px; margin-bottom: 12px;">📦</div>
      <div style="font-size: 16px; font-weight: 500;">No Purchase Orders</div>
      <div style="font-size: 13px; margin-top: 6px; opacity: 0.7;">All POs have been received or there are no active orders.</div>
    </div>
  <?php else: ?>
    <div class="st-table-wrap">
      <table class="st-table">
        <thead>
          <tr>
            <th>PO #</th>
            <th>Product</th>
            <th>Qty</th>
            <th>Status</th>
            <th>Created</th>
            <th>Expected Delivery</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($pos as $po): ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
              <td><?php echo htmlspecialchars($po['product_name']); ?></td>
              <td><?php echo number_format($po['qty'], 0); ?> units</td>
              <td>
                <span class="st-badge st-badge-<?php echo $po['status']; ?>">
                  <?php 
                    $status_labels = [
                      'pending_supplier' => 'Pending Supplier',
                      'in_transit' => 'In Transit',
                      'delivered' => 'Delivered',
                      'received' => 'Received'
                    ];
                    echo $status_labels[$po['status']] ?? ucfirst($po['status']);
                  ?>
                </span>
              </td>
              <td><?php echo date('M d, Y', strtotime($po['created_at'])); ?></td>
              <td><?php echo $po['delivery_date'] ? date('M d, Y', strtotime($po['delivery_date'])) : 'Not set'; ?></td>
              <td>
                <?php if (!in_array($po['status'], ['received'])): ?>
                  <button type="button" class="st-btn" onclick="openUpdateModal(<?php echo $po['id']; ?>, '<?php echo htmlspecialchars($po['po_number']); ?>')">
                    Update
                  </button>
                <?php else: ?>
                  <span style="color: #94a3b8; font-size: 12px;">Completed</span>
                <?php endif; ?>
                <a href="print_po.php?id=<?php echo $po['id']; ?>" target="_blank" class="st-btn" style="background: #059669; text-decoration: none; margin-left: 5px;">Print</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  
  <div style="margin-top: 40px; padding: 20px; background: #e8f1f8; border-left: 4px solid #003d7a; border-radius: 8px;">
    <strong style="color: #b45309;">📌 Delivery Tracking Flow:</strong>
    <ul style="margin-top: 8px; margin-left: 20px; color: #b45309; font-size: 13px; line-height: 1.8;">
      <li><strong>Pending Supplier</strong> - PO created, awaiting supplier confirmation</li>
      <li><strong>In Transit</strong> - Supplier confirmed delivery, items on the way</li>
      <li><strong>Delivered</strong> - Supplier delivered, awaiting staff receiving confirmation</li>
      <li><strong>Received</strong> - Staff confirmed receipt and updated inventory</li>
    </ul>
  </div>
</div>

<!-- Update Delivery Modal -->
<div class="st-modal" id="updateModal">
  <div class="st-modal-content">
    <div class="st-modal-title">Update Delivery Status</div>
    <form method="post" class="st-modal-form">
      <input type="hidden" name="action" value="update_delivery">
      <input type="hidden" name="po_id" id="modalPoId" value="">
      
      <div>
        <label>PO Number</label>
        <input type="text" id="modalPoNumber" readonly style="background: #f3f4f6;">
      </div>
      
      <div>
        <label>Delivery Status *</label>
        <select name="delivery_status" required>
          <option value="">Select Status</option>
          <option value="in_transit">In Transit</option>
          <option value="delivered">Delivered</option>
        </select>
      </div>
      
      <div>
        <label>Expected Delivery Date *</label>
        <input type="date" name="delivery_date" required>
      </div>
      
      <div>
        <label>Supplier Notes / Comments</label>
        <textarea name="supplier_notes" placeholder="e.g., Delivery scheduled for Feb 12, 2026"></textarea>
      </div>
      
      <div class="st-modal-actions">
        <button type="button" class="st-modal-btn st-modal-btn-cancel" onclick="closeUpdateModal()">Cancel</button>
        <button type="submit" class="st-modal-btn st-modal-btn-submit">Update Status</button>
      </div>
    </form>
  </div>
</div>

<script>
function openUpdateModal(poId, poNumber) {
  document.getElementById('modalPoId').value = poId;
  document.getElementById('modalPoNumber').value = poNumber;
  document.getElementById('updateModal').classList.add('active');
}

function closeUpdateModal() {
  document.getElementById('updateModal').classList.remove('active');
}

document.getElementById('updateModal').addEventListener('click', (e) => {
  if (e.target === document.getElementById('updateModal')) closeUpdateModal();
});

// Set today's date as default
document.querySelector('input[name="delivery_date"]').valueAsDate = new Date();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
