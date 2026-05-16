<?php
$page_id = 'staff_stock_requests';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

if ($role !== 'staff') {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// ── Fetch low/out-of-stock items for the modal ──────────────────────────────
$low_stock_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            ip.id AS item_id,
            ip.product_name AS item_name,
            ip.sku AS item_sku,
            ip.category AS item_category,
            COALESCE(si.stock_level, ip.stock_quantity, 0) AS current_stock,
            COALESCE(si.reorder_level, 5) AS reorder_level
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category != 'Fuel'
          AND (
              COALESCE(si.stock_level, ip.stock_quantity, 0) <= COALESCE(si.reorder_level, 5)
          )
        ORDER BY COALESCE(si.stock_level, ip.stock_quantity, 0) ASC
        LIMIT 50
    ");
    $stmt->execute([$station_id]);
    $low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $low_stock_items = [];
}

// ── Fetch my requests ───────────────────────────────────────────────────────
$requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT sr.*, s.name AS station_name
        FROM stock_requests sr
        LEFT JOIN stations s ON sr.station_id = s.id
        WHERE sr.staff_id = ?
        ORDER BY sr.created_at DESC
        LIMIT 60
    ");
    $stmt->execute([(int)$me['id']]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $requests = [];
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.ssr-wrap { max-width:1300px; margin:0 auto; padding:24px; }
.ssr-header { background:linear-gradient(135deg,#003d7a,#002d5c); color:#fff; padding:40px 32px; border-radius:16px; margin-bottom:28px; box-shadow:0 8px 24px rgba(0,61,122,.3); }
.ssr-header h1 { font-size:32px; font-weight:700; margin-bottom:6px; color:#fff !important; }
.ssr-header p  { font-size:15px; opacity:.85; color:#fff !important; }
.ssr-alert { display:flex; align-items:center; gap:10px; padding:13px 16px; border-radius:8px; margin-bottom:18px; font-size:14px; }
.ssr-alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.ssr-alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* Summary cards */
.ssr-summary { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:24px; }
.ssr-stat { flex:1; min-width:120px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; text-align:center; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.ssr-stat-num { font-size:28px; font-weight:800; color:#002F70; }
.ssr-stat-lbl { font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.5px; margin-top:2px; }
.ssr-stat-pending  .ssr-stat-num { color:#fd7e14; }
.ssr-stat-forwarded .ssr-stat-num { color:#6f42c1; }
.ssr-stat-rejected .ssr-stat-num { color:#dc3545; }

/* Request Stock button */
.btn-request-stock { background:linear-gradient(135deg,#003d7a,#002d5c); color:#fff; border:none; padding:12px 28px; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:10px; box-shadow:0 4px 14px rgba(0,61,122,.3); transition:all .2s; }
.btn-request-stock:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,61,122,.4); }

/* Table */
.ssr-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:24px; }
.ssr-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.ssr-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.ssr-card-body { padding:20px; }
.sbadge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
.sbadge-pending  { background:#fff3cd; color:#856404; }
.sbadge-approved { background:#d1ecf1; color:#0c5460; }
.sbadge-validated { background:#cce5ff; color:#004085; }
.sbadge-forwarded-to-admin { background:#e6e6fa; color:#5f5f9c; border:1px solid #d8d8ff; }
.sbadge-rejected { background:#f8d7da; color:#721c24; }

/* Modal */
.sr-modal { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.6); z-index:9999; align-items:center; justify-content:center; }
.sr-modal.open { display:flex; }
.sr-modal-box { background:#fff; border-radius:16px; padding:0; width:700px; max-width:calc(100vw - 32px); max-height:calc(100vh - 40px); overflow:hidden; display:flex; flex-direction:column; box-shadow:0 24px 80px rgba(0,0,0,.3); animation:srModalIn .2s ease; }
@keyframes srModalIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
.sr-modal-head { background:linear-gradient(135deg,#003d7a,#002d5c); color:#fff; padding:20px 24px; display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
.sr-modal-head h3 { font-size:1.1rem; font-weight:700; color:#fff !important; margin:0; }
.sr-modal-close { background:none; border:none; color:#fff; font-size:22px; cursor:pointer; opacity:.8; line-height:1; }
.sr-modal-close:hover { opacity:1; }
.sr-modal-body { padding:20px 24px; overflow-y:auto; flex:1; }
.sr-modal-footer { padding:16px 24px; border-top:1px solid #e9ecef; display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; background:#f8f9fa; border-radius:0 0 16px 16px; }

/* Low stock item list */
.ls-item { display:flex; align-items:center; gap:12px; padding:10px 12px; border:1.5px solid #e2e8f0; border-radius:8px; margin-bottom:8px; cursor:pointer; transition:all .15s; }
.ls-item:hover { border-color:#003d7a; background:#f0f4ff; }
.ls-item.selected { border-color:#003d7a; background:#e8f0fe; }
.ls-item input[type=checkbox] { width:18px; height:18px; accent-color:#003d7a; flex-shrink:0; cursor:pointer; }
.ls-item-info { flex:1; }
.ls-item-name { font-weight:700; font-size:14px; color:#0f172a; }
.ls-item-meta { font-size:12px; color:#64748b; margin-top:2px; }
.ls-item-badge { font-size:11px; font-weight:700; padding:2px 8px; border-radius:10px; flex-shrink:0; }
.ls-badge-out  { background:#fee2e2; color:#7f1d1d; }
.ls-badge-low  { background:#fef3c7; color:#92400e; }

.remarks-group { margin-top:14px; }
.remarks-group label { display:block; font-weight:700; font-size:12px; color:#495057; text-transform:uppercase; letter-spacing:.4px; margin-bottom:6px; }
.remarks-group textarea { width:100%; padding:10px 12px; border:1.5px solid #dee2e6; border-radius:8px; font-size:13px; resize:vertical; font-family:inherit; }
.remarks-group textarea:focus { border-color:#003d7a; outline:none; box-shadow:0 0 0 3px rgba(0,61,122,.1); }

.info-note { background:#e8f4fd; border-left:4px solid #003d7a; border-radius:6px; padding:10px 14px; font-size:12px; color:#002F70; line-height:1.6; margin-bottom:14px; }
.empty-ls { text-align:center; padding:32px; color:#6c757d; }
.empty-ls i { font-size:2.5em; display:block; margin-bottom:10px; opacity:.3; }
</style>

<div class="ssr-wrap">
  <!-- Header -->
  <div class="ssr-header">
    <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;justify-content:space-between;">
      <div>
        <h1><i class="fas fa-boxes" style="margin-right:10px;"></i>Stock Requests</h1>
        <p>Select low/out-of-stock items to request replenishment — manager will set the quantity</p>
      </div>
      <button class="btn-request-stock" onclick="openStockModal()">
        <i class="fas fa-plus-circle"></i> Request Stock
      </button>
    </div>
  </div>

  <?php if($msg): ?>
  <div class="ssr-alert <?php echo strpos($msg,'✅')!==false?'ssr-alert-success':'ssr-alert-error'; ?>">
    <i class="fas <?php echo strpos($msg,'✅')!==false?'fa-check-circle':'fa-exclamation-circle'; ?>"></i>
    <span><?php echo htmlspecialchars($msg); ?></span>
  </div>
  <?php endif; ?>
  <div id="ajaxFlash" style="display:none;" class="ssr-alert"></div>

  <!-- Summary -->
  <?php
  $cnt_total    = count($requests);
  $cnt_pending  = count(array_filter($requests, fn($r) => $r['status'] === 'Pending'));
  $cnt_forward  = count(array_filter($requests, fn($r) => $r['status'] === 'Forwarded to Admin'));
  $cnt_rejected = count(array_filter($requests, fn($r) => $r['status'] === 'Rejected'));
  ?>
  <div class="ssr-summary">
    <div class="ssr-stat"><div class="ssr-stat-num"><?php echo $cnt_total; ?></div><div class="ssr-stat-lbl">Total</div></div>
    <div class="ssr-stat ssr-stat-pending"><div class="ssr-stat-num"><?php echo $cnt_pending; ?></div><div class="ssr-stat-lbl">Pending Review</div></div>
    <div class="ssr-stat ssr-stat-forwarded"><div class="ssr-stat-num"><?php echo $cnt_forward; ?></div><div class="ssr-stat-lbl">Forwarded to Admin</div></div>
    <div class="ssr-stat ssr-stat-rejected"><div class="ssr-stat-num"><?php echo $cnt_rejected; ?></div><div class="ssr-stat-lbl">Rejected</div></div>
  </div>

  <!-- My Requests Table -->
  <div class="ssr-card">
    <div class="ssr-card-head">
      <div class="ssr-card-title"><i class="fas fa-list"></i> My Stock Requests</div>
      <button onclick="location.reload()" class="btn ghost" style="font-size:12px;"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
    <div class="ssr-card-body">
      <?php if(empty($requests)): ?>
        <div style="text-align:center;padding:48px;color:#6c757d;">
          <i class="fas fa-inbox" style="font-size:3em;display:block;margin-bottom:12px;opacity:.2;"></i>
          <strong>No requests yet.</strong><br>Click <em>Request Stock</em> to get started.
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>#</th><th>Date</th><th>SKU</th><th>Product</th><th>Category</th>
              <th>Current Stock</th><th>Remarks</th><th>Status</th><th>Manager Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($requests as $r):
              $st = $r['status'] ?? 'Pending';
              $st_key = strtolower(str_replace(' ','-',$st));
              $cls = 'sbadge sbadge-'.$st_key;
            ?>
            <tr>
              <td style="color:#6c757d;font-size:12px;">#<?php echo $r['id']; ?></td>
              <td style="font-size:12px;"><?php echo date('M d, Y H:i', strtotime($r['created_at'])); ?></td>
              <td><code style="font-size:11px;"><?php echo htmlspecialchars($r['item_sku'] ?? ''); ?></code></td>
              <td><strong><?php echo htmlspecialchars($r['item_name'] ?? $r['product_name'] ?? ''); ?></strong></td>
              <td style="font-size:12px;"><?php echo htmlspecialchars($r['item_category'] ?? ''); ?></td>
              <td style="text-align:center;"><?php echo (int)($r['current_stock'] ?? 0); ?></td>
              <td style="font-size:12px;color:#6c757d;max-width:160px;"><?php echo $r['remarks'] ? htmlspecialchars($r['remarks']) : '<span style="color:#adb5bd;">—</span>'; ?></td>
              <td><span class="<?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
              <td style="font-size:12px;color:#495057;max-width:180px;"><?php echo $r['manager_notes'] ? htmlspecialchars($r['manager_notes']) : '<span style="color:#adb5bd;">—</span>'; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── STOCK REQUEST MODAL ── -->
<div class="sr-modal" id="stockRequestModal">
  <div class="sr-modal-box">
    <div class="sr-modal-head">
      <h3><i class="fas fa-exclamation-triangle" style="margin-right:8px;color:#ffc107;"></i> Request Stock Replenishment</h3>
      <button class="sr-modal-close" onclick="closeStockModal()">&times;</button>
    </div>
    <div class="sr-modal-body">
      <div class="info-note">
        <i class="fas fa-info-circle"></i>
        <strong>How it works:</strong> Select the items that need restocking. The manager will review your request and set the approved quantity. No need to enter quantities here.
      </div>

      <?php if(empty($low_stock_items)): ?>
        <div class="empty-ls">
          <i class="fas fa-check-circle" style="color:#28a745;"></i>
          <strong>All stock levels are normal!</strong><br>
          <span style="font-size:13px;">No low or out-of-stock items found for your station.</span>
        </div>
      <?php else: ?>
        <div style="font-weight:700;font-size:13px;color:#495057;margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">
          <i class="fas fa-exclamation-circle" style="color:#dc3545;"></i>
          Low / Out-of-Stock Items (<?php echo count($low_stock_items); ?> items)
        </div>
        <div id="lowStockList">
          <?php foreach($low_stock_items as $item):
            $is_out = (int)$item['current_stock'] <= 0;
            $badge_cls = $is_out ? 'ls-badge-out' : 'ls-badge-low';
            $badge_txt = $is_out ? 'OUT OF STOCK' : 'LOW STOCK';
          ?>
          <div class="ls-item" onclick="toggleItem(this)" data-id="<?php echo (int)$item['item_id']; ?>"
               data-name="<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES); ?>"
               data-sku="<?php echo htmlspecialchars($item['item_sku'] ?? '', ENT_QUOTES); ?>"
               data-category="<?php echo htmlspecialchars($item['item_category'] ?? '', ENT_QUOTES); ?>"
               data-stock="<?php echo (int)$item['current_stock']; ?>">
            <input type="checkbox" onclick="event.stopPropagation();" onchange="syncCheck(this)">
            <div class="ls-item-info">
              <div class="ls-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
              <div class="ls-item-meta">
                SKU: <?php echo htmlspecialchars($item['item_sku'] ?? 'N/A'); ?> &nbsp;|&nbsp;
                Category: <?php echo htmlspecialchars($item['item_category'] ?? 'N/A'); ?> &nbsp;|&nbsp;
                Current Stock: <strong><?php echo (int)$item['current_stock']; ?></strong>
              </div>
            </div>
            <span class="ls-item-badge <?php echo $badge_cls; ?>"><?php echo $badge_txt; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="remarks-group">
        <label>Remarks / Notes (Optional)</label>
        <textarea id="srRemarks" rows="3" placeholder="e.g., Urgently needed for weekend operations..."></textarea>
      </div>

      <div id="srError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px;border-radius:6px;margin-top:10px;font-size:13px;"></div>
    </div>
    <div class="sr-modal-footer">
      <button type="button" onclick="closeStockModal()" style="padding:10px 22px;background:#6c757d;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">Cancel</button>
      <button type="button" id="srSubmitBtn" onclick="submitStockRequest()" style="padding:10px 24px;background:#003d7a;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:700;display:inline-flex;align-items:center;gap:8px;">
        <i class="fas fa-paper-plane"></i> Submit Request
      </button>
    </div>
  </div>
</div>

<script>
// ── Modal open/close ──────────────────────────────────────────────────────────
function openStockModal() {
    document.getElementById('stockRequestModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeStockModal() {
    document.getElementById('stockRequestModal').classList.remove('open');
    document.body.style.overflow = '';
    // Uncheck all
    document.querySelectorAll('#lowStockList .ls-item').forEach(function(el) {
        el.classList.remove('selected');
        el.querySelector('input[type=checkbox]').checked = false;
    });
    document.getElementById('srRemarks').value = '';
    document.getElementById('srError').style.display = 'none';
}
document.getElementById('stockRequestModal').addEventListener('click', function(e) {
    if (e.target === this) closeStockModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeStockModal();
});

// ── Item selection ────────────────────────────────────────────────────────────
function toggleItem(el) {
    var cb = el.querySelector('input[type=checkbox]');
    cb.checked = !cb.checked;
    el.classList.toggle('selected', cb.checked);
}
function syncCheck(cb) {
    cb.closest('.ls-item').classList.toggle('selected', cb.checked);
}

// ── Submit ────────────────────────────────────────────────────────────────────
function submitStockRequest() {
    var selected = [];
    document.querySelectorAll('#lowStockList .ls-item.selected').forEach(function(el) {
        selected.push({
            item_id:       parseInt(el.dataset.id),
            item_name:     el.dataset.name,
            item_sku:      el.dataset.sku,
            item_category: el.dataset.category,
            current_stock: parseInt(el.dataset.stock)
        });
    });

    if (selected.length === 0) {
        showSrErr('Please select at least one item to request.');
        return;
    }

    var remarks = document.getElementById('srRemarks').value.trim();
    var btn = document.getElementById('srSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    document.getElementById('srError').style.display = 'none';

    // Submit each selected item as a separate request
    var promises = selected.map(function(item) {
        return fetch('../backend/api/stock_request.php?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                item_id:            item.item_id,
                sku:                item.item_sku,
                item_name:          item.item_name,
                item_category:      item.item_category,
                current_stock:      item.current_stock,
                requested_quantity: 0,
                remarks:            remarks
            })
        }).then(function(r) { return r.json(); });
    });

    Promise.all(promises).then(function(results) {
        var errors = results.filter(function(r) { return !r.success; });
        if (errors.length > 0) {
            var dupErrors = errors.filter(function(r) { return r.message && r.message.indexOf('pending request') !== -1; });
            if (dupErrors.length === errors.length) {
                showSrErr('Some items already have a pending request. Please check your existing requests.');
            } else {
                showSrErr('Some items could not be submitted: ' + errors.map(function(r) { return r.message; }).join(', '));
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        } else {
            closeStockModal();
            showFlash('success', '&#10003; Stock request submitted for ' + selected.length + ' item(s). Awaiting manager review.');
            setTimeout(function() { location.reload(); }, 1800);
        }
    }).catch(function() {
        showSrErr('Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
    });
}

function showSrErr(msg) {
    var el = document.getElementById('srError');
    el.textContent = msg;
    el.style.display = 'block';
}
function showFlash(type, msg) {
    var el = document.getElementById('ajaxFlash');
    el.className = 'ssr-alert ssr-alert-' + type;
    el.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'times-circle') + '"></i><span>' + msg + '</span>';
    el.style.display = 'flex';
    setTimeout(function() { el.style.display = 'none'; }, 6000);
}

// Move modal to body
document.addEventListener('DOMContentLoaded', function() {
    var m = document.getElementById('stockRequestModal');
    if (m && m.parentNode !== document.body) document.body.appendChild(m);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
