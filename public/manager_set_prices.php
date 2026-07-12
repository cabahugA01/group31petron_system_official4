<?php
// Force browser to always load fresh — prevents stale CSS/JS cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$page_id = 'manager_set_prices';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// ── Access control ──────────────────────────────────────────────────────────
if ($role !== 'manager') {
    header('Location: dashboard.php');
    exit;
}
if ((int)$station_id <= 0) {
    render_no_station_page('manager_dashboard.php');
}

// ── Fetch station name ───────────────────────────────────────────────────────
$station_name = 'Unknown Station';
try {
    $stmt2 = $pdo->prepare('SELECT name FROM stations WHERE id = ? LIMIT 1');
    $stmt2->execute([$station_id]);
    $station_name = $stmt2->fetchColumn() ?: 'Unknown Station';
} catch (Exception $e) { /* silent */ }

// Helper function to get the canonical 5 fuel types
function get_canonical_fuel_name($name) {
    $name_lower = strtolower(trim($name));
    if (strpos($name_lower, 'turbo') !== false) {
        return 'Turbo Diesel';
    } elseif (strpos($name_lower, 'diesel') !== false) {
        return 'Diesel';
    } elseif (strpos($name_lower, 'kerosene') !== false) {
        return 'Kerosene';
    } elseif (strpos($name_lower, 'xcs') !== false) {
        return 'XCS Plus';
    } elseif (strpos($name_lower, 'xtra') !== false || strpos($name_lower, 'unl') !== false || strpos($name_lower, 'advance') !== false) {
        return 'XTR ADVANCE';
    }
    return $name;
}

// ── Fetch fuel inventory ─────────────────────────────────────────────────────
$fuel_products = [];
$fuel_stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'last_updated' => null, 'updates_today' => 0];
try {
    $TANK_CONFIG_17 = get_tank_config();

    $fi_lookup = [];
    $s = $pdo->prepare("SELECT id, fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, reorder_level, critical_level FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }

    $del_lookup = [];
    $s = $pdo->prepare("SELECT tank_assigned, fuel_type, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id = ? AND DATE(delivery_date) = CURDATE() AND status = 'Verified' GROUP BY tank_assigned, fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];
    }

    $sales_lookup = [];
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'Verified' GROUP BY fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];
    }

    $adj_lookup = [];
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id = fi.fuel_type_id AND fi.station_id = fa.station_id WHERE fa.station_id = ? AND DATE(fa.adjustment_date) = CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];
    }

    $price_lookup = [];
    $s = $pdo->prepare("SELECT ft.name AS fuel_type, fp.price_per_liter FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id = ft.id WHERE fp.station_id = ? AND fp.is_active = 1 ORDER BY fp.effective_date DESC");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim($row['fuel_type']));
        if (!isset($price_lookup[$key])) $price_lookup[$key] = (float)$row['price_per_liter'];
    }

    $pending_approvals = [];
    $s = $pdo->prepare("SELECT fuel_type_id, new_value, status, id AS approval_id FROM pending_price_approvals WHERE station_id = ? AND product_type IN ('fuel', 'fuel_inventory') AND status = 'pending'");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p_row) {
        $pending_approvals[(int)$p_row['fuel_type_id']] = $p_row;
    }

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
        $cur_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;

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
        $purchases = $del_lookup[$tank_key] ?? 0;

        $sales_total = $sales_lookup[$ft_key] ?? 0;
        $adj_total   = $adj_lookup[$ft_key] ?? 0;
        $sales       = $same_type_count > 0 ? round($sales_total / $same_type_count, 2) : 0;
        $calibration = $same_type_count > 0 ? round($adj_total / $same_type_count, 2) : 0;

        $beginning = $same_type_count > 0 ? round($cur_level / $same_type_count, 2) : 0;
        $total_available = $beginning + $purchases;
        $ending_system   = min(max(0, $total_available - $sales - $calibration), $capacity);

        if ($capacity == 14000) {
            $critical_lvl = 2500; $low_lvl = 5000;
        } elseif ($capacity == 7000) {
            $critical_lvl = 1000; $low_lvl = 2000;
        } else {
            $critical_lvl = $capacity * 0.10; $low_lvl = $capacity * 0.20;
        }

        if ($ending_system <= 0) {
            $status = 'Out of Stock';
        } elseif ($ending_system <= $critical_lvl) {
            $status = 'Critical';
        } elseif ($ending_system <= $low_lvl) {
            $status = 'Low';
        } else {
            $status = 'Normal';
        }

        $price = $price_lookup[$ft_key] ?? ($inv ? (float)($inv['price_per_liter'] ?? 0) : 0);
        $timestamp = $inv['last_updated'] ?? null;
        $critical_level = $inv ? (float)($inv['critical_level'] ?? 0) : 300;

        $inv_id = $inv['id'] ?? null;
        $app = $inv_id ? ($pending_approvals[(int)$inv_id] ?? null) : null;

        $fuel_products[] = [
            'id'             => $inv_id,
            'pump_id'        => $tc['tanker_num'],
            'tank_label'     => $tc['label'],
            'raw_fuel_type'  => $tc['fuel_type'],
            'capacity'       => $capacity,
            'current_stock'  => $ending_system,
            'critical_level' => $critical_level,
            'status'         => $status,
            'last_updated'   => $timestamp,
            'price_per_liter'=> $price,
            'pending_price'  => $app ? (float)$app['new_value'] : null,
            'approval_status'=> $app ? $app['status'] : null,
            'approval_id'    => $app ? $app['approval_id'] : null
        ];
    }

    // Calculate stats
    $fuel_stats['total'] = count($fuel_products);
    foreach ($fuel_products as $f) {
        if (strtolower($f['status'] ?? 'normal') === 'normal') {
            $fuel_stats['active']++;
        } else {
            $fuel_stats['inactive']++;
        }
        if ($f['last_updated'] && (!$fuel_stats['last_updated'] || $f['last_updated'] > $fuel_stats['last_updated'])) {
            $fuel_stats['last_updated'] = $f['last_updated'];
        }
        if ($f['last_updated'] && date('Y-m-d', strtotime($f['last_updated'])) === date('Y-m-d')) {
            $fuel_stats['updates_today']++;
        }
    }
} catch (Exception $e) {
    $fuel_products = [];
    error_log('[manager_set_prices] fuel error: ' . $e->getMessage());
}

// ── Fetch merchandise grouped by category ───────────────────────────────────────
$merch_by_cat   = [];
$merch_all      = [];
$merch_stats    = ['total' => 0, 'valid_price' => 0, 'below_cost' => 0, 'unpriced' => 0];
$all_categories = [];

try {
    // Try manager's station first
    $sid_merch = $station_id;
    $stmt = $pdo->prepare("
        SELECT i.id, i.category, i.product_name, i.sku, i.size, i.unit_cost, i.supplier,
               i.unit_price, i.stock_quantity, i.stock, i.created_at,
               p.new_value  AS pending_price,
               p.status     AS approval_status,
               p.id         AS approval_id
        FROM inventory_products i
        LEFT JOIN pending_price_approvals p
               ON p.product_id = i.id
              AND p.product_type = 'merchandise'
              AND p.status = 'pending'
              AND p.station_id = ?
        WHERE i.category != 'Fuel'
          AND LOWER(COALESCE(i.status,'active')) != 'inactive'
        ORDER BY i.category, i.product_name
    ");
    $stmt->execute([$sid_merch]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If empty, try station 1
    if (empty($rows)) {
        $sid_merch = 1;
        $stmt->execute([$sid_merch]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as $row) {
        $cat    = $row['category'] ?? 'Uncategorized';
        $cost   = (float)($row['unit_cost']  ?? 0);
        $price  = (float)($row['unit_price'] ?? 0);
        $stock  = (int)($row['stock_quantity'] ?? $row['stock'] ?? 0);

        // Pricing stats
        $merch_stats['total']++;
        if ($price <= 0) {
            $merch_stats['unpriced']++;
        } elseif ($price < $cost) {
            $merch_stats['below_cost']++;
        } else {
            $merch_stats['valid_price']++;
        }

        $row['_cost']  = $cost;
        $row['_price'] = $price;
        $row['_stock'] = $stock;

        $merch_by_cat[$cat][] = $row;
        $merch_all[]          = $row;
        $all_categories[$cat] = true;
    }
} catch (Exception $e) {
    $merch_by_cat = [];
    error_log('[manager_set_prices] merch error: ' . $e->getMessage());
}

$all_categories = array_keys($all_categories);
sort($all_categories);

// ── Ensure job_order_service_types table exists & fetch service types ──────
$service_types = [];
$service_error = null;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_order_service_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL DEFAULT 1,
        service_key VARCHAR(100) NOT NULL,
        service_name VARCHAR(200) NOT NULL,
        service_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        min_price DECIMAL(12,2) DEFAULT 0,
        max_price DECIMAL(12,2) DEFAULT 0,
        price_description TEXT DEFAULT NULL,
        pricing_notes TEXT DEFAULT NULL,
        icon_class VARCHAR(100) DEFAULT 'fa-wrench',
        color_class VARCHAR(100) DEFAULT 'text-primary',
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_station (station_id),
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("
        SELECT s.id, s.service_name, s.service_key, s.service_price,
               s.status, s.active,
               p.new_price AS pending_price,
               p.status    AS approval_status,
               p.id        AS approval_id
        FROM job_order_service_types s
        LEFT JOIN pending_price_approvals p
               ON s.id = p.product_id
              AND p.product_type = 'service_type'
              AND p.status = 'pending'
              AND p.station_id = ?
        ORDER BY s.service_name
    ");
    $stmt->execute([$station_id]);
    $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $service_types = [];
    $service_error = null; // suppress in production
    error_log("[manager_set_prices] service types error: " . $e->getMessage());
}

// ── Log page view ────────────────────────────────────────────────────────────
try {
    log_activity($pdo, $me['id'], 'View Product Pricing',
        "Manager viewed pricing for station {$station_id}");
} catch (Exception $e) { /* silent */ }

// ── Active tab (persists across refresh via ?tab= query param) ───────────────
$active_tab = $_GET['tab'] ?? 'fuel';
if (!in_array($active_tab, ['fuel', 'merch', 'services'])) $active_tab = 'fuel';

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Page-level styles ─────────────────────────────────────────────────────── */
.ato-tab-bar { display:flex;gap:0;border-bottom:2px solid #dee2e6;margin-bottom:18px; }
.ato-tab { display:inline-flex;align-items:center;gap:7px;padding:10px 22px;font-size:13px;font-weight:600;color:#6c757d;text-decoration:none;border-bottom:3px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s;white-space:nowrap; cursor:pointer; }
.ato-tab:hover { color:#002F6C; }
.ato-tab.active { color:#002F6C;border-bottom-color:#002F6C;background:#f8fbff;border-radius:6px 6px 0 0; }
.pricing-tabs { display: none; } /* replaced by dropdown */
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* Summary cards */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: #fff; 
    border: 1px solid #e2e8f0; 
    border-radius: 10px;
    padding: 16px; 
    text-align: center;
    transition: transform .2s, box-shadow .2s;
}
.summary-card:hover { 
    transform: translateY(-2px); 
    box-shadow: 0 2px 8px rgba(0,0,0,.08); 
}
.summary-card .s-num  { font-size: 1.8rem; font-weight: 700; line-height: 1; color: #002F70; }
.summary-card .s-lbl  { font-size: .75rem; color: #666; text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }
.summary-card.s-total  .s-num { color: #002F6C; }
.summary-card.s-valid  .s-num { color: #16a34a; }
.summary-card.s-below  .s-num { color: #dc2626; }
.summary-card.s-unpriced .s-num { color: #d97706; }

/* Toolbar */
.toolbar {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
    margin-bottom: 16px;
}
.toolbar input[type="text"],
.toolbar select {
    padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; color: #334155; background: #fff;
}
.toolbar input[type="text"]:focus,
.toolbar select:focus { outline: none; border-color: #002F6C; box-shadow: 0 0 0 2px rgba(0,47,108,.12); }

/* Table tweaks */
.pricing-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pricing-table th {
    background: #002F70 !important; 
    color: #fff !important; 
    padding: 10px 12px; 
    text-align: left;
    font-size: 11px; 
    font-weight: 700; 
    text-transform: uppercase;
    letter-spacing: .4px; 
    border-bottom: 2px solid #002F70; 
    white-space: nowrap;
}
.pricing-table td { padding: 11px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.pricing-table tbody tr:hover { background: #e3f2fd; }

/* Category header row */
.cat-row td {
    background: #f1f5f9 !important; font-weight: 700; font-size: 11px;
    text-transform: uppercase; letter-spacing: .5px; color: #475569;
    padding: 7px 12px; border-bottom: 1px solid #e2e8f0;
}

/* Row highlight for price-below-cost */
.row-below-cost { background: #fff5f5 !important; }
.row-below-cost:hover { background: #fee2e2 !important; }

/* Badges */
.badge-normal    { background: #dcfce7; color: #166534; }
.badge-low       { background: #fef9c3; color: #854d0e; }
.badge-critical  { background: #fee2e2; color: #991b1b; }
.badge-out       { background: #fee2e2; color: #991b1b; }
.badge-available { background: #dcfce7; color: #166534; }
.badge-noprice   { background: #fef3c7; color: #92400e; }
.badge-warn      { background: #fee2e2; color: #991b1b; }
.badge-ok        { background: #dcfce7; color: #166534; }

.badge {
    display: inline-block; padding: 3px 9px; border-radius: 999px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
}

/* == MODAL STYLES (Transaction Module Design) == */
.modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:9999; background:rgba(0,0,0,0.55); backdrop-filter:blur(4px); }
.modal-card { position:relative; background:#fff; border-radius:16px; max-width:600px; width:90%; max-height:90vh; overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,.3); animation:modalSlideIn .18s ease; }
@keyframes modalSlideIn { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
.modal-head { display:flex; justify-content:space-between; align-items:center; padding:15px 20px; background:#fff; border-bottom:2px solid #e2e8f0; color:#1e293b; }
.modal-head .modal-icon { width:34px; height:34px; background:#f1f5f9; border-radius:8px; display:flex; align-items:center; justify-content:center; margin-right:10px; }
.modal-head .modal-icon i { color:#64748b; font-size:15px; }
.modal-title { font-weight:700; font-size:14px; color:#1e293b; }
.modal-subtitle { font-size:11px; color:#64748b; margin-top:1px; }
.modal-close { background:#f1f5f9; border:none; color:#64748b; font-size:17px; cursor:pointer; width:28px; height:28px; border-radius:6px; display:flex; align-items:center; justify-content:center; transition:background .15s; }
.modal-close:hover { background:#e2e8f0; color:#475569; }
.modal-body { padding:20px; overflow-y:auto; max-height:calc(90vh - 140px); }
.modal-body label { font-size:13px; font-weight:600; color:#334155 !important; display:block; margin-bottom:6px; letter-spacing:.3px; }
.modal-body .input { width:100%; padding:9px 12px; border:1.5px solid #d1d5db; border-radius:8px; font-size:13px; box-sizing:border-box; color:#1e293b; background:#fff; outline:none; transition:border-color .15s; }
.modal-body .input:focus { border-color:#003d7a; }
.modal-body textarea.input { resize:vertical; font-family:inherit; }
.modal-body select.input { cursor:pointer; }
.modal-actions { display:flex; justify-content:flex-end; gap:8px; padding:15px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; }
.modal-info-box { background:#f0f7ff; border:1px solid #dbeafe; border-radius:8px; padding:12px; margin-bottom:20px; }
.modal-info-box h4 { margin:0 0 10px 0; font-size:13px; color:#003d7a; font-weight:700; }
.modal-info-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; font-size:12px; }
.modal-info-grid div { display:flex; flex-direction:column; }
.modal-info-grid strong { color:#374151; font-size:11px; margin-bottom:2px; }

/* Button styles */
.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 16px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
    border: 1.5px solid transparent;
}
.flt-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}
.flt-btn-solid-primary {
    background: #002F70 !important;
    color: #fff !important;
    border-color: #002F70 !important;
}
.flt-btn-solid-primary:hover {
    background: #001a3d !important;
    border-color: #001a3d !important;
}
.flt-btn-reset {
    background: #f1f5f9 !important;
    color: #64748b !important;
    border-color: #e2e8f0 !important;
}
.flt-btn-reset:hover {
    background: #e2e8f0 !important;
    color: #334155 !important;
}
.flt-btn-danger {
    background: #dc2626 !important;
    color: #fff !important;
    border-color: #dc2626 !important;
}
.flt-btn-danger:hover {
    background: #b91c1c !important;
    border-color: #b91c1c !important;
}

/* == Action buttons — transaction module style == */
.act-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    line-height: 1.2;
    width: 100%;
    margin-bottom: 5px;
    transition: all .18s;
    background: #fff !important;
    border: 1px solid transparent;
    text-decoration: none;
    box-sizing: border-box;
}
.act-btn:last-child { margin-bottom: 0; }
.act-btn-view  { color: #16a34a !important; border-color: #16a34a !important; }
.act-btn-view:hover  { background: #16a34a !important; color: #fff !important; }
.act-btn-edit  { color: #002F6C !important; border-color: #002F6C !important; }
.act-btn-edit:hover  { background: #002F6C !important; color: #fff !important; }
.act-btn-deactivate { color: #dc2626 !important; border-color: #dc2626 !important; }
.act-btn-deactivate:hover { background: #dc2626 !important; color: #fff !important; }
.act-btn-activate { color: #16a34a !important; border-color: #16a34a !important; }
.act-btn-activate:hover { background: #16a34a !important; color: #fff !important; }
.act-btn-wrap { display: flex; flex-direction: column; gap: 4px; min-width: 100px; }

@media (max-width: 768px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .toolbar { flex-direction: column; align-items: stretch; }
    .toolbar input[type="text"] { min-width: unset; width: 100%; }
}
</style>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="page-head" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="h1"><i class="fas fa-tags"></i> Product &amp; Pricing Management</h1>
        <div class="sub">Manage product pricing and inventory</div>
    </div>
</div>



<!-- ── Section Tabs ──────────────────────────────────────────────────── -->
<input type="hidden" id="activeSection" value="<?php echo htmlspecialchars($active_tab); ?>">
<div class="ato-tab-bar">
    <a onclick="switchTab('fuel')" id="tab-btn-fuel" class="ato-tab <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>"><i class="fas fa-gas-pump"></i> Fuel Products</a>
    <a onclick="switchTab('merch')" id="tab-btn-merch" class="ato-tab <?php echo $active_tab === 'merch' ? 'active' : ''; ?>"><i class="fas fa-box"></i> Merchandise</a>
    <a onclick="switchTab('services')" id="tab-btn-services" class="ato-tab <?php echo $active_tab === 'services' ? 'active' : ''; ?>"><i class="fas fa-wrench"></i> Service Types</a>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 1 — FUEL PRODUCTS
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-fuel" class="tab-panel <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>">
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
            <strong style="font-size:15px;color:#002F6C;"><i class="fas fa-gas-pump"></i> Fuel Inventory &amp; Pricing</strong>
            <button onclick="openAddProductModal()" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:all 0.2s;">
                <i class="fas fa-plus-circle"></i> Add Product
            </button>
        </div>
        <div class="table-wrap" style="overflow-x:auto;">
            <table class="pricing-table">
                <thead>
                    <tr>
                        <th>UGT No.</th>
                        <th>Fuel Type</th>
                        <th>Price / Liter (&#8369;)</th>
                        <th>Stock Level (L)</th>
                        <th>Capacity (L)</th>
                        <th>Critical Level (L)</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($fuel_products)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:28px;color:#94a3b8;">
                            <i class="fas fa-info-circle"></i> No fuel inventory records found for this station.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    foreach ($fuel_products as $f):
                        $level    = $f['current_stock'];
                        $critical = $f['critical_level'];
                        $capacity = $f['capacity'];
                        
                        $status_label = $f['status'];
                        if ($status_label === 'Normal') {
                            $status_class = 'badge-normal';
                            $bar_color = '#16a34a';
                        } elseif ($status_label === 'Low') {
                            $status_class = 'badge-low';
                            $bar_color = '#ef4444';
                        } else {
                            $status_class = 'badge-critical';
                            $bar_color = '#dc2626';
                        }
                        
                        $pct = $capacity > 0 ? min(100, round($level / $capacity * 100)) : 0;
                        $canonical_type = get_canonical_fuel_name($f['raw_fuel_type']);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($f['tank_label']); ?></strong>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($canonical_type); ?></strong>
                        </td>
                        <td>
                            <strong style="color:#002F6C;">&#8369;<?php echo number_format((float)($f['price_per_liter'] ?? 0), 2); ?></strong>
                            <?php if (($f['approval_status'] ?? '') === 'pending'): ?>
                                <div style="font-size:11px; color:#d97706; background:#fef3c7; padding:2px 6px; border-radius:4px; margin-top:4px; display:inline-block; font-weight:600;">
                                    Pending: ₱<?php echo number_format($f['pending_price'], 2); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo number_format($level, 2); ?>
                            <div style="margin-top:4px;height:4px;background:#e2e8f0;border-radius:2px;width:80px;">
                                <div style="height:4px;background:<?php echo $bar_color; ?>;border-radius:2px;width:<?php echo $pct; ?>%;"></div>
                            </div>
                        </td>
                        <td><?php echo number_format($capacity, 2); ?></td>
                        <td><?php echo number_format($critical, 2); ?></td>
                        <td>
                            <?php if ($status_label === 'Critical'): ?>
                                <span class="badge <?php echo $status_class; ?>">&#9888; Critical</span>
                            <?php elseif ($status_label === 'Low' || $status_label === 'Low Stock'): ?>
                                <span class="badge <?php echo $status_class; ?>">&#9888; Low Stock</span>
                            <?php elseif ($status_label === 'Out of Stock'): ?>
                                <span class="badge <?php echo $status_class; ?>">&#9888; Out of Stock</span>
                            <?php else: ?>
                                <span class="badge <?php echo $status_class; ?>">&#10003; Normal</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted" style="font-size:12px;">
                            <?php echo $f['last_updated'] ? htmlspecialchars(date('M d, Y H:i', strtotime($f['last_updated']))) : '&mdash;'; ?>
                        </td>
                        <td>
                            <div class="act-btn-wrap">
                                <?php if (!empty($f['id'])): ?>
                                    <button onclick="viewFuelDetails(<?php echo $f['id']; ?>)" class="act-btn act-btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button onclick="openEditPriceModal(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars($f['raw_fuel_type']); ?>', <?php echo (float)($f['price_per_liter'] ?? 0); ?>)" class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if (($f['status'] ?? 'active') === 'active'): ?>
                                        <button onclick="deactivateFuel(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars($f['raw_fuel_type']); ?>')" class="act-btn act-btn-deactivate">
                                            <i class="fas fa-ban"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button onclick="activateFuel(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars($f['raw_fuel_type']); ?>')" class="act-btn act-btn-activate">
                                            <i class="fas fa-check-circle"></i> Activate
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="font-size:11px;color:#94a3b8;font-style:italic;">No Actions</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 2 — MERCHANDISE PRODUCTS
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-merch" class="tab-panel <?php echo $active_tab === 'merch' ? 'active' : ''; ?>">

    <!-- Toolbar: search + filters -->
    <div class="toolbar">
        <input type="text" id="searchInput" placeholder="&#128269; Search by product name or SKU&hellip;" oninput="filterTable()">
        <select id="catFilter" onchange="filterTable()">
            <option value="">All Categories</option>
            <?php foreach ($all_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="statusFilter" onchange="filterTable()">
            <option value="">All Statuses</option>
            <option value="available">Available</option>
            <option value="low">Low Stock</option>
            <option value="out">Out of Stock</option>
            <option value="noprice">No Price Set</option>
            <option value="belowcost">Price Below Cost</option>
        </select>
        <div style="margin-left:auto;">
            <button onclick="openAddMerchandiseModal()" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:all 0.2s;">
                <i class="fas fa-plus-circle"></i> Add Merchandise
            </button>
        </div>
    </div>

    <?php if (empty($merch_by_cat)): ?>
        <div class="card" style="padding:28px;text-align:center;color:#94a3b8;">
            <i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>
            No merchandise products found.
        </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="table-wrap" style="overflow-x:auto;">
            <table class="pricing-table" id="merchTable">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Size</th>
                        <th>Cost (&#8369;)</th>
                        <th>Price (&#8369;)</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="merchBody">
                <?php foreach ($merch_by_cat as $cat_label => $items): ?>
                    <tr class="cat-row" data-cat-header="<?php echo htmlspecialchars($cat_label); ?>">
                        <td colspan="9">
                            <i class="fas fa-folder"></i>
                            <?php echo htmlspecialchars($cat_label); ?>
                            <span class="muted cat-count" style="font-weight:400;margin-left:6px;">(<?php echo count($items); ?> items)</span>
                        </td>
                    </tr>
                    <?php foreach ($items as $item):
                        $cost   = $item['_cost'];
                        $price  = $item['_price'];
                        $stock  = $item['_stock'];

                        $below_cost = ($price > 0 && $price < $cost);
                        $no_price   = ($price <= 0);

                        if ($stock <= 0)       { $st_label = 'Out of Stock'; $st_class = 'badge-out';       $st_key = 'out'; }
                        elseif ($stock <= 10)  { $st_label = 'Low Stock';    $st_class = 'badge-low';       $st_key = 'low'; }
                        else                   { $st_label = 'Available';    $st_class = 'badge-available'; $st_key = 'available'; }

                        $row_class = $below_cost ? 'row-below-cost' : '';
                    ?>
                    <tr class="merch-row <?php echo $row_class; ?>"
                        data-name="<?php echo strtolower(htmlspecialchars($item['product_name'] ?? '')); ?>"
                        data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                        data-cat="<?php echo htmlspecialchars($cat_label); ?>"
                        data-status="<?php echo $st_key; ?>"
                        data-noprice="<?php echo $no_price ? '1' : '0'; ?>"
                        data-belowcost="<?php echo $below_cost ? '1' : '0'; ?>">
                        <td>
                            <strong><?php echo htmlspecialchars($item['product_name'] ?? ''); ?></strong>
                            <?php if ($below_cost): ?>
                                <span class="badge badge-warn" style="margin-left:6px;font-size:10px;">&#9888; Price Below Cost</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?php echo htmlspecialchars($item['sku'] ?? '&mdash;'); ?></td>
                        <td><?php echo htmlspecialchars($cat_label); ?></td>
                        <td class="muted"><?php echo htmlspecialchars($item['size'] ?? '&mdash;'); ?></td>
                        <td style="color:#64748b;">&#8369;<?php echo number_format($cost, 2); ?></td>
                        <td>
                            <?php if ($no_price): ?>
                                <span class="badge badge-noprice">No Price Set</span>
                            <?php else: ?>
                                <strong style="color:<?php echo $below_cost ? '#dc2626' : '#002F6C'; ?>">
                                    &#8369;<?php echo number_format($price, 2); ?>
                                </strong>
                            <?php endif; ?>
                            <?php if (($item['approval_status'] ?? '') === 'pending'): ?>
                                <div style="font-size:11px; color:#d97706; background:#fef3c7; padding:2px 6px; border-radius:4px; margin-top:4px; display:block; font-weight:600; text-align:left; line-height:1.3;">
                                    Pending Cost: ₱<?php echo number_format($item['pending_cost'], 2); ?><br>
                                    Pending Price: ₱<?php echo number_format($item['pending_price'], 2); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($stock, 0); ?></td>
                        <td><span class="badge <?php echo $st_class; ?>"><?php echo $st_label; ?></span></td>
                        <td>
                            <div class="act-btn-wrap">
                                <button onclick="openEditMerchPriceModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['product_name'] ?? ''); ?>', <?php echo $price; ?>)" class="act-btn act-btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if (($item['status'] ?? 'active') !== 'inactive'): ?>
                                    <button onclick="deactivateMerchandise(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['product_name'] ?? ''); ?>')" class="act-btn act-btn-deactivate">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                <?php else: ?>
                                    <button onclick="activateMerchandise(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['product_name'] ?? ''); ?>')" class="act-btn act-btn-activate">
                                        <i class="fas fa-check-circle"></i> Activate
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 3 — SERVICE TYPES
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-services" class="tab-panel <?php echo $active_tab === 'services' ? 'active' : ''; ?>">
    <?php if (isset($service_error)): ?>
        <div style="padding:20px;background:#fee2e2;color:#991b1b;border-radius:8px;margin-bottom:16px;">
            <strong>Notice:</strong> <?php echo htmlspecialchars($service_error); ?>
        </div>
    <?php endif; ?>
    
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:10px;">
                <strong style="font-size:15px;color:#002F6C;"><i class="fas fa-wrench"></i> Service Types</strong>
                <div style="color:#64748b;font-size:12px;">
                    Found <?php echo count($service_types); ?> service type(s)
                </div>
            </div>
            <button onclick="openAddServiceModal()" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:all 0.2s;">
                <i class="fas fa-plus-circle"></i> Add Service
            </button>
        </div>
        
        <?php if (empty($service_types)): ?>
            <div style="padding:28px;text-align:center;color:#94a3b8;">
                <i class="fas fa-wrench" style="font-size:32px;margin-bottom:10px;display:block;"></i>
                No service types found.
            </div>
        <?php else: ?>
            <div class="table-wrap" style="overflow-x:auto;">
                <table class="pricing-table">
                    <thead>
                        <tr>
                            <th>Service Name</th>
                            <th>Service Key</th>
                            <th>Price (&#8369;)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($service_types as $svc): 
                            $currentPrice = (float)$svc['service_price'];
                            $isServiceActive = (int)($svc['active'] ?? 1) === 1;
                            $statusDisplay = $isServiceActive ? 'Active' : 'Inactive';
                            $statusColor = $isServiceActive ? '#16a34a' : '#dc2626';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($svc['service_name']); ?></strong>
                            </td>
                            <td>
                                <span style="font-family:monospace;color:#64748b;font-size:12px;">
                                    <?php echo htmlspecialchars($svc['service_key']); ?>
                                </span>
                            </td>
                            <td>
                                <strong style="color:#002F6C;">&#8369;<?php echo number_format($currentPrice, 2); ?></strong>
                                <?php if (($svc['approval_status'] ?? '') === 'pending'): ?>
                                    <div style="font-size:11px; color:#d97706; background:#fef3c7; padding:2px 6px; border-radius:4px; margin-top:4px; display:inline-block; font-weight:600;">
                                        Pending: ₱<?php echo number_format($svc['pending_price'], 2); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color:<?php echo $statusColor; ?>;font-weight:600;"><?php echo $statusDisplay; ?></span>
                            </td>
                            <td>
                                <div class="act-btn-wrap">
                                    <button onclick="openEditServicePriceModal(<?php echo $svc['id']; ?>, '<?php echo htmlspecialchars($svc['service_name']); ?>', <?php echo $currentPrice; ?>)" class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if ($isServiceActive): ?>
                                        <button onclick="deactivateService(<?php echo $svc['id']; ?>, '<?php echo htmlspecialchars($svc['service_name']); ?>')" class="act-btn act-btn-deactivate">
                                            <i class="fas fa-ban"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button onclick="activateService(<?php echo $svc['id']; ?>, '<?php echo htmlspecialchars($svc['service_name']); ?>')" class="act-btn act-btn-activate">
                                            <i class="fas fa-check-circle"></i> Activate
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ── Tab switching — updates URL so refresh stays on same tab ─────────────────
function switchTab(name) {
    document.querySelectorAll('.tab-panel').forEach(function(p) {
        if (p) p.classList.remove('active');
    });
    var targetTab = document.getElementById('tab-' + name);
    if (targetTab) targetTab.classList.add('active');

    document.querySelectorAll('.ato-tab').forEach(function(btn) {
        btn.classList.remove('active');
    });
    var targetBtn = document.getElementById('tab-btn-' + name);
    if (targetBtn) targetBtn.classList.add('active');

    var activeSec = document.getElementById('activeSection');
    if (activeSec) activeSec.value = name;

    // Update URL without reloading so refresh lands on the same tab
    var url = new URL(window.location.href);
    url.searchParams.set('tab', name);
    window.history.replaceState(null, '', url.toString());
}

// ── Merchandise filter ───────────────────────────────────────────────────────
function filterTable() {
    var merchTab = document.getElementById('tab-merch');
    if (!merchTab || !merchTab.classList.contains('active')) {
        return;
    }
    
    var q          = document.getElementById('searchInput').value.toLowerCase().trim();
    var catFilter  = document.getElementById('catFilter').value;
    var stFilter   = document.getElementById('statusFilter').value;
    var rows       = document.querySelectorAll('#merchBody .merch-row');
    var catHeaders = document.querySelectorAll('#merchBody .cat-row');
    var visible    = 0;

    var catVisibleCount = {};

    rows.forEach(function(row) {
        var name      = row.getAttribute('data-name') || '';
        var sku       = row.getAttribute('data-sku')  || '';
        var cat       = row.getAttribute('data-cat')  || '';
        var status    = row.getAttribute('data-status') || '';
        var noprice   = row.getAttribute('data-noprice') === '1';
        var belowcost = row.getAttribute('data-belowcost') === '1';

        var matchQ   = !q || name.indexOf(q) !== -1 || sku.indexOf(q) !== -1;
        var matchCat = !catFilter || cat === catFilter;
        var matchSt  = true;
        if (stFilter === 'available') matchSt = status === 'available';
        else if (stFilter === 'low')  matchSt = status === 'low';
        else if (stFilter === 'out')  matchSt = status === 'out';
        else if (stFilter === 'noprice')   matchSt = noprice;
        else if (stFilter === 'belowcost') matchSt = belowcost;

        var show = matchQ && matchCat && matchSt;
        row.style.display = show ? '' : 'none';
        if (show) {
            visible++;
            catVisibleCount[cat] = (catVisibleCount[cat] || 0) + 1;
        }
    });

    catHeaders.forEach(function(hdr) {
        var cat = hdr.getAttribute('data-cat-header') || '';
        var count = catVisibleCount[cat] || 0;
        if (count > 0) {
            hdr.style.display = '';
            var countSpan = hdr.querySelector('.cat-count');
            if (countSpan) countSpan.textContent = '(' + count + ' item' + (count !== 1 ? 's' : '') + ')';
        } else {
            hdr.style.display = 'none';
        }
    });
}
</script>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODALS — Add Product & Edit Price
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- Add Product Modal -->
<div id="addProductModal" class="modal">
    <div style="background:#fff;border-radius:12px;width:90%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.3);animation:slideDown 0.3s ease-out;">
        <div style="background:#fff;border-bottom:2px solid #e2e8f0;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1;">
            <h3 style="margin:0;font-size:18px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-plus-circle" style="color:#64748b;"></i> Add New Fuel Product
            </h3>
        </div>
        <form id="addProductForm" style="padding:24px;">
            <div style="margin-bottom:18px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#334155;font-size:13px;">FUEL TYPE <span style="color:#dc2626;">*</span></label>
                <input type="text" id="newFuelType" required style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:6px;font-size:13px;transition:border-color 0.2s;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#e2e8f0'" placeholder="e.g. Unleaded, Diesel, Premium">
            </div>
            <div style="margin-bottom:18px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#334155;font-size:13px;">PRICE PER LITER (₱) <span style="color:#dc2626;">*</span></label>
                <input type="number" id="newPrice" step="0.01" min="0" required style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:6px;font-size:13px;transition:border-color 0.2s;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#e2e8f0'" placeholder="0.00">
            </div>
            <div style="margin-bottom:18px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#334155;font-size:13px;">CAPACITY (LITERS) <span style="color:#dc2626;">*</span></label>
                <input type="number" id="newCapacity" step="0.01" min="0" required style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:6px;font-size:13px;transition:border-color 0.2s;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#e2e8f0'" placeholder="0.00">
            </div>
            <div style="margin-bottom:24px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#334155;font-size:13px;">CRITICAL LEVEL (LITERS) <span style="color:#dc2626;">*</span></label>
                <input type="number" id="newCriticalLevel" step="0.01" min="0" required style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:6px;font-size:13px;transition:border-color 0.2s;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#e2e8f0'" placeholder="0.00">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="closeAddProductModal()" style="background:#e2e8f0;color:#475569;border:none;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:background 0.2s;" onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='#e2e8f0'">
                    Cancel
                </button>
                <button type="submit" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
                    <i class="fas fa-check"></i> Add Product
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Fuel Modal — Full Edit -->
<div id="editPriceModal" class="modal">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:580px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
    <div style="background:#002F6C !important;border-radius:12px 12px 0 0;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0 !important;font-size:17px !important;font-weight:800 !important;color:#ffffff !important;display:flex !important;align-items:center;gap:10px;letter-spacing:0.3px;"><i class="fas fa-gas-pump" style="font-size:18px;color:#ffffff !important;"></i> <span style="color:#ffffff !important;">EDIT FUEL PRODUCT</span></h3>
    </div>
    <form id="editPriceForm" style="padding:22px;">
      <input type="hidden" id="editFuelId">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Fuel Type <span style="color:#dc2626;">*</span></label><input type="text" id="editFuelType" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"></div>
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Price / Liter (₱) <span style="color:#dc2626;">*</span></label><input type="number" id="editPrice" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Capacity (L) <span style="color:#dc2626;">*</span></label><input type="number" id="editFuelCapacity" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00"></div>
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Critical Level (L) <span style="color:#dc2626;">*</span></label><input type="number" id="editFuelCritical" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00"></div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeEditPriceModal()" style="background:#f1f5f9;color:#ffffff !important;border:1px solid #e2e8f0;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#002F6C;color:#fff;border:none;padding:9px 22px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- View Fuel Details Modal -->
<div id="viewFuelModal" class="modal">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;">
                <div class="modal-icon"><i class="fas fa-gas-pump"></i></div>
                <div>
                    <div class="modal-title">Fuel Details</div>
                    <div class="modal-subtitle">View fuel information and price history</div>
                </div>
            </div>
        </div>
        <div class="modal-body">
            <!-- Fuel Information Grid -->
            <div class="modal-info-box">
                <h4><i class="fas fa-info-circle" style="margin-right:6px;"></i>Fuel Information</h4>
                <div class="modal-info-grid" style="grid-template-columns:repeat(4,1fr);">
                    <div>
                        <strong>FUEL TYPE</strong>
                        <span style="font-size:14px;font-weight:600;color:#002F6C;" id="viewFuelType">-</span>
                    </div>
                    <div>
                        <strong>CURRENT PRICE</strong>
                        <span style="font-size:14px;font-weight:700;color:#16a34a;" id="viewCurrentPrice">₱0.00</span>
                    </div>
                    <div>
                        <strong>STOCK</strong>
                        <span style="font-size:14px;font-weight:600;color:#002F6C;" id="viewStock">0 L</span>
                    </div>
                    <div>
                        <strong>TANK CAPACITY</strong>
                        <span style="font-size:14px;font-weight:600;color:#002F6C;" id="viewCapacity">0 L</span>
                    </div>
                    <div>
                        <strong>CRITICAL LEVEL</strong>
                        <span style="font-size:14px;font-weight:600;color:#dc2626;" id="viewCriticalLevel">0 L</span>
                    </div>
                    <div>
                        <strong>STATUS</strong>
                        <span style="font-size:14px;font-weight:600;" id="viewStatus">-</span>
                    </div>
                    <div>
                        <strong>LAST UPDATED</strong>
                        <span style="font-size:12px;color:#64748b;" id="viewLastUpdated">-</span>
                    </div>
                    <div>
                        <strong>UPDATED BY</strong>
                        <span style="font-size:12px;color:#64748b;" id="viewUpdatedBy">-</span>
                    </div>
                </div>
            </div>

            <!-- Price History Section -->
            <h4 style="margin:0 0 12px 0;font-size:13px;color:#003d7a;font-weight:700;"><i class="fas fa-history" style="margin-right:6px;"></i>Price History</h4>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:#f8fafc;">
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;">Previous Price</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;">New Price</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;">Reason</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;">Updated By</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;">Date</th>
                            <th style="padding:10px 12px;text-align:center;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="priceHistoryBody">
                        <tr>
                            <td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">No price history available</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="flt-btn flt-btn-reset" onclick="closeViewFuelModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- Rollback Price Modal -->
<div id="rollbackPriceModal" class="modal">
    <div class="modal-card">
        <div class="modal-head">
            <div style="display:flex;align-items:center;">
                <div class="modal-icon"><i class="fas fa-undo"></i></div>
                <div>
                    <div class="modal-title">Rollback Price</div>
                    <div class="modal-subtitle">Revert to previous price</div>
                </div>
            </div>
        </div>
        <form id="rollbackPriceForm">
            <input type="hidden" id="rollbackFuelId">
            <input type="hidden" id="rollbackHistoryId">
            
            <div class="modal-body">
                <div style="margin-bottom:15px;">
                    <label>FUEL</label>
                    <input type="text" id="rollbackFuelName" readonly class="input" style="background:#f8fafc;color:#64748b;">
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                    <div>
                        <label>CURRENT PRICE</label>
                        <input type="text" id="rollbackCurrentPrice" readonly class="input" style="background:#fef2f2;color:#dc2626;font-weight:700;">
                    </div>
                    <div>
                        <label>ROLLBACK TO</label>
                        <input type="text" id="rollbackToPrice" readonly class="input" style="background:#f0fdf4;color:#16a34a;font-weight:700;">
                    </div>
                </div>
                
                <div>
                    <label>REASON <span style="color:#dc2626;">*</span></label>
                    <textarea id="rollbackReason" required rows="3" class="input" placeholder="Explain why you are rolling back this price..."></textarea>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="flt-btn flt-btn-reset" onclick="closeRollbackModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="flt-btn flt-btn-danger"><i class="fas fa-undo"></i> Confirm Rollback</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Merchandise Modal -->
<div id="addMerchandiseModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
    <div style="background:#002F6C !important;border-radius:12px 12px 0 0;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0 !important;font-size:17px !important;font-weight:800 !important;color:#ffffff !important;display:flex !important;align-items:center;gap:10px;letter-spacing:0.3px;"><i class="fas fa-plus-circle" style="font-size:18px;color:#ffffff !important;"></i> <span style="color:#ffffff !important;">ADD NEW MERCHANDISE</span></h3>
    </div>
    <form id="addMerchandiseForm" style="padding:22px;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Product Name <span style="color:#dc2626;">*</span></label><input type="text" id="newMerchName" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="Product Name"></div>
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">SKU</label><input type="text" id="newMerchSku" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. ITEM-001"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Category <span style="color:#dc2626;">*</span></label><input type="text" id="newMerchCategory" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Air Fresheners"></div>
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Size / Unit</label><input type="text" id="newMerchSize" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. 500ml, Standard"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Unit Cost (₱) <span style="color:#dc2626;">*</span></label><input type="number" id="newMerchCost" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00"></div>
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Unit Price (₱) <span style="color:#dc2626;">*</span></label><input type="number" id="newMerchPrice" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00"></div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeAddMerchandiseModal()" style="background:#f1f5f9;color:#ffffff !important;border:1px solid #e2e8f0;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#002F6C;color:#fff;border:none;padding:9px 22px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-check"></i> Add Merchandise</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Merchandise Modal — Full Edit -->
<div id="editMerchPriceModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
    <div style="background:#002F6C !important;border-radius:12px 12px 0 0;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0 !important;font-size:17px !important;font-weight:800 !important;color:#ffffff !important;display:flex !important;align-items:center;gap:10px;letter-spacing:0.3px;"><i class="fas fa-box" style="font-size:18px;color:#ffffff !important;"></i> <span style="color:#ffffff !important;">EDIT MERCHANDISE</span></h3>
    </div>
    <form id="editMerchPriceForm" style="padding:22px;">
      <input type="hidden" id="editMerchId">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Product Name <span style="color:#dc2626;">*</span></label><input type="text" id="editMerchName" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"></div>
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">SKU</label><input type="text" id="editMerchSku" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. ITEM-001"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Category <span style="color:#dc2626;">*</span></label><input type="text" id="editMerchCategory" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"></div>
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Size / Unit</label><input type="text" id="editMerchSize" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. 500ml, Standard"></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Unit Cost (₱) <span style="color:#dc2626;">*</span></label><input type="number" id="editMerchCost" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00"></div>
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Unit Price (₱) <span style="color:#dc2626;">*</span></label><input type="number" id="editMerchPrice" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00"></div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeEditMerchPriceModal()" style="background:#f1f5f9;color:#ffffff !important;border:1px solid #e2e8f0;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#002F6C;color:#fff;border:none;padding:9px 22px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Service Modal -->
<div id="addServiceModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;width:90%;max-width:500px;box-shadow:0 8px 32px rgba(0,0,0,0.3);animation:slideDown 0.3s ease-out;">
        <div style="background:#fff;border-bottom:2px solid #e2e8f0;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;">
            <h3 style="margin:0;font-size:18px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-plus-circle" style="color:#64748b;"></i> Add New Service Type
            </h3>
        </div>
        <form id="addServiceForm" style="padding:24px;">
            <div style="margin-bottom:18px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#334155;font-size:13px;">Service Name <span style="color:#dc2626;">*</span></label>
                <input type="text" id="newServiceName" required style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:6px;font-size:13px;transition:border-color 0.2s;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#e2e8f0'" placeholder="e.g. Oil Change">
            </div>
            <div style="margin-bottom:18px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#334155;font-size:13px;">Service Key <span style="color:#dc2626;">*</span></label>
                <input type="text" id="newServiceKey" required style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:6px;font-size:13px;transition:border-color 0.2s;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#e2e8f0'" placeholder="e.g. oil_change">
                <small style="color:#64748b;font-size:11px;display:block;margin-top:4px;">Use lowercase letters and underscores only</small>
            </div>
            <div style="margin-bottom:24px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#334155;font-size:13px;">Service Price (₱) <span style="color:#dc2626;">*</span></label>
                <input type="number" id="newServicePrice" step="0.01" min="0" required style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:6px;font-size:13px;transition:border-color 0.2s;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#e2e8f0'" placeholder="0.00">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="closeAddServiceModal()" style="background:#e2e8f0;color:#475569;border:none;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:background 0.2s;" onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='#e2e8f0'">
                    Cancel
                </button>
                <button type="submit" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
                    <i class="fas fa-check"></i> Add Service
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Service Modal — Full Edit -->
<div id="editServicePriceModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
    <div style="background:#002F6C !important;border-radius:12px 12px 0 0;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0 !important;font-size:17px !important;font-weight:800 !important;color:#ffffff !important;display:flex !important;align-items:center;gap:10px;letter-spacing:0.3px;"><i class="fas fa-wrench" style="font-size:18px;color:#ffffff !important;"></i> <span style="color:#ffffff !important;">EDIT SERVICE TYPE</span></h3>
    </div>
    <form id="editServicePriceForm" style="padding:22px;">
      <input type="hidden" id="editServiceId">
      <div style="margin-bottom:14px;"><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Service Name <span style="color:#dc2626;">*</span></label><input type="text" id="editServiceName" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Service Key <span style="color:#dc2626;">*</span></label><input type="text" id="editServiceKey" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;font-family:monospace;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"><small style="color:#94a3b8;font-size:11px;">lowercase + underscores only</small></div>
        <div><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Price (₱) <span style="color:#dc2626;">*</span></label><input type="number" id="editServicePrice" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00"></div>
      </div>
      <div style="margin-bottom:18px;"><label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Status</label><select id="editServiceActive" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;"><option value="1">Active</option><option value="0">Inactive</option></select></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeEditServicePriceModal()" style="background:#f1f5f9;color:#ffffff !important;border:1px solid #e2e8f0;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#002F6C;color:#fff;border:none;padding:9px 22px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>



<style>
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<script>
// ── Modal functions ─────────────────────────────────────────────────────────
function openAddProductModal() {
    document.getElementById('addProductModal').style.display = 'flex';
    document.getElementById('newFuelType').focus();
}

function closeAddProductModal() {
    document.getElementById('addProductModal').style.display = 'none';
    document.getElementById('addProductForm').reset();
}

function openEditPriceModal(id, fuelType, currentPrice) {
    document.getElementById('editFuelId').value = id;
    document.getElementById('editFuelType').value = fuelType;
    document.getElementById('editPrice').value = currentPrice;
    // Fetch full details to populate capacity, critical level, status
    fetch('manager_set_prices_handler.php?action=get_fuel_details&id=' + id)
        .then(r => r.json()).then(data => {
            if (data.success && data.fuel) {
                document.getElementById('editFuelCapacity').value = parseFloat(data.fuel.capacity || 0);
                document.getElementById('editFuelCritical').value = parseFloat(data.fuel.critical_level || 0);
                document.getElementById('editFuelStatus').value = data.fuel.status || 'active';
            }
        });
    document.getElementById('editPriceModal').style.display = 'flex';
    document.getElementById('editFuelType').focus();
}

function closeEditPriceModal() {
    document.getElementById('editPriceModal').style.display = 'none';
    document.getElementById('editPriceForm').reset();
}

// Close modals on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddProductModal();
        closeEditPriceModal();
        closeViewFuelModal();
        closeRollbackModal();
        closeAddMerchandiseModal();
        closeEditMerchPriceModal();
        closeAddServiceModal();
        closeEditServicePriceModal();
    }
});

// Close modals on background click
document.getElementById('addProductModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddProductModal();
});
document.getElementById('editPriceModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditPriceModal();
});
document.getElementById('viewFuelModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewFuelModal();
});
document.getElementById('rollbackPriceModal').addEventListener('click', function(e) {
    if (e.target === this) closeRollbackModal();
});
document.getElementById('addMerchandiseModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddMerchandiseModal();
});
document.getElementById('editMerchPriceModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditMerchPriceModal();
});
document.getElementById('addServiceModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddServiceModal();
});
document.getElementById('editServicePriceModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditServicePriceModal();
});

// ── Add Product Form Handler ────────────────────────────────────────────────
document.getElementById('addProductForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    var fuelType = document.getElementById('newFuelType').value.trim();
    var price = parseFloat(document.getElementById('newPrice').value);
    var capacity = parseFloat(document.getElementById('newCapacity').value);
    var criticalLevel = parseFloat(document.getElementById('newCriticalLevel').value);
    
    if (!fuelType || price < 0 || capacity < 0 || criticalLevel < 0) {
        alert('Please fill all required fields with valid values.');
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'add_fuel_product');
    formData.append('fuel_type', fuelType);
    formData.append('price', price);
    formData.append('capacity', capacity);
    formData.append('critical_level', criticalLevel);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: Fuel product added successfully!');
            closeAddProductModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to add product'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error adding product. Please try again.');
    });
});

// ── Edit Fuel Full Form Handler ─────────────────────────────────────────────
document.getElementById('editPriceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('action', 'edit_fuel_full');
    fd.append('id',             document.getElementById('editFuelId').value);
    fd.append('fuel_type',      document.getElementById('editFuelType').value.trim());
    fd.append('price',          document.getElementById('editPrice').value);
    fd.append('capacity',       document.getElementById('editFuelCapacity').value);
    fd.append('critical_level', document.getElementById('editFuelCritical').value);
    fetch('manager_set_prices_handler.php', {method:'POST', body:fd})
        .then(r => r.json()).then(data => {
            if (data.success) { alert('SUCCESS: ' + (data.message || 'Fuel product updated!')); closeEditPriceModal(); location.reload(); }
            else alert('Error: ' + (data.message || 'Failed'));
        }).catch(() => alert('Error updating fuel.'));
});

// ── View Fuel Details ───────────────────────────────────────────────────────
function viewFuelDetails(fuelId) {
    // Fetch fuel details
    fetch('manager_set_prices_handler.php?action=get_fuel_details&id=' + fuelId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                var fuel = data.fuel;
                var history = data.history || [];
                
                // Populate fuel details
                document.getElementById('viewFuelType').textContent = fuel.fuel_type;
                document.getElementById('viewCurrentPrice').textContent = '₱' + parseFloat(fuel.price_per_liter).toFixed(2);
                document.getElementById('viewStock').textContent = parseFloat(fuel.current_level).toLocaleString() + ' L';
                document.getElementById('viewCapacity').textContent = parseFloat(fuel.capacity).toLocaleString() + ' L';
                document.getElementById('viewCriticalLevel').textContent = parseFloat(fuel.critical_level).toLocaleString() + ' L';
                document.getElementById('viewStatus').textContent = fuel.status.charAt(0).toUpperCase() + fuel.status.slice(1);
                document.getElementById('viewStatus').style.color = fuel.status === 'active' ? '#16a34a' : '#dc2626';
                document.getElementById('viewLastUpdated').textContent = fuel.last_updated || '-';
                document.getElementById('viewUpdatedBy').textContent = fuel.updated_by_name || '-';
                
                // Populate price history
                var historyBody = document.getElementById('priceHistoryBody');
                historyBody.innerHTML = '';
                
                if (history.length === 0) {
                    historyBody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">No price history available</td></tr>';
                } else {
                    history.forEach(function(h) {
                        var oldPrice = parseFloat(h.old_price);
                        var newPrice = parseFloat(h.new_price);
                        var arrow = newPrice > oldPrice ? '↑' : '↓';
                        var arrowColor = newPrice > oldPrice ? '#16a34a' : '#dc2626';
                        
                        var row = document.createElement('tr');
                        row.style.borderBottom = '1px solid #f1f5f9';
                        row.innerHTML = `
                            <td style="padding:10px 12px;">₱${oldPrice.toFixed(2)}</td>
                            <td style="padding:10px 12px;"><span style="color:${arrowColor};font-weight:700;">${arrow}</span> ₱${newPrice.toFixed(2)}</td>
                            <td style="padding:10px 12px;">${h.reason || '-'}</td>
                            <td style="padding:10px 12px;">${h.updated_by_name || '-'}</td>
                            <td style="padding:10px 12px;font-size:12px;color:#64748b;">${h.created_at || '-'}</td>
                            <td style="padding:10px 12px;text-align:center;">
                                <button onclick="openRollbackModal(${fuelId}, ${h.id}, '${fuel.fuel_type}', ${newPrice}, ${oldPrice})" 
                                        style="background:#dc2626;color:#fff;border:none;padding:5px 12px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;transition:background 0.2s;"
                                        onmouseover="this.style.background='#b91c1c'" 
                                        onmouseout="this.style.background='#dc2626'">
                                    <i class="fas fa-undo"></i> Rollback
                                </button>
                            </td>
                        `;
                        historyBody.appendChild(row);
                    });
                }
                
                document.getElementById('viewFuelModal').style.display = 'flex';
            } else {
                alert('Error: ' + (data.message || 'Failed to load fuel details'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading fuel details. Please try again.');
        });
}

function closeViewFuelModal() {
    document.getElementById('viewFuelModal').style.display = 'none';
}

// ── Rollback Price ──────────────────────────────────────────────────────────
function openRollbackModal(fuelId, historyId, fuelName, currentPrice, rollbackPrice) {
    document.getElementById('rollbackFuelId').value = fuelId;
    document.getElementById('rollbackHistoryId').value = historyId;
    document.getElementById('rollbackFuelName').value = fuelName;
    document.getElementById('rollbackCurrentPrice').value = '₱' + currentPrice.toFixed(2);
    document.getElementById('rollbackToPrice').value = '₱' + rollbackPrice.toFixed(2);
    document.getElementById('rollbackPriceModal').style.display = 'flex';
    document.getElementById('rollbackReason').focus();
}

function closeRollbackModal() {
    document.getElementById('rollbackPriceModal').style.display = 'none';
    document.getElementById('rollbackPriceForm').reset();
}

// Rollback Form Handler
document.getElementById('rollbackPriceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    var fuelId = document.getElementById('rollbackFuelId').value;
    var historyId = document.getElementById('rollbackHistoryId').value;
    var reason = document.getElementById('rollbackReason').value.trim();
    
    if (!fuelId || !historyId || !reason) {
        alert('Please provide a reason for the rollback.');
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'rollback_price');
    formData.append('fuel_id', fuelId);
    formData.append('history_id', historyId);
    formData.append('reason', reason);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: Price rolled back successfully!');
            closeRollbackModal();
            closeViewFuelModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to rollback price'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error rolling back price. Please try again.');
    });
});

// ── Deactivate Fuel ─────────────────────────────────────────────────────────
function deactivateFuel(id, fuelType) {
    if (!confirm('Are you sure you want to deactivate "' + fuelType + '"?\n\nThis will set the fuel status to inactive.')) {
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'deactivate_fuel');
    formData.append('id', id);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: Fuel product deactivated successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to deactivate product'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error deactivating product. Please try again.');
    });
}

// ── Activate Fuel ───────────────────────────────────────────────────────────
function activateFuel(id, fuelType) {
    if (!confirm('Are you sure you want to activate "' + fuelType + '"?\n\nThis will set the fuel status to active.')) {
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'activate_fuel');
    formData.append('id', id);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: Fuel product activated successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to activate product'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error activating product. Please try again.');
    });
}

// ══════════════════════════════════════════════════════════════════════════
// MERCHANDISE FUNCTIONS
// ══════════════════════════════════════════════════════════════════════════

function openAddMerchandiseModal() {
    document.getElementById('addMerchandiseModal').style.display = 'flex';
    document.getElementById('newMerchName').focus();
}

function closeAddMerchandiseModal() {
    document.getElementById('addMerchandiseModal').style.display = 'none';
    document.getElementById('addMerchandiseForm').reset();
}

function openEditMerchPriceModal(id, productName, currentPrice) {
    document.getElementById('editMerchId').value = id;
    document.getElementById('editMerchName').value = productName;
    document.getElementById('editMerchPrice').value = currentPrice;
    // Fetch full details
    fetch('manager_set_prices_handler.php?action=get_merch_details&id=' + id)
        .then(r => r.json()).then(data => {
            if (data.success && data.item) {
                var i = data.item;
                document.getElementById('editMerchName').value     = i.product_name || productName;
                document.getElementById('editMerchSku').value      = i.sku || '';
                document.getElementById('editMerchCategory').value = i.category || '';
                document.getElementById('editMerchSize').value     = i.size || '';
                document.getElementById('editMerchCost').value     = parseFloat(i.unit_cost || 0);
                document.getElementById('editMerchPrice').value    = parseFloat(i.unit_price || currentPrice);
            }
        });
    document.getElementById('editMerchPriceModal').style.display = 'flex';
    document.getElementById('editMerchName').focus();
}

function closeEditMerchPriceModal() {
    document.getElementById('editMerchPriceModal').style.display = 'none';
    document.getElementById('editMerchPriceForm').reset();
}

// Add Merchandise Form Handler
document.getElementById('addMerchandiseForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    var name = document.getElementById('newMerchName').value.trim();
    var category = document.getElementById('newMerchCategory').value.trim();
    var cost = parseFloat(document.getElementById('newMerchCost').value);
    var price = parseFloat(document.getElementById('newMerchPrice').value);
    var sku = document.getElementById('newMerchSku').value.trim();
    var size = document.getElementById('newMerchSize').value.trim();
    
    if (!name || !category || cost < 0 || price < 0) {
        alert('Please fill all required fields with valid values.');
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'add_merchandise');
    formData.append('product_name', name);
    formData.append('category', category);
    formData.append('unit_cost', cost);
    formData.append('unit_price', price);
    formData.append('sku', sku);
    formData.append('size', size);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: Merchandise added successfully!');
            closeAddMerchandiseModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to add merchandise'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error adding merchandise. Please try again.');
    });
});

// Edit Merchandise Full Form Handler
document.getElementById('editMerchPriceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('action',       'edit_merchandise_full');
    fd.append('id',           document.getElementById('editMerchId').value);
    fd.append('product_name', document.getElementById('editMerchName').value.trim());
    fd.append('sku',          document.getElementById('editMerchSku').value.trim());
    fd.append('category',     document.getElementById('editMerchCategory').value.trim());
    fd.append('size',         document.getElementById('editMerchSize').value.trim());
    fd.append('unit_cost',    document.getElementById('editMerchCost').value);
    fd.append('unit_price',   document.getElementById('editMerchPrice').value);
    fetch('manager_set_prices_handler.php', {method:'POST', body:fd})
        .then(r => r.json()).then(data => {
            if (data.success) { alert('SUCCESS: Merchandise updated!'); closeEditMerchPriceModal(); location.reload(); }
            else alert('Error: ' + (data.message || 'Failed'));
        }).catch(() => alert('Error updating merchandise.'));
});

function deactivateMerchandise(id, productName) {
    if (!confirm('Are you sure you want to deactivate "' + productName + '"?\n\nThis will set the merchandise status to inactive.')) {
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'deactivate_merchandise');
    formData.append('id', id);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: Merchandise deactivated successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to deactivate merchandise'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error deactivating merchandise. Please try again.');
    });
}

function activateMerchandise(id, productName) {
    if (!confirm('Are you sure you want to activate "' + productName + '"?\n\nThis will set the merchandise status to active.')) {
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'activate_merchandise');
    formData.append('id', id);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: Merchandise activated successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to activate merchandise'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error activating merchandise. Please try again.');
    });
}

// ══════════════════════════════════════════════════════════════════════════
// SERVICE FUNCTIONS
// ══════════════════════════════════════════════════════════════════════════

function openAddServiceModal() {
    document.getElementById('addServiceModal').style.display = 'flex';
    document.getElementById('newServiceName').focus();
}

function closeAddServiceModal() {
    document.getElementById('addServiceModal').style.display = 'none';
    document.getElementById('addServiceForm').reset();
}

function openEditServicePriceModal(id, serviceName, currentPrice) {
    document.getElementById('editServiceId').value = id;
    document.getElementById('editServiceName').value = serviceName;
    document.getElementById('editServicePrice').value = currentPrice;
    // Fetch full details
    fetch('manager_set_prices_handler.php?action=get_service_details&id=' + id)
        .then(r => r.json()).then(data => {
            if (data.success && data.service) {
                var s = data.service;
                document.getElementById('editServiceName').value   = s.service_name || serviceName;
                document.getElementById('editServiceKey').value    = s.service_key  || '';
                document.getElementById('editServicePrice').value  = parseFloat(s.service_price || currentPrice);
                document.getElementById('editServiceActive').value = (parseInt(s.active) === 1) ? '1' : '0';
            }
        });
    document.getElementById('editServicePriceModal').style.display = 'flex';
    document.getElementById('editServiceName').focus();
}

function closeEditServicePriceModal() {
    document.getElementById('editServicePriceModal').style.display = 'none';
    document.getElementById('editServicePriceForm').reset();
}

// Add Service Form Handler
document.getElementById('addServiceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    var name = document.getElementById('newServiceName').value.trim();
    var key = document.getElementById('newServiceKey').value.trim();
    var price = parseFloat(document.getElementById('newServicePrice').value);
    
    if (!name || !key || price < 0) {
        alert('Please fill all required fields with valid values.');
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'add_service');
    formData.append('service_name', name);
    formData.append('service_key', key);
    formData.append('service_price', price);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: Service type added successfully!');
            closeAddServiceModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to add service'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error adding service. Please try again.');
    });
});

// Edit Service Full Form Handler
document.getElementById('editServicePriceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('action',        'edit_service_full');
    fd.append('id',            document.getElementById('editServiceId').value);
    fd.append('service_name',  document.getElementById('editServiceName').value.trim());
    fd.append('service_key',   document.getElementById('editServiceKey').value.trim());
    fd.append('service_price', document.getElementById('editServicePrice').value);
    fd.append('active',        document.getElementById('editServiceActive').value);
    fetch('manager_set_prices_handler.php', {method:'POST', body:fd})
        .then(r => r.json()).then(data => {
            if (data.success) { alert('SUCCESS: Service updated!'); closeEditServicePriceModal(); location.reload(); }
            else alert('Error: ' + (data.message || 'Failed'));
        }).catch(() => alert('Error updating service.'));
});

function deactivateService(id, serviceName) {
    if (!confirm('Are you sure you want to deactivate "' + serviceName + '"?\n\nThis will set the service status to inactive.')) {
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'deactivate_service');
    formData.append('id', id);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: Service deactivated successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to deactivate service'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error deactivating service. Please try again.');
    });
}

function activateService(id, serviceName) {
    if (!confirm('Are you sure you want to activate "' + serviceName + '"?\n\nThis will set the service status to active.')) {
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'activate_service');
    formData.append('id', id);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SUCCESS: Service activated successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to activate service'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error activating service. Please try again.');
    });
}

</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
