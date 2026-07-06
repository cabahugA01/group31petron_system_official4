<?php
// Admin Fuel Reconciliation Oversight
$page_id = 'admin_fuel_reconciliation_oversight';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();  $me  = current_user();
$role  = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();  if (!in_array($role, ['admin', 'superadmin'])) {  $_SESSION['error'] = 'Access denied.';  header('Location: admin_dashboard.php'); exit;
}  $msg_success = $_SESSION['success'] ?? '';
$msg_error  = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);  // ── Filters ────────────────────────────────────────────────
$date_from  = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$date_to  = trim($_GET['date_to']  ?? date('Y-m-d'));
$filter_status = trim($_GET['status']  ?? '');
$export  = trim($_GET['export']  ?? '');  $filter_station = ($role === 'superadmin') ? (int)($_GET['station'] ?? 0) : $station_id;  // ── Station Name ────────────────────────────────────────────
$station_name = 'All Stations';
if ($filter_station > 0) {  try {  $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");  $sn->execute([$filter_station]);  $station_name = $sn->fetchColumn() ?: 'Station';  } catch (Exception $e) {}
}  // ── Build WHERE ─────────────────────────────────────────────
$where  = ["DATE(fvr.report_date) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];
if ($filter_station > 0) { $where[] = "fvr.station_id = ?"; $params[] = $filter_station; }
if ($filter_status !== '') { $where[] = "LOWER(fvr.status) = ?"; $params[] = strtolower($filter_status); }
$where_sql = implode(' AND ', $where);  // ── Summary Counts ──────────────────────────────────────────
$cnt_total = $cnt_open = $cnt_invest = $cnt_resolved = 0;
$sum_variance = 0;
try {  $s = $pdo->prepare("SELECT  COUNT(*) as total,  SUM(CASE WHEN LOWER(fvr.status)='open' THEN 1 ELSE 0 END) as open_c,  SUM(CASE WHEN LOWER(fvr.status)='under investigation' THEN 1 ELSE 0 END) as invest_c,  SUM(CASE WHEN LOWER(fvr.status)='resolved' THEN 1 ELSE 0 END) as resolved_c,  SUM(ABS(fvr.variance_liters)) as total_var  FROM fuel_variance_reports fvr WHERE $where_sql");  $s->execute($params);  $row = $s->fetch(PDO::FETCH_ASSOC);  $cnt_total  = (int)($row['total']  ?? 0);  $cnt_open  = (int)($row['open_c']  ?? 0);  $cnt_invest  = (int)($row['invest_c']  ?? 0);  $cnt_resolved = (int)($row['resolved_c']?? 0);  $sum_variance = (float)($row['total_var']?? 0);
} catch (Exception $e) {}  // ── Fetch Variance Records ──────────────────────────────────
$records = [];
try {  $stmt = $pdo->prepare("SELECT fvr.*,  s.name as station_name,  rb.name as resolved_by_name  FROM fuel_variance_reports fvr  LEFT JOIN stations s  ON fvr.station_id = s.id  LEFT JOIN users rb  ON fvr.resolved_by = rb.id  WHERE $where_sql  ORDER BY FIELD(LOWER(fvr.status),'open','under investigation','resolved'), fvr.report_date DESC  LIMIT 500");  $stmt->execute($params);  $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}  // ── Stations list for superadmin ────────────────────────────
$stations = [];
if ($role === 'superadmin') {  try {  $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'Active' ORDER BY name");  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);  } catch (Exception $e) {}
}  // ── EXPORT ─────────────────────────────────────────────────
if ($export === 'excel') {  header('Content-Type: application/vnd.ms-excel; charset=utf-8');  header('Content-Disposition: attachment; filename="reconciliation_oversight_'.date('Ymd').'.xls"');  echo '<html><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff}</style></head><body>';  echo '<h2>Fuel Reconciliation Oversight</h2><p>Period: '.$date_from.' – '.$date_to.' | Station: '.$station_name.'</p>';  echo '<table><thead><tr><th>ID</th><th>Date</th><th>Station</th><th>Fuel Type</th><th>Expected (L)</th><th>Actual (L)</th><th>Variance (L)</th><th>Variance (%)</th><th>Status</th><th>Resolved By</th><th>Notes</th></tr></thead><tbody>';  foreach ($records as $r) {  echo '<tr><td>VAR-'.$r['id'].'</td><td>'.date('M d, Y',strtotime($r['report_date'])).'</td>';  echo '<td>'.htmlspecialchars($r['station_name']??'').'</td><td>'.htmlspecialchars($r['fuel_type']).'</td>';  echo '<td>'.number_format($r['expected_stock'],2).'</td><td>'.number_format($r['actual_stock'],2).'</td>';  echo '<td>'.number_format($r['variance_liters'],2).'</td><td>'.number_format($r['variance_percent'],2).'%</td>';  echo '<td>'.htmlspecialchars($r['status']).'</td><td>'.htmlspecialchars($r['resolved_by_name']??'—').'</td>';  echo '<td>'.htmlspecialchars(substr($r['resolution_notes']??'',0,80)).'</td></tr>';  }  echo '</tbody></table></body></html>'; exit;
}  require_once __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>
<style>
html,body{max-width:100vw;overflow-x:hidden}
/* == PAGE HEADER - matches SuperAdmin int-head standard == */
.int-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;margin-top:-12px!important}
.int-head h1{font-size:22px!important;font-weight:700!important;color:var(--petron-blue,#00264D)!important;margin:0!important;text-transform:uppercase!important;display:flex;align-items:center;gap:8px}
.int-head .sub{font-size:13px;color:#666;margin-top:4px;text-transform:none!important}
/* == Outline Buttons - SuperAdmin standard == */
.ato-btn{display:inline-flex;align-items:center;gap:6px;padding:0 16px;height:36px;border:1px solid transparent;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;background:white!important;transition:all .15s}
.ato-btn-excel{color:#1d6f42!important;border-color:#1d6f42!important}.ato-btn-excel:hover{background:#1d6f42!important;color:#fff!important}
.ato-btn-back{color:#4b5563!important;border-color:#6b7280!important}.ato-btn-back:hover{background:#6b7280!important;color:#fff!important}
.ato-btn-filter{color:#002F70!important;border-color:#002F70!important}.ato-btn-filter:hover{background:#002F70!important;color:#fff!important}
/* == KPI Cards == */
.afao-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px}
.afao-card{background:#fff;border:1px solid #e2e8f0;border-radius:11px;padding:16px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.afao-card-ico{width:40px;height:40px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;color:#002F6C}
.afao-card-meta h3{margin:0;font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
.afao-card-meta h2{margin:2px 0 0;font-size:24px;font-weight:900;color:#00264D;line-height:1}
.afao-card-meta span{font-size:11px;color:#94a3b8}
/* == Filter Bar == */
.afao-filter{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:16px}
.afao-fg{display:flex;flex-direction:column;gap:3px}
.afao-fg label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.afao-fg input,.afao-fg select{height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box}
.afao-fg input:focus,.afao-fg select:focus{border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1)}
/* == Table Card - matches SuperAdmin ato-table == */
.afao-table-card{background:#fff;border:1px solid #e2e8f0;border-radius:11px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.afao-table-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:8px}
.afao-table-title{font-size:13px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:.3px;margin:0}
.afao-tbl{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px}
.afao-tbl thead tr{background:#002F70}
.afao-tbl thead th{padding:9px 10px;text-align:left;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.4px;overflow:hidden;text-overflow:ellipsis;border-bottom:2px solid #001a3d;vertical-align:middle}
.afao-tbl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s}
.afao-tbl tbody tr:hover td{background:#eff6ff}
.afao-tbl tbody td{padding:9px 10px;color:#334155;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:#fff;font-size:11px}
.afao-empty{text-align:center;padding:60px 20px;color:#94a3b8}
.afao-empty i{font-size:44px;display:block;margin-bottom:14px;opacity:.4}
.var-high{color:#dc2626;font-weight:700}
.var-ok{color:#16a34a;font-weight:700}
</style>  <div class="int-head">  <div>  <h1><i class="fas fa-balance-scale"></i> Fuel Reconciliation Oversight</h1>  <div class="sub">Audit reconciliations of pump readings, deliveries, and stock balances to detect and resolve inconsistencies.</div>  </div>  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">  <a href="?<?= http_build_query(['date_from'=>$date_from,'date_to'=>$date_to,'station'=>$filter_station,'status'=>$filter_status,'export'=>'excel']) ?>" class="ato-btn ato-btn-excel"><i class="fas fa-file-excel"></i> Export Excel</a>  </div>
</div>  <?php if ($msg_success): ?>
<div style="padding:12px 16px;background:#dcfce7;border:1px solid #bbf7d0;color:#15803d;border-radius:8px;margin-bottom:16px;font-weight:600;"><i class="fas fa-check-circle" style="margin-right:6px;"></i><?= htmlspecialchars($msg_success) ?></div>
<?php endif; ?>
<?php if ($msg_error): ?>
<div style="padding:12px 16px;background:#fee2e2;border:1px solid #fecaca;color:#b91c1c;border-radius:8px;margin-bottom:16px;font-weight:600;"><i class="fas fa-exclamation-circle" style="margin-right:6px;"></i><?= htmlspecialchars($msg_error) ?></div>
<?php endif; ?>  <!-- Summary Cards -->
<div class="afao-cards">  <div class="afao-card c-blue">  <div class="afao-card-ico"><i class="fas fa-list-ul"></i></div>  <div class="afao-card-meta">  <h3>Total Records</h3>  <h2><?= number_format($cnt_total) ?></h2>  <span>All variance entries</span>  </div>  </div>  <div class="afao-card c-red">  <div class="afao-card-ico"><i class="fas fa-exclamation-circle"></i></div>  <div class="afao-card-meta">  <h3>Open</h3>  <h2><?= number_format($cnt_open) ?></h2>  <span>Needs attention</span>  </div>  </div>  <div class="afao-card c-amber">  <div class="afao-card-ico"><i class="fas fa-search"></i></div>  <div class="afao-card-meta">  <h3>Investigating</h3>  <h2><?= number_format($cnt_invest) ?></h2>  <span>Under review</span>  </div>  </div>  <div class="afao-card c-green">  <div class="afao-card-ico"><i class="fas fa-check-circle"></i></div>  <div class="afao-card-meta">  <h3>Resolved</h3>  <h2><?= number_format($cnt_resolved) ?></h2>  <span>Total variance: <?= number_format($sum_variance,2) ?> L</span>  </div>  </div>
</div>  <!-- Filter Bar -->
<form method="get" class="afao-filter">  <?php if ($role === 'superadmin' && !empty($stations)): ?>  <div class="afao-fg">  <label>Station</label>  <select name="station">  <option value="0" <?= $filter_station==0?'selected':'' ?>>All Stations</option>  <?php foreach ($stations as $s): ?>  <option value="<?= $s['id'] ?>" <?= $filter_station==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>  <?php endforeach; ?>  </select>  </div>  <?php endif; ?>  <div class="afao-fg">  <label>Date From</label>  <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">  </div>  <div class="afao-fg">  <label>Date To</label>  <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">  </div>  <div class="afao-fg">  <label>Status</label>  <select name="status">  <option value="" <?= $filter_status===''?'selected':'' ?>>All Status</option>  <option value="open" <?= $filter_status==='open'?'selected':'' ?>>Open</option>  <option value="under investigation" <?= $filter_status==='under investigation'?'selected':'' ?>>Investigating</option>  <option value="resolved" <?= $filter_status==='resolved'?'selected':'' ?>>Resolved</option>  </select>  </div>  <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-filter"></i> Apply</button>
</form>  <!-- Table -->
<div class="afao-table-card">  <div class="afao-table-hd">  <h3 class="afao-table-title"><i class="fas fa-balance-scale"></i> Fuel Variance / Reconciliation Records</h3>  <span style="font-size:11px;color:#64748b;"><?= number_format(count($records)) ?> record(s) — <?= htmlspecialchars($date_from) ?> to <?= htmlspecialchars($date_to) ?></span>  </div>  <?php if (empty($records)): ?>  <div class="afao-empty">  <i class="fas fa-check-circle" style="color:#10b981;"></i>  <div style="font-size:15px;font-weight:700;color:#64748b;margin-bottom:4px;">No Records Found</div>  <div style="font-size:13px;">No variance/reconciliation entries for the selected period and filters.</div>  </div>  <?php else: ?>  <div style="overflow-x:hidden;max-width:100%;">  <table class="afao-tbl" id="reconTable">  <colgroup>  <col style="width:7%"><col style="width:9%"><col style="width:11%"><col style="width:9%">  <col style="width:9%"><col style="width:9%"><col style="width:8%"><col style="width:8%">  <col style="width:10%"><col style="width:12%"><col style="width:8%">  </colgroup>  <thead>  <tr>  <th>ID</th>  <th>Date</th>  <th>Station</th>  <th>Fuel Type</th>  <th>Expected (L)</th>  <th>Actual (L)</th>  <th>Var. (L)</th>  <th>Var. (%)</th>  <th>Status</th>  <th>Resolved By</th>  <th>Notes</th>  </tr>  </thead>  <tbody>  <?php foreach ($records as $r):  $sl = strtolower($r['status'] ?? '');  $var_pct = abs($r['variance_percent'] ?? 0);  $lc = ($r['variance_liters'] ?? 0) < 0 ? 'color:#dc2626;' : 'color:#16a34a;';  if ($sl === 'open')  $st_color = 'color:#dc2626;font-weight:700;';  elseif ($sl === 'under investigation') $st_color = 'color:#d97706;font-weight:700;';  else  $st_color = 'color:#16a34a;font-weight:700;';  ?>  <tr>  <td style="font-weight:600;color:#475569;">VAR-<?= htmlspecialchars($r['id']) ?></td>  <td><?= date('M d, Y', strtotime($r['report_date'])) ?></td>  <td title="<?= htmlspecialchars($r['station_name']??'') ?>"><?= htmlspecialchars($r['station_name'] ?? '—') ?></td>  <td style="font-weight:600;"><?= htmlspecialchars($r['fuel_type']) ?></td>  <td><?= number_format($r['expected_stock'],2) ?> L</td>  <td><?= number_format($r['actual_stock'],2) ?> L</td>  <td style="font-weight:700;font-family:monospace;<?= $lc ?>">  <?= ($r['variance_liters'] > 0 ? '+' : '') . number_format($r['variance_liters'],2) ?> L  </td>  <td style="<?= $var_pct > 5 ? 'color:#dc2626;font-weight:700;' : 'color:#16a34a;font-weight:700;' ?>">  <?= number_format($r['variance_percent'],2) ?>%  </td>  <td style="<?= $st_color ?>"><?= htmlspecialchars(strtoupper($r['status'] ?? '—')) ?></td>  <td title="<?= htmlspecialchars($r['resolved_by_name']??'') ?>"><?= htmlspecialchars($r['resolved_by_name'] ?? '—') ?></td>  <td title="<?= htmlspecialchars($r['resolution_notes']??'') ?>">  <?= htmlspecialchars(substr($r['resolution_notes'] ?? '—', 0, 30)) ?><?= strlen($r['resolution_notes']??'') > 30 ? '…' : '' ?>  </td>  </tr>  <?php endforeach; ?>  </tbody>  </table>  </div>  <!-- Pagination -->  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid #f1f5f9;flex-wrap:wrap;gap:12px;">  <div style="display:flex;align-items:center;gap:8px;">  <label style="font-size:12px;color:#64748b;font-weight:600;">Rows per page:</label>  <select id="rowsPerPage" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;cursor:pointer;">  <option value="10">10</option><option value="20" selected>20</option>  <option value="30">30</option><option value="50">50</option>  </select>  </div>  <div style="display:flex;align-items:center;gap:10px;">  <span id="pageInfo" style="font-size:12px;color:#64748b;font-weight:600;">Page 1 of 1</span>  <div style="display:flex;gap:4px;">  <button id="prevPage" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;" disabled><i class="fas fa-chevron-left"></i> Prev</button>  <button id="nextPage" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;">Next <i class="fas fa-chevron-right"></i></button>  </div>  </div>  </div>  <?php endif; ?>
</div>  <script>
(function() {  const tbody = document.querySelector('#reconTable tbody');  if (!tbody) return;  const allRows = Array.from(tbody.querySelectorAll('tr'));  let page = 1, rpp = 20;  const rppSel = document.getElementById('rowsPerPage');  const info  = document.getElementById('pageInfo');  const prev  = document.getElementById('prevPage');  const next  = document.getElementById('nextPage');  function render() {  const total = Math.ceil(allRows.length / rpp) || 1;  allRows.forEach(r => r.style.display = 'none');  allRows.slice((page-1)*rpp, page*rpp).forEach(r => r.style.display = '');  info.textContent = `Page ${page} of ${total}`;  prev.disabled = page === 1;  next.disabled = page >= total;  prev.style.opacity = prev.disabled ? '0.5' : '1';  next.style.opacity = next.disabled ? '0.5' : '1';  }  rppSel.addEventListener('change', () => { rpp = parseInt(rppSel.value); page = 1; render(); });  prev.addEventListener('click', () => { if(page>1){page--;render();} });  next.addEventListener('click', () => { if(page < Math.ceil(allRows.length/rpp)){page++;render();} });  render();
})();
</script>  <?php require_once __DIR__ . '/../partials/footer.php'; ?>
