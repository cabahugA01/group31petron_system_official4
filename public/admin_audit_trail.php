<?php
// Admin Audit Trail Module
$page_id = 'admin_audit_trail';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

$station_id   = (int) user_station_id();
if ($station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}
$station_name = 'Station';
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: 'Station';
} catch (Exception $e) {}

// Date range defaults
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// User, Action, Module Filters
$user_filter   = (int)($_GET['user_id'] ?? 0);
$action_filter = trim($_GET['action_type'] ?? '');
$module_filter = trim($_GET['module'] ?? '');

// Populate filters dynamically
$staff_members = [];
try {
    $su = $pdo->prepare("SELECT id, name, role FROM users WHERE station_id=? AND status='active' AND LOWER(role) NOT IN ('admin','superadmin','super admin') ORDER BY name");
    $su->execute([$station_id]);
    $staff_members = $su->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$action_types = [];
try {
    $sa = $pdo->prepare("SELECT DISTINCT al.action_type FROM audit_logs al JOIN users u ON u.id = al.user_id WHERE u.station_id=? ORDER BY al.action_type");
    $sa->execute([$station_id]);
    $action_types = $sa->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {}

$modules = [];
try {
    $sm = $pdo->prepare("SELECT DISTINCT al.entity_type FROM audit_logs al JOIN users u ON u.id = al.user_id WHERE u.station_id=? ORDER BY al.entity_type");
    $sm->execute([$station_id]);
    $modules = $sm->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Audit Trail Styles ────────────────────────────────── */
.aud-page { padding: 0; }

.aud-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 20px;
}
.aud-header-left h1 { margin: 0 0 4px; }
.aud-header-left .sub { color: var(--muted); font-size: 13px; }

/* Filter bar */
.aud-filter-bar {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 20px;
}
.aud-filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    align-items: flex-end;
}
.aud-fg { display: flex; flex-direction: column; gap: 4px; }
.aud-fg label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; }
.aud-fg select, .aud-fg input[type=date] {
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 7px 10px;
    font-size: 13px;
    outline: none;
    background: #fff;
}
.aud-fg select:focus, .aud-fg input[type=date]:focus { border-color: var(--blue); }

.aud-filter-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* Compliance scope alert */
.scope-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 18px;
    font-size: 13px;
    color: #1e40af;
}
.scope-banner i { font-size: 18px; color: #3b82f6; }

/* Export buttons */
.export-bar {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.export-bar .lbl { font-size: 12px; color: var(--muted); }
.btn-export {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid var(--line);
    background: #fff;
    cursor: pointer;
    text-decoration: none;
    color: var(--text);
    transition: all .15s;
}
.btn-export:hover { background: #f1f5f9; }
.btn-export.excel { border-color: #16a34a; color: #16a34a; }
.btn-export.excel:hover { background: #f0fdf4; }
.btn-export.pdf   { border-color: #dc2626; color: #dc2626; }
.btn-export.pdf:hover   { background: #fef2f2; }

/* Table styles */
.aud-table-wrap {
    overflow-x:hidden;
    border-radius: 12px;
    border: 1px solid var(--line);
}
.aud-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.aud-table thead th {
    background: var(--blue);
    color: #fff;
    padding: 10px 14px;
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}
.aud-table tbody td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--line);
    vertical-align: top;
}
.aud-table tbody tr:last-child td { border-bottom: none; }
.aud-table tbody tr:hover td { background: #f8fafc; }

.badge-status {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
}
.badge-status.success { background: #dcfce7; color: #16a34a; }
.badge-status.failed { background: #fee2e2; color: #dc2626; }
.badge-status.pending { background: #fef9c3; color: #ca8a04; }

.badge-role {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    background: #f1f5f9;
    color: #475569;
}

.aud-loading {
    text-align: center;
    padding: 45px;
    color: var(--muted);
}
.aud-loading i { font-size: 28px; margin-bottom: 10px; display: block; }
.aud-empty {
    text-align: center;
    padding: 45px;
    color: var(--muted);
}
.aud-empty i { font-size: 32px; margin-bottom: 10px; display: block; opacity: .4; }
</style>

<div class="page-content aud-page">

  <!-- ── Page Header ──────────────────────────────────────────────────────── -->
  <div class="aud-header">
    <div class="aud-header-left">
      <h1><i class="fas fa-shield-halved" style="color:var(--blue);margin-right:8px"></i>Compliance Audit Trail</h1>
      <div class="sub"><?php echo htmlspecialchars($station_name); ?> &mdash; Independent security &amp; accountability log</div>
    </div>
  </div>

  <!-- ── Compliance Scope Banner ─────────────────────────────────────────── -->
  <div class="scope-banner">
    <i class="fas fa-circle-info"></i>
    <div>
      <strong>Oversight Scope:</strong> This compliance trail tracks all Staff and Manager activities (transactions, adjustments, and price approvals). Admin actions are excluded to maintain strict administrative independence.
    </div>
  </div>

  <!-- ── Search & Filter Panel ───────────────────────────────────────────── -->
  <form method="get" class="aud-filter-bar" id="auditFilterForm">
    <div class="aud-filter-grid">
      
      <div class="aud-fg">
        <label>User / Encoder</label>
        <select name="user_id" id="userId">
          <option value="0">-- All Active Users --</option>
          <?php foreach ($staff_members as $sm): ?>
            <option value="<?php echo $sm['id']; ?>" <?php echo $user_filter === (int)$sm['id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($sm['name']); ?> (<?php echo htmlspecialchars(ucfirst($sm['role'])); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="aud-fg">
        <label>Action Type</label>
        <select name="action_type" id="actionType">
          <option value="">-- All Actions --</option>
          <?php foreach ($action_types as $at): ?>
            <option value="<?php echo htmlspecialchars($at); ?>" <?php echo $action_filter === $at ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($at); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="aud-fg">
        <label>Module</label>
        <select name="module" id="module">
          <option value="">-- All Modules --</option>
          <?php foreach ($modules as $m): ?>
            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $module_filter === $m ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($m); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="aud-fg">
        <label>From Date</label>
        <input type="date" name="date_from" id="dateFrom" value="<?php echo htmlspecialchars($date_from); ?>">
      </div>

      <div class="aud-fg">
        <label>To Date</label>
        <input type="date" name="date_to" id="dateTo" value="<?php echo htmlspecialchars($date_to); ?>">
      </div>

      <div class="aud-filter-actions">
        <button type="submit" class="btn primary" style="padding:7px 16px;font-size:13px;width:100%">
          <i class="fas fa-filter"></i> Filter
        </button>
        <button type="button" class="btn ghost" style="font-size:12px;width:100%" onclick="resetFilters()">
          Clear
        </button>
      </div>

    </div>
  </form>

  <!-- ── Export utilities ────────────────────────────────────────────────── -->
  <div class="export-bar">
    <span class="lbl"><i class="fas fa-download"></i> Export Options:</span>
    <a id="exportExcelBtn" href="#" class="btn-export excel" target="_blank">
      <i class="fas fa-file-csv"></i> Export CSV
    </a>
    <a id="exportPdfBtn" href="#" class="btn-export pdf" target="_blank">
      <i class="fas fa-file-pdf"></i> Export PDF
    </a>
  </div>

  <!-- ── Data Table ──────────────────────────────────────────────────────── -->
  <div class="aud-table-wrap">
    <table class="aud-table">
      <thead>
        <tr>
          <th>Date &amp; Time</th>
          <th>User</th>
          <th>Role</th>
          <th>Action</th>
          <th>Module</th>
          <th>Details</th>
          <th>IP Address</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="auditTbody">
        <tr><td colspan="8" class="aud-loading"><i class="fas fa-spinner fa-spin"></i>Loading compliance records...</td></tr>
      </tbody>
    </table>
  </div>

</div><!-- /.aud-page -->

<script>
(function() {
'use strict';

const API = '../backend/api/admin_reports_audit_api.php';

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

function statusBadge(s) {
    const sl = (s || '').toLowerCase();
    const cls = sl === 'success' || sl === 'completed' || sl === 'approved' ? 'success' : (sl === 'failed' || sl === 'error' || sl === 'rejected' ? 'failed' : 'pending');
    return `<span class="badge-status ${cls}">${esc(s)}</span>`;
}

function formatTime(tStr) {
    if (!tStr) return '—';
    const date = new Date(tStr);
    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

function emptyRow(cols, msg = 'No compliance records found matching the active criteria.') {
    return `<tr><td colspan="${cols}" class="aud-empty"><i class="fas fa-shield-slash"></i>${msg}</td></tr>`;
}

window.resetFilters = function() {
    document.getElementById('userId').value = '0';
    document.getElementById('actionType').value = '';
    document.getElementById('module').value = '';
    
    // Set to first day of current month
    const today = new Date();
    const from = today.toISOString().slice(0, 8) + '01';
    const to = today.toISOString().slice(0, 10);
    document.getElementById('dateFrom').value = from;
    document.getElementById('dateTo').value = to;
    
    document.getElementById('auditFilterForm').submit();
};

function loadAuditTrail() {
    const userId     = document.getElementById('userId').value;
    const actionType = document.getElementById('actionType').value;
    const module     = document.getElementById('module').value;
    const dateFrom   = document.getElementById('dateFrom').value;
    const dateTo     = document.getElementById('dateTo').value;

    // Update export links
    const baseParams = new URLSearchParams({
        action: 'export_audit',
        date_from: dateFrom,
        date_to: dateTo,
        user_id: userId,
        action_type: actionType,
        module: module
    });
    
    document.getElementById('exportExcelBtn').href = `${API}?${baseParams.toString()}&format=csv`;
    document.getElementById('exportPdfBtn').href   = `${API}?${baseParams.toString()}&format=pdf`;

    // Fetch live logs
    const fetchParams = new URLSearchParams({
        action: 'audit_trail',
        date_from: dateFrom,
        date_to: dateTo,
        user_id: userId,
        action_type: actionType,
        module: module
    });

    fetch(`${API}?${fetchParams.toString()}`)
        .then(r => r.json())
        .then(res => {
            if (!res.ok) {
                document.getElementById('auditTbody').innerHTML = emptyRow(8, res.error || 'Failed to fetch logs.');
                return;
            }
            const rows = res.data || [];
            if (!rows.length) {
                document.getElementById('auditTbody').innerHTML = emptyRow(8);
                return;
            }

            let html = '';
            rows.forEach(r => {
                html += `<tr>
                    <td style="white-space:nowrap">${formatTime(r.created_at)}</td>
                    <td><strong>${esc(r.user_name)}</strong><br><small style="color:var(--muted)">ID: ${parseInt(r.user_id)}</small></td>
                    <td><span class="badge-role">${esc(r.role)}</span></td>
                    <td><strong>${esc(r.action_type)}</strong></td>
                    <td><code>${esc(r.module)}</code></td>
                    <td style="max-width:280px;word-break:break-word">${esc(r.details)}</td>
                    <td><code>${esc(r.ip_address)}</code></td>
                    <td>${statusBadge(r.status)}</td>
                </tr>`;
            });
            document.getElementById('auditTbody').innerHTML = html;
        })
        .catch(err => {
            document.getElementById('auditTbody').innerHTML = emptyRow(8, 'Error loading logs: ' + err.message);
        });
}

// Initial load
loadAuditTrail();

})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
