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
    $stmt = $pdo->prepare("SELECT po.*,CONCAT(u_mgr.first_name, ' ', u_mgr.last_name) AS manager_name,CONCAT(u_adm.first_name, ' ', u_adm.last_name) AS admin_name,sr.item_sku,sr.item_category,sr.remarks AS sr_remarks,sr.current_stock FROM purchase_orders po LEFT JOIN users u_mgr ON po.created_by=u_mgr.id LEFT JOIN users u_adm ON po.admin_id=u_adm.id LEFT JOIN stock_requests sr ON po.request_id=sr.id WHERE po.station_id=? AND po.type='merch' AND po.admin_finalized=1 AND po.delivery_validated=0 AND po.stock_in_done=0 ORDER BY po.admin_finalized_at ASC");
    $stmt->execute([$station_id]);
    $pending_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$validated_pos = [];
try {
    $stmt = $pdo->prepare("SELECT po.*,CONCAT(u_mgr.first_name, ' ', u_mgr.last_name) AS manager_name,CONCAT(u_adm.first_name, ' ', u_adm.last_name) AS admin_name,CONCAT(u_val.first_name, ' ', u_val.last_name) AS validated_by_name FROM purchase_orders po LEFT JOIN users u_mgr ON po.created_by=u_mgr.id LEFT JOIN users u_adm ON po.admin_id=u_adm.id LEFT JOIN users u_val ON po.delivery_validated_by=u_val.id WHERE po.station_id=? AND po.type='merch' AND po.delivery_validated=1 ORDER BY po.delivery_validated_at DESC LIMIT 50");
    $stmt->execute([$station_id]);
    $validated_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$active_tab = $_GET["tab"] ?? "pending";
include __DIR__ . "/../partials/header.php";
?>

<style>
* { box-sizing: border-box; }
/* int-head standard */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:#00264D !important; margin:0 !important; text-transform:none !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#64748b; margin-top:4px; }
.ato-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 16px; border-radius:7px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s; height:36px; white-space:nowrap; background:white !important; }
.ato-btn-back { color:#4b5563 !important; border-color:#6b7280 !important; }
.ato-btn-back:hover { background:#6b7280 !important; color:#fff !important; }
/* Flash alerts */
.flash-ok  { display:flex; align-items:center; gap:8px; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:13px; font-weight:500; background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.flash-err { display:flex; align-items:center; gap:8px; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:13px; font-weight:500; background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
/* Tabs */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:22px; }
.tab-btn { padding:10px 24px; background:none; border:none; border-bottom:3px solid transparent; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70; }
/* Card */
.dv-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; margin-bottom:20px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.dv-card-head { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
.dv-card-title { font-size:13px; font-weight:700; color:#00264D; text-transform:uppercase; letter-spacing:.3px; display:flex; align-items:center; gap:8px; }
.dv-card-body { padding:0; }
/* Table */
.po-table-wrap { width:100%; overflow-x:auto; }
.po-table { width:100%; border-collapse:collapse; font-size:11px; }
.po-table thead tr { background:#002F70; }
.po-table thead th { padding:9px 10px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; border-bottom:2px solid #001a3d; vertical-align:middle; white-space:nowrap; }
.po-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.po-table tbody tr:hover td { background:#eff6ff; }
.po-table tbody td { padding:9px 10px; color:#334155; vertical-align:middle; white-space:nowrap; background:#fff; font-size:11px; }
/* Badges */
.afto-badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:700; white-space:nowrap; text-transform:uppercase; }
.bg-amber  { background:#fffbeb; color:#b45309; border:1px solid #fef3c7; }
.bg-green  { background:#f0fdf4; color:#166534; border:1px solid #dcfce7; }
.bg-red    { background:#fef2f2; color:#991b1b; border:1px solid #fee2e2; }
.bg-gray   { background:#f8fafc; color:#475569; border:1px solid #e2e8f0; }
/* Action buttons */
.row-btn { padding:0 10px; border-radius:5px; font-size:11px; font-weight:700; border:1px solid transparent; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:4px; transition:all .15s; text-transform:uppercase; height:28px; background:white !important; text-decoration:none; }
.row-btn-success { color:#16a34a !important; border-color:#16a34a !important; }
.row-btn-success:hover { background:#16a34a !important; color:#fff !important; }
.row-btn-danger  { color:#dc2626 !important; border-color:#dc2626 !important; }
.row-btn-danger:hover  { background:#dc2626 !important; color:#fff !important; }
/* Empty state */
.empty-state { text-align:center; padding:60px 20px; color:#94a3b8; }
.empty-state i { font-size:44px; display:block; margin-bottom:14px; opacity:.4; }
/* Modal */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:9999; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal-box { background:#fff; border-radius:12px; width:90%; max-width:540px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); padding:28px; }
.modal-box h3 { margin:0 0 16px; font-size:15px; color:#00264D; font-weight:700; text-transform:uppercase; display:flex; align-items:center; gap:8px; border-bottom:1px solid #f1f5f9; padding-bottom:10px; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
.form-group input, .form-group select, .form-group textarea { width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; outline:none; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,0.1); }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; }
/* Actions cell */
.actions-cell { display:inline-flex; gap:4px; }
</style>

<div class="int-head">
  <div>
    <h1><i class="fas fa-truck-loading"></i> Delivery Validation</h1>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <a href="manager_delivery_validation.php" class="ato-btn ato-btn-back"><i class="fas fa-sync-alt"></i> Refresh</a>
    <a href="manager_dashboard.php" class="ato-btn ato-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
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
          <?php if (empty($pending_pos)): ?>
            <tr>
              <td colspan="11">
                <div class="empty-state">
                  <i class="fas fa-check-circle" style="color:#28a745;opacity:.5;"></i>
                  <strong>No deliveries awaiting validation.</strong><br>
                  <span style="font-size:13px;">All Admin-finalized POs have been validated, or no POs have been finalized yet.</span>
                </div>
              </td>
            </tr>
          <?php else: ?>
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
            <td style="text-align:center;">
              <div class="actions-cell">
                <button class="row-btn row-btn-success"
                  onclick="openValidate(<?= $po['id'] ?>, '<?= htmlspecialchars($po['po_number'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($po['product_name'] ?? '', ENT_QUOTES) ?>', <?= (int)$po['quantity'] ?>)"
                  title="Validate Delivery">
                  <i class="fas fa-check-double"></i> Validate
                </button>
                <button class="row-btn row-btn-danger"
                  onclick="openFlag(<?= $po['id'] ?>, '<?= htmlspecialchars($po['po_number'] ?? '', ENT_QUOTES) ?>')"
                  title="Flag Issue">
                  <i class="fas fa-exclamation-triangle"></i> Flag
                </button>
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

<?php else: ?>
<!-- ══ HISTORY TAB ══ -->
<div class="dv-card">
  <div class="dv-card-head">
    <div class="dv-card-title"><i class="fas fa-history"></i> Validated Deliveries</div>
  </div>
  <div class="dv-card-body">
      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th>PO Number</th><th>Product</th><th>PO Qty</th><th>Actual Received</th>
              <th>Flag</th><th>Validated By</th><th>Validated At</th><th>Notes</th><th>Stock-In</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($validated_pos)): ?>
              <tr>
                <td colspan="9">
                  <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <strong>No validated deliveries yet.</strong>
                  </div>
                </td>
              </tr>
            <?php else: ?>
            <?php foreach ($validated_pos as $po):
              $flag = $po['delivery_flag'] ?? 'OK';
              $flag_lc = strtolower($flag);
              $flag_cls = ($flag_lc === 'ok') ? 'bg-green' : (($flag_lc === 'short' || $flag_lc === 'damaged') ? 'bg-red' : 'bg-gray');
            ?>
            <tr>
              <td><strong style="font-family:monospace;color:#002F70;"><?= htmlspecialchars($po['po_number'] ?? '') ?></strong></td>
              <td><?= htmlspecialchars($po['product_name'] ?? '') ?></td>
              <td style="text-align:center;"><?= (int)$po['quantity'] ?></td>
              <td style="text-align:center;font-weight:700;">
                <?= $po['actual_qty_received'] !== null ? (int)$po['actual_qty_received'] : '<span style="color:#adb5bd;">—</span>' ?>
              </td>
              <td><span class="afto-badge <?= $flag_cls ?>"><?= htmlspecialchars($flag) ?></span></td>
              <td><?= htmlspecialchars($po['validated_by_name'] ?? '—') ?></td>
              <td style="font-size:12px;"><?= $po['delivery_validated_at'] ? date('M d, Y H:i', strtotime($po['delivery_validated_at'])) : '—' ?></td>
              <td style="font-size:12px;color:#6c757d;max-width:160px;"><?= htmlspecialchars($po['delivery_notes'] ?? '') ?: '<span style="color:#adb5bd;">—</span>' ?></td>
              <td>
                <?php if ($po['stock_in_done']): ?>
                  <span class="status-badge badge-approved"><i class="fas fa-check"></i> Done</span>
                <?php else: ?>
                  <a href="manager_stock_in.php" class="status-badge badge-other" style="text-decoration:none;"><i class="fas fa-arrow-right"></i> Stock-In</a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
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
        <button type="button" onclick="closeModal('validateModal')" class="ato-btn ato-btn-back">Cancel</button>
        <button type="submit" class="ato-btn" style="background:#16a34a !important;color:#fff !important;border-color:#16a34a !important;"><i class="fas fa-check-double"></i> Confirm Validation</button>
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
        <button type="button" onclick="closeModal('flagModal')" class="ato-btn ato-btn-back">Cancel</button>
        <button type="submit" class="ato-btn" style="background:#dc2626 !important;color:#fff !important;border-color:#dc2626 !important;"><i class="fas fa-exclamation-triangle"></i> Flag Issue</button>
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
