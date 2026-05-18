<?php
$page_id = 'admin_deliveries_oversight';
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

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F70;--red:#dc3545;--green:#28a745;--orange:#fd7e14;--gray:#6c757d;--light:#f8f9fa;}
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
.filter-bar input,.filter-bar select{padding:7px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;min-width:130px;}
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
/* ── Action Buttons (Aligned with User Management) ── */
.action-btn { font-size:12px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:5px; transition:all .15s; font-weight:600; width:100px; text-decoration:none; }
.action-btn:hover { filter:brightness(.9); transform:translateY(-1px); }
.btn-view    { background:#28a745; color:#fff; }
.btn-validate { background:#002F70; color:#fff; }
.btn-flag    { background:#dc3545; color:#fff; }
/* ── Badges ── */
.badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;white-space:nowrap;}
.badge-pending{background:#fff3cd;color:#856404;}
.badge-validated{background:#d1fae5;color:#065f46;}
.badge-flagged{background:#fee2e2;color:#991b1b;}
.badge-fuel{background:#dbeafe;color:#1e40af;}
.badge-merch{background:#ede9fe;color:#5b21b6;}
/* ── Modals ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:28px;width:540px;max-width:96vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-box.wide{width:680px;}
.modal-box h3{margin:0 0 16px;font-size:16px;color:var(--blue);display:flex;align-items:center;gap:8px;}
.modal-box label{font-size:12px;font-weight:600;color:var(--gray);display:block;margin-bottom:5px;}
.modal-box input,.modal-box select,.modal-box textarea{width:100%;padding:9px 11px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;}
.modal-box input:focus,.modal-box select:focus,.modal-box textarea:focus{outline:none;border-color:var(--blue);}
.modal-box textarea{resize:vertical;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;}
.form-group{margin-bottom:12px;}
/* ── Detail Grid ── */
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;}
.detail-item{background:var(--light);border-radius:6px;padding:10px 12px;}
.detail-item .di-label{font-size:11px;color:var(--gray);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;}
.detail-item .di-val{font-size:13px;color:#222;font-weight:500;}
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
}
</style>

<div class="page-head">
  <div>
    <h1><i class="fas fa-truck"></i> Deliveries Oversight</h1>
    <div class="page-subtitle">Review and validate delivery records from Manager</div>
  </div>
  <div class="header-actions">
    <button class="btn btn-outline" onclick="loadDeliveries()"><i class="fas fa-sync-alt"></i> Refresh</button>
  </div>
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
          <option value="">All Statuses</option>
          <option value="Pending Manager Approval">Pending Validation</option>
          <option value="Confirmed">Validated</option>
          <option value="Discrepancy">Flagged</option>
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
            <th>Product</th><th>Quantity</th><th>Date</th><th>Encoded By</th>
            <th>Status</th><th>Remarks</th><th>Actions</th>
          </tr>
        </thead>
        <tbody id="deliveriesBody">
          <tr><td colspan="11"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading…</div></td></tr>
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
    const url=`${API}?action=list&start=${start}&end=${end}&status=${encodeURIComponent(status)}&type=${encodeURIComponent(type)}&supplier=${encodeURIComponent(supplier)}`;
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
    'Pending Manager Approval':'Pending Validation',
    'Pending Manager Confirmation':'Pending Validation',
    'Confirmed':'Validated',
    'Discrepancy':'Flagged',
    'Validated':'Validated',
    'Flagged':'Flagged',
  };
  const displayStatus=statusMap[r.status]||r.status;

  const statusBadge={
    'Pending Validation':'<span class="badge badge-pending">Pending Validation</span>',
    'Validated':'<span class="badge badge-validated">Validated</span>',
    'Flagged':'<span class="badge badge-flagged">Flagged</span>',
  }[displayStatus]||`<span class="badge">${esc(displayStatus)}</span>`;

  const typeBadge=r.delivery_type==='fuel'
    ?'<span class="badge badge-fuel">Fuel</span>'
    :'<span class="badge badge-merch">Merchandise</span>';

  let actions=`<button class="action-btn btn-view" onclick="showDetail(${r.id})" title="View Details"><i class="fas fa-eye"></i> View</button> `;
  if(displayStatus==='Pending Validation'){
    actions+=`<button class="action-btn btn-validate" onclick="openValidate(${r.id},'${esc(r.supplier)}','${esc(r.product)}','${fmtQty(r.quantity,r.unit)}','${esc(r.dr_number||'')}')"><i class="fas fa-check"></i> Validate</button> `;
    actions+=`<button class="action-btn btn-flag" onclick="openFlag(${r.id},'${esc(r.supplier)}','${esc(r.product)}')"><i class="fas fa-flag"></i> Flag</button>`;
  }

  return `<tr>
    <td style="font-size:11px;color:var(--gray);">${esc(r.delivery_ref)}</td>
    <td>${typeBadge}</td>
    <td>${esc(r.dr_number||'—')}</td>
    <td>${esc(r.supplier)}</td>
    <td>${esc(r.product)}</td>
    <td>${fmtQty(r.quantity,r.unit)}</td>
    <td>${fmtDate(r.delivery_date)}</td>
    <td>${esc(r.encoded_by_name||'—')}</td>
    <td>${statusBadge}</td>
    <td style="font-size:12px;color:var(--gray);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
        title="${esc(r.admin_notes||r.remarks||'')}">${esc(r.admin_notes||r.remarks||'—')}</td>
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
  document.getElementById('detailModal').classList.add('show');

  try{
    const res=await fetch(`${API}?action=detail&id=${id}`);
    const data=await res.json();
    if(!data.success){document.getElementById('detailContent').innerHTML='<div class="empty-state">Record not found.</div>';return;}
    const r=data.data;
    currentRec=r;

    const statusMap={
      'Pending Manager Approval':'Pending Validation',
      'Pending Manager Confirmation':'Pending Validation',
      'Confirmed':'Validated',
      'Discrepancy':'Flagged',
      'Validated':'Validated',
      'Flagged':'Flagged',
    };
    const displayStatus=statusMap[r.status]||r.status;

    const statusBadge={
      'Pending Validation':'<span class="badge badge-pending">Pending Validation</span>',
      'Validated':'<span class="badge badge-validated">Validated</span>',
      'Flagged':'<span class="badge badge-flagged">Flagged</span>',
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
  ['detailModal','validateModal','flagModal'].forEach(function(id){
    var el=document.getElementById(id);
    if(el&&el.parentNode!==document.body)document.body.appendChild(el);
  });
  loadDeliveries();
});

// Close modals on overlay click
document.addEventListener('click',function(e){
  ['detailModal','validateModal','flagModal'].forEach(function(id){
    var el=document.getElementById(id);
    if(el&&e.target===el)closeModal(id);
  });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
