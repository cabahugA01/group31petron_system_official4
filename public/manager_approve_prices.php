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
            SELECT details FROM activity_logs 
            WHERE action = 'Propose Price' AND details LIKE ?
            ORDER BY created_at DESC LIMIT 1
        ");
        $log_stmt->execute(["%Product ID $prod_id%"]);
        $log = $log_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($log) {
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
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f8fafc; }
  
  .ma-wrapper { max-width: 1400px; margin: 0 auto; padding: 24px; }
  
  .ma-header { 
    background: linear-gradient(135deg, #059669 0%, #047857 100%); 
    color: white; padding: 40px 32px; border-radius: 16px; margin-bottom: 32px; 
    box-shadow: 0 8px 24px rgba(5,150,105,0.3); 
  }
  .ma-header-content { display: flex; align-items: center; gap: 16px; }
  .ma-header-icon { font-size: 42px; }
  .ma-header h1 { font-size: 32px; font-weight: 700; margin-bottom: 6px; }
  .ma-header p { font-size: 14px; opacity: 0.85; }
  
  .ma-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
  .ma-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .ma-alert-error { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
  
  .ma-table-wrap { background: white; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
  .ma-table { width: 100%; border-collapse: collapse; }
  .ma-table th { background: #f8fafc; padding: 14px; text-align: left; font-size: 12px; font-weight: 700; color: #334155; border-bottom: 2px solid #e2e8f0; }
  .ma-table td { padding: 14px; border-bottom: 1px solid #f1f5f9; }
  .ma-table tr:hover { background: #f8fafc; }
  
  .ma-btn-approve { background: #10b981; color: white; padding: 8px 16px; border: 0; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 12px; }
  .ma-btn-approve:hover { background: #059669; }
  
  .ma-btn-reject { background: #ef4444; color: white; padding: 8px 16px; border: 0; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 12px; margin-left: 8px; }
  .ma-btn-reject:hover { background: #dc2626; }
  
  .ma-empty { text-align: center; padding: 40px; color: #94a3b8; }
  
  .ma-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
  .ma-modal.active { display: flex; align-items: center; justify-content: center; }
  .ma-modal-content { background: white; border-radius: 12px; padding: 28px; max-width: 500px; width: 90%; }
  .ma-modal-title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 16px; }
  .ma-modal-form { display: grid; gap: 16px; }
  .ma-modal-form textarea { width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; font-family: inherit; }
  .ma-modal-form textarea:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 4px rgba(5,150,105,0.08); }
  .ma-modal-actions { display: flex; gap: 12px; margin-top: 20px; }
  .ma-modal-actions button { flex: 1; padding: 11px; border: 0; border-radius: 8px; font-weight: 600; cursor: pointer; }
  .ma-modal-close { background: #e2e8f0; color: #334155; }
  .ma-modal-submit { background: #ef4444; color: white; }
</style>

<div class="ma-wrapper">
  <div class="ma-header">
    <div class="ma-header-content">
      <div class="ma-header-icon"><i class="fas fa-check-double"></i></div>
      <div>
        <h1>Verify Price Proposals</h1>
        <p>Manager - Review and approve/reject price changes from Admin</p>
      </div>
    </div>
  </div>
  
  <?php if($msg): ?>
    <div class="ma-alert <?php echo strpos($msg, '✅') !== false ? 'ma-alert-success' : 'ma-alert-error'; ?>">
      <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
      <?php echo htmlspecialchars($msg); ?>
    </div>
  <?php endif; ?>
  
  <div class="ma-table-wrap">
    <table class="ma-table">
      <thead>
        <tr>
          <th>Product</th>
          <th>SKU</th>
          <th>Current Cost</th>
          <th>Proposed Cost</th>
          <th>Current Price</th>
          <th>Proposed Price</th>
          <th style="text-align: center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($products)): ?>
          <tr><td colspan="7" class="ma-empty">No products found</td></tr>
        <?php else: ?>
          <?php foreach($products as $p): ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
              <td><?php echo $p['sku']; ?></td>
              <td>₱<?php echo number_format($p['current_cost'], 2); ?></td>
              <td style="background: #fef3c7;"><strong>₱<?php echo number_format($p['proposed_cost'], 2); ?></strong></td>
              <td>₱<?php echo number_format($p['current_price'], 2); ?></td>
              <td style="background: #fef3c7;"><strong>₱<?php echo number_format($p['proposed_price'], 2); ?></strong></td>
              <td style="text-align: center;">
                <form method="post" style="display: inline;">
                  <input type="hidden" name="action" value="approve_price">
                  <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                  <input type="hidden" name="new_cost" value="<?php echo $p['proposed_cost']; ?>">
                  <input type="hidden" name="new_price" value="<?php echo $p['proposed_price']; ?>">
                  <button type="submit" class="ma-btn-approve"><i class="fas fa-check"></i> Approve</button>

                </form>
                <button type="button" class="ma-btn-reject" onclick="openRejectModal(<?php echo $p['id']; ?>)"><i class="fas fa-times"></i> Reject</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Rejection Modal -->
<div class="ma-modal" id="rejectModal">
  <div class="ma-modal-content">
    <div class="ma-modal-title">Reject Price</div>
    <form method="post" class="ma-modal-form" id="rejectForm">
      <input type="hidden" name="action" value="reject_price">
      <input type="hidden" name="product_id" id="productId" value="">
      
      <div>
        <label style="font-size: 13px; font-weight: 600; color: #334155; display: block; margin-bottom: 8px;">Reason for Rejection *</label>
        <textarea name="rejection_reason" placeholder="e.g., Price too high, market rate lower, etc." required></textarea>
      </div>
      
      <div class="ma-modal-actions">
        <button type="button" class="ma-modal-close" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="ma-modal-submit">Reject Price</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openRejectModal(productId) {
    document.getElementById('productId').value = productId;
    document.getElementById('rejectModal').classList.add('active');
  }
  
  function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('active');
  }
  
  document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
  });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
