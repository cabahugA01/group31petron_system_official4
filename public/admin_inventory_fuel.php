<?php
$page_id = 'admin_inventory_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

// â”€â”€ Module gate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['admin','superadmin'])) { header('Location: dashboard.php'); exit; }
if ($station_id <= 0 && $role === 'admin') { render_no_station_page('admin_dashboard.php'); }
$can_correct_fuel = in_array($role, ['superadmin'], true);

// â”€â”€ AJAX Handler for Unified Recent Activity â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_fuel_details') {
    header('Content-Type: application/json');
    $fuel_type = trim($_GET['fuel_type'] ?? '');
    
    $deliveries = [];
    try {
        $stmt = $pdo->prepare("
            SELECT fd.id,
                   fd.delivery_date,
                   COALESCE(NULLIF(fd.invoice_no,''), CONCAT('DEL-', LPAD(fd.id, 5, '0'))) AS invoice_no,
                   fd.delivery_liters,
                   65.50 AS cost_per_liter,
                   COALESCE(NULLIF(fd.supplier,''), 'Petron Corporation') AS supplier,
                   COALESCE(fd.status, 'Verified') AS status
            FROM fuel_deliveries fd
            WHERE fd.station_id = ?
              AND LOWER(fd.fuel_type) LIKE LOWER(?)
            ORDER BY fd.delivery_date DESC, fd.id DESC LIMIT 20
        ");
        $stmt->execute([$station_id, '%' . strtolower(explode(' ', $fuel_type)[0]) . '%']);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $adjustments = [];
    try {
        $stmt = $pdo->prepare("
            SELECT fa.id,
                   fa.adjustment_date,
                   fa.previous_value,
                   fa.new_value,
                   fa.variance,
                   COALESCE(NULLIF(fa.reason,''), NULLIF(fa.notes,''), 'Routine Calibration') AS reason,
                   COALESCE(u.name, 'Manager') AS adjusted_by
            FROM fuel_adjustments fa
            LEFT JOIN users u ON fa.user_id = u.id
            WHERE fa.station_id = ?
              AND LOWER(fa.fuel_type) LIKE LOWER(?)
            ORDER BY fa.adjustment_date DESC, fa.id DESC LIMIT 20
        ");
        $stmt->execute([$station_id, '%' . strtolower(explode(' ', $fuel_type)[0]) . '%']);
        $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    echo json_encode([
        'success'     => true,
        'deliveries'  => $deliveries,
        'adjustments' => $adjustments
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

$TANK_CONFIG_17 = get_tank_config($station_id);

// ── DB lookups ────────────────────────────────────────────────────────
$fi_raw = [];
$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT id, fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, COALESCE(ugt_no, '') AS ugt_no FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$station_id]);
    $fi_raw = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fi_raw as $row) {
        $fuel_key = strtolower(trim($row['fuel_type']));
        $ugt_no   = strtolower(trim($row['ugt_no'] ?? ''));
        
        if (!isset($fi_lookup[$fuel_key])) {
            $fi_lookup[$fuel_key] = $row;
        }
        if ($ugt_no) {
            $fi_lookup[$fuel_key . '_tank_' . $ugt_no] = $row;
            $fi_lookup[$fuel_key . '_' . $ugt_no] = $row;
        }
    }
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

// ── Build 7 rows (Aligned with Manager and Staff Fuel Inventory) ──────
$rows = [];
foreach ($TANK_CONFIG_17 as $tc) {
    $tank_num = $tc['tanker_num'];
    $ugt_str  = 'UGT-' . str_pad($tank_num, 2, '0', STR_PAD_LEFT);
    $fuel_type_base = $tc['fuel_type'];
    $ft_key   = strtolower(trim($fuel_type_base));
    
    // Smart match: match by UGT number, tanker_num, or clean fuel_type
    $inv = null;
    foreach ($fi_raw as $r) {
        $r_ugt = strtolower(trim($r['ugt_no']));
        $r_ft  = strtolower(trim($r['fuel_type']));
        if (($r_ugt !== '' && ($r_ugt === strtolower($ugt_str) || strpos($r_ugt, '#' . $tank_num) !== false)) || 
            strpos($r_ft, '#' . $tank_num) !== false || 
            strpos($r_ft, 'ugt-' . str_pad($tank_num, 2, '0', STR_PAD_LEFT)) !== false) {
            $inv = $r;
            break;
        }
    }
    if (!$inv) {
        if ($ft_key === 'xtra unl' || $ft_key === 'xtr advance' || $ft_key === 'xtra advance') {
            $cand = '';
            if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'xtra unl 1'; }
            elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'xtra unl 2'; }
            if ($cand && isset($fi_lookup[$cand]) && (float)($fi_lookup[$cand]['current_level'] ?? 0) > 0) { 
                $ft_key = $cand; 
                $inv = $fi_lookup[$cand];
            } else { 
                $ft_key = 'xtra unl'; 
                $inv = $fi_lookup['xtra unl'] ?? null;
            }
        } elseif ($ft_key === 'diesel') {
            $cand = '';
            if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'diesel 1'; }
            elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'diesel 2'; }
            if ($cand && isset($fi_lookup[$cand]) && (float)($fi_lookup[$cand]['current_level'] ?? 0) > 0) { 
                $ft_key = $cand; 
                $inv = $fi_lookup[$cand];
            } else { 
                $ft_key = 'diesel'; 
                $inv = $fi_lookup['diesel'] ?? null;
            }
        } else {
            $inv = $fi_lookup[$ft_key] ?? null;
        }
    }

    if (!$inv || ((float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) <= 0)) {
        $base_key = strtolower(explode(' ', $tc['fuel_type'])[0]);
        if (isset($fi_lookup[$base_key]) && (float)($fi_lookup[$base_key]['current_level'] ?? $fi_lookup[$base_key]['current_stock'] ?? 0) > 0) {
            $inv = $fi_lookup[$base_key];
        }
    }
    
    $capacity  = (float)$tc['capacity'];
    $raw_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
    $cur_level = $raw_level > 0 ? min($raw_level, $capacity) : 0;
    
    $same_n = count(array_filter($TANK_CONFIG_17, function($t) use ($ft_key, $fi_lookup) {
        $k = strtolower(trim($t['fuel_type']));
        if ($k === 'xtra unl' || $k === 'xtr advance' || $k === 'xtra advance') {
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
    
    $tank_key  = strtolower($ugt_str);
    $purchases = $del_lookup[$tank_key] ?? 0;
    
    $sales_total = $sales_lookup[$ft_key] ?? 0;
    $adj_total   = $adj_lookup[$ft_key] ?? 0;
    $sales       = $same_n > 0 ? round($sales_total / $same_n, 2) : 0;
    $calibration = $same_n > 0 ? round($adj_total / $same_n, 2) : 0;
    
    $beginning   = $same_n > 0 ? round($cur_level / $same_n, 2) : 0;
    $total_avail = $beginning + $purchases;
    
    $ending      = min(max(0, $total_avail - $sales - $calibration), $capacity);
    $remaining_capacity = max(0, $capacity - $ending);
    $actual_dip  = $ending;
    $variance    = 0;
    
    // Thresholds — from DB tank config (reorder_level / critical_level)
    $critical_lvl = (float)($tc['critical_level'] ?? 0);
    $low_lvl      = (float)($tc['reorder_level']  ?? 0);
    if ($critical_lvl <= 0) $critical_lvl = $capacity > 0 ? $capacity * 0.15 : 0;
    if ($low_lvl <= 0)      $low_lvl      = $capacity > 0 ? $capacity * 0.30 : 0;
    
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
    
    $ugt_formatted = 'UGT-' . str_pad($tc['tanker_num'], 2, '0', STR_PAD_LEFT);

    $rows[]  = [
        'fuel_id'        => $inv['id'] ?? 0,
        'ugt_no'         => $ugt_formatted,
        'fuel_type'      => $tc['fuel_type'],
        'label'          => $tc['label'],
        'tank'           => $tc['tank'],
        'tank_name'      => $ugt_formatted,
        'tank_label'     => $tc['label'],
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
        'available_space'=> $remaining_capacity,
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

// ── Active Tab ────────────────────────────────────────────────────────
$active_tab = trim($_GET['tab'] ?? 'overview');
if (!in_array($active_tab, ['overview', 'movement', 'adjustments', 'alerts'], true)) {
    $active_tab = 'overview';
}

// ── Fetch Fuel Movement History (Deliveries, Sales, Adjustments) ──────
$fuel_movement_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            fd.delivery_date AS date,
            fd.fuel_type,
            COALESCE(NULLIF(fd.invoice_no,''), CONCAT('FDEL-', LPAD(fd.id, 5, '0'))) AS ref_no,
            'Delivery (IN)' AS movement_type,
            fd.delivery_liters AS inflow,
            0 AS outflow,
            fd.delivery_liters AS net_change,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Staff') AS user_name,
            COALESCE(NULLIF(fd.supplier,''), 'Petron Corporation') AS remarks
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        WHERE fd.station_id = ?
        ORDER BY fd.delivery_date DESC LIMIT 150
    ");
    $stmt->execute([$station_id]);
    $fuel_movement_history = array_merge($fuel_movement_history, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT
            ft.transaction_date AS date,
            ft.fuel_type,
            COALESCE(NULLIF(ft.transaction_id,''), CONCAT('FTRX-', LPAD(ft.id, 5, '0'))) AS ref_no,
            'Dispensed / Sales (OUT)' AS movement_type,
            0 AS inflow,
            ft.liters_sold AS outflow,
            -1 * ft.liters_sold AS net_change,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Pump Attendant') AS user_name,
            CONCAT('Pump ', COALESCE(ft.pump_number, '1'), ' Shift Sales') AS remarks
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id
        WHERE ft.station_id = ?
          AND LOWER(COALESCE(ft.status,'')) NOT IN ('voided','cancelled','rejected')
        ORDER BY ft.transaction_date DESC LIMIT 150
    ");
    $stmt->execute([$station_id]);
    $fuel_movement_history = array_merge($fuel_movement_history, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT
            fa.adjustment_date AS date,
            fa.fuel_type,
            COALESCE(NULLIF(fa.reference_no,''), CONCAT('FADJ-', LPAD(fa.id, 5, '0'))) AS ref_no,
            'Calibration / Dip' AS movement_type,
            CASE WHEN fa.variance > 0 THEN fa.variance ELSE 0 END AS inflow,
            CASE WHEN fa.variance < 0 THEN ABS(fa.variance) ELSE 0 END AS outflow,
            fa.variance AS net_change,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Manager') AS user_name,
            COALESCE(NULLIF(fa.reason,''), NULLIF(fa.notes,''), 'Routine Calibration') AS remarks
        FROM fuel_adjustments fa
        LEFT JOIN users u ON fa.user_id = u.id
        WHERE fa.station_id = ?
        ORDER BY fa.adjustment_date DESC LIMIT 100
    ");
    $stmt->execute([$station_id]);
    $fuel_movement_history = array_merge($fuel_movement_history, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

usort($fuel_movement_history, function($a, $b) {
    return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
});

// â”€â”€ Fetch Fuel Deliveries History â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$fuel_deliveries_list = [];
try {
    $stmt = $pdo->prepare("
        SELECT fd.id,
               CONCAT('DEL-', LPAD(fd.id, 5, '0')) AS delivery_no,
               COALESCE(NULLIF(fd.invoice_no,''), fd.po_number, '—') AS po_number,
               COALESCE(NULLIF(fd.supplier,''), 'Petron Corporation') AS supplier,
               COALESCE(fd.fuel_type, 'Diesel') AS fuel_type,
               fd.delivery_liters AS liters,
               COALESCE(fd.cost_per_liter, 65.50) AS unit_cost,
               fd.delivery_date AS date,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Staff') AS received_by_name,
               COALESCE(fd.status, 'Verified') AS status
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        WHERE fd.station_id = ?
        ORDER BY fd.delivery_date DESC, fd.id DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $fuel_deliveries_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// â”€â”€ Fetch Fuel Adjustment History â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$fuel_adjustments_list = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            CONCAT('ADJ-', LPAD(fa.id, 5, '0')) AS adjustment_no,
            fa.id,
            fa.adjustment_date AS date,
            COALESCE(NULLIF(fa.fuel_type,''), fi.fuel_type, ft.name, 'Diesel') AS fuel_type,
            COALESCE(NULLIF(fa.ugt_no,''), NULLIF(fi.ugt_no,''), 'UGT-01') AS ugt_no,
            COALESCE(fa.adjustment_type, 'Physical Count / Tank Dip') AS adjustment_type,
            fa.liters,
            COALESCE(fa.adjustment_direction, IF(fa.variance >= 0, 'Increase', 'Decrease')) AS adjustment_direction,
            fa.previous_value AS previous_reading,
            fa.new_value AS new_reading,
            COALESCE(fa.variance, (fa.new_value - fa.previous_value)) AS variance,
            COALESCE(NULLIF(fa.reason,''), NULLIF(fa.notes,''), 'Routine Calibration') AS reason,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Manager') AS adjusted_by,
            COALESCE(fa.status, 'Approved') AS status
        FROM fuel_adjustments fa
        LEFT JOIN fuel_inventory fi ON (fa.fuel_type_id = fi.fuel_type_id OR LOWER(fa.fuel_type) = LOWER(fi.fuel_type))
        LEFT JOIN fuel_types ft ON fa.fuel_type_id = ft.id
        LEFT JOIN users u ON fa.user_id = u.id
        WHERE fa.station_id = ?
        GROUP BY fa.id
        ORDER BY CASE WHEN LOWER(COALESCE(fa.status,'')) LIKE '%pending%' THEN 0 ELSE 1 END, fa.adjustment_date DESC, fa.id DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $fuel_adjustments_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}


include __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>
<style>
.main-content {
    padding: 0 !important;
    box-sizing: border-box;
    width: 100%;
}
/* == PAGE HEADER - Uniform standard across all modules == */
.int-head { display:flex; justify-content:space-between; gap:16px; align-items:center; margin-top:0 !important; margin-bottom:25px !important; padding:0 !important; border:none !important; width:100%; }
.int-head h1 { margin:0 !important; color:#002f70 !important; font-size:24px !important; font-weight:700 !important; text-transform:uppercase !important; letter-spacing:0.5px !important; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif !important; display:flex !important; align-items:center !important; gap:10px !important; line-height:1.2 !important; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }

/* â”€â”€ Tab Navigation â”€â”€ */
/* ── Tab Navigation - Reports-style boxed design ── */
.tab-nav {
    display: flex !important; flex-wrap: wrap !important;
    margin-bottom: 22px !important;
    border: 1px solid #d1d9e6 !important; border-radius: 0 !important;
    overflow: hidden !important; border-bottom: 3px solid #00264D !important;
    gap: 0 !important; background: transparent !important;
    padding: 0 !important; width: 100% !important;
}
.tab-btn {
    flex: 1 !important; min-width: 140px !important;
    padding: 12px 16px !important; font-size: 11.5px !important; font-weight: 700 !important;
    color: #334155 !important; background: #ffffff !important;
    border: none !important; border-right: 1px solid #d1d9e6 !important;
    border-radius: 0 !important; text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important; align-items: center !important;
    justify-content: center !important; gap: 7px !important;
    text-transform: uppercase !important; letter-spacing: 0.3px !important;
    text-align: center !important; cursor: pointer !important;
    margin-bottom: 0 !important; box-shadow: none !important; white-space: nowrap;
}
.tab-btn:last-child { border-right: none !important; }
.tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.tab-btn.active {
    background: #00264D !important; color: #ffffff !important;
    font-weight: 800 !important; box-shadow: none !important;
    border-bottom-color: transparent !important;
}

/* â”€â”€ Modal Tab Button Overrides â”€â”€ */
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
.flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-csv { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-csv:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }

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
</style>

<div class="main-content">
<div class="int-head">
  <div>
    <h1><i class="fas fa-gas-pump"></i> Fuel Inventory Management</h1>
  </div>
</div>

<!-- â•â• Dashboard Cards (5 Cards) â•â• -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:24px;">
    <!-- Card 1: Total Fuel Available -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Fuel Available (L)</div>
            <div style="font-size:20px; font-weight:800; color:#002F70; margin-top:4px;"><?= number_format($total_fuel_available, 2) ?> L</div>
        </div>
        <div style="background:#e0f2fe; color:#002F70; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-gas-pump"></i></div>
    </div>
    <!-- Card 2: Total UGT Tanks -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total UGT Tanks</div>
            <div style="font-size:20px; font-weight:800; color:#0f172a; margin-top:4px;"><?= count($rows) ?></div>
        </div>
        <div style="background:#f1f5f9; color:#475569; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-database"></i></div>
    </div>
    <!-- Card 3: Low Fuel Tanks -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#ea580c; text-transform:uppercase; letter-spacing:.3px;">Low Fuel Tanks</div>
            <div style="font-size:20px; font-weight:800; color:#ea580c; margin-top:4px;"><?= number_format($total_low_fuel_tanks) ?></div>
        </div>
        <div style="background:#fff3cd; color:#ea580c; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <!-- Card 4: Critical Fuel Tanks -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#dc2626; text-transform:uppercase; letter-spacing:.3px;">Critical Fuel Tanks</div>
            <div style="font-size:20px; font-weight:800; color:#dc2626; margin-top:4px;"><?= number_format($total_critical_fuel_tanks) ?></div>
        </div>
        <div style="background:#fce8e6; color:#dc2626; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-fire"></i></div>
    </div>
    <!-- Card 5: Total Fuel Inventory Value -->
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Inventory Value</div>
            <div style="font-size:20px; font-weight:800; color:#16a34a; margin-top:4px;">₱<?= number_format($total_fuel_value, 2) ?></div>
        </div>
        <div style="background:#dcfce7; color:#16a34a; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px;"><i class="fas fa-coins"></i></div>
    </div>
</div>

<!-- ══ Tab Navigation (4 Tabs) ══ -->
<div class="tab-nav">
    <a href="admin_inventory_fuel.php?tab=overview" class="tab-btn <?= $active_tab === 'overview' ? 'active' : '' ?>">
        <i class="fas fa-gas-pump"></i> Fuel Inventory Overview
    </a>
    <a href="admin_inventory_fuel.php?tab=movement" class="tab-btn <?= $active_tab === 'movement' ? 'active' : '' ?>">
        <i class="fas fa-exchange-alt"></i> Stock Movement Monitoring
    </a>
    <a href="admin_inventory_fuel.php?tab=adjustments" class="tab-btn <?= $active_tab === 'adjustments' ? 'active' : '' ?>">
        <i class="fas fa-history"></i> Fuel Adjustment History
    </a>
    <a href="admin_inventory_fuel.php?tab=alerts" class="tab-btn <?= $active_tab === 'alerts' ? 'active' : '' ?>">
        <i class="fas fa-exclamation-triangle"></i> Stock Alerts
    </a>
</div>

<!-- ══ TAB 1: FUEL INVENTORY OVERVIEW ══ -->
<?php if ($active_tab === 'overview'): ?>
<!-- Search & Filters -->
<div class="inv-filter-bar" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:20px; background:#fff; padding:12px 16px; border:1px solid #e2e8f0; border-radius:8px;">
  <div style="position:relative;">
    <i class="fas fa-search" style="position:absolute; left:10px; top:11px; color:#94a3b8; font-size:12px;"></i>
    <input type="text" id="sq" placeholder="Search UGT No. / Fuel Type..." oninput="filterFuelTable()" style="padding:7px 10px 7px 28px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:220px; outline:none;">
  </div>
  <select id="cf" onchange="filterFuelTable()" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; outline:none; background:#fff;">
    <option value="">All Fuel Types</option>
    <option value="diesel">Diesel</option>
    <option value="kerosene">Kerosene</option>
    <option value="turbo diesel">Turbo Diesel</option>
    <option value="xcs">XCS</option>
    <option value="xtra advance">Xtra Advance</option>
  </select>
  <select id="sf" onchange="filterFuelTable()" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; outline:none; background:#fff;">
    <option value="">All Statuses</option>
    <option value="normal">Normal</option>
    <option value="low">Low</option>
    <option value="critical">Critical</option>
    <option value="out of stock">Out of Stock</option>
  </select>
  <button type="button" class="flt-btn flt-btn-csv" onclick="filterFuelTable()"><i class="fas fa-search"></i> Filter</button>
  <button type="button" class="flt-btn btn-cancel" onclick="resetAdminFuelFilters()"><i class="fas fa-rotate-left"></i> Reset</button>
</div>

<div style="background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px;">
  <div class="aif-wrap">
    <table class="aif-tbl" id="adminFuelInvTable">
      <thead>
        <tr>
          <th>UGT No.</th>
          <th>Fuel Type</th>
          <th style="text-align:right;">Capacity</th>
          <th style="text-align:right;">Current Volume</th>
          <th style="text-align:right;">Available Space</th>
          <th style="text-align:right;">Reorder Level</th>
          <th style="text-align:right;">Critical Level</th>
          <th style="text-align:center;">Status</th>
          <th>Last Updated</th>
          <th style="text-align:center;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10" style="text-align:center; padding:32px; color:#6c757d;">No fuel inventory data available.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r):
            $ts_str  = ($r['timestamp'] && strtotime($r['timestamp']) > 0) ? date('M d, Y h:i A', strtotime($r['timestamp'])) : '&mdash;';
            $ugt_no = 'UGT-' . str_pad($r['tanker_num'], 2, '0', STR_PAD_LEFT);
            $rem_cap = max(0, $r['capacity'] - $r['current_level']);
            $r_json = htmlspecialchars(json_encode(array_merge($r, ['ugt_no' => $ugt_no, 'available_space' => $rem_cap])), ENT_QUOTES);
        ?>
        <tr class="fuel-row"
            data-tank-num="<?= htmlspecialchars(strtolower($r['tanker_num'])) ?>"
            data-ugt-no="<?= htmlspecialchars(strtolower($ugt_no)) ?>"
            data-fuel-type="<?= htmlspecialchars(strtolower(get_canonical_fuel_name($r['fuel_type']))) ?>"
            data-status="<?= htmlspecialchars(strtolower($r['status'])) ?>">
          <td><code style="font-weight:700;color:#002F70;font-size:12px;"><?= htmlspecialchars($ugt_no) ?></code></td>
          <td style="font-weight:700;color:#0f172a;"><?= htmlspecialchars(get_canonical_fuel_name($r['fuel_type'])) ?></td>
          <td style="text-align:right; font-weight:600; color:#475569;"><?= number_format($r['capacity'], 0) ?> L</td>
          <td style="text-align:right; font-weight:800; color:#002F70;"><?= number_format($r['current_level'], 2) ?> L</td>
          <td style="text-align:right; font-weight:600; color:#16a34a;"><?= number_format($rem_cap, 2) ?> L</td>
          <td style="text-align:right; font-weight:600; color:#eab308;"><?= number_format($r['reorder_level'], 0) ?> L</td>
          <td style="text-align:right; font-weight:600; color:#dc2626;"><?= number_format($r['critical_level'], 0) ?> L</td>
          <td style="text-align:center;"><span class="status-pill" style="background:<?= $r['status_color'] ?>18; color:<?= $r['status_color'] ?>; border:1px solid <?= $r['status_color'] ?>40;"><?= htmlspecialchars($r['status']) ?></span></td>
          <td style="color:#64748b; font-size:11px;"><?= $ts_str ?></td>
          <td style="text-align:center;">
            <button type="button" class="int-btn-outline" 
                    data-fuel="<?= $r_json ?>"
                    data-ugt="<?= htmlspecialchars($ugt_no) ?>"
                    data-fuel-type="<?= htmlspecialchars(get_canonical_fuel_name($r['fuel_type'])) ?>"
                    data-capacity="<?= (float)$r['capacity'] ?>"
                    data-current-level="<?= (float)$r['current_level'] ?>"
                    data-available-space="<?= (float)$rem_cap ?>"
                    data-reorder="<?= (float)$r['reorder_level'] ?>"
                    data-critical="<?= (float)$r['critical_level'] ?>"
                    data-status="<?= htmlspecialchars($r['status']) ?>"
                    onclick="event.stopPropagation(); viewTankDetailsFromBtn(this)">
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
<?php endif; ?>

<!-- ══ TAB 2: FUEL STOCK MOVEMENT MONITORING ══ -->
<?php if ($active_tab === 'movement'): ?>
<?php
    $fmov_inflow = 0;
    $fmov_outflow = 0;
    foreach ($fuel_movement_history as $fm) {
        $fmov_inflow += (float)($fm['inflow'] ?? 0);
        $fmov_outflow += (float)($fm['outflow'] ?? 0);
    }
?>
<!-- Fuel Movement KPI Summary -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:20px;">
    <div style="background:#fff; border:1px solid #bbf7d0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:.3px;">Total Deliveries (Inflow)</div>
            <div style="font-size:22px; font-weight:800; color:#15803d; margin-top:4px;">+<?= number_format($fmov_inflow, 2) ?> L</div>
        </div>
        <div style="background:#dcfce7; color:#15803d; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-truck"></i></div>
    </div>
    <div style="background:#fff; border:1px solid #fed7aa; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#c2410c; text-transform:uppercase; letter-spacing:.3px;">Total Dispensed (Outflow)</div>
            <div style="font-size:22px; font-weight:800; color:#c2410c; margin-top:4px;">-<?= number_format($fmov_outflow, 2) ?> L</div>
        </div>
        <div style="background:#ffedd5; color:#c2410c; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-gas-pump"></i></div>
    </div>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.3px;">Net Movement Volume</div>
            <div style="font-size:22px; font-weight:800; color:<?= ($fmov_inflow - $fmov_outflow) >= 0 ? '#15803d' : '#dc2626' ?>; margin-top:4px;"><?= (($fmov_inflow - $fmov_outflow) >= 0 ? '+' : '') . number_format($fmov_inflow - $fmov_outflow, 2) ?> L</div>
        </div>
        <div style="background:#f0f4ff; color:#002F70; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-scale-balanced"></i></div>
    </div>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Movement Logs</div>
            <div style="font-size:22px; font-weight:800; color:#0f172a; margin-top:4px;"><?= count($fuel_movement_history) ?> Logs</div>
        </div>
        <div style="background:#f1f5f9; color:#475569; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-list-check"></i></div>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="inv-filter-bar" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:20px; background:#fff; padding:12px 16px; border:1px solid #e2e8f0; border-radius:8px;">
  <div style="position:relative;">
    <i class="fas fa-search" style="position:absolute; left:10px; top:11px; color:#94a3b8; font-size:12px;"></i>
    <input type="text" id="fmovSearch" placeholder="Search Ref, Type, Staff..." oninput="filterFuelMovementTable()" style="padding:7px 10px 7px 28px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:220px; outline:none;">
  </div>
  <select id="fmovTypeFilter" onchange="filterFuelMovementTable()" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; outline:none; background:#fff;">
    <option value="">All Fuel Types</option>
    <option value="diesel">Diesel</option>
    <option value="kerosene">Kerosene</option>
    <option value="turbo diesel">Turbo Diesel</option>
    <option value="xcs">XCS</option>
    <option value="xtra advance">Xtra Advance</option>
  </select>
  <select id="fmovMoveFilter" onchange="filterFuelMovementTable()" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; outline:none; background:#fff;">
    <option value="">All Movement Types</option>
    <option value="delivery">Delivery (IN)</option>
    <option value="dispensed">Dispensed / Sales (OUT)</option>
    <option value="calibration">Calibration / Dip (ADJ)</option>
  </select>
  <button type="button" class="flt-btn flt-btn-csv" onclick="filterFuelMovementTable()"><i class="fas fa-search"></i> Filter</button>
  <button type="button" class="flt-btn btn-cancel" onclick="resetFuelMovementFilters()"><i class="fas fa-rotate-left"></i> Reset</button>
</div>

<!-- Flat Fuel Movement Table -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px;">
  <div class="aif-wrap">
    <table class="aif-tbl" id="adminFuelMovementTable">
      <thead>
        <tr>
          <th>Reference No.</th>
          <th>Date &amp; Time</th>
          <th>Fuel Type</th>
          <th>Movement Type</th>
          <th style="text-align:right;">Inflow (IN)</th>
          <th style="text-align:right;">Outflow (OUT)</th>
          <th style="text-align:right;">Net Change</th>
          <th>Handled By</th>
          <th>Remarks / Notes</th>
        </tr>
      </thead>
      <tbody id="adminFuelMovementTbody">
      <?php if (empty($fuel_movement_history)): ?>
        <tr><td colspan="9" style="text-align:center; padding:32px; color:#6c757d;">No fuel stock movements recorded yet.</td></tr>
      <?php else: ?>
        <?php foreach ($fuel_movement_history as $fm):
            $mtype = $fm['movement_type'];
            $is_in = stripos($mtype, 'delivery') !== false || stripos($mtype, 'in') !== false;
            $is_out = stripos($mtype, 'dispensed') !== false || stripos($mtype, 'sales') !== false || stripos($mtype, 'out') !== false;
            $badge_bg = $is_in ? '#dcfce7' : ($is_out ? '#fee2e2' : '#fef3c7');
            $badge_color = $is_in ? '#15803d' : ($is_out ? '#b91c1c' : '#b45309');
            $net = (float)$fm['net_change'];
            $net_color = $net > 0 ? '#15803d' : ($net < 0 ? '#dc2626' : '#64748b');
        ?>
        <tr class="fmov-row"
            data-search="<?= strtolower(htmlspecialchars($fm['ref_no'] . ' ' . $fm['fuel_type'] . ' ' . $fm['movement_type'] . ' ' . $fm['user_name'] . ' ' . $fm['remarks'])) ?>"
            data-fuel-type="<?= strtolower(htmlspecialchars(get_canonical_fuel_name($fm['fuel_type']))) ?>"
            data-move-type="<?= strtolower(htmlspecialchars($fm['movement_type'])) ?>">
          <td><code style="font-weight:700;color:#002F70;"><?= htmlspecialchars($fm['ref_no']) ?></code></td>
          <td style="color:#64748b; font-size:11px; white-space:nowrap;"><?= date('M d, Y h:i A', strtotime($fm['date'])) ?></td>
          <td style="font-weight:700;color:#0f172a;"><?= htmlspecialchars(get_canonical_fuel_name($fm['fuel_type'])) ?></td>
          <td><span style="background:<?= $badge_bg ?>;color:<?= $badge_color ?>;padding:3px 8px;border-radius:12px;font-size:10.5px;font-weight:700;white-space:nowrap;"><?= htmlspecialchars($fm['movement_type']) ?></span></td>
          <td style="text-align:right; font-weight:700; color:#15803d;"><?= (float)$fm['inflow'] > 0 ? ('+' . number_format((float)$fm['inflow'], 2) . ' L') : '—' ?></td>
          <td style="text-align:right; font-weight:700; color:#dc2626;"><?= (float)$fm['outflow'] > 0 ? ('-' . number_format((float)$fm['outflow'], 2) . ' L') : '—' ?></td>
          <td style="text-align:right; font-weight:800; color:<?= $net_color ?>;"><?= ($net > 0 ? '+' : '') . number_format($net, 2) ?> L</td>
          <td style="font-size:11px; color:#334155;"><?= htmlspecialchars($fm['user_name']) ?></td>
          <td style="font-size:11px; color:#64748b; max-width:200px;"><?= htmlspecialchars($fm['remarks'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div id="adminFuelMovementPagination" style="padding:8px 16px;"></div>
</div>
<?php endif; ?>

<!-- ══ TAB 3: FUEL ADJUSTMENT HISTORY ══ -->
<?php if ($active_tab === 'adjustments'): ?>
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px;">
  <div style="padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#f8fafc;">
    <div style="font-weight:700; color:#002F70; font-size:14px; text-transform:uppercase; display:flex; align-items:center; gap:8px;">
      <i class="fas fa-history"></i> Fuel Adjustment History Log
    </div>
    <input type="text" id="adjSearchInput" placeholder="Search adjustment..." oninput="filterAdjTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; width:220px; outline:none;">
  </div>
  <div class="aif-wrap">
    <table class="aif-tbl" id="adminFuelAdjTable">
      <thead>
        <tr>
          <th>Adjustment No.</th>
          <th>UGT No.</th>
          <th>Fuel Type</th>
          <th>Adjustment Type</th>
          <th style="text-align:right;">System Vol</th>
          <th style="text-align:right;">Actual Dip</th>
          <th style="text-align:right;">Variance</th>
          <th>Reason</th>
          <th>Requested By</th>
          <th>Status</th>
          <th style="text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody id="adminFuelAdjBody">
      <?php if (empty($fuel_adjustments_list)): ?>
        <tr><td colspan="11" style="text-align:center; padding:32px; color:#6c757d;"><i class="fas fa-info-circle" style="font-size:24px; display:block; margin-bottom:8px;"></i>No fuel adjustment requests found.</td></tr>
      <?php else: ?>
        <?php foreach ($fuel_adjustments_list as $adj):
            $adate = $adj['date'] ? date('M d, Y h:i A', strtotime($adj['date'])) : '—';
            $var = (float)($adj['variance'] ?? 0);
            $var_color = $var > 0 ? '#16a34a' : ($var < 0 ? '#dc2626' : '#64748b');
            $st = strtolower(trim($adj['status'] ?? ''));
            $is_pending = strpos($st, 'pending') !== false;
            $st_badge = $is_pending ? '<span style="background:#fef3c7;color:#b45309;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700;"><i class="fas fa-clock"></i> Pending Admin Approval</span>'
                      : ($st === 'approved' ? '<span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700;"><i class="fas fa-check-circle"></i> Approved</span>'
                      : '<span style="background:#fee2e2;color:#b91c1c;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700;"><i class="fas fa-times-circle"></i> Rejected</span>');
        ?>
        <tr class="adj-row" data-search="<?= strtolower(htmlspecialchars($adj['adjustment_no'] . ' ' . $adj['fuel_type'] . ' ' . $adj['reason'])) ?>">
          <td><code style="font-weight:700; color:#002F70;"><?= htmlspecialchars($adj['adjustment_no']) ?></code></td>
          <td><code style="font-weight:700; color:#002F70;"><?= htmlspecialchars($adj['ugt_no']) ?></code></td>
          <td style="font-weight:700; color:#0f172a;"><?= htmlspecialchars(get_canonical_fuel_name($adj['fuel_type'])) ?></td>
          <td style="font-size:11px; font-weight:600; color:#475569;"><?= htmlspecialchars($adj['adjustment_type']) ?></td>
          <td style="text-align:right; font-weight:600; color:#475569;"><?= number_format((float)$adj['previous_reading'], 2) ?> L</td>
          <td style="text-align:right; font-weight:700; color:#002F70;"><?= number_format((float)$adj['new_reading'], 2) ?> L</td>
          <td style="text-align:right; font-weight:700; color:<?= $var_color ?>;"><?= ($var >= 0 ? '+' : '') . number_format($var, 2) ?> L</td>
          <td style="font-size:11px; color:#334155; max-width:180px;"><?= htmlspecialchars($adj['reason']) ?></td>
          <td style="font-size:11px; color:#334155;"><?= htmlspecialchars($adj['adjusted_by']) ?></td>
          <td><?= $st_badge ?></td>
          <td style="text-align:center; white-space:nowrap;">
            <?php if ($is_pending): ?>
              <button type="button" onclick="approveFuelAdjustment(<?= $adj['id'] ?>)" style="background:#16a34a; color:#fff; border:none; border-radius:5px; padding:4px 10px; font-size:11px; font-weight:700; cursor:pointer; margin-right:4px;"><i class="fas fa-check"></i> Approve</button>
              <button type="button" onclick="rejectFuelAdjustment(<?= $adj['id'] ?>)" style="background:#dc2626; color:#fff; border:none; border-radius:5px; padding:4px 10px; font-size:11px; font-weight:700; cursor:pointer;"><i class="fas fa-times"></i> Reject</button>
            <?php else: ?>
              <span style="color:#94a3b8; font-size:11px; font-weight:600;">Processed</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>

  </div>
  <div id="adminFuelAdjPagination" style="padding:8px 16px;"></div>
</div>
<?php endif; ?>

<!-- ══ TAB 4: STOCK ALERTS ══ -->
<?php if ($active_tab === 'alerts'): ?>
<?php
    $alert_tanks = array_filter($rows, fn($r) => in_array($r['status'], ['Low', 'Critical', 'Out of Stock']));
?>
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:20px;">
    <div style="background:#fff; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #fed7aa;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#ea580c; text-transform:uppercase; letter-spacing:.3px;">Low Fuel Tanks</div>
            <div style="font-size:24px; font-weight:800; color:#ea580c; margin-top:4px;"><?= number_format($total_low_fuel_tanks) ?></div>
        </div>
        <div style="background:#fff7ed; color:#ea580c; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <div style="background:#fff; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; border:1px solid #fecaca;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#dc2626; text-transform:uppercase; letter-spacing:.3px;">Critical Fuel Tanks</div>
            <div style="font-size:24px; font-weight:800; color:#dc2626; margin-top:4px;"><?= number_format($total_critical_fuel_tanks) ?></div>
        </div>
        <div style="background:#fef2f2; color:#dc2626; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-fire"></i></div>
    </div>
</div>

<div style="background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px;">
  <div style="padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#f8fafc;">
    <div style="font-weight:700; color:#002F70; font-size:14px; text-transform:uppercase; display:flex; align-items:center; gap:8px;">
      <i class="fas fa-exclamation-triangle" style="color:#ea580c;"></i> Active Stock Alerts Catalog
    </div>
  </div>
  <div class="aif-wrap">
    <table class="aif-tbl" id="adminFuelAlertTable">
      <thead>
        <tr>
          <th>UGT No.</th>
          <th>Fuel Type</th>
          <th style="text-align:right;">Capacity</th>
          <th style="text-align:right;">Current Volume</th>
          <th style="text-align:right;">Reorder Level</th>
          <th style="text-align:right;">Critical Level</th>
          <th style="text-align:center;">Status</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($alert_tanks)): ?>
        <tr><td colspan="7" style="text-align:center; padding:32px; color:#6c757d;"><i class="fas fa-check-circle" style="font-size:24px; color:#16a34a; display:block; margin-bottom:8px;"></i>All fuel tanks have healthy inventory levels (No critical or low alerts).</td></tr>
      <?php else: ?>
        <?php foreach ($alert_tanks as $r):
            $ugt_no = 'UGT-' . str_pad($r['tanker_num'], 2, '0', STR_PAD_LEFT);
        ?>
        <tr>
          <td><code style="font-weight:700;color:#002F70;font-size:12px;"><?= htmlspecialchars($ugt_no) ?></code></td>
          <td style="font-weight:700;color:#0f172a;"><?= htmlspecialchars(get_canonical_fuel_name($r['fuel_type'])) ?></td>
          <td style="text-align:right; font-weight:600; color:#475569;"><?= number_format($r['capacity'], 0) ?> L</td>
          <td style="text-align:right; font-weight:800; color:#002F70;"><?= number_format($r['current_level'], 2) ?> L</td>
          <td style="text-align:right; font-weight:600; color:#eab308;"><?= number_format($r['reorder_level'], 0) ?> L</td>
          <td style="text-align:right; font-weight:600; color:#dc2626;"><?= number_format($r['critical_level'], 0) ?> L</td>
          <td style="text-align:center;"><span class="status-pill" style="background:<?= $r['status_color'] ?>18; color:<?= $r['status_color'] ?>; border:1px solid <?= $r['status_color'] ?>40;"><?= htmlspecialchars($r['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div id="adminFuelAlertPagination" style="padding:8px 16px;"></div>
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
        </div>
        <!-- Sub-tabs -->
        <div style="display:flex; border-bottom:2px solid #e2e8f0; background:#f8fafc; flex-shrink:0; padding:0 16px; overflow-x:auto; white-space:nowrap; gap:4px;">
            <button type="button" class="modal-tab-btn active" id="vfmTab1" onclick="vfmSwitchTab(1)"><i class="fas fa-info-circle"></i> UGT Information</button>
            <button type="button" class="modal-tab-btn" id="vfmTab2" onclick="vfmSwitchTab(2)"><i class="fas fa-truck-loading"></i> Fuel Delivery History</button>
            <button type="button" class="modal-tab-btn" id="vfmTab3" onclick="vfmSwitchTab(3)"><i class="fas fa-history"></i> Fuel Adjustment History</button>
        </div>
        <!-- Body -->
        <div style="overflow-y:auto; flex:1; padding:22px;" id="vfmBody">
            <!-- SUB-TAB 1: UGT Information -->
            <div id="vfmPane1">
                <div style="font-size:11px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; padding-bottom:6px; border-bottom:2px solid #e9ecef;"><i class="fas fa-info-circle"></i> Tank &amp; Fuel Specifications</div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px 24px; margin-bottom:20px;">
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">UGT No.</div><div id="vfmUgtNo" style="font-weight:800; color:#002F70; font-size:16px;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Fuel Type</div><div id="vfmFuelType" style="font-weight:800; color:#0f172a; font-size:16px;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Storage Capacity</div><div id="vfmCapacity" style="font-weight:600; color:#475569;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Current Volume</div><div id="vfmVolume" style="font-weight:800; color:#002F70; font-size:18px;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Available Space</div><div id="vfmAvailableSpace" style="font-weight:700; color:#16a34a; font-size:16px;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Reorder Level</div><div id="vfmReorder" style="font-weight:600; color:#d97706;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Critical Level</div><div id="vfmCritical" style="font-weight:600; color:#dc2626;"></div></div>
                    <div><div style="font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Status</div><div id="vfmStatus"></div></div>
                </div>
            </div>
            <!-- SUB-TAB 2: Fuel Delivery History -->
            <div id="vfmPane2" style="display:none;">
                <div style="font-size:11px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; padding-bottom:6px; border-bottom:2px solid #e9ecef;"><i class="fas fa-truck-loading"></i> Recent Fuel Deliveries</div>
                <div id="vfmDeliveriesTable"><div style="text-align:center; padding:24px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading deliveries...</div></div>
            </div>
            <!-- SUB-TAB 3: Fuel Adjustment History -->
            <div id="vfmPane3" style="display:none;">
                <div style="font-size:11px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; padding-bottom:6px; border-bottom:2px solid #e9ecef;"><i class="fas fa-history"></i> Recent Fuel Adjustments</div>
                <div id="vfmAdjustmentsTable"><div style="text-align:center; padding:24px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading adjustments...</div></div>
            </div>
        </div>
        <!-- Footer -->
        <div style="padding:12px 22px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px; background:#f8fafc; flex-shrink:0;">
            <button type="button" onclick="closeTankModal()" class="btn-cancel">Close</button>
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
            <button type="button" onclick="closeDelViewModal()" class="int-btn-outline" style="border-color:#6b7280 !important; color:#6b7280 !important;">Close</button>
        </div>
    </div>
</div>

<script>
var _adminCurrentFuel = null;

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
    for (var i = 1; i <= 3; i++) {
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

function viewTankDetailsFromBtn(btn) {
    var targetBtn = (btn && btn.closest) ? btn.closest('button') : btn;
    if (!targetBtn) return;
    
    var r = {};
    try {
        var raw = targetBtn.getAttribute('data-fuel');
        if (raw) {
            r = JSON.parse(raw);
        }
    } catch(e) {}

    r.ugt_no = r.ugt_no || targetBtn.getAttribute('data-ugt') || '';
    r.fuel_type = r.fuel_type || targetBtn.getAttribute('data-fuel-type') || '';
    r.capacity = r.capacity || Number(targetBtn.getAttribute('data-capacity') || 0);
    r.current_level = (r.current_level !== undefined && r.current_level !== null) ? r.current_level : Number(targetBtn.getAttribute('data-current-level') || 0);
    r.available_space = (r.available_space !== undefined && r.available_space !== null) ? r.available_space : Number(targetBtn.getAttribute('data-available-space') || 0);
    r.reorder_level = r.reorder_level || Number(targetBtn.getAttribute('data-reorder') || 0);
    r.critical_level = r.critical_level || Number(targetBtn.getAttribute('data-critical') || 0);
    r.status = r.status || targetBtn.getAttribute('data-status') || 'Normal';

    viewTankDetails(r);
}

function viewTankDetails(r) {
    _adminCurrentFuel = r;
    var overlay = document.getElementById('tankModal');
    if (!overlay) return;

    try {
        if (overlay.parentNode !== document.body) {
            document.body.appendChild(overlay);
        }

        var ugtNoStr = r.ugt_no || ('UGT #' + (r.tanker_num || ''));
        if (document.getElementById('vfmTitle')) document.getElementById('vfmTitle').textContent = 'View Fuel Details — ' + ugtNoStr + ' (' + (r.fuel_type || '') + ')';
        
        if (document.getElementById('vfmUgtNo')) document.getElementById('vfmUgtNo').textContent = ugtNoStr;
        if (document.getElementById('vfmFuelType')) document.getElementById('vfmFuelType').textContent = r.fuel_type || '—';
        if (document.getElementById('vfmCapacity')) document.getElementById('vfmCapacity').textContent = Number(r.capacity || 0).toLocaleString() + ' L';
        if (document.getElementById('vfmVolume')) document.getElementById('vfmVolume').textContent = Number(r.current_level || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
        if (document.getElementById('vfmAvailableSpace')) document.getElementById('vfmAvailableSpace').textContent = Number(r.available_space || (r.capacity - r.current_level) || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
        if (document.getElementById('vfmReorder')) document.getElementById('vfmReorder').textContent = Number(r.reorder_level || 0).toLocaleString() + ' L';
        if (document.getElementById('vfmCritical')) document.getElementById('vfmCritical').textContent = Number(r.critical_level || 0).toLocaleString() + ' L';

        var statusMap = {
            'Normal': '<span class="status-pill" style="background:#dcfce7;color:#15803d;border:1px solid #a7f3d0;">Normal</span>',
            'Low': '<span class="status-pill" style="background:#fff3cd;color:#ea580c;border:1px solid #ffeba8;">Low</span>',
            'Critical': '<span class="status-pill" style="background:#fce8e6;color:#dc3545;border:1px solid #f8c2bc;">Critical</span>',
            'Out of Stock': '<span class="status-pill" style="background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;">Out of Stock</span>'
        };
        if (document.getElementById('vfmStatus')) document.getElementById('vfmStatus').innerHTML = statusMap[r.status] || '<span class="status-pill" style="background:#f1f5f9;color:#475569;">' + esc(r.status || '') + '</span>';

        // Reset sub-tabs to 1
        if (typeof vfmSwitchTab === 'function') vfmSwitchTab(1);

        // Placeholders for async tables
        if (document.getElementById('vfmDeliveriesTable')) document.getElementById('vfmDeliveriesTable').innerHTML = '<div style="text-align:center;padding:16px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading deliveries...</div>';
        if (document.getElementById('vfmAdjustmentsTable')) document.getElementById('vfmAdjustmentsTable').innerHTML = '<div style="text-align:center;padding:16px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading adjustments...</div>';

        // Show Modal
        overlay.classList.add('open');
        overlay.style.display = 'flex';
        overlay.style.position = 'fixed';
        overlay.style.top = '0'; overlay.style.left = '0'; overlay.style.right = '0'; overlay.style.bottom = '0';
        overlay.style.zIndex = '10000';
    } catch(err) {
        console.error("viewTankDetails error:", err);
        overlay.classList.add('open');
        overlay.style.display = 'flex';
        overlay.style.position = 'fixed';
        overlay.style.top = '0'; overlay.style.left = '0'; overlay.style.right = '0'; overlay.style.bottom = '0';
        overlay.style.zIndex = '10000';
    }

    // Fetch AJAX activity
    fetch('admin_inventory_fuel.php?ajax=1&action=get_fuel_details&fuel_type=' + encodeURIComponent(r.fuel_type || ''))
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data || !data.success) return;

        // 1. Deliveries Table
        if (!data.deliveries || data.deliveries.length === 0) {
            if (document.getElementById('vfmDeliveriesTable')) document.getElementById('vfmDeliveriesTable').innerHTML = '<div style="text-align:center;padding:12px;color:#94a3b8;">No delivery records for this fuel type.</div>';
        } else {
            var dHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:#f8fafc;"><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Delivery No.</th><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Date</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Liters</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Cost/Liter</th><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Supplier</th></tr></thead><tbody>';
            data.deliveries.forEach(function(d) {
                var dNo = d.invoice_no || ('DEL-' + String(d.id || 0).padStart(5, '0'));
                var dDate = d.delivery_date ? new Date(d.delivery_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—';
                dHtml += '<tr><td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;"><code style="font-weight:700;color:#002F70;">' + esc(dNo) + '</code></td>';
                dHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;color:#64748b;">' + dDate + '</td>';
                dHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:#16a34a;">' + Number(d.delivery_liters || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</td>';
                dHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;color:#002F70;">₱' + Number(d.cost_per_liter || 65.5).toFixed(2) + '</td>';
                dHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;">' + esc(d.supplier || 'Petron Corporation') + '</td></tr>';
            });
            dHtml += '</tbody></table>';
            if (document.getElementById('vfmDeliveriesTable')) document.getElementById('vfmDeliveriesTable').innerHTML = dHtml;
        }

        // 2. Adjustments Table
        if (!data.adjustments || data.adjustments.length === 0) {
            if (document.getElementById('vfmAdjustmentsTable')) document.getElementById('vfmAdjustmentsTable').innerHTML = '<div style="text-align:center;padding:12px;color:#94a3b8;">No adjustment history for this fuel type.</div>';
        } else {
            var aHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:#f8fafc;"><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Date</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Previous</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">New</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Variance</th><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Reason</th><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Adjusted By</th></tr></thead><tbody>';
            data.adjustments.forEach(function(a) {
                var aDate = a.adjustment_date ? new Date(a.adjustment_date).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
                var prev = Number(a.previous_value || 0);
                var newVal = Number(a.new_value || 0);
                var v = newVal - prev;
                var vColor = v > 0 ? '#16a34a' : (v < 0 ? '#dc3545' : '#64748b');
                aHtml += '<tr><td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;color:#475569;">' + aDate + '</td>';
                aHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;color:#64748b;">' + prev.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</td>';
                aHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:#002F70;">' + newVal.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</td>';
                aHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:' + vColor + ';">' + (v >= 0 ? '+' : '') + v.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</td>';
                aHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;font-size:11px;">' + esc(a.reason || a.notes || 'Routine Calibration') + '</td>';
                aHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;font-size:11px;">' + esc(a.adjusted_by || 'Manager') + '</td></tr>';
            });
            aHtml += '</tbody></table>';
            if (document.getElementById('vfmAdjustmentsTable')) document.getElementById('vfmAdjustmentsTable').innerHTML = aHtml;
        }
    })
    .catch(function(err) {
        console.error("get_fuel_details error:", err);
        if (document.getElementById('vfmDeliveriesTable')) document.getElementById('vfmDeliveriesTable').innerHTML = '<div style="text-align:center;color:#dc3545;padding:12px;">Error loading deliveries.</div>';
        if (document.getElementById('vfmAdjustmentsTable')) document.getElementById('vfmAdjustmentsTable').innerHTML = '<div style="text-align:center;color:#dc3545;padding:12px;">Error loading adjustments.</div>';
    });
}

// Global click listener backup for any View button in the catalog table
document.addEventListener('click', function(e) {
    var btn = e.target.closest ? e.target.closest('button') : null;
    if (btn && btn.getAttribute('data-fuel') !== null) {
        e.stopPropagation();
        viewTankDetailsFromBtn(btn);
    }
});

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
    for (var i = 1; i <= 3; i++) {
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

function resetAdminFuelFilters() {
    if (document.getElementById('sq')) document.getElementById('sq').value = '';
    if (document.getElementById('cf')) document.getElementById('cf').value = '';
    if (document.getElementById('sf')) document.getElementById('sf').value = '';
    filterFuelTable();
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
        var rUgtNo = (row.dataset.ugtNo || '').toLowerCase();
        var rFuelType = (row.dataset.fuelType || '').toLowerCase();
        var rStatus = (row.dataset.status || '').toLowerCase();

        if (search && rTankNum.indexOf(search) === -1 && rUgtNo.indexOf(search) === -1 && rFuelType.indexOf(search) === -1) match = false;
        if (fuelType && rFuelType.indexOf(fuelType) === -1) match = false;
        if (status) {
            if (status === 'normal' && rStatus !== 'normal') match = false;
            else if (status === 'low' && rStatus !== 'low') match = false;
            else if (status === 'critical' && rStatus !== 'critical') match = false;
            else if (status === 'out of stock' && rStatus !== 'out of stock') match = false;
        }

        if (match) {
            row.classList.remove('search-hidden');
            row.style.display = '';
        } else {
            row.classList.add('search-hidden');
            row.style.display = 'none';
        }
    });
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminFuelInvTable', null, 'adminFuelInvPagination', 20);
    }
}

function filterDeliveriesTable() {
    var q = (document.getElementById('delSearchInput') || {}).value || '';
    q = q.toLowerCase().trim();
    var rows = document.querySelectorAll('#adminFuelDelBody tr.del-row');
    rows.forEach(function(r) {
        var s = (r.dataset.search || '').toLowerCase();
        if (!q || s.indexOf(q) !== -1) {
            r.classList.remove('search-hidden');
            r.style.display = '';
        } else {
            r.classList.add('search-hidden');
            r.style.display = 'none';
        }
    });
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminFuelDelTable', null, 'adminFuelDelPagination', 20);
    }
}

function filterAdjTable() {
    var q = (document.getElementById('adjSearchInput') || {}).value || '';
    q = q.toLowerCase().trim();
    var rows = document.querySelectorAll('#adminFuelAdjBody tr.adj-row');
    rows.forEach(function(r) {
        var s = (r.dataset.search || '').toLowerCase();
        if (!q || s.indexOf(q) !== -1) {
            r.classList.remove('search-hidden');
            r.style.display = '';
        } else {
            r.classList.add('search-hidden');
            r.style.display = 'none';
        }
    });
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminFuelAdjTable', null, 'adminFuelAdjPagination', 20);
    }
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
</script>

<!-- â•â• ADMIN APPROVE CONFIRMATION MODAL â•â• -->
<div class="modal-overlay" id="adminApproveModal" style="z-index:10005; display:none; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;">
    <div style="background:#fff; border-radius:14px; width:96%; max-width:480px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 24px 40px rgba(0,0,0,.25); position:relative; z-index:10006; margin:auto;">
        <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:space-between;">
            <div style="font-size:15px; font-weight:800; color:#002F70; text-transform:uppercase; letter-spacing:.4px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-check-circle" style="color:#16a34a;"></i> Confirm Fuel Stock Adjustment
            </div>
        </div>
        <div style="padding:22px; text-align:center;">
            <div style="width:54px; height:54px; border-radius:50%; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; font-size:24px; margin:0 auto 14px;">
                <i class="fas fa-gas-pump"></i>
            </div>
            <h4 style="margin:0 0 8px; font-size:16px; font-weight:800; color:#0f172a;">Approve this Fuel Stock Adjustment?</h4>
            <p style="margin:0; font-size:13px; color:#64748b; line-height:1.5;">This will automatically update the Underground Storage Tank (UGT) Current Volume and record the movement in the audit trail.</p>
            <div id="adminApproveError" style="color:#dc2626; font-size:12px; font-weight:700; margin-top:10px; display:none;"></div>
        </div>
        <div style="padding:14px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" id="adminApproveConfirmBtn" onclick="execApproveFuelAdjustment()" style="background:#ffffff !important; color:#16a34a !important; border:1px solid #16a34a !important; border-radius:6px; padding:8px 20px; font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:none !important;"><i class="fas fa-check"></i> Approve Adjustment</button>
            <button type="button" onclick="closeAdminApproveModal()" style="background:#ffffff !important; color:#475569 !important; border:1px solid #cbd5e1 !important; border-radius:6px; padding:8px 20px; font-size:13px; font-weight:600; cursor:pointer; box-shadow:none !important;">Cancel</button>
        </div>
    </div>
</div>

<!-- â•â• ADMIN REJECT CONFIRMATION MODAL â•â• -->
<div class="modal-overlay" id="adminRejectModal" style="z-index:10005; display:none; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;">
    <div style="background:#fff; border-radius:14px; width:96%; max-width:480px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 24px 40px rgba(0,0,0,.25); position:relative; z-index:10006; margin:auto;">
        <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:space-between;">
            <div style="font-size:15px; font-weight:800; color:#dc2626; text-transform:uppercase; letter-spacing:.4px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-times-circle" style="color:#dc2626;"></i> Reject Fuel Stock Adjustment
            </div>
        </div>
        <div style="padding:22px;">
            <p style="margin:0 0 10px; font-size:13px; font-weight:700; color:#334155;">Enter reason for rejecting this stock adjustment request: <span style="color:#dc2626;">*</span></p>
            <textarea id="adminRejectReason" rows="3" placeholder="State reason for rejection..." style="width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; resize:vertical;"></textarea>
            <div id="adminRejectError" style="color:#dc2626; font-size:12px; font-weight:700; margin-top:8px; display:none;"></div>
        </div>
        <div style="padding:14px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" id="adminRejectConfirmBtn" onclick="execRejectFuelAdjustment()" style="background:#ffffff !important; color:#dc2626 !important; border:1px solid #dc2626 !important; border-radius:6px; padding:8px 20px; font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:none !important;"><i class="fas fa-times"></i> Confirm Rejection</button>
            <button type="button" onclick="closeAdminRejectModal()" style="background:#ffffff !important; color:#475569 !important; border:1px solid #cbd5e1 !important; border-radius:6px; padding:8px 20px; font-size:13px; font-weight:600; cursor:pointer; box-shadow:none !important;">Cancel</button>
        </div>
    </div>
</div>

<script>

var _selectedAdjustmentId = null;

function approveFuelAdjustment(id) {
    _selectedAdjustmentId = id;
    var errEl = document.getElementById('adminApproveError');
    if (errEl) errEl.style.display = 'none';
    var modal = document.getElementById('adminApproveModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.style.position = 'fixed';
        modal.style.top = '0'; modal.style.left = '0'; modal.style.right = '0'; modal.style.bottom = '0';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        modal.style.padding = '20px';
        modal.style.zIndex = '10005';
    }
}

function closeAdminApproveModal() {
    var modal = document.getElementById('adminApproveModal');
    if (modal) modal.style.display = 'none';
}

function execApproveFuelAdjustment() {
    if (!_selectedAdjustmentId) return;
    var btn = document.getElementById('adminApproveConfirmBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving...'; }

    var fd = new FormData();
    fd.append('action', 'approve_adjustment');
    fd.append('adjustment_id', _selectedAdjustmentId);

    fetch('../backend/api/fuel_adjustments.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Approve Adjustment'; }
        if (!data.success) {
            var errEl = document.getElementById('adminApproveError');
            if (errEl) { errEl.textContent = data.message || 'Failed to approve adjustment.'; errEl.style.display = 'block'; }
            return;
        }
        closeAdminApproveModal();
        if (window.showPetronFlash) {
            window.showPetronFlash(data.message || 'Fuel Stock Adjustment approved successfully! Inventory volume updated.', 'success', 5000);
        }
        setTimeout(function() { location.reload(); }, 1200);
    })
    .catch(function(err) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Approve Adjustment'; }
        var errEl = document.getElementById('adminApproveError');
        if (errEl) { errEl.textContent = 'Network error. Please try again.'; errEl.style.display = 'block'; }
    });
}

function rejectFuelAdjustment(id) {
    _selectedAdjustmentId = id;
    var reasonInput = document.getElementById('adminRejectReason');
    if (reasonInput) reasonInput.value = '';
    var errEl = document.getElementById('adminRejectError');
    if (errEl) errEl.style.display = 'none';
    var modal = document.getElementById('adminRejectModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.style.position = 'fixed';
        modal.style.top = '0'; modal.style.left = '0'; modal.style.right = '0'; modal.style.bottom = '0';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        modal.style.padding = '20px';
        modal.style.zIndex = '10005';
    }
}


function closeAdminRejectModal() {
    var modal = document.getElementById('adminRejectModal');
    if (modal) modal.style.display = 'none';
}

function execRejectFuelAdjustment() {
    if (!_selectedAdjustmentId) return;
    var reason = (document.getElementById('adminRejectReason').value || '').trim();
    var errEl = document.getElementById('adminRejectError');
    if (errEl) errEl.style.display = 'none';

    if (!reason) {
        if (errEl) { errEl.textContent = 'Rejection reason is required.'; errEl.style.display = 'block'; }
        return;
    }

    var btn = document.getElementById('adminRejectConfirmBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rejecting...'; }

    var fd = new FormData();
    fd.append('action', 'reject_adjustment');
    fd.append('adjustment_id', _selectedAdjustmentId);
    fd.append('reason', reason);

    fetch('../backend/api/fuel_adjustments.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-times"></i> Confirm Rejection'; }
        if (!data.success) {
            if (errEl) { errEl.textContent = data.message || 'Failed to reject adjustment.'; errEl.style.display = 'block'; }
            return;
        }
        closeAdminRejectModal();
        if (window.showPetronFlash) {
            window.showPetronFlash(data.message || 'Fuel Stock Adjustment request rejected.', 'success', 5000);
        }
        setTimeout(function() { location.reload(); }, 1200);
    })
    .catch(function(err) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-times"></i> Confirm Rejection'; }
        if (errEl) { errEl.textContent = 'Network error. Please try again.'; errEl.style.display = 'block'; }
    });
}


function filterFuelMovementTable() {
    var sq = (document.getElementById('fmovSearch') ? document.getElementById('fmovSearch').value : '').toLowerCase().trim();
    var ft = (document.getElementById('fmovTypeFilter') ? document.getElementById('fmovTypeFilter').value : '').toLowerCase().trim();
    var mv = (document.getElementById('fmovMoveFilter') ? document.getElementById('fmovMoveFilter').value : '').toLowerCase().trim();

    var rows = document.querySelectorAll('#adminFuelMovementTbody tr.fmov-row');
    rows.forEach(function(row) {
        var sText = row.getAttribute('data-search') || '';
        var fType = row.getAttribute('data-fuel-type') || '';
        var mType = row.getAttribute('data-move-type') || '';

        var matchS = !sq || sText.indexOf(sq) !== -1;
        var matchF = !ft || fType.indexOf(ft) !== -1;
        var matchM = !mv || mType.indexOf(mv) !== -1;

        if (matchS && matchF && matchM) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function resetFuelMovementFilters() {
    if (document.getElementById('fmovSearch')) document.getElementById('fmovSearch').value = '';
    if (document.getElementById('fmovTypeFilter')) document.getElementById('fmovTypeFilter').value = '';
    if (document.getElementById('fmovMoveFilter')) document.getElementById('fmovMoveFilter').value = '';
    filterFuelMovementTable();
}

document.addEventListener('DOMContentLoaded', function() {
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminFuelInvTable', null, 'adminFuelInvPagination', 20);
        setupTablePagination('adminFuelMovementTable', null, 'adminFuelMovementPagination', 20);
        setupTablePagination('adminFuelDelTable', null, 'adminFuelDelPagination', 20);
        setupTablePagination('adminFuelAdjTable', null, 'adminFuelAdjPagination', 20);
        setupTablePagination('adminFuelAlertTable', null, 'adminFuelAlertPagination', 20);
    }
});
</script>
</div> <!-- /.main-content -->

<?php include __DIR__ . '/../partials/footer.php'; ?>
