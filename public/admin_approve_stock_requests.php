<?php
$page_id = 'admin_approve_stock_requests';
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

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (in_array($action, ['approve_request', 'reject_request'])) {
        $rid = (int)($_POST['request_id'] ?? 0);
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id=?");
            $stmt->execute([$rid]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$req) {
                $msg = "❌ Request not found.";
            } elseif ($req['status'] !== 'pending') {
                $msg = "❌ Request already processed.";
            } else {
                if ($action === 'reject_request') {
                    $reason = trim($_POST['reject_reason'] ?? '');
                    $stmt = $pdo->prepare("UPDATE stock_requests SET status='rejected', notes=?, processed_by=?, processed_at=NOW() WHERE id=?");
                    $stmt->execute([$reason, (int)$me['id'], $rid]);
                    log_activity($pdo, $me['id'], 'Reject Stock Request', "Stock Request #$rid | Product: {$req['product_name']} | Qty: {$req['qty']} | Reason: $reason");
                    $msg = "✅ Request rejected. Operations Staff will be notified.";
                } else {
                    // Approve and generate PO
                    $stmt = $pdo->prepare("UPDATE stock_requests SET status='approved', processed_by=?, processed_at=NOW() WHERE id=?");
                    $stmt->execute([(int)$me['id'], $rid]);
                    
                    // Generate PO
                    $po_number = 'PO-' . date('YmdHis') . '-' . str_pad($rid, 4, '0', STR_PAD_LEFT);
                    $stmt_po = $pdo->prepare("INSERT INTO purchase_orders (request_id, po_number, product_name, quantity, type, station_id, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending_supplier', ?, NOW())");
                    $stmt_po->execute([$rid, $po_number, $req['product_name'], $req['qty'], $req['type'], $req['station_id'], (int)$me['id']]);
                    
                    log_activity($pdo, $me['id'], 'Approve Stock Request', "Stock Request #$rid | APPROVED & PO Generated #$po_number | Product: {$req['product_name']} | Qty: {$req['qty']}");
                    $msg = "✅ Request approved! PO #$po_number generated and will be sent to supplier.";
                }
            }
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    }
}

// Fetch pending stock requests with manager feedback
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

// Get manager reviews from activity logs
$reviews = [];
try {
    $stmt = $pdo->query("SELECT * FROM activity_logs WHERE action = 'Review Stock Request' ORDER BY created_at DESC LIMIT 100");
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reviews = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f8fafc; }
  
  .aa-wrapper { max-width: 1200px; margin: 0 auto; padding: 24px; }
  
  .aa-header { 
    background: linear-gradient(135deg, #003d7a 0%, #002d5c 100%); 
    color: white; padding: 40px 32px; border-radius: 16px; margin-bottom: 32px; 
    box-shadow: 0 8px 24px rgba(0,61,122,0.3); 
  }
  .aa-header-content { display: flex; align-items: center; gap: 16px; }
  .aa-header-icon { font-size: 42px; }
  .aa-header h1 { font-size: 32px; font-weight: 700; margin-bottom: 6px; color: white !important; }
  .aa-header p { font-size: 14px; opacity: 0.85; color: white !important; }
  
  .aa-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
  .aa-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .aa-alert-error { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
  
  .aa-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 20px; }
  
  .aa-card { 
    background: white; border-radius: 12px; padding: 24px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-top: 4px solid #003d7a; 
  }
  .aa-card-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 18px; }
  .aa-card-title { font-size: 18px; font-weight: 700; color: #0f172a; }
  .aa-card-badge { 
    background: #e8f1f8; color: #003d7a; padding: 4px 12px; 
    border-radius: 20px; font-size: 12px; font-weight: 600; 
  }
  
  .aa-card-body { display: grid; gap: 12px; }
  .aa-info { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
  .aa-info-label { color: #64748b; font-size: 13px; font-weight: 600; }
  .aa-info-value { color: #0f172a; font-weight: 500; }
  
  .aa-review { background: #e8f1f8; border-left: 3px solid #003d7a; padding: 12px; border-radius: 6px; margin: 12px 0; }
  .aa-review-label { font-size: 12px; color: #003d7a; font-weight: 600; }
  .aa-review-text { font-size: 13px; color: #002d5c; margin-top: 4px; }
  
  .aa-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 16px; }
  .aa-btn { 
    padding: 10px 16px; border: 0; border-radius: 8px; 
    cursor: pointer; font-weight: 600; font-size: 13px; 
  }
  .aa-btn-approve { background: #059669; color: white; }
  .aa-btn-approve:hover { background: #047857; }
  .aa-btn-reject { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
  .aa-btn-reject:hover { background: #e5e7eb; }
  
  .aa-empty { text-align: center; padding: 60px 20px; color: #94a3b8; }
  .aa-empty-icon { font-size: 48px; margin-bottom: 12px; }
  
  .aa-modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
  .aa-modal.active { display: flex; }
  .aa-modal-content { background: white; border-radius: 12px; padding: 24px; max-width: 400px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
  .aa-modal-title { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 16px; }
  .aa-modal-form { display: grid; gap: 16px; }
  .aa-modal-form label { font-size: 13px; font-weight: 600; color: #334155; }
  .aa-modal-form textarea { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; resize: none; height: 100px; }
  .aa-modal-form textarea:focus { outline: none; border-color: #003d7a; }
  .aa-modal-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 16px; }
  .aa-modal-btn { padding: 10px 16px; border: 0; border-radius: 8px; cursor: pointer; font-weight: 600; }
  .aa-modal-btn-submit { background: #003d7a; color: white; }
  .aa-modal-btn-cancel { background: #f3f4f6; color: #374151; }
</style>

<div class="aa-wrapper">
  <div class="aa-header">
    <div class="aa-header-content">
      <div class="aa-header-icon">👑</div>
      <div>
        <h1>Approve Stock Requests & Generate PO</h1>
        <p>Admin/Owner - Final approval for purchase orders</p>
      </div>
    </div>
  </div>
  
  <?php if($msg): ?>
    <div class="aa-alert <?php echo strpos($msg, '✅') !== false ? 'aa-alert-success' : 'aa-alert-error'; ?>">
      <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
      <?php echo htmlspecialchars($msg); ?>
    </div>
  <?php endif; ?>
  
  <?php if(empty($requests)): ?>
    <div class="aa-empty">
      <div class="aa-empty-icon">✓</div>
      <div style="font-size: 16px; font-weight: 500;">No pending stock requests</div>
      <div style="font-size: 13px; margin-top: 6px;">All requests have been processed. Great work!</div>
    </div>
  <?php else: ?>
    <div class="aa-cards">
      <?php foreach($requests as $req): ?>
        <div class="aa-card">
          <div class="aa-card-header">
            <div>
              <div class="aa-card-title"><?php echo htmlspecialchars($req['product_name']); ?></div>
              <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Req #<?php echo $req['id']; ?></div>
            </div>
            <div class="aa-card-badge">PENDING</div>
          </div>
          
          <div class="aa-card-body">
            <div class="aa-info">
              <div class="aa-info-label">Type</div>
              <div class="aa-info-value"><?php echo ucfirst($req['type']); ?></div>
            </div>
            <div class="aa-info">
              <div class="aa-info-label">Quantity</div>
              <div class="aa-info-value"><?php echo number_format($req['qty'], 0); ?> units</div>
            </div>
            <div class="aa-info">
              <div class="aa-info-label">Requested By</div>
              <div class="aa-info-value"><?php echo htmlspecialchars($req['submitted_by_name'] ?? 'Unknown'); ?></div>
            </div>
            <div class="aa-info">
              <div class="aa-info-label">Date</div>
              <div class="aa-info-value"><?php echo date('M d, Y', strtotime($req['submitted_at'])); ?></div>
            </div>
            
            <?php if($req['notes']): ?>
            <div class="aa-review">
              <div class="aa-review-label">Staff Notes:</div>
              <div class="aa-review-text"><?php echo htmlspecialchars($req['notes']); ?></div>
            </div>
            <?php endif; ?>
            
            <?php
            // Find manager review for this request
            $manager_feedback = null;
            foreach ($reviews as $rev) {
              if (strpos($rev['details'], "#" . $req['id']) !== false) {
                $manager_feedback = $rev['details'];
                break;
              }
            }
            if ($manager_feedback):
            ?>
            <div class="aa-review">
              <div class="aa-review-label">Manager Review:</div>
              <div class="aa-review-text"><?php echo htmlspecialchars($manager_feedback); ?></div>
            </div>
            <?php endif; ?>
            
            <div class="aa-actions">
              <button type="button" class="aa-btn aa-btn-approve" onclick="approveRequest(<?php echo $req['id']; ?>)">
                <i class="fas fa-check"></i> Approve & Generate PO
              </button>
              <button type="button" class="aa-btn aa-btn-reject" onclick="openRejectModal(<?php echo $req['id']; ?>)">
                <i class="fas fa-times"></i> Reject
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  
  <div style="margin-top: 40px; padding: 20px; background: #e8f1f8; border-left: 4px solid #003d7a; border-radius: 8px;">
    <strong style="color: #003d7a;">👑 Admin Role:</strong>
    <ul style="margin-top: 8px; margin-left: 20px; color: #003d7a; font-size: 13px; line-height: 1.8;">
      <li>Review stock requests and manager feedback</li>
      <li>Make final approval decision</li>
      <li>Approve → Generates Purchase Order (PO) for supplier</li>
      <li>Reject → Returns request to Operations Staff with reason</li>
      <li>Once approved, PO is sent to supplier automatically</li>
    </ul>
  </div>
</div>

<!-- Reject Modal -->
<div class="aa-modal" id="rejectModal">
  <div class="aa-modal-content">
    <div class="aa-modal-title">Reject Stock Request</div>
    <form method="post" class="aa-modal-form">
      <input type="hidden" name="action" value="reject_request">
      <input type="hidden" name="request_id" id="modalRequestId" value="">
      
      <label>Reason for Rejection *</label>
      <textarea name="reject_reason" placeholder="e.g., Quantity too high for current needs" required></textarea>
      
      <div class="aa-modal-actions">
        <button type="button" class="aa-modal-btn aa-modal-btn-cancel" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="aa-modal-btn aa-modal-btn-submit">Reject</button>
      </div>
    </form>
  </div>
</div>

<script>
function approveRequest(id) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
    <input type="hidden" name="action" value="approve_request">
    <input type="hidden" name="request_id" value="${id}">
  `;
  document.body.appendChild(form);
  form.submit();
}

function openRejectModal(id) {
  document.getElementById('modalRequestId').value = id;
  document.getElementById('rejectModal').classList.add('active');
}

function closeRejectModal() {
  document.getElementById('rejectModal').classList.remove('active');
}

document.getElementById('rejectModal').addEventListener('click', (e) => {
  if (e.target === document.getElementById('rejectModal')) closeRejectModal();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
