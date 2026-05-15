<?php
$page_id = 'mgr_merch_deliveries';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS merchandise_deliveries (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        delivery_ref       VARCHAR(50)  NOT NULL,
        supplier_name      VARCHAR(200) NOT NULL DEFAULT '',
        product_name       VARCHAR(200) NOT NULL DEFAULT '',
        category           VARCHAR(100) NOT NULL DEFAULT '',
        quantity_delivered DECIMAL(12,2) NOT NULL DEFAULT 0,
        delivery_date      DATETIME     NOT NULL,
        encoded_by         INT          DEFAULT NULL,
        station_id         INT          NOT NULL,
        status             ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
        manager_id         INT          DEFAULT NULL,
        manager_action_at  DATETIME     DEFAULT NULL,
        remarks            TEXT         DEFAULT NULL,
        manager_reason     TEXT         DEFAULT NULL,
        created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_station (station_id),
        INDEX idx_status  (status),
        INDEX idx_date    (delivery_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?><style>
.sbadge{
    display:inline-block;
    padding:4px 12px;
    border-radius:20px;
    font-size:11px;
    font-weight:700;
    white-space:nowrap;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
}
.sbadge:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
.sbadge-pending{
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    color:#856404;
    border: 1px solid #f0d43a;
}
.sbadge-approved{
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    color:#155724;
    border: 1px solid #28a745;
}
.sbadge-rejected{
    background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
    color:#721c24;
    border: 1px solid #dc3545;
}
.del-ref{
    font-family: 'Courier New', monospace;
    font-size:11px;
    background: linear-gradient(135deg, #e8f4fd 0%, #d1e7ff 100%);
    color:#002F70;
    padding:4px 8px;
    border-radius:6px;
    font-weight:700;
    display:inline-block;
    border: 1px solid #b8d4f1;
    box-shadow: 0 2px 4px rgba(0,47,112,0.1);
    transition: all 0.2s ease;
}
.del-ref:hover {
    background: linear-gradient(135deg, #d1e7ff 0%, #b8d4f1 100%);
    transform: translateY(-1px);
}
.cat-tag{font-size:10px;color:#6c757d;display:block;margin-top:2px;}

/* ── Actions column: side-by-side layout ── */
.act-wrap{display:flex;gap:4px;align-items:center;min-width:250px;flex-wrap:nowrap;}
.act-row{display:flex;gap:4px;flex-wrap:nowrap;}
.btn-act{
    padding:6px 10px;
    border:none;
    border-radius:6px;
    cursor:pointer;
    font-size:11px;
    font-weight:700;
    display:inline-flex;
    align-items:center;
    gap:4px;
    transition:all 0.2s ease;
    white-space:nowrap;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.btn-act:hover{
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}
.btn-act:active{
    transform: translateY(0);
}
.btn-approve{
    background: linear-gradient(135deg, #28a745 0%, #218838 100%);
    color:#fff;
    border: 1px solid #1e7e34;
}
.btn-approve:hover{
    background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
}
.btn-reject{
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    color:#fff;
    border: 1px solid #bd2130;
}
.btn-reject:hover{
    background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
}
.btn-adjust{
    background: linear-gradient(135deg, #002F70 0%, #0040a0 100%);
    color:#fff;
    border: 1px solid #003080;
}
.btn-adjust:hover{
    background: linear-gradient(135deg, #0040a0 0%, #003080 100%);
}
.btn-view{
    background: linear-gradient(135deg, #f0f4ff 0%, #e1e9ff 100%);
    color:#002F70;
    border: 1px solid #c5d3f0;
}
.btn-view:hover{
    background: linear-gradient(135deg, #e1e9ff 0%, #d1e7ff 100%);
}

/* ── Enhanced Table Styling ── */
.table-wrap {
    overflow-x: auto;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

#del-table{
    table-layout: auto;
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

#del-table thead {
    background: transparent;
    color: #002F70;
}

#del-table th {
    padding: 14px 12px;
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: none;
    white-space: nowrap;
    position: relative;
}

#del-table th:not(:last-child)::after {
    content: '';
    position: absolute;
    right: 0;
    top: 20%;
    height: 60%;
    width: 1px;
    background: rgba(255,255,255,0.2);
}

#del-table tbody tr {
    transition: all 0.2s ease;
    border-bottom: 1px solid #f0f0f0;
}

#del-table tbody tr:nth-child(even) {
    background: #f8f9fa;
}

#del-table tbody tr:hover {
    background: #e8f4fd;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,47,112,0.1);
    z-index: 1;
    position: relative;
}

#del-table td {
    padding: 12px 8px;
    font-size: 13px;
    border: none;
    vertical-align: middle;
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: normal;
}

/* Enhanced empty state styling */
#del-table tbody tr td[colspan] {
    text-align: center;
    padding: 48px 20px;
    color: #6c757d;
    font-size: 14px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

#del-table tbody tr td[colspan] i {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.4;
    display: block;
}

#del-table tbody tr td[colspan] strong {
    display: block;
    margin-bottom: 8px;
    font-size: 16px;
    color: #495057;
}

/* Responsive improvements */
@media (max-width: 768px) {
    #del-table {
        font-size: 12px;
    }
    
    #del-table th {
        padding: 10px 8px;
        font-size: 11px;
    }
    
    #del-table td {
        padding: 8px;
        font-size: 12px;
    }
    
    .act-wrap {
        flex-direction: column;
        min-width: auto;
    }
    
    .btn-act {
        width: 100%;
        justify-content: center;
    }
}

/* ── Table column widths ── */
#del-table th:nth-child(1),#del-table td:nth-child(1){min-width:120px;max-width:140px;}  /* Delivery ID */
#del-table th:nth-child(2),#del-table td:nth-child(2){min-width:120px;max-width:140px;}  /* Supplier */
#del-table th:nth-child(3),#del-table td:nth-child(3){min-width:130px;max-width:160px;}  /* Product */
#del-table th:nth-child(4),#del-table td:nth-child(4){min-width:70px;max-width:90px;text-align:right;}  /* Qty */
#del-table th:nth-child(5),#del-table td:nth-child(5){min-width:100px;max-width:120px;}  /* Date */
#del-table th:nth-child(6),#del-table td:nth-child(6){min-width:90px;max-width:110px;}  /* Encoded By */
#del-table th:nth-child(7),#del-table td:nth-child(7){min-width:80px;max-width:100px;}   /* Status */
#del-table th:nth-child(8),#del-table td:nth-child(8){min-width:100px;max-width:130px;}  /* Remarks */
#del-table th:nth-child(9),#del-table td:nth-child(9){min-width:140px;max-width:180px;}  /* Actions */
.filter-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;}
.filter-row .fg{display:flex;flex-direction:column;gap:3px;}
.filter-row .fg label{font-size:10px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;}
.filter-row select,.filter-row input{
    padding:7px 10px;
    border:1px solid #dee2e6;
    border-radius:6px;
    font-size:13px;
    min-width:130px;
    background: #ffffff;
    transition: all 0.2s ease;
}
.filter-row select:focus,.filter-row input:focus{
    border-color:#002F70;
    outline:none;
    box-shadow:0 0 0 3px rgba(0,47,112,.1);
    background: #ffffff;
}
.modal-overlay{display:none;position:fixed;inset:0;width:100vw;height:100vh;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:14px;padding:28px;width:580px;max-width:calc(100vw - 32px);max-height:calc(100vh - 40px);overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:mIn .2s ease;position:relative;z-index:10000;}
@keyframes mIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid #e9ecef;}
.modal-title{font-size:1.05rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:#adb5bd;line-height:1;}
.modal-close:hover{color:#333;}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid #e9ecef;}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fld{margin-bottom:14px;}
.fld label{display:block;margin-bottom:5px;font-weight:700;font-size:11px;color:#495057;text-transform:uppercase;letter-spacing:.4px;}
.fld input,.fld textarea{
    width:100%;
    padding:9px 11px;
    border:1px solid #dee2e6;
    border-radius:6px;
    font-size:13px;
    box-sizing:border-box;
    font-family:inherit;
    background: #ffffff;
    transition: all 0.2s ease;
}
.fld input[readonly]{
    background: #f8f9fa;
    color:#6c757d;
    border-color: #ced4da;
}
.fld input:focus,.fld textarea:focus{
    border-color:#002F70;
    outline:none;
    box-shadow:0 0 0 3px rgba(0,47,112,.1);
    background: #ffffff;
}
.fld textarea{resize:vertical;}
.ibox{background:#e8f4fd;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#002F70;line-height:1.6;}
.wbox{background:#fff3cd;border-left:4px solid #856404;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#856404;line-height:1.6;}
.drow{display:flex;gap:8px;padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:13px;}
.drow:last-child{border-bottom:none;}
.dlbl{font-weight:600;color:#6c757d;min-width:150px;font-size:11px;text-transform:uppercase;letter-spacing:.3px;}
.dval{color:#212529;flex:1;}
.toast{position:fixed;bottom:24px;right:24px;padding:13px 20px;border-radius:8px;color:#fff;font-weight:600;font-size:13px;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.2);display:none;animation:tUp .25s ease;max-width:340px;}
.toast.show{display:block;}
.toast-success{background:#28a745;}
.toast-error{background:#dc3545;}
@keyframes tUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
</style>
<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-truck"></i> Deliveries Management</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; Merchandise deliveries &mdash; Approve, Reject, or Adjust</div>
    </div>
    <div class="header-actions">
        <button onclick="exportExcel()" class="btn" style="background:#28a745;color:#fff;"><i class="fas fa-file-excel"></i> Excel</button>
        <button onclick="exportPDF()" class="btn" style="background:#dc3545;color:#fff;"><i class="fas fa-file-pdf"></i> PDF</button>
        <button onclick="loadDeliveries()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<div class="filter-row" style="margin: 20px 0; padding: 0 20px;">
    <div class="fg">
        <label>Status</label>
        <select id="f-status" onchange="loadDeliveries()">
            <option value="">All Statuses</option>
            <option value="Pending">Pending</option>
            <option value="Approved">Approved</option>
            <option value="Rejected">Rejected</option>
        </select>
    </div>
    <div class="fg">
        <label>Supplier</label>
        <input type="text" id="f-supplier" placeholder="Search supplier...">
    </div>
    <div class="fg">
        <label>From</label>
        <input type="date" id="f-start" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" onchange="loadDeliveries()">
    </div>
    <div class="fg">
        <label>To</label>
        <input type="date" id="f-end" value="<?php echo date('Y-m-d'); ?>" onchange="loadDeliveries()">
    </div>
</div>

<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title">
            <i class="fas fa-boxes"></i> Merchandise Deliveries
        </div>
            </div>
    <div class="inv-card-body">

        <div class="table-wrap">
            <table class="table" id="del-table">
                <thead>
                    <tr>
                        <th>Delivery ID</th>
                        <th>Supplier Name</th>
                        <th>Product / Category</th>
                        <th>Qty Delivered</th>
                        <th>Date &amp; Time</th>
                        <th>Encoded By</th>
                        <th>Status</th>
                        <th>Remarks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="del-tbody">
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px;color:#6c757d;">
                            <i class="fas fa-spinner fa-spin" style="font-size:1.6rem;display:block;margin-bottom:10px;"></i>
                            Loading deliveries...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- APPROVE MODAL -->
<div class="modal-overlay" id="aprModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-check-circle" style="color:#28a745;"></i> Approve Delivery</div>
            <button class="modal-close" onclick="closeM('aprModal')">&times;</button>
        </div>
        <div class="ibox"><i class="fas fa-info-circle"></i> Approving will <strong>automatically update inventory</strong> — delivered quantity added to stock.</div>
        <div class="fg2">
            <div class="fld"><label>Delivery ID</label><input type="text" id="apr-ref" readonly></div>
            <div class="fld"><label>Supplier</label><input type="text" id="apr-sup" readonly></div>
        </div>
        <div class="fg2">
            <div class="fld"><label>Product</label><input type="text" id="apr-prod" readonly></div>
            <div class="fld"><label>Qty Delivered</label><input type="text" id="apr-qty" readonly></div>
        </div>
        <div class="fld"><label>Optional Remarks</label><textarea id="apr-rmk" rows="2" placeholder="Optional notes..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('aprModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doApprove()" class="btn" style="background:#28a745;color:#fff;font-weight:700;"><i class="fas fa-check"></i> Approve &amp; Update Inventory</button>
        </div>
    </div>
</div>

<!-- REJECT MODAL -->
<div class="modal-overlay" id="rejModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Delivery</div>
            <button class="modal-close" onclick="closeM('rejModal')">&times;</button>
        </div>
        <div class="wbox"><i class="fas fa-exclamation-triangle"></i> Delivery will be <strong>returned to Staff for correction</strong>. Reason is required.</div>
        <div class="fg2">
            <div class="fld"><label>Delivery ID</label><input type="text" id="rej-ref" readonly></div>
            <div class="fld"><label>Qty Delivered</label><input type="text" id="rej-qty" readonly></div>
        </div>
        <div class="fld"><label>Product</label><input type="text" id="rej-prod" readonly></div>
        <div class="fld"><label>Rejection Reason <span style="color:#dc3545;">*</span></label><textarea id="rej-rsn" rows="3" placeholder="Explain why this delivery is being rejected..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('rejModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doReject()" class="btn" style="background:#dc3545;color:#fff;font-weight:700;"><i class="fas fa-times"></i> Reject &amp; Return to Staff</button>
        </div>
    </div>
</div>

<!-- ADJUST MODAL -->
<div class="modal-overlay" id="adjModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-sliders-h" style="color:#002F70;"></i> Adjust Delivery</div>
            <button class="modal-close" onclick="closeM('adjModal')">&times;</button>
        </div>
        <div class="ibox"><i class="fas fa-info-circle"></i> Minor corrections only. <strong>Reason is mandatory</strong> for all adjustments.</div>
        <div class="fg2">
            <div class="fld"><label>Delivery ID</label><input type="text" id="adj-ref" readonly></div>
            <div class="fld"><label>Product</label><input type="text" id="adj-prod" readonly></div>
        </div>
        <div class="fg2">
            <div class="fld"><label>Qty Delivered <span style="color:#dc3545;">*</span></label><input type="number" id="adj-qty" min="0.01" step="0.01"></div>
            <div class="fld"><label>Supplier</label><input type="text" id="adj-sup"></div>
        </div>
        <div class="fld"><label>Remarks</label><input type="text" id="adj-rmk" placeholder="Optional remarks..."></div>
        <div class="fld"><label>Adjustment Reason <span style="color:#dc3545;">*</span></label><textarea id="adj-rsn" rows="3" placeholder="Mandatory: explain the reason for this adjustment..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('adjModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doAdjust()" class="btn" style="background:#002F70;color:#fff;font-weight:700;"><i class="fas fa-save"></i> Save Adjustment</button>
        </div>
    </div>
</div>

<!-- DETAIL MODAL -->
<div class="modal-overlay" id="dtlModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-file-alt" style="color:#17a2b8;"></i> Delivery Details</div>
            <button class="modal-close" onclick="closeM('dtlModal')">&times;</button>
        </div>
        <div id="dtl-body"><div style="text-align:center;padding:32px;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('dtlModal')" class="btn ghost">Close</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>
<script>
var API = '../backend/api/manager_merchandise_deliveries_api.php';
var CID = null;
var _t = null;

document.addEventListener('DOMContentLoaded', function() {
    ['aprModal','rejModal','adjModal','dtlModal'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    document.getElementById('f-supplier').addEventListener('input', function() {
        clearTimeout(_t); _t = setTimeout(loadDeliveries, 400);
    });
    loadDeliveries();
});

function openM(id)  { document.getElementById(id).classList.add('open'); }
function closeM(id) { document.getElementById(id).classList.remove('open'); }

document.addEventListener('click', function(e) {
    ['aprModal','rejModal','adjModal','dtlModal'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && e.target === el) closeM(id);
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') ['aprModal','rejModal','adjModal','dtlModal'].forEach(closeM);
});

function toast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast toast-' + (type || 'success') + ' show';
    setTimeout(function() { t.classList.remove('show'); }, 3800);
}

function h(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function j(s) { return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }
function dt(s) { return s ? String(s).replace('T',' ').substring(0,16) : '<span style="color:#adb5bd">—</span>'; }

function loadDeliveries() {
    var status   = document.getElementById('f-status').value;
    var supplier = document.getElementById('f-supplier').value;
    var start    = document.getElementById('f-start').value;
    var end      = document.getElementById('f-end').value;
    var url = API + '?action=list&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
    if (status)   url += '&status='   + encodeURIComponent(status);
    if (supplier) url += '&supplier=' + encodeURIComponent(supplier);

    var tb = document.getElementById('del-tbody');
    tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:40px;color:#6c757d;"><i class="fas fa-spinner fa-spin" style="font-size:1.6rem;display:block;margin-bottom:10px;"></i>Loading...</td></tr>';

    fetch(url).then(function(r){return r.json();}).then(function(res) {
        if (!res.success) {
            tb.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#dc3545;padding:32px;"><i class="fas fa-exclamation-circle"></i> ' + h(res.message) + '</td></tr>';
            return;
        }
                var rows = res.data || [];
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:48px;color:#6c757d;"><i class="fas fa-truck" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:.3;"></i><strong>No deliveries found</strong><br><span style="font-size:12px;">Try adjusting the filters above.</span></td></tr>';
            return;
        }
        var out = '';
        rows.forEach(function(d) {
            var badge = '<span class="sbadge sbadge-' + d.display_status.toLowerCase() + '">' + h(d.display_status) + '</span>';
            var cat   = d.category ? '<span class="cat-tag">' + h(d.category) + '</span>' : '';
            var enc   = d.encoded_by_name ? h(d.encoded_by_name) : '<span style="color:#adb5bd">—</span>';
            var rem   = d.remarks ? h(String(d.remarks).substring(0,50)) + (d.remarks.length > 50 ? '…' : '') : '<span style="color:#adb5bd">—</span>';
            var qty   = parseFloat(d.quantity_delivered||0).toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:2});

            // Build action buttons — side-by-side layout
            var actBtns = '';
            console.log('Delivery Status:', d.display_status, 'Original:', d.status, 'Type:', typeof d.display_status); // Debug log
            if (d.display_status && (d.display_status.includes('Pending') || d.display_status.includes('pending'))) {
                console.log('Creating action buttons for:', d.display_status); // Debug log
                actBtns += '<button class="btn-act btn-approve" onclick="openApr(' + d.id + ',\'' + j(d.delivery_ref) + '\',\'' + j(d.product_name) + '\',' + d.quantity_delivered + ',\'' + j(d.supplier_name) + '\')"><i class="fas fa-check"></i> Approve</button>'
                    + '<button class="btn-act btn-reject"  onclick="openRej(' + d.id + ',\'' + j(d.delivery_ref) + '\',\'' + j(d.product_name) + '\',' + d.quantity_delivered + ')"><i class="fas fa-times"></i> Reject</button>'
                    + '<button class="btn-act btn-adjust"  onclick="openAdj(' + d.id + ',\'' + j(d.delivery_ref) + '\',\'' + j(d.product_name) + '\',' + d.quantity_delivered + ',\'' + j(d.supplier_name) + '\',\'' + j(d.remarks||'') + '\')"><i class="fas fa-sliders-h"></i> Adjust</button>';
            } else {
                console.log('No action buttons - status:', d.status); // Debug log
                actBtns = '';
            }
            console.log('Action buttons HTML:', actBtns); // Debug log

            out += '<tr>'
                + '<td><span class="del-ref">' + h(d.delivery_ref) + '</span></td>'
                + '<td><strong>' + h(d.supplier_name||'—') + '</strong></td>'
                + '<td><strong>' + h(d.product_name||'—') + '</strong>' + cat + '</td>'
                + '<td style="font-weight:700;color:#155724;text-align:right;">' + qty + '</td>'
                + '<td style="font-size:12px;color:#6c757d;">' + dt(d.delivery_date) + '</td>'
                + '<td style="font-size:12px;">' + enc + '</td>'
                + '<td>' + badge + '</td>'
                + '<td style="font-size:12px;color:#6c757d;">' + rem + '</td>'
                + '<td><div class="act-wrap">' + actBtns + '</div></td>'
                + '</tr>';
        });
        tb.innerHTML = out;
    }).catch(function() {
        tb.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#dc3545;padding:32px;"><i class="fas fa-wifi"></i> Network error — please refresh.</td></tr>';
    });
}

function openApr(id, ref, prod, qty, sup) {
    CID = id;
    document.getElementById('apr-ref').value  = ref;
    document.getElementById('apr-prod').value = prod;
    document.getElementById('apr-qty').value  = qty;
    document.getElementById('apr-sup').value  = sup;
    document.getElementById('apr-rmk').value  = '';
    openM('aprModal');
}
function doApprove() {
    fetch(API + '?action=approve', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CID,reason:document.getElementById('apr-rmk').value})})
    .then(function(r){return r.json();}).then(function(res){closeM('aprModal');toast(res.message,res.success?'success':'error');if(res.success)loadDeliveries();});
}

function openRej(id, ref, prod, qty) {
    CID = id;
    document.getElementById('rej-ref').value  = ref;
    document.getElementById('rej-prod').value = prod;
    document.getElementById('rej-qty').value  = qty;
    document.getElementById('rej-rsn').value  = '';
    openM('rejModal');
}
function doReject() {
    var rsn = document.getElementById('rej-rsn').value.trim();
    if (!rsn) { toast('Rejection reason is required.','error'); return; }
    fetch(API + '?action=reject', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CID,reason:rsn})})
    .then(function(r){return r.json();}).then(function(res){closeM('rejModal');toast(res.message,res.success?'success':'error');if(res.success)loadDeliveries();});
}

function openAdj(id, ref, prod, qty, sup, rmk) {
    CID = id;
    document.getElementById('adj-ref').value  = ref;
    document.getElementById('adj-prod').value = prod;
    document.getElementById('adj-qty').value  = qty;
    document.getElementById('adj-sup').value  = sup;
    document.getElementById('adj-rmk').value  = rmk;
    document.getElementById('adj-rsn').value  = '';
    openM('adjModal');
}
function doAdjust() {
    var rsn = document.getElementById('adj-rsn').value.trim();
    var qty = parseFloat(document.getElementById('adj-qty').value);
    if (!rsn)    { toast('Adjustment reason is mandatory.','error'); return; }
    if (!(qty>0)){ toast('Quantity must be greater than 0.','error'); return; }
    fetch(API + '?action=adjust', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CID,quantity_delivered:qty,supplier_name:document.getElementById('adj-sup').value,remarks:document.getElementById('adj-rmk').value,reason:rsn})})
    .then(function(r){return r.json();}).then(function(res){closeM('adjModal');toast(res.message,res.success?'success':'error');if(res.success)loadDeliveries();});
}

function openDtl(id) {
    document.getElementById('dtl-body').innerHTML = '<div style="text-align:center;padding:32px;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    openM('dtlModal');
    fetch(API + '?action=get_detail&id=' + id).then(function(r){return r.json();}).then(function(res){
        if (!res.success) { document.getElementById('dtl-body').innerHTML = '<p style="color:#dc3545;padding:16px;">' + h(res.message) + '</p>'; return; }
        var d = res.data;
        var html = dr('Delivery ID','<span class="del-ref">'+h(d.delivery_ref)+'</span>')
            + dr('Supplier','<strong>'+h(d.supplier_name||'—')+'</strong>')
            + dr('Product',h(d.product_name||'—'))
            + dr('Category',h(d.category||'—'))
            + dr('Qty Delivered','<strong style="color:#155724;">'+parseFloat(d.quantity_delivered||0).toLocaleString()+'</strong>')
            + dr('Date &amp; Time',dt(d.delivery_date))
            + dr('Encoded By',h(d.encoded_by_name||'—'))
            + dr('Status','<span class="sbadge sbadge-'+d.status.toLowerCase()+'">'+h(d.status)+'</span>')
            + dr('Remarks',h(d.remarks||'—'))
            + dr('Manager',h(d.manager_name||'—'))
            + dr('Action At',dt(d.manager_action_at))
            + dr('Manager Reason',h(d.manager_reason||'—'));
        document.getElementById('dtl-body').innerHTML = html;
    });
}
function dr(l,v){ return '<div class="drow"><span class="dlbl">'+l+'</span><span class="dval">'+v+'</span></div>'; }

function exportExcel() {
    var status   = document.getElementById('f-status').value;
    var supplier = document.getElementById('f-supplier').value;
    var start    = document.getElementById('f-start').value;
    var end      = document.getElementById('f-end').value;
    var url = API + '?action=export_excel&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
    if (status)   url += '&status='   + encodeURIComponent(status);
    if (supplier) url += '&supplier=' + encodeURIComponent(supplier);
    
    window.open(url, '_blank');
    toast('Exporting to Excel...', 'success');
}

function exportPDF() {
    var status   = document.getElementById('f-status').value;
    var supplier = document.getElementById('f-supplier').value;
    var start    = document.getElementById('f-start').value;
    var end      = document.getElementById('f-end').value;
    var url = API + '?action=export_pdf&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
    if (status)   url += '&status='   + encodeURIComponent(status);
    if (supplier) url += '&supplier=' + encodeURIComponent(supplier);
    
    window.open(url, '_blank');
    toast('Exporting to PDF...', 'success');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
