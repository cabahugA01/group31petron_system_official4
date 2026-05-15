<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'stock_requests';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = strtolower($me['role'] ?? 'staff');

// Restrict access to staff only (they can only view their own requests)
if ($role === 'staff') {
    header('Location: staff_dashboard.php');
    exit;
}

$station_id = user_station_id();
if (!$station_id && !in_array($role, ['admin', 'superadmin'])) { 
    die('Error: You are not assigned to a station.'); 
}

$msg = '';
if (isset($_SESSION['success'])) { $msg = $_SESSION['success']; unset($_SESSION['success']); }
if (isset($_SESSION['error'])) { $msg = $_SESSION['error']; unset($_SESSION['error']); }

// Handle stock request status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'update_request') {
        $request_id = $_POST['request_id'] ?? 0;
        $new_status = $_POST['status'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        if ($request_id && $new_status) {
            try {
                $response = file_get_contents('http://' . $_SERVER['HTTP_HOST'] . '/backend/api/low_stock_alerts.php?action=update_stock_request', false, stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/x-www-form-urlencoded',
                        'content' => http_build_query([
                            'request_id' => $request_id,
                            'status' => $new_status,
                            'notes' => $notes
                        ])
                    ]
                ]));
                
                $result = json_decode($response, true);
                if ($result['success']) {
                    $_SESSION['success'] = $result['message'];
                } else {
                    $_SESSION['error'] = $result['error'];
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error updating request: ' . $e->getMessage();
            }
        }
        header('Location: stock_requests.php');
        exit;
    }
    
    // Legacy support for existing form submissions
    if ($action === 'submit_request' && in_array($role, ['staff'])) {
        $req_type = $_POST['type'] ?? '';
        $product = trim($_POST['product_name'] ?? '');
        $qty = (float)($_POST['qty'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$req_type || !$product || $qty <= 0) {
            $_SESSION['error'] = "❌ Please fill in all required fields.";
        } else {
            // Convert to new system format
            try {
                $data = [
                    'station_id' => $station_id,
                    'product_id' => 0, // Would need product lookup
                    'category' => $req_type,
                    'requested_quantity' => $qty,
                    'urgency' => 'medium',
                    'reason' => $notes,
                    'unit' => 'pcs'
                ];
                
                $response = file_get_contents('http://' . $_SERVER['HTTP_HOST'] . '/backend/api/low_stock_alerts.php?action=create_stock_request', false, stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/json',
                        'content' => json_encode($data)
                    ]
                ]));
                
                $result = json_decode($response, true);
                if ($result['success']) {
                    $_SESSION['success'] = "✅ Stock request created successfully! Request #: " . $result['request_number'];
                } else {
                    $_SESSION['error'] = "❌ Error: " . $result['error'];
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "❌ Error creating request: " . $e->getMessage();
            }
        }
        header('Location: stock_requests.php');
        exit;
    }
}

// Get stock requests
$requests = [];
try {
    $api_url = 'http://' . $_SERVER['HTTP_HOST'] . '/backend/api/low_stock_alerts.php?action=get_stock_requests';
    if (!in_array($role, ['admin', 'superadmin']) && $station_id) {
        $api_url .= '&station_id=' . $station_id;
    }
    
    $response = file_get_contents($api_url);
    $result = json_decode($response, true);
    
    if ($result['success']) {
        $requests = $result['requests'];
    }
} catch (Exception $e) {
    $error_msg = 'Error loading stock requests: ' . $e->getMessage();
}
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO stock_requests (station_id, type, product_name, qty, notes, status, submitted_by, submitted_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())");
                $stmt->execute([$station_id, $req_type, $product, $qty, $notes, (int)$me['id']]);
                $msg = "✅ Stock request submitted! Awaiting manager review.";
            } catch (Exception $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
    
    // MANAGER ONLY: Approve/Reject stock requests (per hierarchy - Admin cannot do Manager work)
    if (in_array($action, ['approve_request', 'reject_request'])) {
        if ($role !== 'manager') {
            $msg = "❌ Error: Only managers can approve/reject stock requests.";
        } else {
            $request_id = (int)($_POST['request_id'] ?? 0);
            $new_status = ($action === 'approve_request') ? 'approved' : 'rejected';
            
            if ($request_id > 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE stock_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_status, (int)$me['id'], $request_id]);
                    $msg = "✅ Stock request has been " . $new_status . ".";
                } catch (Exception $e) {
                    $msg = "❌ Error: " . $e->getMessage();
                }
            }
        }
    }
}

$requests = [];
try {
    if ($role === 'superadmin') {
        $stmt = $pdo->query("SELECT r.*, s.name as station_name, u.name as requester_name FROM stock_requests r LEFT JOIN stations s ON r.station_id = s.id LEFT JOIN users u ON r.requested_by = u.id ORDER BY (r.status='pending') DESC, r.created_at DESC");
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT r.*, s.name as station_name, u.name as requester_name FROM stock_requests r LEFT JOIN stations s ON r.station_id = s.id LEFT JOIN users u ON r.requested_by = u.id WHERE r.station_id = ? ORDER BY (r.status='pending') DESC, r.created_at DESC");
        $stmt->execute([$station_id]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $requests = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
  
  .sr-wrapper { max-width: 1400px; margin: 0 auto; padding: 24px; }
  
  /* Header */
  .sr-header { 
    background: linear-gradient(135deg, #003d7a 0%, #002d5c 100%); 
    color: white; padding: 48px 32px; border-radius: 16px; margin-bottom: 32px; 
    box-shadow: 0 8px 24px rgba(0,61,122,0.3); 
  }
  .sr-header-content { display: flex; align-items: center; gap: 20px; }
  .sr-header-icon { font-size: 48px; opacity: 0.9; }
  .sr-header h1 { font-size: 36px; font-weight: 700; margin-bottom: 6px; color: white !important; }
  .sr-header p { font-size: 16px; opacity: 0.85; color: white !important; }
  
  /* Alert */
  .sr-alert { 
    display: flex; align-items: center; gap: 12px; padding: 14px 16px; 
    border-radius: 10px; margin-bottom: 24px; font-size: 14px; animation: slideIn 0.3s ease; 
  }
  .sr-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .sr-alert-error { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
  .sr-alert i { font-size: 18px; }
  
  @keyframes slideIn { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
  
  /* Grid */
  .sr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px; }
  
  /* Cards */
  .sr-card { 
    background: white; border-radius: 14px; padding: 28px; 
    box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; 
    transition: all 0.3s; 
  }
  .sr-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
  
  .sr-card-header { 
    display: flex; align-items: center; gap: 12px; margin-bottom: 24px; 
    padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; 
  }
  .sr-card-icon { font-size: 24px; color: #003d7a; }
  .sr-card-title { font-size: 20px; font-weight: 700; color: #0f172a; }
  
  /* Form */
  .sr-form { display: grid; gap: 16px; }
  .sr-form-group { }
  .sr-form-group label { 
    font-size: 13px; font-weight: 600; color: #334155; 
    display: block; margin-bottom: 8px; 
  }
  .sr-form-group input, .sr-form-group select, .sr-form-group textarea { 
    width: 100%; padding: 12px 14px; border: 1.5px solid #cbd5e1; 
    border-radius: 10px; font-size: 14px; font-family: inherit; 
    background: #f8fafc; transition: all 0.2s; 
  }
  .sr-form-group input:focus, .sr-form-group select:focus, .sr-form-group textarea:focus { 
    outline: none; background: white; border-color: #003d7a; 
    box-shadow: 0 0 0 4px rgba(0,61,122,0.08); 
  }
  
  .sr-btn { 
    background: linear-gradient(135deg, #003d7a 0%, #002d5c 100%); 
    color: white; padding: 12px 24px; border: 0; border-radius: 10px; 
    cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s; 
    box-shadow: 0 4px 12px rgba(0,61,122,0.25); width: 100%;
  }
  .sr-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,61,122,0.35); }
  .sr-btn:active { transform: translateY(0); }
  .sr-btn i { margin-right: 8px; }
  
  /* Info Box */
  .sr-info-box { 
    background: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%); 
    border-left: 5px solid #0369a1; padding: 20px; border-radius: 10px; line-height: 1.7; 
  }
  .sr-info-box strong { 
    color: #075985; display: block; margin-bottom: 10px; font-size: 14px; 
  }
  .sr-info-box p { 
    font-size: 13px; color: #0c4a6e; margin-bottom: 12px; 
  }
  .sr-info-box p:last-child { margin-bottom: 0; }
  
  /* Requests Section */
  .sr-requests-section { }
  .sr-section-title { 
    font-size: 22px; font-weight: 700; color: #0f172a; 
    margin-bottom: 20px; display: flex; align-items: center; gap: 10px; 
  }
  .sr-section-title i { color: #003d7a; }
  
  /* Request Items */
  .sr-request-item { 
    background: white; border: 1px solid #e2e8f0; border-radius: 12px; 
    padding: 20px; margin-bottom: 14px; display: grid; 
    grid-template-columns: 1fr auto; gap: 20px; align-items: center; 
    transition: all 0.2s; 
  }
  .sr-request-item:hover { 
    border-color: #cbd5e1; box-shadow: 0 4px 16px rgba(0,0,0,0.08); 
  }
  
  .sr-req-content { }
  .sr-req-title { 
    font-size: 16px; font-weight: 700; color: #0f172a; 
    margin-bottom: 10px; display: flex; align-items: center; gap: 10px; 
  }
  .sr-req-type-icon { font-size: 18px; }
  .sr-req-type-fuel { color: #f59e0b; }
  .sr-req-type-merch { color: #8b5cf6; }
  
  .sr-req-meta { 
    display: grid; grid-template-columns: auto auto auto auto; 
    gap: 20px; font-size: 13px; 
  }
  .sr-req-meta-item { }
  .sr-req-meta-label { color: #64748b; font-weight: 600; }
  .sr-req-meta-value { color: #334155; font-weight: 500; }
  
  /* Status Badge */
  .sr-status { 
    display: inline-block; padding: 6px 12px; border-radius: 8px; 
    font-size: 12px; font-weight: 700; 
  }
  .sr-status-pending { background: #fef3c7; color: #92400e; }
  .sr-status-approved { background: #d1fae5; color: #065f46; }
  .sr-status-rejected { background: #fee2e2; color: #7f1d1d; }
  
  /* Actions */
  .sr-actions { display: flex; gap: 10px; }
  .sr-btn-sm { 
    padding: 8px 16px; font-size: 13px; border: 0; border-radius: 8px; 
    cursor: pointer; font-weight: 600; transition: all 0.2s; 
  }
  .sr-btn-approve { background: #10b981; color: white; }
  .sr-btn-approve:hover { background: #059669; }
  .sr-btn-reject { background: #ef4444; color: white; }
  .sr-btn-reject:hover { background: #dc2626; }
  .sr-btn-sm i { margin-right: 4px; font-size: 12px; }
  
  /* Empty State */
  .sr-empty { text-align: center; padding: 60px 20px; }
  .sr-empty-icon { font-size: 56px; color: #cbd5e1; margin-bottom: 16px; }
  .sr-empty-title { font-size: 18px; font-weight: 600; color: #64748b; margin-bottom: 8px; }
  .sr-empty-text { font-size: 14px; color: #94a3b8; }
  
  /* Role Badge */
  .sr-role-badge { 
    background: #f1f5f9; color: #475569; padding: 16px 20px; 
    border-radius: 10px; font-size: 13px; text-align: center; 
    margin-bottom: 20px; border-left: 4px solid #003d7a; 
  }
  .sr-role-badge strong { color: #0f172a; display: block; margin-bottom: 4px; }
  
  /* Responsive */
  @media (max-width: 1000px) {
    .sr-grid { grid-template-columns: 1fr; }
    .sr-request-item { grid-template-columns: 1fr; }
    .sr-req-meta { grid-template-columns: auto auto; }
  }
  
  @media (max-width: 600px) {
    .sr-wrapper { padding: 16px; }
    .sr-header { padding: 32px 20px; }
    .sr-header h1 { font-size: 26px; }
    .sr-req-meta { grid-template-columns: 1fr; gap: 10px; }
    .sr-actions { flex-direction: column; }
    .sr-actions form { width: 100%; }
  }
</style>

<div class="sr-wrapper">
  <!-- Header -->
  <div class="sr-header">
    <div class="sr-header-content">
      <div class="sr-header-icon"><i class="fas fa-warehouse"></i></div>
      <div>
        <h1>Stock Requests</h1>
        <p>Manage inventory replenishment with approval workflow</p>
      </div>
    </div>
  </div>
  
  <!-- Alert -->
  <?php if($msg): ?>
    <div class="sr-alert <?php echo strpos($msg, '✅') !== false ? 'sr-alert-success' : 'sr-alert-error'; ?>">
      <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
      <span><?php echo htmlspecialchars($msg); ?></span>
    </div>
  <?php endif; ?>
  
  <!-- Form & Info -->
  <div class="sr-grid">
    <!-- Form -->
    <div class="sr-card">
      <div class="sr-card-header">
        <div class="sr-card-icon"><i class="fas fa-plus-circle"></i></div>
        <div class="sr-card-title">Submit Request</div>
      </div>
      
      <?php if($role === 'staff'): ?>
        <form method="post" class="sr-form">
          <input type="hidden" name="action" value="request_stock">
          
          <div class="sr-form-group">
            <label>Type <span style="color: #ef4444;">*</span></label>
            <select name="req_type" required>
              <option value="">Select Type</option>
              <option value="merch">Merchandise</option>
              <option value="fuel">Fuel</option>
            </select>
          </div>
          
          <div class="sr-form-group">
            <label>Product Name <span style="color: #ef4444;">*</span></label>
            <input type="text" name="product_name" placeholder="e.g., Sprite 1L, Diesel Max" required>
          </div>
          
          <div class="sr-form-group">
            <label>Quantity <span style="color: #ef4444;">*</span></label>
            <input type="number" name="qty" step="0.01" placeholder="0" min="0.01" required>
          </div>
          
          <div class="sr-form-group">
            <label>Notes (Optional)</label>
            <textarea name="notes" rows="3" placeholder="Add any additional details..."></textarea>
          </div>
          
          <button type="submit" class="sr-btn"><i class="fas fa-send"></i>Submit Request</button>
        </form>
      <?php else: ?>
        <div class="sr-role-badge">
          <strong><i class="fas fa-lock"></i> Access Restricted</strong>
          Only Operations Staff can submit requests
        </div>
      <?php endif; ?>
    </div>
    
    <!-- Info -->
    <div class="sr-card">
      <div class="sr-card-header">
        <div class="sr-card-icon"><i class="fas fa-lightbulb"></i></div>
        <div class="sr-card-title">How It Works</div>
      </div>
      
      <div class="sr-info-box">
        <strong><i class="fas fa-user-tie"></i> Staff Role</strong>
        <p>Submit requests for fuel or merchandise when inventory runs low. Your request will be reviewed by management.</p>
        
        <strong style="margin-top: 16px;"><i class="fas fa-user-shield"></i> Admin/Manager</strong>
        <p>Review pending requests, approve to automatically update stock, or reject if necessary. All changes are logged.</p>
        
        <strong style="margin-top: 16px;"><i class="fas fa-chart-line"></i> Tracking</strong>
        <p>Track all requests with status updates. See who requested, when, and the current approval status.</p>
      </div>
    </div>
  </div>
  
  <!-- Requests List -->
  <div class="sr-requests-section">
    <div class="sr-section-title">
      <i class="fas fa-list-check"></i>All Requests
    </div>
    
    <?php if(empty($requests)): ?>
      <div class="sr-empty">
        <div class="sr-empty-icon"><i class="fas fa-inbox"></i></div>
        <div class="sr-empty-title">No Requests</div>
        <div class="sr-empty-text">No stock requests yet. Staff can start submitting requests above.</div>
      </div>
    <?php else: ?>
      <?php foreach($requests as $r): ?>
        <div class="sr-request-item">
          <div class="sr-req-content">
            <div class="sr-req-title">
              <i class="fas <?php echo $r['type'] === 'fuel' ? 'fa-gas-pump' : 'fa-shopping-bag'; ?> sr-req-type-icon sr-req-type-<?php echo $r['type']; ?>"></i>
              <?php echo htmlspecialchars($r['product_name']); ?>
            </div>
            
            <div class="sr-req-meta">
              <div class="sr-req-meta-item">
                <div class="sr-req-meta-label">Quantity</div>
                <div class="sr-req-meta-value"><?php echo number_format($r['qty'], 2); ?></div>
              </div>
              <div class="sr-req-meta-item">
                <div class="sr-req-meta-label">Status</div>
                <div class="sr-req-meta-value">
                  <span class="sr-status sr-status-<?php echo $r['status']; ?>">
                    <?php echo ucfirst($r['status']); ?>
                  </span>
                </div>
              </div>
              <div class="sr-req-meta-item">
                <div class="sr-req-meta-label">By</div>
                <div class="sr-req-meta-value"><?php echo htmlspecialchars($r['requester_name'] ?? '—'); ?></div>
              </div>
              <div class="sr-req-meta-item">
                <div class="sr-req-meta-label">Date</div>
                <div class="sr-req-meta-value"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></div>
              </div>
            </div>
          </div>
          
          <div class="sr-actions">
            <?php if($r['status'] === 'pending' && $role === 'manager'): ?>
              <form method="post">
                <input type="hidden" name="action" value="approve_request">
                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                <button type="submit" class="sr-btn-sm sr-btn-approve"><i class="fas fa-check"></i>Approve</button>
              </form>
              <form method="post">
                <input type="hidden" name="action" value="reject_request">
                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                <button type="submit" class="sr-btn-sm sr-btn-reject"><i class="fas fa-times"></i>Reject</button>
              </form>
            <?php elseif($r['status'] !== 'pending'): ?>
              <div style="color: #94a3b8; font-size: 13px;">No actions</div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
