<?php
$page_id = 'deliveries_oversight';
// Determine active sub-page for sidebar highlighting
$status_param = $_GET['status'] ?? '';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin access required.';
    header('Location: dashboard.php');
    exit;
}
if ((int)$station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

$station_name = 'Unknown Station';
try {
    $s = $pdo->prepare('SELECT name FROM stations WHERE id = ? LIMIT 1');
    $s->execute([$station_id]);
    $station_name = $s->fetchColumn() ?: $station_name;
} catch (Exception $e) {}

/* ══════════════════════════════════════════════════════════
   ADD PAYMENT COLUMNS TO deliveries_oversight TABLE
══════════════════════════════════════════════════════════ */
$payment_columns = [
    "ALTER TABLE deliveries_oversight ADD COLUMN unit_price DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN expected_quantity DECIMAL(12,3) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN actual_quantity DECIMAL(12,3) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN damaged_quantity DECIMAL(12,3) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN expected_amount DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN payable_amount DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN discrepancy_type ENUM('','Partial','Damaged','Rejected','Mixed') DEFAULT ''",
];
foreach ($payment_columns as $col_sql) {
    try { $pdo->exec($col_sql); } catch (Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F70;--red:#dc3545;--green:#28a745;--orange:#fd7e14;--gray:#6c757d;--light:#f8f9fa;--purple:#6f42c1;--yellow:#ffc107;}
.page-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.page-head h1{margin:0;font-size:1.6rem;color:var(--blue);display:flex;align-items:center;gap:10px;}
.page-subtitle{font-size:13px;color:var(--gray);margin:4px 0 0;}
.header-actions{display:flex;gap:8px;flex-wrap:wrap;}
/* ── Cards ── */
.card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:20px;overflow:hidden;}
.card-header{padding:14px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.card-title{font-size:15px;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.card-body{padding:20px;}
/* ── Filter Bar ── */
.filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;}
.filter-bar .fg{display:flex;flex-direction:column;gap:3px;}
.filter-bar label{font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;}
.filter-bar input,.filter-bar select{padding:7px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--blue);outline:none;box-shadow:0 0 0 3px rgba(0,47,112,.1);}
/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .2s;text-decoration:none;}
.btn-primary{background:var(--blue);color:#fff;}.btn-primary:hover{background:#001F4F;}
.btn-success{background:var(--green);color:#fff;}.btn-success:hover{background:#218838;}
.btn-danger{background:var(--red);color:#fff;}.btn-danger:hover{background:#c82333;}
.btn-warning{background:var(--orange);color:#fff;}.btn-warning:hover{background:#e0a800;}
.btn-outline{background:#fff;color:var(--blue);border:1px solid var(--blue);}.btn-outline:hover{background:#e8f0fe;}
.btn-sm{padding:5px 10px;font-size:12px;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
/* ── Table ── */
.table-wrap{overflow-x:auto;}
table.dt{width:100%;border-collapse:collapse;font-size:13px;}
table.dt th{background:var(--light);padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:var(--gray);border-bottom:2px solid #dee2e6;white-space:nowrap;text-transform:uppercase;letter-spacing:.4px;}
table.dt td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
table.dt tr:hover td{background:#f8f9fa;}
/* ── Action Buttons ── */
.action-btn { font-size:11px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:4px; transition:all .15s; font-weight:600; min-width:90px; text-decoration:none; }
.action-btn:hover { filter:brightness(.9); transform:translateY(-1px); }
.btn-view    { background:#17a2b8; color:#fff; }
.btn-process { background:#002F70; color:#fff; }
.btn-partial { background:#fd7e14; color:#fff; }
.btn-damaged { background:#dc3545; color:#fff; }
.btn-reject  { background:#6c757d; color:#fff; }
.btn-print   { background:#6f42c1; color:#fff; }
/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;white-space:nowrap;}
.badge-expected{background:#e0f2fe;color:#0369a1;}
.badge-pending{background:#fff3cd;color:#856404;}
.badge-validated{background:#d1fae5;color:#065f46;}
.badge-approved{background:#d1fae5;color:#065f46;}
.badge-flagged{background:#fee2e2;color:#991b1b;}
.badge-partial{background:#fff3cd;color:#f59e0b;border:1px solid #fbbf24;}
.badge-damaged{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}
.badge-rejected{background:#f3f4f6;color:#6b7280;border:1px solid #d1d5db;}
.badge-fuel{background:#dbeafe;color:#1e40af;}
.badge-merch{background:#ede9fe;color:#5b21b6;}
/* ── Payment Info ── */
.payment-info{background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px;margin:10px 0;font-size:13px;}
.payment-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #d1f5e0;}
.payment-row:last-child{border-bottom:none;font-weight:700;font-size:14px;color:var(--green);margin-top:5px;padding-top:10px;}
.payment-label{color:#6c757d;}
.payment-value{font-weight:600;color:#222;}
/* ── Discrepancy Alert ── */
.discrepancy-alert{background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px;margin:10px 0;font-size:13px;color:#856404;}
.discrepancy-alert strong{color:#d97706;}
/* ── Modals ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:28px;width:600px;max-width:96vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-box.wide{width:750px;}
.modal-box h3{margin:0 0 16px;font-size:16px;color:var(--blue);display:flex;align-items:center;gap:8px;}
.modal-box label{font-size:12px;font-weight:600;color:var(--gray);display:block;margin-bottom:5px;}
.modal-box input,.modal-box select,.modal-box textarea{width:100%;padding:9px 11px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;}
.modal-box input:focus,.modal-box select:focus,.modal-box textarea:focus{outline:none;border-color:var(--blue);}
.modal-box textarea{resize:vertical;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;}
.form-group{margin-bottom:12px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
/* ── Detail Grid ── */
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;}
.detail-item{background:var(--light);border-radius:6px;padding:10px 12px;}
.detail-item .di-label{font-size:11px;color:var(--gray);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;}
.detail-item .di-val{font-size:13px;color:#222;font-weight:500;}
.detail-item.highlight{background:#f0fdf4;border:1px solid #86efac;}
.detail-item.alert{background:#fff5f5;border:1px solid #fecaca;}
/* ── Empty State ── */
.empty-state{text-align:center;padding:48px 20px;color:var(--gray);}
.empty-state i{font-size:40px;margin-bottom:12px;opacity:.4;display:block;}
/* ── Toast ── */
.toast{position:fixed;bottom:24px;right:24px;padding:13px 20px;border-radius:8px;color:#fff;font-weight:600;font-size:13px;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.2);display:none;animation:tUp .25s ease;max-width:340px;}
.toast.show{display:block;}
.toast-success{background:var(--green);}
.toast-error{background:var(--red);}
@keyframes tUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:768px){
  .filter-bar{flex-direction:column;}
  .detail-grid{grid-template-columns:1fr;}
  .form-row{grid-template-columns:1fr;}
}
</style>

<div class="page-head">
  <div>
    <h1><i class="fas fa-truck"></i> Deliveries Oversight</h1>
    <div class="page-subtitle">
      Review and validate delivery records, expected shipments, and flagged discrepancies.
    </div>
  </div>
  <div class="header-actions">
    <button class="btn btn-outline" onclick="loadDeliveries()"><i class="fas fa-sync-alt"></i> Refresh</button>
  </div>
</div>

<!-- ── Compliance Alerts Panel ─────────────────────────────────────────────── -->
<div class="card" id="compliancePanel" style="display:none;">
  <div class="card-header" style="background:#fff5f5;border-bottom-color:#fecaca;">
    <div class="card-title" style="color:#991b1b;"><i class="fas fa-triangle-exclamation"></i> Compliance Alerts</div>
    <span id="alertCount" style="font-size:12px;color:#991b1b;font-weight:700;"></span>
  </div>
  <div class="card-body" style="padding:12px 20px;" id="alertsList"></div>
</div>

<!-- ── Merchandise PO Stock-In Tracker ──────────────────────────────────────── -->
<div class="card" id="stockInPanel" style="display:none;">
  <div class="card-header" style="background:#f0f4ff;border-bottom-color:#c5d3f0;">
    <div class="card-title" style="color:#002F70;"><i class="fas fa-dolly"></i> Merchandise POs Awaiting Stock-In</div>
    <span id="stockInCount" style="font-size:12px;color:#002F70;font-weight:700;"></span>
  </div>
  <div class="card-body" style="padding:12px 20px;" id="stockInList"></div>
</div>

<!-- Deliveries Table -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-list"></i> Delivery Records</div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <span id="recordCount" style="font-size:12px;color:var(--gray);">Loading…</span>
    </div>
  </div>
  <div class="card-body">

    <!-- Filter Bar -->
    <div class="filter-bar">
      <div class="fg">
        <label>From</label>
        <input type="date" id="fStart" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
      </div>
      <div class="fg">
        <label>To</label>
        <input type="date" id="fEnd" value="<?php echo date('Y-m-d'); ?>">
      </div>
      <div class="fg">
        <label>Status</label>
        <select id="fStatus">
          <option value="">All (Manager-Validated)</option>
          <option value="approved">Approved / Confirmed</option>
          <option value="flagged">Flagged / Discrepancy</option>
          <option value="expected">Expected Delivery</option>
          <option value="pending">Pending Admin Oversight</option>
        </select>
      </div>
      <div class="fg">
        <label>Type</label>
        <select id="fType">
          <option value="">All Types</option>
          <option value="fuel">Fuel</option>
          <option value="merchandise">Merchandise</option>
        </select>
      </div>
      <div class="fg">
        <label>Supplier</label>
        <input type="text" id="fSupplier" placeholder="Search supplier…">
      </div>
      <div style="margin-top:auto;">
        <button class="btn btn-primary" onclick="loadDeliveries()"><i class="fas fa-search"></i> Filter</button>
      </div>
      <div style="margin-top:auto;margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn btn-outline" onclick="exportReport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
        <button class="btn btn-outline" onclick="exportReport('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table class="dt">
        <thead>
          <tr>
            <th>Delivery ID</th><th>Type</th><th>DR Number</th><th>Supplier</th>
            <th>Product</th><th>Quantity</th><th>Payable Amount</th><th>Date</th>
            <th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody id="deliveriesBody">
          <tr><td colspan="10"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading…</div></td></tr>
        </tbody>
      </table>
    </div>

  </div>
</div>

<!-- ── Detail Modal ──────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="detailModal">
  <div class="modal-box wide">
    <h3><i class="fas fa-info-circle"></i> Delivery Details</h3>
    <div id="detailContent">
      <div style="text-align:center;padding:20px;color:var(--gray);"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeModal('detailModal')">Close</button>
      <button class="btn btn-success" id="detailValidateBtn" onclick="validateFromDetail()" style="display:none;"><i class="fas fa-check"></i> Validate</button>
      <button class="btn btn-danger" id="detailFlagBtn" onclick="flagFromDetail()" style="display:none;"><i class="fas fa-flag"></i> Flag</button>
      <button class="btn btn-primary" id="detailFinalizeBtn" onclick="finalizeFromDetail()" style="display:none;"><i class="fas fa-print"></i> Finalize &amp; Print</button>
    </div>
  </div>
</div>

<!-- ── Validate Modal ────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="validateModal">
  <div class="modal-box">
    <h3><i class="fas fa-check-circle" style="color:var(--green);"></i> Validate Delivery</h3>
    <p style="font-size:13px;color:#555;margin:0 0 12px;">Confirm this delivery record is accurate and matches the physical Delivery Receipt.</p>
    <div id="validateDetail" style="background:var(--light);border-radius:6px;padding:12px;font-size:13px;margin-bottom:14px;"></div>
    <div class="form-group">
      <label>Notes (optional)</label>
      <textarea id="validateNotes" rows="3" placeholder="e.g. Verified against DR, quantities match."></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeModal('validateModal')">Cancel</button>
      <button class="btn btn-success" onclick="submitValidate()"><i class="fas fa-check"></i> Validate</button>
    </div>
  </div>
</div>

<!-- ── Flag Modal ────────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="flagModal">
  <div class="modal-box">
    <h3><i class="fas fa-flag" style="color:var(--red);"></i> Flag Delivery</h3>
    <p style="font-size:13px;color:#555;margin:0 0 12px;">Flag this delivery if there is a discrepancy or issue that needs attention.</p>
    <div id="flagDetail" style="background:#fff5f5;border-radius:6px;padding:12px;font-size:13px;margin-bottom:14px;"></div>
    <div class="form-group">
      <label>Reason for flagging <span style="color:var(--red);">*</span></label>
      <textarea id="flagReason" rows="3" placeholder="e.g. Quantity mismatch: DR shows 5,000L but record shows 4,800L."></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeModal('flagModal')">Cancel</button>
      <button class="btn btn-danger" onclick="submitFlag()"><i class="fas fa-flag"></i> Flag Delivery</button>
    </div>
  </div>
</div>

<!-- ── Finalize & Print Modal ─────────────────────────────────────────────── -->
<div class="modal-overlay" id="finalizeModal">
  <div class="modal-box">
    <h3><i class="fas fa-print" style="color:var(--blue);"></i> Finalize &amp; Print</h3>
    <p style="font-size:13px;color:#555;margin:0 0 12px;">Generate an official delivery record for Petron Corporation. This marks the delivery as finalized.</p>
    <div id="finalizeDetail" style="background:var(--light);border-radius:6px;padding:12px;font-size:13px;margin-bottom:14px;"></div>
    <div class="form-group">
      <label>Final Remarks (optional)</label>
      <textarea id="finalizeRemarks" rows="2" placeholder="e.g. Verified and finalized for official records."></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeModal('finalizeModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitFinalize()"><i class="fas fa-print"></i> Finalize &amp; Print</button>
    </div>
  </div>
</div>

<!-- ── Process Delivery Modal (with Payment Computation) ──────────────────── -->
<div class="modal-overlay" id="processModal">
  <div class="modal-box wide">
    <h3><i class="fas fa-calculator" style="color:var(--blue);"></i> Process Delivery & Compute Payment</h3>
    <p style="font-size:13px;color:#555;margin:0 0 14px;">Review delivery details, adjust quantities if needed, and compute the payable amount for supplier payment.</p>
    
    <!-- Delivery Info -->
    <div id="processInfo" style="background:#f8f9fa;border-radius:6px;padding:14px;margin-bottom:14px;font-size:13px;">
      <strong>Loading...</strong>
    </div>

    <form id="processForm">
      <input type="hidden" id="proc_id" name="delivery_id">
      
      <div class="form-row">
        <div class="form-group">
          <label>Expected Quantity <span style="color:var(--gray);">(from PO/Staff)</span></label>
          <input type="number" step="0.01" id="proc_expected" class="form-control" readonly style="background:#e9ecef;">
        </div>
        <div class="form-group">
          <label>Unit Price (₱) <span style="color:var(--red);">*</span></label>
          <input type="number" step="0.01" id="proc_unit_price" class="form-control" placeholder="e.g. 50.00" required oninput="recalcPayment()">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Actual Received Quantity <span style="color:var(--red);">*</span></label>
          <input type="number" step="0.01" id="proc_actual" class="form-control" placeholder="Actual qty received" required oninput="recalcPayment()">
        </div>
        <div class="form-group">
          <label>Damaged/Defective Quantity</label>
          <input type="number" step="0.01" id="proc_damaged" class="form-control" placeholder="0.00" value="0" oninput="recalcPayment()">
        </div>
      </div>

      <div class="form-group">
        <label>Discrepancy Type</label>
        <select id="proc_type" class="form-control">
          <option value="">None (Full Delivery)</option>
          <option value="Partial">Partial Delivery (Kulang ang qty)</option>
          <option value="Damaged">Damaged Items (May guba)</option>
          <option value="Mixed">Mixed (Kulang + Guba)</option>
          <option value="Rejected">Rejected (Wrong item/batch)</option>
        </select>
      </div>

      <div class="form-group">
        <label>Admin Remarks / Notes <span style="color:var(--red);">*</span></label>
        <textarea id="proc_remarks" class="form-control" rows="3" placeholder="e.g. Partial delivery: Only 80 pcs received out of 100 pcs ordered. Damaged items: 5 pcs broken/unusable." required></textarea>
      </div>

      <!-- Payment Summary -->
      <div class="payment-info" id="paymentSummary" style="display:none;">
        <h4 style="margin:0 0 10px;font-size:14px;color:var(--blue);"><i class="fas fa-money-bill-wave"></i> Payment Computation</h4>
        <div class="payment-row">
          <span class="payment-label">Expected Amount (PO):</span>
          <span class="payment-value" id="pay_expected">₱0.00</span>
        </div>
        <div class="payment-row">
          <span class="payment-label">Actual Received:</span>
          <span class="payment-value"><span id="pay_actual_qty">0</span> × ₱<span id="pay_unit_price">0</span> = <span id="pay_actual_amt">₱0.00</span></span>
        </div>
        <div class="payment-row" id="damagedRow" style="display:none;">
          <span class="payment-label">Less: Damaged Items:</span>
          <span class="payment-value" style="color:var(--red);">-<span id="pay_damaged_qty">0</span> × ₱<span id="pay_damaged_price">0</span> = -<span id="pay_damaged_amt">₱0.00</span></span>
        </div>
        <div class="payment-row">
          <span class="payment-label">PAYABLE AMOUNT:</span>
          <span class="payment-value" style="font-size:16px;color:var(--green);">₱<span id="pay_total">0.00</span></span>
        </div>
      </div>

      <div class="discrepancy-alert" id="discrepancyAlert" style="display:none;">
        <strong><i class="fas fa-exclamation-triangle"></i> Discrepancy Detected!</strong><br>
        <span id="discrepancyMsg"></span>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeModal('processModal')">Cancel</button>
        <button type="button" class="btn btn-success" onclick="submitProcess('approve')"><i class="fas fa-check"></i> Approve & Compute Payment</button>
        <button type="button" class="btn btn-print" onclick="submitProcess('print')"><i class="fas fa-print"></i> Approve & Print Report</button>
      </div>
    </form>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const API = '../backend/api/admin_deliveries_oversight_api.php';
let currentId = null, currentRec = null;

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function closeModal(id){document.getElementById(id).classList.remove('show');}
function toast(msg,type){
  const t=document.getElementById('toast');
  t.textContent=msg;
  t.className='toast toast-'+(type||'success')+' show';
  setTimeout(function(){t.classList.remove('show');},3800);
}
function fmtQty(q,u){return parseFloat(q).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})+' '+esc(u);}
function fmtDate(d){if(!d)return '—';try{return new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'});}catch(e){return d;}}

// ── Load deliveries ───────────────────────────────────────────────────────────
async function loadDeliveries(){
  const start=document.getElementById('fStart').value;
  const end=document.getElementById('fEnd').value;
  const status=document.getElementById('fStatus').value;
  const type=document.getElementById('fType').value;
  const supplier=document.getElementById('fSupplier').value;

  document.getElementById('deliveriesBody').innerHTML=
    '<tr><td colspan="11"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading…</div></td></tr>';

  try{
    // Admin Oversight: when no status filter selected, default to manager-validated records only.
    // 'pending' filter explicitly requested by admin to review unprocessed items.
    const effectiveStatus = status === '' ? 'approved' : status;
    const url=`${API}?action=list&start=${start}&end=${end}&status=${encodeURIComponent(effectiveStatus)}&type=${encodeURIComponent(type)}&supplier=${encodeURIComponent(supplier)}`;
    const res=await fetch(url);
    const data=await res.json();
    if(!data.success){toast(data.message,'error');return;}

    const rows=data.data||[];
    document.getElementById('recordCount').textContent=rows.length+' record(s)';

    if(rows.length===0){
      document.getElementById('deliveriesBody').innerHTML=
        '<tr><td colspan="11"><div class="empty-state"><i class="fas fa-truck"></i> No delivery records found for the selected filters.</div></td></tr>';
      return;
    }

    document.getElementById('deliveriesBody').innerHTML=rows.map(r=>buildRow(r)).join('');
  }catch(e){
    toast('Error loading deliveries: '+e.message,'error');
  }
}

function buildRow(r){
  // Map status to display labels
  const statusMap={
    'Expected Delivery':'Expected',
    'Pending Manager Approval':'Pending Validation',
    'Pending Manager Confirmation':'Pending Validation',
    'Pending Validation':'Pending Validation',
    'Confirmed':'Approved',
    'Validated':'Approved',
    'Discrepancy':'Flagged',
    'Flagged':'Flagged',
    'Partial Delivery':'Partial',
    'Damaged Items':'Damaged',
    'Rejected Delivery':'Rejected',
  };
  const displayStatus=statusMap[r.status]||r.status;

  const statusBadge={
    'Expected':'<span class="badge badge-expected"><i class="fas fa-clock"></i> Expected</span>',
    'Pending Validation':'<span class="badge badge-pending"><i class="fas fa-hourglass-half"></i> Pending</span>',
    'Approved':'<span class="badge badge-approved"><i class="fas fa-check-circle"></i> Approved</span>',
    'Flagged':'<span class="badge badge-flagged"><i class="fas fa-exclamation-triangle"></i> Flagged</span>',
    'Partial':'<span class="badge badge-partial"><i class="fas fa-box-open"></i> Partial</span>',
    'Damaged':'<span class="badge badge-damaged"><i class="fas fa-hammer"></i> Damaged</span>',
    'Rejected':'<span class="badge badge-rejected"><i class="fas fa-times-circle"></i> Rejected</span>',
  }[displayStatus]||`<span class="badge">${esc(displayStatus)}</span>`;

  const typeBadge=r.delivery_type==='fuel'
    ?'<span class="badge badge-fuel">Fuel</span>'
    :'<span class="badge badge-merch">Merchandise</span>';

  // Payment amount display
  const payableAmt = parseFloat(r.payable_amount||0);
  const paymentDisplay = payableAmt > 0 
    ? '<strong style="color:var(--green);">₱' + payableAmt.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}) + '</strong>'
    : '<span style="color:var(--gray);">Not computed</span>';

  let actions=`<button class="action-btn btn-view" onclick="showDetail(${r.id})" title="View Details"><i class="fas fa-eye"></i> View</button>`;
  
  // Add "Process" button for Approved deliveries (with payment computation)
  if(displayStatus==='Approved' && r.delivery_type==='merchandise'){
    actions+=` <button class="action-btn btn-process" onclick="openProcess(${r.id})" title="Process & Compute Payment"><i class="fas fa-calculator"></i> Process</button>`;
  }
  
  // Show print button if already processed with payment
  if(payableAmt > 0){
    actions+=` <button class="action-btn btn-print" onclick="printDeliveryReport(${r.id})" title="Print Payment Report"><i class="fas fa-print"></i> Print</button>`;
  }

  return `<tr>
    <td style="font-size:11px;color:var(--gray);">${esc(r.delivery_ref)}</td>
    <td>${typeBadge}</td>
    <td>${esc(r.dr_number||'—')}</td>
    <td>${esc(r.supplier)}</td>
    <td>${esc(r.product)}</td>
    <td>${fmtQty(r.quantity,r.unit)}</td>
    <td>${paymentDisplay}</td>
    <td>${fmtDate(r.delivery_date)}</td>
    <td>${statusBadge}</td>
    <td style="vertical-align:middle;">
      <div style="display:flex;flex-direction:column;gap:4px;align-items:stretch;">
        ${actions}
      </div>
    </td>
  </tr>`;
}

// ── Detail View ───────────────────────────────────────────────────────────────
async function showDetail(id){
  currentId=id;
  document.getElementById('detailContent').innerHTML=
    '<div style="text-align:center;padding:20px;color:var(--gray);"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
  document.getElementById('detailValidateBtn').style.display='none';
  document.getElementById('detailFlagBtn').style.display='none';
  document.getElementById('detailFinalizeBtn').style.display='none';
  document.getElementById('detailModal').classList.add('show');

  try{
    const res=await fetch(`${API}?action=detail&id=${id}`);
    const data=await res.json();
    if(!data.success){document.getElementById('detailContent').innerHTML='<div class="empty-state">Record not found.</div>';return;}
    const r=data.data;
    currentRec=r;

    const statusMap={
      'Expected Delivery':'Expected',
      'Pending Manager Approval':'Pending Validation',
      'Pending Manager Confirmation':'Pending Validation',
      'Pending Validation':'Pending Validation',
      'Confirmed':'Approved',
      'Validated':'Approved',
      'Discrepancy':'Flagged',
      'Flagged':'Flagged',
    };
    const displayStatus=statusMap[r.status]||r.status;

    const statusBadge={
      'Expected':'<span class="badge badge-expected"><i class="fas fa-clock"></i> Expected</span>',
      'Pending Validation':'<span class="badge badge-pending"><i class="fas fa-hourglass-half"></i> Pending Validation</span>',
      'Approved':'<span class="badge badge-validated"><i class="fas fa-check-circle"></i> Approved</span>',
      'Flagged':'<span class="badge badge-flagged"><i class="fas fa-exclamation-triangle"></i> Flagged</span>',
    }[displayStatus]||`<span class="badge">${esc(displayStatus)}</span>`;

    document.getElementById('detailContent').innerHTML=`
      <div class="detail-grid">
        <div class="detail-item"><div class="di-label">Reference</div><div class="di-val">${esc(r.delivery_ref)}</div></div>
        <div class="detail-item"><div class="di-label">Status</div><div class="di-val">${statusBadge}</div></div>
        <div class="detail-item"><div class="di-label">Type</div><div class="di-val">${r.delivery_type==='fuel'?'Fuel':'Merchandise'}</div></div>
        <div class="detail-item"><div class="di-label">DR Number</div><div class="di-val">${esc(r.dr_number||'—')}</div></div>
        <div class="detail-item"><div class="di-label">Supplier</div><div class="di-val">${esc(r.supplier)}</div></div>
        <div class="detail-item"><div class="di-label">Product</div><div class="di-val">${esc(r.product)}</div></div>
        <div class="detail-item"><div class="di-label">Quantity</div><div class="di-val">${fmtQty(r.quantity,r.unit)}</div></div>
        <div class="detail-item"><div class="di-label">Delivery Date</div><div class="di-val">${fmtDate(r.delivery_date)}</div></div>
        <div class="detail-item"><div class="di-label">Encoded By</div><div class="di-val">${esc(r.encoded_by_name||'—')}</div></div>
        <div class="detail-item"><div class="di-label">Admin</div><div class="di-val">${esc(r.admin_name||'—')}</div></div>
        <div class="detail-item"><div class="di-label">Action Date</div><div class="di-val">${r.admin_action_at?new Date(r.admin_action_at).toLocaleString('en-PH'):'—'}</div></div>
        <div class="detail-item"><div class="di-label">Notes</div><div class="di-val">${esc(r.admin_notes||r.remarks||'—')}</div></div>
      </div>
    `;

    if(displayStatus==='Pending Validation'){
      document.getElementById('detailValidateBtn').style.display='inline-flex';
      document.getElementById('detailFlagBtn').style.display='inline-flex';
    }
  }catch(e){
    document.getElementById('detailContent').innerHTML='<div class="empty-state">Error loading details.</div>';
  }
}

// ── Validate ──────────────────────────────────────────────────────────────────
function openValidate(id,supplier,product,qty,dr){
  currentId=id;
  document.getElementById('validateDetail').innerHTML=
    `<strong>Supplier:</strong> ${esc(supplier)}<br>
     <strong>Product:</strong> ${esc(product)}<br>
     <strong>Quantity:</strong> ${qty}<br>
     <strong>DR Number:</strong> ${esc(dr||'—')}`;
  document.getElementById('validateNotes').value='';
  document.getElementById('validateModal').classList.add('show');
}
async function submitValidate(){
  const notes=document.getElementById('validateNotes').value.trim();
  try{
    const res=await fetch(`${API}?action=validate`,{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({id:currentId,notes})
    });
    const data=await res.json();
    closeModal('validateModal');
    closeModal('detailModal');
    toast(data.message,data.success?'success':'error');
    if(data.success)loadDeliveries();
  }catch(e){
    toast('Error: '+e.message,'error');
  }
}
function validateFromDetail(){
  if(!currentRec)return;
  openValidate(currentRec.id,currentRec.supplier,currentRec.product,fmtQty(currentRec.quantity,currentRec.unit),currentRec.dr_number);
}

// ── Flag ──────────────────────────────────────────────────────────────────────
function openFlag(id,supplier,product){
  currentId=id;
  document.getElementById('flagDetail').innerHTML=
    `<strong>Supplier:</strong> ${esc(supplier)}<br>
     <strong>Product:</strong> ${esc(product)}`;
  document.getElementById('flagReason').value='';
  document.getElementById('flagModal').classList.add('show');
}
async function submitFlag(){
  const reason=document.getElementById('flagReason').value.trim();
  if(!reason){toast('Reason is required when flagging.','error');return;}
  try{
    const res=await fetch(`${API}?action=flag`,{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({id:currentId,reason})
    });
    const data=await res.json();
    closeModal('flagModal');
    closeModal('detailModal');
    toast(data.message,data.success?'success':'error');
    if(data.success)loadDeliveries();
  }catch(e){
    toast('Error: '+e.message,'error');
  }
}
function flagFromDetail(){
  if(!currentRec)return;
  openFlag(currentRec.id,currentRec.supplier,currentRec.product);
}

// ── Finalize & Print ──────────────────────────────────────────────────────────
function openFinalize(id,supplier,product,qty,dr){
  currentId=id;
  document.getElementById('finalizeDetail').innerHTML=
    `<strong>Supplier:</strong> ${esc(supplier)}<br>
     <strong>Product:</strong> ${esc(product)}<br>
     <strong>Quantity:</strong> ${qty}<br>
     <strong>DR Number:</strong> ${esc(dr||'—')}`;
  document.getElementById('finalizeRemarks').value='';
  document.getElementById('finalizeModal').classList.add('show');
}
async function submitFinalize(){
  const remarks=document.getElementById('finalizeRemarks').value.trim();
  try{
    const res=await fetch(`${API}?action=finalize`,{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({id:currentId,remarks})
    });
    const data=await res.json();
    if(!data.success){toast(data.message,'error');return;}
    closeModal('finalizeModal');
    closeModal('detailModal');
    toast(data.message,'success');
    loadDeliveries();
    // Open print window
    window.open(`${API}?action=print_receipt&id=${currentId}`,'_blank');
  }catch(e){
    toast('Error: '+e.message,'error');
  }
}
function finalizeFromDetail(){
  if(!currentRec)return;
  openFinalize(currentRec.id,currentRec.supplier,currentRec.product,fmtQty(currentRec.quantity,currentRec.unit),currentRec.dr_number);
}

// ── Compliance Alerts ─────────────────────────────────────────────────────────
async function loadComplianceAlerts(){
  try{
    const res=await fetch(`${API}?action=compliance_alerts`);
    const data=await res.json();
    if(!data.success||!data.alerts||data.alerts.length===0){
      document.getElementById('compliancePanel').style.display='none';
      return;
    }
    const alerts=data.alerts;
    document.getElementById('alertCount').textContent=alerts.length+' alert(s)';
    let html='';
    alerts.forEach(function(a){
      html+=`<div class="alert-item">
        <div class="ai-icon"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="ai-body">
          <div class="ai-title">${esc(a.title)}</div>
          <div class="ai-sub">${esc(a.description)}</div>
        </div>
        <div class="ai-action">
          <button class="btn btn-sm btn-outline" onclick="showDetail(${a.delivery_id})" style="font-size:11px;padding:4px 8px;">View</button>
        </div>
      </div>`;
    });
    document.getElementById('alertsList').innerHTML=html;
    document.getElementById('compliancePanel').style.display='block';
  }catch(e){}
}

// ── Stock-In Tracker ─────────────────────────────────────────────────────────
async function loadStockInTracker(){
  try{
    const res=await fetch('../backend/api/merchandise_stock_in.php?action=get_pending_deliveries');
    const data=await res.json();
    if(!data.success||!data.deliveries||data.deliveries.length===0){
      document.getElementById('stockInPanel').style.display='none';
      return;
    }
    const items=data.deliveries;
    document.getElementById('stockInCount').textContent=items.length+' PO(s) awaiting stock-in';
    let html='<div style="display:flex;flex-wrap:wrap;gap:10px;">';
    items.forEach(function(d){
      html+=`<div style="background:#fff;border:1px solid #c5d3f0;border-radius:8px;padding:12px 14px;flex:1;">
        <div style="font-size:12px;font-weight:700;color:#002F70;">${esc(d.po_number||'Manual')}</div>
        <div style="font-size:13px;font-weight:700;color:#222;margin:3px 0;">${esc(d.product_name||'')}</div>
        <div style="font-size:11px;color:#6c757d;">Qty: <strong>${d.qty_ordered}</strong> &nbsp;|&nbsp; Finalized by: ${esc(d.admin_name||'—')}</div>
        <a href="staff_stock_in.php" style="display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:5px 12px;background:#002F70;color:#fff;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;">
          <i class="fas fa-dolly"></i> Go to Stock-In
        </a>
      </div>`;
    });
    html+='</div>';
    document.getElementById('stockInList').innerHTML=html;
    document.getElementById('stockInPanel').style.display='block';
  }catch(e){}
}

// ── Export ────────────────────────────────────────────────────────────────────
function exportReport(format){
  const start=document.getElementById('fStart').value;
  const end=document.getElementById('fEnd').value;
  const status=document.getElementById('fStatus').value;
  const url=`${API}?action=export_${format}&start=${start}&end=${end}&status=${encodeURIComponent(status)}`;
  window.open(url,'_blank');
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded',function(){
  ['detailModal','validateModal','flagModal','finalizeModal'].forEach(function(id){
    var el=document.getElementById(id);
    if(el&&el.parentNode!==document.body)document.body.appendChild(el);
  });

  // Pre-filter based on URL ?status= param
  const urlStatus = '<?php echo addslashes($status_param); ?>';
  if(urlStatus === 'expected' || urlStatus === 'pending' || urlStatus === 'approved' || urlStatus === 'flagged'){
    document.getElementById('fStatus').value = urlStatus;
  }

  loadDeliveries();
  loadComplianceAlerts();
  loadStockInTracker();
});

// Close modals on overlay click
document.addEventListener('click',function(e){
  ['detailModal','validateModal','flagModal','finalizeModal'].forEach(function(id){
    var el=document.getElementById(id);
    if(el&&e.target===el)closeModal(id);
  });
});

// ══════════════════════════════════════════════════════════════════════════════
// ── PAYMENT COMPUTATION & PROCESS DELIVERY ─────────────────────────────────────
// ══════════════════════════════════════════════════════════════════════════════

// ── Open Process Modal ────────────────────────────────────────────────────────
async function openProcess(id) {
  currentId = id;
  document.getElementById('processModal').classList.add('show');
  document.getElementById('paymentSummary').style.display = 'none';
  document.getElementById('discrepancyAlert').style.display = 'none';
  
  try {
    const res = await fetch(`${API}?action=detail&id=${id}`);
    const data = await res.json();
    if (!data.success) {
      toast('Failed to load delivery details', 'error');
      return;
    }
    
    const r = data.data;
    currentRec = r;
    
    // Fill in delivery info
    document.getElementById('processInfo').innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div><strong>Delivery Ref:</strong> ${esc(r.delivery_ref)}</div>
        <div><strong>DR Number:</strong> ${esc(r.dr_number || 'N/A')}</div>
        <div><strong>Supplier:</strong> ${esc(r.supplier)}</div>
        <div><strong>Product:</strong> ${esc(r.product)}</div>
        <div><strong>Unit:</strong> ${esc(r.unit)}</div>
        <div><strong>Delivery Date:</strong> ${fmtDate(r.delivery_date)}</div>
      </div>
    `;
    
    // Get unit price from PO (if exists) or from existing delivery record
    let unitPrice = 0;
    let priceSource = 'Manual Input Required';
    
    // Try to get from existing delivery record first
    if (r.unit_price && parseFloat(r.unit_price) > 0) {
      unitPrice = parseFloat(r.unit_price);
      priceSource = 'From Delivery Record';
    }
    
    // Try to fetch from PO if source_ref exists
    if (r.source_ref && r.source_ref !== '') {
      try {
        const poRes = await fetch(`${API}?action=get_po_price&source_ref=${encodeURIComponent(r.source_ref)}`);
        const poData = await poRes.json();
        if (poData.success && poData.unit_price > 0) {
          unitPrice = parseFloat(poData.unit_price);
          priceSource = 'From Purchase Order (PO)';
        }
      } catch (e) {
        console.log('Could not fetch PO price:', e);
      }
    }
    
    // Fill form fields
    document.getElementById('proc_id').value = r.id;
    document.getElementById('proc_expected').value = parseFloat(r.quantity).toFixed(2);
    document.getElementById('proc_actual').value = parseFloat(r.quantity).toFixed(2); // Pre-fill with expected
    document.getElementById('proc_damaged').value = '0.00';
    document.getElementById('proc_unit_price').value = unitPrice.toFixed(2);
    document.getElementById('proc_type').value = '';
    document.getElementById('proc_remarks').value = '';
    
    // Make unit price readonly if from PO
    const priceInput = document.getElementById('proc_unit_price');
    if (unitPrice > 0) {
      priceInput.readOnly = true;
      priceInput.style.background = '#e8f4fd';
      priceInput.style.color = '#002F70';
      priceInput.style.fontWeight = '600';
      priceInput.title = priceSource;
      
      // Show price source label
      const priceLabel = priceInput.parentElement.querySelector('label');
      priceLabel.innerHTML = `Unit Price (₱) <span style="color:var(--green);font-size:10px;margin-left:5px;"><i class="fas fa-check-circle"></i> ${priceSource}</span>`;
    } else {
      priceInput.readOnly = false;
      priceInput.style.background = '#fff';
      priceInput.style.color = '';
      priceInput.style.fontWeight = '';
      priceInput.title = 'Enter unit price manually';
      
      const priceLabel = priceInput.parentElement.querySelector('label');
      priceLabel.innerHTML = `Unit Price (₱) <span style="color:var(--red);">*</span> <span style="color:var(--orange);font-size:10px;margin-left:5px;"><i class="fas fa-exclamation-triangle"></i> No PO price found - manual input required</span>`;
    }
    
    // Trigger initial calculation if price is available
    if (unitPrice > 0) {
      recalcPayment();
    }
    
  } catch (e) {
    toast('Error loading delivery: ' + e.message, 'error');
  }
}

// ── Recalculate Payment ───────────────────────────────────────────────────────
function recalcPayment() {
  const expected = parseFloat(document.getElementById('proc_expected').value) || 0;
  const actual = parseFloat(document.getElementById('proc_actual').value) || 0;
  const damaged = parseFloat(document.getElementById('proc_damaged').value) || 0;
  const unitPrice = parseFloat(document.getElementById('proc_unit_price').value) || 0;
  
  if (unitPrice <= 0) {
    document.getElementById('paymentSummary').style.display = 'none';
    return;
  }
  
  // Show payment summary
  document.getElementById('paymentSummary').style.display = 'block';
  
  // Calculate amounts
  const expectedAmt = expected * unitPrice;
  const actualAmt = actual * unitPrice;
  const damagedAmt = damaged * unitPrice;
  const payableAmt = actualAmt - damagedAmt;
  
  // Update display
  document.getElementById('pay_expected').textContent = '₱' + expectedAmt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
  document.getElementById('pay_actual_qty').textContent = actual.toFixed(2);
  document.getElementById('pay_unit_price').textContent = unitPrice.toFixed(2);
  document.getElementById('pay_actual_amt').textContent = '₱' + actualAmt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
  document.getElementById('pay_total').textContent = payableAmt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
  
  // Show/hide damaged row
  if (damaged > 0) {
    document.getElementById('damagedRow').style.display = 'flex';
    document.getElementById('pay_damaged_qty').textContent = damaged.toFixed(2);
    document.getElementById('pay_damaged_price').textContent = unitPrice.toFixed(2);
    document.getElementById('pay_damaged_amt').textContent = '₱' + damagedAmt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
  } else {
    document.getElementById('damagedRow').style.display = 'none';
  }
  
  // Check for discrepancies
  const discrepancyEl = document.getElementById('discrepancyAlert');
  const discrepancyMsg = document.getElementById('discrepancyMsg');
  
  if (actual < expected || damaged > 0) {
    discrepancyEl.style.display = 'block';
    let msgs = [];
    
    if (actual < expected) {
      const shortfall = expected - actual;
      msgs.push(`Partial Delivery: ${shortfall.toFixed(2)} units short (Expected: ${expected.toFixed(2)}, Received: ${actual.toFixed(2)})`);
    }
    
    if (damaged > 0) {
      msgs.push(`Damaged Items: ${damaged.toFixed(2)} units damaged/unusable`);
    }
    
    discrepancyMsg.innerHTML = msgs.join('<br>');
    
    // Auto-suggest discrepancy type
    const typeSelect = document.getElementById('proc_type');
    if (actual < expected && damaged > 0) {
      typeSelect.value = 'Mixed';
    } else if (actual < expected) {
      typeSelect.value = 'Partial';
    } else if (damaged > 0) {
      typeSelect.value = 'Damaged';
    }
  } else {
    discrepancyEl.style.display = 'none';
  }
}

// ── Submit Process ────────────────────────────────────────────────────────────
async function submitProcess(mode) {
  const id = parseInt(document.getElementById('proc_id').value);
  const expected = parseFloat(document.getElementById('proc_expected').value) || 0;
  const actual = parseFloat(document.getElementById('proc_actual').value) || 0;
  const damaged = parseFloat(document.getElementById('proc_damaged').value) || 0;
  const unitPrice = parseFloat(document.getElementById('proc_unit_price').value) || 0;
  const discrepancyType = document.getElementById('proc_type').value;
  const remarks = document.getElementById('proc_remarks').value.trim();
  
  // Validation
  if (!id || actual <= 0 || unitPrice <= 0) {
    toast('Please fill in all required fields with valid values', 'error');
    return;
  }
  
  if (!remarks) {
    toast('Please provide remarks explaining the delivery details', 'error');
    return;
  }
  
  if (damaged > actual) {
    toast('Damaged quantity cannot exceed actual received quantity', 'error');
    return;
  }
  
  // Calculate payment
  const expectedAmt = expected * unitPrice;
  const actualAmt = actual * unitPrice;
  const damagedAmt = damaged * unitPrice;
  const payableAmt = actualAmt - damagedAmt;
  
  try {
    const res = await fetch(`${API}?action=process_delivery`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        id: id,
        expected_quantity: expected,
        actual_quantity: actual,
        damaged_quantity: damaged,
        unit_price: unitPrice,
        expected_amount: expectedAmt,
        payable_amount: payableAmt,
        discrepancy_type: discrepancyType,
        remarks: remarks
      })
    });
    
    const data = await res.json();
    
    if (!data.success) {
      toast(data.message, 'error');
      return;
    }
    
    closeModal('processModal');
    toast(data.message, 'success');
    loadDeliveries();
    
    // If print mode, open print window
    if (mode === 'print') {
      setTimeout(() => {
        printDeliveryReport(id);
      }, 500);
    }
    
  } catch (e) {
    toast('Error processing delivery: ' + e.message, 'error');
  }
}

// ── Print Delivery Report ─────────────────────────────────────────────────────
function printDeliveryReport(id) {
  window.open(`${API}?action=print_payment_report&id=${id}`, '_blank');
}

</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
