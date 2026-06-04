<?php
$page_id = 'inv_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

$fuel_inventory = [];
$msg = '';
try {
    $stmt = $pdo->prepare("
        SELECT ip.product_name AS name,
               COALESCE(fi.price_per_liter, ip.unit_cost) AS price,
               COALESCE(fi.current_level, ip.stock)       AS stock_level,
               COALESCE(fi.capacity, 20000.00)            AS capacity
        FROM inventory_products ip
        LEFT JOIN fuel_inventory fi
               ON ip.product_name = fi.fuel_type AND fi.station_id = ?
        WHERE ip.category = 'Fuel'
        ORDER BY ip.product_name
    ");
    $stmt->execute([$station_id]);
    $fuel_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading fuel inventory: ' . $e->getMessage();
}

$pending_fuel_sr = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE staff_id=? AND status='Pending'");
    $s->execute([$me['id']]);
    $pending_fuel_sr = (int)$s->fetchColumn();
} catch (Exception $e) {}

// Build JS data array
$js_fuel = [];
foreach ($fuel_inventory as $f) {
    $fl  = (float)($f['stock_level'] ?? 0);
    $cap = (float)($f['capacity'] ?? 1);
    $pct = $cap > 0 ? ($fl / $cap) * 100 : 0;
    if      ($fl  <= 0)   { $st = 'OUT OF STOCK'; $sc = '#dc3545'; $st_cls = 'status-critical'; }
    elseif  ($pct <= 10)  { $st = 'CRITICAL';     $sc = '#dc3545'; $st_cls = 'status-critical'; }
    elseif  ($pct <= 25)  { $st = 'LOW';          $sc = '#fd7e14'; $st_cls = 'status-low'; }
    elseif  ($fl  <= 500) { $st = 'LOW STOCK';    $sc = '#fd7e14'; $st_cls = 'status-low'; }
    else                  { $st = 'AVAILABLE';    $sc = '#28a745'; $st_cls = 'status-ok'; }
    $js_fuel[] = [
        'name'      => $f['name'],
        'level'     => $fl,
        'capacity'  => $cap,
        'pct'       => round($pct, 1),
        'status'    => $st,
        'statusCls' => $st_cls,
        'color'     => $sc,
    ];
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.inv-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:20px; }
.inv-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.inv-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.inv-card-body  { padding:20px; }

/* ══ No-Scroll Fixed-Layout Table ══ */
body, html { overflow-x: hidden !important; }

.table-wrap {
    width: 100%;
    max-width: 100%;
    overflow: hidden;   /* no scroll at all */
    padding: 0;
}
.fuel-table {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
    border-collapse: collapse;
    border-spacing: 0;
}
/* Column widths via colgroup — percentages sum to 100% */
.fuel-table col.c-fuel   { width: 30%; }
.fuel-table col.c-level  { width: 18%; }
.fuel-table col.c-cap    { width: 18%; }
.fuel-table col.c-fill   { width: 22%; }
.fuel-table col.c-price  { width: 12%; }

.fuel-table thead th {
    background: #002F6C;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 11px 10px;
    white-space: normal;
    word-wrap: break-word;
    overflow-wrap: break-word;
    vertical-align: middle;
}
.fuel-table thead th.r { text-align: right; }
.fuel-table tbody td {
    padding: 11px 10px;
    font-size: 13px;
    border-bottom: 1px solid #e9ecef;
    vertical-align: middle;
    white-space: normal;
    word-wrap: break-word;
    overflow-wrap: break-word;
    min-width: 0 !important;
}
.fuel-table tbody td.r { text-align: right; }
.fuel-table tbody tr:hover { background: #f0f4ff; }

/* Fill bar — always fits in its column */
.fill-bar-wrap { background:#e9ecef; border-radius:4px; height:8px; overflow:hidden; margin-bottom:3px; width:100%; }
.fill-bar-inner { height:100%; border-radius:4px; }

/* Status pill */
.fuel-status-pill {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
    margin-left: 4px;
    vertical-align: middle;
}

/* Mobile: hide Capacity, redistribute widths */
@media (max-width: 768px) {
    .fuel-table col.c-fuel  { width: 36%; }
    .fuel-table col.c-level { width: 22%; }
    .fuel-table col.c-cap   { width: 0;   }
    .fuel-table col.c-fill  { width: 28%; }
    .fuel-table col.c-price { width: 14%; }
    .fuel-table .col-cap    { display: none; }
    .inv-card-body          { padding: 10px; }
}

/* ── Modal ── */
.sr-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9999; align-items:center; justify-content:center; }
.sr-modal-overlay.open { display:flex; }
.sr-modal-box { background:#fff; border-radius:14px; padding:28px; width:680px; max-width:calc(100vw - 32px); max-height:calc(100vh - 40px); overflow-y:auto; box-shadow:0 24px 80px rgba(0,0,0,.3); animation:srIn .2s ease; }
@keyframes srIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
.sr-modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; padding-bottom:14px; border-bottom:2px solid #e9ecef; }
.sr-modal-title { font-size:1.05rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.sr-modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#adb5bd; }
.sr-modal-close:hover { color:#333; }
.sr-modal-footer { display:flex; gap:10px; justify-content:flex-end; align-items:center; margin-top:16px; padding-top:14px; border-top:1px solid #e9ecef; }
.sr-info-box { background:#e8f4fd; border-left:4px solid #002F70; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-size:12px; color:#002F70; line-height:1.6; }

/* ── Modal checkbox rows ── */
.fsr-cb-row {
    display:flex; align-items:center; gap:12px;
    padding:10px 14px; border-radius:8px; border:1px solid #dee2e6;
    margin-bottom:7px; cursor:pointer; transition:background .1s;
    user-select:none;
}
.fsr-cb-row:hover   { background:#f0f4ff; }
.fsr-cb-row.checked { background:#eef2ff; border-color:#90a8e0; }
.fsr-cb-row.status-critical { border-left:4px solid #dc3545; }
.fsr-cb-row.status-low      { border-left:4px solid #fd7e14; }
.fsr-cb-row.status-ok       { border-left:4px solid #28a745; }
.fsr-cb { width:18px; height:18px; accent-color:#002F70; cursor:pointer; flex-shrink:0; }
.fsr-item-info { flex:1; min-width:0; }
.fsr-item-name { font-weight:700; font-size:13px; color:#212529; }
.fsr-item-meta { font-size:11px; color:#6c757d; margin-top:2px; }

/* ── Select-all bar ── */
.fsr-select-bar {
    display:flex; align-items:center; gap:8px;
    padding:8px 14px; background:#f8f9fa; border-radius:6px;
    margin-bottom:10px; font-size:12px; color:#495057; font-weight:600;
}
.fsr-select-bar input { width:16px; height:16px; accent-color:#002F70; cursor:pointer; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-gas-pump"></i> Fuel Inventory</h1>
        <div class="sub">RECORD FUEL PUMP READINGS AND DELIVERIES WITH BATCH ID.</div>
    </div>
    <div class="header-actions" style="display:flex;gap:8px;align-items:center;">
        <?php if ($pending_fuel_sr > 0): ?>
        <a href="staff_stock_requests.php#tab-fuel" style="background:#856404;color:#fff;padding:7px 16px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-clock"></i> <?= $pending_fuel_sr ?> Pending Request<?= $pending_fuel_sr > 1 ? 's' : '' ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../partials/staff_inventory_summary.php'; ?>

<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-gas-pump"></i> Fuel Inventory</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?php
            $export_table_id       = 'fuelTable';
            $export_filename       = 'fuel_inventory_' . date('Ymd');
            $export_title          = 'Fuel Inventory';
            $export_rows_select_id = 'fuelRowsLimit';
            $export_default_rows   = 10;
            require __DIR__ . '/../partials/export_buttons.php';
            ?>
            <button onclick="openFuelSrModal()"
                    style="background:#002F70;color:#fff;border:none;display:inline-flex;align-items:center;gap:7px;height:36px;padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
                <i class="fas fa-gas-pump"></i> Stock Request
            </button>
        </div>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="fuel-table" id="fuelTable">
                <colgroup>
                    <col class="c-fuel">
                    <col class="c-level">
                    <col class="c-cap">
                    <col class="c-fill">
                    <col class="c-price">
                </colgroup>
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th class="r">Current Level</th>
                        <th class="r col-cap">Capacity</th>
                        <th>Fill %</th>
                        <th class="r">Price / L</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($fuel_inventory)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:28px;color:#6c757d;">
                        <i class="fas fa-gas-pump" style="font-size:2em;display:block;margin-bottom:8px;opacity:.3;"></i>
                        No fuel inventory data available.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($fuel_inventory as $fuel):
                        $fl  = (float)($fuel['stock_level'] ?? 0);
                        $cap = (float)($fuel['capacity']    ?? 1);
                        $pct = $cap > 0 ? ($fl / $cap) * 100 : 0;
                        if      ($fl  <= 0)   { $st = 'OUT OF STOCK'; $sc = '#dc3545'; }
                        elseif  ($pct <= 10)  { $st = 'CRITICAL';     $sc = '#dc3545'; }
                        elseif  ($pct <= 25)  { $st = 'LOW';          $sc = '#fd7e14'; }
                        elseif  ($fl  <= 500) { $st = 'LOW STOCK';    $sc = '#fd7e14'; }
                        else                  { $st = 'AVAILABLE';    $sc = '#28a745'; }
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($fuel['name']); ?></strong>
                            <span class="fuel-status-pill" style="background:<?php echo $sc; ?>20;color:<?php echo $sc; ?>;border:1px solid <?php echo $sc; ?>40;">
                                <?php echo $st; ?>
                            </span>
                        </td>
                        <td class="r"><?php echo number_format($fl, 2); ?> L</td>
                        <td class="r col-cap"><?php echo number_format($cap, 2); ?> L</td>
                        <td>
                            <div class="fill-bar-wrap">
                                <div class="fill-bar-inner" style="width:<?php echo min(100, round($pct)); ?>%;background:<?php echo $sc; ?>;"></div>
                            </div>
                            <small style="color:#6c757d;font-size:11px;"><?php echo round($pct, 1); ?>%</small>
                        </td>
                        <td class="r">&#8369;<?php echo number_format($fuel['price'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="fuelPagination"></div>
    </div>
</div>

<!-- ══ FUEL STOCK REQUEST MODAL ══ -->
<div class="sr-modal-overlay" id="fuelSrModal">
    <div class="sr-modal-box">
        <div class="sr-modal-head">
            <div class="sr-modal-title">
                <i class="fas fa-gas-pump" style="color:#002F70;"></i>
                Fuel Stock Request
            </div>
            <button class="sr-modal-close" id="fuelSrClose">&times;</button>
        </div>

        <div class="sr-info-box">
            <i class="fas fa-info-circle"></i>
            <strong>Select the fuel types you want to request, then click Submit.</strong><br>
            &bull; Manager will review and set the approved liters<br>
            &bull; Fuel inventory is NOT updated until Manager processes the delivery
        </div>

        <!-- Select-all bar -->
        <div class="fsr-select-bar">
            <input type="checkbox" id="fsrSelectAll">
            <label for="fsrSelectAll" style="cursor:pointer;margin:0;">Select All</label>
            <span id="fsrSelectedCount" style="margin-left:auto;color:#002F70;"></span>
        </div>

        <!-- Fuel list with checkboxes -->
        <div id="fsrCheckList"></div>

        <div id="fsrError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:13px;"></div>

        <div class="sr-modal-footer">
            <button type="button" id="fsrCancelBtn"
                    style="padding:9px 22px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">
                Cancel
            </button>
            <button type="button" id="fsrSubmitBtn"
                    style="padding:9px 22px;background:#002F70;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
        </div>
    </div>
</div>

<!-- ── Success popup ── -->
<div id="fsrSuccessOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10998;"></div>
<div id="fsrSuccessPopup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10999;background:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);text-align:center;min-width:300px;">
    <div style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-check" style="color:#fff;font-size:22px;"></i>
    </div>
    <h3 style="margin:0 0 8px;color:#28a745;">Request Submitted!</h3>
    <p style="margin:0 0 18px;color:#333;font-size:14px;line-height:1.5;" id="fsrSuccessMsg">
        Your fuel stock request is now <strong>Pending</strong> Manager review.
    </p>
    <button onclick="closeFsrSuccess()" style="background:#002F70;color:#fff;border:none;padding:9px 26px;border-radius:6px;cursor:pointer;font-weight:600;">OK</button>
</div>

<script>
var allFuelData = <?php echo json_encode($js_fuel); ?>;

// ── Open modal ────────────────────────────────────────────────────────────────
function openFuelSrModal() {
    renderFsrCheckList();
    syncFsrSelectAll();
    document.getElementById('fsrError').style.display = 'none';
    document.getElementById('fsrSubmitBtn').disabled  = false;
    document.getElementById('fsrSubmitBtn').innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
    document.getElementById('fuelSrModal').classList.add('open');
}

function renderFsrCheckList() {
    // Only show fuels that need replenishment — exclude AVAILABLE status
    var needsRestock = allFuelData.filter(function(it) {
        var s = (it.status || '').toUpperCase();
        return s === 'CRITICAL' || s === 'LOW' || s === 'LOW STOCK' || s === 'OUT OF STOCK';
    });

    if (needsRestock.length === 0) {
        document.getElementById('fsrCheckList').innerHTML =
            '<div style="text-align:center;padding:28px 16px;color:#6c757d;">' +
            '<i class="fas fa-check-circle" style="font-size:2.5em;display:block;margin-bottom:10px;color:#28a745;opacity:.5;"></i>' +
            '<strong>All fuel tanks are at sufficient levels.</strong><br>' +
            '<small>Stock requests are only needed for Critical, Low, or Out-of-Stock fuels.</small></div>';
        document.getElementById('fsrSubmitBtn').disabled = true;
        return;
    }

    document.getElementById('fsrSubmitBtn').disabled = false;

    var html = needsRestock.map(function(it) {
        // Find the original index in allFuelData so submission still works
        var idx = allFuelData.indexOf(it);
        var bar = '<div style="background:#e9ecef;border-radius:3px;height:6px;width:70px;display:inline-block;vertical-align:middle;margin:0 5px;">' +
                  '<div style="width:' + Math.min(100, it.pct) + '%;height:100%;background:' + it.color + ';border-radius:3px;"></div></div>';
        var badge = '<span style="background:' + it.color + '20;color:' + it.color + ';border:1px solid ' + it.color + '40;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:700;">' + esc(it.status) + '</span>';
        return '<label class="fsr-cb-row ' + it.statusCls + '" data-idx="' + idx + '">' +
            '<input type="checkbox" class="fsr-cb fsr-item-cb" data-idx="' + idx + '">' +
            '<div class="fsr-item-info">' +
                '<div class="fsr-item-name"><i class="fas fa-gas-pump" style="color:' + it.color + ';margin-right:5px;"></i>' + esc(it.name) + '</div>' +
                '<div class="fsr-item-meta">' +
                    it.level.toLocaleString('en-PH',{minimumFractionDigits:2}) + ' L / ' +
                    it.capacity.toLocaleString('en-PH',{minimumFractionDigits:2}) + ' L ' +
                    bar + it.pct + '% &bull; ' + badge +
                '</div>' +
            '</div>' +
        '</label>';
    }).join('');
    document.getElementById('fsrCheckList').innerHTML = html;

    // Highlight row when checkbox changes
    document.querySelectorAll('.fsr-item-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            this.closest('.fsr-cb-row').classList.toggle('checked', this.checked);
            syncFsrSelectAll();
        });
    });
}

function syncFsrSelectAll() {
    var all     = document.querySelectorAll('.fsr-item-cb');
    var checked = document.querySelectorAll('.fsr-item-cb:checked');
    var sa = document.getElementById('fsrSelectAll');
    sa.indeterminate = checked.length > 0 && checked.length < all.length;
    sa.checked       = all.length > 0 && checked.length === all.length;
    document.getElementById('fsrSelectedCount').textContent =
        checked.length > 0 ? checked.length + ' selected' : '';
}

document.getElementById('fsrSelectAll').addEventListener('change', function() {
    var c = this.checked;
    document.querySelectorAll('.fsr-item-cb').forEach(function(cb) { cb.checked = c; });
    document.querySelectorAll('.fsr-cb-row').forEach(function(row) { row.classList.toggle('checked', c); });
    syncFsrSelectAll();
});

// ── Close ─────────────────────────────────────────────────────────────────────
function closeFuelSrModal() { document.getElementById('fuelSrModal').classList.remove('open'); }
document.getElementById('fuelSrClose').addEventListener('click', closeFuelSrModal);
document.getElementById('fsrCancelBtn').addEventListener('click', closeFuelSrModal);
document.getElementById('fuelSrModal').addEventListener('click', function(e) { if (e.target === this) closeFuelSrModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeFuelSrModal(); });

// ── Submit ────────────────────────────────────────────────────────────────────
document.getElementById('fsrSubmitBtn').addEventListener('click', function() {
    var checked = document.querySelectorAll('.fsr-item-cb:checked');
    if (checked.length === 0) {
        var el = document.getElementById('fsrError');
        el.textContent = 'Please select at least one fuel type.';
        el.style.display = 'block';
        return;
    }

    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    document.getElementById('fsrError').style.display = 'none';

    var queue = [];
    checked.forEach(function(cb) { queue.push(allFuelData[parseInt(cb.dataset.idx)]); });

    var results = { ok: 0, fail: 0, errors: [] };

    function submitNext() {
        if (queue.length === 0) {
            closeFuelSrModal();
            var msg = results.ok + ' fuel request' + (results.ok !== 1 ? 's' : '') + ' submitted successfully.';
            if (results.fail > 0) msg += ' ' + results.fail + ' failed: ' + results.errors.join('; ');
            document.getElementById('fsrSuccessMsg').innerHTML = msg;
            document.getElementById('fsrSuccessPopup').style.display  = 'block';
            document.getElementById('fsrSuccessOverlay').style.display = 'block';
            setTimeout(closeFsrSuccess, 6000);
            return;
        }
        var it = queue.shift();
        fetch('../backend/api/fuel_stock_request.php?action=create', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                fuel_type:        it.name,
                current_level:    it.level,
                capacity:         it.capacity,
                stock_status:     it.status,
                requested_liters: 0,
                remarks:          ''
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) results.ok++;
            else { results.fail++; results.errors.push(it.name + ': ' + (res.message || 'error')); }
            submitNext();
        })
        .catch(function() {
            results.fail++;
            results.errors.push(it.name + ': network error');
            submitNext();
        });
    }
    submitNext();
});

function closeFsrSuccess() {
    document.getElementById('fsrSuccessPopup').style.display  = 'none';
    document.getElementById('fsrSuccessOverlay').style.display = 'none';
    location.reload();
}
function esc(str) { var d = document.createElement('div'); d.appendChild(document.createTextNode(str)); return d.innerHTML; }

document.addEventListener('DOMContentLoaded', function() {
    ['fuelSrModal','fsrSuccessOverlay','fsrSuccessPopup'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    setupTablePagination('fuelTable', 'fuelRowsLimit', 'fuelPagination', 10);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
