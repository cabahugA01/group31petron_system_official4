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
        'tank_name'      => $inv['tank_name'] ?? $tc['label'] ?? '',
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

// ── Active Tab ────────────────────────────────────────────────
$active_tab = trim($_GET['tab'] ?? 'overview');
if (in_array($active_tab, ['transactions', 'movement'], true)) $active_tab = 'movement';
elseif (in_array($active_tab, ['deliveries', 'stockin'], true)) $active_tab = 'stockin';
elseif ($active_tab === 'sales') $active_tab = 'sales';
elseif ($active_tab === 'remaining') $active_tab = 'remaining';
else $active_tab = 'overview';

// ── Spec Dashboard Metrics ────────────────────────────────────
$diesel_available  = 0;
$premium_available = 0;
$regular_available = 0;
$low_fuel_alerts   = 0;
$critical_fuel_alerts = 0;

foreach ($rows as $r) {
    $ft  = strtolower(trim($r['fuel_type']));
    $vol = (float)$r['current_level'];
    if (strpos($ft, 'diesel') !== false || strpos($ft, 'kerosene') !== false) {
        $diesel_available += $vol;
    } elseif (strpos($ft, 'xcs') !== false || strpos($ft, 'premium') !== false || strpos($ft, 'turbo') !== false) {
        $premium_available += $vol;
    } else {
        $regular_available += $vol;
    }
    if ($r['status'] === 'Low') {
        $low_fuel_alerts++;
    } elseif (in_array($r['status'], ['Critical', 'Out of Stock'])) {
        $critical_fuel_alerts++;
    }
}

// ── Fetch Fuel Deliveries History ─────────────────────────────
$fuel_deliveries_list = [];
try {
    $stmt = $pdo->prepare("
        SELECT fd.id, fd.delivery_date, fd.fuel_type, fd.supplier, fd.invoice_no,
               fd.delivery_liters, fd.status, fd.tanker_number, fd.notes,
               COALESCE(fd.po_number, '—') AS po_number,
               COALESCE(fd.cost_per_liter, 0) AS cost_per_liter,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Staff') AS received_by_name
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        WHERE fd.station_id = ?
        ORDER BY fd.delivery_date DESC, fd.id DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $fuel_deliveries_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fetch Fuel Transactions History ───────────────────────────
$fuel_transactions_list = [];
try {
    $stmt = $pdo->prepare("
        SELECT ft.id, ft.transaction_date, ft.fuel_type, ft.liters_sold, ft.calibration,
               ft.total_amount, ft.status, ft.shift_period,
               COALESCE(ft.beginning_volume, 0) AS beginning_volume,
               COALESCE(ft.delivered_volume, 0) AS delivered_volume,
               COALESCE(ft.ending_volume, 0) AS ending_volume,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Station Manager') AS verified_by_name
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.validated_by = u.id
        WHERE ft.station_id = ?
        ORDER BY ft.transaction_date DESC, ft.id DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $fuel_transactions_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fetch Daily Fuel Sales ──────────────────────────────────────
$daily_fuel_sales = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            DATE(ft.transaction_date) AS date,
            ft.fuel_type,
            SUM(ft.liters_sold) AS liters_sold,
            SUM(ft.total_amount) AS sales_amount
        FROM fuel_transactions ft
        WHERE (ft.station_id = ? OR ft.station_id = 0 OR ft.station_id IS NULL)
          AND (ft.status IS NULL OR LOWER(ft.status) NOT IN ('voided','void','cancelled'))
        GROUP BY DATE(ft.transaction_date), ft.fuel_type
        ORDER BY DATE(ft.transaction_date) DESC, ft.fuel_type ASC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $daily_fuel_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Compute Remaining Fuel Volume Summary ────────────────────────
$remaining_fuel_volume = [];
$_rfv_seen = [];
foreach ($rows as $r) {
    $ft = get_canonical_fuel_name($r['fuel_type']);
    if (!isset($_rfv_seen[$ft])) {
        $_rfv_seen[$ft] = [
            'fuel_type'   => $ft,
            'beginning'   => 0,
            'delivered'   => 0,
            'dispensed'   => 0,
            'calibration' => 0,
            'remaining'   => 0
        ];
    }
    $_rfv_seen[$ft]['beginning']   += (float)($r['beginning'] ?? $r['current_level'] ?? 0);
    $_rfv_seen[$ft]['delivered']   += (float)($r['purchases'] ?? 0);
    $_rfv_seen[$ft]['dispensed']   += (float)($r['sales'] ?? 0);
    $_rfv_seen[$ft]['calibration'] += (float)($r['calibration'] ?? 0);
    $_rfv_seen[$ft]['remaining']   += (float)($r['current_level'] ?? 0);
}
$remaining_fuel_volume = array_values($_rfv_seen);

// ── Fetch Fuel Movement History (combined) ────────────────────────
$fuel_movement_log = [];
try {
    $stmt = $pdo->prepare("
        SELECT fd.delivery_date AS date, fd.fuel_type,
               fd.delivery_liters AS delivery, 0 AS dispensed, 0 AS calibration
        FROM fuel_deliveries fd
        WHERE (fd.station_id = ? OR fd.station_id = 0 OR fd.station_id IS NULL)
        ORDER BY fd.delivery_date DESC LIMIT 100
    ");
    $stmt->execute([$station_id]);
    $fuel_movement_log = array_merge($fuel_movement_log, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("
        SELECT ft.transaction_date AS date, ft.fuel_type,
               0 AS delivery, ft.liters_sold AS dispensed,
               COALESCE(ft.calibration, 0) AS calibration
        FROM fuel_transactions ft
        WHERE (ft.station_id = ? OR ft.station_id = 0 OR ft.station_id IS NULL)
        ORDER BY ft.transaction_date DESC LIMIT 100
    ");
    $stmt->execute([$station_id]);
    $fuel_movement_log = array_merge($fuel_movement_log, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}
usort($fuel_movement_log, function($a, $b) { return strcmp($b['date'], $a['date']); });

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

/* ── Modal Tab Button Overrides ── */
.modal-tab-btn {
    background: none !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 10px 16px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    border-bottom: 2px solid transparent !important;
    transition: color 0.15s, border-color 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.modal-tab-btn.active {
    border-bottom: 2px solid #002F70 !important;
    color: #002F70 !important;
    font-weight: 700 !important;
    background: transparent !important;
    background-color: transparent !important;
}
.modal-tab-btn:not(.active) {
    color: #64748b !important;
    background: transparent !important;
    background-color: transparent !important;
}
.modal-tab-btn:hover:not(.active) {
    color: #002F70 !important;
    border-bottom: 2px solid #c7d4ea !important;
    background: #f1f5f9 !important;
}

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
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:24px;">
    <!-- Card 1: Total Fuel Available -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Fuel Available (L)</div>
            <div style="font-size:20px; font-weight:800; color:#0284c7; margin-top:4px;"><?= number_format($total_fuel_available, 2) ?> L</div>
        </div>
        <div style="background:#e0f2fe; color:#0284c7; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-gas-pump"></i></div>
    </div>
    <!-- Card 2: Diesel Available -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Diesel Available (L)</div>
            <div style="font-size:20px; font-weight:800; color:#0f172a; margin-top:4px;"><?= number_format($diesel_available, 2) ?> L</div>
        </div>
        <div style="background:#f1f5f9; color:#475569; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-oil-can"></i></div>
    </div>
    <!-- Card 3: Premium Available -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Premium Available (L)</div>
            <div style="font-size:20px; font-weight:800; color:#16a34a; margin-top:4px;"><?= number_format($premium_available, 2) ?> L</div>
        </div>
        <div style="background:#dcfce7; color:#16a34a; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-charging-station"></i></div>
    </div>
    <!-- Card 4: Regular Available -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Regular Available (L)</div>
            <div style="font-size:20px; font-weight:800; color:#2563eb; margin-top:4px;"><?= number_format($regular_available, 2) ?> L</div>
        </div>
        <div style="background:#dbeafe; color:#2563eb; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-fill-drip"></i></div>
    </div>
    <!-- Card 5: Low Fuel Alerts -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Low Fuel Alerts</div>
            <div style="font-size:20px; font-weight:800; color:#fd7e14; margin-top:4px;"><?= number_format($low_fuel_alerts) ?></div>
        </div>
        <div style="background:#fff3cd; color:#fd7e14; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <!-- Card 6: Critical Fuel Alerts -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Critical Fuel Alerts</div>
            <div style="font-size:20px; font-weight:800; color:#dc3545; margin-top:4px;"><?= number_format($critical_fuel_alerts) ?></div>
        </div>
        <div style="background:#fce8e6; color:#dc3545; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-fire"></i></div>
    </div>
</div>

<!-- Tab Navigation -->
<div class="tab-nav" style="overflow-x:auto; flex-wrap:nowrap; white-space:nowrap; padding-bottom:2px;">
    <a href="admin_inventory_fuel.php?<?= http_build_query(array_merge($_GET, ['tab' => 'overview'])) ?>"
       class="tab-btn <?= $active_tab === 'overview' ? 'active' : '' ?>">
        <i class="fas fa-gas-pump"></i> Fuel Overview
    </a>
    <a href="admin_inventory_fuel.php?tab=stockin"
       class="tab-btn <?= ($active_tab === 'stockin' || $active_tab === 'deliveries') ? 'active' : '' ?>">
        <i class="fas fa-truck"></i> Fuel Deliveries
    </a>
    <a href="admin_inventory_fuel.php?tab=sales"
       class="tab-btn <?= $active_tab === 'sales' ? 'active' : '' ?>">
        <i class="fas fa-chart-line"></i> Daily Fuel Sales
    </a>
    <a href="admin_inventory_fuel.php?tab=remaining"
       class="tab-btn <?= $active_tab === 'remaining' ? 'active' : '' ?>">
        <i class="fas fa-tachometer-alt"></i> Remaining Fuel Volume
    </a>
</div>

<?php if ($active_tab === 'overview'): ?>
<!-- Search & Filters -->
<div class="inv-filter-bar" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:20px; background:#fff; padding:12px 16px; border:1px solid #e2e8f0; border-radius:8px;">
  <!-- Search Fuel Type -->
  <div style="position:relative;">
    <i class="fas fa-search" style="position:absolute; left:10px; top:11px; color:#94a3b8; font-size:12px;"></i>
    <input type="text" id="sq" placeholder="Search Fuel Type..." oninput="filterFuelTable()" style="padding:7px 10px 7px 28px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:180px; outline:none;">
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
          <th style="width:100px; text-align:center;">UGT No.</th>
          <th>Fuel Type</th>
          <th style="text-align:right;">Current Level (L)</th>
          <th style="text-align:right;">Reorder Level</th>
          <th style="text-align:right;">Critical Level</th>
          <th style="text-align:center;">Status</th>
          <th>Last Updated</th>
          <th style="text-align:center;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="8" style="text-align:center; padding:32px; color:#6c757d;">No fuel inventory data available.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r):
            $ts_str  = $r['timestamp'] ? date('M d, Y h:i A', strtotime($r['timestamp'])) : '—';
            $fill = min(100, round($r['fill_pct'], 0));
            $r_json = htmlspecialchars(json_encode($r), ENT_QUOTES);
        ?>
        <tr class="fuel-row"
            data-tank-num="<?= htmlspecialchars(strtolower($r['tanker_num'])) ?>"
            data-fuel-type="<?= htmlspecialchars(strtolower(get_canonical_fuel_name($r['fuel_type']))) ?>"
            data-status="<?= htmlspecialchars(strtolower($r['status'])) ?>">
          <td style="text-align:center;">
            <strong style="font-family:monospace;color:#002F6C;font-size:13px;"><?= htmlspecialchars('UGT #' . $r['tanker_num']) ?></strong>
            <div style="font-size:10px;color:#64748b;font-weight:600;"><?= htmlspecialchars($r['tank_name']) ?></div>
          </td>
          <td style="font-weight:700;"><?= htmlspecialchars(get_canonical_fuel_name($r['fuel_type'])) ?></td>
          <td style="text-align:right; font-weight:700; color:#002F70;"><?= number_format($r['current_level'], 2) ?> L</td>
          <td style="text-align:right; font-weight:600; color:#d97706;"><?= number_format($r['reorder_level'], 0) ?> L</td>
          <td style="text-align:right; font-weight:600; color:#dc2626;"><?= number_format($r['critical_level'], 0) ?> L</td>
          <td style="text-align:center;"><span class="status-pill" style="background:<?= $r['status_color'] ?>18; color:<?= $r['status_color'] ?>; border:1px solid <?= $r['status_color'] ?>40;"><?= htmlspecialchars($r['status']) ?></span></td>
          <td style="color:#64748b; font-size:11px;"><?= $ts_str ?></td>
          <td style="text-align:center;">
            <button type="button" class="int-btn-outline" onclick='viewTankDetails(<?= $r_json ?>)'>
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
<?php endif; /* end overview tab */ ?>

<?php if ($active_tab === 'stockin' || $active_tab === 'deliveries'): ?>
<!-- ── STOCK-IN HISTORY TAB ── -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px;">
  <div style="padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#f8fafc;">
    <div style="font-weight:700; color:#002F70; font-size:14px; text-transform:uppercase; display:flex; align-items:center; gap:8px;">
      <i class="fas fa-truck-loading"></i> Stock-In History
    </div>
    <input type="text" id="delSearchInput" placeholder="Search delivery, PO, supplier..." oninput="filterDeliveriesTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; width:220px; outline:none;">
  </div>
  <div class="aif-wrap">
    <table class="aif-tbl" id="adminFuelDelTable">
      <thead>
        <tr>
          <th>Delivery No.</th>
          <th>PO No.</th>
          <th>Supplier</th>
          <th>Fuel Type</th>
          <th style="text-align:right;">Liters</th>
          <th>Delivery Date</th>
          <th style="text-align:center;">Status</th>
        </tr>
      </thead>
      <tbody id="adminFuelDelBody">
      <?php if (empty($fuel_deliveries_list)): ?>
        <tr><td colspan="7" style="text-align:center; padding:32px; color:#6c757d;"><i class="fas fa-truck" style="font-size:24px; display:block; margin-bottom:8px;"></i>No fuel delivery records found.</td></tr>
      <?php else: ?>
        <?php foreach ($fuel_deliveries_list as $d):
            $d_no = $d['invoice_no'] ?: ('DEL-' . str_pad($d['id'], 5, '0', STR_PAD_LEFT));
            $d_date = $d['delivery_date'] ? date('M d, Y g:i A', strtotime($d['delivery_date'])) : '—';
            $d_status = strtolower($d['status']);
            $d_badge = ($d_status === 'verified' || $d_status === 'completed') ? '<span class="status-pill" style="background:#dcfce7;color:#15803d;border:1px solid #a7f3d0;">Verified</span>' : '<span class="status-pill" style="background:#fef3c7;color:#b45309;border:1px solid #fde68a;">'.ucfirst($d['status']).'</span>';
            $d_json = htmlspecialchars(json_encode([
                'delivery_no' => $d_no,
                'po_number'   => $d['po_number'],
                'supplier'    => $d['supplier'],
                'fuel_type'   => $d['fuel_type'],
                'liters'      => number_format((float)$d['delivery_liters'], 2) . ' L',
                'cost'        => '₱' . number_format((float)$d['cost_per_liter'], 2),
                'date'        => $d_date,
                'status'      => ucfirst($d['status']),
                'received_by' => $d['received_by_name'],
                'notes'       => $d['notes'] ?: '—'
            ]), ENT_QUOTES);
        ?>
        <tr class="del-row" data-search="<?= htmlspecialchars(strtolower($d_no . ' ' . $d['po_number'] . ' ' . $d['supplier'] . ' ' . $d['fuel_type'])) ?>">
          <td><code style="font-weight:700; color:#002F70;"><?= htmlspecialchars($d_no) ?></code></td>
          <td><code style="font-size:11px;"><?= htmlspecialchars($d['po_number']) ?></code></td>
          <td style="font-weight:600;"><?= htmlspecialchars($d['supplier']) ?></td>
          <td style="font-weight:700; color:#334155;"><?= htmlspecialchars(get_canonical_fuel_name($d['fuel_type'])) ?></td>
          <td style="text-align:right; font-weight:700; color:#16a34a;"><?= number_format((float)$d['delivery_liters'], 2) ?> L</td>
          <td style="color:#64748b; font-size:11px;"><?= $d_date ?></td>
          <td style="text-align:center;"><?= $d_badge ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div id="adminFuelDelPagination" style="padding:8px 16px;"></div>
</div>
<?php endif; ?>

<?php if ($active_tab === 'sales'): ?>
<!-- ── DAILY FUEL SALES TAB ── -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px;">
  <div style="padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#f8fafc;">
    <div style="font-weight:700; color:#002F70; font-size:14px; text-transform:uppercase; display:flex; align-items:center; gap:8px;">
      <i class="fas fa-chart-line"></i> Daily Fuel Sales
    </div>
    <input type="text" id="salesSearchInput" placeholder="Search fuel type, date..." oninput="filterSalesTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; width:200px; outline:none;">
  </div>
  <div class="aif-wrap">
    <table class="aif-tbl" id="adminFuelSalesTable">
      <thead>
        <tr>
          <th style="text-align:center;">Date</th>
          <th>Fuel Type</th>
          <th style="text-align:right;">Liters Sold</th>
          <th style="text-align:right;">Sales Amount</th>
        </tr>
      </thead>
      <tbody id="adminFuelSalesBody">
      <?php if (empty($daily_fuel_sales)): ?>
        <tr><td colspan="4" style="text-align:center; padding:32px; color:#6c757d;"><i class="fas fa-chart-line" style="font-size:24px; display:block; margin-bottom:8px;"></i>No daily fuel sales records found.</td></tr>
      <?php else: ?>
        <?php foreach ($daily_fuel_sales as $ds):
            $ds_date = $ds['date'] ? date('M d, Y', strtotime($ds['date'])) : '—';
        ?>
        <tr class="sales-row" data-search="<?= strtolower(htmlspecialchars($ds['fuel_type'] . ' ' . $ds_date)) ?>">
          <td style="text-align:center; color:#475569; font-size:11px;"><?= $ds_date ?></td>
          <td style="font-weight:700; color:#002F70;"><?= htmlspecialchars(get_canonical_fuel_name($ds['fuel_type'])) ?></td>
          <td style="text-align:right; font-weight:700; color:#0284c7;"><?= number_format((float)$ds['liters_sold'], 2) ?> L</td>
          <td style="text-align:right; font-weight:700; color:#16a34a;">₱<?= number_format((float)$ds['sales_amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div id="adminFuelSalesPagination" style="padding:8px 16px;"></div>
</div>
<?php endif; ?>

<?php if ($active_tab === 'remaining'): ?>
<!-- ── REMAINING FUEL VOLUME TAB ── -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px;">
  <div style="padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; background:#f8fafc;">
    <div style="font-weight:700; color:#002F70; font-size:14px; text-transform:uppercase; display:flex; align-items:center; gap:8px;">
      <i class="fas fa-tachometer-alt"></i> Remaining Fuel Volume Summary
    </div>
  </div>
  <div class="aif-wrap">
    <table class="aif-tbl" id="adminFuelRemTable">
      <thead>
        <tr>
          <th>Fuel Type</th>
          <th style="text-align:right;">Beginning</th>
          <th style="text-align:right;">Delivered</th>
          <th style="text-align:right;">Dispensed</th>
          <th style="text-align:right;">Calibration</th>
          <th style="text-align:right;">Remaining</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($remaining_fuel_volume)): ?>
        <tr><td colspan="6" style="text-align:center; padding:32px; color:#6c757d;">No fuel volume data found.</td></tr>
      <?php else: ?>
        <?php foreach ($remaining_fuel_volume as $rv): ?>
        <tr>
          <td style="font-weight:700; color:#002F70;"><?= htmlspecialchars($rv['fuel_type']) ?></td>
          <td style="text-align:right; font-weight:600; color:#475569;"><?= number_format((float)$rv['beginning'], 2) ?> L</td>
          <td style="text-align:right; font-weight:700; color:#16a34a;">+<?= number_format((float)$rv['delivered'], 2) ?> L</td>
          <td style="text-align:right; font-weight:700; color:#dc2626;">-<?= number_format((float)$rv['dispensed'], 2) ?> L</td>
          <td style="text-align:right; font-weight:600; color:#2563eb;"><?= number_format((float)$rv['calibration'], 2) ?> L</td>
          <td style="text-align:right; font-weight:800; color:#002F70; font-size:14px;"><?= number_format((float)$rv['remaining'], 2) ?> L</td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ══ VIEW FUEL DETAILS MODAL (SUB-TABBED) ══ -->
<div class="modal-overlay" id="tankModal" style="z-index:10000;">
    <div style="background:#fff; border-radius:14px; width:96%; max-width:850px; max-height:92vh; display:flex; flex-direction:column; box-shadow:0 24px 40px rgba(0,0,0,.18); overflow:hidden; position:relative;">
        <!-- Header -->
        <div style="padding:16px 22px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#f8fafc; flex-shrink:0;">
            <div style="font-size:15px; font-weight:800; color:#002F70; text-transform:uppercase; letter-spacing:.4px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-gas-pump"></i> <span id="vfmTitle">View Fuel Details</span>
            </div>
            <button type="button" onclick="closeTankModal()" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
        </div>
        <!-- Sub-tabs -->
        <div style="display:flex; border-bottom:2px solid #e2e8f0; background:#f8fafc; flex-shrink:0; padding:0 16px; overflow-x:auto; white-space:nowrap; gap:4px;">
            <button type="button" class="modal-tab-btn active" id="vfmTab1" onclick="vfmSwitchTab(1)"><i class="fas fa-info-circle"></i> Fuel Information</button>
            <button type="button" class="modal-tab-btn" id="vfmTab2" onclick="vfmSwitchTab(2)"><i class="fas fa-chart-pie"></i> Fuel Summary</button>
            <button type="button" class="modal-tab-btn" id="vfmTab3" onclick="vfmSwitchTab(3)"><i class="fas fa-truck-loading"></i> Stock-In History</button>
            <button type="button" class="modal-tab-btn" id="vfmTab4" onclick="vfmSwitchTab(4)"><i class="fas fa-exchange-alt"></i> Stock Movement History</button>
        </div>
        <!-- Body -->
        <div style="overflow-y:auto; flex:1; padding:22px;" id="vfmBody">
            <!-- SUB-TAB 1: Fuel Information -->
            <div id="vfmPane1">
                <div style="font-size:11px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; padding-bottom:6px; border-bottom:2px solid #e9ecef;"><i class="fas fa-info-circle"></i> Fuel Details</div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px 24px; margin-bottom:20px;">
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Fuel Type</div><div id="vfmFuelType" style="font-weight:800; color:#002F70; font-size:16px;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Current Volume</div><div id="vfmVolume" style="font-weight:800; color:#16a34a; font-size:18px;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Storage Capacity</div><div id="vfmCapacity" style="font-weight:600; color:#475569;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Reorder Level</div><div id="vfmReorder" style="font-weight:600; color:#d97706;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Critical Level</div><div id="vfmCritical" style="font-weight:600; color:#dc2626;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Status</div><div id="vfmStatus"></div></div>
                    <div style="grid-column:span 2;"><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Last Updated</div><div id="vfmUpdated" style="font-weight:600; color:#64748b;"></div></div>
                </div>
            </div>
            <!-- SUB-TAB 2: Fuel Summary -->
            <div id="vfmPane2" style="display:none;">
                <div style="font-size:11px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; padding-bottom:6px; border-bottom:2px solid #e9ecef;"><i class="fas fa-chart-pie"></i> Daily Fuel Summary &amp; Balances</div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px 24px; margin-bottom:20px;">
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Beginning Volume</div><div id="vfmBeginning" style="font-weight:700; color:#475569; font-size:15px;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Delivered Volume</div><div id="vfmDelivered" style="font-weight:700; color:#16a34a; font-size:15px;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Dispensed Volume</div><div id="vfmDispensed" style="font-weight:700; color:#dc2626; font-size:15px;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Calibration</div><div id="vfmCalibration" style="font-weight:700; color:#2563eb; font-size:15px;"></div></div>
                    <div style="grid-column:span 2;"><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Ending Volume</div><div id="vfmEnding" style="font-weight:800; color:#002F70; font-size:18px;"></div></div>
                </div>
            </div>
            <!-- SUB-TAB 3: Delivery History -->
            <div id="vfmPane3" style="display:none;">
                <div style="font-size:11px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; padding-bottom:6px; border-bottom:2px solid #e9ecef;"><i class="fas fa-truck-loading"></i> Recent Fuel Deliveries</div>
                <div id="vfmDeliveriesTable"><div style="text-align:center; padding:24px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading deliveries...</div></div>
            </div>
            <!-- SUB-TAB 4: Fuel Transaction History -->
            <div id="vfmPane4" style="display:none;">
                <div style="font-size:11px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; padding-bottom:6px; border-bottom:2px solid #e9ecef;"><i class="fas fa-history"></i> Recent Fuel Transactions</div>
                <div id="vfmTransactionsTable"><div style="text-align:center; padding:24px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading transactions...</div></div>
            </div>
        </div>
        <!-- Footer -->
        <div style="padding:12px 22px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px; background:#f8fafc; flex-shrink:0;">
            <button type="button" onclick="closeTankModal()" class="int-btn-outline" style="border-color:#6b7280 !important; color:#6b7280 !important;"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- ══ VIEW DELIVERY DETAIL MODAL ══ -->
<div class="modal-overlay" id="delDetailModal" style="z-index:10005;">
    <div style="background:#fff; border-radius:14px; width:96%; max-width:540px; box-shadow:0 24px 40px rgba(0,0,0,.22); overflow:hidden; position:relative;">
        <div style="padding:16px 22px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#f8fafc;">
            <div style="font-size:15px; font-weight:800; color:#002F70; text-transform:uppercase; letter-spacing:.4px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-truck-loading"></i> Fuel Delivery Details
            </div>
            <button type="button" onclick="closeDelViewModal()" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
        </div>
        <div style="padding:22px; font-size:13px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Delivery No.</div><div id="vdmNo" style="font-weight:800; color:#002F70; font-size:15px;"></div></div>
                <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">PO Number</div><div id="vdmPo" style="font-weight:700; color:#0f172a;"></div></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Supplier</div><div id="vdmSupplier" style="font-weight:600; color:#334155;"></div></div>
                <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Fuel Type</div><div id="vdmFuelType" style="font-weight:700; color:#002F70;"></div></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:12px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Liters Received</div><div id="vdmLiters" style="font-weight:800; color:#16a34a; font-size:15px;"></div></div>
                <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Cost / Liter</div><div id="vdmCost" style="font-weight:700; color:#475569;"></div></div>
                <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Status</div><div id="vdmStatus"></div></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Delivery Date</div><div id="vdmDate" style="font-weight:600; color:#334155;"></div></div>
                <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Received By</div><div id="vdmBy" style="font-weight:600; color:#334155;"></div></div>
            </div>
            <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Notes</div><div id="vdmNotes" style="color:#64748b; font-style:italic;"></div></div>
        </div>
        <div style="padding:12px 22px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; background:#f8fafc;">
            <button type="button" onclick="closeDelViewModal()" class="int-btn-outline" style="border-color:#6b7280 !important; color:#6b7280 !important;"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<script>
var _adminCurrentFuel = null;

function viewTankDetails(r) {
    _adminCurrentFuel = r;
    var overlay = document.getElementById('tankModal');
    if (!overlay) return;
    if (overlay.parentNode !== document.body) {
        document.body.appendChild(overlay);
    }

    document.getElementById('vfmTitle').textContent = 'View Fuel Details — ' + (r.fuel_type || '') + ' (UGT #' + (r.tanker_num || '') + ')';
    document.getElementById('vfmFuelType').textContent = r.fuel_type || '—';
    document.getElementById('vfmVolume').textContent = Number(r.current_level || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
    document.getElementById('vfmCapacity').textContent = Number(r.capacity || 0).toLocaleString() + ' L';
    document.getElementById('vfmReorder').textContent = Number(r.reorder_level || 0).toLocaleString() + ' L';
    document.getElementById('vfmCritical').textContent = Number(r.critical_level || 0).toLocaleString() + ' L';

    var statusMap = {
        'Normal': '<span class="status-pill" style="background:#dcfce7;color:#15803d;border:1px solid #a7f3d0;">Normal</span>',
        'Low': '<span class="status-pill" style="background:#fff3cd;color:#fd7e14;border:1px solid #ffeba8;">Low</span>',
        'Critical': '<span class="status-pill" style="background:#fce8e6;color:#dc3545;border:1px solid #f8c2bc;">Critical</span>',
        'Out of Stock': '<span class="status-pill" style="background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;">Out of Stock</span>'
    };
    document.getElementById('vfmStatus').innerHTML = statusMap[r.status] || '<span class="status-pill" style="background:#f1f5f9;color:#475569;">' + esc(r.status) + '</span>';
    document.getElementById('vfmUpdated').textContent = r.timestamp ? new Date(r.timestamp).toLocaleString() : '—';

    // Sub-tab 2 Fuel Summary
    document.getElementById('vfmBeginning').textContent = Number(r.beginning || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L';
    document.getElementById('vfmDelivered').textContent = Number(r.purchases || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L';
    document.getElementById('vfmDispensed').textContent = Number(r.sales || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L';
    document.getElementById('vfmCalibration').textContent = Number(r.calibration || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L';
    document.getElementById('vfmEnding').textContent = Number(r.ending_system || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L';

    // Reset sub-tabs to 1
    vfmSwitchTab(1);

    // Placeholders for async tables
    document.getElementById('vfmDeliveriesTable').innerHTML = '<div style="text-align:center;padding:16px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading deliveries...</div>';
    document.getElementById('vfmTransactionsTable').innerHTML = '<div style="text-align:center;padding:16px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading transactions...</div>';

    // Show Modal
    overlay.classList.add('open');
    overlay.style.display = 'flex';
    overlay.style.position = 'fixed';
    overlay.style.top = '0'; overlay.style.left = '0'; overlay.style.right = '0'; overlay.style.bottom = '0';
    overlay.style.zIndex = '10000';

    // Fetch AJAX activity
    fetch('manager_inventory_fuel.php?ajax=1&action=get_fuel_details&fuel_type=' + encodeURIComponent(r.fuel_type))
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.success) return;

        // Deliveries Table
        if (!data.deliveries || data.deliveries.length === 0) {
            document.getElementById('vfmDeliveriesTable').innerHTML = '<div style="text-align:center;padding:12px;color:#94a3b8;">No delivery records for this fuel type.</div>';
        } else {
            var dHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:#f8fafc;"><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Delivery No.</th><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Supplier</th><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Date</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Liters</th><th style="padding:8px;text-align:center;border-bottom:1px solid #e2e8f0;">Status</th></tr></thead><tbody>';
            data.deliveries.forEach(function(d) {
                dHtml += '<tr><td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;"><code style="font-weight:700;color:#002F70;">' + esc(d.invoice_no || '—') + '</code></td>';
                dHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;">' + esc(d.supplier || 'Petron') + '</td>';
                dHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;color:#64748b;">' + (d.delivery_date ? new Date(d.delivery_date).toLocaleDateString() : '—') + '</td>';
                dHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:#16a34a;">' + Number(d.delivery_liters).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</td>';
                dHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:center;"><span style="background:#dcfce7;color:#15803d;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;">' + esc(d.status || 'Verified') + '</span></td></tr>';
            });
            dHtml += '</tbody></table>';
            document.getElementById('vfmDeliveriesTable').innerHTML = dHtml;
        }

        // Transactions Table
        if (!data.transactions || data.transactions.length === 0) {
            document.getElementById('vfmTransactionsTable').innerHTML = '<div style="text-align:center;padding:12px;color:#94a3b8;">No transaction history for this fuel type.</div>';
        } else {
            var tHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:#f8fafc;"><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Date</th><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Shift</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Liters Sold</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Total Amount</th><th style="padding:8px;text-align:center;border-bottom:1px solid #e2e8f0;">Status</th></tr></thead><tbody>';
            data.transactions.forEach(function(t) {
                tHtml += '<tr><td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;color:#475569;">' + (t.transaction_date ? new Date(t.transaction_date).toLocaleString() : '—') + '</td>';
                tHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;">' + esc(t.shift_period || '—') + '</td>';
                tHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:#dc2626;">' + Number(t.liters_sold).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</td>';
                tHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:#002F70;">₱' + Number(t.total_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td>';
                tHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:center;"><span style="background:#dcfce7;color:#15803d;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;">' + esc(t.status || 'Verified') + '</span></td></tr>';
            });
            tHtml += '</tbody></table>';
            document.getElementById('vfmTransactionsTable').innerHTML = tHtml;
        }
    })
    .catch(function() {
        document.getElementById('vfmDeliveriesTable').innerHTML = '<div style="text-align:center;color:#dc3545;padding:12px;">Connection error loading deliveries.</div>';
        document.getElementById('vfmTransactionsTable').innerHTML = '<div style="text-align:center;color:#dc3545;padding:12px;">Connection error loading transactions.</div>';
    });
}

function esc(str) {
    if (!str) return '';
    return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function closeTankModal() {
    var overlay = document.getElementById('tankModal');
    if (overlay) {
        overlay.classList.remove('open');
        overlay.style.display = 'none';
    }
}

function vfmSwitchTab(tabNum) {
    for (var i = 1; i <= 4; i++) {
        var pane = document.getElementById('vfmPane' + i);
        var btn = document.getElementById('vfmTab' + i);
        if (pane) pane.style.display = (i === tabNum) ? 'block' : 'none';
        if (btn) {
            btn.style.background = 'transparent';
            btn.style.backgroundColor = 'transparent';
            if (i === tabNum) {
                btn.classList.add('active');
                btn.style.borderBottom = '2px solid #002F70';
                btn.style.color = '#002F70';
                btn.style.fontWeight = '700';
            } else {
                btn.classList.remove('active');
                btn.style.borderBottom = '2px solid transparent';
                btn.style.color = '#64748b';
                btn.style.fontWeight = '600';
            }
        }
    }
}

function printAdminFuelDetails() {
    var r = _adminCurrentFuel;
    if (!r) return;
    var pw = window.open('', '_blank');
    pw.document.write('<!DOCTYPE html><html><head><title>Fuel Details — ' + (r.fuel_type || '') + '</title>');
    pw.document.write('<style>body{font-family:Arial,sans-serif;font-size:13px;color:#222;margin:0;padding:24px;}.header{background:#002F6C;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;}.header h2{margin:0;font-size:16px;}.section{border:1px solid #e2e8f0;border-top:none;padding:16px 20px;margin-bottom:12px;}.section h4{margin:0 0 10px;color:#002F6C;font-size:11px;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}table.info{width:100%;border-collapse:collapse;font-size:12px;}table.info td{padding:5px 0;border-bottom:1px solid #f1f5f9;}table.info td:first-child{color:#64748b;font-weight:600;width:180px;}.footer{text-align:center;font-size:10px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}</style></head><body>');
    pw.document.write('<div class="header"><h2>Fuel Details Slip — ' + (r.fuel_type || '') + ' (UGT #' + (r.tanker_num || '') + ')</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
    pw.document.write('<div class="section"><h4>Fuel Information</h4><table class="info">');
    pw.document.write('<tr><td>UGT Tank:</td><td><strong>UGT #' + (r.tanker_num || '—') + ' (' + (r.tank_name || '') + ')</strong></td></tr>');
    pw.document.write('<tr><td>Fuel Type:</td><td><strong>' + (r.fuel_type || '—') + '</strong></td></tr>');
    pw.document.write('<tr><td>Current Volume:</td><td><strong style="color:#002F70;">' + Number(r.current_level || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</strong></td></tr>');
    pw.document.write('<tr><td>Storage Capacity:</td><td>' + Number(r.capacity || 0).toLocaleString() + ' L</td></tr>');
    pw.document.write('<tr><td>Reorder Level:</td><td>' + Number(r.reorder_level || 0).toLocaleString() + ' L</td></tr>');
    pw.document.write('<tr><td>Critical Level:</td><td>' + Number(r.critical_level || 0).toLocaleString() + ' L</td></tr>');
    pw.document.write('<tr><td>Selling Price/Liter:</td><td><strong style="color:#16a34a;">₱' + Number(r.price || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</strong></td></tr>');
    pw.document.write('</table></div>');
    pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    pw.document.write('</body></html>');
    pw.document.close();
    setTimeout(function() { pw.print(); }, 300);
}

function openDelViewModal(d) {
    var modal = document.getElementById('delDetailModal');
    if (!modal) return;
    document.getElementById('vdmNo').textContent = d.delivery_no;
    document.getElementById('vdmPo').textContent = d.po_number;
    document.getElementById('vdmSupplier').textContent = d.supplier;
    document.getElementById('vdmFuelType').textContent = d.fuel_type;
    document.getElementById('vdmLiters').textContent = d.liters;
    document.getElementById('vdmCost').textContent = d.cost;
    document.getElementById('vdmStatus').innerHTML = '<span style="background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;">' + esc(d.status) + '</span>';
    document.getElementById('vdmDate').textContent = d.date;
    document.getElementById('vdmBy').textContent = d.received_by;
    document.getElementById('vdmNotes').textContent = d.notes;

    modal.classList.add('open');
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.top = '0'; modal.style.left = '0'; modal.style.right = '0'; modal.style.bottom = '0';
    modal.style.zIndex = '10005';
}

function closeDelViewModal() {
    var modal = document.getElementById('delDetailModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function filterFuelTable() {
    var search = (document.getElementById('sq') || {}).value || '';
    var fuelType = (document.getElementById('cf') || {}).value || '';
    var status = (document.getElementById('sf') || {}).value || '';
    search = search.toLowerCase().trim();
    fuelType = fuelType.toLowerCase().trim();
    status = status.toLowerCase().trim();

    var rows = document.querySelectorAll('#adminFuelInvTable tbody tr');
    rows.forEach(function(row) {
        if (row.querySelector('td[colspan]')) return;
        var match = true;
        var rTankNum = (row.dataset.tankNum || '').toLowerCase();
        var rFuelType = (row.dataset.fuelType || '').toLowerCase();
        var rStatus = (row.dataset.status || '').toLowerCase();

        if (search && rTankNum.indexOf(search) === -1 && rFuelType.indexOf(search) === -1) match = false;
        if (fuelType && rFuelType.indexOf(fuelType) === -1) match = false;
        if (status) {
            if (status === 'normal') {
                if (rStatus !== 'normal') match = false;
            } else if (status === 'low' || status === 'critical' || status === 'out of stock' || status.indexOf('low') !== -1 || status.indexOf('critical') !== -1 || status.indexOf('out') !== -1) {
                if (rStatus !== 'low' && rStatus !== 'critical' && rStatus !== 'out of stock') {
                    match = false;
                }
            } else if (rStatus.indexOf(status) === -1) {
                match = false;
            }
        }
        row.style.display = match ? '' : 'none';
    });
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminFuelInvTable', 'adminFuelRowsLimit', 'adminFuelInvPagination', 20);
    }
}

function filterDeliveriesTable() {
    var q = (document.getElementById('delSearchInput') || {}).value || '';
    q = q.toLowerCase().trim();
    var rows = document.querySelectorAll('#adminFuelDelBody tr.del-row');
    rows.forEach(function(r) {
        var s = (r.dataset.search || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
}

function filterTxTable() {
    var q = (document.getElementById('txSearchInput') || {}).value || '';
    q = q.toLowerCase().trim();
    var rows = document.querySelectorAll('#adminFuelTxBody tr.tx-row');
    rows.forEach(function(r) {
        var s = (r.dataset.search || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
}

function filterSalesTable() {
    var q = (document.getElementById('salesSearchInput') || {}).value || '';
    q = q.toLowerCase().trim();
    document.querySelectorAll('#adminFuelSalesTable .sales-row').forEach(function(r) {
        var s = (r.getAttribute('data-search') || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
}

// Modal dismissal on click outside
document.querySelectorAll('.modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('open');
            this.style.display = 'none';
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminFuelInvTable','adminFuelRowsLimit','adminFuelInvPagination',20);
        setupTablePagination('adminFuelDelTable','adminFuelDelRowsLimit','adminFuelDelPagination',20);
        setupTablePagination('adminFuelTxTable','adminFuelTxRowsLimit','adminFuelTxPagination',20);
        setupTablePagination('adminFuelSalesTable','adminFuelSalesRowsLimit','adminFuelSalesPagination',20);
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
