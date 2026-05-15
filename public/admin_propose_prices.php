<?php
$page_id = 'admin_propose_prices';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Admin/Owner only
if (!in_array($role, ['admin', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// Handle price proposal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'propose_price') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $cost = (float)($_POST['cost'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        
        if ($product_id <= 0 || $price < 0 || $cost < 0) {
            $msg = "❌ Invalid product or price values.";
        } elseif ($price < $cost) {
            $msg = "❌ Selling price must be at least equal to cost.";
        } else {
            try {
                // Check if product exists
                $stmt = $pdo->prepare("SELECT id, cost as old_cost, price as old_price FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$prod) {
                    $msg = "❌ Product not found.";
                } else {
                    // Store proposed prices temporarily (don't apply yet)
                    // They'll only apply after manager approves
                    log_activity($pdo, $me['id'], 'Propose Price', "PROPOSED: Product ID $product_id | Old Cost: {$prod['old_cost']} → New Cost: $cost | Old Price: {$prod['old_price']} → New Price: $price");
                    
                    $msg = "✅ Price proposal submitted! Awaiting manager approval.";
                }
            } catch (Exception $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
}

// Fetch all products
$products = [];
try {
    $stmt = $pdo->query("SELECT id, name, sku, cost, price FROM products ORDER BY name ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
}

// Fetch recently received/encoded items by staff
$staff_encoded = [];
try {
    $stmt = $pdo->query("
        SELECT DISTINCT 
            ri.id,
            ri.delivery_date,
            ri.item_name,
            ri.quantity,
            ri.supplier,
            u.name as received_by_name,
            p.name as product_name,
            p.id as product_id
        FROM received_items ri
        LEFT JOIN users u ON ri.received_by = u.id
        LEFT JOIN products p ON ri.product_id = p.id
        WHERE ri.delivery_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY ri.delivery_date DESC 
        LIMIT 20
    ");
    $staff_encoded = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $staff_encoded = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f8fafc; }
  
  .pp-wrapper { max-width: 1200px; margin: 0 auto; padding: 24px; }
  
  .pp-header { 
    background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); 
    color: white; padding: 40px 32px; border-radius: 16px; margin-bottom: 32px; 
    box-shadow: 0 8px 24px rgba(124,58,237,0.3); 
  }
  .pp-header-content { display: flex; align-items: center; gap: 16px; }
  .pp-header-icon { font-size: 42px; }
  .pp-header h1 { font-size: 32px; font-weight: 700; margin-bottom: 6px; }
  .pp-header p { font-size: 14px; opacity: 0.85; }
  
  .pp-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
  .pp-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .pp-alert-error { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
  
  .pp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px; }
  
  .pp-card { background: white; border-radius: 12px; padding: 28px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; overflow: visible; }
  .pp-card-header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
  .pp-card-icon { font-size: 24px; color: #7c3aed; }
  .pp-card-title { font-size: 18px; font-weight: 700; color: #0f172a; }
  
  .pp-form { display: grid; gap: 16px; position: relative; z-index: 1; }
  .pp-form-group { position: relative; }
  .pp-form-group label { font-size: 13px; font-weight: 600; color: #334155; display: block; margin-bottom: 8px; }
  .pp-form-group input, .pp-form-group select { 
    width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; 
    border-radius: 10px; font-size: 14px; background: #f8fafc; font-family: inherit; position: relative; z-index: 10;
  }
  .pp-form-group select { position: relative; z-index: 100; }
  .pp-form-group input:focus, .pp-form-group select:focus { 
    outline: none; border-color: #7c3aed; box-shadow: 0 0 0 4px rgba(124,58,237,0.08); background: white; position: relative; z-index: 101;
  }
  
  .pp-btn { 
    background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); 
    color: white; padding: 12px 24px; border: 0; border-radius: 10px; 
    cursor: pointer; font-weight: 600; font-size: 14px; width: 100%;
  }
  .pp-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(124,58,237,0.3); }
  
  .pp-info { background: #f3f0ff; border-left: 4px solid #7c3aed; padding: 16px; border-radius: 8px; font-size: 13px; color: #6b21a8; line-height: 1.6; }
  .pp-info strong { color: #5b21b6; display: block; margin-bottom: 8px; margin-top: 12px; }
  .pp-info strong:first-child { margin-top: 0; }
  
  @media (max-width: 1000px) {
    .pp-grid { grid-template-columns: 1fr; }
  }
</style>

<div class="pp-wrapper">
  <div class="pp-header">
    <div class="pp-header-content">
      <div class="pp-header-icon"><i class="fas fa-tag"></i></div>
      <div>
        <h1>Propose Price Changes</h1>
        <p>Admin/Owner - Submit prices for manager review</p>
      </div>
    </div>
  </div>
  
  <?php if($msg): ?>
    <div class="pp-alert <?php echo strpos($msg, '✅') !== false ? 'pp-alert-success' : 'pp-alert-error'; ?>">
      <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
      <?php echo htmlspecialchars($msg); ?>
    </div>
  <?php endif; ?>

  <!-- Staff Encoded Items Section -->
  <?php if (!empty($staff_encoded)): ?>
  <div style="background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%); border: 2px solid #d8b4fe; border-radius: 12px; padding: 24px; margin-bottom: 24px;">
    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
      <span style="font-size: 24px;">📦</span>
      <h3 style="margin: 0; color: #6b21a8; font-size: 18px; font-weight: 700;">Staff Recently Encoded Items (Last 7 Days)</h3>
    </div>
    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #e9d5ff; border-radius: 8px; background: white;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="background: #f3e8ff; border-bottom: 2px solid #e9d5ff; position: sticky; top: 0;">
            <th style="padding: 12px; text-align: left; color: #6b21a8; font-weight: 600; font-size: 13px;">Date</th>
            <th style="padding: 12px; text-align: left; color: #6b21a8; font-weight: 600; font-size: 13px;">Product</th>
            <th style="padding: 12px; text-align: center; color: #6b21a8; font-weight: 600; font-size: 13px;">Qty</th>
            <th style="padding: 12px; text-align: left; color: #6b21a8; font-weight: 600; font-size: 13px;">Encoded By</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staff_encoded as $enc): ?>
          <tr style="border-bottom: 1px solid #f3e8ff; hover: background: #faf5ff;">
            <td style="padding: 12px; color: #6b21a8; font-size: 13px;"><?php echo date('M d, Y', strtotime($enc['delivery_date'])); ?></td>
            <td style="padding: 12px; color: #6b21a8; font-size: 13px; font-weight: 500;"><?php echo htmlspecialchars($enc['item_name'] ?? $enc['product_name'] ?? 'N/A'); ?></td>
            <td style="padding: 12px; color: #6b21a8; font-size: 13px; text-align: center;"><?php echo number_format($enc['quantity'] ?? 0, 0); ?></td>
            <td style="padding: 12px; color: #6b21a8; font-size: 13px;"><?php echo htmlspecialchars($enc['received_by_name'] ?? 'Unknown'); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="margin: 12px 0 0 0; color: #6b21a8; font-size: 12px; opacity: 0.8;">
      ℹ️ <strong>Select the encoded products below and propose prices. Prices will apply to staff POS after manager approval.</strong>
    </p>
  </div>
  <?php endif; ?>
  
  <div class="pp-grid">
    <div class="pp-card">
      <div class="pp-card-header">
        <div class="pp-card-icon"><i class="fas fa-edit"></i></div>
        <div class="pp-card-title">Propose Price</div>
      </div>
      
      <form method="post" class="pp-form">
        <input type="hidden" name="action" value="propose_price">
        
        <div class="pp-form-group">
          <label>Product *</label>
          <select name="product_id" required>
            <option value="">Select Product</option>
            <?php foreach($products as $p): ?>
              <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> (<?php echo $p['sku']; ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="pp-form-group">
          <label>Cost Price (₱) *</label>
          <input type="number" name="cost" step="0.01" min="0" placeholder="0.00" required>
        </div>
        
        <div class="pp-form-group">
          <label>Selling Price (₱) *</label>
          <input type="number" name="price" step="0.01" min="0" placeholder="0.00" required>
        </div>
        
        <button type="submit" class="pp-btn"><i class="fas fa-check"></i> Submit for Approval</button>
      </form>
    </div>
    
    <div class="pp-card">
      <div class="pp-card-header">
        <div class="pp-card-icon"><i class="fas fa-info-circle"></i></div>
        <div class="pp-card-title">3-Tier Price Approval</div>
      </div>
      
      <div class="pp-info">
        <strong><i class="fas fa-1"></i> Admin Proposes</strong>
        You propose cost and selling prices for products.
        
        <strong><i class="fas fa-2"></i> Manager Approves</strong>
        Manager reviews and verifies prices align with station policies.
        
        <strong><i class="fas fa-3"></i> Staff Uses Approved</strong>
        Once approved, prices automatically appear in POS and transactions. Staff cannot edit.
        
        <strong><i class="fas fa-check-circle"></i> Important</strong>
        Selling price must be ≥ cost price. Manager must approve before prices go live in POS.
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
