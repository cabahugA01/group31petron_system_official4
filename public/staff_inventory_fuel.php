<?php
$page_id = 'inv_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

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

$TANK_CONFIG_17 = get_tank_config();

// ── Fetch fuel_inventory (one row per fuel_type for this station) ─────
$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {}

// ── Fetch today's deliveries per (fuel_type, tank_assigned) ─────────
$del_lookup = [];
try {
    $s = $pdo->prepare("SELECT tank_assigned, fuel_type, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id=? AND DATE(delivery_date)=CURDATE() AND status='Verified' GROUP BY tank_assigned, fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];
    }
} catch (Exception $e) {}

// ── Fetch today's sales per fuel_type ────────────────────────────────
$sales_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE() AND status='Verified' GROUP BY fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];
    }
} catch (Exception $e) {}

// ── Fetch today's calibration/adjustments per fuel_type ──────────────
$adj_lookup = [];
try {
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id=fi.fuel_type_id AND fi.station_id=fa.station_id WHERE fa.station_id=? AND DATE(fa.adjustment_date)=CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];
    }
} catch (Exception $e) {}

// ── Fetch latest price per fuel_type from fuel_pricing ──────────────
$price_lookup = [];
try {
    $s = $pdo->prepare("SELECT ft.name AS fuel_type, fp.price_per_liter FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id=ft.id WHERE fp.station_id=? AND fp.is_active=1 ORDER BY fp.effective_date DESC");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim($row['fuel_type']));
        if (!isset($price_lookup[$key])) $price_lookup[$key] = (float)$row['price_per_liter'];
    }
} catch (Exception $e) {}

// ── Fetch latest validated readings per fuel type ──────────────────
$latest_readings_lookup = [];
try {
    $s = $pdo->prepare("
        SELECT ft.fuel_type, ft.previous_reading, ft.present_reading, ft.calibration, ft.liters_sold
        FROM fuel_transactions ft
        INNER JOIN (
            SELECT fuel_type, MAX(id) AS max_id
            FROM fuel_transactions
            WHERE station_id = ? AND status IN ('Approved', 'Completed', 'Verified')
            GROUP BY fuel_type
        ) latest ON ft.id = latest.max_id
    ");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $latest_readings_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {}

// Fallback lookup if no validated transactions exist yet
foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key = strtolower(trim($tc['fuel_type']));
    if (!isset($latest_readings_lookup[$ft_key])) {
        try {
            $s = $pdo->prepare("
                SELECT fuel_type, previous_reading, present_reading, calibration, liters_sold
                FROM fuel_transactions
                WHERE station_id = ? AND LOWER(trim(fuel_type)) = ?
                ORDER BY transaction_date DESC, id DESC
                LIMIT 1
            ");
            $s->execute([$station_id, $ft_key]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $latest_readings_lookup[$ft_key] = $row;
            } else {
                $latest_readings_lookup[$ft_key] = [
                    'fuel_type' => $tc['fuel_type'],
                    'previous_reading' => 0.00,
                    'present_reading' => 0.00,
                    'calibration' => 0.00,
                    'liters_sold' => 0.00
                ];
            }
        } catch (Exception $e) {
            $latest_readings_lookup[$ft_key] = [
                'fuel_type' => $tc['fuel_type'],
                'previous_reading' => 0.00,
                'present_reading' => 0.00,
                'calibration' => 0.00,
                'liters_sold' => 0.00
            ];
        }
    }
}

// ── Build 17-row dataset ─────────────────────────────────────────────
$rows = [];
$msg = '';
try {
    foreach ($TANK_CONFIG_17 as $tc) {
        $ft_key   = strtolower(trim($tc['fuel_type']));
        if ($ft_key === 'xtra unl' || $ft_key === 'xtr advance') {
            $cand = '';
            if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'xtra unl 1'; }
            elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'xtra unl 2'; }
            if ($cand && isset($fi_lookup[$cand])) { $ft_key = $cand; }
            else { $ft_key = 'xtra unl'; }
        } elseif ($ft_key === 'diesel') {
            $cand = '';
            if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'diesel 1'; }
            elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'diesel 2'; }
            if ($cand && isset($fi_lookup[$cand])) { $ft_key = $cand; }
            else { $ft_key = 'diesel'; }
        }
        $tank_key = strtolower(trim($tc['tank']));
        $inv      = $fi_lookup[$ft_key] ?? null;

        $capacity  = (float)$tc['capacity'];
        $cur_level = min(
            $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0,
            $capacity
        );

        // Number of tanks for this fuel sub-group
        $same_type_count = count(array_filter($TANK_CONFIG_17, function($t) use ($ft_key, $fi_lookup) {
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

        // Deliveries: per tank_assigned
        $purchases = $del_lookup[$tank_key] ?? 0;

        // Sales & Calibration: split equally
        $sales_total = $sales_lookup[$ft_key] ?? 0;
        $adj_total   = $adj_lookup[$ft_key] ?? 0;
        $sales       = $same_type_count > 0 ? round($sales_total / $same_type_count, 2) : 0;
        $calibration_adj = $same_type_count > 0 ? round($adj_total / $same_type_count, 2) : 0;

        // Beginning Balance
        $beginning = $same_type_count > 0 ? round($cur_level / $same_type_count, 2) : 0;

        $total_available = $beginning + $purchases;
        $ending_system   = min(max(0, $total_available - $sales - $calibration_adj), $capacity);

        // Actual Dip = use ending_system as proxy
        $actual_dip = $ending_system;
        $variance   = $ending_system - $actual_dip;

        $current_level_tank = $ending_system;

        // Capacity-based thresholds aligned with TANK_CONFIG_17
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
        $fill_pct = $capacity > 0 ? round(($current_level_tank / $capacity) * 100, 2) : 0;
        if      ($current_level_tank <= 0)             { $status = 'Out of Stock'; $sc = '#dc3545'; }
        elseif  ($current_level_tank <= $critical_lvl) { $status = 'Critical';     $sc = '#dc3545'; }
        elseif  ($current_level_tank <= $low_lvl)      { $status = 'Low';          $sc = '#fd7e14'; }
        else                                           { $status = 'Normal';       $sc = '#28a745'; }

        // Price
        $price = $price_lookup[$ft_key] ?? ($inv ? (float)($inv['price_per_liter'] ?? 0) : 0);

        // Revenue
        $revenue = round($sales * $price, 2);

        // Timestamp
        $timestamp = $inv['last_updated'] ?? null;

        // Fetch Beginning / Ending Readings for this Tank's Fuel Type
        $tx = $latest_readings_lookup[$ft_key] ?? null;
        $beg_reading = $tx ? (float)$tx['previous_reading'] : 0.00;
        $end_reading = $tx ? (float)$tx['present_reading'] : 0.00;
        $calibration_val = $tx ? (float)$tx['calibration'] : 0.00;
        $total_dispensed = max(0, $end_reading - $beg_reading - $calibration_val);

        $rows[] = [
            'fuel_type'       => $tc['fuel_type'],
            'label'           => $tc['label'],
            'tank'            => $tc['tank'],
            'tanker_num'      => $tc['tanker_num'],
            'capacity'        => $capacity,
            'reorder_level'   => $tc['reorder_level'],
            'beginning'       => $beginning,
            'purchases'       => $purchases,
            'total_available' => $total_available,
            'sales'           => $sales,
            'calibration_adj' => $calibration_adj,
            'ending_system'   => $ending_system,
            'actual_dip'      => $actual_dip,
            'variance'        => $variance,
            'current_level'   => $current_level_tank,
            'status'          => $status,
            'status_color'    => $sc,
            'fill_pct'        => $fill_pct,
            'price'           => $price,
            'revenue'         => $revenue,
            'timestamp'       => $timestamp,
            'beginning_reading'=> $beg_reading,
            'ending_reading'   => $end_reading,
            'calibration'     => $calibration_val,
            'total_dispensed' => $total_dispensed
        ];
    }
} catch (Exception $e) {
    $msg = 'Error loading fuel inventory: ' . $e->getMessage();
}

$pending_fuel_sr = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE staff_id=? AND status IN ('Pending', 'Pending Manager Review')");
    $s->execute([$me['id']]);
    $pending_fuel_sr = (int)$s->fetchColumn();
} catch (Exception $e) {}

// Build JS data array for Stock Request modal
$js_fuel = [];
foreach ($rows as $r) {
    $fl  = (float)$r['current_level'];
    $cap = (float)$r['capacity'];
    $pct = $cap > 0 ? ($fl / $cap) * 100 : 0;
    
    $st = strtoupper($r['status']);
    $sc = $r['status_color'];
    if ($st === 'NORMAL' || $st === 'AVAILABLE') {
        $st_cls = 'status-ok';
    } elseif ($st === 'LOW') {
        $st_cls = 'status-low';
    } else {
        $st_cls = 'status-critical';
    }
    
    $js_fuel[] = [
        'name'         => $r['fuel_type'],
        'tanker_label' => $r['label'] ?? '',
        'tanker_num'   => $r['tanker_num'] ?? 0,
        'level'        => $fl,
        'capacity'     => $cap,
        'reorder_level'=> (float)($r['reorder_level'] ?? 0),
        'pct'          => round($pct, 1),
        'variance'     => $r['variance'],
        'status'       => $st,
        'statusCls'    => $st_cls,
        'color'        => $sc,
    ];
}

// Summary Metrics calculations
$total_tanks = count($rows);
$total_fuel_available = array_sum(array_column($rows, 'current_level'));
$total_low_fuel_tanks = count(array_filter($rows, fn($r) => $r['status'] === 'Low'));
$total_critical_fuel_tanks = count(array_filter($rows, fn($r) => in_array($r['status'], ['Critical','Out of Stock'])));

include __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>
<div class="stock-page">
<style>
.int-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; margin-top: 0 !important; padding-top: 0; padding-bottom: 10px; border-bottom: 2px solid #e9ecef; }
.main, .main-content { padding-top: 0 !important; }
.int-head h1 { font-size: 22px !important; font-weight: 700 !important; color: #00264D !important; margin: 0 !important; text-transform: uppercase !important; display: flex; align-items: center; gap: 8px; }
.int-head .sub { font-size: 13px; color: #64748b; margin-top: 4px; text-transform: none !important; }

.inv-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:20px; }
.inv-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.inv-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.inv-card-body  { padding:20px; }

/* ── Filter Bar ── */
.inv-filter-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:16px; position:relative; z-index:25; isolation:isolate; pointer-events:auto; }
.inv-filter-bar select, .inv-filter-bar input[type=text], .inv-filter-bar input[type=date] {
    padding:8px 10px;
    border:1px solid #ced4da;
    border-radius:6px;
    font-size:13px;
    color:#374151;
    background:#fff;
    height:36px;
    outline:none;
    pointer-events:auto;
    position:relative;
    z-index:2;
}
.inv-filter-bar select { cursor:pointer; }
.inv-filter-bar input[type=text] { cursor:text; }
.fuel-filter-actions { display:flex; align-items:center; gap:8px; }

/* Keep filter controls above table/card surfaces */
#sq, #cf, #sf, #df,
select#cf, select#sf,
input#sq, input#df {
    pointer-events: auto !important;
    cursor: pointer !important;
    opacity: 1 !important;
    visibility: visible !important;
    display: inline-block !important;
    position: relative !important;
    z-index: 3 !important;
}
#sq { cursor: text !important; }
.inv-filter-bar button, .inv-filter-bar a { pointer-events:auto !important; position:relative; z-index:3; }

/* ── No-Scroll Fixed-Layout Table ── */
body, html { overflow-x: hidden !important; }

.table-wrap {
    width: 100%;
    max-width: 100%;
    overflow: hidden;
    padding: 0;
}
.fuel-table {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
    border-collapse: collapse;
    border-spacing: 0;
    font-size: 12.5px;
}
.fuel-table thead tr { background: #002F6C; }
.fuel-table thead th {
    padding: 10px 6px; 
    text-align: center; 
    font-size: 11px; 
    font-weight: 700;
    color: #fff; 
    text-transform: uppercase; 
    letter-spacing: .3px;
    white-space: normal; 
    word-wrap: break-word; 
    overflow-wrap: break-word;
    line-height: 1.35; 
    vertical-align: middle;
}
.fuel-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .1s; }
.fuel-table tbody tr:hover { background: #eff6ff; }
.fuel-table tbody td {
    padding: 9px 6px; 
    color: #1e293b; 
    vertical-align: middle;
    text-align: center; 
    overflow: hidden; 
    text-overflow: ellipsis;
    white-space: nowrap; 
    line-height: 1.4; 
}
.fuel-table tbody td.bold { font-weight: 700; color: #002F70; }
.status-pill {
    display: inline-block; 
    padding: 3px 10px; 
    border-radius: 20px;
    font-size: 11px; 
    font-weight: 700; 
    white-space: nowrap;
}
.var-zero { color: #6c757d; }
.var-pos  { color: #28a745; font-weight: 700; }
.var-neg  { color: #dc3545; font-weight: 700; }

/* ══ Modal Elements ══ */
.sr-modal-overlay { position:fixed; top:0; right:0; bottom:0; left:250px; background:rgba(0,0,0,.5); z-index:10000; display:none !important; align-items:center; justify-content:center; opacity:0; pointer-events:none !important; transition:opacity .2s ease-in-out; }
.sr-modal-overlay.open { display:flex !important; opacity:1; pointer-events:auto !important; }
.sr-modal-box { background:#fff; border-radius:12px; width:100%; max-width:540px; box-shadow:0 10px 25px rgba(0,0,0,.2); display:flex; flex-direction:column; max-height:90vh; overflow:hidden; pointer-events:auto !important; position:relative; z-index:10001; }
.sr-modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e2e8f0; flex-shrink:0; background:#fff; z-index:1; }
.sr-modal-title { font-size:16px; font-weight:700; color:#002F70; }
.sr-modal-body { overflow-y:auto; flex:1; min-height:0; padding:16px; }
.sr-modal-close { background:none !important; background-color:transparent !important; border:none !important; font-size:24px; color:#64748b !important; cursor:pointer !important; box-shadow:none !important; pointer-events:auto !important; }
.sr-modal-close:hover { color:#1e293b !important; }

/* Modal close and tab button overrides to prevent global button overrides */
.modal-header button, 
.sr-modal-head button,
.modal-tab-btn {
    background: none !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
    pointer-events: auto !important;
    cursor: pointer !important;
}
.modal-tab-btn.active {
    border-bottom: 2px solid #002F70 !important;
    color: #002F70 !important;
}
.modal-tab-btn:not(.active) {
    color: #64748b !important;
}
.sr-info-box { background:#eff6ff; border-left:4px solid #002F70; padding:12px 16px; margin:16px; border-radius:0 8px 8px 0; font-size:13px; color:#1e293b; line-height:1.5; }
.fsr-select-bar { display:flex; align-items:center; padding:10px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:13px; font-weight:600; pointer-events:auto !important; }
.fsr-select-bar input[type="checkbox"] { pointer-events:auto !important; cursor:pointer !important; }
.fsr-select-bar label { pointer-events:auto !important; cursor:pointer !important; }
#fsrCheckList { overflow-y:auto; flex:1; padding:8px 16px; pointer-events:auto !important; }
.fsr-cb-row { display:table-row; padding:0; border-radius:0; border:none; margin-bottom:0; cursor:pointer !important; transition:background .15s ease; pointer-events:auto !important; }
.fsr-cb-row td { padding:10px 12px; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
.fsr-cb-row:hover td { background:#f8fafc; }
.fsr-cb-row.checked td { background:#f0fdf4; }
.fsr-cb-row input[type="checkbox"] { margin-top:3px; transform:scale(1.1); cursor:pointer !important; pointer-events:auto !important; }
.fsr-item-info { flex:1; pointer-events:none; }
.fsr-item-name { font-weight:700; font-size:14px; color:#1e293b; }
.fsr-item-meta { font-size:12px; color:#64748b; margin-top:3px; display:flex; align-items:center; flex-wrap:wrap; gap:4px; }
.sr-modal-footer { display:flex; align-items:center; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; flex-shrink:0; z-index:2; pointer-events:auto !important; }
.sr-modal-footer button { pointer-events:auto !important; cursor:pointer !important; }

/* Petron-clean flt-btn Styles */
.flt-btn { display:inline-flex; align-items:center; gap:6px; padding:0 14px; height:35px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; border:1px solid transparent; background:#fff !important; transition:all .15s; }
.flt-btn-search { color:#0891b2; border-color:#0891b2; background:#fff !important; }
.flt-btn-search:hover { background:#0891b2 !important; color:#fff; }
.flt-btn-reset { color:#6b7280; border-color:#6b7280; background:#fff !important; }
.flt-btn-reset:hover { background:#6b7280 !important; color:#fff; }
.flt-btn-excel { color:#1d6f42; border-color:#1d6f42; background:#fff !important; }
.flt-btn-excel:hover { background:#1d6f42 !important; color:#fff; }
.flt-btn-csv { color:#002F70; border-color:#002F70; background:#fff !important; }
.flt-btn-csv:hover { background:#002F70 !important; color:#fff; }
.flt-btn-pdf { color:#dc2626; border-color:#dc2626; background:#fff !important; }
.flt-btn-pdf:hover { background:#dc2626 !important; color:#fff; }

/* Custom Outlined Buttons for Petron-clean Look */
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

.txn-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; gap:6px !important; padding:7px 14px !important; border-radius:4px !important; font-size:11px !important; font-weight:600 !important; cursor:pointer !important; border:1px solid transparent !important; transition:all .2s ease-in-out !important; text-decoration:none !important; white-space:nowrap !important; box-sizing:border-box !important; background-color:#ffffff !important; background:#ffffff !important; }
.txn-btn.primary   { color:#00264D !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #00264D !important; }
.txn-btn.primary:hover   { background-color:#00264D !important; background:#00264D !important; color:#ffffff !important; }
.txn-btn.secondary { color:#475569 !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #475569 !important; }
.txn-btn.secondary:hover { background-color:#475569 !important; background:#475569 !important; color:#ffffff !important; }
</style>

<div class="int-head">
    <div>
        <h1><i class="fas fa-gas-pump"></i> Fuel Inventory</h1>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- ══ Summary Cards ══ -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px;">
    <!-- Total Tanks -->
    <div style="background:#fff; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Tanks</div>
            <div style="font-size:24px; font-weight:800; color:#002F6C; margin-top:4px;"><?= number_format($total_tanks) ?></div>
        </div>
        <div style="background:#e8f4fd; color:#002F6C; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-database"></i></div>
    </div>
    <!-- Total Fuel Available -->
    <div style="background:#fff; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Fuel Available</div>
            <div style="font-size:24px; font-weight:800; color:#0284c7; margin-top:4px;"><?= number_format($total_fuel_available, 2) ?> Liters (L)</div>
        </div>
        <div style="background:#e0f2fe; color:#0284c7; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-gas-pump"></i></div>
    </div>
    <!-- Low Fuel Tanks -->
    <div style="background:#fff; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Low Fuel Tanks</div>
            <div style="font-size:24px; font-weight:800; color:#fd7e14; margin-top:4px;"><?= number_format($total_low_fuel_tanks) ?></div>
        </div>
        <div style="background:#fff3cd; color:#fd7e14; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <!-- Critical Fuel Tanks -->
    <div style="background:#fff; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Critical Fuel Tanks</div>
            <div style="font-size:24px; font-weight:800; color:#dc3545; margin-top:4px;"><?= number_format($total_critical_fuel_tanks) ?></div>
        </div>
        <div style="background:#fce8e6; color:#dc3545; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-times-circle"></i></div>
    </div>
</div>

<!-- ══ Filter Bar ══ -->
<form id="fuelFilterForm" class="inv-filter-bar" onsubmit="applyFuelInventoryFilters(event)" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
    <div style="position:relative;">
        <i class="fas fa-search" style="position:absolute; left:10px; top:11px; color:#94a3b8; font-size:12px;"></i>
        <input type="text" id="sq" placeholder="Search Tank..." oninput="filterFuelTable()" autocomplete="off" style="padding-left:28px;">
    </div>
    <select id="cf" onchange="filterFuelTable()">
        <option value="">All Fuel Types</option>
        <option value="diesel">Diesel</option>
        <option value="kerosene">Kerosene</option>
        <option value="turbo diesel">Turbo Diesel</option>
        <option value="xcs plus">XCS Plus</option>
        <option value="xtra unl">XTRA UNL</option>
    </select>
    <select id="sf" onchange="filterFuelTable()">
        <option value="">All Statuses</option>
        <option value="normal">Normal</option>
        <option value="low">Low</option>
        <option value="critical">Critical</option>
        <option value="out of stock">Out of Stock</option>
    </select>
    <div style="display:flex; align-items:center; gap:6px;">
        <span style="font-size:13px; color:#64748b; font-weight:500;">Date:</span>
        <input type="date" id="df" onchange="filterFuelTable()">
    </div>
    <div class="fuel-filter-actions">
        <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-search"></i> Filter</button>
        <button type="button" class="flt-btn flt-btn-reset" onclick="resetFuelInventoryFilters()"><i class="fas fa-rotate-left"></i> Reset</button>
    </div>
</form>

<script>
// IMMEDIATE FIX: Force enable dropdowns NOW
(function() {
    function forceEnableNow() {
        ['sq', 'cf', 'sf', 'df'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.style.pointerEvents = 'auto';
                el.style.cursor = id === 'sq' ? 'text' : 'pointer';
                el.disabled = false;
                el.style.opacity = '1';
                el.style.zIndex = '3';
            }
        });
    }
    forceEnableNow();
    setTimeout(forceEnableNow, 100);
    setTimeout(forceEnableNow, 500);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', forceEnableNow);
    }
})();
</script>

<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title">Fuel Tanks Catalog</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button onclick="openFuelSrModal()" class="txn-btn primary">
                <i class="fas fa-boxes"></i> Stock Request
            </button>
        </div>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="fuel-table" id="fuelTable">
                <colgroup>
                    <col style="width:8%">
                    <col style="width:14%">
                    <col style="width:12%">
                    <col style="width:11%">
                    <col style="width:10%">
                    <col style="width:11%">
                    <col style="width:9%">
                    <col style="width:9%">
                    <col style="width:11%">
                    <col style="width:5%">
                </colgroup>
                <thead>
                    <tr>
                        <th>UGT No.</th>
                        <th>Fuel Type</th>
                        <th>Current Level (L)</th>
                        <th>Capacity (L)</th>
                        <th>Fill %</th>
                        <th>Reorder Level</th>
                        <th>Price/L</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:32px;color:#6c757d;font-size:14px;">
                            No fuel inventory data available.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                         $ts_str  = $r['timestamp'] ? date('M d, Y h:i A', strtotime($r['timestamp'])) : '—';
                         $fill    = min(100, round($r['fill_pct'], 0));
                         $fl      = $r['current_level'];
                         $row_date = $r['timestamp'] ? date('Y-m-d', strtotime($r['timestamp'])) : '';
                    ?>
                    <tr class="fuel-row"
                        data-tank-num="<?= htmlspecialchars(strtolower($r['tanker_num'])) ?>"
                        data-fuel-type="<?= htmlspecialchars(strtolower($r['fuel_type'])) ?>"
                        data-tank-ref="<?= htmlspecialchars(strtolower($r['label'])) ?>"
                        data-status="<?= htmlspecialchars(strtolower($r['status'])) ?>"
                        data-date="<?= $row_date ?>">
                        <td style="font-weight:700;color:#002F70;"><?= $r['tanker_num'] ?></td>
                        <td style="font-weight:700;"><?= htmlspecialchars($r['fuel_type']) ?></td>
                        <td style="font-weight:700;color:#002F70;"><?= number_format($fl, 2) ?> L</td>
                        <td style="font-weight:600;color:#475569;"><?= number_format($r['capacity'], 0) ?> L</td>
                        <td>
                            <div style="display:flex;align-items:center;gap:6px;justify-content:center;">
                                <div style="flex:1;height:8px;background:#e0e0e0;border-radius:4px;overflow:hidden;min-width:36px;">
                                    <div style="width:<?= $fill ?>%;height:100%;background:<?= $r['status_color'] ?>;border-radius:4px;"></div>
                                </div>
                                <span style="font-size:11px;font-weight:600;min-width:30px;text-align:right;"><?= $fill ?>%</span>
                            </div>
                        </td>
                        <td style="font-weight:600;color:#64748b;"><?= number_format($r['reorder_level'], 0) ?> L</td>
                        <td style="font-weight:600;color:#0f172a;">&#8369;<?= number_format($r['price'], 2) ?></td>
                        <td>
                            <span class="status-pill" style="background:<?= $r['status_color'] ?>18;color:<?= $r['status_color'] ?>;border:1px solid <?= $r['status_color'] ?>40;">
                                <?= htmlspecialchars($r['status']) ?>
                            </span>
                        </td>
                        <td style="color:#64748b; font-size:11px;"><?= $ts_str ?></td>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:4px; width:100%;">
                                <button class="int-btn-outline" onclick="viewTankDetails(<?= htmlspecialchars(json_encode($r)) ?>)" title="View Details">
                                    <i class="fas fa-eye" style="width:14px;"></i> View
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                    <tr id="fuelNoResultsRow" class="no-paginate" style="display:none;">
                        <td colspan="10" style="text-align:center;padding:32px;color:#64748b;font-size:14px;">
                            No fuel tanks match the selected filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div id="fuelPagination"></div>
    </div>
</div>

<!-- ══ Tank Details Modal ══ -->
<div class="modal-overlay" id="tankModal">
    <div class="modal-box" style="width:500px;">
        <div class="modal-header">
            <h3 id="tankModalTitle">Tank Details</h3>
        </div>
        <div class="modal-body">
            <table style="width:100%; font-size:13px; border-collapse:collapse;">
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600; width:180px;">UGT No:</td><td id="detTankId" style="font-weight:700; color:#0f172a;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Tank Reference:</td><td id="detTankName" style="font-weight:700; color:#0f172a;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Fuel Type:</td><td id="detFuelType" style="font-weight:700; color:#0f172a;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Capacity:</td><td id="detCapacity" style="font-weight:600; color:#475569;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Reorder Level:</td><td id="detReorderLevel" style="font-weight:600; color:#475569;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Calibration:</td><td id="detCalibration" style="font-weight:600; color:#475569;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Total Dispensed:</td><td id="detTotalDispensed" style="font-weight:600; color:#475569;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Remaining Fuel:</td><td id="detVolume" style="font-weight:700; color:#002F70; font-size:14px;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; color:#64748b; font-weight:600;">Status:</td><td id="detStatus" style="padding:10px 0;"></td></tr>
                <tr><td style="padding:10px 0; color:#64748b; font-weight:600;">Last Updated:</td><td id="detUpdated" style="color:#64748b;"></td></tr>
            </table>
        </div>
        <div class="modal-footer">
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
                                <th style="text-align:right;">Liters (L)</th>
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
                                <th style="text-align:right;">Liters Sold (L)</th>
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

<!-- ══ FUEL STOCK REQUEST MODAL ══ -->
<div class="sr-modal-overlay" id="fuelSrModal">
    <div class="sr-modal-box" style="max-width:1100px;">
        <div class="sr-modal-head" style="display:flex; justify-content:space-between; align-items:center;">
            <div class="sr-modal-title">
                <i class="fas fa-gas-pump"></i> Fuel Stock Request
            </div>
            <button class="sr-modal-close" id="fuelSrClose" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">&times;</button>
        </div>

        <div class="sr-modal-body">
            <div style="display:grid; grid-template-columns:280px minmax(0,1fr); gap:20px;">
                <!-- LEFT COLUMN: Request Information -->
                <div>
                    <div style="background:#f8fafc; padding:16px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:16px;">
                        <h4 style="margin:0 0 12px; font-size:14px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:6px;">
                            <i class="fas fa-file-alt"></i> Request Information
                        </h4>
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:12.5px;">
                            <span style="color:#64748b; font-weight:600;">Request No:</span>
                            <span style="font-weight:700; color:#1e293b;">Auto-Assigned</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:12.5px;">
                            <span style="color:#64748b; font-weight:600;">Request Date:</span>
                            <span style="font-weight:700; color:#1e293b;"><?= date('M d, Y') ?></span>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:12.5px;">
                            <span style="color:#64748b; font-weight:600;">Requested By:</span>
                            <span style="font-weight:700; color:#1e293b;"><?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?></span>
                        </div>
                    </div>

                    <div style="margin-top:14px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px;">Remarks / Notes</label>
                        <textarea id="fsrRemarks" rows="4" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#334155;resize:vertical;box-sizing:border-box;outline:none;" placeholder="Optional remarks..."></textarea>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Fuel Selection -->
                <div style="display:flex; flex-direction:column;">
                    <label style="display:block;font-size:12.5px;font-weight:700;color:#374151;margin-bottom:8px;">
                        <i class="fas fa-gas-pump" style="color:#eab308;margin-right:4px;"></i> Fuel Types <span style="color:#dc2626;">*</span>
                    </label>
                    
                    <!-- Select-all bar -->
                    <div class="fsr-select-bar" style="margin-bottom:8px;">
                        <input type="checkbox" id="fsrSelectAll">
                        <label for="fsrSelectAll" style="cursor:pointer;margin:0;margin-left:8px;">Select All</label>
                        <span id="fsrSelectedCount" style="margin-left:auto;color:#002F70;"></span>
                    </div>

                    <!-- Fuel list with checkboxes -->
                    <div id="fsrCheckList"></div>
                </div>
            </div>

            <div id="fsrError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px 14px;border-radius:6px;margin-top:12px;font-size:13px;"></div>
        </div>

        <div class="sr-modal-footer">
            <button type="button" id="fsrCancelBtn" class="txn-btn secondary">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" id="fsrSubmitBtn" class="txn-btn primary">
                <i class="fas fa-paper-plane"></i> Submit Stock Request
            </button>
        </div>
    </div>
</div>

<!-- ── Success popup ── -->
<div id="fsrSuccessOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10998;"></div>
<div id="fsrSuccessPopup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10999;background:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);text-align:center;">
    <div style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <span style="color:#fff;font-size:32px;font-weight:700;">✓</span>
    </div>
    <h3 style="margin:0 0 8px;color:#28a745;">Request Submitted!</h3>
    <p style="margin:0 0 18px;color:#333;font-size:14px;line-height:1.5;" id="fsrSuccessMsg">
        Your fuel stock request is now <strong>Pending</strong> Manager review.
    </p>
    <button onclick="closeFsrSuccess()" class="txn-btn primary">OK</button>
</div>

<script>
var allFuelData = <?php echo json_encode($js_fuel); ?>;

// ── Open stock request modal ────────────────────────────────────────────────────────────────
function openFuelSrModal() {
    renderFsrCheckList();
    syncFsrSelectAll();
    document.getElementById('fsrError').style.display = 'none';
    var rem = document.getElementById('fsrRemarks');
    if (rem) rem.value = '';
    var sb = document.getElementById('fsrSubmitBtn');
    if (sb) { sb.disabled = false; sb.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Stock Request'; }
    document.getElementById('fuelSrModal').classList.add('open');
}

function renderFsrCheckList() {
    // Only show fuels that need replenishment — exclude AVAILABLE status
    var needsRestock = allFuelData.filter(function(it) {
        var s = (it.status || '').toUpperCase();
        return s === 'CRITICAL' || s === 'LOW' || s === 'LOW STOCK' || s === 'OUT OF STOCK';
    });

    if (needsRestock.length === 0) {
        document.getElementById('fsrCheckList').innerHTML =
            '<div style="text-align:center;padding:28px 16px;color:#6c757d;">' +
            '<strong>All fuel tanks are at sufficient levels.</strong><br>' +
            '<small>Stock requests are only needed for Critical, Low, or Out-of-Stock fuels.</small></div>';
        document.getElementById('fsrSubmitBtn').disabled = true;
        return;
    }

    document.getElementById('fsrSubmitBtn').disabled = false;

    var rows = needsRestock.map(function(it) {
        var idx = allFuelData.indexOf(it);
        var badge = '<span style="background:' + it.color + '20;color:' + it.color + ';border:1px solid ' + it.color + '40;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:700;">' + esc(it.status) + '</span>';
        var ugtNo = it.tanker_num ? it.tanker_num : (it.tanker_label || '');
        return '<tr class="fsr-cb-row ' + it.statusCls + '" data-idx="' + idx + '" style="cursor:pointer;">' +
            '<td style="text-align:center;"><input type="checkbox" class="fsr-cb fsr-item-cb" data-idx="' + idx + '"></td>' +
            '<td style="font-weight:700;color:#002F70;">' + esc(it.name) + '</td>' +
            '<td style="font-family:monospace;font-weight:700;">' + esc(ugtNo) + '</td>' +
            '<td style="text-align:right;font-weight:700;">' + it.level.toLocaleString('en-PH',{minimumFractionDigits:2}) + ' L</td>' +
            '<td style="text-align:right;color:#dc2626;font-weight:700;">' + Number(it.reorder_level || 0).toLocaleString('en-PH',{minimumFractionDigits:0}) + ' L</td>' +
            '<td style="text-align:center;">' + badge + '</td>' +
        '</tr>';
    }).join('');
    var html = '<div style="max-height:360px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;">' +
        '<table class="sr-table" style="width:100%;border-collapse:collapse;font-size:12px;">' +
            '<thead><tr style="background:#002F70;color:#fff;position:sticky;top:0;z-index:5;">' +
                '<th style="width:7%;text-align:center;">Select</th>' +
                '<th style="width:24%;text-align:left;">Fuel Type</th>' +
                '<th style="width:16%;text-align:left;">UGT No.</th>' +
                '<th style="width:19%;text-align:right;">Current Liters</th>' +
                '<th style="width:18%;text-align:right;">Reorder Level</th>' +
                '<th style="width:16%;text-align:center;">Status</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>';
    document.getElementById('fsrCheckList').innerHTML = html;

    // Highlight row when checkbox changes
    document.querySelectorAll('.fsr-item-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var row = this.closest('.fsr-cb-row');
            if (row) {
                row.classList.toggle('checked', this.checked);
            }
            syncFsrSelectAll();
        });
    });

    document.querySelectorAll('.fsr-cb-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            if (e.target && e.target.matches('input')) return;
            var cb = this.querySelector('.fsr-item-cb');
            if (!cb) return;
            cb.checked = !cb.checked;
            this.classList.toggle('checked', cb.checked);
            syncFsrSelectAll();
        });
    });
}

function syncFsrSelectAll() {
    var all     = document.querySelectorAll('.fsr-item-cb');
    var checked = document.querySelectorAll('.fsr-item-cb:checked');
    var sa = document.getElementById('fsrSelectAll');
    if (sa) {
        sa.indeterminate = checked.length > 0 && checked.length < all.length;
        sa.checked       = all.length > 0 && checked.length === all.length;
    }
    var countLabel = document.getElementById('fsrSelectedCount');
    if (countLabel) {
        countLabel.textContent = checked.length > 0 ? checked.length + ' selected' : '';
    }
}

var selectAllEl = document.getElementById('fsrSelectAll');
if (selectAllEl) {
    selectAllEl.addEventListener('change', function() {
        var c = this.checked;
        document.querySelectorAll('.fsr-item-cb').forEach(function(cb) { cb.checked = c; });
        document.querySelectorAll('.fsr-cb-row').forEach(function(row) { row.classList.toggle('checked', c); });
        syncFsrSelectAll();
    });
}

// ── Close stock request modal ─────────────────────────────────────────────────────────────────────
function closeFuelSrModal() {
    var m = document.getElementById('fuelSrModal');
    if (m) m.classList.remove('open');
}

// Event listener setup is deferred to DOMContentLoaded to avoid race
// conditions when elements are moved to body

// ── Submit stock request ────────────────────────────────────────────────────────────────────
function submitFuelStockRequest() {
    var checked = document.querySelectorAll('.fsr-item-cb:checked');
    var errEl = document.getElementById('fsrError');
    if (checked.length === 0) {
        errEl.textContent = 'Please select at least one fuel type.';
        errEl.style.display = 'block';
        return;
    }

    var btn = document.getElementById('fsrSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    errEl.style.display = 'none';

    var remarks = ((document.getElementById('fsrRemarks') || {}).value || '').trim() || 'Bulk fuel stock request';

    var items = [];
    checked.forEach(function(cb) {
        var it = allFuelData[parseInt(cb.dataset.idx)];
        if (it) {
            items.push({
                fuel_type:        it.name,
                current_level:    it.level,
                capacity:         it.capacity,
                stock_status:     it.status,
                requested_liters: 0
            });
        }
    });

    fetch('../backend/api/fuel_stock_request.php?action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            items: items,
            remarks: remarks
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Stock Request';
        closeFuelSrModal();

        if (res.success) {
            var srNo = res.request_no || '';
            var cnt  = res.inserted_count || items.length;
            var msg  = 'Successfully submitted stock requests for <strong>' + cnt + '</strong> fuel type' + (cnt !== 1 ? 's' : '') + '.';
            if (srNo) msg += '<br><span style="font-size:12px;color:#64748b;">Request No: <strong>' + esc(srNo) + '</strong></span>';
            if (res.message && res.message.indexOf('skipped') !== -1) {
                msg += '<br><small style="color:#d97706;">' + esc(res.message.split('Note:')[1] || '') + '</small>';
            }
            document.getElementById('fsrSuccessMsg').innerHTML = msg;
        } else {
            document.getElementById('fsrSuccessMsg').innerHTML =
                '<span style="color:#dc2626;">' + esc(res.message || 'Submission failed. Please try again.') + '</span>';
        }

        document.getElementById('fsrSuccessPopup').style.display = 'block';
        document.getElementById('fsrSuccessOverlay').style.display = 'block';
        setTimeout(closeFsrSuccess, 7000);
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        errEl.textContent = 'Network error. Please check your connection and try again.';
        errEl.style.display = 'block';
    });
}

function closeFsrSuccess() {
    document.getElementById('fsrSuccessPopup').style.display  = 'none';
    document.getElementById('fsrSuccessOverlay').style.display = 'none';
    location.reload();
}

function esc(str) { var d = document.createElement('div'); d.appendChild(document.createTextNode(str)); return d.innerHTML; }

// ── Filter Table Logic ──────────────────────────────────────────────────────────
function fuelFilterValue(id) {
    var el = document.getElementById(id);
    return el ? String(el.value || '').toLowerCase().trim() : '';
}

function refreshFuelPagination() {
    if (window.tablePaginationTriggers && typeof window.tablePaginationTriggers.fuelTable === 'function') {
        window.tablePaginationTriggers.fuelTable();
    }
}

function filterFuelTable() {
    var search = fuelFilterValue('sq');
    var fuelType = fuelFilterValue('cf');
    var status = fuelFilterValue('sf');
    var dateVal = document.getElementById('df') ? document.getElementById('df').value : '';
    var rows = document.querySelectorAll('#fuelTable tbody tr.fuel-row');
    var visibleCount = 0;

    rows.forEach(function(row) {
        var rTankNum = (row.dataset.tankNum || '').toLowerCase();
        var rFuelType = (row.dataset.fuelType || '').toLowerCase();
        var rTankRef = (row.dataset.tankRef || '').toLowerCase();
        var rStatus = (row.dataset.status || '').toLowerCase();
        var rDate = row.dataset.date || '';
        var match = true;

        if (search && rTankNum.indexOf(search) === -1 && rFuelType.indexOf(search) === -1 && rTankRef.indexOf(search) === -1) {
            match = false;
        }
        if (fuelType && rFuelType !== fuelType) {
            match = false;
        }
        if (status && rStatus !== status) {
            match = false;
        }
        if (dateVal && rDate !== dateVal) {
            match = false;
        }

        row.classList.toggle('search-hidden', !match);
        row.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });

    var noResultsRow = document.getElementById('fuelNoResultsRow');
    if (noResultsRow) {
        noResultsRow.style.display = rows.length > 0 && visibleCount === 0 ? '' : 'none';
    }

    refreshFuelPagination();
}

function applyFuelInventoryFilters(e) {
    if (e) e.preventDefault();
    filterFuelTable();
    return false;
}

function resetFuelInventoryFilters() {
    ['sq', 'cf', 'sf', 'df'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
    filterFuelTable();
}

// ── Tank Details Modal ──────────────────────────────────────────────────────────
function viewTankDetails(r) {
    document.getElementById('detTankId').textContent = r.tanker_num;
    document.getElementById('detTankName').textContent = r.label;
    document.getElementById('detFuelType').textContent = r.fuel_type;
    document.getElementById('detCapacity').textContent = Number(r.capacity).toLocaleString() + ' Liters (L)';
    document.getElementById('detReorderLevel').textContent = Number(r.reorder_level).toLocaleString() + ' Liters (L)';
    document.getElementById('detCalibration').textContent = Number(r.calibration).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Liters (L)';
    document.getElementById('detTotalDispensed').textContent = Number(r.total_dispensed).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Liters (L)';
    document.getElementById('detVolume').textContent = Number(r.current_level).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Liters (L)';
    
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

// ── Fuel Movement Modal ──────────────────────────────────────────────────────────
var currentMovTab = 'deliveries';
function viewFuelMovement(fuelType, tankName) {
    document.getElementById('movementModalTitle').textContent = 'Movement History — ' + tankName + ' (' + fuelType + ')';
    
    var delBody = document.getElementById('movDeliveriesBody');
    var salesBody = document.getElementById('movSalesBody');
    
    delBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:24px; color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading deliveries...</td></tr>';
    salesBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:24px; color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading sales...</td></tr>';
    
    document.getElementById('movementModal').classList.add('open');
    switchMovTab('deliveries');

    fetch('staff_inventory_fuel.php?ajax=1&action=get_fuel_details&fuel_type=' + encodeURIComponent(fuelType))
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
                    '<td style="text-align:right; font-weight:700; color:#002F70;">' + Number(d.delivery_liters).toLocaleString() + ' Liters (L)</td>' +
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
                    '<td style="text-align:right; font-weight:700; color:#002F70;">' + Number(t.liters_sold).toLocaleString() + ' Liters (L)</td>' +
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

// ── Print Tank Record ──────────────────────────────────────────────────────────
function printTankRecord(r) {
    var win = window.open('', '_blank');
    var ts = r.timestamp ? new Date(r.timestamp).toLocaleString() : '—';
    var fill = Math.min(100, Math.max(0, Math.round(r.fill_pct, 1)));
    
    var html = '<html><head><title>Tank Report - ' + r.label + '</title>';
    html += '<style>';
    html += 'body { font-family: Arial, sans-serif; margin: 40px; color: #333; }';
    html += '.header { border-bottom: 2px solid #002F6C; padding-bottom: 10px; margin-bottom: 20px; }';
    html += 'h2 { color: #002F6C; margin: 0; }';
    html += '.meta { font-size: 12px; color: #666; margin-top: 5px; }';
    html += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    html += 'th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }';
    html += 'th { background-color: #f8f9fa; color: #002F6C; font-weight: bold; }';
    html += '.status { font-weight: bold; }';
    html += '</style></head><body>';
    
    html += '<div class="header">';
    html += '<h2>PETRON TANK REPORT</h2>';
    html += '<div class="meta">Generated on: ' + new Date().toLocaleString() + '</div>';
    html += '</div>';
    
    html += '<table>';
    html += '<tr><td><strong>Tank Number</strong></td><td>' + r.tanker_num + '</td></tr>';
    html += '<tr><td><strong>Tank Reference</strong></td><td>' + r.label + '</td></tr>';
    html += '<tr><td><strong>Fuel Type</strong></td><td>' + r.fuel_type + '</td></tr>';
    html += '<tr><td><strong>Capacity</strong></td><td>' + Number(r.capacity).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Latest Reading</strong></td><td>' + Number(r.ending_reading).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Beginning Reading</strong></td><td>' + Number(r.beginning_reading).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Ending Reading</strong></td><td>' + Number(r.ending_reading).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Calibration</strong></td><td>' + Number(r.calibration).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Total Dispensed</strong></td><td>' + Number(r.total_dispensed).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Remaining Fuel</strong></td><td>' + Number(r.current_level).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Available %</strong></td><td>' + fill + '%</td></tr>';
    html += '<tr><td><strong>Status</strong></td><td class="status">' + r.status + '</td></tr>';
    html += '<tr><td><strong>Last Updated</strong></td><td>' + ts + '</td></tr>';
    html += '</table>';
    
    html += '</body></html>';
    
    win.document.write(html);
    win.document.close();
    win.print();
}

document.addEventListener('DOMContentLoaded', function() {
    // Move modals to body to avoid z-index and stacking context issues
    ['tankModal', 'movementModal', 'fuelSrModal', 'fsrSuccessOverlay', 'fsrSuccessPopup'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });

    // Wire modal buttons AFTER elements are moved to body
    var closeBtn   = document.getElementById('fuelSrClose');
    var cancelBtn  = document.getElementById('fsrCancelBtn');
    var submitBtn  = document.getElementById('fsrSubmitBtn');
    var overlay    = document.getElementById('fuelSrModal');
    var selectAll  = document.getElementById('fsrSelectAll');

    if (closeBtn)  closeBtn.addEventListener('click',  closeFuelSrModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeFuelSrModal);
    if (overlay)   overlay.addEventListener('click', function(e) {
        if (e.target === this) closeFuelSrModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeFuelSrModal();
    });

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var c = this.checked;
            document.querySelectorAll('.fsr-item-cb').forEach(function(cb) { cb.checked = c; });
            document.querySelectorAll('.fsr-cb-row').forEach(function(row) { row.classList.toggle('checked', c); });
            syncFsrSelectAll();
        });
    }

    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            submitFuelStockRequest();
        });
    }

    var filterForm = document.getElementById('fuelFilterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', applyFuelInventoryFilters);
    }

    [['sq', 'input'], ['cf', 'change'], ['sf', 'change'], ['df', 'change']].forEach(function(item) {
        var el = document.getElementById(item[0]);
        if (el) {
            el.disabled = false;
            el.addEventListener(item[1], filterFuelTable);
        }
    });

    if (typeof setupTablePagination === 'function') {
        setupTablePagination('fuelTable', 'fuelRowsLimit', 'fuelPagination', 20);
    }
    filterFuelTable();
});

// ── Initialize page ──
</script>
</div> <!-- /stock-page -->
<?php include __DIR__ . '/../partials/footer.php'; ?>

