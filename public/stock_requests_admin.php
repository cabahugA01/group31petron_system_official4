<?php
/**
 * Admin – Stock Requests Oversight & Audit Trail
 * Read-only oversight of all stock requests.
 * Audit trail with export to CSV for compliance.
 */
$page_id = 'stock_requests_admin';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

$active_tab = $_GET['tab'] ?? 'requests';

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Layout ── */
.sr-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:24px; }
.sr-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.sr-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.sr-card-body { padding:20px; }

/* ── Stats ── */
.stats-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:24px; }
.stat-box { background:#fff; border-radius:10px; border:1px solid #e9ecef; padding:16px 20px; text-align:center; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.stat-num { font-size:2rem; font-weight:800; color:#002F70; }
.stat-lbl { font-size:12px; color:#6c757d; margin-top:4px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }

/* ── Tabs ── */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e9ecef; margin-bottom:20px; }
.tab-btn { padding:10px 22px; background:none; border:none; border-bottom:3px solid transparent; font-size:14px; font-weight:600; color:#6c757d; cursor:pointer; margin-bottom:-2px; transition:all .15s; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70; }

/* ── Badges ── */
.sbadge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
.sbadge-pending  { background:#fff3cd; color:#856404; }
.sbadge-approved { background:#d4edda; color:#155724; }
.sbadge-rejected { background:#f8d7da; color:#721c24; }
.sbadge-validated { background:#cce5ff; color:#004085; }

/* ── Audit action badges ── */
.abadge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.abadge-created  { background:#e2f0fb; color:#0c5460; }
.abadge-approved { background:#d4edda; color:#155724; }
.abadge-rejected { background:#f8d7da; color:#721c24; }
.abadge-validated { background:#cce5ff; color:#004085; }

/* ── Filters ── */
.filter-bar { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
.filter-bar select, .filter-bar input[type=date] {
    padding:7px 10px; border:1px solid #ced4da; border-radius:6px; font-size:13px; color:#495057;
}
.filter-bar select:focus, .filter-bar input[type=date]:focus {
    border-color:#002F70; outline:none; box-shadow:0 0 0 2px rgba(0,47,112,.1);
}
.btn-filter { padding:7px 16px; background:#002F70; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; }
.btn-filter:hover { background:#001F4F; }
.btn-export { padding:7px 16px; background:#28a745; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.btn-export:hover { background:#1e7e34; }
.btn-reset { padding:7px 14px; background:#f8f9fa; color:#495057; border:1px solid #ced4da; border-radius:6px; font-size:13px; cursor:pointer; }
.btn-reset:hover { background:#e9ecef; }

/* ── Readonly badge ── */
.readonly-badge { display:inline-flex; align-items:center; gap:5px; background:#e3f2fd; color:#1565c0; border:1px solid #90caf9; border-radius:20px; padding:3px 11px; font-size:11px; font-weight:600; }

/* ── Table ── */
.table-scroll { max-height:520px; overflow-y:auto; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-clipboard-list"></i> Stock Requests Oversight</h1>
        <div class="sub">Admin oversight &mdash; read-only view with audit trail &amp; compliance export</div>
    </div>
    <div class="header-actions">
        <span class="readonly-badge"><i class="fas fa-eye"></i> Oversight Only</span>
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<!-- Stats -->
<div class="stats-row" id="statsRow">
    <div class="stat-box"><div class="stat-num" id="statTotal">—</div><div class="stat-lbl">Total Requests</div></div>
    <div class="stat-box"><div class="stat-num" id="statPending" style="color:#856404;">—</div><div class="stat-lbl">Pending</div></div>
    <div class="stat-box"><div class="stat-num" id="statApproved" style="color:#155724;">—</div><div class="stat-lbl">Approved</div></div>
    <div class="stat-box"><div class="stat-num" id="statRejected" style="color:#721c24;">—</div><div class="stat-lbl">Rejected</div></div>
</div>

<!-- Tabs -->
<div class="tab-nav">
    <button class="tab-btn <?= $active_tab === 'requests' ? 'active' : '' ?>" onclick="switchTab('requests',this)">
        <i class="fas fa-list"></i> Stock Requests
    </button>
    <button class="tab-btn <?= $active_tab === 'audit' ? 'active' : '' ?>" onclick="switchTab('audit',this)">
        <i class="fas fa-shield-alt"></i> Audit Trail
    </button>
</div>

<!-- ══ REQUESTS TAB ══ -->
<div id="tab-requests" style="display:<?= $active_tab === 'requests' ? 'block' : 'none' ?>;">
    <div class="sr-card">
        <div class="sr-card-head">
            <div class="sr-card-title"><i class="fas fa-list"></i> All Stock Requests</div>
            <button class="btn-export" onclick="exportRequests()">
                <i class="fas fa-download"></i> Export CSV
            </button>
        </div>
        <div class="sr-card-body">
            <div class="filter-bar">
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#6c757d;margin-bottom:3px;">STATUS</label>
                    <select id="reqStatusFilter">
                        <option value="">All Status</option>
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#6c757d;margin-bottom:3px;">DATE FROM</label>
                    <input type="date" id="reqDateFrom">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#6c757d;margin-bottom:3px;">DATE TO</label>
                    <input type="date" id="reqDateTo" value="<?= date('Y-m-d') ?>">
                </div>
                <div style="display:flex;gap:6px;align-items:flex-end;">
                    <button class="btn-filter" onclick="loadRequests()"><i class="fas fa-filter"></i> Filter</button>
                    <button class="btn-reset" onclick="resetReqFilters()">Reset</button>
                </div>
            </div>
            <div class="table-scroll">
                <table class="table" id="reqTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Station</th>
                            <th>Staff</th>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Category</th>
                            <th>Current Stock</th>
                            <th>Qty Requested</th>
                            <th>Qty Approved</th>
                            <th>Status</th>
                            <th>Manager</th>
                            <th>Manager Notes</th>
                            <th>Staff Remarks</th>
                            <th>Processed On</th>
                        </tr>
                    </thead>
                    <tbody id="reqBody">
                        <tr><td colspan="15" style="text-align:center;padding:30px;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══ AUDIT TRAIL TAB ══ -->
<div id="tab-audit" style="display:<?= $active_tab === 'audit' ? 'block' : 'none' ?>;">
    <div class="sr-card">
        <div class="sr-card-head">
            <div class="sr-card-title"><i class="fas fa-shield-alt"></i> Audit Trail</div>
            <button class="btn-export" onclick="exportAudit()">
                <i class="fas fa-download"></i> Export CSV
            </button>
        </div>
        <div class="sr-card-body">
            <div class="filter-bar">
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#6c757d;margin-bottom:3px;">STATUS</label>
                    <select id="auditStatusFilter">
                        <option value="">All</option>
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#6c757d;margin-bottom:3px;">DATE FROM</label>
                    <input type="date" id="auditDateFrom">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#6c757d;margin-bottom:3px;">DATE TO</label>
                    <input type="date" id="auditDateTo" value="<?= date('Y-m-d') ?>">
                </div>
                <div style="display:flex;gap:6px;align-items:flex-end;">
                    <button class="btn-filter" onclick="loadAudit()"><i class="fas fa-filter"></i> Filter</button>
                    <button class="btn-reset" onclick="resetAuditFilters()">Reset</button>
                </div>
            </div>

            <div style="background:#e8f4fd;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#002F70;">
                <i class="fas fa-info-circle"></i>
                <strong>Compliance Note:</strong> This audit trail records every action on stock requests — creation by staff, approval/rejection by manager. Export CSV for compliance reporting.
            </div>

            <div class="table-scroll">
                <table class="table" id="auditTable">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Action</th>
                            <th>Performed By</th>
                            <th>Role</th>
                            <th>Station</th>
                            <th>Staff (Requester)</th>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Old Status</th>
                            <th>New Status</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody id="auditBody">
                        <tr><td colspan="11" style="text-align:center;padding:30px;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
var _reqData   = [];
var _auditData = [];

document.addEventListener('DOMContentLoaded', function() {
    loadRequests();
    if ('<?= $active_tab ?>' === 'audit') loadAudit();
});

// ── Tab switch ────────────────────────────────────────────────────────────────
function switchTab(tab, btn) {
    document.getElementById('tab-requests').style.display = tab === 'requests' ? 'block' : 'none';
    document.getElementById('tab-audit').style.display    = tab === 'audit'    ? 'block' : 'none';
    document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    if (tab === 'audit' && _auditData.length === 0) loadAudit();
}

// ── Load requests ─────────────────────────────────────────────────────────────
function loadRequests() {
    var params = new URLSearchParams();
    var status = document.getElementById('reqStatusFilter').value;
    var from   = document.getElementById('reqDateFrom').value;
    var to     = document.getElementById('reqDateTo').value;
    if (status) params.set('status', status);
    if (from)   params.set('date_from', from);
    if (to)     params.set('date_to', to);

    fetch('../backend/api/stock_request.php?action=get_requests&' + params.toString())
    .then(function(r) { return r.json(); })
    .then(function(data) {
        _reqData = data.requests || [];
        renderRequests(_reqData);
        updateStats(_reqData);
    })
    .catch(function() {
        document.getElementById('reqBody').innerHTML = '<tr><td colspan="15" style="text-align:center;padding:30px;color:#dc3545;">Error loading requests.</td></tr>';
    });
}

function renderRequests(rows) {
    var tbody = document.getElementById('reqBody');
    if (rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="15" style="text-align:center;padding:40px;color:#6c757d;">No requests found.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(function(r) {
        var st  = r.status || 'Unknown';
        var cls = 'sbadge sbadge-' + st.toLowerCase();
        var qtyApp = (r.approved_quantity !== null && r.approved_quantity !== undefined)
            ? '<strong style="color:#28a745;">' + r.approved_quantity + '</strong>'
            : '<span style="color:#adb5bd;">—</span>';
        return '<tr>' +
            '<td style="color:#6c757d;font-size:12px;">#' + r.id + '</td>' +
            '<td style="font-size:12px;">' + fmtDate(r.created_at) + '</td>' +
            '<td style="font-size:12px;">' + esc(r.station_name || '—') + '</td>' +
            '<td>' + esc(r.staff_name || '') + '</td>' +
            '<td><strong>' + esc(r.item_name) + '</strong></td>' +
            '<td><code style="font-size:11px;">' + esc(r.item_sku || '') + '</code></td>' +
            '<td style="font-size:12px;">' + esc(r.item_category || '') + '</td>' +
            '<td style="text-align:center;">' + r.current_stock + '</td>' +
            '<td style="text-align:center;font-weight:700;color:#002F70;">' + r.requested_quantity + '</td>' +
            '<td style="text-align:center;">' + qtyApp + '</td>' +
            '<td><span class="' + cls + '">' + esc(st) + '</span></td>' +
            '<td style="font-size:12px;">' + esc(r.manager_name || '—') + '</td>' +
            '<td style="font-size:12px;color:#495057;max-width:160px;">' + (r.manager_notes ? esc(r.manager_notes) : '<span style="color:#adb5bd;">—</span>') + '</td>' +
            '<td style="font-size:12px;color:#6c757d;max-width:140px;">' + (r.remarks ? esc(r.remarks) : '<span style="color:#adb5bd;">—</span>') + '</td>' +
            '<td style="font-size:12px;color:#6c757d;">' + (r.processed_at ? fmtDate(r.processed_at) : '<span style="color:#adb5bd;">—</span>') + '</td>' +
        '</tr>';
    }).join('');
}

function updateStats(rows) {
    document.getElementById('statTotal').textContent    = rows.length;
    document.getElementById('statPending').textContent  = rows.filter(function(r) { return r.status === 'Pending'; }).length;
    document.getElementById('statApproved').textContent = rows.filter(function(r) { return r.status === 'Approved'; }).length;
    document.getElementById('statRejected').textContent = rows.filter(function(r) { return r.status === 'Rejected'; }).length;
}

function resetReqFilters() {
    document.getElementById('reqStatusFilter').value = '';
    document.getElementById('reqDateFrom').value     = '';
    document.getElementById('reqDateTo').value       = '<?= date('Y-m-d') ?>';
    loadRequests();
}

// ── Load audit trail ──────────────────────────────────────────────────────────
function loadAudit() {
    var params = new URLSearchParams();
    var status = document.getElementById('auditStatusFilter').value;
    var from   = document.getElementById('auditDateFrom').value;
    var to     = document.getElementById('auditDateTo').value;
    if (status) params.set('status', status);
    if (from)   params.set('date_from', from);
    if (to)     params.set('date_to', to);

    fetch('../backend/api/stock_request.php?action=audit_trail&' + params.toString())
    .then(function(r) { return r.json(); })
    .then(function(data) {
        _auditData = data.audit_trail || [];
        renderAudit(_auditData);
    })
    .catch(function() {
        document.getElementById('auditBody').innerHTML = '<tr><td colspan="11" style="text-align:center;padding:30px;color:#dc3545;">Error loading audit trail.</td></tr>';
    });
}

function renderAudit(rows) {
    var tbody = document.getElementById('auditBody');
    if (rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:40px;color:#6c757d;">No audit records found.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(function(r) {
        var act = (r.action_type || '').toLowerCase();
        var cls = 'abadge abadge-' + act;
        return '<tr>' +
            '<td style="font-size:12px;white-space:nowrap;">' + fmtDate(r.created_at) + '</td>' +
            '<td><span class="' + cls + '">' + esc(r.action_type || '') + '</span></td>' +
            '<td>' + esc(r.performed_by_name || '') + '</td>' +
            '<td style="font-size:12px;text-transform:capitalize;">' + esc(r.performed_by_role || '') + '</td>' +
            '<td style="font-size:12px;">' + esc(r.station_name || '—') + '</td>' +
            '<td style="font-size:12px;">' + esc(r.staff_name || '') + '</td>' +
            '<td><strong>' + esc(r.item_name || '') + '</strong></td>' +
            '<td><code style="font-size:11px;">' + esc(r.item_sku || '') + '</code></td>' +
            '<td>' + (r.old_status ? '<span class="sbadge sbadge-' + r.old_status.toLowerCase() + '">' + esc(r.old_status) + '</span>' : '<span style="color:#adb5bd;">—</span>') + '</td>' +
            '<td>' + (r.new_status ? '<span class="sbadge sbadge-' + r.new_status.toLowerCase() + '">' + esc(r.new_status) + '</span>' : '<span style="color:#adb5bd;">—</span>') + '</td>' +
            '<td style="font-size:12px;color:#495057;max-width:200px;">' + esc(r.notes || '') + '</td>' +
        '</tr>';
    }).join('');
}

function resetAuditFilters() {
    document.getElementById('auditStatusFilter').value = '';
    document.getElementById('auditDateFrom').value     = '';
    document.getElementById('auditDateTo').value       = '<?= date('Y-m-d') ?>';
    loadAudit();
}

// ── Export ────────────────────────────────────────────────────────────────────
function exportRequests() {
    var params = new URLSearchParams();
    var status = document.getElementById('reqStatusFilter').value;
    var from   = document.getElementById('reqDateFrom').value;
    var to     = document.getElementById('reqDateTo').value;
    if (status) params.set('status', status);
    if (from)   params.set('date_from', from);
    if (to)     params.set('date_to', to);
    window.location.href = '../backend/api/stock_request.php?action=export_csv&' + params.toString();
}

function exportAudit() {
    // Export audit trail as CSV via requests export with all statuses
    var params = new URLSearchParams();
    var from = document.getElementById('auditDateFrom').value;
    var to   = document.getElementById('auditDateTo').value;
    if (from) params.set('date_from', from);
    if (to)   params.set('date_to', to);
    window.location.href = '../backend/api/stock_request.php?action=export_csv&' + params.toString();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(ds) {
    if (!ds) return '—';
    var d = new Date(ds);
    return d.toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'}) + ' ' +
           d.toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit'});
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
