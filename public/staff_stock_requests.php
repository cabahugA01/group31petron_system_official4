<?php
$page_id = 'staff_stock_requests';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Only staff can access this page
if ($role !== 'staff') {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'submit_request') {
        $req_type = $_POST['type'] ?? '';
        $product_name = trim($_POST['product_name'] ?? '');
        $qty = (float)($_POST['qty'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$req_type || !$product_name || $qty <= 0) {
            $msg = "❌ Please fill in all required fields.";
        } else {
            try {
                // Generate unique request ID
                $request_id = 'SR-' . rand(1000, 9999);
                
                // Get product info from inventory to populate required fields
                $product_info = null;
                if ($req_type === 'fuel') {
                    // Try to get from fuel inventory
                    $stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE fuel_type = ? AND station_id = ? LIMIT 1");
                    $stmt->execute([$product_name, $station_id]);
                    $product_info = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    // Try to get from inventory_products
                    $stmt = $pdo->prepare("SELECT * FROM inventory_products WHERE product_name = ? LIMIT 1");
                    $stmt->execute([$product_name]);
                    $product_info = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                // Set defaults for required fields
                $unit = $product_info['unit'] ?? 'pcs';
                $cost_per_unit = $product_info['unit_cost'] ?? 0;
                $current_stock = $product_info['stock_level'] ?? 0;
                
                $stmt = $pdo->prepare("
                    INSERT INTO stock_requests (request_id, station_id, staff_id, product_name, quantity_requested, reason, unit, cost_per_unit, current_stock, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
                ");
                $stmt->execute([$request_id, $station_id, (int)$me['id'], $product_name, $qty, $notes, $unit, $cost_per_unit, $current_stock]);
                $msg = "✅ Stock request submitted! Awaiting manager review.";
            } catch (Exception $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
}

$status_filter = $_GET['status'] ?? '';
$where_clause = "r.station_id = ? AND r.staff_id = ?";
$params = [$station_id, (int)$me['id']];

if ($status_filter === 'pending') {
    $where_clause .= " AND r.status = 'Pending'";
} elseif ($status_filter === 'approved') {
    $where_clause .= " AND r.status = 'Approved'";
} elseif ($status_filter === 'completed') {
    $where_clause .= " AND r.status = 'Completed'";
}

$requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, s.name as station_name, u.name as requester_name 
        FROM stock_requests r 
        LEFT JOIN stations s ON r.station_id = s.id 
        LEFT JOIN users u ON r.staff_id = u.id 
        WHERE $where_clause
        ORDER BY (r.status='Pending') DESC, r.created_at DESC
    ");
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $requests = [];
}

// Get low stock items for reference
$low_stock_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.name as product_name,
            p.sku,
            si.stock_level,
            si.unit,
            si.reorder_level,
            pc.name as category_name,
            CASE 
                WHEN si.stock_level <= si.reorder_level THEN 'Low Stock'
                WHEN si.stock_level <= (si.reorder_level * 0.5) THEN 'Critical'
                ELSE 'Normal'
            END as stock_status
        FROM station_inventory si
        JOIN products p ON si.product_id = p.id
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE si.station_id = ? AND si.status = 'active'
        ORDER BY si.stock_level ASC
        LIMIT 10
    ");
    $stmt->execute([$station_id]);
    $low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $low_stock_items = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
  
  .ssr-wrapper { max-width: 1400px; margin: 0 auto; padding: 24px; }
  
  /* Header */
  .ssr-header { 
    background: linear-gradient(135deg, #003d7a 0%, #002d5c 100%); 
    color: white; padding: 48px 32px; border-radius: 16px; margin-bottom: 32px; 
    box-shadow: 0 8px 24px rgba(0,61,122,0.3); 
  }
  .ssr-header-content { display: flex; align-items: center; gap: 20px; }
  .ssr-header-icon { font-size: 48px; opacity: 0.9; }
  .ssr-header h1 { font-size: 36px; font-weight: 700; margin-bottom: 6px; color: white !important; }
  .ssr-header p { font-size: 16px; opacity: 0.85; color: white !important; }
  
  /* Alert */
  .ssr-alert { 
    display: flex; align-items: center; gap: 12px; padding: 14px 16px; 
    border-radius: 10px; margin-bottom: 24px; font-size: 14px; animation: slideIn 0.3s ease; 
  }
  .ssr-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .ssr-alert-error { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
  .ssr-alert i { font-size: 18px; }
  
  @keyframes slideIn { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
  
  /* Grid */
  .ssr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px; }
  
  /* Cards */
  .ssr-card { 
    background: white; border-radius: 14px; padding: 28px; 
    box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; 
    transition: all 0.3s; 
  }
  .ssr-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
  
  .ssr-card-header { 
    display: flex; align-items: center; gap: 12px; margin-bottom: 24px; 
    padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; 
  }
  .ssr-card-icon { font-size: 24px; color: #003d7a; }
  .ssr-card-title { font-size: 20px; font-weight: 700; color: #0f172a; }
  
  /* Form */
  .ssr-form { display: grid; gap: 16px; }
  .ssr-form-group { }
  .ssr-form-group label { 
    font-size: 13px; font-weight: 600; color: #334155; 
    display: block; margin-bottom: 8px; 
  }
  .ssr-form-group input, .ssr-form-group select, .ssr-form-group textarea { 
    width: 100%; padding: 12px 14px; border: 1.5px solid #cbd5e1; 
    border-radius: 10px; font-size: 14px; font-family: inherit; 
    background: #f8fafc; transition: all 0.2s; 
  }
  .ssr-form-group input:focus, .ssr-form-group select:focus, .ssr-form-group textarea:focus { 
    outline: none; background: white; border-color: #003d7a; 
    box-shadow: 0 0 0 4px rgba(0,61,122,0.08); 
  }
  
  .ssr-btn { 
    background: linear-gradient(135deg, #003d7a 0%, #002d5c 100%); 
    color: white; padding: 12px 24px; border: 0; border-radius: 10px; 
    cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s; 
    box-shadow: 0 4px 12px rgba(0,61,122,0.25); width: 100%;
  }
  .ssr-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,61,122,0.35); }
  .ssr-btn:active { transform: translateY(0); }
  .ssr-btn i { margin-right: 8px; }
  
  /* Low Stock Alert */
  .ssr-low-stock { 
    background: linear-gradient(135deg, #fef2f2 0%, #fef7f7 100%); 
    border-left: 5px solid #dc2626; padding: 20px; border-radius: 10px; margin-bottom: 20px; 
  }
  .ssr-low-stock-title { 
    color: #991b1b; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; 
  }
  .ssr-low-stock-item { 
    display: flex; justify-content: space-between; align-items: center; 
    padding: 8px 12px; background: white; border-radius: 6px; margin-bottom: 6px; 
  }
  .ssr-low-stock-name { font-size: 13px; font-weight: 600; color: #374151; }
  .ssr-low-stock-level { font-size: 12px; color: #6b7280; }
  .ssr-low-stock-critical { border-left: 3px solid #dc2626; }
  .ssr-low-stock-normal { border-left: 3px solid #f59e0b; }
  
  /* Requests Section */
  .ssr-requests-section { }
  .ssr-section-title { 
    font-size: 22px; font-weight: 700; color: #0f172a; 
    margin-bottom: 20px; display: flex; align-items: center; gap: 10px; 
  }
  .ssr-section-title i { color: #003d7a; }
  
  /* Request Items */
  .ssr-request-item { 
    background: white; border: 1px solid #e2e8f0; border-radius: 12px; 
    padding: 20px; margin-bottom: 14px; display: grid; 
    grid-template-columns: 1fr auto; gap: 20px; align-items: center; 
    transition: all 0.2s; 
  }
  .ssr-request-item:hover { 
    border-color: #cbd5e1; box-shadow: 0 4px 16px rgba(0,0,0,0.08); 
  }
  
  .ssr-req-content { }
  .ssr-req-title { 
    font-size: 16px; font-weight: 700; color: #0f172a; 
    margin-bottom: 10px; display: flex; align-items: center; gap: 10px; 
  }
  .ssr-req-type-icon { font-size: 18px; }
  .ssr-req-type-fuel { color: #f59e0b; }
  .ssr-req-type-merch { color: #8b5cf6; }
  
  .ssr-req-meta { 
    display: grid; grid-template-columns: auto auto auto; 
    gap: 20px; font-size: 13px; 
  }
  .ssr-req-meta-item { }
  .ssr-req-meta-label { color: #64748b; font-weight: 600; }
  .ssr-req-meta-value { color: #334155; font-weight: 500; }
  
  /* Status Badge */
  .ssr-status { 
    display: inline-block; padding: 6px 12px; border-radius: 8px; 
    font-size: 12px; font-weight: 700; 
  }
  .ssr-status-pending { background: #fef3c7; color: #92400e; }
  .ssr-status-approved { background: #d1fae5; color: #065f46; }
  .ssr-status-rejected { background: #fee2e2; color: #7f1d1d; }
  
  /* Empty State */
  .ssr-empty { text-align: center; padding: 60px 20px; }
  .ssr-empty-icon { font-size: 56px; color: #cbd5e1; margin-bottom: 16px; }
  .ssr-empty-title { font-size: 18px; font-weight: 600; color: #64748b; margin-bottom: 8px; }
  .ssr-empty-text { font-size: 14px; color: #94a3b8; }
  
  /* Quick Actions */
  .ssr-quick-actions { 
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 16px; 
  }
  .ssr-quick-action { 
    padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; 
    border-radius: 8px; text-align: center; cursor: pointer; transition: all 0.2s; 
    text-decoration: none; color: inherit; 
  }
  .ssr-quick-action:hover { 
    background: #e2e8f0; border-color: #cbd5e1; 
  }
  .ssr-quick-action i { font-size: 20px; color: #003d7a; margin-bottom: 4px; display: block; }
  .ssr-quick-action span { font-size: 12px; font-weight: 600; color: #475569; }
  
  /* Responsive */
  @media (max-width: 1000px) {
    .ssr-grid { grid-template-columns: 1fr; }
    .ssr-request-item { grid-template-columns: 1fr; }
    .ssr-req-meta { grid-template-columns: auto auto; }
  }
  
  @media (max-width: 600px) {
    .ssr-wrapper { padding: 16px; }
    .ssr-header { padding: 32px 20px; }
    .ssr-header h1 { font-size: 26px; }
    .ssr-req-meta { grid-template-columns: 1fr; gap: 10px; }
    .ssr-quick-actions { grid-template-columns: 1fr; }
  }
</style>

<div class="ssr-wrapper">
  <!-- Header -->
  <div class="ssr-header">
    <div class="ssr-header-content">
      <div class="ssr-header-icon"></div>
      <div>
        <h1>Stock Requests</h1>
        <p>Submit inventory requests for manager approval</p>
      </div>
    </div>
  </div>
  
  <!-- Alert -->
  <?php if($msg): ?>
    <div class="ssr-alert <?php echo strpos($msg, '✅') !== false ? 'ssr-alert-success' : 'ssr-alert-error'; ?>">
      <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
      <span><?php echo htmlspecialchars($msg); ?></span>
    </div>
  <?php endif; ?>
  
  <!-- Main Grid -->
  <div class="ssr-grid">
    <!-- Request Form -->
    <div class="ssr-card">
      <div class="ssr-card-header">
        <div class="ssr-card-icon"></div>
        <div class="ssr-card-title">New Request</div>
      </div>
      
      <form method="post" class="ssr-form">
        <input type="hidden" name="action" value="submit_request">
        
        <div class="ssr-form-group">
          <label>Request Type <span style="color: #ef4444;">*</span></label>
          <select name="type" required>
            <option value="">Select Type</option>
            <option value="fuel">Fuel</option>
            <option value="merch">Merchandise</option>
          </select>
        </div>
        
        <div class="ssr-form-group">
          <label>Product Name <span style="color: #ef4444;">*</span></label>
          <input type="text" name="product_name" placeholder="e.g., Diesel Max, Sprite 1L" required>
        </div>
        
        <div class="ssr-form-group">
          <label>Quantity <span style="color: #ef4444;">*</span></label>
          <input type="number" name="qty" step="0.01" placeholder="0" min="0.01" required>
        </div>
        
        <div class="ssr-form-group">
          <label>Notes (Optional)</label>
          <textarea name="notes" rows="3" placeholder="Reason for request or special instructions..."></textarea>
        </div>
        
        <button type="submit" class="ssr-btn">Submit Request</button>
      </form>
    </div>
    
    <!-- Low Stock Alert & Quick Actions -->
    <div class="ssr-card">
      <div class="ssr-card-header">
        <div class="ssr-card-icon"></div>
        <div class="ssr-card-title">Low Stock Alert</div>
      </div>
      
      <?php if(!empty($low_stock_items)): ?>
        <div class="ssr-low-stock">
          <div class="ssr-low-stock-title">
            Items Needing Replenishment
          </div>
          <?php foreach($low_stock_items as $item): ?>
            <div class="ssr-low-stock-item <?php echo $item['stock_status'] === 'Critical' ? 'ssr-low-stock-critical' : 'ssr-low-stock-normal'; ?>">
              <div>
                <div class="ssr-low-stock-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                <div class="ssr-low-stock-level"><?php echo number_format($item['stock_level'], 2); ?> <?php echo htmlspecialchars($item['unit']); ?></div>
              </div>
              <div style="text-align: right;">
                <div style="font-size: 11px; color: #6b7280;">Reorder at:</div>
                <div style="font-size: 12px; font-weight: 600; color: #374151;"><?php echo number_format($item['reorder_level'], 2); ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div style="text-align: center; padding: 20px; color: #6b7280;">
          <div>All stock levels are normal</div>
        </div>
      <?php endif; ?>
      
      <!-- Quick Actions -->
      <div class="ssr-quick-actions">
        <a href="inventory.php" class="ssr-quick-action">
          <span>View Inventory</span>
        </a>
        <a href="inventory_history.php" class="ssr-quick-action">
          <span>Stock History</span>
        </a>
      </div>
    </div>
  </div>
  
  <!-- My Requests -->
  <div class="ssr-requests-section">
    <div class="ssr-section-title">
<?php echo ucfirst($status_filter ?: 'All'); ?> Requests
    </div>
    
    <?php if(empty($requests)): ?>
      <div class="ssr-empty">
        <div class="ssr-empty-icon"></div>
        <div class="ssr-empty-title">No Requests Yet</div>
        <div class="ssr-empty-text">Submit your first stock request using the form above.</div>
      </div>
    <?php else: ?>
      <?php foreach($requests as $r): ?>
        <div class="ssr-request-item">
          <div class="ssr-req-content">
            <div class="ssr-req-title">
              <?php echo htmlspecialchars($r['product_name']); ?>
            </div>
            
            <div class="ssr-req-meta">
              <div class="ssr-req-meta-item">
                <div class="ssr-req-meta-label">Quantity</div>
                <div class="ssr-req-meta-value"><?php echo number_format($r['quantity_requested'], 2); ?></div>
              </div>
              <div class="ssr-req-meta-item">
                <div class="ssr-req-meta-label">Status</div>
                <div class="ssr-req-meta-value">
                  <span class="ssr-status ssr-status-<?php echo strtolower($r['status']); ?>">
                    <?php echo htmlspecialchars($r['status']); ?>
                  </span>
                </div>
              </div>
              <div class="ssr-req-meta-item">
                <div class="ssr-req-meta-label">Date</div>
                <div class="ssr-req-meta-value"><?php echo date('M d, Y h:i A', strtotime($r['created_at'])); ?></div>
              </div>
            </div>
          </div>
          
          <div style="color: #94a3b8; font-size: 13px;">
            <?php if($r['status'] === 'Pending'): ?>
Awaiting review
            <?php elseif($r['status'] === 'Approved'): ?>
Approved
            <?php else: ?>
Rejected
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
