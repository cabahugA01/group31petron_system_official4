<?php
// Admin Audit Trail Module — Full Compliance Log (All Roles)
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

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

$user_filter   = (int)($_GET['user_id'] ?? 0);
$action_filter = trim($_GET['action_type'] ?? '');
$module_filter = trim($_GET['module'] ?? '');
$status_filter = trim($_GET['status_filter'] ?? '');
$active_tab    = in_array($_GET['atab'] ?? '', ['logs','anomalies']) ? ($_GET['atab'] ?? 'logs') : 'logs';

// Users filter — Staff, Manager, Admin only (SuperAdmin excluded)
$all_users = [];
try {
    $su = $pdo->prepare("
        SELECT `user_id`, name, role FROM users
        WHERE station_id=? AND status = 'Active'
          AND LOWER(TRIM(COALESCE(role,''))) NOT IN ('superadmin','super admin','super_admin')
        ORDER BY role, name
    ");
    $su->execute([$station_id]);
    $all_users = $su->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Distinct action types (Staff, Manager, Admin only)
$action_types = [];
try {
    $sa = $pdo->prepare("
        SELECT DISTINCT al.action_type
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE u.station_id = ?
          AND LOWER(TRIM(COALESCE(u.role,''))) NOT IN ('superadmin','super admin','super_admin')
          AND al.action_type IS NOT NULL
        ORDER BY al.action_type
    ");
    $sa->execute([$station_id]);
    $action_types = $sa->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {}

// Distinct modules (Staff, Manager, Admin only)
$modules = [];
try {
    $sm = $pdo->prepare("
        SELECT DISTINCT al.entity_type
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE u.station_id = ?
          AND LOWER(TRIM(COALESCE(u.role,''))) NOT IN ('superadmin','super admin','super_admin')
          AND al.entity_type IS NOT NULL
        ORDER BY al.entity_type
    ");
    $sm->execute([$station_id]);
    $modules = $sm->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.aud-page { padding: 0; }
.aud-header { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:14px; margin-bottom:18px; }
.aud-header-left h1 { margin: 0 0 4px; }
.aud-header-left .sub { color:var(--muted); font-size:13px; }

/* Summary cards */
.aud-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:18px; }
.aud-stat-card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; }
.aud-stat-card .sc-label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.4px; }
.aud-stat-card .sc-val { font-size:22px; font-weight:700; margin-top:4px; color:var(--blue); }
.aud-stat-card.danger .sc-val { color:#dc2626; }
.aud-stat-card.warn .sc-val   { color:#d97706; }
.aud-stat-card.ok .sc-val     { color:#16a34a; }

/* Scope banner */
.scope-banner { display:flex; align-items:flex-start; gap:12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:12px 16px; margin-bottom:18px; font-size:13px; color:#1e40af; }
.scope-banner i { font-size:18px; color:#3b82f6; flex-shrink:0; margin-top:2px; }
.scope-banner ul { margin:4px 0 0 16px; padding:0; }
.scope-banner ul li { margin-bottom:2px; }

/* Sub-tabs */
.aud-tabs { display:flex; gap:6px; border-bottom:2px solid var(--line); margin-bottom:18px; }
.aud-tab { padding:9px 18px; font-size:13px; font-weight:600; border:none; background:none; cursor:pointer; color:var(--muted); border-bottom:2px solid transparent; margin-bottom:-2px; border-radius:8px 8px 0 0; transition:all .15s; }
.aud-tab:hover { background:#f1f5f9; color:var(--text); }
.aud-tab.active { color:var(--blue); border-bottom-color:var(--blue); background:#eff6ff; }

/* Filter bar */
.aud-filter-bar { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:18px; }
.aud-filter-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; align-items:flex-end; }
.aud-fg { display:flex; flex-direction:column; gap:4px; }
.aud-fg label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.4px; }
.aud-fg select, .aud-fg input[type=date] { border:1px solid var(--line); border-radius:8px; padding:7px 10px; font-size:13px; outline:none; background:#fff; }
.aud-fg select:focus, .aud-fg input[type=date]:focus { border-color:var(--blue); }

/* Export bar */
.export-bar { display:flex; gap:8px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
.export-bar .lbl { font-size:12px; color:var(--muted); }
.btn-export { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:12px; font-weight:600; border:1px solid var(--line); background:#fff; cursor:pointer; text-decoration:none; color:var(--text); transition:all .15s; }
.btn-export.excel { border-color:#16a34a; color:#16a34a; }
.btn-export.excel:hover { background:#f0fdf4; }
.btn-export.pdf   { border-color:#dc2626; color:#dc2626; }
.btn-export.pdf:hover { background:#fef2f2; }

/* Table */
.aud-table-wrap { overflow:hidden; border-radius:12px; border:1px solid var(--line); }
.aud-table { width:100%; border-collapse:collapse; font-size:13px; }
.aud-table thead th { background:var(--blue); color:#fff; padding:10px 14px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.4px; }
.aud-table tbody td { padding:10px 14px; border-bottom:1px solid var(--line); vertical-align:top; }
.aud-table tbody tr:last-child td { border-bottom:none; }
.aud-table tbody tr:hover td { background:#f8fafc; }

.badge-status { display:inline-block; padding:2px 8px; border-radius:12px; font-size:10px; font-weight:700; text-transform:uppercase; }
.badge-status.success { background:#dcfce7; color:#16a34a; }
.badge-status.failed  { background:#fee2e2; color:#dc2626; }
.badge-status.pending { background:#fef9c3; color:#ca8a04; }

.badge-role { display:inline-block; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; }
.badge-role.admin     { background:#ede9fe; color:#7c3aed; }
.badge-role.manager   { background:#dbeafe; color:#1d4ed8; }
.badge-role.staff     { background:#dcfce7; color:#15803d; }

.anomaly-card { background:#fff; border:1px solid #fecaca; border-left:4px solid #dc2626; border-radius:10px; padding:14px 16px; margin-bottom:10px; }
.anomaly-card .a-head { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
.anomaly-card .a-count { background:#fee2e2; color:#dc2626; font-weight:700; font-size:13px; padding:2px 10px; border-radius:20px; }
.anomaly-card .a-detail { font-size:12px; color:var(--muted); }

.aud-loading { text-align:center; padding:45px; color:var(--muted); }
.aud-loading i { font-size:28px; margin-bottom:10px; display:block; }
.aud-empty { text-align:center; padding:45px; color:var(--muted); }
.aud-empty i { font-size:32px; margin-bottom:10px; display:block; opacity:.4; }
</style>

<div class="page-content aud-page">

  <!-- Page Header -->
  <div class="aud-header">
    <div class="aud-header-left">
      <h1><i class="fas fa-shield-halved" style="color:var(--blue);margin-right:8px"></i>Compliance Audit Trail</h1>
      <div class="sub"><?php echo htmlspecialchars($station_name); ?> &mdash; Full accountability log — Staff, Manager &amp; Admin actions</div>
    </div>
  </div>

  <!-- Summary Stats -->
  <div class="aud-stats" id="auditStats">
    <div class="aud-stat-card ok"><div class="sc-label">Total Logs</div><div class="sc-val" id="statTotal">—</div></div>
    <div class="aud-stat-card"><div class="sc-label">Active Users</div><div class="sc-val" id="statUsers">—</div></div>
    <div class="aud-stat-card danger"><div class="sc-label">Failed Actions</div><div class="sc-val" id="statFailed">—</div></div>
    <div class="aud-stat-card warn"><div class="sc-label">Anomaly IPs</div><div class="sc-val" id="statAnomalies">—</div></div>
  </div>

  <!-- Scope Banner -->
  <div class="scope-banner">
    <i class="fas fa-circle-info"></i>
    <div>
      <strong>Scope — Station Level Only:</strong> This audit trail logs actions from <em>Staff, Manager, and Admin</em> users assigned to this station. SuperAdmin actions are handled separately at the system level.
      <ul>
        <li><strong>Staff</strong> &mdash; encoding, stock-in, transactions, deliveries</li>
        <li><strong>Manager</strong> &mdash; validation, approvals, rejections, returns</li>
        <li><strong>Admin</strong> &mdash; oversight decisions, price changes, user management</li>
      </ul>
      Timestamps, IP addresses, and action details are captured for every event for full compliance and accountability.
    </div>
  </div>

  <!-- Sub-tabs -->
  <div class="aud-tabs">
    <button class="aud-tab <?php echo $active_tab==='logs'?'active':''; ?>" onclick="switchAudTab('logs',this)">
      <i class="fas fa-list-ul"></i> All Logs
    </button>
    <button class="aud-tab <?php echo $active_tab==='anomalies'?'active':''; ?>" onclick="switchAudTab('anomalies',this)">
      <i class="fas fa-triangle-exclamation"></i> Anomaly Detection
    </button>
  </div>

  <!-- Filter Bar -->
  <form method="get" class="aud-filter-bar" id="auditFilterForm">
    <input type="hidden" name="atab" id="hiddenAtab" value="<?php echo htmlspecialchars($active_tab); ?>">
    <div class="aud-filter-grid">

      <div class="aud-fg">
        <label>User / Encoder</label>
        <select name="user_id" id="userId">
          <option value="0">-- All Users --</option>
          <?php foreach ($all_users as $u): ?>
            <option value="<?php echo $u['id']; ?>" <?php echo $user_filter===(int)$u['id']?'selected':''; ?>>
              <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars(ucfirst($u['role'])); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="aud-fg">
        <label>Action Type</label>
        <select name="action_type" id="actionType">
          <option value="">-- All Actions --</option>
          <?php foreach ($action_types as $at): ?>
            <option value="<?php echo htmlspecialchars($at); ?>" <?php echo $action_filter===$at?'selected':''; ?>>
              <?php echo htmlspecialchars($at); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="aud-fg">
        <label>Module</label>
        <select name="module" id="moduleFilter">
          <option value="">-- All Modules --</option>
          <?php foreach ($modules as $m): ?>
            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $module_filter===$m?'selected':''; ?>>
              <?php echo htmlspecialchars($m); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="aud-fg">
        <label>Status</label>
        <select name="status_filter" id="statusFilter">
          <option value="">-- All Status --</option>
          <option value="success" <?php echo $status_filter==='success'?'selected':''; ?>>Success</option>
          <option value="failed"  <?php echo $status_filter==='failed'?'selected':''; ?>>Failed</option>
          <option value="pending" <?php echo $status_filter==='pending'?'selected':''; ?>>Pending</option>
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

      <div class="aud-fg" style="flex-direction:row;gap:6px;align-items:flex-end">
        <button type="submit" class="btn primary" style="padding:7px 16px;font-size:13px;flex:1">
          <i class="fas fa-filter"></i> Filter
        </button>
        <button type="button" class="btn ghost" style="font-size:12px" onclick="resetFilters()">Clear</button>
      </div>

    </div>
  </form>

  <!-- ═══ LOGS TAB ═══════════════════════════════════════════════════════════ -->
  <div id="tab-logs" style="<?php echo $active_tab==='anomalies'?'display:none':''; ?>">
    <div class="export-bar">
      <span class="lbl"><i class="fas fa-download"></i> Export:</span>
      <a id="exportExcelBtn" href="#" class="btn-export excel" target="_blank">
        <i class="fas fa-file-csv"></i> Export CSV
      </a>
      <a id="exportPdfBtn" href="#" class="btn-export pdf" target="_blank">
        <i class="fas fa-file-pdf"></i> Export PDF
      </a>
    </div>

    <div class="aud-table-wrap">
      <table class="aud-table">
        <thead>
          <tr>
            <th>Date &amp; Time</th>
            <th>User</th>
            <th>Role</th>
            <th>Action</th>
            <th>Module</th>
            <th>Source</th>
            <th>Details</th>
            <th>IP Address</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="auditTbody">
          <tr><td colspan="9" class="aud-loading"><i class="fas fa-spinner fa-spin"></i>Loading compliance records...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══ ANOMALIES TAB ══════════════════════════════════════════════════════ -->
  <div id="tab-anomalies" style="<?php echo $active_tab==='logs'?'display:none':''; ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" id="anomalyGrid">
      <div>
        <h3 style="margin:0 0 12px;font-size:14px;color:#dc2626"><i class="fas fa-exclamation-triangle"></i> Repeated Failed Actions (≥3)</h3>
        <div id="anomalyFailures"><div class="aud-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</div></div>
      </div>
      <div>
        <h3 style="margin:0 0 12px;font-size:14px;color:#d97706"><i class="fas fa-rotate-left"></i> Repeated Rejections (≥2)</h3>
        <div id="anomalyRejections"><div class="aud-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</div></div>
      </div>
    </div>
  </div>

</div><!-- /.aud-page -->

<script>
(function() {
'use strict';

const API    = '../backend/api/admin_reports_audit_api.php';
const FROM   = document.getElementById('dateFrom').value;
const TO     = document.getElementById('dateTo').value;
const UID    = document.getElementById('userId').value;
const ACT    = document.getElementById('actionType').value;
const MOD    = document.getElementById('moduleFilter').value;
const STA    = document.getElementById('statusFilter').value;

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

function statusBadge(s) {
    const sl = (s || '').toLowerCase();
    const cls = sl==='success'||sl==='completed'||sl==='approved' ? 'success' : (sl==='failed'||sl==='error'||sl==='rejected' ? 'failed' : 'pending');
    return `<span class="badge-status ${cls}">${esc(s)}</span>`;
}

function roleBadge(r) {
    const rl = (r || '').toLowerCase().replace(/\s/g,'');
    const cls = rl.includes('super') ? 'superadmin' : (rl==='admin' ? 'admin' : (rl==='manager' ? 'manager' : 'staff'));
    return `<span class="badge-role ${cls}">${esc(r)}</span>`;
}

function fmt(tStr) {
    if (!tStr) return '—';
    return new Date(tStr).toLocaleString('en-US', {month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true});
}

// Switch tabs
window.switchAudTab = function(tab, btn) {
    document.querySelectorAll('.aud-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-logs').style.display      = tab==='logs'      ? '' : 'none';
    document.getElementById('tab-anomalies').style.display = tab==='anomalies' ? '' : 'none';
    document.getElementById('hiddenAtab').value = tab;
    if (tab === 'anomalies') loadAnomalies();
};

window.resetFilters = function() {
    const today = new Date();
    document.getElementById('userId').value        = '0';
    document.getElementById('actionType').value    = '';
    document.getElementById('moduleFilter').value  = '';
    document.getElementById('statusFilter').value  = '';
    document.getElementById('dateFrom').value      = today.toISOString().slice(0,8) + '01';
    document.getElementById('dateTo').value        = today.toISOString().slice(0,10);
    document.getElementById('auditFilterForm').submit();
};

// ── Summary Stats ─────────────────────────────────────────────────────────
function loadSummary() {
    fetch(`${API}?action=audit_summary&date_from=${FROM}&date_to=${TO}`)
        .then(r => r.json()).then(res => {
            if (!res.ok) return;
            const d = res.data;
            document.getElementById('statTotal').textContent     = d.total?.toLocaleString() ?? '0';
            document.getElementById('statUsers').textContent     = d.users_active ?? '0';
            document.getElementById('statFailed').textContent    = d.failed?.toLocaleString() ?? '0';
            document.getElementById('statAnomalies').textContent = d.anomalies ?? '0';
        }).catch(() => {});
}

// ── Audit Logs ────────────────────────────────────────────────────────────
function loadAuditTrail() {
    const params = new URLSearchParams({
        action:'audit_trail', date_from:FROM, date_to:TO,
        user_id:UID, action_type:ACT, module:MOD, status_filter:STA
    });

    // Update export links
    const expBase = new URLSearchParams({action:'export_audit',date_from:FROM,date_to:TO,user_id:UID,action_type:ACT,module:MOD});
    document.getElementById('exportExcelBtn').href = `${API}?${expBase}&format=csv`;
    document.getElementById('exportPdfBtn').href   = `${API}?${expBase}&format=pdf`;

    fetch(`${API}?${params}`)
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('auditTbody');
            if (!res.ok || !res.data?.length) {
                tbody.innerHTML = `<tr><td colspan="9" class="aud-empty"><i class="fas fa-shield-slash"></i>No compliance records found for the selected filters.</td></tr>`;
                return;
            }
            tbody.innerHTML = res.data.map(r => {
                const src = (r.log_source || '').toLowerCase();
                const srcLabel = src === 'validation_trail' ? 'Validation' : 'System';
                const srcStyle = src === 'validation_trail'
                    ? 'background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;'
                    : 'background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;';
                return `<tr>
                <td style="white-space:nowrap">${fmt(r.created_at)}</td>
                <td><strong>${esc(r.user_name)}</strong><br><small style="color:var(--muted)">ID: ${parseInt(r.user_id)||'—'}</small></td>
                <td>${roleBadge(r.role)}</td>
                <td><strong>${esc(r.action_type)}</strong></td>
                <td><code style="font-size:11px">${esc(r.module)}</code></td>
                <td><span style="${srcStyle}">${srcLabel}</span></td>
                <td style="max-width:260px;word-break:break-word;font-size:12px">${esc(r.details)}</td>
                <td><code style="font-size:11px">${esc(r.ip_address) || '—'}</code></td>
                <td>${statusBadge(r.status)}</td>
            </tr>`;
            }).join('');
        })
        .catch(err => {
            document.getElementById('auditTbody').innerHTML = `<tr><td colspan="9" class="aud-empty">Error: ${err.message}</td></tr>`;
        });
}

// ── Anomaly Detection ─────────────────────────────────────────────────────
function loadAnomalies() {
    fetch(`${API}?action=anomaly_detection&date_from=${FROM}&date_to=${TO}`)
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const d = res.data;

            // Repeated failures
            const fEl = document.getElementById('anomalyFailures');
            if (!d.repeated_failures?.length) {
                fEl.innerHTML = `<div class="aud-empty" style="padding:20px"><i class="fas fa-check-circle" style="color:#16a34a"></i>No repeated failures detected.</div>`;
            } else {
                fEl.innerHTML = d.repeated_failures.map(r => `
                <div class="anomaly-card">
                  <div class="a-head">
                    <span class="a-count">✕ ${r.fail_count} failures</span>
                    <strong>${esc(r.user_name)}</strong>
                    ${roleBadge(r.role)}
                  </div>
                  <div class="a-detail">
                    <i class="fas fa-network-wired"></i> IP: <code>${esc(r.ip_address)}</code> &nbsp;|&nbsp;
                    <i class="fas fa-clock"></i> Last: ${fmt(r.last_attempt)}<br>
                    <i class="fas fa-bolt"></i> Actions: ${esc(r.actions)}
                  </div>
                </div>`).join('');
            }

            // Repeated rejections
            const rEl = document.getElementById('anomalyRejections');
            if (!d.repeated_rejections?.length) {
                rEl.innerHTML = `<div class="aud-empty" style="padding:20px"><i class="fas fa-check-circle" style="color:#16a34a"></i>No repeated rejections detected.</div>`;
            } else {
                rEl.innerHTML = d.repeated_rejections.map(r => `
                <div class="anomaly-card" style="border-left-color:#d97706;border-color:#fed7aa">
                  <div class="a-head">
                    <span class="a-count" style="background:#fef3c7;color:#d97706">↩ ${r.reject_count}x ${esc(r.action_type)}</span>
                    <strong>${esc(r.user_name)}</strong>
                    ${roleBadge(r.role)}
                  </div>
                  <div class="a-detail">
                    <i class="fas fa-clock"></i> Last seen: ${fmt(r.last_seen)}
                  </div>
                </div>`).join('');
            }
        })
        .catch(() => {});
}

// ── Init ──────────────────────────────────────────────────────────────────
loadSummary();
loadAuditTrail();
const ACTIVE_TAB = '<?php echo $active_tab; ?>';
if (ACTIVE_TAB === 'anomalies') loadAnomalies();

})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
