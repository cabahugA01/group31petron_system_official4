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
   ADD REQUIRED COLUMNS TO deliveries_oversight TABLE
══════════════════════════════════════════════════════════ */
$required_columns = [
    "ALTER TABLE deliveries_oversight ADD COLUMN batch_id VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN unit_price DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN expected_quantity DECIMAL(12,3) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN actual_quantity DECIMAL(12,3) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN damaged_quantity DECIMAL(12,3) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN expected_amount DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN payable_amount DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE deliveries_oversight ADD COLUMN discrepancy_type ENUM('','Partial','Damaged','Rejected','Mixed') DEFAULT ''",
    "ALTER TABLE deliveries_oversight ADD COLUMN category VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN manager_id INT DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN manager_action_at DATETIME DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN manager_notes TEXT DEFAULT NULL",
];
foreach ($required_columns as $col_sql) {
    try { $pdo->exec($col_sql); } catch (Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F70;--red:#dc3545;--green:#28a745;--orange:#fd7e14;--gray:#6c757d;--light:#f8f9fa;}
.page-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.page-head h1{margin:0;font-size:1.6rem;color:var(--blue);display:flex;align-items:center;gap:10px;}
.page-subtitle{font-size:13px;color:var(--gray);margin:4px 0 0;}
.header-actions{display:flex;gap:8px;flex-wrap:wrap;}
/* == PAGE HEADER - matches Transaction module int-head standard == */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }
.int-head .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
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
.table-wrap{width:100%;overflow:visible;}
table.dt{width:100%;table-layout:auto;border-collapse:collapse;font-size:11px;}
table.dt th{background:var(--blue);color:#fff;padding:10px 8px;text-align:left;font-size:10px;font-weight:700;border:none;white-space:normal;text-transform:uppercase;letter-spacing:.3px;line-height:1.3;vertical-align:top;}
table.dt td{padding:8px;border-bottom:1px solid #e9ecef;vertical-align:top;background:#fff;white-space:normal;word-wrap:break-word;line-height:1.5;}
table.dt tr:hover td{background:#eff6ff;}
/* Right-aligned columns */
table.dt th:nth-child(7), table.dt td:nth-child(7),
table.dt th:nth-child(8), table.dt td:nth-child(8),
table.dt th:nth-child(9), table.dt td:nth-child(9),
table.dt th:nth-child(10), table.dt td:nth-child(10){text-align:right;}
/* ── Badges ── */
.badge{display:inline-block;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap;}
.badge-expected{background:#e0f2fe;color:#0369a1;}
.badge-pending{background:#fff3cd;color:#856404;}
.badge-approved{background:#d1fae5;color:#065f46;}
.badge-flagged{background:#fee2e2;color:#991b1b;}
.badge-partial{background:#fff3cd;color:#f59e0b;}
.badge-damaged{background:#fee2e2;color:#dc2626;}
.badge-rejected{background:#f3f4f6;color:#6b7280;}
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
}
</style>

<div class="int-head">
  <div>
    <h1><i class="fas fa-truck"></i> Merchandise Deliveries Oversight</h1>
    <div class="sub">
      Review manager-validated merchandise delivery records with quantities, variances, and adjustments. View-only monitoring.
    </div>
  </div>
  <div class="actions">
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
          <option value="">All (Manager-Validated)</option>
          <option value="approved">Approved / Confirmed Only</option>
          <option value="flagged">Flagged / Discrepancy Only</option>
          <option value="expected">Expected Delivery</option>
          <option value="pending">Pending Admin Oversight</option>
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
            <th>Batch ID</th>
            <th>Delivery ID</th>
            <th>Supplier</th>
            <th>DR No.</th>
            <th>Item Name</th>
            <th>Category</th>
            <th>DR Qty</th>
            <th>Encoded Qty</th>
            <th>Actual Qty</th>
            <th>Variance</th>
            <th>Reason</th>
            <th>Manager</th>
            <th>Timestamp</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="deliveriesBody">
          <tr><td colspan="14"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading…</div></td></tr>
        </tbody>
      </table>
    </div>

  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const API = '../backend/api/admin_deliveries_oversight_api.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function toast(msg,type){
  const t=document.getElementById('toast');
  t.textContent=msg;
  t.className='toast toast-'+(type||'success')+' show';
  setTimeout(function(){t.classList.remove('show');},3800);
}
function fmtQty(q,u){return parseFloat(q).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})+' '+esc(u);}
function fmtDate(d){if(!d)return '—';try{return new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'});}catch(e){return d;}}

// ── Load deliveries (VIEW ONLY) ───────────────────────────────────────────────
async function loadDeliveries(){
  const start=document.getElementById('fStart').value;
  const end=document.getElementById('fEnd').value;
  const status=document.getElementById('fStatus').value;
  const supplier=document.getElementById('fSupplier').value;

  document.getElementById('deliveriesBody').innerHTML=
    '<tr><td colspan="14"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading…</div></td></tr>';

  try{
    // Admin Oversight: Show only manager-validated merchandise deliveries
    const effectiveStatus = status;
    const url=`${API}?action=list&start=${start}&end=${end}&status=${encodeURIComponent(effectiveStatus)}&type=merchandise&supplier=${encodeURIComponent(supplier)}`;
    const res=await fetch(url);
    const data=await res.json();
    if(!data.success){toast(data.message,'error');return;}

    const rows=data.data||[];
    document.getElementById('recordCount').textContent=rows.length+' record(s)';

    if(rows.length===0){
      document.getElementById('deliveriesBody').innerHTML=
        '<tr><td colspan="14"><div class="empty-state"><i class="fas fa-truck"></i> No merchandise delivery records found for the selected filters.</div></td></tr>';
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
    'Pending Manager Approval':'Pending',
    'Pending Manager Confirmation':'Pending',
    'Pending Validation':'Pending',
    'Confirmed':'Cleared',
    'Validated':'Cleared',
    'Discrepancy':'Flagged',
    'Flagged':'Flagged',
    'Partial Delivery':'Partial',
    'Damaged Items':'Damaged',
    'Rejected Delivery':'Rejected',
  };
  const displayStatus=statusMap[r.status]||r.status;

  const statusBadge={
    'Expected':'<span class="badge badge-expected">Expected</span>',
    'Pending':'<span class="badge badge-pending">Pending</span>',
    'Cleared':'<span class="badge badge-approved">Cleared</span>',
    'Flagged':'<span class="badge badge-flagged">Flagged</span>',
    'Partial':'<span class="badge badge-partial">Partial</span>',
    'Damaged':'<span class="badge badge-damaged">Damaged</span>',
    'Rejected':'<span class="badge badge-rejected">Rejected</span>',
  }[displayStatus]||`<span class="badge">${esc(displayStatus)}</span>`;

  // Calculate variance
  const drQty = parseFloat(r.expected_quantity || r.quantity || 0);
  const encodedQty = parseFloat(r.quantity || 0);
  const actualQty = parseFloat(r.actual_quantity || r.quantity || 0);
  const variance = actualQty - drQty;
  
  // Variance display with color
  let varianceDisplay = '0.0';
  let varianceStyle = 'color:#6c757d;';
  if (Math.abs(variance) > 0.001) {
    const varianceColor = variance < 0 ? '#dc3545' : '#28a745';
    const varianceSign = variance > 0 ? '+' : '';
    varianceStyle = `color:${varianceColor};font-weight:600;`;
    varianceDisplay = `${varianceSign}${variance.toFixed(1)}`;
  }

  // Get category
  const category = r.category || '—';
  
  // Manager name and timestamp
  const managerName = r.manager_name || '—';
  const timestamp = r.manager_action_at ? new Date(r.manager_action_at).toLocaleString('en-PH', {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
  }) : '—';
  
  // Reason/remarks - full text, will wrap naturally
  const reason = r.manager_notes || r.admin_notes || r.remarks || '—';

  return `<tr>
    <td style="font-family:monospace;font-weight:600;color:#002F70;">${esc(r.batch_id||'—')}</td>
    <td style="color:#6c757d;">${esc(r.delivery_ref)}</td>
    <td>${esc(r.supplier)}</td>
    <td style="font-weight:600;">${esc(r.dr_number||'—')}</td>
    <td style="font-weight:600;">${esc(r.product)}</td>
    <td>${esc(category)}</td>
    <td><span style="font-weight:600;">${drQty.toFixed(1)}</span> <span style="color:#6c757d;font-size:10px;">${esc(r.unit)}</span></td>
    <td><span style="font-weight:600;">${encodedQty.toFixed(1)}</span> <span style="color:#6c757d;font-size:10px;">${esc(r.unit)}</span></td>
    <td><span style="font-weight:700;color:#002F70;">${actualQty.toFixed(1)}</span> <span style="color:#6c757d;font-size:10px;">${esc(r.unit)}</span></td>
    <td style="${varianceStyle}">${varianceDisplay}</td>
    <td>${esc(reason)}</td>
    <td>${esc(managerName)}</td>
    <td style="color:#6c757d;">${timestamp}</td>
    <td>${statusBadge}</td>
  </tr>`;
}

// Export functions
function exportReport(format){
  const start=document.getElementById('fStart').value;
  const end=document.getElementById('fEnd').value;
  const status=document.getElementById('fStatus').value;
  const supplier=document.getElementById('fSupplier').value;
  
  const url=`${API}?action=export_${format}&start=${start}&end=${end}&status=${encodeURIComponent(status)}&type=merchandise&supplier=${encodeURIComponent(supplier)}`;
  window.open(url,'_blank');
}

// Load on page load
document.addEventListener('DOMContentLoaded',function(){
  loadDeliveries();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
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
