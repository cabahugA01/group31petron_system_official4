<?php
$page_id = "mgr_del_validate";
require_once __DIR__ . "/../backend/lib.php";
require_once __DIR__ . "/db_connect.php";
require_login();
$me = current_user();
$role = role_key($me["role"] ?? "");
$station_id = (int)user_station_id();
if (!in_array($role, ["manager","admin","superadmin"])) { header("Location: dashboard.php"); exit; }
foreach (["ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized TINYINT(1) NOT NULL DEFAULT 0","ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized_at DATETIME NULL","ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_done TINYINT(1) NOT NULL DEFAULT 0","ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_validated TINYINT(1) NOT NULL DEFAULT 0","ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_validated_at DATETIME NULL","ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_validated_by INT NULL","ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_flag ENUM('OK','Short','Damaged','Excess','Mixed') NULL","ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_notes TEXT NULL","ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS actual_qty_received INT NULL"] as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }
$flash_ok = $_SESSION["dv_ok"] ?? null; unset($_SESSION["dv_ok"]);
$flash_err = $_SESSION["dv_err"] ?? null; unset($_SESSION["dv_err"]);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $act = $_POST["action"] ?? "";
    $po_id = (int)($_POST["po_id"] ?? 0);
    if ($act === "validate" && $po_id > 0) {
        $actual_qty = (int)($_POST["actual_qty"] ?? 0);
        $flag = $_POST["delivery_flag"] ?? "OK";
        $notes = trim($_POST["delivery_notes"] ?? "");
        if (!in_array($flag, ["OK","Short","Damaged","Excess","Mixed"])) $flag = "OK";
        try {
            $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND station_id=? AND admin_finalized=1 AND delivery_validated=0");
            $stmt->execute([$po_id, $station_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$po) throw new Exception("PO not found or already validated.");
            $pdo->prepare("UPDATE purchase_orders SET delivery_validated=1,delivery_validated_at=NOW(),delivery_validated_by=?,delivery_flag=?,delivery_notes=?,actual_qty_received=?,updated_at=NOW() WHERE id=?")->execute([$me["id"],$flag,$notes,$actual_qty,$po_id]);
            if (function_exists("log_activity")) log_activity($pdo,$me["id"],"Validate Delivery","PO #{$po['po_number']} | {$po['product_name']} | Flag:{$flag} | Actual:{$actual_qty}");
            $_SESSION["dv_ok"] = "Delivery for PO #{$po['po_number']} validated. Flag: {$flag}. Staff can now encode Stock-In.";
        } catch (Exception $e) { $_SESSION["dv_err"] = $e->getMessage(); }
        header("Location: manager_delivery_validation.php"); exit;
    }
    if ($act === "flag_issue" && $po_id > 0) {
        $flag = $_POST["delivery_flag"] ?? "Short";
        $notes = trim($_POST["delivery_notes"] ?? "");
        if (empty($notes)) { $_SESSION["dv_err"] = "Notes required when flagging."; header("Location: manager_delivery_validation.php"); exit; }
        try {
            $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND station_id=?");
            $stmt->execute([$po_id,$station_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$po) throw new Exception("PO not found.");
            $pdo->prepare("UPDATE purchase_orders SET delivery_flag=?,delivery_notes=?,updated_at=NOW() WHERE id=?")->execute([$flag,$notes,$po_id]);
            $_SESSION["dv_ok"] = "Issue flagged for PO #{$po['po_number']}.";
        } catch (Exception $e) { $_SESSION["dv_err"] = $e->getMessage(); }
        header("Location: manager_delivery_validation.php"); exit;
    }
}
$pending_pos = [];
try {
    $stmt = $pdo->prepare("SELECT po.*,u_mgr.name AS manager_name,u_adm.name AS admin_name,sr.item_sku,sr.item_category,sr.remarks AS sr_remarks,sr.current_stock FROM purchase_orders po LEFT JOIN users u_mgr ON po.created_by=u_mgr.id LEFT JOIN users u_adm ON po.admin_id=u_adm.id LEFT JOIN stock_requests sr ON po.request_id=sr.id WHERE po.station_id=? AND po.type='merch' AND po.admin_finalized=1 AND po.delivery_validated=0 AND po.stock_in_done=0 ORDER BY po.admin_finalized_at ASC");
    $stmt->execute([$station_id]);
    $pending_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$validated_pos = [];
try {
    $stmt = $pdo->prepare("SELECT po.*,u_mgr.name AS manager_name,u_adm.name AS admin_name,u_val.name AS validated_by_name FROM purchase_orders po LEFT JOIN users u_mgr ON po.created_by=u_mgr.id LEFT JOIN users u_adm ON po.admin_id=u_adm.id LEFT JOIN users u_val ON po.delivery_validated_by=u_val.id WHERE po.station_id=? AND po.type='merch' AND po.delivery_validated=1 ORDER BY po.delivery_validated_at DESC LIMIT 50");
    $stmt->execute([$station_id]);
    $validated_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$active_tab = $_GET["tab"] ?? "pending";
include __DIR__ . "/../partials/header.php";
?>

<style>
/* ── Table wrapper ── */
.po-table-wrap { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.07); overflow-x:auto; }
/* ── Table ── */
.po-table { width:100%; border-collapse:collapse; font-size:0.88rem; }
.po-table thead th { background:#002F70; color:#fff; padding:12px 14px; text-align:left; font-weight:600; white-space:nowrap; }
.po-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background 0.15s; }
.po-table tbody tr:hover { background:#f5f8ff; }
.po-table tbody td { padding:11px 14px; vertical-align:middle; color:#333; }
/* ── Status badges — plain text, no background color ── */
.status-badge { display:inline-block; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; color:#333; }
.badge-pending   { color:#002F70; }
.badge-approved  { color:#28a745; }
.badge-rejected  { color:#dc3545; }
.badge-other     { color:#6c757d; }
/* Delivery flag badges */
.badge-ok      { color:#28a745; }
.badge-short   { color:#dc3545; }
.badge-damaged { color:#dc3545; }
.badge-excess  { color:#6c757d; }
.badge-mixed   { color:#6c757d; }
.badge-validated { color:#28a745; }
/* ── Action buttons ── */
.btn-action { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; border:none; border-radius:6px; cursor:pointer; font-size:0.82rem; font-weight:600; text-decoration:none; transition:opacity 0.2s; white-space:nowrap; margin-bottom:3px; }
.btn-action:hover { opacity:0.85; }
.btn-approve { background:#28a745; color:#fff; }
.btn-reject  { background:#dc3545; color:#fff; }
.btn-view    { background:#6c757d; color:#fff; }
.btn-primary { background:#002F70; color:#fff; }
/* ── Page header ── */
.page-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap; gap:6px; }
.page-head h1 { margin:0 0 2px; font-size:1.4rem; font-weight:700; color:#002F70; }
.page-head .sub { font-size:0.8rem; color:#6c757d; }
/* ── Alerts ── */
.alert { padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:0.9rem; font-weight:500; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.flash-ok  { display:flex; align-items:center; gap:8px; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:0.9rem; font-weight:500; background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.flash-err { display:flex; align-items:center; gap:8px; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:0.9rem; font-weight:500; background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
/* ── Empty state ── */
.empty-state { text-align:center; padding:70px 20px; color:#666; }
.empty-state i { font-size:3.5rem; color:#002F70; margin-bottom:18px; display:block; opacity:0.5; }
.empty-state h3 { font-size:1.2rem; font-weight:700; color:#333; margin:0 0 8px; }
.empty-state p { font-size:0.9rem; max-width:420px; margin:0 auto; line-height:1.6; }
/* ── Tabs ── */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e9ecef; margin-bottom:22px; }
.tab-btn { padding:10px 24px; background:none; border:none; border-bottom:3px solid transparent; font-size:14px; font-weight:600; color:#6c757d; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70; }
/* ── Card wrapper ── */
.dv-card { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.07); margin-bottom:20px; overflow:hidden; }
.dv-card-head { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.dv-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.dv-card-body { padding:0; }
/* ── Actions cell: stacked buttons ── */
.actions-cell { display:flex; flex-direction:column; gap:4px; min-width:130px; }
.actions-cell .btn-action { width:100%; justify-content:center; margin-bottom:0; }
/* ── Modal ── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1050; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal-box { background:#fff; border-radius:12px; width:90%; max-width:540px; max-height:90vh; overflow-y:auto; box-shadow:0 8px 32px rgba(0,0,0,0.18); padding:28px; }
.modal-box h3 { margin:0 0 16px; font-size:1.05rem; color:#002F70; display:flex; align-items:center; gap:8px; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:12px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
.form-group input, .form-group select, .form-group textarea { width:100%; padding:9px 11px; border:1px solid #ced4da; border-radius:6px; font-size:0.9rem; box-sizing:border-box; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline:none; border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,0.1); }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; }
</style>

<div class="page-head">
  <div>
    <h1><i class="fas fa-truck-loading"></i> Delivery Validation</h1>
    <div class="sub">Validate actual delivery vs PO when supplier arrives. Flag shortages, damages, or excess.</div>
  </div>
  <div class="header-actions">
    <a href="manager_delivery_validation.php" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</a>
  </div>
</div>

<?php if ($flash_ok): ?>
<div class="flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_err): ?>
<div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_err) ?></div>
<?php endif; ?>

<div class="tab-nav">
  <a href="manager_delivery_validation.php?tab=pending" class="tab-btn <?= $active_tab === 'pending' ? 'active' : '' ?>">
    <i class="fas fa-clock"></i> Awaiting Validation
    <?php if (count($pending_pos) > 0): ?>
      <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?= count($pending_pos) ?></span>
    <?php endif; ?>
  </a>
  <a href="manager_delivery_validation.php?tab=history" class="tab-btn <?= $active_tab === 'history' ? 'active' : '' ?>">
    <i class="fas fa-history"></i> Validated History
  </a>
</div>

<?php if ($active_tab === 'pending'): ?>
<!-- ══ PENDING TAB ══ -->

<?php if (empty($pending_pos)): ?>
  <div class="empty-state">
    <i class="fas fa-check-circle" style="color:#28a745;opacity:.5;"></i>
    <strong>No deliveries awaiting validation.</strong><br>
    <span style="font-size:13px;">All Admin-finalized POs have been validated, or no POs have been finalized yet.</span>
  </div>
<?php else: ?>
<div class="dv-card">
  <div class="dv-card-head">
    <div class="dv-card-title"><i class="fas fa-clock"></i> Awaiting Validation</div>
    <span style="font-size:12px;color:#6c757d;"><?= count($pending_pos) ?> record(s)</span>
  </div>
  <div class="dv-card-body">
    <div class="po-table-wrap">
      <table class="po-table">
        <thead>
          <tr>
            <th>PO Number</th>
            <th>Product</th>
            <th>SKU / Category</th>
            <th style="text-align:right;">PO Qty</th>
            <th style="text-align:right;">Unit Price</th>
            <th style="text-align:right;">Total</th>
            <th>Manager</th>
            <th>Admin</th>
            <th>Finalized</th>
            <th style="text-align:center;">Status</th>
            <th style="text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending_pos as $po): ?>
          <tr>
            <td><strong style="color:#002F70;font-family:monospace;"><?= htmlspecialchars($po['po_number'] ?? 'N/A') ?></strong></td>
            <td style="font-weight:600;"><?= htmlspecialchars($po['product_name'] ?? '—') ?></td>
            <td>
              <?php if (!empty($po['item_sku'])): ?><span style="font-family:monospace;font-size:11px;background:#e8f4fd;color:#002F70;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($po['item_sku']) ?></span><?php endif; ?>
              <?php if (!empty($po['item_category'])): ?><span style="display:block;font-size:11px;color:#6c757d;margin-top:3px;"><?= htmlspecialchars($po['item_category']) ?></span><?php endif; ?>
              <?php if (empty($po['item_sku']) && empty($po['item_category'])): ?><span style="color:#adb5bd;">—</span><?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:700;"><?= (int)$po['quantity'] ?></td>
            <td style="text-align:right;">&#8369;<?= number_format((float)$po['unit_price'], 2) ?></td>
            <td style="text-align:right;font-weight:700;color:#155724;">&#8369;<?= number_format((float)$po['total_amount'], 2) ?></td>
            <td style="font-size:12px;"><?= htmlspecialchars($po['manager_name'] ?? '—') ?></td>
            <td style="font-size:12px;"><?= htmlspecialchars($po['admin_name'] ?? '—') ?></td>
            <td style="font-size:12px;white-space:nowrap;"><?= !empty($po['admin_finalized_at']) ? date('M d, Y', strtotime($po['admin_finalized_at'])) : '—' ?></td>
            <td style="text-align:center;"><span class="status-badge badge-pending"><i class="fas fa-clock"></i> Awaiting</span></td>
            <td>
              <div class="actions-cell">
                <button class="btn-action btn-approve"
                  onclick="openValidate(<?= $po['id'] ?>, '<?= htmlspecialchars($po['po_number'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($po['product_name'] ?? '', ENT_QUOTES) ?>', <?= (int)$po['quantity'] ?>)">
                  <i class="fas fa-check-double"></i> Validate Delivery
                </button>
                <button class="btn-action btn-reject"
                  onclick="openFlag(<?= $po['id'] ?>, '<?= htmlspecialchars($po['po_number'] ?? '', ENT_QUOTES) ?>')">
                  <i class="fas fa-exclamation-triangle"></i> Flag Issue
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ══ HISTORY TAB ══ -->
<div class="dv-card">
  <div class="dv-card-head">
    <div class="dv-card-title"><i class="fas fa-history"></i> Validated Deliveries</div>
  </div>
  <div class="dv-card-body">
    <?php if (empty($validated_pos)): ?>
      <div class="empty-state">
        <i class="fas fa-history"></i>
        <strong>No validated deliveries yet.</strong>
      </div>
    <?php else: ?>
      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th>PO Number</th><th>Product</th><th>PO Qty</th><th>Actual Received</th>
              <th>Flag</th><th>Validated By</th><th>Validated At</th><th>Notes</th><th>Stock-In</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($validated_pos as $po):
              $flag = $po['delivery_flag'] ?? 'OK';
              $flag_cls = 'status-badge badge-' . strtolower($flag);
            ?>
            <tr>
              <td><strong style="font-family:monospace;color:#002F70;"><?= htmlspecialchars($po['po_number'] ?? '') ?></strong></td>
              <td><?= htmlspecialchars($po['product_name'] ?? '') ?></td>
              <td style="text-align:center;"><?= (int)$po['quantity'] ?></td>
              <td style="text-align:center;font-weight:700;">
                <?= $po['actual_qty_received'] !== null ? (int)$po['actual_qty_received'] : '<span style="color:#adb5bd;">—</span>' ?>
              </td>
              <td><span class="<?= $flag_cls ?>"><?= htmlspecialchars($flag) ?></span></td>
              <td><?= htmlspecialchars($po['validated_by_name'] ?? '—') ?></td>
              <td style="font-size:12px;"><?= $po['delivery_validated_at'] ? date('M d, Y H:i', strtotime($po['delivery_validated_at'])) : '—' ?></td>
              <td style="font-size:12px;color:#6c757d;max-width:160px;"><?= htmlspecialchars($po['delivery_notes'] ?? '') ?: '<span style="color:#adb5bd;">—</span>' ?></td>
              <td>
                <?php if ($po['stock_in_done']): ?>
                  <span class="status-badge badge-approved"><i class="fas fa-check"></i> Done</span>
                <?php else: ?>
                  <a href="staff_stock_in.php" class="status-badge badge-other" style="text-decoration:none;"><i class="fas fa-arrow-right"></i> Stock-In</a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- VALIDATE MODAL -->
<div class="modal-overlay" id="validateModal">
  <div class="modal-box">
    <h3><i class="fas fa-check-double" style="color:#28a745;"></i> Validate Delivery</h3>
    <div id="validatePoInfo" style="background:#f8f9fa;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;"></div>
    <form method="post" action="manager_delivery_validation.php">
      <input type="hidden" name="action" value="validate">
      <input type="hidden" name="po_id" id="validatePoId">
      <div class="form-group">
        <label>Actual Qty Received <span style="color:#dc3545;">*</span></label>
        <input type="number" name="actual_qty" id="validateActualQty" min="0" required placeholder="Enter actual quantity received...">
      </div>
      <div class="form-group">
        <label>Delivery Status <span style="color:#dc3545;">*</span></label>
        <select name="delivery_flag" id="validateFlag">
          <option value="OK">OK — Matches PO</option>
          <option value="Short">Short — Less than ordered</option>
          <option value="Excess">Excess — More than ordered</option>
          <option value="Damaged">Damaged — Items damaged</option>
          <option value="Mixed">Mixed — Multiple issues</option>
        </select>
      </div>
      <div class="form-group">
        <label>Notes (optional)</label>
        <textarea name="delivery_notes" rows="3" placeholder="e.g. Delivery matches DR. 2 units damaged on arrival."></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" onclick="closeModal('validateModal')" style="padding:9px 20px;background:#e9ecef;color:#333;border:none;border-radius:6px;cursor:pointer;font-weight:600;">Cancel</button>
        <button type="submit" class="btn-action btn-approve"><i class="fas fa-check-double"></i> Confirm Validation</button>
      </div>
    </form>
  </div>
</div>

<!-- FLAG MODAL -->
<div class="modal-overlay" id="flagModal">
  <div class="modal-box">
    <h3><i class="fas fa-exclamation-triangle" style="color:var(--red);"></i> Flag Delivery Issue</h3>
    <div id="flagPoInfo" style="background:#f8f9fa;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;border:1px solid #e9ecef;"></div>
    <form method="post" action="manager_delivery_validation.php">
      <input type="hidden" name="action" value="flag_issue">
      <input type="hidden" name="po_id" id="flagPoId">
      <div class="form-group">
        <label>Issue Type <span style="color:#dc3545;">*</span></label>
        <select name="delivery_flag">
          <option value="Short">Short — Less than ordered</option>
          <option value="Damaged">Damaged — Items damaged</option>
          <option value="Excess">Excess — More than ordered</option>
          <option value="Mixed">Mixed — Multiple issues</option>
        </select>
      </div>
      <div class="form-group">
        <label>Issue Description <span style="color:#dc3545;">*</span></label>
        <textarea name="delivery_notes" rows="3" required placeholder="Required: describe the issue in detail..."></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" onclick="closeModal('flagModal')" style="padding:9px 20px;background:#e9ecef;color:#333;border:none;border-radius:6px;cursor:pointer;font-weight:600;">Cancel</button>
        <button type="submit" class="btn-action btn-reject"><i class="fas fa-exclamation-triangle"></i> Flag Issue</button>
      </div>
    </form>
  </div>
</div>

<script>
function openValidate(id, poNum, product, qty) {
    document.getElementById('validatePoId').value = id;
    document.getElementById('validateActualQty').value = qty;
    document.getElementById('validatePoInfo').innerHTML =
        '<strong>PO:</strong> ' + poNum + ' &nbsp;|&nbsp; <strong>Product:</strong> ' + product + ' &nbsp;|&nbsp; <strong>PO Qty:</strong> ' + qty;
    document.getElementById('validateModal').classList.add('show');
    setTimeout(function(){ document.getElementById('validateActualQty').focus(); }, 120);
}
function openFlag(id, poNum) {
    document.getElementById('flagPoId').value = id;
    document.getElementById('flagPoInfo').innerHTML = '<strong>PO:</strong> ' + poNum;
    document.getElementById('flagModal').classList.add('show');
}
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('show'); });
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.show').forEach(function(m){ m.classList.remove('show'); }); });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
