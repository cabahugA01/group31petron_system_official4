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
$can_correct_fuel = in_array($role, ['superadmin'], true);

// ── AJAX Handler for Unified Recent Activity ──────────────────────────────
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_fuel_details') {
    header('Content-Type: application/json');
    $fuel_type = $_GET['fuel_type'] ?? '';
    
    $activity = [];
    
    // 1. Deliveries
    try {
        $stmt = $pdo->prepare("
            SELECT fd.delivery_date AS date, 
                   fd.invoice_no AS reference, 
                   'Delivery' AS transaction_type, 
                   fd.delivery_liters AS liters, 
                   COALESCE(u.username, 'Staff') AS user
            FROM fuel_deliveries fd
            LEFT JOIN users u ON fd.received_by = u.id
            WHERE fd.station_id = ? AND LOWER(fd.fuel_type) = LOWER(?)
            ORDER BY fd.delivery_date DESC, fd.id DESC LIMIT 10
        ");
        $stmt->execute([$station_id, $fuel_type]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $activity[] = [
                'date' => $r['date'] ? date('M d, Y', strtotime($r['date'])) : '—',
                'raw_date' => $r['date'],
                'reference' => $r['reference'] ?: '—',
                'transaction' => $r['transaction_type'],
                'liters' => '+' . number_format((float)$r['liters'], 2) . ' L',
                'user' => $r['user']
            ];
        }
    } catch (Exception $e) {}

    // 2. Sales
    try {
        $stmt = $pdo->prepare("
            SELECT ft.transaction_date AS date, 
                   ft.shift_period AS reference, 
                   'Sales' AS transaction_type, 
                   ft.liters_sold AS liters, 
                   'Shift Staff' AS user
            FROM fuel_transactions ft
            WHERE ft.station_id = ? AND LOWER(ft.fuel_type) = LOWER(?)
            ORDER BY ft.transaction_date DESC, ft.id DESC LIMIT 10
        ");
        $stmt->execute([$station_id, $fuel_type]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $activity[] = [
                'date' => $r['date'] ? date('M d, Y h:i A', strtotime($r['date'])) : '—',
                'raw_date' => $r['date'],
                'reference' => $r['reference'] ?: '—',
                'transaction' => $r['transaction_type'],
                'liters' => '-' . number_format((float)$r['liters'], 2) . ' L',
                'user' => $r['user']
            ];
        }
    } catch (Exception $e) {}

    // 3. Adjustments
    try {
        $stmt = $pdo->prepare("
            SELECT fa.adjustment_date AS date, 
                   fa.reference_no AS reference, 
                   'Adjustment' AS transaction_type, 
                   fa.liters AS liters, 
                   COALESCE(u.username, 'Manager') AS user
            FROM fuel_adjustments fa
            JOIN fuel_inventory fi ON fa.fuel_type_id = fi.fuel_type_id AND fi.station_id = fa.station_id
            LEFT JOIN users u ON fa.user_id = u.id
            WHERE fa.station_id = ? AND LOWER(fi.fuel_type) = LOWER(?)
            ORDER BY fa.adjustment_date DESC, fa.id DESC LIMIT 10
        ");
        $stmt->execute([$station_id, $fuel_type]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $activity[] = [
                'date' => $r['date'] ? date('M d, Y h:i A', strtotime($r['date'])) : '—',
                'raw_date' => $r['date'],
                'reference' => $r['reference'] ?: '—',
                'transaction' => $r['transaction_type'],
                'liters' => ((float)$r['liters'] >= 0 ? '+' : '') . number_format((float)$r['liters'], 2) . ' L',
                'user' => $r['user']
            ];
        }
    } catch (Exception $e) {}

    // Sort by raw_date descending
    usort($activity, function($a, $b) {
        return strtotime($b['raw_date'] ?? '') - strtotime($a['raw_date'] ?? '');
    });
    $activity = array_slice($activity, 0, 10);

    echo json_encode([
        'success' => true,
        'activity' => $activity
    ]);
    exit;
}

// Handle edit liters POST (Discrepancy Correction)
$flash_ok = $_SESSION['ok'] ?? null; unset($_SESSION['ok']);
$flash_err = $_SESSION['err'] ?? null; unset($_SESSION['err']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_level') {
    if (!$can_correct_fuel) {
        $_SESSION['err'] = 'Admin inventory monitoring is read-only.';
        header('Location: admin_inventory_fuel.php');
        exit;
    }
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

$TANK_CONFIG_17 = get_tank_config();

// ── DB lookups ──────────────────────────────────────────────────────
$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT id, fuel_type, tank_number, tank_name, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated FROM fuel_inventory WHERE station_id=?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fuel_key = strtolower(trim($row['fuel_type']));
        $tank_num = $row['tank_number'] ?? '';
        $tank_name = strtolower(trim($row['tank_name'] ?? ''));
        
        if (!isset($fi_lookup[$fuel_key])) {
            $fi_lookup[$fuel_key] = $row;
        }
        if ($tank_num) {
            $fi_lookup[$fuel_key . '_tank_' . $tank_num] = $row;
        }
        if ($tank_name) {
            $fi_lookup[$fuel_key . '_' . $tank_name] = $row;
        }
    }
} catch (Exception $e) {
    try {
        $s = $pdo->prepare("SELECT id, fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated FROM fuel_inventory WHERE station_id=?");
        $s->execute([$station_id]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
            $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
    } catch (Exception $e2) {}
}

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

// Fetch cost and selling price from inventory_products
$fuel_products_lookup = [];
try {
    $stmt = $pdo->prepare("SELECT product_name, unit_cost, unit_price FROM inventory_products WHERE category = 'Fuel' AND station_id = ?");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $canonical = strtolower(trim($row['product_name']));
        $fuel_products_lookup[$canonical] = $row;
    }
} catch (Exception $e) {}

// Helper function to get canonical fuel name
if (!function_exists('get_canonical_fuel_name')) {
    function get_canonical_fuel_name($name) {
        $name_lower = strtolower(trim($name));
        if (strpos($name_lower, 'turbo') !== false) {
            return 'Turbo Diesel';
        } elseif (strpos($name_lower, 'diesel') !== false) {
            return 'Diesel';
        } elseif (strpos($name_lower, 'kerosene') !== false) {
            return 'Kerosene';
        } elseif (strpos($name_lower, 'xcs') !== false) {
            return 'XCS';
        } elseif (strpos($name_lower, 'xtra') !== false || strpos($name_lower, 'unl') !== false || strpos($name_lower, 'advance') !== false) {
            return 'Xtra Advance';
        }
        return $name;
    }
}

// ── Build 7 rows ──────────────────────────────────────────────────
$rows = [];
foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key = strtolower(trim($tc['fuel_type']));
    $tank_num = $tc['tanker_num'];
    
    $inv = null;
    if (isset($fi_lookup[$ft_key . '_tank_' . $tank_num])) {
        $inv = $fi_lookup[$ft_key . '_tank_' . $tank_num];
    } elseif (isset($fi_lookup[$ft_key . '_' . strtolower(trim($tc['tank']))])) {
        $inv = $fi_lookup[$ft_key . '_' . strtolower(trim($tc['tank']))];
    } elseif ($ft_key === 'xtra unl' || $ft_key === 'xtr advance') {
        $cand = '';
        if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'xtra unl 1'; }
        elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'xtra unl 2'; }
        if ($cand && isset($fi_lookup[$cand])) { 
            $ft_key = $cand;
            $inv = $fi_lookup[$cand];
        } else {
            $inv = $fi_lookup['xtra unl'] ?? null;
        }
    } elseif ($ft_key === 'diesel') {
        $cand = '';
        if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'diesel 1'; }
        elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'diesel 2'; }
        if ($cand && isset($fi_lookup[$cand])) { 
            $ft_key = $cand;
            $inv = $fi_lookup[$cand];
        } else {
            $inv = $fi_lookup['diesel'] ?? null;
        }
    } else {
        $inv = $fi_lookup[$ft_key] ?? null;
    }
    
    $tank_key = strtolower(trim($tc['tank']));
    $capacity = (float)$tc['capacity'];
    
    $cur_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
    
    $same_n = count(array_filter($TANK_CONFIG_17, function($t) use ($ft_key, $fi_lookup) {
        $k = strtolower(trim($t['fuel_type']));
        if ($k === 'xtra unl' || $k === 'xtr advance') {
            $cand = '';
            if (strpos(strtolower($t['label']), '1') !== false) { $cand = 'xtra unl 1'; }
            elseif (strpos(strtolower($t['label']), '2') !== false) { $cand = 'xtra unl 2'; }
            if ($cand && isset($fi_lookup[$cand])) { $k = $cand; }
            else { $k = 'xtra unl'; }
        } elseif ($k === 'diesel') {
            $cand = '';
            if (strpos(strtolower($t['label']), '1') !== false) { $cand = 'diesel 1'; }
            elseif (strpos(strtolower($t['label']), '2') !== false) { $cand = 'diesel 2'; }
            if ($cand && isset($fi_lookup[$cand])) { $k = $cand; }
            else { $k = 'diesel'; }
        }
        return $k === $ft_key;
    }));
    
    $purchases   = $del_lookup[$tank_key] ?? 0;
    
    $beginning   = $cur_level;
    $sales       = $same_n > 0 ? round(($sales_lookup[$ft_key] ?? 0) / $same_n, 2) : 0;
    $calibration = $same_n > 0 ? round(($adj_lookup[$ft_key]  ?? 0) / $same_n, 2) : 0;
    $total_avail = $beginning + $purchases;
    
    $ending      = max(0, $total_avail - $sales - $calibration);
    $actual_dip  = $ending;
    $variance    = 0;
    
    // Capacity-based thresholds
    if ($capacity == 14000) {
        $critical_lvl = 2500;
        $low_lvl = 5000;
    } elseif ($capacity == 7000) {
        $critical_lvl = 1000;
        $low_lvl = 2000;
    } else {
        $critical_lvl = $capacity * 0.10;
        $low_lvl = $capacity * 0.20;
    }
    $fill_pct = $capacity > 0 ? round(($ending / $capacity) * 100, 2) : 0;
    if ($ending <= 0) {
        $status = 'Out of Stock'; $sc = '#dc3545';
    } elseif ($ending <= $critical_lvl) {
        $status = 'Critical';     $sc = '#dc3545';
    } elseif ($ending <= $low_lvl) {
        $status = 'Low';          $sc = '#fd7e14';
    } else {
        $status = 'Normal';       $sc = '#28a745';
    }
    
    $canon_type = strtolower(get_canonical_fuel_name($tc['fuel_type']));
    $cost = isset($fuel_products_lookup[$canon_type]) ? (float)$fuel_products_lookup[$canon_type]['unit_cost'] : 0.00;
    
    // If not found in products lookup, fallback to selling price * 0.90
    $selling_price = isset($fuel_products_lookup[$canon_type]) && (float)$fuel_products_lookup[$canon_type]['unit_price'] > 0
        ? (float)$fuel_products_lookup[$canon_type]['unit_price']
        : ($price_lookup[strtolower(trim($tc['fuel_type']))] ?? ($inv ? (float)($inv['price_per_liter'] ?? 0) : 0));
        
    if ($cost == 0) {
        $cost = round($selling_price * 0.90, 2);
    }
    
    $revenue = round($ending * $selling_price, 2);
    
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
        'cost'           => $cost,
        'price'          => $selling_price,
        'value'          => $revenue,
        'reorder_level'  => $low_lvl,
        'critical_level' => $critical_lvl,
        'timestamp'      => $inv['last_updated'] ?? null,
    ];
}

$total_tanks = count($rows);
$total_fuel_available = array_sum(array_column($rows, 'current_level'));
$total_tank_capacity = array_sum(array_column($rows, 'capacity'));
$total_low_fuel_tanks = count(array_filter($rows, fn($r) => $r['status'] === 'Low'));
$total_critical_fuel_tanks = count(array_filter($rows, fn($r) => in_array($r['status'], ['Critical','Out of Stock'])));
$total_fuel_value = array_sum(array_column($rows, 'value'));

include __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>
<style>
/* == PAGE HEADER == */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:0 !important; padding-top:16px; padding-bottom:16px; border-bottom:2px solid #e9ecef; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }

/* ── Tab Navigation ── */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:22px; }
.tab-btn { padding:10px 24px; background:none; border:none; border-bottom:3px solid transparent; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70 !important; background:#f8fafc !important; }

:root {
    --blue: #002F6C;
    --red: #dc3545;
    --gray: #6c757d;
}
body, html { overflow-x:hidden !important; }

/* Table */
.aif-wrap { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
.aif-tbl { width:100%; border-collapse:collapse; font-size:12px; }
.aif-tbl thead tr { background:#002F70; }
.aif-tbl thead th { padding:10px 8px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.3px; border:none; }
.aif-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.aif-tbl tbody tr:hover { background:#f8fafc; }
.aif-tbl tbody td { padding:10px 8px; color:#1e293b; vertical-align:middle; font-size:12px; }
.aif-tbl tbody td.bold { font-weight:700; color:#002F70; }
.status-pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; }

/* Buttons */
.flt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .15s;
    background: white !important;
    border: 1px solid transparent;
}
.flt-btn-excel { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.flt-btn-excel:hover { background: #1d6f42 !important; color: #fff !important; }
.flt-btn-csv { color: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-csv:hover { background: #002F70 !important; color: #fff !important; }
.flt-btn-pdf { color: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-pdf:hover { background: #dc2626 !important; color: #fff !important; }

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

/* Modals centering */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
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
    background: #00264D;
}
.modal-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: #ffffff !important;
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

.info-note { background:#e8f4fd; border-left:3px solid var(--blue); padding:9px 13px; border-radius:5px; font-size:12px; color:#1e4080; margin-bottom:13px; }
.form-group { margin-bottom:13px; }
.form-group label { display:block; font-size:11px; font-weight:700; color:var(--gray); text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px; }
.form-group input, .form-group textarea { width:100%; padding:8px 10px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; box-sizing:border-box; }
.form-group input:focus, .form-group textarea:focus { outline:none; border-color:var(--blue); }
</style>

<div class="int-head">
  <div>
    <h1><i class="fas fa-gas-pump"></i> Fuel Inventory Management</h1>
  </div>
</div>

<!-- Summary Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:24px;">
    <!-- Card 1: Total Fuel Available -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Fuel Available</div>
            <div style="font-size:22px; font-weight:800; color:#0284c7; margin-top:4px;"><?= number_format($total_fuel_available, 2) ?> L</div>
        </div>
        <div style="background:#e0f2fe; color:#0284c7; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-gas-pump"></i></div>
    </div>
    <!-- Card 2: Total Tank Capacity -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Tank Capacity</div>
            <div style="font-size:22px; font-weight:800; color:#475569; margin-top:4px;"><?= number_format($total_tank_capacity) ?> L</div>
        </div>
        <div style="background:#f1f5f9; color:#475569; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-database"></i></div>
    </div>
    <!-- Card 3: Low Fuel Tanks -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Low Fuel Tanks</div>
            <div style="font-size:22px; font-weight:800; color:#fd7e14; margin-top:4px;"><?= number_format($total_low_fuel_tanks) ?></div>
        </div>
        <div style="background:#fff3cd; color:#fd7e14; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <!-- Card 4: Critical Fuel Tanks -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Critical Fuel Tanks</div>
            <div style="font-size:22px; font-weight:800; color:#dc3545; margin-top:4px;"><?= number_format($total_critical_fuel_tanks) ?></div>
        </div>
        <div style="background:#fce8e6; color:#dc3545; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-fire"></i></div>
    </div>
    <!-- Card 5: Total Fuel Value -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Fuel Inventory Value</div>
            <div style="font-size:22px; font-weight:800; color:#16a34a; margin-top:4px;">₱<?= number_format($total_fuel_value, 2) ?></div>
        </div>
        <div style="background:#dcfce7; color:#16a34a; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-coins"></i></div>
    </div>
</div>

<!-- Search & Filters -->
<div class="inv-filter-bar" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:20px; background:#fff; padding:12px 16px; border:1px solid #e2e8f0; border-radius:8px;">
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
    <option value="xcs">XCS</option>
    <option value="xtra advance">Xtra Advance</option>
  </select>
  <!-- Filter Status -->
  <select id="sf" onchange="filterFuelTable()" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; outline:none; background:#fff;">
    <option value="">All Statuses</option>
    <option value="normal">Normal</option>
    <option value="low">Low</option>
    <option value="critical">Critical</option>
    <option value="out of stock">Out of Stock</option>
  </select>
</div>

<!-- Table Wrap -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px;">
  <div class="aif-wrap">
    <table class="aif-tbl" id="adminFuelInvTable">
      <thead>
        <tr>
          <th style="width:70px; text-align:center;">UGT No.</th>
          <th>Fuel Type</th>
          <th style="text-align:right;">Current Level (L)</th>
          <th style="text-align:right;">Capacity (L)</th>
          <th style="text-align:center;">Fill %</th>
          <th style="text-align:right;">Reorder Level</th>
          <th style="text-align:right;">Price/L</th>
          <th style="text-align:center;">Status</th>
          <th>Last Updated</th>
          <th style="text-align:center; width:100px;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10" style="text-align:center; padding:32px; color:#6c757d;">No fuel inventory data available.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r):
            $ts_str  = $r['timestamp'] ? date('M d, Y h:i A', strtotime($r['timestamp'])) : '—';
        ?>
        <?php $fill = min(100, round($r['fill_pct'], 0)); ?>
        <tr class="fuel-row"
            data-tank-num="<?= htmlspecialchars(strtolower($r['tanker_num'])) ?>"
            data-fuel-type="<?= htmlspecialchars(strtolower(get_canonical_fuel_name($r['fuel_type']))) ?>"
            data-status="<?= htmlspecialchars(strtolower($r['status'])) ?>">
          <td style="text-align:center;" class="bold"><?= $r['tanker_num'] ?></td>
          <td style="font-weight:700;"><?= htmlspecialchars(get_canonical_fuel_name($r['fuel_type'])) ?></td>
          <td style="text-align:right; font-weight:700; color:#002F70;"><?= number_format($r['current_level'], 2) ?> L</td>
          <td style="text-align:right; font-weight:600; color:#475569;"><?= number_format($r['capacity'], 0) ?> L</td>
          <td style="text-align:center;">
            <div style="display:flex;align-items:center;gap:8px;justify-content:center;">
              <div style="flex:1;height:8px;background:#e0e0e0;border-radius:4px;overflow:hidden;min-width:48px;">
                <div style="width:<?= $fill ?>%;height:100%;background:<?= $r['status_color'] ?>;border-radius:4px;"></div>
              </div>
              <span style="font-size:11px;font-weight:600;min-width:32px;text-align:right;"><?= $fill ?>%</span>
            </div>
          </td>
          <td style="text-align:right;"><?= number_format($r['reorder_level'], 0) ?> L</td>
          <td style="text-align:right; font-family:monospace; font-weight:600;">&#8369;<?= number_format($r['price'], 2) ?></td>
          <td style="text-align:center;"><span class="status-pill" style="background:<?= $r['status_color'] ?>18; color:<?= $r['status_color'] ?>; border:1px solid <?= $r['status_color'] ?>40;"><?= htmlspecialchars($r['status']) ?></span></td>
          <td style="color:#64748b; font-size:11px;"><?= $ts_str ?></td>
          <td style="text-align:center;">
            <button class="int-btn-outline" onclick="viewTankDetails(<?= htmlspecialchars(json_encode($r)) ?>)" title="View Details" style="padding:6px 12px;">
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
    <div class="modal-box" style="width:650px;">
        <div class="modal-header">
            <h3 id="tankModalTitle">Tank Details</h3>
        </div>
        <div class="modal-body">
            <!-- Grid Layout for Tank details -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                <!-- Tank Info -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:15px;">
                    <h4 style="margin:0 0 10px; color:#00264D; font-size:12px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px;"><i class="fas fa-info-circle"></i> Tank Information</h4>
                    <table style="width:100%; font-size:13px;">
                        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0; color:#64748b; font-weight:600; width:55%;">UGT Number:</td><td id="detTankId" style="font-weight:700; color:#0f172a;"></td></tr>
                        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0; color:#64748b; font-weight:600;">Fuel Type:</td><td id="detFuelType" style="font-weight:700; color:#0f172a;"></td></tr>
                        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0; color:#64748b; font-weight:600;">Tank Capacity:</td><td id="detCapacity" style="font-weight:600; color:#475569;"></td></tr>
                        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0; color:#64748b; font-weight:600;">Current Liters:</td><td id="detVolume" style="font-weight:700; color:#002F70;"></td></tr>
                        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0; color:#64748b; font-weight:600;">Reorder Level:</td><td id="detReorder" style="color:#0f172a;"></td></tr>
                        <tr><td style="padding:6px 0; color:#64748b; font-weight:600;">Critical Level:</td><td id="detCritical" style="color:#0f172a;"></td></tr>
                    </table>
                </div>
                
                <!-- Pricing & Status -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:15px;">
                    <h4 style="margin:0 0 10px; color:#00264D; font-size:12px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px;"><i class="fas fa-tags"></i> Pricing &amp; Status</h4>
                    <table style="width:100%; font-size:13px;">
                        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0; color:#64748b; font-weight:600; width:55%;">Latest Cost/Liter:</td><td id="detCost" style="font-weight:700; color:#475569;"></td></tr>
                        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0; color:#64748b; font-weight:600;">Selling Price/Liter:</td><td id="detPrice" style="font-weight:700; color:#16a34a;"></td></tr>
                        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0; color:#64748b; font-weight:600;">Inventory Value:</td><td id="detVal" style="font-weight:700; color:#002F70;"></td></tr>
                        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0; color:#64748b; font-weight:600;">Status:</td><td id="detStatus" style="padding:6px 0;"></td></tr>
                        <tr><td style="padding:6px 0; color:#64748b; font-weight:600;">Last Updated:</td><td id="detUpdated" style="color:#64748b; font-size:11px;"></td></tr>
                    </table>
                </div>
            </div>

            <!-- Recent Activity Table -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:15px;">
                <h4 style="margin:0 0 10px; color:#00264D; font-size:12px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px;"><i class="fas fa-history"></i> Recent Activity (Read-Only)</h4>
                <div style="max-height:200px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:6px; background:#fff;">
                    <table class="po-table" style="margin:0; width:100%;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reference</th>
                                <th>Transaction</th>
                                <th style="text-align:right;">Liters</th>
                                <th>Performed By</th>
                            </tr>
                        </thead>
                        <tbody id="detRecentActivity">
                            <tr><td colspan="5" style="text-align:center; padding:12px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <?php if ($can_correct_fuel): ?>
            <button onclick="openEditFromDetails()" class="btn-save" style="height:32px; font-size:12px; padding:0 12px;">
                <i class="fas fa-edit"></i> Correct Level
            </button>
            <?php endif; ?>
            <button onclick="closeTankModal()" class="btn-cancel" style="height:32px; font-size:12px; padding:0 12px;">Close</button>
        </div>
    </div>
</div>

<?php if ($can_correct_fuel): ?>
<!-- Edit Modal (Discrepancy Correction) -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box" style="width:460px;">
    <div class="modal-header">
      <h3>Correct Fuel Level Discrepancy</h3>
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
<?php endif; ?>

<script>
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
        var rStatus = (row.dataset.status || '').toLowerCase();
        
        if (search) {
            if (rTankNum.indexOf(search) === -1 && rFuelType.indexOf(search) === -1) {
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

var _selectedTank = null;
function viewTankDetails(r) {
    _selectedTank = r;
    document.getElementById('detTankId').textContent = r.tanker_num;
    document.getElementById('detFuelType').textContent = r.fuel_type;
    document.getElementById('detCapacity').textContent = Number(r.capacity).toLocaleString() + ' L';
    document.getElementById('detVolume').textContent = Number(r.current_level).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
    document.getElementById('detReorder').textContent = Number(r.reorder_level).toLocaleString() + ' L';
    document.getElementById('detCritical').textContent = Number(r.critical_level).toLocaleString() + ' L';
    document.getElementById('detCost').textContent = '₱' + Number(r.cost).toFixed(2);
    document.getElementById('detPrice').textContent = '₱' + Number(r.price).toFixed(2);
    document.getElementById('detVal').textContent = '₱' + Number(r.value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    var fill = Math.min(100, Math.max(0, Math.round(r.fill_pct, 1)));
    var statusSpan = '<span class="status-pill" style="background:' + r.status_color + '18; color:' + r.status_color + '; border:1px solid ' + r.status_color + '40;">' + r.status + ' (' + fill + '%)</span>';
    document.getElementById('detStatus').innerHTML = statusSpan;
    
    var ts = r.timestamp ? new Date(r.timestamp).toLocaleString() : '—';
    document.getElementById('detUpdated').textContent = ts;
    
    // Load unified activity
    var actBody = document.getElementById('detRecentActivity');
    actBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:12px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading recent activity...</td></tr>';
    
    fetch('admin_inventory_fuel.php?ajax=1&action=get_fuel_details&fuel_type=' + encodeURIComponent(r.fuel_type))
    .then(function(res) { return res.json(); })
    .then(function(res) {
        if (!res.success || !res.activity || res.activity.length === 0) {
            actBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:12px; color:#94a3b8;">No recent activity recorded.</td></tr>';
            return;
        }
        var html = '';
        res.activity.forEach(function(a) {
            var color = a.transaction === 'Delivery' ? '#16a34a' : (a.transaction === 'Sales' ? '#dc2626' : '#2563eb');
            html += '<tr>' +
                '<td>' + a.date + '</td>' +
                '<td><code>' + a.reference + '</code></td>' +
                '<td>' + a.transaction + '</td>' +
                '<td style="text-align:right; font-weight:700; color:' + color + ';">' + a.liters + '</td>' +
                '<td>' + a.user + '</td>' +
                '</tr>';
        });
        actBody.innerHTML = html;
    })
    .catch(function() {
        actBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#dc3545; padding:12px;">Connection error.</td></tr>';
    });
    
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

// Modal dismissal on click outside
document.querySelectorAll('.modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

document.addEventListener('DOMContentLoaded', function() {
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminFuelInvTable','adminFuelRowsLimit','adminFuelInvPagination',20);
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
