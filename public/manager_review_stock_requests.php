<?php
$page_id = 'manager_review_stock_requests';
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

// Handle manager feedback/notes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_review') {
        $rid = (int)($_POST['request_id'] ?? 0);
        $notes = trim($_POST['review_notes'] ?? '');
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id=?");
            $stmt->execute([$rid]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($req && !empty($notes)) {
                // Log manager review in activity logs
                log_activity($pdo, $me['id'], 'Review Stock Request', "Stock Request #$rid | Product: {$req['product_name']} | Qty: {$req['qty']} | Review: $notes");
                $msg = "✅ Review recorded. Awaiting Admin approval.";
            }
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    }
}

// Fetch pending stock requests for manager to review
$requests = [];
try {
    if ($role === 'superadmin') {
        $stmt = $pdo->query("
            SELECT sr.*, s.name as station_name, u.name as submitted_by_name
            FROM stock_requests sr
            LEFT JOIN stations s ON sr.station_id = s.id
            LEFT JOIN users u ON sr.submitted_by = u.id
            WHERE sr.status = 'pending'
            ORDER BY sr.submitted_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT sr.*, s.name as station_name, u.name as submitted_by_name
            FROM stock_requests sr
            LEFT JOIN stations s ON sr.station_id = s.id
            LEFT JOIN users u ON sr.submitted_by = u.id
            WHERE sr.status = 'pending' AND sr.station_id = ?
            ORDER BY sr.submitted_at DESC
        ");
        $stmt->execute([$station_id]);
    }
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $requests = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f8fafc; }
  
  .mr-wrapper { max-width: 1200px; margin: 0 auto; padding: 24px; }
  
  .mr-header { 
    background: linear-gradient(135deg, #059669 0%, #047857 100%); 
    color: white; padding: 40px 32px; border-radius: 16px; margin-bottom: 32px; 
    box-shadow: 0 8px 24px rgba(5,150,105,0.3); 
  }
  .mr-header-content { display: flex; align-items: center; gap: 16px; }
  .mr-header-icon { font-size: 42px; }
  .mr-header h1 { font-size: 32px; font-weight: 700; margin-bottom: 6px; }
  .mr-header p { font-size: 14px; opacity: 0.85; }
  
  .mr-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
  .mr-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .mr-alert-error { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
  
  .mr-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
  
  .mr-card { 
    background: white; border-radius: 12px; padding: 20px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #059669; 
  }
  .mr-card-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px; }
  .mr-card-title { font-size: 18px; font-weight: 700; color: #0f172a; }
  .mr-card-badge { 
    background: #dcfce7; color: #166534; padding: 4px 12px; 
    border-radius: 20px; font-size: 12px; font-weight: 600; 
  }
  
  .mr-card-body { display: grid; gap: 12px; }
  .mr-info { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
  .mr-info-label { color: #64748b; font-size: 13px; font-weight: 600; }
  .mr-info-value { color: #0f172a; font-weight: 500; }
  
  .mr-form { display: grid; gap: 12px; margin-top: 16px; padding-top: 16px; border-top: 2px solid #f1f5f9; }
  .mr-form label { font-size: 13px; font-weight: 600; color: #334155; }
  .mr-form textarea { 
    width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; 
    font-family: inherit; font-size: 13px; resize: none; height: 80px;
  }
  .mr-form textarea:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5,150,105,0.1); }
  
  .mr-btn-group { display: flex; gap: 10px; }
  .mr-btn { 
    flex: 1; padding: 10px 16px; border: 0; border-radius: 8px; 
    cursor: pointer; font-weight: 600; font-size: 13px; 
  }
  .mr-btn-submit { background: #059669; color: white; }
  .mr-btn-submit:hover { background: #047857; }
  
  .mr-empty { text-align: center; padding: 60px 20px; color: #94a3b8; }
  .mr-empty-icon { font-size: 48px; margin-bottom: 12px; }
</style>

<div class="mr-wrapper">
  <div class="mr-header">
    <div class="mr-header-content">
      <div class="mr-header-icon">✓</div>
      <div>
        <h1>Review Stock Requests</h1>
        <p>Manager - Review requests submitted by Operations Staff</p>
      </div>
    </div>
  </div>
  
  <?php if($msg): ?>
    <div class="mr-alert <?php echo strpos($msg, '✅') !== false ? 'mr-alert-success' : 'mr-alert-error'; ?>">
      <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
      <?php echo htmlspecialchars($msg); ?>
    </div>
  <?php endif; ?>
  
  <?php if(empty($requests)): ?>
    <div class="mr-empty">
      <div class="mr-empty-icon">📋</div>
      <div style="font-size: 16px; font-weight: 500;">No pending stock requests</div>
      <div style="font-size: 13px; margin-top: 6px;">All requests have been processed or approved by Admin.</div>
    </div>
  <?php else: ?>
    <div class="mr-cards">
      <?php foreach($requests as $req): ?>
        <div class="mr-card">
          <div class="mr-card-header">
            <div>
              <div class="mr-card-title"><?php echo htmlspecialchars($req['product_name']); ?></div>
              <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Req #<?php echo $req['id']; ?></div>
            </div>
            <div class="mr-card-badge">PENDING</div>
          </div>
          
          <div class="mr-card-body">
            <div class="mr-info">
              <div class="mr-info-label">Type</div>
              <div class="mr-info-value"><?php echo ucfirst($req['type']); ?></div>
            </div>
            <div class="mr-info">
              <div class="mr-info-label">Quantity</div>
              <div class="mr-info-value"><?php echo number_format($req['qty'], 0); ?> units</div>
            </div>
            <div class="mr-info">
              <div class="mr-info-label">Submitted By</div>
              <div class="mr-info-value"><?php echo htmlspecialchars($req['submitted_by_name'] ?? 'Unknown'); ?></div>
            </div>
            <div class="mr-info">
              <div class="mr-info-label">Date</div>
              <div class="mr-info-value"><?php echo date('M d, Y', strtotime($req['submitted_at'])); ?></div>
            </div>
            <?php if($req['notes']): ?>
            <div style="background: #f0fdf4; border-left: 3px solid #059669; padding: 10px; border-radius: 4px; font-size: 13px;">
              <strong style="color: #047857;">Notes:</strong> <?php echo htmlspecialchars($req['notes']); ?>
            </div>
            <?php endif; ?>
            
            <form method="post" class="mr-form">
              <input type="hidden" name="action" value="add_review">
              <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
              
              <label>Your Review & Feedback</label>
              <textarea name="review_notes" placeholder="e.g., Quantity seems reasonable. Approve for Admin review." required></textarea>
              
              <button type="submit" class="mr-btn mr-btn-submit">
                <i class="fas fa-check"></i> Record Review
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  
  <div style="margin-top: 40px; padding: 20px; background: #f0fdf4; border-left: 4px solid #059669; border-radius: 8px;">
    <strong style="color: #047857;">📌 Manager Role:</strong>
    <ul style="margin-top: 8px; margin-left: 20px; color: #047857; font-size: 13px; line-height: 1.8;">
      <li>Review stock requests from Operations Staff</li>
      <li>Verify quantity is justified and aligned with operational needs</li>
      <li>Record your review/feedback for Admin to consider</li>
      <li>You do NOT have final approval authority</li>
      <li>Admin will make the final decision and generate Purchase Orders</li>
    </ul>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
