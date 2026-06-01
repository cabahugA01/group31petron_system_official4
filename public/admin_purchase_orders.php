<?php
$page_id = 'admin_purchase_orders';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['admin','superadmin'])) {
    header('Location: dashboard.php'); exit;
}

// Ensure admin finalization columns exist
foreach ([
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_id INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_done TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_by INT NULL",
] as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

$flash_ok  = $_SESSION['ok']  ?? null; unset($_SESSION['ok']);
$flash_err = $_SESSION['err'] ?? null; unset($_SESSION['err']);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act   = $_POST['action'] ?? '';
    $po_id = (int)($_POST['po_id'] ?? 0);

    if ($act === 'finalize' && $po_id > 0) {
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        try {
            $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND station_id=? AND status='Pending Admin Validation' AND admin_finalized=0");
            $stmt->execute([$po_id, $station_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$po) throw new Exception('PO not found or already finalized.');
            $pdo->prepare("UPDATE purchase_orders SET admin_finalized=1, admin_id=?, admin_notes=?, admin_finalized_at=NOW(), status='Admin Finalized' WHERE id=?")
                ->execute([$me['id'], $admin_notes, $po_id]);
            if (function_exists('log_activity'))
                log_activity($pdo, $me['id'], 'Admin Finalize PO', 'PO #'.$po['po_number'].' finalized. Ready for delivery & stock-in.');
            $_SESSION['ok'] = 'PO #'.$po['po_number'].' finalized. Forwarded to Deliveries & Stock-In.';
        } catch (Exception $e) { $_SESSION['err'] = $e->getMessage(); }
        header('Location: admin_purchase_orders.php'); exit;
    }

    if ($act === 'reject' && $po_id > 0) {
        $reason = trim($_POST['reject_reason'] ?? '');
        if (empty($reason)) { $_SESSION['err'] = 'Rejection reason is required.'; header('Location: admin_purchase_orders.php'); exit; }
        try {
            $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND station_id=?");
            $stmt->execute([$po_id, $station_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$po) throw new Exception('PO not found.');
            $pdo->prepare("UPDATE purchase_orders SET status='Rejected by Admin', admin_notes=?, admin_id=?, updated_at=NOW() WHERE id=?")
                ->execute([$reason, $me['id'], $po_id]);
            if (function_exists('log_activity'))
                log_activity($pdo, $me['id'], 'Admin Reject PO', 'PO #'.$po['po_number'].' rejected. Reason: '.$reason);
            $_SESSION['ok'] = 'PO #'.$po['po_number'].' rejected.';
        } catch (Exception $e) { $_SESSION['err'] = $e->getMessage(); }
        header('Location: admin_purchase_orders.php'); exit;
    }
}

// Fetch POs pending admin validation
$pending_pos = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, u_mgr.name AS manager_name, u_adm.name AS admin_name,
               sr.item_sku, sr.item_category, sr.remarks AS sr_remarks, sr.current_stock
        FROM purchase_orders po
        LEFT JOIN users u_mgr ON po.created_by = u_mgr.id
        LEFT JOIN users u_adm ON po.admin_id = u_adm.id
        LEFT JOIN stock_requests sr ON po.request_id = sr.id
        WHERE po.station_id = ? AND po.type = 'merch'
          AND po.status = 'Pending Admin Validation' AND po.admin_finalized = 0
        ORDER BY po.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $pending_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch finalized POs (history)
$finalized_pos = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, u_mgr.name AS manager_name, u_adm.name AS admin_name
        FROM purchase_orders po
        LEFT JOIN users u_mgr ON po.created_by = u_mgr.id
        LEFT JOIN users u_adm ON po.admin_id = u_adm.id
        WHERE po.station_id = ? AND po.type = 'merch' AND po.admin_finalized = 1
        ORDER BY po.admin_finalized_at DESC LIMIT 50
    ");
    $stmt->execute([$station_id]);
    $finalized_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F70;--green:#28a745;--red:#dc3545;--gray:#6c757d;}
.po-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid #e9ecef;margin-bottom:18px;overflow:hidden;}
.po-card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e9ecef;flex-wrap:wrap;gap:8px;}
.po-card-title{font-size:1rem;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.po-card-body{padding:18px 20px;}
.po-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;}
.po-item{background:#fff;border-radius:10px;border:1px solid #dee2e6;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);transition:box-shadow .15s;}
.po-item:hover{box-shadow:0 4px 14px rgba(0,0,0,.1);}
.po-item-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;}
.po-number{font-size:1rem;font-weight:700;color:var(--blue);}
.po-meta{display:grid;gap:6px;font-size:13px;margin-bottom:14px;}
.po-meta-row{display:flex;gap:8px;}
.po-meta-label{color:var(--gray);min-width:110px;font-weight:600;}
.po-meta-val{color:#222;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;white-space:nowrap;}
.badge-pending{background:#fff3cd;color:#856404;}
.badge-finalized{background:#d4edda;color:#155724;}
.badge-rejected{background:#f8d7da;color:#721c24;}
.badge-stockin{background:#cce5ff;color:#004085;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .2s;text-decoration:none;}
.btn-finalize{background:var(--blue);color:#fff;}.btn-finalize:hover{background:#001F4F;}
.btn-reject{background:var(--red);color:#fff;}.btn-reject:hover{background:#c82333;}
.btn-print{background:#6c757d;color:#fff;}.btn-print:hover{background:#545b62;}
.btn-sm{padding:5px 12px;font-size:12px;}
.flash-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.flash-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.empty-state{text-align:center;padding:48px;color:var(--gray);}
.empty-state i{font-size:3rem;display:block;margin-bottom:12px;opacity:.3;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:28px;width:500px;max-width:96vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-box h3{margin:0 0 16px;font-size:1.05rem;color:var(--blue);display:flex;align-items:center;gap:8px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:12px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.form-group textarea,.form-group input{width:100%;padding:9px 11px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;}
.form-group textarea:focus,.form-group input:focus{outline:none;border-color:var(--blue);}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;}
.info-box{background:#e8f4fd;border-left:4px solid var(--blue);border-radius:6px;padding:10px 14px;font-size:12px;color:var(--blue);line-height:1.6;margin-bottom:14px;}
.tab-nav{display:flex;gap:0;border-bottom:2px solid #e9ecef;margin-bottom:22px;}
.tab-btn{padding:10px 24px;background:none;border:none;border-bottom:3px solid transparent;font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;margin-bottom:-2px;transition:all .15s;}
.tab-btn.active{color:var(--blue);border-bottom-color:var(--blue);}
.tab-btn:hover{color:var(--blue);}
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-file-invoice"></i> Purchase Orders Oversight</h1>
    <div class="sub">Review Manager-approved POs &mdash; finalize for supplier, then forward to Deliveries &amp; Stock-In.</div>
  </div>
  <div class="header-actions">
    <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
  </div>
</div>

<?php if ($flash_ok): ?>
<div class="flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_err): ?>
<div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_err) ?></div>
<?php endif; ?>

<div class="tab-nav">
  <button class="tab-btn active" id="tabPendingBtn" onclick="switchTab('pending',this)">
    <i class="fas fa-clock"></i> Pending Finalization
    <?php if (count($pending_pos) > 0): ?>
      <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;margin-left:4px;"><?= count($pending_pos) ?></span>
    <?php endif; ?>
  </button>
  <button class="tab-btn" id="tabHistoryBtn" onclick="switchTab('history',this)">
    <i class="fas fa-history"></i> Finalized History
  </button>
</div>

<!-- PENDING TAB -->
<div id="tab-pending">
  <?php if (empty($pending_pos)): ?>
    <div class="empty-state">
      <i class="fas fa-check-circle" style="color:#28a745;opacity:.5;"></i>
      <strong>No POs pending finalization.</strong><br>
      <span style="font-size:13px;">All Manager-approved POs have been processed.</span>
    </div>
  <?php else: ?>
    <div class="po-grid">
      <?php foreach ($pending_pos as $po): ?>
      <div class="po-item">
        <div class="po-item-header">
          <div class="po-number"><?= htmlspecialchars($po['po_number']) ?></div>
          <span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span>
        </div>
        <div class="po-meta">
          <div class="po-meta-row"><span class="po-meta-label">Product:</span><span class="po-meta-val"><strong><?= htmlspecialchars($po['product_name']) ?></strong></span></div>
          <div class="po-meta-row"><span class="po-meta-label">SKU:</span><span class="po-meta-val"><?= htmlspecialchars($po['item_sku'] ?? '&mdash;') ?></span></div>
          <div class="po-meta-row"><span class="po-meta-label">Category:</span><span class="po-meta-val"><?= htmlspecialchars($po['item_category'] ?? '&mdash;') ?></span></div>
          <div class="po-meta-row"><span class="po-meta-label">Qty Ordered:</span><span class="po-meta-val"><strong><?= (int)$po['quantity'] ?></strong> units</span></div>
          <div class="po-meta-row"><span class="po-meta-label">Unit Price:</span><span class="po-meta-val">&#8369;<?= number_format((float)$po['unit_price'],2) ?></span></div>
          <div class="po-meta-row"><span class="po-meta-label">Total Amount:</span><span class="po-meta-val" style="color:#28a745;font-weight:700;">&#8369;<?= number_format((float)$po['total_amount'],2) ?></span></div>
          <div class="po-meta-row"><span class="po-meta-label">Manager:</span><span class="po-meta-val"><?= htmlspecialchars($po['manager_name'] ?? '&mdash;') ?></span></div>
          <div class="po-meta-row"><span class="po-meta-label">Date Created:</span><span class="po-meta-val"><?= date('M d, Y H:i', strtotime($po['created_at'])) ?></span></div>
          <?php if (!empty($po['sr_remarks'])): ?>
          <div class="po-meta-row"><span class="po-meta-label">Staff Remarks:</span><span class="po-meta-val" style="color:#6c757d;"><?= htmlspecialchars($po['sr_remarks']) ?></span></div>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="btn btn-finalize btn-sm"
            onclick="openFinalize(<?= $po['id'] ?>, '<?= htmlspecialchars($po['po_number'],ENT_QUOTES) ?>', '<?= htmlspecialchars($po['product_name'],ENT_QUOTES) ?>', <?= (int)$po['quantity'] ?>)">
            <i class="fas fa-check-double"></i> Finalize &amp; Forward
          </button>
          <button class="btn btn-reject btn-sm"
            onclick="openReject(<?= $po['id'] ?>, '<?= htmlspecialchars($po['po_number'],ENT_QUOTES) ?>')">
            <i class="fas fa-times"></i> Reject
          </button>
          <a href="print_po_new.php?id=<?= $po['id'] ?>&type=merch" target="_blank" class="btn btn-print btn-sm">
            <i class="fas fa-print"></i> Print PO
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- HISTORY TAB -->
<div id="tab-history" style="display:none;">
  <?php if (empty($finalized_pos)): ?>
    <div class="empty-state">
      <i class="fas fa-history"></i>
      <strong>No finalized POs yet.</strong>
    </div>
  <?php else: ?>
    <div class="po-card">
      <div class="po-card-head">
        <div class="po-card-title"><i class="fas fa-history"></i> Finalized POs</div>
      </div>
      <div class="po-card-body">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>PO Number</th><th>Product</th><th>Qty</th><th>Total</th>
                <th>Manager</th><th>Admin</th><th>Finalized At</th><th>Stock-In</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($finalized_pos as $po): ?>
              <tr>
                <td><strong><?= htmlspecialchars($po['po_number']) ?></strong></td>
                <td><?= htmlspecialchars($po['product_name']) ?></td>
                <td><?= (int)$po['quantity'] ?></td>
                <td>&#8369;<?= number_format((float)$po['total_amount'],2) ?></td>
                <td><?= htmlspecialchars($po['manager_name'] ?? '&mdash;') ?></td>
                <td><?= htmlspecialchars($po['admin_name'] ?? '&mdash;') ?></td>
                <td><?= $po['admin_finalized_at'] ? date('M d, Y H:i', strtotime($po['admin_finalized_at'])) : '&mdash;' ?></td>
                <td>
                  <?php if ($po['stock_in_done']): ?>
                    <span class="badge badge-stockin"><i class="fas fa-check"></i> Done</span>
                  <?php else: ?>
                    <span class="badge badge-pending">Pending</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- FINALIZE MODAL -->
<div class="modal-overlay" id="finalizeModal">
  <div class="modal-box">
    <h3><i class="fas fa-check-double" style="color:#28a745;"></i> Finalize Purchase Order</h3>
    <div class="info-box">
      <i class="fas fa-info-circle"></i> <strong>On Finalize:</strong><br>
      &bull; PO status &rarr; <strong>Admin Finalized</strong><br>
      &bull; PO forwarded to <strong>Deliveries Oversight</strong><br>
      &bull; Staff can now encode <strong>Stock-In</strong> upon delivery<br>
      &bull; Inventory is <strong>NOT updated yet</strong> &mdash; only after Stock-In
    </div>
    <div id="finalizePoInfo" style="background:#f8f9fa;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;"></div>
    <form method="post" action="admin_purchase_orders.php">
      <input type="hidden" name="action" value="finalize">
      <input type="hidden" name="po_id" id="finalizePOId">
      <div class="form-group">
        <label>Admin Notes (Optional)</label>
        <textarea name="admin_notes" rows="3" placeholder="e.g., Approved for delivery. Contact supplier for schedule."></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" onclick="closeModal('finalizeModal')" style="padding:9px 20px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">Cancel</button>
        <button type="submit" class="btn btn-finalize"><i class="fas fa-check-double"></i> Finalize &amp; Forward</button>
      </div>
    </form>
  </div>
</div>

<!-- REJECT MODAL -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal-box">
    <h3><i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Purchase Order</h3>
    <div id="rejectPoInfo" style="background:#f8f9fa;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;"></div>
    <form method="post" action="admin_purchase_orders.php">
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="po_id" id="rejectPOId">
      <div class="form-group">
        <label>Rejection Reason <span style="color:#dc3545;">*</span></label>
        <textarea name="reject_reason" rows="3" placeholder="Required: explain why this PO is being rejected..." required></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" onclick="closeModal('rejectModal')" style="padding:9px 20px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">Cancel</button>
        <button type="submit" class="btn btn-reject"><i class="fas fa-times"></i> Reject PO</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchTab(tab, btn) {
    document.getElementById('tab-pending').style.display = tab === 'pending' ? 'block' : 'none';
    document.getElementById('tab-history').style.display = tab === 'history' ? 'block' : 'none';
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
}
function openFinalize(id, poNum, product, qty) {
    document.getElementById('finalizePOId').value = id;
    document.getElementById('finalizePoInfo').innerHTML =
        '<strong>PO:</strong> ' + poNum + ' &nbsp;|&nbsp; <strong>Product:</strong> ' + product + ' &nbsp;|&nbsp; <strong>Qty:</strong> ' + qty + ' units';
    document.getElementById('finalizeModal').classList.add('show');
}
function openReject(id, poNum) {
    document.getElementById('rejectPOId').value = id;
    document.getElementById('rejectPoInfo').innerHTML = '<strong>PO:</strong> ' + poNum;
    document.getElementById('rejectModal').classList.add('show');
}
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('show'); });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
