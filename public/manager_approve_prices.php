<?php
$page_id = 'manager_approve_prices';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Manager only
if (!in_array($role, ['manager', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// Handle price approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'approve_price') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $new_cost = (float)($_POST['new_cost'] ?? 0);
        $new_price = (float)($_POST['new_price'] ?? 0);
        
        if ($product_id <= 0 || $new_price < 0 || $new_cost < 0) {
            $msg = "❌ Invalid product or price values.";
        } elseif ($new_price < $new_cost) {
            $msg = "❌ Selling price must be at least equal to cost.";
        } else {
            try {
                // Get old prices
                $stmt = $pdo->prepare("SELECT cost, price FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$prod) {
                    $msg = "❌ Product not found.";
                } else {
                    // APPLY the price changes now that manager approved
                    $stmt = $pdo->prepare("UPDATE products SET cost = ?, price = ? WHERE id = ?");
                    $stmt->execute([$new_cost, $new_price, $product_id]);
                    
                    // Also update station_inventory selling price so POS gets it immediately
                    $stmt = $pdo->prepare("UPDATE station_inventory SET price = ? WHERE product_id = ? AND station_id = ?");
                    $stmt->execute([$new_price, $product_id, $station_id]);
                    
                    log_activity($pdo, $me['id'], 'Approve Price', "APPROVED: Product ID $product_id | Old Cost: {$prod['cost']} → New Cost: $new_cost | Old Price: {$prod['price']} → New Price: $new_price | NOW ACTIVE FOR STAFF");
                    
                    $msg = "✅ Price approved! Now active for staff in POS and transactions.";
                }
            } catch (Exception $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'reject_price') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        
        if ($product_id <= 0 || empty($reason)) {
            $msg = "❌ Please provide a rejection reason.";
        } else {
            try {
                log_activity($pdo, $me['id'], 'Reject Price', "REJECTED: Product ID $product_id. Reason: $reason | Admin must re-propose prices.");
                $msg = "✅ Price rejected. Admin will be notified to re-propose.";
            } catch (Exception $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'hold_price') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $reason = trim($_POST['hold_reason'] ?? '');
        
        if ($product_id <= 0 || empty($reason)) {
            $msg = "❌ Please provide a reason to hold.";
        } else {
            try {
                log_activity($pdo, $me['id'], 'Hold Price', "HELD: Product ID $product_id. Reason: $reason | Proposal is under review.");
                $msg = "✅ Price proposal placed on hold.";
            } catch (Exception $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
}

// Fetch all products with their current prices
$products = [];
try {
    $stmt = $pdo->query("SELECT id, name, sku, cost as current_cost, price as current_price FROM products ORDER BY name ASC");
    $all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each product, extract proposed prices from latest "Propose Price" log
    foreach ($all_products as $p) {
        $prod_id = $p['id'];
        
        // Get latest price proposal for this product
        $log_stmt = $pdo->prepare("
            SELECT created_at, details FROM activity_logs 
            WHERE action = 'Propose Price' AND details LIKE ?
            ORDER BY created_at DESC LIMIT 1
        ");
        $log_stmt->execute(["%Product ID $prod_id %"]);
        $log = $log_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$log) {
            // Also try matching without space, just in case
            $log_stmt->execute(["%Product ID $prod_id%"]);
            $log = $log_stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($log) {
            // Check if there are any newer Approve/Reject/Hold logs for this product
            $status_stmt = $pdo->prepare("
                SELECT action FROM activity_logs 
                WHERE action IN ('Approve Price', 'Reject Price', 'Hold Price') 
                AND details LIKE ? 
                AND created_at > ?
                ORDER BY created_at DESC LIMIT 1
            ");
            $status_stmt->execute(["%Product ID $prod_id%", $log['created_at']]);
            $latest_action = $status_stmt->fetchColumn();
            
            if ($latest_action === 'Approve Price' || $latest_action === 'Reject Price') {
                continue; // Proposal already resolved
            }
            
            $status = ($latest_action === 'Hold Price') ? 'On Hold' : 'Pending';

            // Parse details to extract proposed prices
            // Format: "PROPOSED: Product ID X | Old Cost: Y → New Cost: Z | Old Price: A → New Price: B"
            $proposed_cost = null;
            $proposed_price = null;
            
            if (preg_match('/New Cost: ([\d.]+)/', $log['details'], $matches)) {
                $proposed_cost = (float)$matches[1];
            }
            if (preg_match('/New Price: ([\d.]+)/', $log['details'], $matches)) {
                $proposed_price = (float)$matches[1];
            }
            
            // Only add to list if there's an actual proposal
            if ($proposed_cost !== null && $proposed_price !== null) {
                $p['proposed_cost'] = $proposed_cost;
                $p['proposed_price'] = $proposed_price;
                $p['status'] = $status;
                $products[] = $p;
            }
        }
    }
} catch (Exception $e) {
    $products = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  .pm-table th { background:#f1f3f4; font-weight:600; color:#333; border-bottom:2px solid #dee2e6; white-space:nowrap; }
  .pm-table td { vertical-align:middle; padding: 12px; }
  
  .action-col { display:flex; flex-direction:column; gap:4px; }
  .action-col .btn { width:100%; font-size:12px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:flex; align-items:center; gap:5px; justify-content:center; transition:all .15s; }
  .action-col .btn:hover { filter:brightness(.9); transform:translateY(-1px); }
  .btn-approve { background: #10b981; color: white; }
  .btn-hold { background: #f59e0b; color: white; }
  .btn-reject { background: #ef4444; color: white; }
  
  .badge-pending { background: #e0e7ff; color: #4338ca; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; display: inline-block; }
  .badge-hold { background: #fef3c7; color: #b45309; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; display: inline-block; }
  
  .empty-state { text-align: center; padding: 40px; color: #6c757d; }
  
  /* Modals */
  .modal { display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.5); align-items:center; justify-content:center; }
  .modal.open { display:flex; }
  .modal-content { background:#fff; border-radius:12px; width:90%; max-width:500px; max-height:90vh; overflow-y:auto; box-shadow:0 8px 32px rgba(0,0,0,.25); animation:mIn .18s ease; }
  @keyframes mIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
  .modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 22px; border-bottom:1px solid #e9ecef; }
  .modal-header h3 { margin:0; font-size:17px; font-weight:700; display: flex; align-items: center; gap: 8px; }
  .close { background:none; border:none; font-size:26px; cursor:pointer; color:#aaa; line-height:1; }
  .close:hover { color:#333; }
  .modal-body { padding:22px; }
  .modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:18px 22px; border-top:1px solid #e9ecef; }
  .form-group { margin-bottom:14px; }
  .form-group label { display:block; margin-bottom:5px; font-weight:600; font-size:12px; color:#374151; }
  .form-control { width:100%; padding:9px 11px; border:1px solid #ddd; border-radius:6px; font-size:13px; box-sizing:border-box; font-family:inherit; }
  .form-control:focus { border-color:#002F70; outline:none; box-shadow:0 0 0 3px rgba(0,47,112,.1); }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-check-double" style="color: #059669;"></i> Verify Price Proposals</h1>
        <div class="sub">Manager &mdash; Review and approve/reject price changes from Admin</div>
    </div>
</div>
<?php if($msg): ?>
<div style="background: <?php echo strpos($msg, '✅') !== false ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo strpos($msg, '✅') !== false ? '#155724' : '#721c24'; ?>; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;">
    <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>
  
<div class="card">
  <div class="card-header">
    <h3><i class="fas fa-list" style="color:#002F70;"></i> Price Proposals</h3>
  </div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table pm-table">
      <thead>
        <tr>
          <th>Product</th>
          <th>SKU</th>
          <th>Current Cost</th>
          <th>Proposed Cost</th>
          <th>Current Price</th>
          <th>Proposed Price</th>
          <th>Status</th>
          <th style="text-align: center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($products)): ?>
          <tr><td colspan="8" class="empty-state">No pending price proposals found.</td></tr>
        <?php else: ?>
          <?php foreach($products as $p): ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
              <td><?php echo $p['sku']; ?></td>
              <td>₱<?php echo number_format($p['current_cost'], 2); ?></td>
              <td style="background: #fef3c7;"><strong>₱<?php echo number_format($p['proposed_cost'], 2); ?></strong></td>
              <td>₱<?php echo number_format($p['current_price'], 2); ?></td>
              <td style="background: #fef3c7;"><strong>₱<?php echo number_format($p['proposed_price'], 2); ?></strong></td>
              <td>
                <span class="<?php echo $p['status'] === 'On Hold' ? 'badge-hold' : 'badge-pending'; ?>">
                  <?php echo $p['status']; ?>
                </span>
              </td>
              <td style="text-align: center; vertical-align: middle;">
                <div class="action-col">
                  <form method="post" style="width: 100%; margin: 0;">
                    <input type="hidden" name="action" value="approve_price">
                    <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                    <input type="hidden" name="new_cost" value="<?php echo $p['proposed_cost']; ?>">
                    <input type="hidden" name="new_price" value="<?php echo $p['proposed_price']; ?>">
                    <button type="submit" class="btn btn-approve"><i class="fas fa-check"></i> Approve</button>
                  </form>
                  <button type="button" class="btn btn-hold" onclick="openHoldModal(<?php echo $p['id']; ?>)"><i class="fas fa-pause"></i> Hold</button>
                  <button type="button" class="btn btn-reject" onclick="openRejectModal(<?php echo $p['id']; ?>)"><i class="fas fa-times"></i> Reject</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  </div>
</div>

<!-- Rejection Modal -->
<div class="modal" id="rejectModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-times-circle" style="color: #ef4444;"></i> Reject Price Proposal</h3>
      <button type="button" class="close" onclick="closeRejectModal()">&times;</button>
    </div>
    <form method="post" id="rejectForm">
      <div class="modal-body">
          <input type="hidden" name="action" value="reject_price">
          <input type="hidden" name="product_id" id="productId" value="">
          
          <div class="form-group">
            <label>Reason for Rejection <span style="color:#dc3545;">*</span></label>
            <textarea name="rejection_reason" class="form-control" rows="3" placeholder="e.g., Price too high, market rate lower, etc." required></textarea>
          </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn ghost" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; border-radius: 6px; cursor: pointer;" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="btn-reject" style="margin-left: 0;">Reject Price</button>
      </div>
    </form>
  </div>
</div>

<!-- Hold Modal -->
<div class="modal" id="holdModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-pause-circle" style="color: #f59e0b;"></i> Hold Price Proposal</h3>
      <button type="button" class="close" onclick="closeHoldModal()">&times;</button>
    </div>
    <form method="post" id="holdForm">
      <div class="modal-body">
          <input type="hidden" name="action" value="hold_price">
          <input type="hidden" name="product_id" id="holdProductId" value="">
          
          <div class="form-group">
            <label>Reason for Hold <span style="color:#dc3545;">*</span></label>
            <textarea name="hold_reason" class="form-control" rows="3" placeholder="e.g., Needs further market review, awaiting supplier confirmation, etc." required></textarea>
          </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn ghost" style="padding: 8px 16px; border: 1px solid #ddd; background: #fff; border-radius: 6px; cursor: pointer;" onclick="closeHoldModal()">Cancel</button>
        <button type="submit" class="btn-hold" style="margin-left: 0;">Hold Proposal</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openRejectModal(productId) {
    document.getElementById('productId').value = productId;
    document.getElementById('rejectModal').classList.add('open');
  }
  
  function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('open');
  }
  
  document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
  });

  function openHoldModal(productId) {
    document.getElementById('holdProductId').value = productId;
    document.getElementById('holdModal').classList.add('open');
  }
  
  function closeHoldModal() {
    document.getElementById('holdModal').classList.remove('open');
  }
  
  document.getElementById('holdModal').addEventListener('click', function(e) {
    if (e.target === this) closeHoldModal();
  });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
