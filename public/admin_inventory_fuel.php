<?php
$page_id = 'admin_inventory_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['admin','superadmin'])) { header('Location: dashboard.php'); exit; }
if ($station_id <= 0 && $role === 'admin') { render_no_station_page('admin_dashboard.php'); }

// ── AJAX Handler for Movement History ──────────────────────────────
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_fuel_details') {
    header('Content-Type: application/json');
    $fuel_type = $_GET['fuel_type'] ?? '';
    
    $deliveries = [];
    try {
        $stmt = $pdo->prepare("SELECT delivery_date, delivery_liters, invoice_no, supplier, status FROM fuel_deliveries WHERE station_id = ? AND LOWER(fuel_type) = LOWER(?) ORDER BY delivery_date DESC, id DESC LIMIT 10");
        $stmt->execute([$station_id, $fuel_type]);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $transactions = [];
    try {
        $stmt = $pdo->prepare("SELECT transaction_date, liters_sold, total_amount, shift_period, status FROM fuel_transactions WHERE station_id = ? AND LOWER(fuel_type) = LOWER(?) ORDER BY transaction_date DESC, id DESC LIMIT 10");
        $stmt->execute([$station_id, $fuel_type]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'deliveries' => $deliveries,
        'transactions' => $transactions
    ]);
    exit;
}

// Handle edit liters POST (Discrepancy Correction)
$flash_ok = $_SESSION['ok'] ?? null; unset($_SESSION['ok']);
$flash_err = $_SESSION['err'] ?? null; unset($_SESSION['err']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_level') {
    $fid  = (int)($_POST['fuel_id'] ?? 0);
    $newL = (float)($_POST['new_level'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    if ($fid > 0 && $newL >= 0) {
        try {
            $stmt = $pdo->prepare("SELECT current_level, capacity FROM fuel_inventory WHERE id=? AND station_id=?");
            $stmt->execute([$fid, $station_id]);
            $fi = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fi) throw new Exception('Fuel record not found.');
            if ($newL > (float)$fi['capacity']) throw new Exception('New level exceeds tank capacity.');
            
            $oldL = (float)$fi['current_level'];
            $pdo->prepare("UPDATE fuel_inventory SET current_level=?, last_updated=NOW() WHERE id=? AND station_id=?")
                ->execute([$newL, $fid, $station_id]);
            
            if (function_exists('log_activity')) {
                log_activity($pdo, $me['id'], 'Admin Edit Fuel Level',
                    "Fuel ID $fid: {$oldL}L → {$newL}L. Note: $note");
            }
            $_SESSION['ok'] = 'Fuel level updated successfully.';
        } catch (Exception $e) { $_SESSION['err'] = $e->getMessage(); }
    }
    header('Location: admin_inventory_fuel.php'); exit;
}

// ── 17-Tanker Configuration ──────────────────────────────────────────
$TANK_CONFIG_17 = [
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 1',     'tank'=>'Underground Tank #1',  'tanker_num'=>1,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 2',     'tank'=>'Underground Tank #2',  'tanker_num'=>2,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 3',     'tank'=>'Underground Tank #3',  'tanker_num'=>3,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 4',     'tank'=>'Underground Tank #4',  'tanker_num'=>4,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 2 - 5',     'tank'=>'Underground Tank #5',  'tanker_num'=>5,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 2 - 6',     'tank'=>'Underground Tank #6',  'tanker_num'=>6,  'capacity'=>50000],
    ['fuel_type'=>'Kerosene',     'label'=>'KEROSENE - 1',     'tank'=>'Underground Tank #7',  'tanker_num'=>7,  'capacity'=>20000],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 1', 'tank'=>'Underground Tank #8',  'tanker_num'=>8,  'capacity'=>45000],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 2', 'tank'=>'Underground Tank #9',  'tanker_num'=>9,  'capacity'=>45000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 1',     'tank'=>'Underground Tank #10', 'tanker_num'=>10, 'capacity'=>20000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 2',     'tank'=>'Underground Tank #11', 'tanker_num'=>11, 'capacity'=>20000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 3',     'tank'=>'Underground Tank #12', 'tanker_num'=>12, 'capacity'=>20000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 4',     'tank'=>'Underground Tank #13', 'tanker_num'=>13, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 1',  'tank'=>'Underground Tank #14', 'tanker_num'=>14, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 2',  'tank'=>'Underground Tank #15', 'tanker_num'=>15, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 2 - 3',  'tank'=>'Underground Tank #16', 'tanker_num'=>16, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 2 - 4',  'tank'=>'Underground Tank #17', 'tanker_num'=>17, 'capacity'=>20000],
];

// ── DB lookups ──────────────────────────────────────────────────────
$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT id, fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated FROM fuel_inventory WHERE station_id=?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
} catch (Exception $e) {}

$del_lookup = [];
try {
    $s = $pdo->prepare("SELECT tank_assigned, SUM(delivery_liters) AS tot FROM fuel_deliveries WHERE station_id=? AND DATE(delivery_date)=CURDATE() AND status='Verified' GROUP BY tank_assigned");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['tot'];
} catch (Exception $e) {}

$sales_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS tot FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE() AND status='Verified' GROUP BY fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['tot'];
} catch (Exception $e) {}

$adj_lookup = [];
try {
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS tot FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id=fi.fuel_type_id AND fi.station_id=fa.station_id WHERE fa.station_id=? AND DATE(fa.adjustment_date)=CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['tot'];
} catch (Exception $e) {}

$price_lookup = [];
try {
    $s = $pdo->prepare("SELECT ft.name AS fuel_type, fp.price_per_liter FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id=ft.id WHERE fp.station_id=? AND fp.is_active=1 ORDER BY fp.effective_date DESC");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k = strtolower(trim($row['fuel_type']));
        if (!isset($price_lookup[$k])) $price_lookup[$k] = (float)$row['price_per_liter'];
    }
} catch (Exception $e) {}

// ── Build 17 rows ──────────────────────────────────────────────────
$rows = [];
foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key   = strtolower(trim($tc['fuel_type']));
    $tank_key = strtolower(trim($tc['tank']));
    $inv      = $fi_lookup[$ft_key] ?? null;
    $capacity = (float)$tc['capacity'];
    $cur_level= $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
    $same_n   = count(array_filter($TANK_CONFIG_17, fn($t) => strtolower($t['fuel_type']) === $ft_key));
    $purchases   = $del_lookup[$tank_key] ?? 0;
    $sales       = $same_n > 0 ? round(($sales_lookup[$ft_key] ?? 0) / $same_n, 2) : 0;
    $calibration = $same_n > 0 ? round(($adj_lookup[$ft_key]  ?? 0) / $same_n, 2) : 0;
    $beginning   = $same_n > 0 ? round($cur_level / $same_n, 2) : 0;
    $total_avail = $beginning + $purchases;
    $ending      = max(0, $total_avail - $sales - $calibration);
    $actual_dip  = $ending;
    $variance    = 0; // computed locally or pulled from reconciliation if needed
    $fill_pct    = $capacity > 0 ? ($ending / $capacity) * 100 : 0;
    if      ($ending <= 0)     { $status = 'Out of Stock'; $sc = '#dc3545'; }
    elseif  ($fill_pct <= 10)  { $status = 'Critical';     $sc = '#dc3545'; }
    elseif  ($fill_pct <= 25)  { $status = 'Low';          $sc = '#fd7e14'; }
    else                       { $status = 'Normal';       $sc = '#28a745'; }
    $price   = $price_lookup[$ft_key] ?? ($inv ? (float)($inv['price_per_liter'] ?? 0) : 0);
    $revenue = round($sales * $price, 2);
    $rows[]  = [
        'fuel_id'        => $inv['id'] ?? 0,
        'fuel_type'      => $tc['fuel_type'],
        'label'          => $tc['label'],
        'tank'           => $tc['tank'],
        'tanker_num'     => $tc['tanker_num'],
        'capacity'       => $capacity,
        'beginning'      => $beginning,
        'purchases'      => $purchases,
        'total_available'=> $total_avail,
        'sales'          => $sales,
        'calibration'    => $calibration,
        'ending_system'  => $ending,
        'actual_dip'     => $actual_dip,
        'variance'       => $variance,
        'current_level'  => $ending,
        'status'         => $status,
        'status_color'   => $sc,
        'fill_pct'       => $fill_pct,
        'price'          => $price,
        'revenue'        => $revenue,
        'timestamp'      => $inv['last_updated'] ?? null,
    ];
}

$total_tanks = count($rows);
$total_fuel_available = array_sum(array_column($rows, 'current_level'));
$total_low_fuel_tanks = count(array_filter($rows, fn($r) => $r['status'] === 'Low'));
$total_critical_fuel_tanks = count(array_filter($rows, fn($r) => in_array($r['status'], ['Critical','Out of Stock'])));
$total_fuel_variance = array_sum(array_column($rows, 'variance'));

$var_color = abs($total_fuel_variance) < 0.01 ? '#64748b' : ($total_fuel_variance >= 0 ? '#28a745' : '#dc3545');
$var_prefix = $total_fuel_variance > 0.01 ? '+' : '';
$var_display = $var_prefix . number_format($total_fuel_variance, 2) . ' L';

include __DIR__ . '/../partials/header.php';
?>
<style>
/* == PAGE HEADER - matches Transaction Module int-head standard == */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:0px !important; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }

:root {
    --blue: #002F6C;
    --red: #dc3545;
    --gray: #6c757d;
}
body, html { overflow-x:hidden !important; }
.flash-ok { background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:7px; padding:11px 15px; margin-bottom:14px; font-size:13px; }
.flash-err { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; border-radius:7px; padding:11px 15px; margin-bottom:14px; font-size:13px; }

/* Table */
.aif-wrap { width:100%; overflow:hidden; }
.aif-tbl { width:100%; border-collapse:collapse; font-size:13px; }
.aif-tbl thead tr { background:#002F70; }
.aif-tbl thead th { padding:10px 8px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.3px; border:none; }
.aif-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.aif-tbl tbody tr:hover { background:#eff6ff; }
.aif-tbl tbody td { padding:10px 8px; color:#1e293b; vertical-align:middle; font-size:13px; }
.aif-tbl tbody td.bold { font-weight:700; color:#002F70; }
.status-pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; }
.var-zero { color:#6c757d; } 
.var-pos { color:#28a745; font-weight:700; } 
.var-neg { color:#dc3545; font-weight:700; }

/* Buttons */
.int-btn-outline {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 6px 12px; border-radius: 4px; font-size: 11px; font-weight: 600;
    cursor: pointer; border: 1px solid #002F6C; transition: all 0.2s;
    background: white !important; color: #002F6C !important; height: 30px;
    line-height: 1; white-space: nowrap; text-decoration: none; box-sizing: border-box;
}
.int-btn-outline:hover {
    background: #002F6C !important; color: white !important;
}

.btn-cancel {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 0 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1px solid #6b7280; background: white !important;
    color: #475569 !important; transition: all .15s; height: 36px;
}
.btn-cancel:hover { background: #6b7280 !important; color: #fff !important; }

.btn-save {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 0 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1px solid #16a34a; background: white !important;
    color: #16a34a !important; transition: all .15s; height: 36px;
}
.btn-save:hover { background: #16a34a !important; color: #fff !important; }

/* Modals */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .55);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.open {
    display: flex;
}
.modal-box {
    background: #fff;
    border-radius: 12px;
    width: 600px;
    max-width: 100%;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: 90vh;
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
}
.modal-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    color: #002F70;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}
.modal-footer {
    padding: 12px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    background: #f8fafc;
}

.po-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.po-table th {
    background: #f1f5f9;
    color: #475569;
    text-transform: uppercase;
    font-size: 10px;
    font-weight: 700;
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}
.po-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
}

.modal-tab-btn {
    border: none;
    background: none;
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.2s;
}
.modal-tab-btn.active {
    color: #002F70;
    border-bottom-color: #002F70;
}

.info-note { background:#e8f4fd; border-left:3px solid var(--blue); padding:9px 13px; border-radius:5px; font-size:12px; color:#1e4080; margin-bottom:13px; }
.form-group { margin-bottom:13px; }
.form-group label { display:block; font-size:12px; font-weight:700; color:var(--gray); text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px; }
.form-group input, .form-group textarea { width:100%; padding:8px 10px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; box-sizing:border-box; }
.form-group input:focus, .form-group textarea:focus { outline:none; border-color:var(--blue); }
</style>

<div class="int-head">
  <div>
    <h1><i class="fas fa-gas-pump"></i> Fuel Inventory Oversight</h1>
    <div class="sub">17-Tanker Overview &middot; Today: <?= date('F d, Y') ?></div>
  </div>
</div>

<?php if ($flash_ok): ?><div class="flash-ok"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash-err"><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

<!-- Summary Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px;">
    <!-- Total Tanks -->
    <div style="background:#fff; border-left:5px solid #002F6C; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-left-width:5px;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Tanks</div>
            <div style="font-size:24px; font-weight:800; color:#002F6C; margin-top:4px;"><?= number_format($total_tanks) ?></div>
        </div>
        <div style="background:#e8f4fd; color:#002F6C; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-database"></i></div>
    </div>
    <!-- Total Fuel Available -->
    <div style="background:#fff; border-left:5px solid #0284c7; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-left-width:5px;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Fuel Available</div>
            <div style="font-size:24px; font-weight:800; color:#0284c7; margin-top:4px;"><?= number_format($total_fuel_available, 2) ?> L</div>
        </div>
        <div style="background:#e0f2fe; color:#0284c7; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-gas-pump"></i></div>
    </div>
    <!-- Low Fuel Tanks -->
    <div style="background:#fff; border-left:5px solid #fd7e14; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-left-width:5px;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Low Fuel Tanks</div>
            <div style="font-size:24px; font-weight:800; color:#fd7e14; margin-top:4px;"><?= number_format($total_low_fuel_tanks) ?></div>
        </div>
        <div style="background:#fff3cd; color:#fd7e14; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <!-- Critical Fuel Tanks -->
    <div style="background:#fff; border-left:5px solid #dc3545; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-left-width:5px;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Critical Fuel Tanks</div>
            <div style="font-size:24px; font-weight:800; color:#dc3545; margin-top:4px;"><?= number_format($total_critical_fuel_tanks) ?></div>
        </div>
        <div style="background:#fce8e6; color:#dc3545; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-times-circle"></i></div>
    </div>
    <!-- Total Fuel Variance -->
    <div style="background:#fff; border-left:5px solid <?= $var_color ?>; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-left-width:5px;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Fuel Variance</div>
            <div style="font-size:24px; font-weight:800; color:<?= $var_color ?>; margin-top:4px;"><?= $var_display ?></div>
        </div>
        <div style="background:#f1f5f9; color:<?= $var_color ?>; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-balance-scale"></i></div>
    </div>
</div>

<!-- Unified Top Controls & Filter Bar -->
<div class="inv-filter-bar" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; background:#fff; padding:12px 16px; border:1px solid #e2e8f0; border-radius:8px;">
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <!-- Search Tank -->
    <div style="position:relative;">
      <i class="fas fa-search" style="position:absolute; left:10px; top:11px; color:#94a3b8; font-size:12px;"></i>
      <input type="text" id="sq" placeholder="Search Tank..." oninput="filterFuelTable()" style="padding:7px 10px 7px 28px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:180px; outline:none;">
    </div>
    <!-- Filter Fuel Type -->
    <select id="cf" onchange="filterFuelTable()" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; outline:none; background:#fff;">
      <option value="">All Fuel Types</option>
      <option value="diesel">Diesel</option>
      <option value="kerosene">Kerosene</option>
      <option value="turbo diesel">Turbo Diesel</option>
      <option value="xcs plus">XCS Plus</option>
      <option value="xtra unl">XTRA UNL</option>
    </select>
    <!-- Filter Status -->
    <select id="sf" onchange="filterFuelTable()" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; outline:none; background:#fff;">
      <option value="">All Statuses</option>
      <option value="normal">🟢 Normal</option>
      <option value="low">🟡 Low</option>
      <option value="critical">🔴 Critical</option>
      <option value="out of stock">🔴 Out of Stock</option>
    </select>
  </div>
  
  <div style="display:flex; align-items:center; gap:8px;">
    <?php
    $export_table_id       = 'adminFuelInvTable';
    $export_filename       = 'admin_fuel_inventory_' . date('Ymd');
    $export_title          = 'Fuel Inventory Oversight';
    $export_rows_select_id = 'adminFuelRowsLimit';
    $export_default_rows   = 20;
    $export_back_url       = 'admin_dashboard.php';
    require __DIR__ . '/../partials/export_buttons.php';
    ?>
  </div>
</div>

<!-- Table Wrap -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px;">
  <div class="aif-wrap">
    <table class="aif-tbl" id="adminFuelInvTable">
      <thead>
        <tr>
          <th style="width:70px; text-align:center;">Tank No.</th>
          <th>Fuel Type</th>
          <th>Tank Reference</th>
          <th style="text-align:right;">Capacity (L)</th>
          <th style="text-align:right;">Current Level (L)</th>
          <th style="text-align:center;">Available %</th>
          <th style="text-align:center;">Status</th>
          <th style="text-align:right;">Variance (L)</th>
          <th>Last Updated</th>
          <th style="text-align:center; width:180px;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10" style="text-align:center; padding:32px; color:#6c757d;">No fuel inventory data available.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r):
            $var     = $r['variance'];
            $var_cls = abs($var) < 0.01 ? 'var-zero' : ($var >= 0 ? 'var-pos' : 'var-neg');
            $var_str = abs($var) < 0.01 ? '0.00' : (($var > 0 ? '+' : '') . number_format($var, 2));
            $ts_str  = $r['timestamp'] ? date('M d, Y h:i A', strtotime($r['timestamp'])) : '—';
            $fill    = min(100, max(0, round($r['fill_pct'], 1)));
        ?>
        <tr class="fuel-row"
            data-tank-num="<?= htmlspecialchars(strtolower($r['tanker_num'])) ?>"
            data-fuel-type="<?= htmlspecialchars(strtolower($r['fuel_type'])) ?>"
            data-tank-ref="<?= htmlspecialchars(strtolower($r['label'])) ?>"
            data-status="<?= htmlspecialchars(strtolower($r['status'])) ?>">
          <td style="text-align:center;" class="bold"><?= $r['tanker_num'] ?></td>
          <td style="font-weight:700;"><?= htmlspecialchars($r['fuel_type']) ?></td>
          <td style="font-weight:600; color:#002F70;"><?= htmlspecialchars($r['label']) ?></td>
          <td style="text-align:right; font-weight:600; color:#475569;"><?= number_format($r['capacity'], 0) ?> L</td>
          <td style="text-align:right; font-weight:700; color:#002F70;"><?= number_format($r['current_level'], 2) ?> L</td>
          <td style="text-align:center; font-weight:600;"><?= $fill ?>%</td>
          <td style="text-align:center;"><span class="status-pill" style="background:<?= $r['status_color'] ?>18; color:<?= $r['status_color'] ?>; border:1px solid <?= $r['status_color'] ?>40;"><?= htmlspecialchars($r['status']) ?></span></td>
          <td style="text-align:right;"><span class="<?= $var_cls ?>"><?= $var_str ?> L</span></td>
          <td style="color:#64748b; font-size:11px;"><?= $ts_str ?></td>
          <td style="text-align:center;">
            <button class="int-btn-outline" onclick="viewTankDetails(<?= htmlspecialchars(json_encode($r)) ?>)" title="View Details" style="padding:6px 16px;">
              <i class="fas fa-eye"></i> View
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div id="adminFuelInvPagination" style="padding:8px 16px;"></div>
</div>

<!-- ══ Tank Details Modal ══ -->
<div class="modal-overlay" id="tankModal">
    <div class="modal-box" style="width:500px;">
        <div class="modal-header">
            <h3 id="tankModalTitle">Tank Details</h3>
            <button onclick="closeTankModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div class="modal-body">
            <table style="width:100%; font-size:13px; border-collapse:collapse;">
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600; width:180px;">Tank No:</td><td id="detTankId" style="font-weight:700; color:#0f172a;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Tank Reference:</td><td id="detTankName" style="font-weight:700; color:#0f172a;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Tank Source:</td><td id="detTankDesc" style="color:#334155;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Fuel Type:</td><td id="detFuelType" style="font-weight:700; color:#0f172a;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Tank Capacity:</td><td id="detCapacity" style="font-weight:600; color:#475569;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Current Volume:</td><td id="detVolume" style="font-weight:700; color:#002F70; font-size:14px;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Remaining Capacity:</td><td id="detRemaining" style="font-weight:600; color:#0f172a;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Status:</td><td id="detStatus" style="padding:10px 0;"></td></tr>
                <tr><td style="padding:10px 0; color:#64748b; font-weight:600;">Last Updated:</td><td id="detUpdated" style="color:#64748b;"></td></tr>
            </table>
        </div>
        <div class="modal-footer">
            <button onclick="openEditFromDetails()" class="btn-save" style="height:32px; font-size:12px; padding:0 12px;">
                <i class="fas fa-edit"></i> Correct Level
            </button>
            <button onclick="closeTankModal()" class="btn-cancel" style="height:32px; font-size:12px; padding:0 12px;">Close</button>
        </div>
    </div>
</div>

<!-- ══ Fuel Movement Modal ══ -->
<div class="modal-overlay" id="movementModal">
    <div class="modal-box" style="width:750px;">
        <div class="modal-header">
            <h3 id="movementModalTitle">Fuel Movement History</h3>
            <button onclick="closeMovementModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div class="modal-body" style="padding:0;">
            <div style="display:flex; border-bottom:2px solid #e2e8f0; background:#f8fafc; padding:0 10px;">
                <button class="modal-tab-btn active" id="tabDelBtn" onclick="switchMovTab('deliveries')" style="padding:12px 16px; border:none; background:none; font-weight:700; font-size:12px; text-transform:uppercase; color:#002F70; border-bottom:2px solid #002F70; cursor:pointer; display:flex; align-items:center; gap:6px;"><i class="fas fa-truck"></i> Deliveries</button>
                <button class="modal-tab-btn" id="tabSalesBtn" onclick="switchMovTab('sales')" style="padding:12px 16px; border:none; background:none; font-weight:700; font-size:12px; text-transform:uppercase; color:#64748b; border-bottom:2px solid transparent; cursor:pointer; display:flex; align-items:center; gap:6px;"><i class="fas fa-receipt"></i> Sales Transactions</button>
            </div>
            <div style="padding:20px;">
                <!-- Tab: Deliveries -->
                <div id="tabContentDeliveries" style="max-height:300px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px;">
                    <table class="po-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Invoice No.</th>
                                <th>Supplier</th>
                                <th style="text-align:right;">Liters</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="movDeliveriesBody"></tbody>
                    </table>
                </div>
                <!-- Tab: Sales -->
                <div id="tabContentSales" style="display:none; max-height:300px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px;">
                    <table class="po-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Shift Period</th>
                                <th style="text-align:right;">Liters Sold</th>
                                <th style="text-align:right;">Total Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="movSalesBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="closeMovementModal()" class="btn-cancel" style="height:32px; font-size:12px; padding:0 12px;">Close</button>
        </div>
    </div>
</div>

<!-- Edit Modal (Discrepancy Correction) -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box" style="width:460px;">
    <div class="modal-header">
      <h3>Correct Fuel Level Discrepancy</h3>
      <button onclick="document.getElementById('editModal').classList.remove('open')" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">&times;</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <div class="info-note">Use this only to correct discrepancies. All changes are logged for audit.</div>
        <input type="hidden" name="action" value="edit_level">
        <input type="hidden" name="fuel_id" id="editFuelId">
        <div id="editFuelInfo" style="background:#f8f9fa; border-radius:6px; padding:10px 12px; margin-bottom:12px; font-size:13px;"></div>
        <div class="form-group">
          <label>New Current Level (Liters)</label>
          <input type="number" name="new_level" id="editNewLevel" min="0" step="0.01" required>
          <div id="editCapNote" style="font-size:11px; color:var(--gray); margin-top:3px;"></div>
        </div>
        <div class="form-group">
          <label>Reason / Note <span style="color:var(--red);">*</span></label>
          <textarea name="note" rows="2" placeholder="e.g. Corrected due to encoding error..." required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" onclick="document.getElementById('editModal').classList.remove('open')" class="btn-cancel">Cancel</button>
        <button type="submit" class="btn-save">Save Correction</button>
      </div>
    </form>
  </div>
</div>

<script>
function esc(str) {
    if (!str) return '';
    return str.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function filterFuelTable() {
    var search = document.getElementById('sq').value.toLowerCase().trim();
    var fuelType = document.getElementById('cf').value.toLowerCase();
    var status = document.getElementById('sf').value.toLowerCase();
    
    var rows = document.querySelectorAll('#adminFuelInvTable tbody tr');
    rows.forEach(function(row) {
        if (row.querySelector('td[colspan]')) return;
        
        var match = true;
        var rTankNum = (row.dataset.tankNum || '').toLowerCase();
        var rFuelType = (row.dataset.fuelType || '').toLowerCase();
        var rTankRef = (row.dataset.tankRef || '').toLowerCase();
        var rStatus = (row.dataset.status || '').toLowerCase();
        
        if (search) {
            if (rTankNum.indexOf(search) === -1 && 
                rFuelType.indexOf(search) === -1 && 
                rTankRef.indexOf(search) === -1) {
                match = false;
            }
        }
        
        if (fuelType && rFuelType !== fuelType) {
            match = false;
        }
        
        if (status && rStatus !== status) {
            match = false;
        }
        
        row.style.display = match ? '' : 'none';
    });
}

// ── Tank Details Modal Functions ──
var _selectedTank = null;
function viewTankDetails(r) {
    _selectedTank = r;
    document.getElementById('detTankId').textContent = r.tanker_num;
    document.getElementById('detTankName').textContent = r.label;
    document.getElementById('detTankDesc').textContent = r.tank;
    document.getElementById('detFuelType').textContent = r.fuel_type;
    document.getElementById('detCapacity').textContent = Number(r.capacity).toLocaleString() + ' L';
    document.getElementById('detVolume').textContent = Number(r.current_level).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
    document.getElementById('detRemaining').textContent = Number(r.capacity - r.current_level).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
    
    var fill = Math.min(100, Math.max(0, Math.round(r.fill_pct, 1)));
    var statusSpan = '<span class="status-pill" style="background:' + r.status_color + '18; color:' + r.status_color + '; border:1px solid ' + r.status_color + '40;">' + r.status + ' (' + fill + '%)</span>';
    document.getElementById('detStatus').innerHTML = statusSpan;
    
    var ts = r.timestamp ? new Date(r.timestamp).toLocaleString() : '—';
    document.getElementById('detUpdated').textContent = ts;
    
    document.getElementById('tankModal').classList.add('open');
}

function closeTankModal() {
    document.getElementById('tankModal').classList.remove('open');
}

function openEditFromDetails() {
    if (!_selectedTank) return;
    closeTankModal();
    openEdit(_selectedTank.fuel_id, _selectedTank.label, _selectedTank.current_level, _selectedTank.capacity);
}

function openEdit(id, name, current, cap) {
    document.getElementById('editFuelId').value = id;
    document.getElementById('editNewLevel').value = current;
    document.getElementById('editNewLevel').max = cap;
    document.getElementById('editFuelInfo').innerHTML = '<strong>' + name + '</strong> &nbsp;|&nbsp; Current: <strong>' + Number(current).toLocaleString() + ' L</strong> &nbsp;|&nbsp; Capacity: ' + Number(cap).toLocaleString() + ' L';
    document.getElementById('editCapNote').textContent = 'Max allowed: ' + Number(cap).toLocaleString() + ' L';
    document.getElementById('editModal').classList.add('open');
}

// ── Fuel Movement Modal Functions ──
var currentMovTab = 'deliveries';
function viewFuelMovement(fuelType, tankName) {
    document.getElementById('movementModalTitle').textContent = 'Movement History — ' + tankName + ' (' + fuelType + ')';
    
    var delBody = document.getElementById('movDeliveriesBody');
    var salesBody = document.getElementById('movSalesBody');
    
    delBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:24px; color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading deliveries...</td></tr>';
    salesBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:24px; color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading sales...</td></tr>';
    
    document.getElementById('movementModal').classList.add('open');
    switchMovTab('deliveries');

    fetch('admin_inventory_fuel.php?ajax=1&action=get_fuel_details&fuel_type=' + encodeURIComponent(fuelType))
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) {
            delBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#dc3545;">Failed to load data.</td></tr>';
            salesBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#dc3545;">Failed to load data.</td></tr>';
            return;
        }

        // Render Deliveries
        if (res.deliveries.length === 0) {
            delBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:12px; color:#94a3b8;">No recent deliveries recorded.</td></tr>';
        } else {
            var delHtml = '';
            res.deliveries.forEach(function(d) {
                var dateStr = d.delivery_date ? new Date(d.delivery_date).toLocaleDateString() : '—';
                var statusCls = d.status === 'Verified' ? 'background:#e6f4ea; color:#28a745; border:1px solid #c3e6cb;' : 'background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;';
                delHtml += '<tr>' +
                    '<td>' + dateStr + '</td>' +
                    '<td><code>' + esc(d.invoice_no || '—') + '</code></td>' +
                    '<td>' + esc(d.supplier || '—') + '</td>' +
                    '<td style="text-align:right; font-weight:700; color:#002F70;">' + Number(d.delivery_liters).toLocaleString() + ' L</td>' +
                    '<td><span style="font-size:10px; font-weight:700; padding:2px 6px; border-radius:4px;' + statusCls + '">' + esc(d.status) + '</span></td>' +
                    '</tr>';
            });
            delBody.innerHTML = delHtml;
        }

        // Render Sales
        if (res.transactions.length === 0) {
            salesBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:12px; color:#94a3b8;">No recent sales transactions.</td></tr>';
        } else {
            var salesHtml = '';
            res.transactions.forEach(function(t) {
                var dateStr = t.transaction_date ? new Date(t.transaction_date).toLocaleDateString() + ' ' + new Date(t.transaction_date).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '—';
                var statusCls = t.status === 'Verified' ? 'background:#e6f4ea; color:#28a745; border:1px solid #c3e6cb;' : 'background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;';
                salesHtml += '<tr>' +
                    '<td>' + dateStr + '</td>' +
                    '<td>' + esc(t.shift_period || '—') + '</td>' +
                    '<td style="text-align:right; font-weight:700; color:#002F70;">' + Number(t.liters_sold).toLocaleString() + ' L</td>' +
                    '<td style="text-align:right; font-weight:600;">₱' + Number(t.total_amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>' +
                    '<td><span style="font-size:10px; font-weight:700; padding:2px 6px; border-radius:4px;' + statusCls + '">' + esc(t.status) + '</span></td>' +
                    '</tr>';
            });
            salesBody.innerHTML = salesHtml;
        }
    })
    .catch(function() {
        delBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#dc3545;">Connection error.</td></tr>';
        salesBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#dc3545;">Connection error.</td></tr>';
    });
}

function closeMovementModal() {
    document.getElementById('movementModal').classList.remove('open');
}

function switchMovTab(tab) {
    currentMovTab = tab;
    var delBtn = document.getElementById('tabDelBtn');
    var salesBtn = document.getElementById('tabSalesBtn');
    var delContent = document.getElementById('tabContentDeliveries');
    var salesContent = document.getElementById('tabContentSales');

    if (tab === 'deliveries') {
        delBtn.classList.add('active');
        salesBtn.classList.remove('active');
        delContent.style.display = 'block';
        salesContent.style.display = 'none';
    } else {
        salesBtn.classList.add('active');
        delBtn.classList.remove('active');
        salesContent.style.display = 'block';
        delContent.style.display = 'none';
    }
}

// ── Print Tank Record Function ──
function printTankRecord(r) {
    var pw = window.open('', '_blank');
    pw.document.write('<!DOCTYPE html><html><head><title>Tank Report — ' + esc(r.label) + '</title>');
    pw.document.write('<style>');
    pw.document.write('body{font-family:Arial,sans-serif; font-size:13px; color:#222; margin:0; padding:24px;}');
    pw.document.write('.header{background:#002F6C; color:#fff; padding:16px 20px; border-radius:6px 6px 0 0;}');
    pw.document.write('.header h2{margin:0; font-size:16px; letter-spacing:.5px;}');
    pw.document.write('.header p{margin:4px 0 0; font-size:11px; opacity:.8;}');
    pw.document.write('.section{border:1px solid #e2e8f0; border-top:none; padding:16px 20px; margin-bottom:12px;}');
    pw.document.write('.section h4{margin:0 0 10px; color:#002F6C; font-size:11px; text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid #e2e8f0; padding-bottom:6px;}');
    pw.document.write('table.info{width:100%; border-collapse:collapse; font-size:12px;}');
    pw.document.write('table.info tr td:first-child{color:#64748b; font-weight:600; width:180px; padding:5px 0;}');
    pw.document.write('table.info tr td{padding:5px 0; border-bottom:1px solid #f1f5f9;}');
    pw.document.write('.badge{display:inline-block; padding:3px 10px; border-radius:4px; font-size:11px; font-weight:700;}');
    pw.document.write('.footer{text-align:center; font-size:10px; color:#94a3b8; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:10px;}');
    pw.document.write('</style></head><body>');
    
    pw.document.write('<div class="header"><h2>Fuel Tank Inventory Record</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
    pw.document.write('<div class="section"><h4>Tank Details</h4>');
    pw.document.write('<table class="info">');
    pw.document.write('<tr><td>Tank No:</td><td><strong>' + r.tanker_num + '</strong></td></tr>');
    pw.document.write('<tr><td>Tank Reference:</td><td><strong>' + esc(r.label) + '</strong></td></tr>');
    pw.document.write('<tr><td>Tank Source:</td><td>' + esc(r.tank) + '</td></tr>');
    pw.document.write('<tr><td>Fuel Type:</td><td><strong>' + esc(r.fuel_type) + '</strong></td></tr>');
    pw.document.write('</table></div>');
    
    pw.document.write('<div class="section"><h4>Inventory & Capacity Status</h4>');
    pw.document.write('<table class="info">');
    pw.document.write('<tr><td>Tank Capacity:</td><td>' + Number(r.capacity).toLocaleString() + ' L</td></tr>');
    pw.document.write('<tr><td>Current Level:</td><td><strong style="font-size:14px; color:#002F70;">' + Number(r.current_level).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</strong></td></tr>');
    pw.document.write('<tr><td>Remaining Capacity:</td><td>' + Number(r.capacity - r.current_level).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</td></tr>');
    pw.document.write('<tr><td>Available Percentage:</td><td>' + Math.min(100, Math.max(0, Math.round(r.fill_pct, 1))) + '%</td></tr>');
    pw.document.write('<tr><td>Status:</td><td><span class="badge" style="background:' + r.status_color + '20; color:' + r.status_color + '; border:1px solid ' + r.status_color + '40;">' + r.status + '</span></td></tr>');
    pw.document.write('<tr><td>Last Updated:</td><td>' + (r.timestamp ? new Date(r.timestamp).toLocaleString() : '—') + '</td></tr>');
    pw.document.write('</table></div>');
    
    pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    pw.document.write('</body></html>');
    pw.document.close();
    pw.print();
}

document.addEventListener('DOMContentLoaded', function() {
    if (typeof setupTablePagination === 'function')
        setupTablePagination('adminFuelInvTable','adminFuelRowsLimit','adminFuelInvPagination',20);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
