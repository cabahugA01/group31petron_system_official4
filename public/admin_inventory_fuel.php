<?php
$page_id = 'admin_inventory_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();
if (!in_array($role, ['admin','superadmin'])) { header('Location: dashboard.php'); exit; }
if ($station_id <= 0 && $role === 'admin') { render_no_station_page('admin_dashboard.php'); }

// Handle edit liters POST (discrepancy correction)
$flash_ok = $_SESSION['ok'] ?? null; unset($_SESSION['ok']);
$flash_err = $_SESSION['err'] ?? null; unset($_SESSION['err']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'edit_level') {
        $fid  = (int)($_POST['fuel_id'] ?? 0);
        $newL = (float)($_POST['new_level'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($fid > 0 && $newL >= 0) {
            try {
                $stmt = $pdo->prepare("SELECT current_level,capacity FROM fuel_inventory WHERE id=? AND station_id=?");
                $stmt->execute([$fid, $station_id]);
                $fi = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$fi) throw new Exception('Fuel record not found.');
                if ($newL > (float)$fi['capacity']) throw new Exception('New level exceeds tank capacity.');
                $pdo->prepare("UPDATE fuel_inventory SET current_level=?, last_updated=NOW() WHERE id=? AND station_id=?")
                    ->execute([$newL, $fid, $station_id]);
                if (function_exists('log_activity'))
                    log_activity($pdo, $me['id'], 'Admin Edit Fuel Level',
                        "Fuel ID $fid: {$fi['current_level']}L → {$newL}L. Note: $note");
                $_SESSION['ok'] = 'Fuel level updated successfully.';
            } catch (Exception $e) { $_SESSION['err'] = $e->getMessage(); }
        }
        header('Location: admin_inventory_fuel.php'); exit;
    }
}

// Fetch fuel inventory
$fuels = [];
try {
    $stmt = $pdo->prepare("
        SELECT f.*, u.name AS updated_by_name
        FROM fuel_inventory f
        LEFT JOIN users u ON f.updated_by = u.id
        WHERE f.station_id = ?
        ORDER BY f.fuel_type
    ");
    $stmt->execute([$station_id]);
    $fuels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fuels as &$f) {
        $lv = (float)($f['current_level'] ?? 0);
        $cp = (float)($f['capacity'] ?? 1);
        $cr = (float)($f['critical_level'] ?? 0);
        $pct = $cp > 0 ? min(100, round($lv / $cp * 100)) : 0;
        if ($lv <= 0)                  { $f['_st']='critical'; $f['_sc']='#dc2626'; }
        elseif ($lv <= $cr * 0.5)      { $f['_st']='critical'; $f['_sc']='#dc2626'; }
        elseif ($lv <= $cr)            { $f['_st']='low';      $f['_sc']='#f59e0b'; }
        else                           { $f['_st']='ok';       $f['_sc']='#16a34a'; }
        $f['_pct'] = $pct;
    }
    unset($f);
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F6C;--red:#dc3545;--gray:#6c757d;}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px;}
.kpi-card{background:#fff;border-radius:10px;padding:16px 18px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.kpi-card .kv{font-size:24px;font-weight:800;line-height:1;} .kpi-card .kl{font-size:12px;color:var(--gray);margin-top:4px;}
.flash-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:7px;padding:11px 15px;margin-bottom:14px;display:flex;align-items:center;gap:8px;font-size:13px;}
.flash-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:7px;padding:11px 15px;margin-bottom:14px;display:flex;align-items:center;gap:8px;font-size:13px;}
.fuel-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:20px;}
.fuel-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:20px;transition:box-shadow .15s;}
.fuel-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);}
.fc-name{font-size:17px;font-weight:800;color:var(--blue);margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.fc-meta{display:grid;gap:7px;margin-bottom:14px;}
.fc-row{display:flex;justify-content:space-between;font-size:13px;}
.fc-label{color:var(--gray);font-weight:600;}
.fc-val{color:#222;font-weight:500;}
.progress-bar-wrap{height:8px;background:#e5e7eb;border-radius:4px;margin-bottom:14px;}
.progress-bar{height:8px;border-radius:4px;transition:width .4s;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:4px;font-size:11px;font-weight:700;}
.badge-ok{background:#dcfce7;color:#166534;} .badge-low{background:#fef9c3;color:#92400e;} .badge-critical{background:#fee2e2;color:#991b1b;}
.btn-edit{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;background:var(--blue);color:#fff;border:none;border-radius:5px;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s;}
.btn-edit:hover{background:#001F4F;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:26px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-box h3{margin:0 0 14px;font-size:15px;color:var(--blue);display:flex;align-items:center;gap:8px;}
.form-group{margin-bottom:13px;}
.form-group label{display:block;font-size:12px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.form-group input,.form-group textarea{width:100%;padding:8px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;}
.form-group input:focus,.form-group textarea:focus{outline:none;border-color:var(--blue);}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:14px;}
.info-note{background:#e8f4fd;border-left:3px solid var(--blue);padding:9px 13px;border-radius:5px;font-size:12px;color:#1e4080;margin-bottom:13px;}
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-gas-pump"></i> Fuel Inventory Oversight</h1>
    <div class="sub">Monitor fuel tank levels, capacity, and fill percentage. Edit liters for discrepancy corrections.</div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;">
    <?php
    $export_table_id       = 'adminFuelInvTable';
    $export_filename       = 'admin_fuel_inventory_' . date('Ymd');
    $export_title          = 'Fuel Inventory Oversight';
    $export_rows_select_id = 'adminFuelRowsLimit';
    $export_default_rows   = 10;
    $export_back_url       = 'admin_dashboard.php';
    require __DIR__ . '/../partials/export_buttons.php';
    ?>
  </div>
</div>

<?php if ($flash_ok): ?><div class="flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

<?php require_once __DIR__ . '/../partials/admin_inventory_summary.php'; ?>

<?php
$tot  = count($fuels);
$ok   = count(array_filter($fuels, fn($f) => $f['_st']==='ok'));
$low  = count(array_filter($fuels, fn($f) => $f['_st']==='low'));
$crit = count(array_filter($fuels, fn($f) => $f['_st']==='critical'));
?>
<!-- Flat KPI Summary Table -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:8px;margin-bottom:20px;overflow:hidden;">
  <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:center;">
    <thead>
      <tr style="background:#f8f9fa;border-bottom:1px solid #dee2e6;">
        <th style="padding:10px;font-weight:700;color:#555;border-right:1px solid #dee2e6;">Total Fuel Types</th>
        <th style="padding:10px;font-weight:700;color:#555;border-right:1px solid #dee2e6;">Normal Level</th>
        <th style="padding:10px;font-weight:700;color:#555;border-right:1px solid #dee2e6;">Low Stock</th>
        <th style="padding:10px;font-weight:700;color:#555;">Critical / Empty</th>
      </tr>
    </thead>
    <tbody>
      <tr style="font-size:16px;font-weight:800;">
        <td style="padding:12px;color:#002F6C;border-right:1px solid #dee2e6;"><?= $tot ?></td>
        <td style="padding:12px;color:#16a34a;border-right:1px solid #dee2e6;"><?= $ok ?></td>
        <td style="padding:12px;color:#f59e0b;border-right:1px solid #dee2e6;"><?= $low ?></td>
        <td style="padding:12px;color:#dc2626;"><?= $crit ?></td>
      </tr>
    </tbody>
  </table>
</div>

<?php if (empty($fuels)): ?>
<div style="background:#fff;border-radius:10px;padding:40px;text-align:center;color:var(--gray);">
  <i class="fas fa-gas-pump" style="font-size:40px;opacity:.3;display:block;margin-bottom:12px;"></i>
  No fuel inventory records found for this station.
</div>
<?php else: ?>
<div class="table-wrap">
  <table class="table" id="adminFuelInvTable" style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr>
        <th>Fuel Type</th><th>Current Level</th><th>Capacity</th><th>Fill %</th>
        <th>Critical Level</th><th>Price / Liter</th><th>Status</th><th>Last Updated</th><th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($fuels as $f):
      $lv = (float)$f['current_level']; $cp = (float)$f['capacity']; $cr = (float)$f['critical_level'];
      $st = $f['_st']; $pct = $f['_pct'];
      $bc = $st==='critical'?'badge-critical':($st==='low'?'badge-low':'badge-ok');
      $bl = $st==='critical'?'Critical':($st==='low'?'Low Stock':'Normal');
    ?>
    <tr>
      <td><strong><?= htmlspecialchars($f['fuel_type']) ?></strong></td>
      <td><strong style="color:<?= $f['_sc'] ?>"><?= number_format($lv,2) ?> L</strong></td>
      <td><?= number_format($cp,2) ?> L</td>
      <td style="min-width:120px;">
        <div style="background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden;margin-bottom:3px;">
          <div style="width:<?= $pct ?>%;height:8px;border-radius:4px;background:<?= $f['_sc'] ?>;"></div>
        </div>
        <small style="color:#6c757d;"><?= $pct ?>%</small>
      </td>
      <td><?= number_format($cr,2) ?> L</td>
      <td>&#8369;<?= number_format((float)($f['price_per_liter']??0),2) ?></td>
      <td><span class="badge <?= $bc ?>"><?= $bl ?></span></td>
      <td style="font-size:12px;color:#6c757d;"><?= $f['last_updated'] ? date('M d, Y H:i', strtotime($f['last_updated'])) : '—' ?></td>
      <td>
        <button class="btn-edit" onclick="openEdit(<?= $f['id'] ?>, '<?= htmlspecialchars($f['fuel_type'],ENT_QUOTES) ?>', <?= $lv ?>, <?= $cp ?>)">
          <i class="fas fa-edit"></i> Edit Level
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div id="adminFuelInvPagination" style="margin-top:10px;"></div>
<?php endif; ?>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <h3><i class="fas fa-edit"></i> Edit Fuel Level</h3>
    <div class="info-note"><i class="fas fa-info-circle"></i> Use this only to correct discrepancies in Manager-encoded data. All changes are logged for audit.</div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_level">
      <input type="hidden" name="fuel_id" id="editFuelId">
      <div id="editFuelInfo" style="background:#f8f9fa;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:13px;"></div>
      <div class="form-group">
        <label>New Current Level (Liters)</label>
        <input type="number" name="new_level" id="editNewLevel" min="0" step="0.01" required>
        <div id="editCapNote" style="font-size:11px;color:var(--gray);margin-top:3px;"></div>
      </div>
      <div class="form-group">
        <label>Reason / Note <span style="color:var(--red);">*</span></label>
        <textarea name="note" rows="2" placeholder="e.g. Corrected due to encoding error by Manager..." required></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" onclick="document.getElementById('editModal').classList.remove('show')" style="padding:8px 18px;background:#6c757d;color:#fff;border:none;border-radius:5px;cursor:pointer;">Cancel</button>
        <button type="submit" class="btn-edit"><i class="fas fa-save"></i> Save Correction</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(id, name, current, cap) {
    document.getElementById('editFuelId').value = id;
    document.getElementById('editNewLevel').value = current;
    document.getElementById('editNewLevel').max = cap;
    document.getElementById('editFuelInfo').innerHTML = '<strong>' + name + '</strong> &nbsp;|&nbsp; Current: <strong>' + current.toLocaleString() + ' L</strong> &nbsp;|&nbsp; Capacity: ' + cap.toLocaleString() + ' L';
    document.getElementById('editCapNote').textContent = 'Max allowed: ' + cap.toLocaleString() + ' L';
    document.getElementById('editModal').classList.add('show');
}
document.getElementById('editModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('show'); });
document.addEventListener('DOMContentLoaded', function() {
    setupTablePagination('adminFuelInvTable','adminFuelRowsLimit','adminFuelInvPagination',10);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
