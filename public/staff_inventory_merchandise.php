<?php
/**
 * Staff Merchandise Inventory
 * Shows all merchandise with stock levels.
 * Staff can submit Stock Requests for any item (required for Low/Out of Stock).
 */
$page_id = 'inv_merch';
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

$merch_inventory = [];
$msg = '';
try {
    $stmt = $pdo->prepare("
        SELECT ip.id,
               ip.product_name AS name,
               ip.category     AS category_name,
               ip.unit_price   AS price,
               ip.unit_cost    AS cost,
               ip.sku,
               COALESCE(si.stock_level, ip.stock, 0) AS stock_level,
               COALESCE(si.reorder_level, 10)        AS reorder_level
        FROM inventory_products ip
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category NOT IN ('Fuel')
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading merchandise: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Page layout ── */
.inv-card {
    background:#fff; border-radius:12px;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    border:1px solid #e9ecef; margin-bottom:20px;
}
.inv-card-head {
    display:flex; align-items:center; justify-content:space-between;
    padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px;
}
.inv-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.inv-card-body  { padding:20px; }

/* ── Table ── */
.cat-header td {
    font-weight:700; background:#e9ecef !important; color:#495057 !important;
    text-transform:uppercase; font-size:.8em; letter-spacing:.5px;
    border-bottom:2px solid #dee2e6; padding:8px 12px;
}
.merch-row:hover { background:#f8f9fa; }
.cost-col  { color:#6c757d; font-size:.9em; }
.price-col { color:#28a745; font-weight:700; }
.profit-sm { font-size:.76em; color:#17a2b8; margin-left:3px; }

/* ── Stock Request button ── */
.sr-btn {
    border:none; padding:5px 12px; font-size:12px; border-radius:4px;
    cursor:pointer; transition:background .15s; font-weight:600;
    display:inline-flex; align-items:center; gap:5px;
}
.sr-btn.urgent {
    background:#dc3545; color:#fff;
}
.sr-btn.urgent:hover { background:#b02a37; }
.sr-btn.warn {
    background:#fd7e14; color:#fff;
}
.sr-btn.warn:hover { background:#d96a0e; }
.sr-btn.normal {
    background:#002F70; color:#fff;
}
.sr-btn.normal:hover { background:#001F4F; }

/* ── Search ── */
#merchSearch {
    padding:8px 12px; border:1px solid #ced4da;
    border-radius:4px; font-size:14px; width:100%;
}
#merchSearch:focus { border-color:#80bdff; outline:0; box-shadow:0 0 0 .2rem rgba(0,123,255,.25); }
.search-wrap { max-width:300px; margin-bottom:14px; }

/* ── Modal ── */
.sr-modal-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.55); z-index:9999;
    align-items:center; justify-content:center;
}
.sr-modal-overlay.open { display:flex; }
.sr-modal-box {
    background:#fff; border-radius:14px; padding:28px;
    width:600px; max-width:calc(100vw - 32px);
    max-height:calc(100vh - 40px); overflow-y:auto;
    box-shadow:0 24px 80px rgba(0,0,0,.3);
    animation:srModalIn .2s ease;
    position:relative; z-index:10000;
}
@keyframes srModalIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
.sr-modal-head {
    display:flex; justify-content:space-between; align-items:center;
    margin-bottom:20px; padding-bottom:14px; border-bottom:2px solid #e9ecef;
}
.sr-modal-title { font-size:1.05rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.sr-modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#adb5bd; line-height:1; }
.sr-modal-close:hover { color:#333; }

/* ── Form grid ── */
.sr-field-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
.sr-field label { display:block; margin-bottom:5px; font-weight:700; font-size:12px; color:#495057; text-transform:uppercase; letter-spacing:.4px; }
.sr-field input[type=text],
.sr-field input[type=number],
.sr-field textarea {
    width:100%; padding:9px 11px; border:1px solid #dee2e6;
    border-radius:6px; font-size:13px; box-sizing:border-box;
}
.sr-field input[readonly] { background:#f8f9fa; color:#6c757d; }
.sr-field input[type=number]:focus,
.sr-field textarea:focus {
    border-color:#002F70; outline:none;
    box-shadow:0 0 0 3px rgba(0,47,112,.12);
}
.sr-field textarea { resize:vertical; }

/* ── Status chip inside modal ── */
.sr-status-chip {
    display:inline-block; padding:4px 12px; border-radius:20px;
    font-size:12px; font-weight:700; text-transform:uppercase;
}
.sr-status-chip.out  { background:#fee2e2; color:#dc3545; }
.sr-status-chip.low  { background:#fff3cd; color:#856404; }
.sr-status-chip.ok   { background:#d4edda; color:#155724; }

/* ── Info box ── */
.sr-info-box {
    background:#e8f4fd; border-left:4px solid #002F70;
    border-radius:6px; padding:10px 14px; margin-bottom:16px;
    font-size:12px; color:#002F70; line-height:1.6;
}

/* ── Modal footer ── */
.sr-modal-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; padding-top:14px; border-top:1px solid #e9ecef; }

/* ── Success popup ── */
.sr-success-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:10998; }
.sr-success-popup {
    display:none; position:fixed; top:50%; left:50%;
    transform:translate(-50%,-50%); z-index:10999;
    background:#fff; padding:28px; border-radius:12px;
    box-shadow:0 10px 30px rgba(0,0,0,.25); text-align:center; min-width:300px;
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-boxes"></i> Merchandise Inventory</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; Submit Stock Requests for any item</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title">
            <i class="fas fa-box"></i> Merchandise Stock
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <?php
            $low_count = 0; $out_count = 0;
            foreach ($merch_inventory as $it) {
                $sl = (int)($it['stock_level'] ?? 0);
                $rl = (int)($it['reorder_level'] ?? 10);
                if ($sl <= 0) $out_count++;
                elseif ($sl <= $rl) $low_count++;
            }
            if ($out_count > 0): ?>
                <span style="background:#fee2e2;color:#dc3545;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:700;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $out_count; ?> Out of Stock
                </span>
            <?php endif; if ($low_count > 0): ?>
                <span style="background:#fff3cd;color:#856404;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:700;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $low_count; ?> Low Stock
                </span>
            <?php endif; ?>
            <span style="font-size:13px;color:#6c757d;"><?php echo count($merch_inventory); ?> products</span>
        </div>
    </div>
    <div class="inv-card-body">
        <div class="search-wrap">
            <input id="merchSearch" placeholder="&#128269; Search products..." autocomplete="off" />
        </div>

        <div class="table-wrap">
            <table class="table" id="merchTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Stock</th>
                        <th>Cost</th>
                        <th>Price</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php
                $categories = [];
                foreach ($merch_inventory as $item) {
                    $cat = $item['category_name'] ?? 'Uncategorized';
                    $categories[$cat][] = $item;
                }
                $cat_order = [
                    'Oils / Lubes / Grease','Car Accessories','Brake System',
                    'Tire','Maintenance','Oil / Fuel Filters','Others (Snacks / Drinks)'
                ];
                $sorted = [];
                foreach ($cat_order as $k) { if (isset($categories[$k])) $sorted[$k] = $categories[$k]; }
                foreach ($categories as $k => $v) { if (!in_array($k, $cat_order)) $sorted[$k] = $v; }

                foreach ($sorted as $cat_label => $items): ?>
                    <tr class="cat-header">
                        <td colspan="7"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td>
                    </tr>
                    <?php foreach ($items as $item):
                        $stock  = (float)($item['stock_level'] ?? 0);
                        $reord  = (float)($item['reorder_level'] ?? 10);
                        $profit = (float)($item['price'] ?? 0) - (float)($item['cost'] ?? 0);

                        if ($stock <= 0) {
                            $st_label = 'OUT OF STOCK';
                            $st_color = '#dc3545';
                            $btn_cls  = 'urgent';
                            $btn_icon = 'fa-exclamation-circle';
                        } elseif ($stock <= $reord) {
                            $st_label = 'LOW STOCK';
                            $st_color = '#fd7e14';
                            $btn_cls  = 'warn';
                            $btn_icon = 'fa-exclamation-triangle';
                        } else {
                            $st_label = 'AVAILABLE';
                            $st_color = '#28a745';
                            $btn_cls  = 'normal';
                            $btn_icon = 'fa-box';
                        }
                    ?>
                    <tr class="merch-row" data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>">
                        <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                        <td><code style="font-size:11px;"><?php echo htmlspecialchars($item['sku'] ?? ''); ?></code></td>
                        <td><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></td>
                        <td style="font-weight:700;color:<?php echo $st_color; ?>;"><?php echo number_format($stock, 0); ?></td>
                        <td class="cost-col">&#8369;<?php echo number_format((float)($item['cost'] ?? 0), 2); ?></td>
                        <td class="price-col">
                            &#8369;<?php echo number_format((float)($item['price'] ?? 0), 2); ?>
                            <?php if ($profit > 0): ?>
                                <span class="profit-sm">(+<?php echo number_format($profit, 2); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="sr-btn <?php echo $btn_cls; ?> stock-request-btn"
                                    data-item-id="<?php echo (int)($item['id'] ?? 0); ?>"
                                    data-sku="<?php echo htmlspecialchars($item['sku'] ?? ''); ?>"
                                    data-name="<?php echo htmlspecialchars($item['name']); ?>"
                                    data-category="<?php echo htmlspecialchars($item['category_name'] ?? ''); ?>"
                                    data-current-stock="<?php echo (int)$stock; ?>"
                                    data-status="<?php echo $st_label; ?>"
                                    data-status-color="<?php echo $st_color; ?>">
                                <i class="fas <?php echo $btn_icon; ?>"></i> Stock Request
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <?php if (empty($merch_inventory)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:28px;color:#6c757d;">No merchandise data available.</td></tr>
                <?php else: ?>
                    <tr style="background:#f8f9fa;font-weight:700;border-top:2px solid #dee2e6;">
                        <td colspan="4">TOTAL</td>
                        <td colspan="3"><?php echo count($merch_inventory); ?> items &mdash; <?php echo count($categories); ?> categories</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     STOCK REQUEST MODAL
══════════════════════════════════════════ -->
<div class="sr-modal-overlay" id="srModal">
    <div class="sr-modal-box">
        <div class="sr-modal-head">
            <div class="sr-modal-title">
                <i class="fas fa-box" style="color:#002F70;"></i>
                Stock Request
            </div>
            <button class="sr-modal-close" id="srModalClose">&times;</button>
        </div>

        <form id="srForm">
            <!-- Hidden fields -->
            <input type="hidden" id="srItemId">
            <input type="hidden" id="srSku">
            <input type="hidden" id="srName">
            <input type="hidden" id="srCategory">
            <input type="hidden" id="srCurStock">

            <!-- Auto-populated read-only info -->
            <div class="sr-field-grid">
                <div class="sr-field">
                    <label>Product Name</label>
                    <input type="text" id="srNameD" readonly>
                </div>
                <div class="sr-field">
                    <label>SKU</label>
                    <input type="text" id="srSkuD" readonly>
                </div>
                <div class="sr-field">
                    <label>Category</label>
                    <input type="text" id="srCategoryD" readonly>
                </div>
                <div class="sr-field">
                    <label>Current Stock</label>
                    <input type="text" id="srCurStockD" readonly>
                </div>
                <div class="sr-field">
                    <label>Status</label>
                    <div id="srStatusChipWrap" style="padding-top:4px;"></div>
                </div>
                <div class="sr-field">
                    <label>Quantity Requested <span style="color:#dc3545;">*</span></label>
                    <input type="number" id="srQty" min="1" required
                           placeholder="Enter quantity..."
                           style="font-size:15px;font-weight:700;color:#002F70;">
                </div>
            </div>

            <div class="sr-field" style="margin-bottom:16px;">
                <label>Remarks <span style="color:#6c757d;font-weight:400;text-transform:none;">(optional)</span></label>
                <textarea id="srRemarks" rows="3"
                          placeholder="e.g. Need for brake pad replacement, running low on shelf..."></textarea>
            </div>

            <div class="sr-info-box">
                <i class="fas fa-info-circle"></i>
                <strong>What happens next:</strong><br>
                &bull; Request status → <strong>Pending</strong> (Manager review)<br>
                &bull; Manager will Approve or Reject with notes<br>
                &bull; Audit trail logged: Staff ID, Item, Quantity, Timestamp
            </div>

            <div id="srError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:13px;"></div>

            <div class="sr-modal-footer">
                <button type="button" id="srCancelBtn"
                        style="padding:9px 22px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" id="srSubmitBtn"
                        style="padding:9px 22px;background:#002F70;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                    <i class="fas fa-paper-plane"></i> Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Success popup ── -->
<div class="sr-success-overlay" id="srSuccessOverlay"></div>
<div class="sr-success-popup" id="srSuccessPopup">
    <div style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-check" style="color:#fff;font-size:22px;"></i>
    </div>
    <h3 style="margin:0 0 8px;color:#28a745;">Request Submitted!</h3>
    <p style="margin:0 0 18px;color:#333;font-size:14px;line-height:1.5;" id="srSuccessMsg">
        Your stock request is now <strong>Pending</strong> Manager review.
    </p>
    <button onclick="closeSrSuccess()"
            style="background:#002F70;color:#fff;border:none;padding:9px 26px;border-radius:6px;cursor:pointer;font-weight:600;">
        OK
    </button>
</div>

<script>
// ── Move modal to body so it's never clipped ──────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    ['srModal','srSuccessOverlay','srSuccessPopup'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
});

// ── Search ────────────────────────────────────────────────────────────────────
document.getElementById('merchSearch').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        r.style.display = (r.getAttribute('data-name') || '').indexOf(q) !== -1 ? '' : 'none';
    });
});

// ── Open modal ────────────────────────────────────────────────────────────────
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.stock-request-btn');
    if (!btn) return;

    var stock  = parseInt(btn.dataset.currentStock) || 0;
    var status = btn.dataset.status || '';
    var color  = btn.dataset.statusColor || '#002F70';

    document.getElementById('srItemId').value   = btn.dataset.itemId    || '';
    document.getElementById('srSku').value      = btn.dataset.sku       || '';
    document.getElementById('srName').value     = btn.dataset.name      || '';
    document.getElementById('srCategory').value = btn.dataset.category  || '';
    document.getElementById('srCurStock').value = stock;

    document.getElementById('srNameD').value     = btn.dataset.name     || '';
    document.getElementById('srSkuD').value      = btn.dataset.sku      || '';
    document.getElementById('srCategoryD').value = btn.dataset.category || '';
    document.getElementById('srCurStockD').value = stock + ' units';

    // Status chip
    var chipCls = stock <= 0 ? 'out' : (stock <= 10 ? 'low' : 'ok');
    document.getElementById('srStatusChipWrap').innerHTML =
        '<span class="sr-status-chip ' + chipCls + '">' + status + '</span>';

    // Reset form state
    document.getElementById('srQty').value     = '';
    document.getElementById('srRemarks').value = '';
    document.getElementById('srError').style.display = 'none';
    document.getElementById('srSubmitBtn').disabled = false;
    document.getElementById('srSubmitBtn').innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';

    document.getElementById('srModal').classList.add('open');
    setTimeout(function() { document.getElementById('srQty').focus(); }, 120);
});

// ── Close modal ───────────────────────────────────────────────────────────────
function closeSrModal() {
    document.getElementById('srModal').classList.remove('open');
}
document.getElementById('srModalClose').addEventListener('click', closeSrModal);
document.getElementById('srCancelBtn').addEventListener('click', closeSrModal);
document.getElementById('srModal').addEventListener('click', function(e) {
    if (e.target === this) closeSrModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSrModal();
});

// ── Submit ────────────────────────────────────────────────────────────────────
document.getElementById('srForm').addEventListener('submit', function(e) {
    e.preventDefault();

    var qty = parseInt(document.getElementById('srQty').value) || 0;
    if (qty <= 0) {
        showSrError('Please enter a valid quantity (minimum 1).');
        return;
    }

    var payload = {
        item_id:            document.getElementById('srItemId').value,
        sku:                document.getElementById('srSku').value,
        item_name:          document.getElementById('srName').value,
        item_category:      document.getElementById('srCategory').value,
        current_stock:      document.getElementById('srCurStock').value,
        requested_quantity: qty,
        remarks:            document.getElementById('srRemarks').value.trim()
    };

    var btn = document.getElementById('srSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    document.getElementById('srError').style.display = 'none';

    fetch('../backend/api/stock_request.php?action=create', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            closeSrModal();
            document.getElementById('srSuccessMsg').innerHTML =
                'Your stock request for <strong>' + escHtml(res.item_name) + '</strong> (' +
                res.requested_quantity + ' units) is now <strong>Pending</strong> Manager review.';
            document.getElementById('srSuccessPopup').style.display  = 'block';
            document.getElementById('srSuccessOverlay').style.display = 'block';
            setTimeout(closeSrSuccess, 6000);
        } else {
            showSrError(res.message || 'Failed to submit request.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        }
    })
    .catch(function() {
        showSrError('Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
    });
});

function showSrError(msg) {
    var el = document.getElementById('srError');
    el.textContent = msg;
    el.style.display = 'block';
}

function closeSrSuccess() {
    document.getElementById('srSuccessPopup').style.display  = 'none';
    document.getElementById('srSuccessOverlay').style.display = 'none';
    location.reload();
}

function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
