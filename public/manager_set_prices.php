<?php
// Force browser to always load fresh — prevents stale CSS/JS cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$page_id = 'manager_set_prices';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

// Schema safety: widen fuel_inventory status column to VARCHAR(50) so 'active' and 'inactive' persist across page refreshes
try {
    $pdo->exec("ALTER TABLE fuel_inventory MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
} catch (Exception $e) {}

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

    $target_sid = $station_id;
    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_inventory WHERE station_id = ?");
    $check_stmt->execute([$target_sid]);
    if ((int)$check_stmt->fetchColumn() === 0) {
        $target_sid = 1;
    }

    $fi_lookup = [];
    $fi_lookup_by_id = [];
    $fi_status_by_id = []; // track active/inactive by ID
    $s = $pdo->prepare("SELECT id, fuel_type, ugt_no, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, reorder_level, critical_level FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fuel_key = strtolower(trim($row['fuel_type']));
        $ugt_val  = strtolower(trim($row['ugt_no'] ?? ''));

        if (!isset($fi_lookup[$fuel_key])) {
            $fi_lookup[$fuel_key] = $row;
        }
        if ($ugt_val) {
            $fi_lookup[$ugt_val] = $row;
        }

        if (preg_match('/diesel\s*(\d)/i', $fuel_key, $m)) {
            $k = 'diesel ' . $m[1];
            if (!isset($fi_lookup[$k])) $fi_lookup[$k] = $row;
        }
        if (strpos($fuel_key, 'diesel') !== false && strpos($fuel_key, 'turbo') === false) {
            if (!isset($fi_lookup['diesel'])) $fi_lookup['diesel'] = $row;
        }
        if (preg_match('/(xtra unl|xtra unl)\s*(\d)/i', $fuel_key, $m)) {
            $k = 'xtra unl ' . $m[2];
            if (!isset($fi_lookup[$k])) $fi_lookup[$k] = $row;
            if (!isset($fi_lookup['xtra unl'])) $fi_lookup['xtra unl'] = $row;
        }
        if (strpos($fuel_key, 'xtra') !== false || strpos($fuel_key, 'unl') !== false) {
            if (!isset($fi_lookup['xtra unl'])) $fi_lookup['xtra unl'] = $row;
        }
        if (strpos($fuel_key, 'xcs') !== false) {
            if (!isset($fi_lookup['xcs plus'])) $fi_lookup['xcs plus'] = $row;
        }
        if (strpos($fuel_key, 'kerosene') !== false) {
            if (!isset($fi_lookup['kerosene'])) $fi_lookup['kerosene'] = $row;
        }
        if (strpos($fuel_key, 'turbo') !== false) {
            if (!isset($fi_lookup['turbo diesel'])) $fi_lookup['turbo diesel'] = $row;
        }

        $fi_lookup_by_id[(int)$row['id']] = $row;
        $st_lower = strtolower(trim($row['status'] ?? ''));
        $fi_status_by_id[(int)$row['id']] = in_array($st_lower, ['inactive', 'disabled', 'deactivated'], true) ? 'inactive' : 'active';
    }

    $del_lookup = [];
    $s = $pdo->prepare("SELECT tank_assigned, fuel_type, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id = ? AND DATE(delivery_date) = CURDATE() AND status = 'Verified' GROUP BY tank_assigned, fuel_type");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];
    }

    $sales_lookup = [];
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'Verified' GROUP BY fuel_type");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];
    }

    $adj_lookup = [];
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id = fi.fuel_type_id AND fi.station_id = fa.station_id WHERE fa.station_id = ? AND DATE(fa.adjustment_date) = CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];
    }

    $price_lookup = [];
    $s = $pdo->prepare("SELECT ft.name AS fuel_type, fp.price_per_liter FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id = ft.id WHERE fp.station_id = ? AND fp.is_active = 1 ORDER BY fp.effective_date DESC");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim($row['fuel_type']));
        if (!isset($price_lookup[$key])) $price_lookup[$key] = (float)$row['price_per_liter'];
    }

    $pending_approvals = [];
    try {
        $s_pa = $pdo->prepare("SELECT id AS approval_id, product_id, fuel_type_id, product_name, COALESCE(new_price, new_value) AS new_value, old_price, new_price, status, reason, requested_by, created_at, station_id FROM pending_price_approvals WHERE status = 'pending' AND product_type IN ('fuel', 'fuel_inventory')");
        $s_pa->execute();
        foreach ($s_pa->fetchAll(PDO::FETCH_ASSOC) as $p_row) {
            $pid = (int)($p_row['product_id'] ?? 0);
            $ftid = (int)($p_row['fuel_type_id'] ?? 0);
            $pname = strtolower(trim($p_row['product_name'] ?? ''));

            if ($pid > 0) {
                $pending_approvals['id_' . $pid] = $p_row;
            }
            if ($ftid > 0) {
                $pending_approvals['id_' . $ftid] = $p_row;
            }
            if ($pname) {
                $pending_approvals['name_' . $pname] = $p_row;
                $canonical_pname = strtolower(get_canonical_fuel_name($pname));
                $pending_approvals['canon_' . $canonical_pname] = $p_row;
            }
        }
    } catch (Exception $e) {}

    foreach ($TANK_CONFIG_17 as $tc) {
        $ft_key = strtolower(trim($tc['fuel_type']));
        $tank_num = $tc['tanker_num'];

        $inv = null;
        if (isset($fi_lookup[$ft_key . '_tank_' . $tank_num])) {
            $inv = $fi_lookup[$ft_key . '_tank_' . $tank_num];
        } elseif (isset($fi_lookup[$ft_key . '_' . strtolower(trim($tc['tank']))])) {
            $inv = $fi_lookup[$ft_key . '_' . strtolower(trim($tc['tank']))];
        } elseif (isset($fi_lookup[$ft_key . '_' . strtolower(trim($tc['label']))])) {
            $inv = $fi_lookup[$ft_key . '_' . strtolower(trim($tc['label']))];
        } elseif (isset($fi_lookup[strtolower(trim($tc['tank']))])) {
            $inv = $fi_lookup[strtolower(trim($tc['tank']))];
        } elseif ($ft_key === 'xtra unl' || $ft_key === 'xtr advance') {
            $cand = '';
            if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'xtra unl 1'; }
            elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'xtra unl 2'; }
            if ($cand && isset($fi_lookup[$cand])) { $inv = $fi_lookup[$cand]; }
            else { $inv = $fi_lookup['xtra unl'] ?? null; }
        } elseif ($ft_key === 'diesel') {
            $cand = '';
            if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'diesel 1'; }
            elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'diesel 2'; }
            if ($cand && isset($fi_lookup[$cand])) { $inv = $fi_lookup[$cand]; }
            else { $inv = $fi_lookup['diesel'] ?? null; }
        } else {
            $inv = $fi_lookup[$ft_key] ?? null;
        }

        $tank_key = strtolower(trim($tc['tank']));
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

        $price = ($inv && (float)($inv['price_per_liter'] ?? 0) > 0) ? (float)$inv['price_per_liter'] : ($price_lookup[$ft_key] ?? 0);
        $timestamp = $inv['last_updated'] ?? null;
        
        $critical_level = $inv ? (float)($inv['critical_level'] ?? 0) : 0;
        if ($critical_level <= 0) {
            $critical_level = ($capacity == 14000) ? 2500 : (($capacity == 7000) ? 1000 : $capacity * 0.10);
        }
        $reorder_level = $inv ? (float)($inv['reorder_level'] ?? 0) : 0;
        if ($reorder_level <= 0) {
            $reorder_level = ($capacity == 14000) ? 5000 : (($capacity == 7000) ? 2000 : $capacity * 0.20);
        }

        $inv_id = $inv['id'] ?? null;
        $inv_name = strtolower(trim($inv['fuel_type'] ?? $tc['fuel_type'] ?? ''));
        $inv_canonical = strtolower(get_canonical_fuel_name($inv_name));
        $ugt_name = strtolower(trim($tc['tank'] ?? ''));

        $app = null;
        if ($inv_id && isset($pending_approvals['id_' . (int)$inv_id])) {
            $app = $pending_approvals['id_' . (int)$inv_id];
        } elseif ($inv_name && isset($pending_approvals['name_' . $inv_name])) {
            $app = $pending_approvals['name_' . $inv_name];
        } elseif ($inv_canonical && isset($pending_approvals['canon_' . $inv_canonical])) {
            $app = $pending_approvals['canon_' . $inv_canonical];
        } elseif ($ugt_name && isset($pending_approvals['name_' . $ugt_name])) {
            $app = $pending_approvals['name_' . $ugt_name];
        }

        $fuel_products[] = [
            'id'             => $inv_id,
            'pump_id'        => $tc['tanker_num'],
            'ugt_no'         => $tc['tank'],
            'tank_label'     => $tc['label'],
            'raw_fuel_type'  => $tc['fuel_type'],
            'capacity'       => $capacity,
            'current_stock'  => $ending_system,
            'critical_level' => $critical_level,
            'reorder_level'  => $reorder_level,
            'status'         => $status,
            'inv_status'     => $inv_id ? ($fi_status_by_id[(int)$inv_id] ?? 'active') : 'active',
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
$all_brands     = [];
$all_units      = [];
$all_suppliers  = [];

try {
    $rows = load_merchandise_pricing_catalog($pdo, (int)$station_id);

    foreach ($rows as $row) {
        $cat    = $row['category_name'] ?? $row['category'] ?? 'Uncategorized';
        $cost   = (float)($row['unit_cost']  ?? 0);
        $price  = (float)($row['unit_price'] ?? 0);
        $stock  = (float)($row['stock_quantity'] ?? $row['stock'] ?? 0);

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
        $all_brands[$row['brand'] ?? 'Generic'] = true;
        $all_units[$row['unit'] ?? 'Piece (pc)'] = true;
        $all_suppliers[$row['supplier'] ?? 'Petron Corporation'] = true;
    }
} catch (Exception $e) {
    $merch_by_cat = [];
    error_log('[manager_set_prices] merch error: ' . $e->getMessage());
}

// ── Pre-load merchandise batches per product ──────────────────────────────
$merch_batches_by_product = [];
try {
    $bStmt = $pdo->prepare("
        SELECT mb.*
        FROM merchandise_batches mb
        WHERE mb.station_id = ? AND LOWER(COALESCE(mb.status, 'active')) NOT IN ('cancelled', 'disabled')
        ORDER BY mb.date_received ASC, mb.id ASC
    ");
    $bStmt->execute([(int)$station_id]);
    foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $merch_batches_by_product[(int)$b['product_id']][] = $b;
    }
} catch (Exception $e) {}

$all_categories = array_keys($all_categories);
sort($all_categories);
$all_brands = array_keys($all_brands);
sort($all_brands);
$all_units = array_keys($all_units);
sort($all_units);
$all_suppliers = array_keys($all_suppliers);
sort($all_suppliers);

// ── Ensure job_order_service_types table exists & fetch service types ──────
$service_types = [];
$service_error = null;
try {
    // Migration safety: add new columns if missing
    $new_cols = [
        "ALTER TABLE job_order_service_types ADD COLUMN IF NOT EXISTS service_code VARCHAR(20) DEFAULT NULL AFTER id",
        "ALTER TABLE job_order_service_types ADD COLUMN IF NOT EXISTS labor_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER service_price",
        "ALTER TABLE job_order_service_types ADD COLUMN IF NOT EXISTS estimated_duration INT DEFAULT 60 AFTER labor_fee",
        "ALTER TABLE job_order_service_types ADD COLUMN IF NOT EXISTS required_mechanics INT DEFAULT 1 AFTER estimated_duration",
        "ALTER TABLE job_order_service_types ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL AFTER required_mechanics",
    ];
    foreach ($new_cols as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

    // Back-fill service codes for rows missing them
    $pdo->exec("UPDATE job_order_service_types SET service_code = CONCAT('SVC-', LPAD(id, 4, '0')) WHERE service_code IS NULL OR service_code = ''");

    $stmt = $pdo->prepare("
        SELECT s.id, s.service_code, s.service_name, s.service_key, s.category,
               s.service_price, s.labor_fee, s.estimated_duration, s.required_mechanics,
               s.description, s.status, s.active, s.updated_at,
               p.new_price  AS pending_service_fee,
               p.new_cost   AS pending_labor_fee,
               p.status     AS approval_status,
               p.id         AS approval_id
        FROM job_order_service_types s
        LEFT JOIN pending_price_approvals p
               ON s.id = p.product_id
              AND p.product_type = 'service'
              AND p.status = 'pending'
        ORDER BY s.category ASC, s.service_name ASC
    ");
    $stmt->execute();
    $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $service_types = [];
    $service_error = null;
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
body, html { overflow-x: hidden; max-width: 100%; }
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

/* Table tweaks - Fix horizontal overflow */
.table-wrap {
    overflow-x: hidden !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
.pricing-table {
    width: 100% !important;
    max-width: 100% !important;
    border-collapse: collapse !important;
    box-sizing: border-box !important;
}
#merchTable {
    table-layout: fixed !important;
}
.pricing-table th {
    background: #002F70 !important; 
    color: #fff !important; 
    padding: 8px 6px !important;
    font-size: 10px !important;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
    white-space: nowrap;
}
.pricing-table td {
    padding: 6px 5px !important;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    white-space: normal !important;
    word-break: break-word !important;
    font-size: 11px !important;
}
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

/* == Action buttons — ultra crisp & visible outline style == */
.act-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 5px !important;
    padding: 5px 10px !important;
    border-radius: 6px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    width: 100% !important;
    max-width: 110px !important;
    margin-bottom: 4px !important;
    transition: all .18s ease-in-out !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
    opacity: 1 !important;
}
.act-btn:last-child { margin-bottom: 0 !important; }

.act-btn-view { color: #16a34a !important; -webkit-text-fill-color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.act-btn-view:hover { background: #16a34a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }

.act-btn-edit { color: #002F6C !important; -webkit-text-fill-color: #002F6C !important; border-color: #002F6C !important; background: #ffffff !important; }
.act-btn-edit:hover { background: #002F6C !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }

.act-btn-deactivate { color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.act-btn-deactivate:hover { background: #dc2626 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }

.act-btn-activate { color: #16a34a !important; -webkit-text-fill-color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.act-btn-activate:hover { background: #16a34a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }

.act-btn-batches { color: #0284c7 !important; -webkit-text-fill-color: #0284c7 !important; border-color: #0284c7 !important; background: #ffffff !important; }
.act-btn-batches:hover { background: #0284c7 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }

.act-btn-history { color: #6366f1 !important; -webkit-text-fill-color: #6366f1 !important; border-color: #c7d2fe !important; background: #ffffff !important; }
.act-btn-history:hover { background: #6366f1 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }

.act-btn i { color: inherit !important; -webkit-text-fill-color: inherit !important; }
.act-btn-wrap { display: flex; flex-direction: column; gap: 3px; width: 100%; align-items: center; }

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
        <div style="padding:14px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:flex-end;">
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
                        <th>Current Volume (L)</th>
                        <th>Capacity (L)</th>
                        <th>Critical Level (L)</th>
                        <th>Reorder Level (L)</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($fuel_products)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:28px;color:#94a3b8;">
                            <i class="fas fa-info-circle"></i> No fuel inventory records found for this station.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    foreach ($fuel_products as $f):
                        $level    = $f['current_stock'];
                        $critical = $f['critical_level'];
                        $reorder  = $f['reorder_level'] ?? 0;
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
                        $ugt_str = $f['ugt_no'] ?? ('UGT #' . $f['pump_id']);
                        $canonical_type = get_canonical_fuel_name($f['raw_fuel_type']);
                        $full_fuel_name = $canonical_type;
                    ?>
                    <tr>
                        <td>
                            <strong style="font-family:monospace;color:#002F6C;font-size:14px;"><?php echo htmlspecialchars($ugt_str); ?></strong>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($full_fuel_name); ?></strong>
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
                        <td><strong style="color:#475569;"><?php echo number_format((float)$reorder, 2); ?></strong></td>
                        <td>
                            <?php if ($status_label === 'Critical'): ?>
                                <span class="badge <?php echo $status_class; ?>">Critical</span>
                            <?php elseif ($status_label === 'Low' || $status_label === 'Low Stock'): ?>
                                <span class="badge <?php echo $status_class; ?>">Low Stock</span>
                            <?php elseif ($status_label === 'Out of Stock'): ?>
                                <span class="badge <?php echo $status_class; ?>">Out of Stock</span>
                            <?php else: ?>
                                <span class="badge <?php echo $status_class; ?>">Normal</span>
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
                                    <button onclick="openEditPriceModal(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($full_fuel_name)); ?>', <?php echo (float)($f['price_per_liter'] ?? 0); ?>, <?php echo (float)($f['capacity'] ?? 0); ?>, <?php echo (float)($f['critical_level'] ?? 0); ?>, <?php echo (float)($f['reorder_level'] ?? 0); ?>, '<?php echo htmlspecialchars(addslashes($ugt_str)); ?>')" class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php 
                                    $fuel_active_status = strtolower($f['inv_status'] ?? ($f['status'] ?? 'active'));
                                    if ($fuel_active_status !== 'inactive'): ?>
                                        <button onclick="toggleFuelStatus(<?php echo $f['id']; ?>, 'inactive', '<?php echo htmlspecialchars(addslashes($canonical_type)); ?>')" class="act-btn act-btn-deactivate">
                                            <i class="fas fa-ban"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button onclick="toggleFuelStatus(<?php echo $f['id']; ?>, 'active', '<?php echo htmlspecialchars(addslashes($canonical_type)); ?>')" class="act-btn act-btn-activate">
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
        <select id="brandFilter" onchange="filterTable()">
            <option value="">All Brands</option>
            <?php foreach ($all_brands as $brand): ?>
                <option value="<?php echo htmlspecialchars($brand); ?>"><?php echo htmlspecialchars($brand); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="unitFilter" onchange="filterTable()">
            <option value="">All UOMs</option>
            <?php foreach ($all_units as $unit): ?>
                <option value="<?php echo htmlspecialchars($unit); ?>"><?php echo htmlspecialchars($unit); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="supplierFilter" onchange="filterTable()">
            <option value="">All Suppliers</option>
            <?php foreach ($all_suppliers as $supplier): ?>
                <option value="<?php echo htmlspecialchars($supplier); ?>"><?php echo htmlspecialchars($supplier); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="statusFilter" onchange="filterTable()">
            <option value="">All Statuses</option>
            <option value="available">Available</option>
            <option value="low">Low Stock</option>
            <option value="critical">Critical Stock</option>
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
        <div class="table-wrap" style="overflow-x:hidden; width:100%;">
            <table class="pricing-table" id="merchTable" style="width:100%; table-layout:fixed;">
                <colgroup>
                    <col style="width:90px;">   <!-- SKU / Code -->
                    <col style="width:20%;">    <!-- Product Name -->
                    <col style="width:130px;">  <!-- Category -->
                    <col style="width:110px;">  <!-- Brand -->
                    <col style="width:95px;">   <!-- UOM -->
                    <col style="width:140px;">  <!-- Default Selling Price -->
                    <col style="width:85px;">   <!-- Reorder Lvl -->
                    <col style="width:85px;">   <!-- Critical Lvl -->
                    <col style="width:105px;">  <!-- Status -->
                    <col style="width:125px;">  <!-- Actions -->
                </colgroup>
                <thead>
                    <tr>
                        <th style="text-align:left;padding:10px 8px;">SKU / Code</th>
                        <th style="text-align:left;padding:10px 8px;">Product Name</th>
                        <th style="text-align:left;padding:10px 8px;">Category</th>
                        <th style="text-align:left;padding:10px 8px;">Brand</th>
                        <th style="text-align:left;padding:10px 8px;">UOM</th>
                        <th style="text-align:right;padding:10px 8px;">Default Selling Price</th>
                        <th style="text-align:center;padding:10px 8px;">Reorder Lvl</th>
                        <th style="text-align:center;padding:10px 8px;">Critical Lvl</th>
                        <th style="text-align:center;padding:10px 8px;">Status</th>
                        <th style="text-align:center;padding:10px 8px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="merchBody">
                <?php foreach ($merch_by_cat as $cat_label => $items): ?>
                    <tr class="cat-row" data-cat-header="<?php echo htmlspecialchars($cat_label); ?>">
                        <td colspan="10">
                            <i class="fas fa-folder"></i>
                            <?php echo htmlspecialchars($cat_label); ?>
                            <span class="muted cat-count" style="font-weight:400;margin-left:6px;">(<?php echo count($items); ?> items)</span>
                        </td>
                    </tr>
                    <?php foreach ($items as $item):
                        $price         = $item['_price'];
                        $stock         = $item['_stock'];
                        $reorder_level = (int)($item['reorder_level']  ?? 24);
                        $critical_lvl  = (int)($item['critical_level'] ?? 10);
                        $no_price      = ($price <= 0);
                        $brand_display = htmlspecialchars($item['brand'] ?? '—');

                        if ($stock <= 0)                { $st_label = 'Out of Stock';   $st_class = 'badge-out';      $st_key = 'out'; }
                        elseif ($stock <= $critical_lvl){ $st_label = 'Critical Stock'; $st_class = 'badge-critical'; $st_key = 'critical'; }
                        elseif ($stock <= $reorder_level){ $st_label = 'Low Stock';     $st_class = 'badge-low';      $st_key = 'low'; }
                        else                            { $st_label = 'Available';      $st_class = 'badge-available';$st_key = 'available'; }

                        $product_status = strtolower(trim($item['status'] ?? 'active'));
                        $is_inactive = in_array($product_status, ['inactive','disabled','deactivated']);
                    ?>
                    <tr class="merch-row"
                        data-name="<?php echo strtolower(htmlspecialchars($item['product_name'] ?? '')); ?>"
                        data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                        data-brand="<?php echo strtolower(htmlspecialchars($item['brand'] ?? '')); ?>"
                        data-unit="<?php echo strtolower(htmlspecialchars($item['unit'] ?? '')); ?>"
                        data-supplier="<?php echo strtolower(htmlspecialchars($item['supplier'] ?? 'Petron Corporation')); ?>"
                        data-cat="<?php echo htmlspecialchars($cat_label); ?>"
                        data-status="<?php echo $st_key; ?>"
                        data-noprice="<?php echo $no_price ? '1' : '0'; ?>"
                        <?php if ($is_inactive): ?>style="opacity:0.6;background:#f8f9fa;"<?php endif; ?>>
                        <!-- SKU / Code -->
                        <td>
                            <code style="font-size:11px;color:#4f46e5;background:#ede9fe;padding:2px 6px;border-radius:4px;font-weight:700;">
                                <?php echo htmlspecialchars($item['sku'] ?? '—'); ?>
                            </code>
                        </td>
                        <!-- Product Name -->
                        <td>
                            <strong style="color:#1e293b;font-size:13px;"><?php echo htmlspecialchars($item['product_name'] ?? ''); ?></strong>
                            <?php if ($is_inactive): ?>
                                <span style="margin-left:5px;font-size:10px;background:#e2e8f0;color:#64748b;padding:1px 5px;border-radius:3px;font-weight:600;">INACTIVE</span>
                            <?php endif; ?>
                            <?php if (($item['approval_status'] ?? '') === 'pending'): ?>
                                <div style="font-size:10px;color:#d97706;background:#fef3c7;padding:2px 5px;border-radius:3px;margin-top:2px;font-weight:600;display:inline-block;">Pending: ₱<?php echo number_format($item['pending_price'], 2); ?></div>
                            <?php endif; ?>
                        </td>
                        <!-- Category -->
                        <td style="font-size:12px;color:#334155;"><?php echo htmlspecialchars($cat_label); ?></td>
                        <!-- Brand -->
                        <td style="font-size:12px;color:#64748b;"><?php echo $brand_display ?: '—'; ?></td>
                        <!-- UOM -->
                        <td style="font-size:12px;color:#334155;font-weight:500;"><?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></td>
                        <!-- Default Selling Price -->
                        <td style="text-align:right;">
                            <?php if ($no_price): ?>
                                <span class="badge badge-noprice">No Price Set</span>
                            <?php else: ?>
                                <strong style="color:#002F6C;font-size:13px;">&#8369;<?php echo number_format($price, 2); ?></strong>
                            <?php endif; ?>
                        </td>
                        <!-- Reorder Level -->
                        <td style="text-align:center;">
                            <span style="display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;padding:2px 8px;font-size:12px;font-weight:700;"><?php echo number_format($reorder_level); ?></span>
                        </td>
                        <!-- Critical Level -->
                        <td style="text-align:center;">
                            <span style="display:inline-block;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:4px;padding:2px 8px;font-size:12px;font-weight:700;"><?php echo number_format($critical_lvl); ?></span>
                        </td>
                        <!-- Status -->
                        <td style="text-align:center;"><span class="badge <?php echo $st_class; ?>"><?php echo $st_label; ?></span></td>
                        <!-- Actions -->
                        <td style="text-align:center;">
                            <div class="act-btn-wrap">
                                <button onclick="viewMerchandiseDetails(<?php echo $item['id']; ?>)" class="act-btn act-btn-view">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button onclick="openEditMerchModal(<?php echo $item['id']; ?>)" class="act-btn act-btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if (!$is_inactive): ?>
                                    <button onclick="deactivateMerchandise(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'] ?? '')); ?>')" class="act-btn act-btn-deactivate">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                <?php else: ?>
                                    <button onclick="activateMerchandise(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'] ?? '')); ?>')" class="act-btn act-btn-activate">
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
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <strong style="font-size:15px;color:#002F6C;"><i class="fas fa-tools"></i> Service Management</strong>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" id="svcSearchInput" oninput="filterServiceTable()" placeholder="&#x1F50D; Search services..." style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#334155;background:#fff;min-width:180px;">
                <select id="serviceCategoryFilter" onchange="filterServiceTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#334155;background:#fff;">
                    <option value="">All Categories</option>
                    <option value="Lubrication">Lubrication</option>
                    <option value="Preventive Maintenance">Preventive Maintenance</option>
                    <option value="Oil &amp; Lubrication Services">Oil &amp; Lubrication Services</option>
                    <option value="Engine Services">Engine Services</option>
                    <option value="Brake Services">Brake Services</option>
                    <option value="Tire Services">Tire Services</option>
                    <option value="Battery Services">Battery Services</option>
                    <option value="Cooling System">Cooling System</option>
                    <option value="Electrical Services">Electrical Services</option>
                    <option value="Air Conditioning">Air Conditioning</option>
                    <option value="Undercarriage Services">Undercarriage Services</option>
                    <option value="Cleaning Services">Cleaning Services</option>
                    <option value="Emergency Services">Emergency Services</option>
                    <option value="Others">Others</option>
                    <option value="Custom Services">Custom Services</option>
                </select>
                <select id="svcStatusFilter" onchange="filterServiceTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#334155;background:#fff;">
                    <option value="">All Status</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
                <button onclick="openAddServiceModal()" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:all 0.2s;">
                    <i class="fas fa-plus-circle"></i> Add Service
                </button>
            </div>
        </div>
        
        <?php if (empty($service_types)): ?>
            <div style="padding:40px;text-align:center;color:#94a3b8;">
                <i class="fas fa-tools" style="font-size:36px;margin-bottom:12px;display:block;color:#cbd5e1;"></i>
                <div style="font-size:15px;font-weight:600;color:#64748b;margin-bottom:4px;">No service types found</div>
                <div style="font-size:13px;">Click <strong>Add Service</strong> to add your first service type.</div>
            </div>
        <?php else: ?>
        <div class="table-wrap" style="overflow-x:auto;">
            <table class="pricing-table" style="min-width:1100px;">
                <thead>
                    <tr>
                        <th style="width:110px;">Code</th>
                        <th>Service Name</th>
                        <th>Category</th>
                        <th style="text-align:right;min-width:115px;">Service Fee</th>
                        <th style="text-align:right;min-width:105px;">Labor Fee</th>
                        <th style="text-align:center;min-width:80px;">Duration</th>
                        <th style="text-align:center;min-width:85px;">Mechanics</th>
                        <th style="text-align:center;min-width:90px;">Status</th>
                        <th style="text-align:center;min-width:100px;">Last Updated</th>
                        <th style="text-align:center;min-width:210px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="serviceTableBody">
                    <?php foreach ($service_types as $svc):
                        $svcId        = (int)$svc['id'];
                        $svcCode      = htmlspecialchars($svc['service_code'] ?? ('SVC-' . str_pad($svcId, 4, '0', STR_PAD_LEFT)));
                        $svcName      = htmlspecialchars($svc['service_name']);
                        $svcKey       = htmlspecialchars($svc['service_key'] ?? '');
                        $svcCat       = htmlspecialchars($svc['category'] ?? 'Others');
                        $svcFee       = (float)($svc['service_price'] ?? 0);
                        $laborFee     = (float)($svc['labor_fee'] ?? 0);
                        $duration     = (int)($svc['estimated_duration'] ?? 60);
                        $mechanics    = (int)($svc['required_mechanics'] ?? 1);
                        $svcDesc      = htmlspecialchars($svc['description'] ?? '');
                        $isActive     = (int)($svc['active'] ?? 1) === 1;
                        $hasPending   = ($svc['approval_status'] ?? '') === 'pending';
                        $pendSvcFee   = $hasPending ? (float)($svc['pending_service_fee'] ?? 0) : 0;
                        $pendLabFee   = $hasPending ? (float)($svc['pending_labor_fee'] ?? 0) : 0;
                        $updatedAt    = !empty($svc['updated_at']) ? date('M j, Y', strtotime($svc['updated_at'])) : '—';
                        $hrs          = floor($duration / 60);
                        $mins         = $duration % 60;
                        $durationStr  = ($hrs > 0 ? $hrs . 'h' : '') . ($mins > 0 ? ($hrs > 0 ? ' ' : '') . $mins . 'm' : ($hrs === 0 ? '0m' : ''));
                        $jsObj = json_encode([
                            'id'                 => $svcId,
                            'service_code'       => $svc['service_code'] ?? '',
                            'service_name'       => $svc['service_name'],
                            'service_key'        => $svc['service_key'] ?? '',
                            'category'           => $svc['category'] ?? '',
                            'service_price'      => $svcFee,
                            'labor_fee'          => $laborFee,
                            'estimated_duration' => $duration,
                            'required_mechanics' => $mechanics,
                            'description'        => $svc['description'] ?? '',
                            'active'             => $isActive ? 1 : 0,
                        ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    ?>
                    <tr class="service-row"
                        data-category="<?php echo $svcCat; ?>"
                        data-active="<?php echo $isActive ? '1' : '0'; ?>"
                        data-name="<?php echo strtolower(htmlspecialchars($svc['service_name'])); ?>">
                        <td>
                            <span style="font-family:monospace;font-size:11px;color:#0369a1;font-weight:700;background:#e0f2fe;padding:3px 7px;border-radius:5px;letter-spacing:0.3px;"><?php echo $svcCode; ?></span>
                        </td>
                        <td>
                            <div style="font-weight:600;color:#1e293b;font-size:13px;"><?php echo $svcName; ?></div>
                            <?php if ($svcDesc): ?>
                            <div style="font-size:11px;color:#94a3b8;margin-top:2px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $svcDesc; ?>"><?php echo $svcDesc; ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="background:#f0f7ff;color:#003d7a;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap;"><?php echo $svcCat; ?></span>
                        </td>
                        <td style="text-align:right;">
                            <div style="font-weight:700;color:#002F6C;font-size:14px;">&#8369;<?php echo number_format($svcFee, 2); ?></div>
                            <?php if ($hasPending && $pendSvcFee > 0): ?>
                            <div style="font-size:10px;color:#d97706;background:#fef3c7;padding:2px 5px;border-radius:4px;margin-top:3px;font-weight:600;display:inline-block;white-space:nowrap;">
                                <i class="fas fa-hourglass-half" style="font-size:9px;"></i> &#8369;<?php echo number_format($pendSvcFee, 2); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <div style="font-weight:600;color:#0369a1;font-size:13px;">&#8369;<?php echo number_format($laborFee, 2); ?></div>
                            <?php if ($hasPending && $pendLabFee > 0): ?>
                            <div style="font-size:10px;color:#d97706;background:#fef3c7;padding:2px 5px;border-radius:4px;margin-top:3px;font-weight:600;display:inline-block;white-space:nowrap;">
                                <i class="fas fa-hourglass-half" style="font-size:9px;"></i> &#8369;<?php echo number_format($pendLabFee, 2); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <span style="color:#64748b;font-size:12px;white-space:nowrap;"><i class="fas fa-clock" style="color:#94a3b8;font-size:11px;"></i> <?php echo $durationStr; ?></span>
                        </td>
                        <td style="text-align:center;">
                            <span style="color:#64748b;font-size:12px;"><i class="fas fa-user-cog" style="color:#94a3b8;font-size:11px;"></i> <?php echo $mechanics; ?></span>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($isActive): ?>
                            <span style="background:#dcfce7;color:#15803d;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;display:inline-block;">Active</span>
                            <?php else: ?>
                            <span style="background:#fee2e2;color:#b91c1c;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;display:inline-block;">Inactive</span>
                            <?php endif; ?>
                            <?php if ($hasPending): ?>
                            <div style="margin-top:4px;">
                                <span style="background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:999px;font-size:10px;font-weight:700;white-space:nowrap;"><i class="fas fa-hourglass-half" style="font-size:9px;"></i> Pending</span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;font-size:12px;color:#94a3b8;white-space:nowrap;"><?php echo $updatedAt; ?></td>
                        <td style="text-align:center;">
                            <div class="act-btn-wrap">
                                <button onclick='openViewServiceModal(<?php echo $jsObj; ?>)' class="act-btn act-btn-view">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button onclick='openEditServiceModal(<?php echo $jsObj; ?>)' class="act-btn act-btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if ($isActive): ?>
                                <button onclick="deactivateService(<?php echo $svcId; ?>, '<?php echo addslashes($svc['service_name']); ?>')" class="act-btn act-btn-deactivate">
                                    <i class="fas fa-ban"></i> Deactivate
                                </button>
                                <?php else: ?>
                                <button onclick="activateService(<?php echo $svcId; ?>, '<?php echo addslashes($svc['service_name']); ?>')" class="act-btn act-btn-activate">
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
        <div id="svcNoResults" style="display:none;padding:30px;text-align:center;color:#94a3b8;">
            <i class="fas fa-search" style="font-size:28px;margin-bottom:8px;display:block;"></i>
            No services match your search/filter criteria.
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ── Tab switching — updates URL & sessionStorage so refresh stays on same tab ─
function switchTab(name) {
    if (['fuel', 'merch', 'services'].indexOf(name) === -1) name = 'fuel';

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
    try {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        window.history.replaceState(null, '', url.toString());
    } catch (e) {}

    // Persist in sessionStorage
    try {
        sessionStorage.setItem('petron_manager_active_tab', name);
    } catch (e) {}
}

(function() {
    var urlParams = new URLSearchParams(window.location.search);
    var tabFromUrl = urlParams.get('tab');
    var savedTab = null;
    try { savedTab = sessionStorage.getItem('petron_manager_active_tab'); } catch (e) {}

    var targetTab = tabFromUrl || savedTab;
    if (targetTab && ['fuel', 'merch', 'services'].indexOf(targetTab) !== -1) {
        switchTab(targetTab);
    } else {
        var activeSec = document.getElementById('activeSection');
        var activeTab = activeSec ? activeSec.value : 'fuel';
        if (!activeTab || ['fuel', 'merch', 'services'].indexOf(activeTab) === -1) activeTab = 'fuel';
        switchTab(activeTab);
    }
})();

// ── Merchandise filter ───────────────────────────────────────────────────────
function filterTable() {
    var merchTab = document.getElementById('tab-merch');
    if (!merchTab || !merchTab.classList.contains('active')) {
        return;
    }
    
    var q          = document.getElementById('searchInput').value.toLowerCase().trim();
    var catFilter  = document.getElementById('catFilter').value;
    var brandFilter = document.getElementById('brandFilter').value;
    var unitFilter = document.getElementById('unitFilter').value;
    var supplierFilter = document.getElementById('supplierFilter').value;
    var stFilter   = document.getElementById('statusFilter').value;
    var rows       = document.querySelectorAll('#merchBody .merch-row');
    var catHeaders = document.querySelectorAll('#merchBody .cat-row');
    var visible    = 0;

    var catVisibleCount = {};

    rows.forEach(function(row) {
        var name      = row.getAttribute('data-name') || '';
        var sku       = row.getAttribute('data-sku')  || '';
        var brand     = row.getAttribute('data-brand') || '';
        var unit      = row.getAttribute('data-unit') || '';
        var supplier  = row.getAttribute('data-supplier') || '';
        var cat       = row.getAttribute('data-cat')  || '';
        var status    = row.getAttribute('data-status') || '';
        var noprice   = row.getAttribute('data-noprice') === '1';
        var belowcost = row.getAttribute('data-belowcost') === '1';

        var matchQ   = !q || name.indexOf(q) !== -1 || sku.indexOf(q) !== -1 || brand.indexOf(q) !== -1 || unit.indexOf(q) !== -1 || supplier.indexOf(q) !== -1 || cat.toLowerCase().indexOf(q) !== -1;
        var matchCat = !catFilter || cat === catFilter;
        var matchBrand = !brandFilter || brand === brandFilter.toLowerCase();
        var matchUnit = !unitFilter || unit === unitFilter.toLowerCase();
        var matchSupplier = !supplierFilter || supplier === supplierFilter.toLowerCase();
        var matchSt  = true;
        if (stFilter === 'available')  matchSt = status === 'available';
        else if (stFilter === 'low')   matchSt = status === 'low';
        else if (stFilter === 'critical') matchSt = status === 'critical';
        else if (stFilter === 'out')   matchSt = status === 'out';
        else if (stFilter === 'noprice')   matchSt = noprice;
        else if (stFilter === 'belowcost') matchSt = belowcost;

        var show = matchQ && matchCat && matchBrand && matchUnit && matchSupplier && matchSt;
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

<!-- Add Product Modal (Landscape Layout) -->
<div id="addProductModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:12px;width:92%;max-width:760px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:auto;overflow:hidden;">
        <!-- Modal Header -->
        <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;">
            <h3 style="margin:0;font-size:17px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;letter-spacing:0.3px;">
                <i class="fas fa-plus-circle" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:18px;"></i>
                <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">ADD FUEL PRODUCT</span>
            </h3>
        </div>
        <!-- Modal Form Body (Landscape 2-Column Grid) -->
        <form id="addProductForm" style="padding:20px 24px;">
            <!-- Row 1: Fuel Name + UGT Number -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Fuel Name <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newFuelName" maxlength="50" required
                           style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="e.g. Diesel, XCS Plus, Turbo Diesel">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        UGT Number <span style="color:#dc2626;">*</span>
                    </label>
                    <select id="newUgtNo" required
                            style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;background:#fff;"
                            onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
                        <option value="">Select UGT</option>
                        <?php
                        // Fetch assigned UGT numbers for this station
                        $assigned_ugt_numbers = [];

                        if (!empty($fuel_products) && is_array($fuel_products)) {
                            foreach ($fuel_products as $fp) {
                                if (!empty($fp['id']) || !empty($fp['raw_fuel_type'])) {
                                    $numOnly = preg_replace('/[^0-9]/', '', $fp['ugt_no'] ?? '');
                                    if ($numOnly !== '') {
                                        $assigned_ugt_numbers[intval($numOnly)] = true;
                                    }
                                }
                            }
                        }

                        try {
                            $ugt_stmt = $pdo->prepare("SELECT ugt_no FROM fuel_inventory WHERE station_id = ? AND ugt_no IS NOT NULL AND ugt_no != ''");
                            $ugt_stmt->execute([$station_id]);
                            while ($ur = $ugt_stmt->fetch(PDO::FETCH_ASSOC)) {
                                $numOnly = preg_replace('/[^0-9]/', '', $ur['ugt_no']);
                                if ($numOnly !== '') {
                                    $n = intval($numOnly);
                                    if ($n >= 1 && $n <= 7) {
                                        $assigned_ugt_numbers[$n] = true;
                                    }
                                }
                            }
                        } catch (Exception $e) {}

                        for ($i = 1; $i <= 7; $i++):
                            $ugt_val = "UGT #$i";
                            $is_assigned = isset($assigned_ugt_numbers[$i]);
                        ?>
                            <option value="<?php echo $ugt_val; ?>" <?php echo $is_assigned ? 'disabled style="color:#94a3b8;background:#f1f5f9;"' : ''; ?>>
                                <?php echo $ugt_val; ?> <?php echo $is_assigned ? '(Assigned)' : ''; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <!-- Row 2: Price + Capacity -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Selling Price Per Liter (₱) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newPrice" step="0.01" min="0.01" required
                           style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="84.00">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Tank Capacity (Liters) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newCapacity" step="1" min="1" required
                           style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="15000">
                </div>
            </div>

            <!-- Row 3: Critical Level + Reorder Level -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Critical Level (Liters) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newCriticalLevel" step="1" min="1" required
                           style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="2500">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Reorder Level (Liters) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newReorderLevel" step="1" min="1" required
                           style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="5000">
                </div>
            </div>

            <!-- Row 4: Status + Remarks -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;align-items:start;">
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:6px;">
                        Status <span style="color:#dc2626;">*</span>
                    </label>
                    <div style="display:flex;gap:18px;align-items:center;padding-top:4px;">
                        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;font-weight:600;color:#166534;">
                            <input type="radio" name="newStatus" value="active" checked style="accent-color:#16a34a;"> Active
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;font-weight:600;color:#991b1b;">
                            <input type="radio" name="newStatus" value="inactive" style="accent-color:#dc2626;"> Inactive
                        </label>
                    </div>
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Remarks <span style="color:#94a3b8;font-weight:400;text-transform:none;">(Optional)</span>
                    </label>
                    <input type="text" id="newRemarks"
                           style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="Optional notes or remarks...">
                </div>
            </div>

            <!-- Actions Footer -->
            <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:14px;">
                <button type="button" onclick="closeAddProductModal()"
                        style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:8px 18px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="background:#00264D !important;color:#ffffff !important;border:none !important;padding:8px 22px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                    <i class="fas fa-check" style="color:#ffffff !important;"></i> Add Fuel Product
                </button>
            </div>
        </form>
    </div>
</div>


<!-- Edit Fuel Modal — Full Edit (Landscape Grid Layout) -->
<div id="editPriceModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:12px;width:92%;max-width:760px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:auto;overflow:hidden;">
    <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:17px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;letter-spacing:0.3px;">
        <i class="fas fa-edit" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:18px;"></i>
        <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">EDIT FUEL PRODUCT</span>
      </h3>
    </div>
    <form id="editPriceForm" style="padding:20px 24px;">
      <input type="hidden" id="editFuelId">
      <input type="hidden" id="editFuelType">
      <input type="hidden" id="editFuelCritical" value="0">

      <!-- Row 1: UGT Number + Fuel Name -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">UGT Number</label>
          <input type="text" id="editUgtNo" style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;color:#002F70;font-weight:800;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">Fuel Name</label>
          <input type="text" id="editFuelName" style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;color:#0f172a;font-weight:700;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        </div>
      </div>

      <!-- Row 2: Price Per Liter + Tank Capacity -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">Price / Liter (₱) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="editPrice" step="0.01" min="0" required style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00">
          <small style="font-size:10px;color:#d97706;display:block;margin-top:2px;"><i class="fas fa-info-circle"></i> Price changes require Admin approval.</small>
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">Tank Capacity (L) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="editFuelCapacity" step="1" min="0" required style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00">
        </div>
      </div>

      <!-- Row 3: Reorder Level + Status -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;align-items:start;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">Reorder Level (L) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="editFuelReorder" step="1" min="0" required style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:6px;">Status <span style="color:#dc2626;">*</span></label>
          <div style="display:flex;gap:18px;align-items:center;padding-top:4px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;font-weight:600;color:#166534;">
              <input type="radio" id="editFuelStatusActive" name="editFuelStatus" value="active" checked style="accent-color:#16a34a;"> Active
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;font-weight:600;color:#991b1b;">
              <input type="radio" id="editFuelStatusInactive" name="editFuelStatus" value="inactive" style="accent-color:#dc2626;"> Inactive
            </label>
          </div>
        </div>
      </div>

      <!-- Row 4: Remarks -->
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">Remarks <span style="color:#94a3b8;font-weight:400;text-transform:none;">(Optional)</span></label>
        <input type="text" id="editFuelRemarks" style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="Optional notes or remarks...">
      </div>

      <!-- Actions -->
      <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:14px;">
        <button type="button" onclick="closeEditPriceModal()" style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:8px 18px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#00264D !important;color:#ffffff !important;border:none !important;padding:8px 22px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-save" style="color:#ffffff !important;"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- View Fuel Details Modal (Matches Add Fuel Product Modal Centering & Dimensions Exactly) -->
<div id="viewFuelModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:flex-start;justify-content:center;padding:85px 20px 70px 20px;box-sizing:border-box;overflow-y:auto;">
    <div style="background:#fff;border-radius:12px;width:92%;max-width:880px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:0 auto;overflow:hidden;max-height:calc(100vh - 155px);display:flex;flex-direction:column;">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <h3 style="margin:0;font-size:17px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;letter-spacing:0.3px;">
                <i class="fas fa-gas-pump" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:18px;"></i>
                <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">FUEL PRODUCT SPECIFICATION &amp; HISTORY</span>
            </h3>
        </div>

        <!-- Body Content -->
        <div style="padding:12px 24px 24px 24px;overflow-y:auto;overflow-x:hidden;flex:1 1 auto;background:#ffffff;min-height:0;box-sizing:border-box;">
            <!-- Fuel Specification & Overview -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:20px;margin-top:4px;width:100%;box-sizing:border-box;">
                <h4 style="margin:0 0 14px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
                    <i class="fas fa-info-circle" style="color:#002F6C;"></i> Fuel Specification &amp; Overview
                </h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(190px, 1fr));gap:14px;font-size:13px;width:100%;">
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Fuel Name</strong>
                        <span style="font-weight:700;color:#002F6C;font-size:14px;" id="viewFuelType">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">UGT Number</strong>
                        <span style="font-weight:700;color:#1e293b;" id="viewUgtNo">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Current Price / Liter</strong>
                        <span style="font-weight:700;color:#16a34a;font-size:14px;" id="viewCurrentPrice">₱0.00</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Current Volume</strong>
                        <span style="font-weight:700;color:#002F6C;" id="viewStock">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Available Capacity</strong>
                        <span style="font-weight:700;color:#2563eb;" id="viewAvailableCapacity">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Tank Capacity</strong>
                        <span style="font-weight:600;color:#334155;" id="viewCapacity">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Critical Level</strong>
                        <span style="font-weight:600;color:#dc2626;" id="viewCriticalLevel">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Reorder Level</strong>
                        <span style="font-weight:600;color:#d97706;" id="viewReorderLevel">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Stock Status</strong>
                        <span style="font-weight:600;" id="viewStatus">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Product Status</strong>
                        <span style="font-weight:600;" id="viewProductStatus">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Price Request Status</strong>
                        <span style="font-weight:600;color:#475569;" id="viewPriceRequestStatus">No Pending Request</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Last Updated</strong>
                        <span style="font-size:12px;color:#475569;" id="viewLastUpdated">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Updated By</strong>
                        <span style="font-size:12px;color:#475569;" id="viewUpdatedBy">-</span>
                    </div>
                </div>
            </div>

            <!-- Price Change History -->
            <div style="margin-bottom:20px;width:100%;box-sizing:border-box;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-history" style="color:#002F6C;"></i> Price Change History
                </h4>
                <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;width:100%;box-sizing:border-box;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:11px;letter-spacing:0.3px;">
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Effective Date</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Price</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Requested By</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Approved By</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Status</th>
                                <th style="padding:10px 12px;text-align:center;border-bottom:1.5px solid #cbd5e1;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="priceHistoryBody">
                            <tr><td colspan="6" style="text-align:center;padding:16px;color:#94a3b8;">No price history available</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Configuration Change History -->
            <div style="margin-bottom:20px;width:100%;box-sizing:border-box;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-sliders-h" style="color:#002F6C;"></i> Configuration Change History
                </h4>
                <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;width:100%;box-sizing:border-box;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:11px;letter-spacing:0.3px;">
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Date</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Field Changed</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Old Value</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">New Value</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="configHistoryBody">
                            <tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No configuration changes recorded yet</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Status Change History -->
            <div style="margin-bottom:10px;width:100%;box-sizing:border-box;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-toggle-on" style="color:#002F6C;"></i> Status Change History
                </h4>
                <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;width:100%;box-sizing:border-box;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:11px;letter-spacing:0.3px;">
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Date</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Old Status</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">New Status</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Reason</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="statusHistoryBody">
                            <tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No status history recorded yet</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div style="display:flex;justify-content:flex-end;padding:14px 24px;border-top:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0;">
            <button type="button" onclick="closeViewFuelModal()" style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:8px 20px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- Professional Confirmation Modal -->
<div id="confirmationModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:10000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;width:90%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.35);overflow:hidden;animation:modalSlideIn .18s ease;">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#dc2626,#991b1b);padding:18px 24px;display:flex;align-items:center;gap:12px;">
            <div style="width:42px;height:42px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-exclamation-triangle" style="color:#fff;font-size:20px;"></i>
            </div>
            <div>
                <h3 style="margin:0;font-size:16px;font-weight:700;color:#fff;" id="confirmModalTitle">Confirm Action</h3>
                <p style="margin:2px 0 0 0;font-size:12px;color:rgba(255,255,255,0.9);" id="confirmModalSubtitle">Please confirm your action</p>
            </div>
        </div>
        
        <!-- Body -->
        <div style="padding:24px;">
            <p style="margin:0;font-size:14px;color:#475569;line-height:1.6;" id="confirmModalMessage">Are you sure you want to proceed?</p>
        </div>
        
        <!-- Footer -->
        <div style="display:flex;justify-content:flex-end;gap:10px;padding:16px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;">
            <button type="button" onclick="closeConfirmModal()" style="background:#f1f5f9 !important;color:#0f172a !important;-webkit-text-fill-color:#0f172a !important;border:1px solid #cbd5e1;padding:9px 20px;border-radius:6px;font-size:13px;font-weight:700 !important;cursor:pointer;transition:all .2s;">
                <i class="fas fa-times" style="color:#0f172a !important;-webkit-text-fill-color:#0f172a !important;"></i> Cancel
            </button>
            <button type="button" id="confirmModalBtn" onclick="confirmModalAction()" style="background:#dc2626 !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 24px;border-radius:6px;font-size:13px;font-weight:700 !important;cursor:pointer;transition:all .2s;box-shadow:0 2px 4px rgba(220,38,38,0.3);">
                <i class="fas fa-check" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm
            </button>
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

<!-- Restore Price Modal (Confirmation & Audit Dialog) -->
<div id="restorePriceModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:10000;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:12px;width:92%;max-width:540px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:auto;overflow:hidden;">
    <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-undo" style="color:#ffffff !important;"></i>
        <span>RESTORE HISTORICAL PRICE</span>
      </h3>
      <button type="button" onclick="closeRestorePriceModal()" style="background:rgba(255,255,255,0.15);border:none;color:#ffffff;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:16px;">&times;</button>
    </div>
    <form id="restorePriceForm" style="padding:20px 24px;">
      <input type="hidden" id="restoreFuelId">
      <input type="hidden" id="restoreTargetPrice">

      <!-- Confirmation Notice -->
      <div style="background:#f0f7ff;border:1px solid #bae6fd;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
        <p style="margin:0 0 8px 0;font-size:13px;font-weight:700;color:#0369a1;">
          Restore this previous price as the current selling price?
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12px;margin-top:10px;background:#ffffff;padding:10px 12px;border-radius:6px;border:1px solid #e0f2fe;">
          <div>
            <span style="color:#64748b;display:block;font-size:11px;">Fuel Product:</span>
            <strong id="restoreFuelNameDisplay" style="color:#002F6C;font-size:13px;">-</strong>
          </div>
          <div>
            <span style="color:#64748b;display:block;font-size:11px;">Historical Date:</span>
            <strong id="restoreDateDisplay" style="color:#334155;font-size:12px;">-</strong>
          </div>
          <div>
            <span style="color:#64748b;display:block;font-size:11px;">Current Price:</span>
            <strong id="restoreCurrentPriceDisplay" style="color:#dc2626;font-size:14px;">₱0.00</strong>
          </div>
          <div>
            <span style="color:#64748b;display:block;font-size:11px;">Previous Price (To Restore):</span>
            <strong id="restoreTargetPriceDisplay" style="color:#16a34a;font-size:14px;">₱0.00</strong>
          </div>
        </div>
      </div>

      <!-- Optional Reason -->
      <div style="margin-bottom:18px;">
        <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">
          Reason for Restoration <span style="color:#94a3b8;font-weight:400;">(Optional note for Admin)</span>
        </label>
        <textarea id="restoreReason" rows="2" style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" placeholder="e.g. Restoring previous standard pricing following promotional period..."></textarea>
      </div>

      <!-- Actions -->
      <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:14px;">
        <button type="button" onclick="closeRestorePriceModal()" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:8px 18px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">
          Cancel
        </button>
        <button type="submit" style="background:#002F6C;color:#ffffff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
          <i class="fas fa-paper-plane"></i> Submit Restoration Request
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Add Merchandise Modal -->
<div id="addMerchandiseModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:650px;max-height:92vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
    <div style="background:linear-gradient(135deg,#002F6C,#004494);border-radius:12px 12px 0 0;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-plus-circle"></i> ADD NEW MERCHANDISE PRODUCT
      </h3>
      <button onclick="closeAddMerchandiseModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:6px;width:30px;height:30px;cursor:pointer;font-size:16px;">&times;</button>
    </div>
    <form id="addMerchandiseForm" style="padding:22px;">
      <!-- Row 1: Product Name + SKU -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Product Name <span style="color:#dc2626;">*</span></label>
          <input type="text" id="newMerchName" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Coke 1.5L">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">SKU / Product Code</label>
          <input type="text" id="newMerchSku" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;font-family:monospace;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. ITEM-001 (auto if blank)">
        </div>
      </div>
      <!-- Row 2: Category + Brand -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Category <span style="color:#dc2626;">*</span></label>
          <input type="text" id="newMerchCategory" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Drinks/Food">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Brand</label>
          <input type="text" id="newMerchBrand" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Coca-Cola, Petron">
        </div>
      </div>
      <!-- Row 3: UOM + Barcode -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Unit of Measure (UOM)</label>
          <input type="text" id="newMerchSize" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Bottle, Box, pcs, 500ml">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Barcode <span style="color:#94a3b8;font-weight:400;text-transform:none;">(optional)</span></label>
          <div style="position:relative;display:flex;align-items:center;">
            <i class="fas fa-barcode" style="position:absolute;left:10px;color:#64748b;font-size:16px;z-index:1;pointer-events:none;"></i>
            <input type="text" id="newMerchBarcode"
              style="width:100%;padding:9px 11px 9px 34px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;font-family:monospace;"
              onfocus="this.style.borderColor='#002F6C'"
              onblur="this.style.borderColor='#d1d5db'"
              placeholder="Scan barcode or type manually"
              autocomplete="off"
              onkeydown="handleBarcodeKeydown(event, 'add')">
            <button type="button" id="newMerchBarcodeScanBtn"
              onclick="activateBarcodeScan('newMerchBarcode', 'add')"
              title="Click then scan with barcode gun"
              style="position:absolute;right:6px;background:#002F6C;color:#fff;border:none;border-radius:5px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:4px;white-space:nowrap;">
              <i class="fas fa-crosshairs"></i> Scan
            </button>
          </div>
          <div id="newMerchBarcodeStatus" style="font-size:11px;margin-top:4px;min-height:16px;"></div>
        </div>
      </div>
      <!-- Row 4: Default Selling Price -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#002F6C;text-transform:uppercase;margin-bottom:4px;">Default Selling Price (&#8369;) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="newMerchPrice" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:2px solid #002F6C;border-radius:7px;font-size:14px;font-weight:600;box-sizing:border-box;" onfocus="this.style.borderColor='#004494'" onblur="this.style.borderColor='#002F6C'" placeholder="0.00">
          <small style="color:#64748b;font-size:11px;">Cost price will be set per delivery batch (Record Delivery)</small>
        </div>
        <div></div>
      </div>
      <!-- Row 5: Reorder Level + Critical Level -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:4px;">Reorder Level</label>
          <input type="number" id="newMerchReorder" min="0" value="24" style="width:100%;padding:9px 11px;border:1.5px solid #fde68a;border-radius:7px;font-size:13px;background:#fffbeb;box-sizing:border-box;" onfocus="this.style.borderColor='#f59e0b'" onblur="this.style.borderColor='#fde68a'" placeholder="24">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#991b1b;text-transform:uppercase;margin-bottom:4px;">Critical Level</label>
          <input type="number" id="newMerchCritical" min="0" value="10" style="width:100%;padding:9px 11px;border:1.5px solid #fca5a5;border-radius:7px;font-size:13px;background:#fff1f2;box-sizing:border-box;" onfocus="this.style.borderColor='#ef4444'" onblur="this.style.borderColor='#fca5a5'" placeholder="10">
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:16px;">
        <button type="button" onclick="closeAddMerchandiseModal()" style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#00264D !important;color:#ffffff !important;border:none !important;padding:9px 22px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-check" style="color:#ffffff !important;"></i> Add Product</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Merchandise Modal — Full Edit -->
<div id="editMerchPriceModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:650px;max-height:92vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
    <div style="background:#002F6C;border-radius:12px 12px 0 0;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-edit"></i> EDIT MERCHANDISE PRODUCT
      </h3>
      <button onclick="closeEditMerchPriceModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:6px;width:30px;height:30px;cursor:pointer;font-size:16px;">&times;</button>
    </div>
    <form id="editMerchPriceForm" style="padding:22px;">
      <input type="hidden" id="editMerchId">
      <!-- Row 1: Product Name + SKU -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Product Name <span style="color:#dc2626;">*</span></label>
          <input type="text" id="editMerchName" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Barcode <span style="color:#94a3b8;font-weight:400;text-transform:none;">(optional)</span></label>
          <div style="position:relative;display:flex;align-items:center;">
            <i class="fas fa-barcode" style="position:absolute;left:10px;color:#64748b;font-size:16px;z-index:1;pointer-events:none;"></i>
            <input type="text" id="editMerchBarcode"
              style="width:100%;padding:9px 11px 9px 34px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;font-family:monospace;"
              onfocus="this.style.borderColor='#002F6C'"
              onblur="this.style.borderColor='#d1d5db'"
              placeholder="Scan barcode or type manually"
              autocomplete="off"
              onkeydown="handleBarcodeKeydown(event, 'edit')">
            <button type="button" id="editMerchBarcodeScanBtn"
              onclick="activateBarcodeScan('editMerchBarcode', 'edit')"
              title="Click then scan with barcode gun"
              style="position:absolute;right:6px;background:#002F6C;color:#fff;border:none;border-radius:5px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:4px;white-space:nowrap;">
              <i class="fas fa-crosshairs"></i> Scan
            </button>
          </div>
          <div id="editMerchBarcodeStatus" style="font-size:11px;margin-top:4px;min-height:16px;"></div>
        </div>
      </div>
      <!-- Row 2: Category + Brand -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Category <span style="color:#dc2626;">*</span></label>
          <input type="text" id="editMerchCategory" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Brand</label>
          <input type="text" id="editMerchBrand" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Coca-Cola, Petron">
        </div>
      </div>
      <!-- Row 3: UOM + SKU -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Unit of Measure (UOM)</label>
          <input type="text" id="editMerchSize" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Bottle, Box, pcs">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">SKU / Product Code <span style="color:#94a3b8;font-weight:400;text-transform:none;">(read-only)</span></label>
          <input type="text" id="editMerchSku" readonly style="width:100%;padding:9px 11px;border:1.5px solid #cbd5e1;border-radius:7px;font-size:13px;background:#f8fafc;color:#4f46e5;font-weight:700;box-sizing:border-box;font-family:monospace;">
        </div>
      </div>
      <!-- Row 4: Default Selling Price -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#002F6C;text-transform:uppercase;margin-bottom:4px;">Default Selling Price (&#8369;) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="editMerchPrice" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:2px solid #002F6C;border-radius:7px;font-size:14px;font-weight:600;box-sizing:border-box;" onfocus="this.style.borderColor='#004494'" onblur="this.style.borderColor='#002F6C'" placeholder="0.00">
          <small style="color:#64748b;font-size:11px;">Cost price is managed per delivery batch</small>
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Status</label>
          <select id="editMerchStatus" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <!-- Row 5: Reorder Level + Critical Level -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:4px;">Reorder Level</label>
          <input type="number" id="editMerchReorder" min="0" style="width:100%;padding:9px 11px;border:1.5px solid #fde68a;border-radius:7px;font-size:13px;background:#fffbeb;box-sizing:border-box;" onfocus="this.style.borderColor='#f59e0b'" onblur="this.style.borderColor='#fde68a'">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#991b1b;text-transform:uppercase;margin-bottom:4px;">Critical Level</label>
          <input type="number" id="editMerchCritical" min="0" style="width:100%;padding:9px 11px;border:1.5px solid #fca5a5;border-radius:7px;font-size:13px;background:#fff1f2;box-sizing:border-box;" onfocus="this.style.borderColor='#ef4444'" onblur="this.style.borderColor='#fca5a5'">
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:16px;">
        <button type="button" onclick="closeEditMerchPriceModal()" style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#00264D !important;color:#ffffff !important;border:none !important;padding:9px 22px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-save" style="color:#ffffff !important;"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- View Merchandise Specification & History Modal -->
<div id="viewMerchModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:flex-start;justify-content:center;padding:85px 20px 70px 20px;box-sizing:border-box;overflow-y:auto;">
    <div style="background:#fff;border-radius:12px;width:92%;max-width:920px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:0 auto;overflow:hidden;max-height:calc(100vh - 155px);display:flex;flex-direction:column;">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <h3 style="margin:0;font-size:17px;font-weight:800;color:#ffffff !important;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-box" style="color:#ffffff !important;font-size:18px;"></i>
                <span id="vm_title" style="color:#ffffff !important;">MERCHANDISE SPECIFICATION &amp; HISTORY</span>
            </h3>
            <button onclick="closeViewMerchModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:6px;width:30px;height:30px;cursor:pointer;font-size:16px;">&times;</button>
        </div>

        <!-- Body Content -->
        <div style="padding:20px 24px;overflow-y:auto;overflow-x:hidden;flex:1 1 auto;background:#ffffff;min-height:0;box-sizing:border-box;">
            
            <!-- 1. Product Specification & Overview -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:20px;">
                <h4 style="margin:0 0 14px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
                    <i class="fas fa-info-circle"></i> Product Specification &amp; Overview
                </h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;font-size:13px;">
                    <div><span style="color:#64748b;font-weight:600;">SKU / Code:</span><br><code id="vm_sku" style="font-weight:800;color:#4f46e5;">-</code></div>
                    <div><span style="color:#64748b;font-weight:600;">Barcode:</span><br><strong id="vm_barcode">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Product Name:</span><br><strong id="vm_name" style="color:#0f172a;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Category:</span><br><strong id="vm_category">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Brand:</span><br><strong id="vm_brand">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Unit (UOM):</span><br><strong id="vm_unit">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Current Selling Price:</span><br><strong id="vm_price" style="color:#002F6C;font-size:15px;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Current Cost Price:</span><br><strong id="vm_cost" style="color:#16a34a;font-size:14px;">-</strong> <small style="color:#94a3b8;font-size:10px;">(from latest approved Stock-In)</small></div>
                    <div><span style="color:#64748b;font-weight:600;">Total Stock (All Batches):</span><br><strong id="vm_stock" style="font-size:14px;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Batch Count:</span><br><strong id="vm_batch_count">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Reorder Level:</span><br><strong id="vm_reorder">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Status:</span><br><span id="vm_status">-</span></div>
                </div>
            </div>

            <!-- 2. Read-Only Batch Summary -->
            <div style="margin-bottom:24px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-layer-group" style="color:#0284c7;"></i> Batch Summary <small style="color:#64748b;font-weight:400;">(Read Only)</small>
                </h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;text-align:left;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;font-weight:700;">
                                <th style="padding:8px 12px;">Batch No.</th>
                                <th style="padding:8px 12px;">Remaining Qty</th>
                                <th style="padding:8px 12px;">Expiration</th>
                                <th style="padding:8px 12px;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="vm_batches_body">
                            <tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No batches found</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. Price History -->
            <div style="margin-bottom:24px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-history" style="color:#4f46e5;"></i> Price History
                </h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;text-align:left;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;font-weight:700;">
                                <th style="padding:8px 12px;">Date</th>
                                <th style="padding:8px 12px;">Old Price</th>
                                <th style="padding:8px 12px;">New Price</th>
                                <th style="padding:8px 12px;">Requested By</th>
                                <th style="padding:8px 12px;">Approved By</th>
                                <th style="padding:8px 12px;">Status</th>
                                <th style="padding:8px 12px;text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="vm_price_history_body">
                            <tr><td colspan="7" style="text-align:center;padding:12px;color:#94a3b8;">No price history</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 4. Configuration History -->
            <div style="margin-bottom:24px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-sliders-h" style="color:#d97706;"></i> Configuration History
                </h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;text-align:left;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;font-weight:700;">
                                <th style="padding:8px 12px;">Date</th>
                                <th style="padding:8px 12px;">Field</th>
                                <th style="padding:8px 12px;">Old Value</th>
                                <th style="padding:8px 12px;">New Value</th>
                                <th style="padding:8px 12px;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="vm_config_history_body">
                            <tr><td colspan="5" style="text-align:center;padding:12px;color:#94a3b8;">No configuration changes recorded</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 5. Status History -->
            <div style="margin-bottom:12px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-power-off" style="color:#dc2626;"></i> Status History
                </h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;text-align:left;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;font-weight:700;">
                                <th style="padding:8px 12px;">Date</th>
                                <th style="padding:8px 12px;">Old Status</th>
                                <th style="padding:8px 12px;">New Status</th>
                                <th style="padding:8px 12px;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="vm_status_history_body">
                            <tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No status changes recorded</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 24px;display:flex;justify-content:flex-end;flex-shrink:0;">
            <button onclick="closeViewMerchModal()" style="background:#00264D !important;color:#fff !important;border:none;padding:8px 20px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<!-- View Batches Modal -->
<div id="viewBatchesModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;width:95%;max-width:900px;max-height:92vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
    <div style="background:linear-gradient(135deg,#0369a1,#0284c7);border-radius:12px 12px 0 0;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-layer-group"></i> <span id="viewBatchesTitle">Product Batches</span>
      </h3>
      <button onclick="document.getElementById('viewBatchesModal').style.display='none'" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:6px;width:30px;height:30px;cursor:pointer;font-size:16px;">&times;</button>
    </div>
    <div id="viewBatchesContent" style="padding:22px;">
      <div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading batches...</div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     ADD SERVICE MODAL
     ══════════════════════════════════════════════════════════ -->
<div id="addServiceModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9999;align-items:center;justify-content:center;padding:30px 16px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:580px;max-height:calc(85vh - 20px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.35);margin:auto;animation:slideDown 0.25s ease-out;">
    <!-- Header -->
    <div style="flex-shrink:0;background:linear-gradient(135deg,#002F6C,#0052A5);border-radius:14px 14px 0 0;padding:16px 22px;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-plus-circle"></i> Add New Service
      </h3>
    </div>
    <!-- Form Body -->
    <form id="addServiceForm" style="flex:1 1 auto;overflow-y:auto;padding:22px;display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Service Name <span style="color:#dc2626;">*</span></label>
            <input type="text" id="addSvcName" required placeholder="e.g. Change Oil - Mineral"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;font-weight:500;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Category <span style="color:#dc2626;">*</span></label>
            <select id="addSvcCategory" required onchange="toggleCustomCategoryInput('add')" style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
              <option value="">-- Select Category --</option>
              <option value="Lubrication">Lubrication</option>
              <option value="Preventive Maintenance">Preventive Maintenance</option>
              <option value="Engine Services">Engine Services</option>
              <option value="Brake Services">Brake Services</option>
              <option value="Tire Services">Tire Services</option>
              <option value="Battery Services">Battery Services</option>
              <option value="Cooling System">Cooling System</option>
              <option value="Electrical Services">Electrical Services</option>
              <option value="Air Conditioning">Air Conditioning</option>
              <option value="Undercarriage Services">Undercarriage Services</option>
              <option value="Cleaning Services">Cleaning Services</option>
              <option value="Emergency Services">Emergency Services</option>
              <option value="Others">Others</option>
              <option value="Custom Services">Custom Services</option>
            </select>
            <!-- Custom Category Input -->
            <div id="addSvcCustomWrap" style="display:none;margin-top:8px;">
              <input type="text" id="addSvcCustomCategory" placeholder="Type custom category name (e.g. Car Audio & Accessories)..."
                style="width:100%;padding:9px 12px;border:1.5px solid #0284c7;border-radius:8px;font-size:13px;background:#f0f9ff;box-sizing:border-box;color:#0369a1;font-weight:600;">
            </div>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Service Fee (₱) <span style="color:#dc2626;">*</span></label>
            <input type="number" id="addSvcServiceFee" step="0.01" min="0" required placeholder="0.00"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
            <small style="color:#94a3b8;font-size:11px;">Parts/materials fee</small>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Labor Fee (₱) <span style="color:#dc2626;">*</span></label>
            <input type="number" id="addSvcLaborFee" step="0.01" min="0" required placeholder="0.00"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
            <small style="color:#94a3b8;font-size:11px;">Mechanic labor fee</small>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Est. Duration (mins)</label>
            <input type="number" id="addSvcDuration" min="5" max="480" step="5" value="60" placeholder="60"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Required Mechanics</label>
            <input type="number" id="addSvcMechanics" min="1" max="10" value="1" placeholder="1"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Description <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
            <textarea id="addSvcDescription" rows="2" placeholder="Brief description of the service..."
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;resize:vertical;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"></textarea>
          </div>
        </div>
      </div>
      <!-- Footer Actions -->
      <div style="display:flex;gap:10px;justify-content:flex-end;padding-top:14px;border-top:1px solid #e2e8f0;margin-top:auto;">
        <button type="button" onclick="closeAddServiceModal()" style="background:#f1f5f9 !important;color:#0f172a !important;border:1px solid #cbd5e1 !important;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#002F6C;color:#fff;border:none;padding:10px 24px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
          <i class="fas fa-check-circle"></i> Add Service
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     EDIT SERVICE MODAL
     ══════════════════════════════════════════════════════════ -->
<div id="editServiceModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9999;align-items:center;justify-content:center;padding:30px 16px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:600px;max-height:calc(85vh - 20px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.35);margin:auto;animation:slideDown 0.25s ease-out;">
    <!-- Header -->
    <div style="flex-shrink:0;background:linear-gradient(135deg,#002F6C,#0052A5);border-radius:14px 14px 0 0;padding:16px 22px;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-edit"></i> Edit Service
      </h3>
      <div id="editSvcCodeDisplay" style="font-size:12px;color:rgba(255,255,255,.7);margin-top:3px;font-family:monospace;"></div>
    </div>
    <!-- Approval Notice -->
    <div id="editSvcApprovalNotice" style="display:none;flex-shrink:0;background:#fef3c7;border-bottom:1px solid #fde68a;padding:10px 22px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:8px;">
      <i class="fas fa-exclamation-triangle"></i>
      <span><strong>Fee changes require Admin approval.</strong> Non-fee fields (name, category, duration, etc.) will save immediately.</span>
    </div>
    <!-- Form Body -->
    <form id="editServiceForm" style="flex:1 1 auto;overflow-y:auto;padding:22px;display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <input type="hidden" id="editSvcId">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Service Name <span style="color:#dc2626;">*</span></label>
            <input type="text" id="editSvcName" required
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;font-weight:500;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Category <span style="color:#dc2626;">*</span></label>
            <select id="editSvcCategory" required onchange="toggleCustomCategoryInput('edit')" style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
              <option value="">-- Select Category --</option>
              <option value="Lubrication">Lubrication</option>
              <option value="Preventive Maintenance">Preventive Maintenance</option>
              <option value="Engine Services">Engine Services</option>
              <option value="Brake Services">Brake Services</option>
              <option value="Tire Services">Tire Services</option>
              <option value="Battery Services">Battery Services</option>
              <option value="Cooling System">Cooling System</option>
              <option value="Electrical Services">Electrical Services</option>
              <option value="Air Conditioning">Air Conditioning</option>
              <option value="Undercarriage Services">Undercarriage Services</option>
              <option value="Cleaning Services">Cleaning Services</option>
              <option value="Emergency Services">Emergency Services</option>
              <option value="Others">Others</option>
              <option value="Custom Services">Custom Services</option>
            </select>
            <!-- Custom Category Input -->
            <div id="editSvcCustomWrap" style="display:none;margin-top:8px;">
              <input type="text" id="editSvcCustomCategory" placeholder="Type custom category name..."
                style="width:100%;padding:9px 12px;border:1.5px solid #0284c7;border-radius:8px;font-size:13px;background:#f0f9ff;box-sizing:border-box;color:#0369a1;font-weight:600;">
            </div>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Service Fee (₱) <span style="color:#dc2626;">*</span></label>
            <input type="number" id="editSvcServiceFee" step="0.01" min="0" required placeholder="0.00"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
              oninput="checkSvcFeeChange()">
            <small style="color:#94a3b8;font-size:11px;">Parts/materials fee</small>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Labor Fee (₱) <span style="color:#dc2626;">*</span></label>
            <input type="number" id="editSvcLaborFee" step="0.01" min="0" required placeholder="0.00"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
              oninput="checkSvcFeeChange()">
            <small style="color:#94a3b8;font-size:11px;">Mechanic labor fee</small>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Est. Duration (mins)</label>
            <input type="number" id="editSvcDuration" min="5" max="480" step="5"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Required Mechanics</label>
            <input type="number" id="editSvcMechanics" min="1" max="10"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Description</label>
            <textarea id="editSvcDescription" rows="2"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;resize:vertical;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"></textarea>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Status</label>
            <select id="editSvcActive" style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
      </div>
      <!-- Footer Actions -->
      <div style="display:flex;gap:10px;justify-content:flex-end;padding-top:14px;border-top:1px solid #e2e8f0;margin-top:auto;">
        <button type="button" onclick="closeEditServiceModal()" style="background:#f1f5f9 !important;color:#0f172a !important;border:1px solid #cbd5e1;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#002F6C;color:#fff;border:none;padding:10px 24px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     VIEW SERVICE MODAL (with Fee History)
     ══════════════════════════════════════════════════════════ -->
<div id="viewServiceModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9999;align-items:center;justify-content:center;padding:30px 16px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:620px;max-height:calc(85vh - 20px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.35);margin:auto;animation:slideDown 0.25s ease-out;">
    <!-- Header (Fixed Top) -->
    <div style="flex-shrink:0;background:#002F6C;border-radius:14px 14px 0 0;padding:22px 28px;">
      <h3 style="margin:0;font-size:17px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-tools"></i> Service Details
      </h3>
      <div id="viewSvcCodeDisplay" style="font-size:12px;color:rgba(255,255,255,.75);margin-top:5px;font-family:monospace;letter-spacing:0.5px;"></div>
    </div>
    <!-- Scrollable Content Body -->
    <div style="flex:1 1 auto;overflow-y:auto;padding:22px;">
      <!-- Info Grid -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
        <div style="grid-column:1/-1;background:#f8fafc;border-radius:10px;padding:14px 16px;">
          <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Service Name</div>
          <div id="viewSvcName" style="font-size:16px;font-weight:700;color:#1e293b;"></div>
          <div id="viewSvcDesc" style="font-size:12px;color:#64748b;margin-top:4px;"></div>
        </div>
        <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;">
          <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Category</div>
          <div id="viewSvcCategory" style="font-size:13px;font-weight:600;color:#003d7a;"></div>
        </div>
        <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;">
          <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Status</div>
          <div id="viewSvcStatus"></div>
        </div>
        <div style="background:#e8f5e9;border-radius:10px;padding:14px 16px;">
          <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Service Fee</div>
          <div id="viewSvcServiceFee" style="font-size:20px;font-weight:800;color:#002F6C;"></div>
        </div>
        <div style="background:#e0f2fe;border-radius:10px;padding:14px 16px;">
          <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Labor Fee</div>
          <div id="viewSvcLaborFee" style="font-size:20px;font-weight:800;color:#0369a1;"></div>
        </div>
        <div style="background:#f0fdf4;border-radius:10px;padding:14px 16px;">
          <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Total Fee</div>
          <div id="viewSvcTotalFee" style="font-size:20px;font-weight:800;color:#15803d;"></div>
        </div>
        <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;">
          <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Duration</div>
          <div id="viewSvcDuration" style="font-size:14px;font-weight:600;color:#334155;"></div>
        </div>
        <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;">
          <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Required Mechanics</div>
          <div id="viewSvcMechanics" style="font-size:14px;font-weight:600;color:#334155;"></div>
        </div>
      </div>

      <!-- Fee History -->
      <div style="border-top:1px solid #e2e8f0;padding-top:18px;">
        <div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-history" style="color:#0369a1;"></i> Fee Change History
        </div>
        <div id="viewSvcHistory" style="min-height:60px;">
          <div style="text-align:center;color:#94a3b8;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>
        </div>
      </div>
    </div>
    <!-- Footer (Fixed Bottom) -->
    <div style="flex-shrink:0;padding:14px 22px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;align-items:center;background:#f8fafc;border-radius:0 0 14px 14px;">
      <button type="button" onclick="closeViewServiceModal()" style="background:#f1f5f9 !important;color:#0f172a !important;border:1px solid #cbd5e1;padding:9px 28px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Close</button>
    </div>
  </div>
</div>



<style>
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Force ultra-bright white title text in all modal headers */
div[id$="Modal"] h3,
div[id$="Modal"] h3 *,
.modal h3,
.modal h3 * {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    opacity: 1 !important;
}

/* Ensure ultra-crisp high contrast text inside all modal inputs */
.modal input[type="text"],
.modal input[type="number"],
.modal input[type="date"],
.modal select,
.modal textarea,
div[id$="Modal"] input[type="text"],
div[id$="Modal"] input[type="number"],
div[id$="Modal"] input[type="date"],
div[id$="Modal"] select,
div[id$="Modal"] textarea {
    color: #0f172a !important;
    background-color: #ffffff !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    border: 1.5px solid #94a3b8 !important;
    border-radius: 8px !important;
    padding: 10px 14px !important;
    box-sizing: border-box !important;
    outline: none !important;
    transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
}

.modal input[type="text"]:focus,
.modal input[type="number"]:focus,
.modal input[type="date"]:focus,
.modal select:focus,
.modal textarea:focus {
    border-color: #002F6C !important;
    box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.15) !important;
}

.modal input::placeholder,
.modal textarea::placeholder {
    color: #64748b !important;
    font-weight: 400 !important;
    opacity: 0.85 !important;
}

.modal label {
    color: #1e293b !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
}
</style>

<script>
// ── Right-Side Toast Banner Notification System (Replaces modal popup forms) ──
function showCustomAlert(message, type, callback) {
    type = type || 'success';
    var isError = (type === 'error' || type === 'danger');

    var container = document.getElementById('rightToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'rightToastContainer';
        container.style.cssText = 'position:fixed;top:24px;right:24px;z-index:999999;display:flex;flex-direction:column;gap:10px;max-width:380px;width:calc(100% - 48px);pointer-events:none;';
        document.body.appendChild(container);
    }

    var toast = document.createElement('div');
    toast.className = 'right-toast-banner ' + (isError ? 'error' : 'success');
    toast.style.cssText = `
        pointer-events: auto;
        background: #ffffff;
        border-radius: 10px;
        padding: 14px 18px;
        box-shadow: 0 12px 30px rgba(0, 47, 108, 0.15), 0 2px 8px rgba(0, 0, 0, 0.06);
        display: flex;
        align-items: flex-start;
        gap: 12px;
        border: 1px solid #e2e8f0;
        ${isError ? 'border-left: 5px solid #dc2626;' : ''}
        transform: translateX(120%);
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    `;

    var iconBg = isError ? '#fee2e2' : '#dcfce7';
    var iconColor = isError ? '#dc2626' : '#16a34a';
    var iconClass = isError ? 'fa-exclamation-triangle' : 'fa-check-circle';
    var titleText = isError ? 'Notice / Error' : 'Submitted to Admin';

    toast.innerHTML = `
        <div style="width:34px;height:34px;border-radius:50%;background:${iconBg};color:${iconColor};display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;margin-top:1px;">
            <i class="fas ${iconClass}"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <h4 style="font-size:13px;font-weight:800;color:#0f172a;margin:0 0 3px 0;line-height:1.2;">${titleText}</h4>
            <p style="font-size:12px;font-weight:500;color:#475569;margin:0;line-height:1.35;">${message}</p>
        </div>
        ${isError ? '<button type="button" onclick="this.parentElement.remove();" style="background:none;border:none;color:#94a3b8;font-size:18px;line-height:1;cursor:pointer;padding:0 2px;margin-top:-2px;">&times;</button>' : ''}
    `;

    container.appendChild(toast);

    setTimeout(function() {
        toast.style.transform = 'translateX(0)';
        toast.style.opacity = '1';
    }, 20);

    var delay = (typeof callback === 'function') ? 1500 : 4000;
    setTimeout(function() {
        toast.style.transform = 'translateX(120%)';
        toast.style.opacity = '0';
        setTimeout(function() {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
            if (typeof callback === 'function') {
                callback();
            }
        }, 300);
    }, delay);
}

function closeStatusNotificationModal() {
    var container = document.getElementById('rightToastContainer');
    if (container) container.innerHTML = '';
}

// ── Professional Confirmation Modal ─────────────────────────────────────────
var confirmModalCallback = null;
var confirmModalData = null;

function showConfirmModal(title, subtitle, message, callback, data) {
    confirmModalCallback = callback || null;
    confirmModalData = data || null;
    
    document.getElementById('confirmModalTitle').textContent = title || 'Confirm Action';
    document.getElementById('confirmModalSubtitle').textContent = subtitle || 'Please confirm your action';
    document.getElementById('confirmModalMessage').textContent = message || 'Are you sure you want to proceed?';
    document.getElementById('confirmationModal').style.display = 'flex';
}

function closeConfirmModal() {
    document.getElementById('confirmationModal').style.display = 'none';
    confirmModalCallback = null;
    confirmModalData = null;
}

function confirmModalAction() {
    var cb = confirmModalCallback;
    var data = confirmModalData;
    closeConfirmModal();
    if (typeof cb === 'function') {
        cb(data);
    }
}

// ── Modal functions ─────────────────────────────────────────────────────────
function openAddProductModal() {
    document.getElementById('addProductModal').style.display = 'flex';
    var sel = document.getElementById('newFuelType');
    if (sel) { try { sel.focus(); } catch(e) {} }
    // Reset hidden fields
    var ftiEl = document.getElementById('newFuelTypeId');
    var ftnEl = document.getElementById('newFuelTypeName');
    if (ftiEl) ftiEl.value = '';
    if (ftnEl) ftnEl.value = '';
}

function closeAddProductModal() {
    document.getElementById('addProductModal').style.display = 'none';
    document.getElementById('addProductForm').reset();
    var ftiEl = document.getElementById('newFuelTypeId');
    var ftnEl = document.getElementById('newFuelTypeName');
    if (ftiEl) ftiEl.value = '';
    if (ftnEl) ftnEl.value = '';
}

function getCleanCanonicalFuelName(name) {
    if (!name) return 'Fuel';
    var lower = String(name).toLowerCase().trim();
    if (lower.indexOf('turbo') !== -1) return 'Turbo Diesel';
    if (lower.indexOf('diesel') !== -1) return 'Diesel';
    if (lower.indexOf('kerosene') !== -1) return 'Kerosene';
    if (lower.indexOf('xcs') !== -1) return 'XCS Plus';
    if (lower.indexOf('xtra') !== -1 || lower.indexOf('unl') !== -1 || lower.indexOf('advance') !== -1) return 'XTR ADVANCE';
    return String(name).replace(/[\s\-_#]*\d+$/gi, '').replace(/\s*\(UGT\s*#?\d+\)/gi, '').trim();
}

function openEditPriceModal(id, fuelType, currentPrice, capacity, criticalLevel, reorderLevel, ugtNo) {
    document.getElementById('editFuelId').value = id;
    
    var cleanFuelName = getCleanCanonicalFuelName(fuelType);
    var ugtVal = ugtNo || '';
    
    var ugtEl = document.getElementById('editUgtNo');
    if (ugtEl) ugtEl.value = ugtVal;
    
    var nameInput = document.getElementById('editFuelName');
    if (nameInput) nameInput.value = cleanFuelName;
    
    if (document.getElementById('editFuelType')) document.getElementById('editFuelType').value = cleanFuelName;
    var displayEl = document.getElementById('editFuelTypeDisplay');
    if (displayEl) displayEl.textContent = cleanFuelName;

    if (document.getElementById('editPrice')) document.getElementById('editPrice').value = parseFloat(currentPrice || 0).toFixed(2);
    if (document.getElementById('editFuelCapacity')) document.getElementById('editFuelCapacity').value = capacity || '';
    if (document.getElementById('editFuelCritical')) document.getElementById('editFuelCritical').value = criticalLevel || '';
    if (document.getElementById('editFuelReorder')) document.getElementById('editFuelReorder').value = reorderLevel || '';
    
    fetch('manager_set_prices_handler.php?action=get_fuel_details&id=' + id)
        .then(r => r.json()).then(data => {
            if (data.success && data.fuel) {
                var f = data.fuel;
                var rawName = getCleanCanonicalFuelName(f.clean_fuel_type || f.fuel_type || fuelType);
                var ugt = f.ugt_no || ugtNo || '';
                
                if (document.getElementById('editUgtNo')) document.getElementById('editUgtNo').value = ugt;
                if (document.getElementById('editFuelName')) document.getElementById('editFuelName').value = rawName;
                if (document.getElementById('editFuelType')) document.getElementById('editFuelType').value = rawName;
                document.getElementById('editFuelCapacity').value = (f.capacity       !== undefined && f.capacity       !== null) ? f.capacity       : (capacity || '');
                document.getElementById('editFuelCritical').value = (f.critical_level !== undefined && f.critical_level !== null) ? f.critical_level : (criticalLevel || '');
                document.getElementById('editFuelReorder').value  = (f.reorder_level  !== undefined && f.reorder_level  !== null) ? f.reorder_level  : (reorderLevel || '');
                if (f.status) {
                    var st = (f.status || '').toLowerCase();
                    if (st === 'inactive' || st === 'deactivated') {
                        var inactRadio = document.getElementById('editFuelStatusInactive');
                        if (inactRadio) inactRadio.checked = true;
                    } else {
                        var actRadio = document.getElementById('editFuelStatusActive');
                        if (actRadio) actRadio.checked = true;
                    }
                }
            }
        }).catch(function(){});
    document.getElementById('editPriceModal').style.display = 'flex';
    document.getElementById('editPrice').focus();
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

// Helper to safely attach event listener without throwing if element is missing
function safeAddListener(id, event, handler) {
    var el = document.getElementById(id);
    if (el) el.addEventListener(event, handler);
}

// Close modals on background click
safeAddListener('addProductModal', 'click', function(e) { if (e.target === this) closeAddProductModal(); });
safeAddListener('editPriceModal', 'click', function(e) { if (e.target === this) closeEditPriceModal(); });
safeAddListener('viewFuelModal', 'click', function(e) { if (e.target === this) closeViewFuelModal(); });
safeAddListener('rollbackPriceModal', 'click', function(e) { if (e.target === this) closeRollbackModal(); });
safeAddListener('addMerchandiseModal', 'click', function(e) { if (e.target === this) closeAddMerchandiseModal(); });
safeAddListener('editMerchPriceModal', 'click', function(e) { if (e.target === this) closeEditMerchPriceModal(); });
safeAddListener('addServiceModal', 'click', function(e) { if (e.target === this) closeAddServiceModal(); });
safeAddListener('editServicePriceModal', 'click', function(e) { if (e.target === this) closeEditServicePriceModal(); });


// ── Add Fuel Product Form Handler ───────────────────────────────────────────
safeAddListener('addProductForm', 'submit', function(e) {
    e.preventDefault();

    var fuelName = (document.getElementById('newFuelName') || {}).value || '';
    fuelName = fuelName.trim();
    var ugtNo    = (document.getElementById('newUgtNo') || {}).value || '';
    ugtNo = ugtNo.trim();
    var priceRaw = (document.getElementById('newPrice') || {}).value || '';
    var price    = parseFloat(priceRaw);
    var capRaw   = (document.getElementById('newCapacity') || {}).value || '';
    var capacity = parseFloat(capRaw);
    var critRaw  = (document.getElementById('newCriticalLevel') || {}).value || '';
    var critical = parseFloat(critRaw) || 0;
    var reordRaw = (document.getElementById('newReorderLevel') || {}).value || '';
    var reorder  = parseFloat(reordRaw) || 0;
    var statusEl = document.querySelector('input[name="newStatus"]:checked');
    var status   = statusEl ? statusEl.value : 'active';
    var remarks  = (document.getElementById('newRemarks') || {}).value || '';
    remarks = remarks.trim();

    if (!fuelName) {
        showCustomAlert('Fuel Name is required.', 'error');
        return;
    }
    if (!ugtNo) {
        showCustomAlert('Please select a UGT Number.', 'error');
        return;
    }
    if (isNaN(price) || price <= 0) {
        showCustomAlert('Please enter a valid selling price per liter.', 'error');
        return;
    }
    if (isNaN(capacity) || capacity <= 0) {
        showCustomAlert('Please enter a valid tank capacity.', 'error');
        return;
    }
    if (capacity <= reorder) {
        showCustomAlert('Tank Capacity must be greater than Reorder Level.', 'error');
        return;
    }
    if (reorder <= critical) {
        showCustomAlert('Reorder Level must be greater than Critical Level.', 'error');
        return;
    }

    var fd = new FormData();
    fd.append('action',         'add_fuel_product');
    fd.append('fuel_type',      fuelName);
    fd.append('ugt_no',         ugtNo);
    fd.append('price',          price);
    fd.append('capacity',       capacity);
    fd.append('critical_level', critical);
    fd.append('reorder_level',  reorder);
    fd.append('status',         status);
    fd.append('remarks',        remarks);

    fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showCustomAlert(data.message || 'Fuel product added successfully!', 'success', function() {
                    closeAddProductModal();
                    location.reload();
                });
            } else {
                showCustomAlert(data.message || 'Failed to add fuel product.', 'error');
            }
        })
        .catch(function() {
            showCustomAlert('Network error. Please try again.', 'error');
        });
});

// ── Edit Fuel Full Form Handler ─────────────────────────────────────────────
safeAddListener('editPriceForm', 'submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    var statusVal = document.querySelector('input[name="editFuelStatus"]:checked') ? document.querySelector('input[name="editFuelStatus"]:checked').value : 'active';
    var critEl = document.getElementById('editFuelCritical');
    fd.append('action',         'edit_fuel_full');
    fd.append('id',             document.getElementById('editFuelId').value);
    fd.append('ugt_no',         document.getElementById('editUgtNo') ? document.getElementById('editUgtNo').value.trim() : '');
    fd.append('fuel_name',      document.getElementById('editFuelName').value.trim());
    fd.append('price',          document.getElementById('editPrice').value);
    fd.append('capacity',       document.getElementById('editFuelCapacity').value);
    fd.append('critical_level', critEl ? critEl.value : '0');
    fd.append('reorder_level',  document.getElementById('editFuelReorder').value);
    fd.append('status',         statusVal);
    fd.append('remarks',        document.getElementById('editFuelRemarks') ? document.getElementById('editFuelRemarks').value.trim() : '');
    
    fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                showCustomAlert(data.message || 'Fuel product updated!', 'success', function() {
                    closeEditPriceModal();
                    location.reload();
                });
            } else {
                showCustomAlert(data.message || 'Update failed', 'error');
            }
        }).catch(function() { showCustomAlert('Network error. Please try again.', 'error'); });
});

// ── View Fuel Details (4 Full Sections) ─────────────────────────────────────
function viewFuelDetails(fuelId) {
    fetch('manager_set_prices_handler.php?action=get_fuel_details&id=' + fuelId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                var fuel = data.fuel;
                var history = data.history || [];
                var configHistory = data.config_history || [];
                var statusHistory = data.status_history || [];
                
                // Section 1 – Fuel Information
                var cleanFuelName = (fuel.fuel_type || '').replace(/\s*\(UGT\s*#?\d+\)/gi, '').trim();
                document.getElementById('viewFuelType').textContent = cleanFuelName;
                var ugtEl = document.getElementById('viewUgtNo');
                if (ugtEl) ugtEl.textContent = fuel.ugt_no || '-';
                document.getElementById('viewCurrentPrice').textContent = '₱' + parseFloat(fuel.price_per_liter || 0).toFixed(2);
                document.getElementById('viewStock').textContent = parseFloat(fuel.current_stock || fuel.current_level || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
                var availCap = parseFloat(fuel.available_capacity || (fuel.capacity - fuel.current_stock) || 0);
                document.getElementById('viewAvailableCapacity').textContent = availCap.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
                document.getElementById('viewCapacity').textContent = parseFloat(fuel.capacity || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
                document.getElementById('viewCriticalLevel').textContent = parseFloat(fuel.critical_level || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
                document.getElementById('viewReorderLevel').textContent = parseFloat(fuel.reorder_level || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
                
                // Stock Status
                var stock = parseFloat(fuel.current_stock || 0);
                var crit = parseFloat(fuel.critical_level || 0);
                var stockStatusText = '<span style="color:#16a34a;font-weight:700;">Normal</span>';
                if (stock <= 0) {
                    stockStatusText = '<span style="color:#dc2626;font-weight:700;">Out of Stock</span>';
                } else if (stock <= crit) {
                    stockStatusText = '<span style="color:#dc2626;font-weight:700;">Critical</span>';
                }
                document.getElementById('viewStatus').innerHTML = stockStatusText;

                // Product Status (Active / Inactive)
                var prodStatus = (fuel.status || 'active').toLowerCase();
                var prodBadge = prodStatus === 'active' 
                    ? '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;">Active</span>' 
                    : '<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;">Inactive</span>';
                document.getElementById('viewProductStatus').innerHTML = prodBadge;

                // Price Request Status
                document.getElementById('viewPriceRequestStatus').textContent = fuel.price_request_status || 'No Pending Request';
                document.getElementById('viewLastUpdated').textContent = fuel.last_updated || '-';
                document.getElementById('viewUpdatedBy').textContent = fuel.updated_by_name || '-';
                
                // Section 2 – Price History
                var historyBody = document.getElementById('priceHistoryBody');
                historyBody.innerHTML = '';
                if (history.length === 0) {
                    historyBody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;color:#94a3b8;">No price history available</td></tr>';
                } else {
                    history.forEach(function(h) {
                        var targetPrice = parseFloat(h.new_price || 0);
                        var curPrice = parseFloat(fuel.price_per_liter || 0);
                        var isCurrent = Math.abs(targetPrice - curPrice) < 0.001;
                        
                        var actionCell = '';
                        if (isCurrent) {
                            actionCell = '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;"><i class="fas fa-check"></i> Current</span>';
                        } else {
                            var safeFuelName = (fuel.fuel_type || '').replace(/'/g, "\\'");
                            var safeDate = (h.created_at || '').replace(/'/g, "\\'");
                            actionCell = '<button type="button" onclick="openRestorePriceModal(' + fuel.id + ', \'' + safeFuelName + '\', ' + curPrice + ', ' + targetPrice + ', \'' + safeDate + '\')" style="background:#002F6C !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:5px 12px;border-radius:6px;font-size:11px;font-weight:700 !important;cursor:pointer;display:inline-flex;align-items:center;gap:4px;box-shadow:0 2px 4px rgba(0,47,108,0.2);"><i class="fas fa-undo" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Restore</button>';
                        }

                        var stLower = (h.status || 'Approved').toLowerCase();
                        var statusBadge = '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Approved</span>';
                        if (stLower === 'pending') {
                            statusBadge = '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Pending</span>';
                        } else if (stLower === 'rejected') {
                            statusBadge = '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Rejected</span>';
                        }

                        var row = document.createElement('tr');
                        row.style.borderBottom = '1px solid #f1f5f9';
                        row.innerHTML = `
                            <td style="padding:10px 12px;color:#475569;">${h.created_at || '-'}</td>
                            <td style="padding:10px 12px;font-weight:700;color:#002F6C;">₱${targetPrice.toFixed(2)}</td>
                            <td style="padding:10px 12px;">${h.requested_by_name || 'Manager'}</td>
                            <td style="padding:10px 12px;">${h.approved_by_name || 'Admin'}</td>
                            <td style="padding:10px 12px;">${statusBadge}</td>
                            <td style="padding:10px 12px;text-align:center;">${actionCell}</td>
                        `;
                        historyBody.appendChild(row);
                    });
                }
                
                // Section 3 – Configuration History
                var configBody = document.getElementById('configHistoryBody');
                if (configBody) {
                    configBody.innerHTML = '';
                    if (configHistory.length === 0) {
                        configBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No configuration changes recorded yet</td></tr>';
                    } else {
                        configHistory.forEach(function(ch) {
                            var row = document.createElement('tr');
                            row.style.borderBottom = '1px solid #f1f5f9';
                            row.innerHTML = `
                                <td style="padding:8px 12px;color:#475569;">${ch.created_at || '-'}</td>
                                <td style="padding:8px 12px;font-weight:700;color:#002F6C;">${ch.field_name || '-'}</td>
                                <td style="padding:8px 12px;color:#dc2626;font-weight:600;">${ch.old_value || '-'}</td>
                                <td style="padding:8px 12px;color:#16a34a;font-weight:700;">${ch.new_value || '-'}</td>
                                <td style="padding:8px 12px;">${ch.updated_by_name || '-'}</td>
                            `;
                            configBody.appendChild(row);
                        });
                    }
                }

                // Section 4 – Status History
                var statusBody = document.getElementById('statusHistoryBody');
                if (statusBody) {
                    statusBody.innerHTML = '';
                    if (statusHistory.length === 0) {
                        statusBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No status history recorded yet</td></tr>';
                    } else {
                        statusHistory.forEach(function(sh) {
                            var oldSt = (sh.old_status || (sh.status === 'Activated' ? 'Inactive' : (sh.status === 'Deactivated' ? 'Active' : 'Active'))).toLowerCase();
                            var newSt = (sh.new_status || (sh.status === 'Deactivated' ? 'Inactive' : (sh.status === 'Activated' ? 'Active' : 'Inactive'))).toLowerCase();
                            
                            var oldBadge = oldSt === 'active'
                                ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Active</span>'
                                : '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Inactive</span>';
                            
                            var newBadge = newSt === 'active'
                                ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Active</span>'
                                : '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Inactive</span>';
                            
                            var reasonTxt = sh.reason ? sh.reason : '-';

                            var row = document.createElement('tr');
                            row.style.borderBottom = '1px solid #f1f5f9';
                            row.innerHTML = `
                                <td style="padding:8px 12px;color:#475569;">${sh.created_at || '-'}</td>
                                <td style="padding:8px 12px;">${oldBadge}</td>
                                <td style="padding:8px 12px;">${newBadge}</td>
                                <td style="padding:8px 12px;color:#64748b;">${reasonTxt}</td>
                                <td style="padding:8px 12px;">${sh.changed_by_name || 'Manager'}</td>
                            `;
                            statusBody.appendChild(row);
                        });
                    }
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

// ── Toggle Fuel Status (Activate / Deactivate with Confirmation Dialog) ─────
function toggleFuelStatus(id, targetStatus, fuelName) {
    var actionText = targetStatus === 'active' ? 'activate' : 'deactivate';
    var titleText = targetStatus === 'active' ? 'Activate Fuel Product' : 'Deactivate Fuel Product';
    var messageText = 'Are you sure you want to ' + actionText + ' "' + fuelName + '"?\n\nThis will set the product status to ' + targetStatus + '.';
    
    showConfirmModal(
        titleText,
        'Confirm ' + actionText,
        messageText,
        function(data) {
            var formData = new FormData();
            formData.append('action', 'toggle_fuel_status');
            formData.append('id', data.id);
            formData.append('target_status', data.targetStatus);
            
            fetch('manager_set_prices_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showCustomAlert(data.message || 'Status updated successfully!', 'success', function() {
                        location.reload();
                    });
                } else {
                    showCustomAlert(data.message || 'Failed to update status', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showCustomAlert('Error updating fuel status. Please try again.', 'error');
            });
        },
        { id: id, targetStatus: targetStatus, fuelName: fuelName }
    );
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
safeAddListener('rollbackPriceForm', 'submit', function(e) {
    e.preventDefault();
    
    var fuelId = document.getElementById('rollbackFuelId').value;
    var historyId = document.getElementById('rollbackHistoryId').value;
    var reason = document.getElementById('rollbackReason').value.trim();
    
    if (!fuelId || !historyId || !reason) {
        showCustomAlert('Please provide a reason for the rollback.', 'error');
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
            showCustomAlert('Price rolled back successfully!', 'success', function() {
                closeRollbackModal();
                closeViewFuelModal();
                location.reload();
            });
        } else {
            showCustomAlert(data.message || 'Failed to rollback price', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showCustomAlert('Error rolling back price. Please try again.', 'error');
    });
});

// ── Price Restoration Modal Functions & Handler ──────────────────────────────
function openRestorePriceModal(fuelId, fuelName, currentPrice, targetPrice, effectiveDate) {
    document.getElementById('restoreFuelId').value = fuelId;
    document.getElementById('restoreTargetPrice').value = targetPrice;
    document.getElementById('restoreFuelNameDisplay').textContent = fuelName;
    document.getElementById('restoreDateDisplay').textContent = effectiveDate || '-';
    document.getElementById('restoreCurrentPriceDisplay').textContent = '₱' + parseFloat(currentPrice).toFixed(2);
    document.getElementById('restoreTargetPriceDisplay').textContent = '₱' + parseFloat(targetPrice).toFixed(2);
    document.getElementById('restoreReason').value = '';
    document.getElementById('restorePriceModal').style.display = 'flex';
}

function closeRestorePriceModal() {
    document.getElementById('restorePriceModal').style.display = 'none';
}

safeAddListener('restorePriceForm', 'submit', function(e) {
    e.preventDefault();
    var fuelId = document.getElementById('restoreFuelId').value;
    var targetPrice = document.getElementById('restoreTargetPrice').value;
    var reason = document.getElementById('restoreReason').value.trim();

    var formData = new FormData();
    formData.append('action', 'submit_price_restoration');
    formData.append('fuel_id', fuelId);
    formData.append('target_price', targetPrice);
    formData.append('reason', reason);

    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showCustomAlert(data.message || 'Price restoration request submitted for Admin approval.', 'success', function() {
                closeRestorePriceModal();
                closeViewFuelModal();
                location.reload();
            });
        } else {
            showCustomAlert(data.message || 'Failed to submit price restoration request.', 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showCustomAlert('Error submitting price restoration request.', 'error');
    });
});

// ── Deactivate Fuel ─────────────────────────────────────────────────────────
function deactivateFuel(id, fuelType) {
    showConfirmModal(
        'Deactivate Fuel Product',
        'Confirm deactivation',
        'Are you sure you want to deactivate "' + fuelType + '"?\n\nThis will set the fuel status to inactive.',
        function(data) {
            var formData = new FormData();
            formData.append('action', 'deactivate_fuel');
            formData.append('id', data.id);
            
            fetch('manager_set_prices_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showCustomAlert('Fuel product deactivated successfully!', 'success', function() {
                        location.reload();
                    });
                } else {
                    showCustomAlert(data.message || 'Failed to deactivate product', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showCustomAlert('Error deactivating product. Please try again.', 'error');
            });
        },
        { id: id, fuelType: fuelType }
    );
}

// ── Activate Fuel ───────────────────────────────────────────────────────────
function activateFuel(id, fuelType) {
    showConfirmModal(
        'Activate Fuel Product',
        'Confirm activation',
        'Are you sure you want to activate "' + fuelType + '"?\n\nThis will set the fuel status to active.',
        function(data) {
            var formData = new FormData();
            formData.append('action', 'activate_fuel');
            formData.append('id', data.id);
            
            fetch('manager_set_prices_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showCustomAlert('Fuel product activated successfully!', 'success', function() {
                        location.reload();
                    });
                } else {
                    showCustomAlert(data.message || 'Failed to activate product', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showCustomAlert('Error activating product. Please try again.', 'error');
            });
        },
        { id: id, fuelType: fuelType }
    );
}

// ══════════════════════════════════════════════════════════════════════════
// MERCHANDISE FUNCTIONS
// ══════════════════════════════════════════════════════════════════════════

function openAddMerchandiseModal() {
    document.getElementById('addMerchandiseModal').style.display = 'flex';
    document.getElementById('newMerchName').focus();
    // Reset barcode status
    var bs = document.getElementById('newMerchBarcodeStatus');
    if (bs) bs.innerHTML = '';
    var bf = document.getElementById('newMerchBarcode');
    if (bf) { bf.style.borderColor = '#d1d5db'; bf.value = ''; }
}

// ══════════════════════════════════════════════════════════════════════════
// BARCODE SCANNER SUPPORT
// Works with USB/Bluetooth barcode guns (rapid keystrokes + Enter terminator)
// Also works with manual keyboard typing + Enter
// ══════════════════════════════════════════════════════════════════════════

// activateBarcodeScan: highlight field as ready for scan, focus it
function activateBarcodeScan(inputId, context) {
    var el = document.getElementById(inputId);
    if (!el) return;

    // Do NOT clear existing value — barcode is optional
    el.focus();
    el.style.borderColor = '#f59e0b';
    el.style.background  = '#fffbeb';
    el.setAttribute('data-scan-context', context);
    el.setAttribute('data-scan-active', '1');

    var statusId = context === 'add' ? 'newMerchBarcodeStatus' : 'editMerchBarcodeStatus';
    var st = document.getElementById(statusId);
    if (st) st.innerHTML = '<span style="color:#d97706;"><i class="fas fa-barcode"></i> Ready — scan now or type barcode, then press Enter</span>';
}

// handleBarcodeKeydown: fires on keydown in the barcode input
// Barcode guns send chars very fast then fire Enter — we catch Enter
function handleBarcodeKeydown(event, context) {
    var el   = event.target;
    var key  = event.key || '';
    var code = event.keyCode || event.which;

    if (key === 'Enter' || code === 13) {
        event.preventDefault();
        event.stopPropagation();

        var barcodeVal = el.value.trim();
        if (barcodeVal.length === 0) return;

        // Visual confirmation
        el.style.borderColor = '#16a34a';
        el.style.background  = '#f0fdf4';

        var ctx = el.getAttribute('data-scan-context') || context;
        lookupProductByBarcode(barcodeVal, ctx);
    }
}

// Global keydown listener: auto-route rapid scanner input to focused barcode field
// Barcode guns fire chars at < 30ms intervals — detect that pattern
(function() {
    var _buf       = '';
    var _lastTime  = 0;
    var _targetEl  = null;
    var RAPID_MS   = 50; // max ms between keystrokes to classify as scanner input

    document.addEventListener('keydown', function(e) {
        // Only intercept if a barcode input has data-scan-active
        var addEl  = document.getElementById('newMerchBarcode');
        var editEl = document.getElementById('editMerchBarcode');
        var active = null;

        if (addEl  && addEl.getAttribute('data-scan-active')  === '1' && document.activeElement === addEl)  active = addEl;
        if (editEl && editEl.getAttribute('data-scan-active') === '1' && document.activeElement === editEl) active = editEl;

        if (!active) return; // not in scan mode, let normal keydown handle it

        var now = Date.now();
        var ch  = e.key;

        if (ch === 'Enter' || e.keyCode === 13) {
            // Scanner completed — value is already in the input via normal keydown
            // handleBarcodeKeydown will handle it
            return;
        }

        // Track rapid input
        if (now - _lastTime < RAPID_MS) {
            // Still in rapid sequence — mark as scanner input
            active.setAttribute('data-from-scanner', '1');
        } else {
            // New sequence
            active.removeAttribute('data-from-scanner');
        }
        _lastTime = now;
    }, true);
})();

// lookupProductByBarcode: calls backend handler to find product by barcode
function lookupProductByBarcode(barcode, context) {
    var statusId = context === 'add' ? 'newMerchBarcodeStatus' : 'editMerchBarcodeStatus';
    var st = document.getElementById(statusId);

    if (st) st.innerHTML = '<span style="color:#0284c7;"><i class="fas fa-spinner fa-spin"></i> Looking up barcode <code>' + barcode + '</code>...</span>';

    fetch('manager_set_prices_handler.php?action=lookup_barcode&barcode=' + encodeURIComponent(barcode))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.product) {
            var p = data.product;
            if (st) st.innerHTML = '<span style="color:#16a34a;"><i class="fas fa-check-circle"></i> Found: <strong>' + escHtml(p.name || '') + '</strong> — empty fields auto-filled!</span>';

            if (context === 'add') {
                fillIfEmpty('newMerchName',     p.name         || '');
                fillIfEmpty('newMerchBrand',    p.brand        || '');
                fillIfEmpty('newMerchCategory', p.category_name || p.category || '');
                fillIfEmpty('newMerchSize',     p.unit         || '');
                fillIfEmpty('newMerchPrice',    p.price        || '');
            } else {
                fillIfEmpty('editMerchBrand',    p.brand        || '');
                fillIfEmpty('editMerchCategory', p.category_name || p.category || '');
                fillIfEmpty('editMerchSize',     p.unit         || '');
            }
        } else {
            if (st) st.innerHTML = '<span style="color:#64748b;"><i class="fas fa-info-circle"></i> Barcode <code style="background:#f1f5f9;padding:1px 6px;border-radius:3px;">' + escHtml(barcode) + '</code> saved — no existing product match.</span>';
        }
    })
    .catch(function() {
        if (st) st.innerHTML = '<span style="color:#64748b;"><i class="fas fa-barcode"></i> Barcode saved: <code>' + escHtml(barcode) + '</code></span>';
    });
}

// fillIfEmpty: only fills a field if it is currently blank
function fillIfEmpty(id, val) {
    var el = document.getElementById(id);
    if (el && val && !el.value.trim()) el.value = val;
}

// escHtml: escape HTML special chars for safe display
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function closeAddMerchandiseModal() {
    document.getElementById('addMerchandiseModal').style.display = 'none';
    document.getElementById('addMerchandiseForm').reset();
}

// Open Edit Merchandise Modal — populates all FIFO Product Management fields
function openEditMerchModal(id) {
    document.getElementById('editMerchId').value = id;
    document.getElementById('editMerchPriceModal').style.display = 'flex';
    // Fetch full details from handler
    fetch('manager_set_prices_handler.php?action=get_merch_details&id=' + id)
        .then(r => r.json()).then(data => {
            if (data.success && data.item) {
                var i = data.item;
                document.getElementById('editMerchName').value      = i.product_name || '';
                document.getElementById('editMerchSku').value       = i.sku || '';
                document.getElementById('editMerchCategory').value  = i.category || '';
                document.getElementById('editMerchBrand').value     = i.brand || '';
                document.getElementById('editMerchSize').value      = i.size || i.unit || '';
                document.getElementById('editMerchBarcode').value   = i.barcode || '';
                document.getElementById('editMerchPrice').value     = parseFloat(i.unit_price || 0);
                document.getElementById('editMerchReorder').value   = parseInt(i.reorder_level || 24);
                document.getElementById('editMerchCritical').value  = parseInt(i.critical_level || 10);
                document.getElementById('editMerchStatus').value    = i.status || 'active';
            }
        });
    document.getElementById('editMerchName').focus();
}

// Keep old name as alias for backward compat
function openEditMerchPriceModal(id, productName, currentPrice) {
    openEditMerchModal(id);
}


function closeEditMerchPriceModal() {
    document.getElementById('editMerchPriceModal').style.display = 'none';
    document.getElementById('editMerchPriceForm').reset();
}

// ── View Merchandise Details Modal ──────────────────────────────────────────
function viewMerchandiseDetails(id) {
    var modal = document.getElementById('viewMerchModal');
    modal.style.display = 'flex';
    // Loading placeholders
    ['vm_sku','vm_barcode','vm_name','vm_category','vm_brand','vm_unit','vm_price','vm_cost','vm_stock','vm_batch_count','vm_reorder'].forEach(function(el){
        var e = document.getElementById(el); if(e) e.textContent = '...';
    });
    document.getElementById('vm_status').innerHTML = '...';
    ['vm_batches_body','vm_price_history_body','vm_config_history_body','vm_status_history_body'].forEach(function(el){
        var e = document.getElementById(el);
        if(e) e.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:12px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
    });

    fetch('manager_set_prices_handler.php?action=get_merchandise_details&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (!data.success) { alert(data.message || 'Failed to load details'); closeViewMerchModal(); return; }
        var p = data.product;
        document.getElementById('vm_title').textContent = (p.name || 'Product') + ' — SPECIFICATION & HISTORY';
        document.getElementById('vm_sku').textContent = p.sku || '—';
        document.getElementById('vm_barcode').textContent = p.barcode || '—';
        document.getElementById('vm_name').textContent = p.name || '—';
        document.getElementById('vm_category').textContent = p.category_name || '—';
        document.getElementById('vm_brand').textContent = p.brand || '—';
        document.getElementById('vm_unit').textContent = p.unit || '—';
        document.getElementById('vm_price').textContent = '₱' + parseFloat(p.price || 0).toFixed(2);
        document.getElementById('vm_cost').textContent = '₱' + parseFloat(p.cost || 0).toFixed(2);
        document.getElementById('vm_stock').textContent = parseFloat(p.current_stock || 0).toLocaleString();
        document.getElementById('vm_batch_count').textContent = (p.batch_count || 0) + ' batch(es)';
        document.getElementById('vm_reorder').textContent = p.min_stock_level || '—';
        var stLower = (p.status || 'active').toLowerCase();
        var stColor = stLower === 'active' ? '#16a34a' : '#dc2626';
        var stBg = stLower === 'active' ? '#dcfce7' : '#fee2e2';
        document.getElementById('vm_status').innerHTML = '<span style="background:' + stBg + ';color:' + stColor + ';padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">' + (p.status || 'Active') + '</span>';

        // Batches
        var bb = document.getElementById('vm_batches_body');
        if (data.batches && data.batches.length > 0) {
            bb.innerHTML = data.batches.map(function(b) {
                var stBadge = b.status === 'active' ? '<span style="background:#dcfce7;color:#16a34a;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:700;">Active</span>' : '<span style="background:#fee2e2;color:#dc2626;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:700;">' + b.status + '</span>';
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-family:monospace;font-weight:700;color:#0284c7;">' + (b.batch_number || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;">' + parseFloat(b.remaining_qty || 0).toLocaleString() + '</td>' +
                    '<td style="padding:8px 12px;">' + (b.expiration_date || '—') + '</td>' +
                    '<td style="padding:8px 12px;">' + stBadge + '</td></tr>';
            }).join('');
        } else {
            bb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No batch records</td></tr>';
        }

        // Price History
        var pb = document.getElementById('vm_price_history_body');
        if (data.price_history && data.price_history.length > 0) {
            pb.innerHTML = data.price_history.map(function(h, idx) {
                var statusColor = h.status === 'approved' ? '#16a34a' : h.status === 'rejected' ? '#dc2626' : '#d97706';
                var statusBg = h.status === 'approved' ? '#dcfce7' : h.status === 'rejected' ? '#fee2e2' : '#fef3c7';
                var actionBtn = '';
                if (h.status === 'approved' && idx > 0) {
                    actionBtn = '<button onclick="restoreMerchPrice(' + (p.id || id) + ',' + h.old_price + ')" style="background:#4f46e5;color:#fff;border:none;padding:3px 10px;border-radius:5px;font-size:11px;cursor:pointer;font-weight:700;"><i class=\'fas fa-undo\'></i> Restore</button>';
                } else if (h.status === 'approved' && idx === 0) {
                    actionBtn = '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">Current</span>';
                } else if (h.status === 'pending') {
                    actionBtn = '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">Awaiting Approval</span>';
                }
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-size:11px;color:#64748b;">' + (h.created_at || '—') + '</td>' +
                    '<td style="padding:8px 12px;">₱' + parseFloat(h.old_price || 0).toFixed(2) + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;color:#002F6C;">₱' + parseFloat(h.new_price || 0).toFixed(2) + '</td>' +
                    '<td style="padding:8px 12px;font-size:11px;">' + (h.requested_by_name || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-size:11px;">' + (h.approved_by_name || '—') + '</td>' +
                    '<td style="padding:8px 12px;"><span style="background:' + statusBg + ';color:' + statusColor + ';padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">' + (h.status || '—') + '</span></td>' +
                    '<td style="padding:8px 12px;text-align:center;">' + actionBtn + '</td></tr>';
            }).join('');
        } else {
            pb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:12px;color:#94a3b8;">No price history</td></tr>';
        }

        // Config History
        var cb = document.getElementById('vm_config_history_body');
        if (data.config_history && data.config_history.length > 0) {
            cb.innerHTML = data.config_history.map(function(h) {
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-size:11px;color:#64748b;">' + (h.created_at || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;">' + (h.field_name || '—') + '</td>' +
                    '<td style="padding:8px 12px;color:#dc2626;">' + (h.old_value || '—') + '</td>' +
                    '<td style="padding:8px 12px;color:#16a34a;font-weight:700;">' + (h.new_value || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-size:11px;">' + (h.changed_by_name || '—') + '</td></tr>';
            }).join('');
        } else {
            cb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:12px;color:#94a3b8;">No configuration changes recorded</td></tr>';
        }

        // Status History
        var sb = document.getElementById('vm_status_history_body');
        if (data.status_history && data.status_history.length > 0) {
            sb.innerHTML = data.status_history.map(function(h) {
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-size:11px;color:#64748b;">' + (h.created_at || '—') + '</td>' +
                    '<td style="padding:8px 12px;color:#64748b;">' + (h.old_status || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;">' + (h.new_status || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-size:11px;">' + (h.changed_by_name || '—') + '</td></tr>';
            }).join('');
        } else {
            sb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No status changes recorded</td></tr>';
        }
    })
    .catch(function(err) {
        closeViewMerchModal();
        alert('Error loading details. Please try again.');
    });
}

function closeViewMerchModal() {
    document.getElementById('viewMerchModal').style.display = 'none';
}

function restoreMerchPrice(id, targetPrice) {
    if (!confirm('Request restore of selling price to ₱' + parseFloat(targetPrice).toFixed(2) + '?\n\nThis will be submitted for Admin approval.')) return;
    var fd = new FormData();
    fd.append('action', 'restore_merchandise_price');
    fd.append('id', id);
    fd.append('target_price', targetPrice);
    fd.append('reason', 'Price Restoration Request by Manager');
    fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showCustomAlert ? showCustomAlert(data.message, 'success') : alert(data.message);
            closeViewMerchModal();
        } else {
            showCustomAlert ? showCustomAlert(data.message || 'Failed to submit restore request.', 'error') : alert(data.message);
        }
    })
    .catch(() => alert('Error submitting restore request.'));
}

// Add Merchandise Form Handler
safeAddListener('addMerchandiseForm', 'submit', function(e) {
    e.preventDefault();

    var name     = document.getElementById('newMerchName').value.trim();
    var category = document.getElementById('newMerchCategory').value.trim();
    var price    = parseFloat(document.getElementById('newMerchPrice').value);
    var sku      = document.getElementById('newMerchSku').value.trim();
    var brand    = document.getElementById('newMerchBrand').value.trim();
    var size     = document.getElementById('newMerchSize').value.trim();
    var barcode  = document.getElementById('newMerchBarcode').value.trim();
    var reorder  = parseInt(document.getElementById('newMerchReorder').value) || 24;
    var critical = parseInt(document.getElementById('newMerchCritical').value) || 10;

    if (!name || !category || isNaN(price) || price < 0) {
        showCustomAlert('Please fill all required fields with valid values.', 'error');
        return;
    }

    var formData = new FormData();
    formData.append('action', 'add_merchandise');
    formData.append('product_name', name);
    formData.append('category', category);
    formData.append('brand', brand);
    formData.append('unit_price', price);
    formData.append('unit_cost', 0); // cost set per delivery batch
    formData.append('sku', sku);
    formData.append('size', size);
    formData.append('barcode', barcode);
    formData.append('reorder_level', reorder);
    formData.append('critical_level', critical);

    fetch('manager_set_prices_handler.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showCustomAlert('Product added successfully!', 'success', function() {
                closeAddMerchandiseModal();
                location.reload();
            });
        } else {
            showCustomAlert(data.message || 'Failed to add product', 'error');
        }
    })
    .catch(() => showCustomAlert('Error adding product. Please try again.', 'error'));
});

// Edit Merchandise Full Form Handler
safeAddListener('editMerchPriceForm', 'submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('action',         'edit_merchandise_full');
    fd.append('id',             document.getElementById('editMerchId').value);
    fd.append('product_name',   document.getElementById('editMerchName').value.trim());
    fd.append('sku',            document.getElementById('editMerchSku').value.trim());
    fd.append('category',       document.getElementById('editMerchCategory').value.trim());
    fd.append('brand',          document.getElementById('editMerchBrand').value.trim());
    fd.append('size',           document.getElementById('editMerchSize').value.trim());
    fd.append('barcode',        document.getElementById('editMerchBarcode').value.trim());
    fd.append('unit_price',     document.getElementById('editMerchPrice').value);
    fd.append('unit_cost',      0); // cost managed per delivery batch
    fd.append('reorder_level',  document.getElementById('editMerchReorder').value);
    fd.append('critical_level', document.getElementById('editMerchCritical').value);
    fd.append('status',         document.getElementById('editMerchStatus').value);
    fetch('manager_set_prices_handler.php', {method:'POST', body:fd})
        .then(r => r.json()).then(data => {
            if (data.success) {
                showCustomAlert('Product updated successfully!', 'success', function() {
                    closeEditMerchPriceModal();
                    location.reload();
                });
            } else {
                showCustomAlert(data.message || 'Failed to update product', 'error');
            }
        }).catch(() => showCustomAlert('Error updating product.', 'error'));
});

// View Batches function
function viewProductBatches(productId, productName) {
    document.getElementById('viewBatchesTitle').textContent = productName + ' — Batch History';
    document.getElementById('viewBatchesContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading batches...</div>';
    document.getElementById('viewBatchesModal').style.display = 'flex';

    fetch('manager_set_prices_handler.php?action=get_product_batches&id=' + productId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.batches || data.batches.length === 0) {
                document.getElementById('viewBatchesContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>No batch records found for this product.<br><small>Record a delivery to create the first batch.</small></div>';
                return;
            }
            var batches = data.batches;
            var firstActive = true;
            var rows = batches.map(function(b) {
                var isFirst = firstActive && b.status === 'active';
                if (isFirst) firstActive = false;
                var bNum = b.batch_number || ('B' + String(b.id).padStart(4,'0'));
                var fifo = isFirst ? '<span style="background:#16a34a;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:700;margin-left:4px;">NEXT FIFO</span>' : '';
                var statusBadge = b.status === 'active'
                    ? '<span style="background:#dcfce7;color:#166534;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:600;">Active</span>'
                    : '<span style="background:#f1f5f9;color:#64748b;padding:2px 7px;border-radius:4px;font-size:11px;">Depleted</span>';
                return '<tr style="border-bottom:1px solid #f1f5f9;">'
                    + '<td style="padding:8px 10px;"><code style="color:#4f46e5;background:#ede9fe;padding:2px 6px;border-radius:3px;font-size:12px;">' + bNum + '</code>' + fifo + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;">' + parseInt(b.quantity_received||0) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;font-weight:700;">' + parseInt(b.remaining_qty||0) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;color:#64748b;">&#8369;' + parseFloat(b.unit_cost||0).toFixed(2) + '</td>'
                    + '<td style="padding:8px 10px;font-size:11px;color:#64748b;">' + (b.date_received||'—').substring(0,10) + '</td>'
                    + '<td style="padding:8px 10px;text-align:center;">' + statusBadge + '</td>'
                    + '</tr>';
            }).join('');
            document.getElementById('viewBatchesContent').innerHTML =
                '<div style="overflow-x:auto;">'
                + '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
                + '<thead><tr style="background:#002F6C;color:#fff;">'
                + '<th style="padding:10px;text-align:left;">Batch No.</th>'
                + '<th style="padding:10px;text-align:right;">Received Qty</th>'
                + '<th style="padding:10px;text-align:right;">Remaining</th>'
                + '<th style="padding:10px;text-align:right;">Unit Cost</th>'
                + '<th style="padding:10px;text-align:right;">Selling Price</th>'
                + '<th style="padding:10px;">Date Received</th>'
                + '<th style="padding:10px;text-align:center;">Status</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
        })
        .catch(() => {
            document.getElementById('viewBatchesContent').innerHTML = '<div style="text-align:center;color:#dc2626;padding:20px;">Error loading batch data.</div>';
        });
}

// ══════════════════════════════════════════════════════════════════════════
// SERVICE FUNCTIONS — fully wired to new modals
// ══════════════════════════════════════════════════════════════════════════

// ── Shared state ─────────────────────────────────────────────────────────
var _svcOriginalFee   = 0;
var _svcOriginalLabor = 0;
var _viewSvcData      = null; // keeps current view data so "Edit from View" works

// ── Filter (search + category + status) ──────────────────────────────────
function filterServiceTable() {
    var q       = (document.getElementById('svcSearchInput') || {}).value || '';
    var cat     = (document.getElementById('serviceCategoryFilter') || {}).value || '';
    var status  = (document.getElementById('svcStatusFilter') || {}).value || '';
    q = q.toLowerCase().trim();

    var rows    = document.querySelectorAll('#serviceTableBody .service-row');
    var visible = 0;

    rows.forEach(function(row) {
        var name   = row.getAttribute('data-name') || '';
        var rCat   = row.getAttribute('data-category') || '';
        var rAct   = row.getAttribute('data-active') || '';

        var matchQ   = !q   || name.indexOf(q) !== -1 || rCat.toLowerCase().indexOf(q) !== -1;
        var matchCat = !cat || rCat === cat;
        var matchSt  = !status || rAct === status;

        if (matchQ && matchCat && matchSt) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    var noRes = document.getElementById('svcNoResults');
    if (noRes) noRes.style.display = visible === 0 && rows.length > 0 ? 'block' : 'none';
}

// ── Custom Category Input Toggle ─────────────────────────────────────────
function toggleCustomCategoryInput(mode) {
    var selId  = mode === 'add' ? 'addSvcCategory' : 'editSvcCategory';
    var wrapId = mode === 'add' ? 'addSvcCustomWrap' : 'editSvcCustomWrap';
    var inpId  = mode === 'add' ? 'addSvcCustomCategory' : 'editSvcCustomCategory';

    var sel  = document.getElementById(selId);
    var wrap = document.getElementById(wrapId);
    var inp  = document.getElementById(inpId);

    if (!sel || !wrap) return;

    var val = sel.value;
    if (val === 'Custom Services' || val === 'Others') {
        wrap.style.display = 'block';
        if (inp) setTimeout(function() { inp.focus(); }, 50);
    } else {
        wrap.style.display = 'none';
        if (inp) inp.value = '';
    }
}

// ── ADD SERVICE MODAL ─────────────────────────────────────────────────────
function openAddServiceModal() {
    var modal = document.getElementById('addServiceModal');
    if (!modal) return;
    document.getElementById('addServiceForm').reset();
    var wrap = document.getElementById('addSvcCustomWrap');
    if (wrap) wrap.style.display = 'none';
    modal.style.display = 'flex';
    var f = document.getElementById('addSvcName');
    if (f) setTimeout(function() { f.focus(); }, 80);
}

function closeAddServiceModal() {
    var modal = document.getElementById('addServiceModal');
    if (modal) modal.style.display = 'none';
    var form = document.getElementById('addServiceForm');
    if (form) form.reset();
    var wrap = document.getElementById('addSvcCustomWrap');
    if (wrap) wrap.style.display = 'none';
}

safeAddListener('addServiceForm', 'submit', function(e) {
    e.preventDefault();

    var name     = (document.getElementById('addSvcName')        || {}).value || '';
    var category = (document.getElementById('addSvcCategory')    || {}).value || '';
    var custom   = ((document.getElementById('addSvcCustomCategory') || {}).value || '').trim();

    if ((category === 'Custom Services' || category === 'Others') && custom) {
        category = custom;
    }

    var svcFee   = parseFloat((document.getElementById('addSvcServiceFee') || {}).value) || 0;
    var laborFee = parseFloat((document.getElementById('addSvcLaborFee')   || {}).value) || 0;
    var duration = parseInt((document.getElementById('addSvcDuration')     || {}).value) || 60;
    var mechs    = parseInt((document.getElementById('addSvcMechanics')    || {}).value) || 1;
    var desc     = (document.getElementById('addSvcDescription')  || {}).value || '';

    name     = name.trim();
    category = category.trim();

    if (!name || !category) {
        showCustomAlert('Please fill in Service Name and Category.', 'error');
        return;
    }
    if (svcFee < 0 || laborFee < 0) {
        showCustomAlert('Fees cannot be negative.', 'error');
        return;
    }

    var btn = e.target.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }

    var fd = new FormData();
    fd.append('action',               'add_service');
    fd.append('service_name',         name);
    fd.append('category',             category);
    fd.append('service_price',        svcFee);
    fd.append('labor_fee',            laborFee);
    fd.append('estimated_duration',   duration);
    fd.append('required_mechanics',   mechs);
    fd.append('description',          desc);

    fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showCustomAlert(data.message || 'Service added successfully!', 'success', function() {
                closeAddServiceModal();
                location.reload();
            });
        } else {
            showCustomAlert(data.message || 'Failed to add service.', 'error');
        }
    })
    .catch(function() {
        showCustomAlert('Network error. Please try again.', 'error');
    })
    .finally(function() {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle"></i> Add Service'; }
    });
});

// ── EDIT SERVICE MODAL ────────────────────────────────────────────────────
function openEditServiceModal(svc) {
    if (typeof svc === 'string') { try { svc = JSON.parse(svc); } catch(e) { return; } }

    _svcOriginalFee   = parseFloat(svc.service_price) || 0;
    _svcOriginalLabor = parseFloat(svc.labor_fee)     || 0;

    document.getElementById('editSvcId').value           = svc.id        || '';
    document.getElementById('editSvcName').value         = svc.service_name || '';

    // Handle Custom vs Preset Category
    var presetCategories = ['Lubrication','Preventive Maintenance','Engine Services','Brake Services','Tire Services','Battery Services','Cooling System','Electrical Services','Air Conditioning','Undercarriage Services','Cleaning Services','Emergency Services'];
    var catVal = svc.category || '';
    var selEl  = document.getElementById('editSvcCategory');
    var wrapEl = document.getElementById('editSvcCustomWrap');
    var inpEl  = document.getElementById('editSvcCustomCategory');

    if (presetCategories.indexOf(catVal) !== -1) {
        selEl.value = catVal;
        if (wrapEl) wrapEl.style.display = 'none';
        if (inpEl)  inpEl.value = '';
    } else if (catVal) {
        selEl.value = 'Custom Services';
        if (wrapEl) wrapEl.style.display = 'block';
        if (inpEl)  inpEl.value = catVal;
    } else {
        selEl.value = '';
        if (wrapEl) wrapEl.style.display = 'none';
        if (inpEl)  inpEl.value = '';
    }

    document.getElementById('editSvcServiceFee').value   = _svcOriginalFee.toFixed(2);
    document.getElementById('editSvcLaborFee').value     = _svcOriginalLabor.toFixed(2);
    document.getElementById('editSvcDuration').value     = svc.estimated_duration  || 60;
    document.getElementById('editSvcMechanics').value    = svc.required_mechanics  || 1;
    document.getElementById('editSvcDescription').value  = svc.description || '';
    document.getElementById('editSvcActive').value       = svc.active ? '1' : '0';

    var codeEl = document.getElementById('editSvcCodeDisplay');
    if (codeEl) codeEl.textContent = svc.service_code ? 'Code: ' + svc.service_code : '';

    // Hide approval notice initially
    var notice = document.getElementById('editSvcApprovalNotice');
    if (notice) notice.style.display = 'none';

    document.getElementById('editServiceModal').style.display = 'flex';
    var f = document.getElementById('editSvcName');
    if (f) setTimeout(function() { f.focus(); }, 80);
}

function closeEditServiceModal() {
    var modal = document.getElementById('editServiceModal');
    if (modal) modal.style.display = 'none';
    var wrap = document.getElementById('editSvcCustomWrap');
    if (wrap) wrap.style.display = 'none';
}

// Show approval notice if fee fields changed
function checkSvcFeeChange() {
    var newSvc   = parseFloat((document.getElementById('editSvcServiceFee') || {}).value) || 0;
    var newLabor = parseFloat((document.getElementById('editSvcLaborFee')   || {}).value) || 0;
    var changed  = Math.abs(newSvc - _svcOriginalFee) > 0.001 || Math.abs(newLabor - _svcOriginalLabor) > 0.001;
    var notice   = document.getElementById('editSvcApprovalNotice');
    if (notice) notice.style.display = changed ? 'flex' : 'none';
}

safeAddListener('editServiceForm', 'submit', function(e) {
    e.preventDefault();

    var id       = document.getElementById('editSvcId').value;
    var name     = document.getElementById('editSvcName').value.trim();
    var category = document.getElementById('editSvcCategory').value.trim();
    var custom   = ((document.getElementById('editSvcCustomCategory') || {}).value || '').trim();

    if ((category === 'Custom Services' || category === 'Others') && custom) {
        category = custom;
    }

    var svcFee   = parseFloat(document.getElementById('editSvcServiceFee').value) || 0;
    var laborFee = parseFloat(document.getElementById('editSvcLaborFee').value)   || 0;
    var duration = parseInt(document.getElementById('editSvcDuration').value)    || 60;
    var mechs    = parseInt(document.getElementById('editSvcMechanics').value)   || 1;
    var desc     = document.getElementById('editSvcDescription').value || '';
    var active   = document.getElementById('editSvcActive').value;

    if (!id || !name || !category) {
        showCustomAlert('Please fill in all required fields.', 'error');
        return;
    }

    var btn = e.target.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }

    var fd = new FormData();
    fd.append('action',               'edit_service_full');
    fd.append('id',                   id);
    fd.append('service_name',         name);
    fd.append('category',             category);
    fd.append('service_price',        svcFee);
    fd.append('labor_fee',            laborFee);
    fd.append('estimated_duration',   duration);
    fd.append('required_mechanics',   mechs);
    fd.append('description',          desc);
    fd.append('active',               active);

    fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showCustomAlert(data.message || 'Service updated successfully!', 'success', function() {
                closeEditServiceModal();
                location.reload();
            });
        } else {
            showCustomAlert(data.message || 'Failed to update service.', 'error');
        }
    })
    .catch(function() {
        showCustomAlert('Network error. Please try again.', 'error');
    })
    .finally(function() {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
    });
});

// ── VIEW SERVICE MODAL ────────────────────────────────────────────────────
function openViewServiceModal(svc) {
    if (typeof svc === 'string') { try { svc = JSON.parse(svc); } catch(e) { return; } }
    _viewSvcData = svc;

    var svcFee   = parseFloat(svc.service_price) || 0;
    var laborFee = parseFloat(svc.labor_fee)     || 0;
    var total    = svcFee + laborFee;

    var hrs  = Math.floor((svc.estimated_duration || 60) / 60);
    var mins = (svc.estimated_duration || 60) % 60;
    var durStr = (hrs > 0 ? hrs + 'h ' : '') + (mins > 0 ? mins + 'm' : (hrs === 0 ? '0m' : ''));

    // Populate
    document.getElementById('viewSvcCodeDisplay').textContent  = svc.service_code ? 'Code: ' + svc.service_code : '';
    document.getElementById('viewSvcName').textContent         = svc.service_name || '';
    document.getElementById('viewSvcDesc').textContent         = svc.description  || '';
    document.getElementById('viewSvcCategory').textContent     = svc.category     || '—';
    document.getElementById('viewSvcServiceFee').textContent   = '₱' + svcFee.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('viewSvcLaborFee').textContent     = '₱' + laborFee.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('viewSvcTotalFee').textContent     = '₱' + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('viewSvcDuration').textContent     = durStr;
    document.getElementById('viewSvcMechanics').textContent    = (svc.required_mechanics || 1) + ' mechanic(s)';

    var statusEl = document.getElementById('viewSvcStatus');
    if (statusEl) {
        statusEl.innerHTML = svc.active
            ? '<span style="background:#dcfce7;color:#15803d;padding:5px 14px;border-radius:999px;font-size:12px;font-weight:700;">Active</span>'
            : '<span style="background:#fee2e2;color:#b91c1c;padding:5px 14px;border-radius:999px;font-size:12px;font-weight:700;">Inactive</span>';
    }

    // Open modal
    document.getElementById('viewServiceModal').style.display = 'flex';

    // Load history async
    var histEl = document.getElementById('viewSvcHistory');
    if (histEl) histEl.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px;"><i class="fas fa-spinner fa-spin" style="font-size:20px;"></i></div>';

    fetch('manager_set_prices_handler.php?action=get_service_history&id=' + encodeURIComponent(svc.id))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!histEl) return;
        if (!data.success || !data.history || data.history.length === 0) {
            histEl.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:16px;font-size:13px;"><i class="fas fa-history" style="font-size:22px;display:block;margin-bottom:8px;"></i>No fee change history yet.</div>';
            return;
        }
        var rows = data.history.map(function(h) {
            var statusBadge = {
                'pending':  '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;">Pending</span>',
                'approved': '<span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;">Approved</span>',
                'rejected': '<span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;">Rejected</span>',
                'direct':   '<span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;">Direct</span>',
            }[h.approval_status] || '<span style="color:#64748b;font-size:10px;">' + escHtml(h.approval_status || '') + '</span>';

            var changeType = {
                'service_fee': '<i class="fas fa-tag" style="color:#002F6C;"></i> Service Fee',
                'labor_fee':   '<i class="fas fa-hammer" style="color:#0369a1;"></i> Labor Fee',
                'created':     '<i class="fas fa-plus-circle" style="color:#16a34a;"></i> Created',
                'updated':     '<i class="fas fa-edit" style="color:#d97706;"></i> Updated',
                'activated':   '<i class="fas fa-check-circle" style="color:#16a34a;"></i> Activated',
                'deactivated': '<i class="fas fa-ban" style="color:#dc2626;"></i> Deactivated',
            }[h.change_type] || '<i class="fas fa-history"></i> ' + escHtml(h.change_type || '');

            var oldNew = '';
            if (h.old_service_fee != null && h.new_service_fee != null) {
                oldNew += '<div style="font-size:11px;color:#64748b;">Svc Fee: <span style="text-decoration:line-through;color:#dc2626;">₱' + parseFloat(h.old_service_fee).toFixed(2) + '</span> → <strong style="color:#16a34a;">₱' + parseFloat(h.new_service_fee).toFixed(2) + '</strong></div>';
            }
            if (h.old_labor_fee != null && h.new_labor_fee != null) {
                oldNew += '<div style="font-size:11px;color:#64748b;">Labor Fee: <span style="text-decoration:line-through;color:#dc2626;">₱' + parseFloat(h.old_labor_fee).toFixed(2) + '</span> → <strong style="color:#16a34a;">₱' + parseFloat(h.new_labor_fee).toFixed(2) + '</strong></div>';
            }

            var who   = h.changed_by_name || ((h.first_name || '') + ' ' + (h.last_name || '')).trim() || 'System';
            var when  = h.created_at ? new Date(h.created_at).toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'}) : '';

            return '<div style="padding:10px 0;border-bottom:1px solid #f1f5f9;display:flex;gap:12px;align-items:flex-start;">'
                + '<div style="flex:1;">'
                    + '<div style="font-size:12px;font-weight:600;color:#334155;display:flex;align-items:center;gap:6px;">' + changeType + '</div>'
                    + (oldNew ? '<div style="margin-top:4px;">' + oldNew + '</div>' : '')
                    + (h.notes ? '<div style="font-size:11px;color:#94a3b8;margin-top:3px;">' + escHtml(h.notes) + '</div>' : '')
                + '</div>'
                + '<div style="text-align:right;flex-shrink:0;">'
                    + statusBadge
                    + '<div style="font-size:10px;color:#94a3b8;margin-top:3px;">' + escHtml(who) + '</div>'
                    + '<div style="font-size:10px;color:#cbd5e1;">' + escHtml(when) + '</div>'
                + '</div>'
                + '</div>';
        }).join('');
        histEl.innerHTML = '<div style="max-height:240px;overflow-y:auto;padding-right:4px;">' + rows + '</div>';
    })
    .catch(function() {
        if (histEl) histEl.innerHTML = '<div style="text-align:center;color:#dc2626;padding:16px;font-size:13px;">Failed to load history.</div>';
    });
}

function closeViewServiceModal() {
    var modal = document.getElementById('viewServiceModal');
    if (modal) modal.style.display = 'none';
    _viewSvcData = null;
}

function editServiceFromView() {
    if (!_viewSvcData) return;
    closeViewServiceModal();
    setTimeout(function() { openEditServiceModal(_viewSvcData); }, 80);
}

// ── DEACTIVATE / ACTIVATE ─────────────────────────────────────────────────
function deactivateService(id, serviceName) {
    showConfirmModal(
        'Deactivate Service',
        'Confirm deactivation',
        'Are you sure you want to deactivate "' + serviceName + '"?\n\nThis will mark the service as inactive.',
        function(payload) {
            var fd = new FormData();
            fd.append('action', 'deactivate_service');
            fd.append('id', payload.id);
            fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showCustomAlert('Service deactivated successfully!', 'success', function() { location.reload(); });
                } else {
                    showCustomAlert(data.message || 'Failed to deactivate service.', 'error');
                }
            })
            .catch(function() { showCustomAlert('Network error. Please try again.', 'error'); });
        },
        { id: id, serviceName: serviceName }
    );
}

function activateService(id, serviceName) {
    showConfirmModal(
        'Activate Service',
        'Confirm activation',
        'Are you sure you want to activate "' + serviceName + '"?\n\nThis will mark the service as active.',
        function(payload) {
            var fd = new FormData();
            fd.append('action', 'activate_service');
            fd.append('id', payload.id);
            fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showCustomAlert('Service activated successfully!', 'success', function() { location.reload(); });
                } else {
                    showCustomAlert(data.message || 'Failed to activate service.', 'error');
                }
            })
            .catch(function() { showCustomAlert('Network error. Please try again.', 'error'); });
        },
        { id: id, serviceName: serviceName }
    );
}

</script>

<!-- Custom Status/Notification Modal Dialog (Replaces native browser alert popups) -->
<div id="statusNotificationModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:10001;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:440px;box-shadow:0 20px 50px rgba(0,0,0,.4);margin:auto;overflow:hidden;text-align:center;animation:statusPopIn .2s ease-out;">
    <div id="statusNotificationHeader" style="padding:22px 20px 14px 20px;background:#f0fdf4;">
      <div id="statusNotificationIcon" style="width:54px;height:54px;border-radius:50%;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;margin:0 auto 12px auto;font-size:24px;">
        <i class="fas fa-check-circle"></i>
      </div>
      <h3 id="statusNotificationTitle" style="margin:0;font-size:17px;font-weight:800;color:#166534;">Success</h3>
    </div>
    <div style="padding:16px 22px 22px 22px;">
      <p id="statusNotificationMessage" style="margin:0 0 20px 0;font-size:13px;color:#334155;line-height:1.5;font-weight:500;">
        Action completed successfully.
      </p>
      <button type="button" id="statusNotificationBtn" onclick="closeStatusNotificationModal()" style="background:#002F6C;color:#ffffff;border:none;padding:10px 28px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(0,47,108,0.25);width:100%;transition:all 0.15s ease;">
        OK
      </button>
    </div>
  </div>
</div>
<style>
@keyframes statusPopIn {
    from { opacity: 0; transform: scale(0.92); }
    to { opacity: 1; transform: scale(1); }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
